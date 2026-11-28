<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
$configPath = __DIR__ . '/../../config/local.php';

function observationTileFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        observationTileFail(405, 'Method not allowed.');
    }
    $time = (string)($_GET['time'] ?? '');
    $variable = (string)($_GET['variable'] ?? '');
    $z = filter_input(INPUT_GET, 'z', FILTER_VALIDATE_INT);
    $x = filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT);
    $y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $time, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $time ||
        !in_array($variable, ['precipitation', 'cloud_cover'], true) ||
        $z === false || $z === null || $z < 0 || $z > 4 ||
        $x === false || $x === null || $y === false || $y === null) {
        observationTileFail(400, 'Invalid EUMETSAT observation tile request.');
    }
    $count = 1 << $z;
    if ($x < 0 || $x >= $count || $y < 0 || $y >= $count) {
        observationTileFail(404, 'Tile is outside the tile matrix.');
    }

    if (!is_file($configPath)) observationTileFail(503, 'GaugeIQ is not installed.');
    $config = require $configPath;
    $cacheBase = (string)($config['weather_map_cache']['directory'] ?? (dirname(__DIR__, 2) . '/storage/weather-map-cache'));
    if ($cacheBase === '' || $cacheBase[0] !== '/') observationTileFail(500, 'Invalid weather-map cache directory.');
    $layer = $variable === 'precipitation' ? 'msg_fes:h60b' : 'msg_fes:clm';
    $cacheRoot = rtrim($cacheBase, '/') . '/eumetsat';
    $directory = $cacheRoot . '/' . $date->format('YmdHi') . '/' . $variable . '/' . $z . '/' . $x;
    // This endpoint is strictly read-only: only the hourly server prefetcher
    // creates cache directories and files. Native prepared coverage ends at z4.
    if ($z > 4 || !is_dir($directory)) {
        observationTileFail(503, 'Observed tile has not been prepared by the server yet.');
    }
    $path = $directory . '/' . $y . '.png';
    $etag = '"' . hash('sha256', implode('|', [$layer, $time, $z, $x, $y])) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=86400, immutable');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag && is_file($path)) {
        http_response_code(304);
        exit;
    }

    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        observationTileFail(503, 'Observation tile cache is busy.');
    }
    try {
        if (!is_file($path)) {
            observationTileFail(503, 'Observed tile has not been prepared by the server yet.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
} catch (Throwable $error) {
    error_log('GaugeIQ EUMETSAT observation tile failed: ' . $error->getMessage());
    observationTileFail(503, 'EUMETSAT observation tile is temporarily unavailable.');
}
