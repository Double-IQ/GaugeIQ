<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    if (!is_file($configPath)) throw new RuntimeException('GaugeIQ is not installed.');
    $config = require $configPath;
    require_once __DIR__ . '/../../app/Database.php';
    require_once __DIR__ . '/../../app/WeatherMapRegionSettings.php';
    $pdo = (new Database($config))->pdo();
    $locationId = filter_input(INPUT_GET, 'location_id', FILTER_VALIDATE_INT);
    if ($locationId === false || $locationId === null || !in_array($locationId, [1, 2], true)) $locationId = 1;
    $regionBounds = WeatherMapRegionSettings::get($pdo, $locationId);

    // Cache the source catalogue for one hour: GaugeIQ, not the phone, decides
    // which four observed timestamps are used for the animation.
    $cacheRoot = (string)($config['weather_map_cache']['directory'] ?? (dirname(__DIR__, 2) . '/storage/weather-map-cache'));
    if ($cacheRoot === '' || $cacheRoot[0] !== '/') throw new RuntimeException('Invalid weather-map cache directory.');
    $directory = rtrim($cacheRoot, '/') . '/eumetsat';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('EUMETSAT cache directory is unavailable.');
    $capabilitiesPath = $directory . '/wms-capabilities.xml';
    $capabilitiesLock = fopen($capabilitiesPath . '.lock', 'c');
    if ($capabilitiesLock === false || !flock($capabilitiesLock, LOCK_EX)) throw new RuntimeException('Could not lock the EUMETSAT catalogue cache.');
    try {
        if (!is_file($capabilitiesPath) || (time() - (int)filemtime($capabilitiesPath)) >= 3600) {
            $ch = curl_init('https://view.eumetsat.int/geoserver/ows?service=WMS&version=1.3.0&request=GetCapabilities');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml'],
                CURLOPT_USERAGENT => 'GaugeIQ-WeatherMap/1.0',
            ]);
            $xml = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($xml) || $status !== 200 || stripos($xml, '<WMS_Capabilities') === false) {
                throw new RuntimeException('The live EUMETSAT WMS catalogue could not be refreshed.');
            }
            $tmp = $capabilitiesPath . '.' . bin2hex(random_bytes(5)) . '.tmp';
            if (file_put_contents($tmp, $xml, LOCK_EX) !== strlen($xml) || !rename($tmp, $capabilitiesPath)) {
                @unlink($tmp);
                throw new RuntimeException('Could not save the EUMETSAT WMS catalogue.');
            }
        }
        $xml = (string)file_get_contents($capabilitiesPath);
    } finally {
        flock($capabilitiesLock, LOCK_UN);
        fclose($capabilitiesLock);
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new RuntimeException('The EUMETSAT WMS catalogue is invalid.');
    $xpath = new DOMXPath($document);
    $latestEnds = [];
    foreach (['msg_fes:h60b', 'msg_fes:clm'] as $layerName) {
        $nameNodes = $xpath->query('//*[local-name()="Layer"]/*[local-name()="Name" and text()="' . $layerName . '"]');
        if (!$nameNodes || $nameNodes->length !== 1) throw new RuntimeException('Required EUMETSAT layer is missing: ' . $layerName);
        $layer = $nameNodes->item(0)->parentNode;
        $timeNodes = $xpath->query('./*[local-name()="Dimension" or local-name()="Extent"][@name="time"]', $layer);
        if (!$timeNodes || $timeNodes->length < 1) throw new RuntimeException('EUMETSAT time dimension is missing: ' . $layerName);
        $range = trim((string)$timeNodes->item(0)->textContent);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\/([^\/]+)\/PT(?:15M|10M)$/', $range, $matches)) {
            throw new RuntimeException('Unsupported EUMETSAT observation cadence for ' . $layerName . '.');
        }
        $end = new DateTimeImmutable($matches[1], new DateTimeZone('UTC'));
        $latestEnds[] = $end;
    }

    // Use only observation timestamps common to both layers. Select the
    // latest complete UTC hour, then the three preceding hourly observations.
    $latest = $latestEnds[0] < $latestEnds[1] ? $latestEnds[0] : $latestEnds[1];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($latest > $now) $latest = $now;
    $hour = $latest->setTime((int)$latest->format('H'), 0, 0);
    $frames = [];
    for ($slot = 0; $slot < 4; $slot++) {
        $time = $hour->modify('-' . $slot . ' hours')->format('Y-m-d\TH:i:s\Z');
        $frames[] = [
            'slot' => $slot,
            'type' => 'past',
            'time' => $time,
            'source' => 'EUMETSAT MSG satellite observation',
            'prepared' => ['precipitation' => true, 'cloud_cover' => true],
            'regionBounds' => $regionBounds,
        ];
    }
    echo json_encode([
        'source' => 'EUMETSAT',
        'cadence' => 'hourly',
        'frames' => $frames,
        'refreshedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'serverPrepared' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('GaugeIQ EUMETSAT observation schedule failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Observed EUMETSAT weather frames are temporarily unavailable. No model-frame fallback is provided.']);
}
