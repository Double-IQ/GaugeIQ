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
    // filter_input() reads the SAPI request data and does not reliably see
    // manually populated $_GET values when this API is included by the CLI
    // prefetch job. Fall back to the request array while keeping validation.
    $locationId = filter_input(INPUT_GET, 'location_id', FILTER_VALIDATE_INT);
    if ($locationId === null) {
        $locationId = filter_var($_GET['location_id'] ?? null, FILTER_VALIDATE_INT);
    }
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
        if (!is_file($capabilitiesPath) || gmdate('YmdH', (int)filemtime($capabilitiesPath)) !== gmdate('YmdH')) {
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
    // Expand the catalogue's actual time dimensions. Never manufacture an
    // observation timestamp by rounding the catalogue's latest time to :00.
    $layerTimes = [];
    foreach (['msg_fes:h60b', 'msg_fes:clm'] as $layerName) {
        $nameNodes = $xpath->query('//*[local-name()="Layer"]/*[local-name()="Name" and normalize-space(text())="' . $layerName . '"]');
        if (!$nameNodes || $nameNodes->length !== 1) throw new RuntimeException('Required EUMETSAT layer is missing: ' . $layerName);
        $layer = $nameNodes->item(0)->parentNode;
        $timeNode = null;
        for ($node = $layer; $node instanceof DOMElement && $timeNode === null; $node = $node->parentNode) {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement &&
                    in_array($child->localName, ['Dimension', 'Extent'], true) &&
                    strtolower((string)$child->getAttribute('name')) === 'time') {
                    $timeNode = $child;
                    break;
                }
            }
        }
        if (!$timeNode) throw new RuntimeException('EUMETSAT time dimension is missing: ' . $layerName);
        $dimension = trim((string)$timeNode->textContent);
        $available = [];
        foreach (preg_split('/\s*,\s*/', $dimension, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)\/(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)\/PT(10|15)M$/', $entry, $parts)) {
                $cursor = new DateTimeImmutable($parts[1], new DateTimeZone('UTC'));
                $last = new DateTimeImmutable($parts[2], new DateTimeZone('UTC'));
                $stepSeconds = (int)$parts[3] * 60;
                for ($i = 0; $cursor <= $last && $i < 20000; $i++, $cursor = $cursor->modify('+' . $stepSeconds . ' seconds')) {
                    $available[$cursor->getTimestamp()] = true;
                }
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $entry)) {
                $parsed = new DateTimeImmutable($entry, new DateTimeZone('UTC'));
                $available[$parsed->getTimestamp()] = true;
                continue;
            }
            throw new RuntimeException('Unsupported EUMETSAT time dimension format for ' . $layerName . '.');
        }
        if ($available === []) throw new RuntimeException('EUMETSAT has no usable observation timestamps for ' . $layerName . '.');
        $layerTimes[] = $available;
    }

    $commonTimes = array_intersect_key($layerTimes[0], $layerTimes[1]);
    if ($commonTimes === []) throw new RuntimeException('EUMETSAT precipitation and cloud observations have no common timestamps.');
    $availableTimes = array_map('intval', array_keys($commonTimes));
    sort($availableTimes, SORT_NUMERIC);

    $nowTimestamp = time();
    $latestCompleteHour = intdiv($nowTimestamp, 3600) * 3600;
    $frames = [];
    $used = [];
    for ($slot = 0; $slot < 4; $slot++) {
        $target = $latestCompleteHour - ($slot * 3600);
        $selected = null;
        for ($i = count($availableTimes) - 1; $i >= 0; $i--) {
            $candidate = $availableTimes[$i];
            if ($candidate <= $target && $candidate >= $target - 5400 && !isset($used[$candidate])) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            throw new RuntimeException('EUMETSAT does not provide four distinct hourly observations for both weather layers.');
        }
        $used[$selected] = true;
        $time = gmdate('Y-m-d\TH:i:s\Z', $selected);
        $frames[] = [
            'slot' => $slot,
            'type' => 'past',
            'time' => $time,
            'source' => 'EUMETSAT MSG satellite observation',
            'serverProxy' => true,
            'regionBounds' => $regionBounds,
        ];
    }
    // Public clients receive only frames whose full configured-region tile
    // set was prepared by the hourly server job. CLI callers are the prefetch
    // job itself and must be able to obtain the schedule before preparing it.
    if (PHP_SAPI !== 'cli') {
        $preparedDirectory = $directory . '/prepared';
        $regionHash = hash('sha256', json_encode($regionBounds, JSON_THROW_ON_ERROR));
        foreach ($frames as $frame) {
            $marker = $preparedDirectory . '/' . hash('sha256', $frame['time'] . '|' . $regionHash) . '.json';
            if (!is_file($marker)) {
                throw new RuntimeException('The selected EUMETSAT observations have not yet been fully prepared on the server.');
            }
        }
    }

    echo json_encode([
        'source' => 'EUMETSAT',
        'cadence' => 'hourly',
        'frames' => $frames,
        'refreshedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'serverProxy' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('GaugeIQ EUMETSAT observation schedule failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Observed EUMETSAT weather frames are temporarily unavailable. No model-frame fallback is provided.']);
}
