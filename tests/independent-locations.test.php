<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/AlertRuleService.php';
require_once __DIR__ . '/../app/PressureService.php';

function assertLocationTest(bool $condition, string $message): void
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
CREATE TABLE gaugeiq_alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    metric TEXT NOT NULL,
    condition_type TEXT NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    last_triggered_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE gaugeiq_alert_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id INTEGER NOT NULL,
    observed_at TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id INTEGER NOT NULL DEFAULT 1,
    temperature_c REAL NULL,
    feels_like_c REAL NULL,
    dew_point_c REAL NULL,
    pressure_hpa REAL NULL,
    humidity_percent REAL NULL,
    wind_speed_kmh REAL NULL,
    wind_direction_degrees REAL NULL,
    rainfall_mm REAL NULL,
    cloud_cover_percent REAL NULL,
    weather_code INTEGER NULL,
    forecast_json TEXT NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    source TEXT NULL
);
SQL);

$locationOne = new AlertRuleService($pdo, new DateTimeZone('Africa/Johannesburg'), 1);
$locationTwo = new AlertRuleService($pdo, new DateTimeZone('Africa/Johannesburg'), 2);

// Verify live observations are written to their own location and that a
// coordinate-change boundary prevents a previous-place reading becoming the
// new place's comparison baseline.
$oldObservedAt = gmdate('Y-m-d\\TH:i:s', time() - 3600);
$oldCreatedAt = '2020-01-01T00:00:00+00:00';
$oldReading = $pdo->prepare(
    "INSERT INTO gaugeiq_pressure_readings
     (location_id, temperature_c, dew_point_c, pressure_hpa, humidity_percent,
      wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent,
      weather_code, observed_at, created_at, source)
     VALUES (?, 20, 12, ?, 60, 10, 180, 0, 40, 1, ?, ?, 'Open-Meteo')"
);
$oldReading->execute([1, 1008.0, $oldObservedAt, $oldCreatedAt]);
$oldReading->execute([2, 1004.0, $oldObservedAt, $oldCreatedAt]);
$saveLocationBoundary = $pdo->prepare('INSERT INTO gaugeiq_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
$saveLocationBoundary->execute(['second_location_changed_at', gmdate('c')]);

$currentObservedAt = gmdate('Y-m-d\\TH:i:s');
$currentReading = [
    'temperature_c' => 21.0,
    'feels_like_c' => 21.0,
    'dew_point_c' => 13.0,
    'pressure_hpa' => 1007.0,
    'humidity_percent' => 62.0,
    'wind_speed_kmh' => 12.0,
    'wind_direction_degrees' => 190.0,
    'rainfall_mm' => 0.0,
    'cloud_cover_percent' => 45.0,
    'weather_code' => 2,
    'forecast' => [],
    'observed_at' => $currentObservedAt,
];
$pressureOne = new PressureService([], $pdo, 1);
$pressureTwo = new PressureService([], $pdo, 2);
$previousOne = $pressureOne->record($currentReading);
$previousTwo = $pressureTwo->record($currentReading);
assertLocationTest(is_array($previousOne) && (float)$previousOne['pressure_hpa'] === 1008.0, 'Location 1 did not retain its own previous pressure baseline.');
assertLocationTest($previousTwo === null, 'Location 2 reused a pressure baseline from before its coordinates changed.');
$readingCounts = $pdo->query('SELECT location_id, COUNT(*) AS total FROM gaugeiq_pressure_readings GROUP BY location_id ORDER BY location_id')->fetchAll(PDO::FETCH_ASSOC);
assertLocationTest(count($readingCounts) === 2 && (int)$readingCounts[0]['total'] === 2 && (int)$readingCounts[1]['total'] === 2, 'Live readings were not stored independently by location.');


$locationOne->create('Location 1 pressure drop', 'pressure', 'drop', ['value' => 3], 0);
$locationTwo->create('Location 2 humidity rise', 'humidity', 'above', ['value' => 70], 0);

$oneRules = $locationOne->all();
$twoRules = $locationTwo->all();
assertLocationTest(count($oneRules) === 1, 'Location 1 did not return only its own alert rule.');
assertLocationTest(count($twoRules) === 1, 'Location 2 did not return only its own alert rule.');
assertLocationTest($oneRules[0]['name'] === 'Location 1 pressure drop', 'Location 1 returned another location\'s rule.');
assertLocationTest($twoRules[0]['name'] === 'Location 2 humidity rise', 'Location 2 returned another location\'s rule.');

$locationTwo->markTriggered((int)$oneRules[0]['id'], 'Invalid cross-location event', '2026-11-11T00:00:00');
$crossLocationEvents = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_events')->fetchColumn();
$locationOneTriggeredAt = $pdo->query('SELECT last_triggered_at FROM gaugeiq_alert_rules WHERE id = ' . (int)$oneRules[0]['id'])->fetchColumn();
assertLocationTest($crossLocationEvents === 0, 'Location 2 inserted an event for a Location 1 rule.');
assertLocationTest($locationOneTriggeredAt === null, 'Location 2 changed the cooldown on a Location 1 rule.');

$locationTwo->markTriggered((int)$twoRules[0]['id'], 'Location 2 alert', '2026-11-11T00:00:00');
$locationTwoEvents = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_events')->fetchColumn();
assertLocationTest($locationTwoEvents === 1, 'Location 2 could not create an event for its own alert.');

$locationTwo->delete((int)$oneRules[0]['id']);
assertLocationTest(count($locationOne->all()) === 1, 'Location 2 was able to delete a Location 1 rule.');
assertLocationTest(count($locationTwo->all()) === 1, 'Location 2 lost its own alert rule after a cross-location delete attempt.');

echo "Independent location alert-rule tests passed.\n";
