<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
header('X-Content-Type-Options: nosniff');

function weatherTileFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function weatherTilePngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function weatherTileEncodePng(string $rgba, int $width, int $height): string
{
    $scanlines = '';
    $stride = $width * 4;
    for ($y = 0; $y < $height; $y++) {
        $scanlines .= "\0" . substr($rgba, $y * $stride, $stride);
    }
    $header = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n" .
        weatherTilePngChunk('IHDR', $header) .
        weatherTilePngChunk('IDAT', gzcompress($scanlines, 6)) .
        weatherTilePngChunk('IEND', '');
}

function weatherTilePalette(string $variable, array $query): array
{
    $defaults = $variable === 'precipitation'
        ? ['#7dd3fc', '#2563eb', '#7c3aed', '#ec4899', '#be123c']
        : ['#e2e8f0', '#cbd5e1', '#94a3b8', '#475569'];
    $expected = count($defaults);
    $colors = isset($query['colors']) ? explode(',', (string)$query['colors']) : $defaults;
    $alphas = isset($query['alphas']) ? explode(',', (string)$query['alphas']) : array_fill(0, $expected, '100');
    if (count($colors) !== $expected || count($alphas) !== $expected) {
        throw new InvalidArgumentException('Invalid weather-map palette.');
    }
    $palette = [];
    foreach ($colors as $index => $hex) {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
            throw new InvalidArgumentException('Invalid weather-map palette colour.');
        }
        $alpha = is_numeric($alphas[$index]) ? max(0, min(100, (float)$alphas[$index])) : 100;
        $palette[] = [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
            (int)round($alpha * 2.55),
        ];
    }
    return $palette;
}

function weatherTileColour(float $value, string $variable, array $palette): array
{
    if (!is_finite($value) || $value < 0) return [0, 0, 0, 0];
    if ($variable === 'cloud_cover') {
        $breaks = [0.0, 25.0, 50.0, 75.0, 100.0];
        $stops = [[0, 0, 0, 0], ...$palette];
    } else {
        $breaks = [0.0, 1.0, 1.1, 2.0, 2.1, 5.0, 5.1, 10.0];
        $stops = [
            [0, 0, 0, 0], $palette[0], $palette[1], $palette[1],
            $palette[2], $palette[2], $palette[3], $palette[4],
        ];
    }
    if ($value <= $breaks[0]) return $stops[0];
    $last = count($breaks) - 1;
    if ($value >= $breaks[$last]) return $stops[$last];
    for ($i = 1; $i <= $last; $i++) {
        if ($value > $breaks[$i]) continue;
        $span = $breaks[$i] - $breaks[$i - 1];
        $fraction = $span > 0 ? ($value - $breaks[$i - 1]) / $span : 1.0;
        $result = [];
        for ($channel = 0; $channel < 4; $channel++) {
            $result[] = (int)round($stops[$i - 1][$channel] +
                ($stops[$i][$channel] - $stops[$i - 1][$channel]) * $fraction);
        }
        return $result;
    }
    return [0, 0, 0, 0];
}


