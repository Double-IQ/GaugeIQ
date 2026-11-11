<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);
    $locationId = (int)($_GET['location'] ?? 1);
    if (!in_array($locationId, [1, 2], true)) $locationId = 1;
    $timezoneKey = $locationId === 2 ? 'location_timezone_location_2' : 'location_timezone';
    $timezoneStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $timezoneStmt->execute([$timezoneKey]);
    $storedTimezone = $timezoneStmt->fetchColumn();
    $timezoneName = is_string($storedTimezone) && $storedTimezone !== ''
        ? $storedTimezone
        : (string)($config['app']['timezone'] ?? 'UTC');
    try { $historyTimezone = new DateTimeZone($timezoneName); } catch (Throwable) { $historyTimezone = new DateTimeZone('UTC'); }

    $hours = min(720, max(1, (int)($_GET['hours'] ?? 24)));
    $since = (new DateTimeImmutable('now', $historyTimezone))->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');
    $sinceDay = substr($since, 0, 10);

    // Filter by observation time, not ingestion time, so historical imports appear
    // in the correct graph range. Date-prefix matching handles legacy ISO timestamps
    // (with T/Z) alongside imported local timestamps (with a space).
    $changedAtKey = $locationId === 2 ? 'second_location_changed_at' : 'active_location_changed_at';
    $changedAtStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $changedAtStmt->execute([$changedAtKey]);
    $changedAtValue = $changedAtStmt->fetchColumn();
    $changedAt = is_string($changedAtValue) ? $changedAtValue : '';
    $historySql = 'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                          wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at, source
                   FROM gaugeiq_pressure_readings
                   WHERE location_id = ? AND observed_at >= ?';
    $historyParams = [$locationId, $sinceDay];
    if ($changedAt !== '') {
        $historySql .= ' AND created_at >= ?';
        $historyParams[] = $changedAt;
    }
    $historySql .= ' ORDER BY observed_at ASC, id ASC';
    $stmt = $pdo->prepare($historySql);
    $stmt->execute($historyParams);

    echo json_encode([
        'hours' => $hours,
        'readings' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('GaugeIQ history API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load GaugeIQ history.']);
}
