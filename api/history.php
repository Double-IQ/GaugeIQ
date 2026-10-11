<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    $timezoneName = (string)($config['app']['timezone'] ?? 'UTC');
    try { $historyTimezone = new DateTimeZone($timezoneName); } catch (Throwable) { $historyTimezone = new DateTimeZone('UTC'); }

    $hours = min(720, max(1, (int)($_GET['hours'] ?? 24)));
    $since = (new DateTimeImmutable('now', $historyTimezone))->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
    $sinceDay = substr($since, 0, 10);

    // Filter by observation time, not ingestion time, so historical imports appear
    // in the correct graph range. Date-prefix matching handles legacy ISO timestamps
    // (with T/Z) alongside imported local timestamps (with a space).
    $stmt = $pdo->prepare(
        'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at, source
         FROM gaugeiq_pressure_readings
         WHERE observed_at >= ?
         ORDER BY observed_at ASC, id ASC'
    );
    $stmt->execute([$sinceDay]);

    echo json_encode([
        'hours' => $hours,
        'readings' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('GaugeIQ history API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load GaugeIQ history.']);
}
