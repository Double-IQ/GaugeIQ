<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}

$config = require $configPath;
require_once __DIR__ . '/../../app/Database.php';
require_once __DIR__ . '/../../app/Schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $timezoneName = (string)($config['app']['timezone'] ?? 'UTC');
    try {
        $historyTimezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
        $historyTimezone = new DateTimeZone('UTC');
    }

    $hours = min(720, max(1, (int)($_GET['hours'] ?? 24)));
    $now = new DateTimeImmutable('now', $historyTimezone);
    $from = $now->modify('-' . $hours . ' hours');
    // Query from the start of the local date, then apply an exact timestamp
    // filter in PHP. This handles imported timestamps ("T" or a space) and
    // the live API's timestamps consistently without filtering by import time.
    $fromDay = $from->format('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code,
                observed_at, created_at, source
         FROM gaugeiq_pressure_readings
         WHERE observed_at >= ?
         ORDER BY observed_at ASC, id ASC'
    );
    $stmt->execute([$fromDay]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fromTimestamp = $from->getTimestamp();
    $nowTimestamp = $now->getTimestamp();
    $includeRecentBackfill = (string)($_GET['include_recent_backfill'] ?? '') === '1';
    $readings = [];

    foreach ($rows as $row) {
        // Recent model-derived values are exposed to the meter fallback only
        // when explicitly requested; they are not presented as observations
        // on the ordinary history charts.
        if (!$includeRecentBackfill && ($row['source'] ?? null) === 'Open-Meteo Recent Backfill') {
            continue;
        }

        $observedAt = trim((string)($row['observed_at'] ?? ''));
        if ($observedAt === '') {
            continue;
        }

        try {
            $observedDate = new DateTimeImmutable($observedAt, $historyTimezone);
        } catch (Throwable) {
            continue;
        }

        $timestamp = $observedDate->getTimestamp();
        if ($timestamp < $fromTimestamp || $timestamp > $nowTimestamp + 300) {
            continue;
        }

        $row['timestamp'] = $observedDate->format(DateTimeInterface::ATOM);
        $readings[] = $row;
    }

    echo json_encode([
        'hours' => $hours,
        'from' => $from->format(DateTimeInterface::ATOM),
        'to' => $now->format(DateTimeInterface::ATOM),
        'count' => count($readings),
        'readings' => $readings,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('GaugeIQ public history API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load GaugeIQ history.']);
}
