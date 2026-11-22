<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$diagnosticRequested = isset($_GET['diagnostic']) && $_GET['diagnostic'] === '1';
$diagnosticAuthorized = false;

if (!is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}

try {
    $config = require $configPath;
    if ($diagnosticRequested) {
        require_once __DIR__ . '/../../app/AdminAuth.php';
        AdminAuth::startSession();
        if (!AdminAuth::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Sign in to GaugeIQ Admin to view weather-map diagnostics.']);
            exit;
        }
        $diagnosticAuthorized = true;
    }

    require_once __DIR__ . '/../../app/Database.php';
    require_once __DIR__ . '/../../app/WeatherMapCacheService.php';

    $pdo = (new Database($config))->pdo();

    // Heal a stale schedule during a normal map request. This is especially
    // important when a deployment changes the ECMWF model path: the old rows
    // must be replaced before the strict public response validation runs.
    // The cache service maintains four past frames for this animation and
    // two future frames for Rain Radar. Only past frames are exposed here.
    $weatherMapCache = new WeatherMapCacheService($pdo, $config);
    // An authenticated diagnostic request explicitly bypasses the normal
    // hourly schedule cache so operators can verify newly deployed scheduler
    // logic immediately. Public map requests retain the hourly refresh limit.
    // If an upstream refresh fails, keep serving the last known-good schedule:
    // the refresh service writes new rows transactionally, so existing rows
    // remain intact until a complete replacement is ready.
    $scheduleRefreshError = null;
    try {
        $weatherMapCache->refreshScheduleIfDue($diagnosticAuthorized ? 0 : 3600);
    } catch (Throwable $refreshError) {
        $scheduleRefreshError = $refreshError;
        error_log('GaugeIQ weather-map schedule refresh failed; trying last known-good frames: ' . $refreshError->getMessage());
    }

    $stmt = $pdo->query(
        "SELECT slot_id, frame_type, frame_time, file_url, model_run_time, refreshed_at
         FROM gaugeiq_weather_map_frames
         WHERE frame_type = 'past'
         ORDER BY slot_id ASC"
    );
    $frames = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $url = (string)($row['file_url'] ?? '');
        $parts = parse_url($url);
        if (!is_array($parts) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
            !preg_match('#^/data_spatial/ecmwf_ifs025/.+\\.om$#', (string)($parts['path'] ?? ''))) {
            continue;
        }

        $cacheRoot = (string)($config['weather_map_cache']['directory'] ?? (dirname(__DIR__, 2) . '/storage/weather-map-cache'));
        $decodedDirectory = rtrim($cacheRoot, '/') . '/decoded';
        $prepared = [];
        foreach (['precipitation', 'cloud_cover'] as $variable) {
            $cacheKey = hash('sha256', $url . '|' . $variable . '|sa-grid-v1');
            $dataPath = $decodedDirectory . '/' . $cacheKey . '.f32';
            $metaPath = $decodedDirectory . '/' . $cacheKey . '.json';
            $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
            $prepared[$variable] = is_file($dataPath) && is_array($meta) &&
                ($meta['version'] ?? null) === 1 &&
                ($meta['frame_time'] ?? '') === (string)$row['frame_time'] &&
                ($meta['source_url_sha256'] ?? '') === hash('sha256', $url) &&
                ($meta['variable'] ?? '') === $variable &&
                ($meta['encoding'] ?? '') === 'float32-le' &&
                (int)($meta['cell_count'] ?? 0) > 0 &&
                (int)(filesize($dataPath) ?: 0) === (int)$meta['cell_count'] * 4;
        }
        $frames[] = [
            'slot' => (int)$row['slot_id'],
            'type' => (string)$row['frame_type'],
            'time' => (string)$row['frame_time'],
            'url' => $url,
            'prepared' => $prepared,
            'modelRunTime' => (string)$row['model_run_time'],
            'refreshedAt' => (string)$row['refreshed_at'],
        ];
    }

    if (count($frames) !== 4 || array_filter($frames, static fn(array $frame): bool => $frame['type'] !== 'past') !== []) {
        throw new RuntimeException('The scheduled weather map does not contain exactly 4 past frames.');
    }

    echo json_encode([
        'frames' => $frames,
        'refreshedAt' => $frames[0]['refreshedAt'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('GaugeIQ weather-map frames API failed: ' . $error->getMessage());
    http_response_code(503);
    if ($diagnosticRequested && $diagnosticAuthorized) {
        echo json_encode([
            'error' => 'Weather-map frame loading failed.',
            'diagnostic' => $error->getMessage(),
            'exception' => get_class($error),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        echo json_encode(['error' => 'The scheduled weather-map frames are not available yet.']);
    }
}
