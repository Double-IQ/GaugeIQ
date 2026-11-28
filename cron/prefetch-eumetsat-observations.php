<?php
declare(strict_types=1);

/**
 * Hourly server-side preparation of EUMETSAT observation tiles.
 * The browser-facing tile API is cache-only; this job is the only code path
 * that downloads observation imagery from EUMETSAT.
 */
$config = require __DIR__ . '/../config/local.php';
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/WeatherMapRegionSettings.php';

$pdo = (new Database($config))->pdo();
$locations = ['1'];
$stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
$stmt->execute(['second_location_enabled']);
if ((string)$stmt->fetchColumn() === '1') $locations[] = '2';

$cacheBase = (string)($config['weather_map_cache']['directory'] ?? (dirname(__DIR__) . '/storage/weather-map-cache'));
if ($cacheBase === '' || $cacheBase[0] !== '/') throw new RuntimeException('Invalid weather-map cache directory.');
$cacheRoot = rtrim($cacheBase, '/') . '/eumetsat';
if (!is_dir($cacheRoot) && !mkdir($cacheRoot, 0750, true) && !is_dir($cacheRoot)) {
    throw new RuntimeException('EUMETSAT cache directory is unavailable.');
}

foreach ($locations as $locationId) {
    // Reuse the exact authoritative server-side timestamp selection used by
    // the public API. Buffer its JSON response instead of making an HTTP loopback.
    $oldGet = $_GET;
    $_GET = ['location_id' => $locationId];
    ob_start();
    try {
        require __DIR__ . '/../public/api/weather-map-frames.php';
        $json = (string)ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $oldGet;
    }
    $schedule = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($schedule['frames'] ?? null) || count($schedule['frames']) !== 4) {
        throw new RuntimeException('The EUMETSAT API did not return four observed frames for Location ' . $locationId . '.');
    }
    $bounds = WeatherMapRegionSettings::get($pdo, (int)$locationId);
    [$west, $south, $east, $north] = $bounds;
    $regionHash = hash('sha256', json_encode($bounds, JSON_THROW_ON_ERROR));

    foreach ($schedule['frames'] as $frame) {
        $time = (string)($frame['time'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $time, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\\TH:i:s\\Z') !== $time) {
            throw new RuntimeException('Invalid observed frame timestamp.');
        }
        foreach (['precipitation' => 'msg_fes:h60b', 'cloud_cover' => 'msg_fes:clm'] as $variable => $layer) {
            // Zoom 0-4 covers the configured region with a bounded tile count.
            // MapLibre overzooms the prepared native tiles at higher display zooms.
            for ($z = 0; $z <= 4; $z++) {
                $count = 1 << $z;
                $tileX = static fn(float $lon): int => max(0, min($count - 1, (int)floor(($lon + 180.0) / 360.0 * $count)));
                $tileY = static function (float $lat) use ($count): int {
                    $lat = max(-85.05112878, min(85.05112878, $lat));
                    return max(0, min($count - 1, (int)floor((1.0 - asinh(tan(deg2rad($lat))) / M_PI) / 2.0 * $count)));
                };
                $xMin = $tileX($west); $xMax = $tileX($east);
                $yMin = $tileY($north); $yMax = $tileY($south);
                for ($x = $xMin; $x <= $xMax; $x++) {
                    for ($y = $yMin; $y <= $yMax; $y++) {
                        $directory = $cacheRoot . '/' . $date->format('YmdH') . '/' . $variable . '/' . $z . '/' . $x;
                        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                            throw new RuntimeException('Could not create EUMETSAT tile cache directory.');
                        }
                        $path = $directory . '/' . $y . '.png';
                        if (is_file($path) && filesize($path) > 8 && file_get_contents($path, false, null, 0, 8) === "\\x89PNG\\r\\n\\x1a\\n") continue;

                        $world = 20037508.342789244;
                        $span = (2.0 * $world) / $count;
                        $minX = -$world + $x * $span;
                        $maxX = $minX + $span;
                        $maxY = $world - $y * $span;
                        $minY = $maxY - $span;
                        $params = [
                            'service' => 'WMS', 'version' => '1.3.0', 'request' => 'GetMap',
                            'layers' => $layer, 'styles' => '', 'format' => 'image/png',
                            'transparent' => 'true', 'width' => '256', 'height' => '256',
                            'crs' => 'EPSG:3857',
                            'bbox' => implode(',', array_map(static fn(float $v): string => sprintf('%.3f', $v), [$minX, $minY, $maxX, $maxY])),
                            'time' => $time,
                        ];
                        $url = 'https://view.eumetsat.int/geoserver/ows?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                        $ch = curl_init($url);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                            CURLOPT_HTTPHEADER => ['Accept: image/png'],
                            CURLOPT_USERAGENT => 'GaugeIQ-WeatherMap/1.0',
                        ]);
                        $png = curl_exec($ch);
                        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                        $error = curl_error($ch);
                        curl_close($ch);
                        if (!is_string($png) || $status !== 200 || stripos($type, 'image/png') === false ||
                            substr($png, 0, 8) !== "\\x89PNG\\r\\n\\x1a\\n") {
                            throw new RuntimeException('EUMETSAT prefetch failed for ' . $variable . ' tile ' . $z . '/' . $x . '/' . $y .
                                ' (HTTP ' . $status . '): ' . $error);
                        }
                        $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
                        if (file_put_contents($tmp, $png, LOCK_EX) !== strlen($png) || !rename($tmp, $path)) {
                            @unlink($tmp);
                            throw new RuntimeException('Could not atomically store EUMETSAT observation tile.');
                        }
                    }
                }
            }
        }
        $preparedDir = $cacheRoot . '/prepared';
        if (!is_dir($preparedDir) && !mkdir($preparedDir, 0750, true) && !is_dir($preparedDir)) {
            throw new RuntimeException('Could not create EUMETSAT prepared-frame directory.');
        }
        $marker = $preparedDir . '/' . hash('sha256', $time . '|' . $regionHash) . '.json';
        $markerData = ['time' => $time, 'bounds' => $bounds, 'preparedAt' => gmdate('c'), 'maxZoom' => 4];
        $tmp = $marker . '.' . bin2hex(random_bytes(5)) . '.tmp';
        $encoded = json_encode($markerData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $encoded, LOCK_EX) !== strlen($encoded) || !rename($tmp, $marker)) {
            @unlink($tmp);
            throw new RuntimeException('Could not publish EUMETSAT prepared-frame marker.');
        }
        printf("EUMETSAT frame prepared: Location %s, %s\\n", $locationId, $time);
    }
}
