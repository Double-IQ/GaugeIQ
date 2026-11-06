<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}

try {
    $config = require $configPath;
    require_once __DIR__ . '/../../app/Database.php';

    $pdo = (new Database($config))->pdo();
    
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
            !preg_match('#^/data_spatial/ecmwf_ifs/.+\\.om$#', (string)($parts['path'] ?? ''))) {
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
    echo json_encode(['error' => 'The scheduled weather-map frames are not available yet.']);
}
