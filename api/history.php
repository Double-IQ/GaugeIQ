<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    $allHistory = strtolower(trim((string)($_GET['hours'] ?? '24'))) === 'all';

    if ($allHistory) {
        // Historical imports and live cron observations share this same table.
        // Return the full timeline in observation-time order for the History graphs.
        $stmt = $pdo->query(
            'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                    wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at, source
             FROM gaugeiq_pressure_readings
             ORDER BY observed_at ASC, id ASC'
        );
        $readings = $stmt->fetchAll();
        echo json_encode([
            'hours' => 'all',
            'readings' => $readings,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $hours = min(336, max(1, (int)($_GET['hours'] ?? 24)));
    $since = gmdate('c', time() - ($hours * 3600));

    // For recent windows, created_at is the UTC ingestion/check time used by
    // GaugeIQ's live monitor. NOAA historical rows use their observation date
    // as created_at, so they naturally appear only in matching recent ranges.
    $stmt = $pdo->prepare(
        'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at
         FROM gaugeiq_pressure_readings
         WHERE created_at >= ?
         ORDER BY observed_at ASC, id ASC'
    );
    $stmt->execute([$since]);

    echo json_encode([
        'hours' => $hours,
        'readings' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('GaugeIQ history API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load GaugeIQ history.']);
}
