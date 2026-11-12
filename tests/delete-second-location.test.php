<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/LocationManagementService.php';

function assertLocationDeletion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<'SQL'
CREATE TABLE gaugeiq_settings (
    `key` TEXT PRIMARY KEY,
    `value` TEXT NOT NULL
);
CREATE TABLE gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    pressure_hpa REAL NOT NULL
);
CREATE TABLE gaugeiq_rain_forecasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    forecast_at TEXT NOT NULL
);
CREATE TABLE gaugeiq_alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL
);
CREATE TABLE gaugeiq_alert_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id INTEGER NOT NULL,
    message TEXT NOT NULL
);
INSERT INTO gaugeiq_settings (`key`, `value`) VALUES
    ('second_location_enabled', '1'),
    ('second_location_name', 'Holiday home'),
    ('second_location_latitude', '-29.000000'),
    ('second_location_longitude', '31.000000'),
    ('second_location_changed_at', '2026-11-11T00:00:00Z'),
    ('location_timezone_location_2', 'Africa/Johannesburg'),
    ('monitor_last_success_at_location_2', '2026-11-12T00:00:00Z'),
    ('weather_map_rainfall_nowcast_location_2', '{}'),
    ('location_name', 'Home'),
    ('monitor_last_success_at', '2026-11-12T00:00:00Z');
INSERT INTO gaugeiq_pressure_readings (location_id, pressure_hpa) VALUES (1, 1012.0), (2, 1008.0);
INSERT INTO gaugeiq_rain_forecasts (location_id, forecast_at) VALUES (1, '2026-11-12T12:00'), (2, '2026-11-12T12:00');
INSERT INTO gaugeiq_alert_rules (location_id, name) VALUES (1, 'Home pressure'), (2, 'Holiday home rain');
INSERT INTO gaugeiq_alert_events (rule_id, message) VALUES (1, 'Home alert'), (2, 'Holiday home alert');
SQL);

LocationManagementService::deleteSecondLocation($pdo);

assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 1')->fetchColumn() === 1, 'Location 1 readings were deleted.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 2')->fetchColumn() === 0, 'Location 2 readings remain.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_rain_forecasts WHERE location_id = 1')->fetchColumn() === 1, 'Location 1 rainfall forecasts were deleted.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_rain_forecasts WHERE location_id = 2')->fetchColumn() === 0, 'Location 2 rainfall forecasts remain.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE location_id = 1')->fetchColumn() === 1, 'Location 1 alert rules were deleted.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE location_id = 2')->fetchColumn() === 0, 'Location 2 alert rules remain.');
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_events')->fetchColumn() === 1, 'Location 2 alert events were not removed or Location 1 events were affected.');

$settings = $pdo->query('SELECT `key`, `value` FROM gaugeiq_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
assertLocationDeletion(($settings['second_location_enabled'] ?? null) === '0', 'Second-location monitoring was not disabled.');
assertLocationDeletion(($settings['location_name'] ?? null) === 'Home', 'Location 1 settings were changed.');
assertLocationDeletion(isset($settings['monitor_last_success_at']), 'Location 1 monitor status was removed.');
foreach (array_keys($settings) as $key) {
    assertLocationDeletion(!str_starts_with($key, 'second_location_') || $key === 'second_location_enabled', 'A secondary location setting remains: ' . $key);
    assertLocationDeletion(!str_ends_with($key, '_location_2'), 'A Location 2 setting remains: ' . $key);
}

// Repeating the operation is safe and leaves Location 1 unchanged.
LocationManagementService::deleteSecondLocation($pdo);
assertLocationDeletion((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 1')->fetchColumn() === 1, 'Repeated deletion affected Location 1.');

echo "Secondary location deletion tests passed.\n";
