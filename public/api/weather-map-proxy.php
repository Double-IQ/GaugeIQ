<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('GaugeIQ is not installed.');
}

require_once __DIR__ . '/../../app/Database.php';
require_once __DIR__ . '/../../app/WeatherMapCacheService.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

function weatherMapProxyFail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

function weatherMapProxyCurl(string $url, bool $head, ?string $range = null): array
{
    $headers = [];
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialise the upstream map request.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'GaugeIQ-WeatherMap/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ];
    if ($head) {
        $options[CURLOPT_NOBODY] = true;
    } elseif ($range !== null) {
        $options[CURLOPT_RANGE] = $range;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        throw new RuntimeException('Upstream map request failed' . ($error !== '' ? ': ' . $error : '.') );
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $head ? '' : (string)$body];
}

function weatherMapProxyEnsureDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the weather-map cache directory.');
    }
}

function weatherMapProxyEvict(string $directory, int $maxBytes, int $incomingBytes): void
{
    $files = glob($directory . '/*.bin') ?: [];
    $total = 0;
    $entries = [];
    foreach ($files as $file) {
        $size = (int)(filesize($file) ?: 0);
        $total += $size;
        $entries[] = ['path' => $file, 'size' => $size, 'time' => (int)(filemtime($file) ?: 0)];
    }
    if ($total + $incomingBytes <= $maxBytes) {
        return;
    }
    usort($entries, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
    foreach ($entries as $entry) {
        if ($total + $incomingBytes <= $maxBytes) {
            break;
        }
        if (@unlink($entry['path'])) {
            $total -= $entry['size'];
        }
    }
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        weatherMapProxyFail(405, 'Method not allowed.');
    }

    $upstreamUrl = (string)($_GET['url'] ?? '');
    $parts = parse_url($upstreamUrl);
    if (!is_array($parts) ||
        ($parts['scheme'] ?? '') !== 'https' ||
        ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
        !preg_match('#^/data_spatial/ecmwf_ifs/.+\\.om$#', (string)($parts['path'] ?? '')) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        weatherMapProxyFail(400, 'Invalid weather-map file URL.');
    }

    $config = require $configPath;
    $database = new Database($config);
    $pdo = $database->pdo();
    $schedule = new WeatherMapCacheService($pdo, $config);
    if (!$schedule->isScheduledFile($upstreamUrl)) {
        // Bootstrap an empty schedule, or recover a stale one if a model hour
        // rolled over between scheduled cron runs.
        $schedule->refreshScheduleIfDue(60);
        if (!$schedule->isScheduledFile($upstreamUrl)) {
            weatherMapProxyFail(404, 'This weather-map frame is not in the current rolling schedule.');
        }
    }

    $canonicalUrl = 'https://openmeteo.s3.amazonaws.com' . $parts['path'];
    $projectRoot = dirname(__DIR__, 2);
    $cacheRoot = (string)($config['weather_map_cache']['directory'] ?? ($projectRoot . '/storage/weather-map-cache'));
    if ($cacheRoot === '' || $cacheRoot[0] !== '/') {
        weatherMapProxyFail(500, 'The weather-map cache directory must be an absolute server path.');
    }
    $blockDirectory = rtrim($cacheRoot, '/') . '/blocks';
    $metadataDirectory = rtrim($cacheRoot, '/') . '/metadata';
    weatherMapProxyEnsureDirectory($blockDirectory);
    weatherMapProxyEnsureDirectory($metadataDirectory);

    $metadataPath = $metadataDirectory . '/' . hash('sha256', $canonicalUrl) . '.json';
    $metadata = null;
    if (is_file($metadataPath) && (time() - (int)filemtime($metadataPath)) < 86400) {
        $decoded = json_decode((string)file_get_contents($metadataPath), true);
        if (is_array($decoded) && isset($decoded['size']) && (int)$decoded['size'] > 0) {
            $metadata = $decoded;
        }
    }
    if ($metadata === null) {
        $head = weatherMapProxyCurl($canonicalUrl, true);
        if ($head['status'] !== 200) {
            weatherMapProxyFail($head['status'] === 404 ? 404 : 502, 'The requested Open-Meteo map frame is not available.');
        }
        $fileSize = (int)($head['headers']['content-length'] ?? 0);
        if ($fileSize <= 0) {
            weatherMapProxyFail(502, 'Open-Meteo did not provide the map-frame size.');
        }
        $metadata = [
            'size' => $fileSize,
            'etag' => (string)($head['headers']['etag'] ?? ''),
            'last_modified' => (string)($head['headers']['last-modified'] ?? ''),
        ];
        $temporaryMetadata = $metadataPath . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (file_put_contents($temporaryMetadata, json_encode($metadata, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($temporaryMetadata, $metadataPath);
        } else {
            @unlink($temporaryMetadata);
        }
    }

    header('Accept-Ranges: bytes');
    if ($metadata['etag'] !== '') {
        header('ETag: ' . $metadata['etag']);
    }
    if ($metadata['last_modified'] !== '') {
        header('Last-Modified: ' . $metadata['last_modified']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        header('Content-Length: ' . (int)$metadata['size']);
        http_response_code(200);
        exit;
    }

    $rangeHeader = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if (!preg_match('/^bytes=(\\d+)-(\\d+)$/', $rangeHeader, $match)) {
        header('Content-Range: bytes */' . (int)$metadata['size']);
        weatherMapProxyFail(416, 'A single byte range is required.');
    }
    $start = (int)$match[1];
    $end = (int)$match[2];
    $fileSize = (int)$metadata['size'];
    $length = $end - $start + 1;
    if ($start < 0 || $end < $start || $end >= $fileSize || $length > 65536) {
        header('Content-Range: bytes */' . $fileSize);
        weatherMapProxyFail(416, 'The requested byte range is invalid.');
    }

    $blockPath = $blockDirectory . '/' . hash('sha256', $canonicalUrl . '|' . $start . '-' . $end) . '.bin';
    if (is_file($blockPath) && (int)filesize($blockPath) === $length) {
        $body = (string)file_get_contents($blockPath);
        @touch($blockPath);
    } else {
        $remote = weatherMapProxyCurl($canonicalUrl, false, $start . '-' . $end);
        if ($remote['status'] !== 206 || strlen($remote['body']) !== $length) {
            weatherMapProxyFail($remote['status'] === 404 ? 404 : 502, 'Open-Meteo returned an incomplete map-data range.');
        }
        $body = $remote['body'];
        $maxBytes = max(16 * 1024 * 1024, (int)($config['weather_map_cache']['max_bytes'] ?? (256 * 1024 * 1024)));
        weatherMapProxyEvict($blockDirectory, $maxBytes, strlen($body));
        $temporaryBlock = $blockPath . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (file_put_contents($temporaryBlock, $body, LOCK_EX) !== false) {
            @rename($temporaryBlock, $blockPath);
        } else {
            @unlink($temporaryBlock);
        }
    }

    http_response_code(206);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($body));
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    echo $body;
} catch (Throwable $error) {
    error_log('GaugeIQ weather-map proxy failed: ' . $error->getMessage());
    if (!headers_sent()) {
        weatherMapProxyFail(502, 'Weather-map data is temporarily unavailable.');
    }
}