function weatherTileCleanupCache(string $directory, int $maxBytes = 134217728): void
{
    $files = glob(rtrim($directory, '/') . '/*.png') ?: [];
    $entries = [];
    $total = 0;
    $expiredBefore = time() - 172800;
    foreach ($files as $path) {
        if (!is_file($path)) continue;
        $size = (int)(filesize($path) ?: 0);
        $modified = (int)(filemtime($path) ?: 0);
        if ($modified > 0 && $modified < $expiredBefore) {
            @unlink($path);
            continue;
        }
        $total += $size;
        $entries[] = ['path' => $path, 'size' => $size, 'modified' => $modified];
    }
    if ($total <= $maxBytes) return;
    usort($entries, static fn(array $a, array $b): int => $a['modified'] <=> $b['modified']);
    foreach ($entries as $entry) {
        if ($total <= $maxBytes) break;
        if (@unlink($entry['path'])) $total -= $entry['size'];
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        weatherTileFail(405, 'Method not allowed.');
    }
    if (!is_file($configPath)) weatherTileFail(503, 'GaugeIQ is not installed.');
    $slot = filter_input(INPUT_GET, 'slot', FILTER_VALIDATE_INT);
    $z = filter_input(INPUT_GET, 'z', FILTER_VALIDATE_INT);
    $x = filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT);
    $y = filter_input(INPUT_GET, 'y', FILTER_VALIDATE_INT);
    $variable = (string)($_GET['variable'] ?? '');
    $requestedFrameTime = (string)($_GET['frame_time'] ?? '');
    $deltaTimeHours = filter_input(INPUT_GET, 'delta_time_hours', FILTER_VALIDATE_FLOAT);
    if ($slot === false || $slot === null || $slot < 0 || $slot > 3 ||
        $z === false || $z === null || $z < 0 || $z > 12 ||
        $x === false || $x === null || $y === false || $y === null ||
        !in_array($variable, ['precipitation', 'cloud_cover'], true) ||
        $deltaTimeHours === false || $deltaTimeHours === null ||
        !is_finite((float)$deltaTimeHours) || $deltaTimeHours <= 0 || $deltaTimeHours > 24) {
        weatherTileFail(400, 'Invalid weather-map tile coordinates or precipitation interval.');
    }
    $tileCount = 1 << $z;
    if ($x < 0 || $x >= $tileCount || $y < 0 || $y >= $tileCount) {
        weatherTileFail(404, 'Weather-map tile is outside the tile matrix.');
    }

    $config = require $configPath;
    require_once __DIR__ . '/../../app/Database.php';
    require_once __DIR__ . '/../../app/WeatherMapRasterTileRenderer.php';
    $pdo = (new Database($config))->pdo();
    $stmt = $pdo->prepare("SELECT file_url, frame_time FROM gaugeiq_weather_map_frames WHERE frame_type = 'past' AND slot_id = ? LIMIT 1");
    $stmt->execute([$slot]);
    $frame = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($frame) || $requestedFrameTime === '' || $requestedFrameTime !== (string)$frame['frame_time']) {
        weatherTileFail(404, 'Weather-map frame is no longer scheduled.');
    }
    $url = (string)$frame['file_url'];
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
        ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
        !preg_match('#^/data_spatial/ecmwf_ifs025/.+\.om$#', (string)($parts['path'] ?? ''))) {
        weatherTileFail(404, 'Weather-map frame is invalid.');
    }

    $cacheRoot = (string)($config['weather_map_cache']['directory'] ?? (dirname(__DIR__, 2) . '/storage/weather-map-cache'));
    if ($cacheRoot === '' || $cacheRoot[0] !== '/') weatherTileFail(500, 'Invalid weather-map cache directory.');
    $decodedDirectory = rtrim($cacheRoot, '/') . '/decoded';
    $key = hash('sha256', $url . '|' . $variable . '|sa-grid-v3');
    $dataPath = $decodedDirectory . '/' . $key . '.f32';
    $metaPath = $decodedDirectory . '/' . $key . '.json';
    if (!is_file($dataPath) || !is_file($metaPath)) {
        weatherTileFail(404, 'Prepared weather-map frame is not ready.');
    }
    $metadata = json_decode((string)file_get_contents($metaPath), true);
    if (!is_array($metadata) || ($metadata['version'] ?? null) !== 3 ||
        ($metadata['variable'] ?? '') !== $variable ||
        ($metadata['frame_time'] ?? '') !== (string)$frame['frame_time'] ||
        ($metadata['encoding'] ?? '') !== 'float32-le') {
        weatherTileFail(503, 'Prepared weather-map frame metadata is invalid.');
    }
    $region = $metadata['region'] ?? [];
    $columns = (int)($region['columns'] ?? 0);
    $rows = (int)($region['rows'] ?? 0);
    $cellCount = (int)($metadata['cell_count'] ?? 0);
    if ($columns < 1 || $rows < 1 || $cellCount !== $columns * $rows ||
        (int)(filesize($dataPath) ?: 0) !== $cellCount * 4) {
        weatherTileFail(503, 'Prepared weather-map frame data is incomplete.');
    }

    $palette = weatherTilePalette($variable, $_GET);
    $interpolation = (string)($_GET['interpolation'] ?? 'cubic');
    if (!in_array($interpolation, ['nearest', 'linear', 'cubic', 'monotone'], true)) {
        weatherTileFail(400, 'Invalid weather-map interpolation mode.');
    }
    $tileCacheDirectory = $decodedDirectory . '/tiles';
    if (!is_dir($tileCacheDirectory) && !mkdir($tileCacheDirectory, 0750, true) && !is_dir($tileCacheDirectory)) {
        weatherTileFail(503, 'Weather-map tile cache is unavailable.');
    }
    $paletteSignature = json_encode($palette, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tileKey = hash('sha256', implode('|', [
        $key, (string)$z, (string)$x, (string)$y, $interpolation, (string)$deltaTimeHours, $paletteSignature
    ]));
    $tilePath = $tileCacheDirectory . '/' . $tileKey . '.png';
    $etag = '"' . $tileKey . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=3600, stale-while-revalidate=300');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag && is_file($tilePath)) {
        http_response_code(304);
        exit;
    }

    $lock = fopen($tilePath . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        weatherTileFail(503, 'Weather-map tile cache is busy.');
    }
    try {
        if (!is_file($tilePath)) {
            $binary = file_get_contents($dataPath);
            if (!is_string($binary) || strlen($binary) !== $cellCount * 4) {
                weatherTileFail(503, 'Prepared weather-map frame could not be read.');
            }
            $grid = unpack('g*', $binary);
            if (!is_array($grid)) weatherTileFail(503, 'Prepared weather-map frame could not be decoded.');
            $grid = array_values($grid);
            WeatherMapRasterTileRenderer::validate($grid, $columns, $rows, $interpolation);

            $west = (float)$region['west'];
            $south = (float)$region['south'];
            $xStart = (int)$region['x_start'];
            $yStart = (int)$region['y_start'];
            $step = (float)($metadata['grid']['step_degrees'] ?? 0.25);
            $rgba = '';
            $size = 256;
            $world = 256 * $tileCount;
            for ($py = 0; $py < $size; $py++) {
                for ($px = 0; $px < $size; $px++) {
                    $globalX = $x * $size + $px + 0.5;
                    $globalY = $y * $size + $py + 0.5;
                    $longitude = ($globalX / $world) * 360.0 - 180.0;
                    $mercator = M_PI * (1.0 - 2.0 * $globalY / $world);
                    $latitude = rad2deg(atan(sinh($mercator)));
                    if ($longitude < $west || $longitude > (float)$region['east'] ||
                        $latitude < $south || $latitude > (float)$region['north']) {
                        $rgba .= "\0\0\0\0";
                        continue;
                    }
                    $gx = ($longitude + 180.0) / $step - $xStart;
                    $gy = ($latitude + 90.0) / $step - $yStart;
                    if ($gx < -1 || $gy < -1 || $gx > $columns || $gy > $rows) {
                        $rgba .= "\0\0\0\0";
                        continue;
                    }
                    $value = WeatherMapRasterTileRenderer::sample($grid, $columns, $rows, $gx, $gy, $interpolation);
                    if ($variable === 'precipitation' && is_finite($value)) {
                        $value = max(0.0, $value) / (float)$deltaTimeHours;
                    }
                    $rgba .= pack('C4', ...weatherTileColour($value, $variable, $palette));
                }
            }

            $png = weatherTileEncodePng($rgba, 256, 256);
            $temporaryPath = $tilePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temporaryPath, $png, LOCK_EX) !== strlen($png) ||
                !@rename($temporaryPath, $tilePath)) {
                @unlink($temporaryPath);
                weatherTileFail(503, 'Unable to publish the prepared weather-map tile.');
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    weatherTileCleanupCache($tileCacheDirectory);
    header('Content-Type: image/png');
    header('Content-Length: ' . (string)filesize($tilePath));
    readfile($tilePath);
} catch (InvalidArgumentException $error) {
    weatherTileFail(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('GaugeIQ prepared weather-map tile failed: ' . $error->getMessage());
    weatherTileFail(503, 'Prepared weather-map tile is temporarily unavailable.');
}
