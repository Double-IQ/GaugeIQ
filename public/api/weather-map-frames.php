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
    // The cache service skips the metadata request when all six current-model
    // rows were refreshed within the configured interval.
    $weatherMapCache = new WeatherMapCacheService($pdo, $config);
    // An authenticated diagnostic request explicitly bypasses the normal
    // hourly schedule cache so operators can verify newly deployed scheduler
    // logic immediately. Public map requests retain the hourly refresh limit.
    $weatherMapCache->refreshScheduleIfDue($diagnosticAuthorized ? 0 : 3600);

    $stmt = $pdo->query(
        'SELECT slot_id, frame_type, frame_time, file_url, model_run_time, refreshed_at
         FROM gaugeiq_weather_map_frames
         ORDER BY slot_id ASC'
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

        $frames[] = [
            'slot' => (int)$row['slot_id'],
            'type' => (string)$row['frame_type'],
            'time' => (string)$row['frame_time'],
            'url' => $url,
            'modelRunTime' => (string)$row['model_run_time'],
            'refreshedAt' => (string)$row['refreshed_at'],
        ];
    }

    if (count($frames) !== 6) {
        throw new RuntimeException('The scheduled weather map does not contain all 6 frames (3 past + 3 forecast).');
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
