<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/LocationManagementService.php';

function assertLocationManagement(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function locationManagementDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
CREATE TABLE gaugeiq_settings (`key` TEXT PRIMARY KEY, `value` TEXT NOT NULL);
CREATE TABLE gaugeiq_pressure_readings (
 id INTEGER PRIMARY KEY AUTOINCREMENT, location_id INTEGER NOT NULL DEFAULT 1,
 pressure_hpa REAL NOT NULL, source TEXT NOT NULL, observed_at TEXT NOT NULL
);
CREATE TABLE gaugeiq_rain_forecasts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, location_id INTEGER NOT NULL DEFAULT 1,
 forecast_at TEXT NOT NULL, source TEXT NOT NULL, UNIQUE(location_id, forecast_at)
);
CREATE TABLE gaugeiq_alert_rules (
 id INTEGER PRIMARY KEY AUTOINCREMENT, location_id INTEGER NOT NULL DEFAULT 1, name TEXT NOT NULL
);
CREATE TABLE gaugeiq_alert_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INTEGER NOT NULL, message TEXT NOT NULL
);
SQL);
    return $pdo;
}

$pdo = locationManagementDatabase();
$pdo->exec(<<<'SQL'
INSERT INTO gaugeiq_settings (`key`, `value`) VALUES
 ('second_location_enabled', '1'), ('second_location_name', 'Holiday home'),
 ('second_location_latitude', '-29.000000'), ('second_location_longitude', '31.000000'),
 ('second_location_changed_at', '2026-11-11T00:00:00Z'),
 ('location_timezone_location_2', 'Africa/Johannesburg'),
 ('monitor_last_success_at_location_2', '2026-11-12T00:00:00Z'),
 ('weather_map_rainfall_nowcast_location_2', '{"frames":[]}'),
 ('location_name', 'Home'), ('location_latitude', '-33.000000'),
 ('location_longitude', '18.000000'), ('monitor_last_success_at', '2026-11-12T00:00:00Z');
INSERT INTO gaugeiq_pressure_readings (location_id, pressure_hpa, source, observed_at) VALUES
 (1, 1012, 'Open-Meteo Historical Weather', '2026-11-11T00:00'),
 (2, 1008, 'Open-Meteo Historical Weather', '2026-11-11T00:00');
INSERT INTO gaugeiq_rain_forecasts (location_id, forecast_at, source) VALUES
 (1, '2026-11-12T12:00', 'Open-Meteo'), (2, '2026-11-12T12:00', 'Open-Meteo');
INSERT INTO gaugeiq_alert_rules (location_id, name) VALUES (1, 'Home pressure'), (2, 'Holiday home rain');
INSERT INTO gaugeiq_alert_events (rule_id, message) VALUES (1, 'Home alert'), (2, 'Holiday home alert');
SQL);

// Deleting the secondary location removes its imported history and alerts only.
LocationManagementService::deleteLocation($pdo, 2);
assertLocationManagement((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 1')->fetchColumn() === 1, 'Deleting Location 2 affected Location 1 history.');
assertLocationManagement((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 2')->fetchColumn() === 0, 'Location 2 history remains after deletion.');
assertLocationManagement((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_events')->fetchColumn() === 1, 'Location 2 alert event was not removed or Location 1 event was affected.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='second_location_enabled'")->fetchColumn() === '0', 'Secondary monitoring was not disabled.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='location_name'")->fetchColumn() === 'Home', 'Location 1 settings changed.');

// Re-create a second location, then delete primary: Location 2 must be promoted,
// with all its imported historical readings, forecast records and alert rules/events.
$pdo->exec(<<<'SQL'
INSERT INTO gaugeiq_settings (`key`, `value`) VALUES
 ('second_location_enabled', '1'), ('second_location_name', 'New primary'),
 ('second_location_latitude', '-29.500000'), ('second_location_longitude', '30.500000'),
 ('location_timezone_location_2', 'Africa/Johannesburg'),
 ('monitor_last_success_at_location_2', '2026-11-12T01:00:00Z');
UPDATE gaugeiq_settings SET value='Old primary' WHERE `key`='location_name';
UPDATE gaugeiq_settings SET value='-33.000000' WHERE `key`='location_latitude';
UPDATE gaugeiq_settings SET value='18.000000' WHERE `key`='location_longitude';
UPDATE gaugeiq_settings SET value='stale-old-primary' WHERE `key`='monitor_last_success_at';
INSERT INTO gaugeiq_pressure_readings (location_id, pressure_hpa, source, observed_at)
 VALUES (1, 1000, 'Open-Meteo Historical Weather', '2026-11-10T00:00'),
        (2, 1005, 'Open-Meteo Historical Weather', '2026-11-10T00:00');
INSERT INTO gaugeiq_rain_forecasts (location_id, forecast_at, source)
 VALUES (1, '2026-11-13T12:00', 'Old'), (2, '2026-11-13T12:00', 'Promoted');
INSERT INTO gaugeiq_alert_rules (location_id, name) VALUES (1, 'Old primary rule'), (2, 'Promoted rule');
INSERT INTO gaugeiq_alert_events (rule_id, message)
 SELECT id, 'Old primary event' FROM gaugeiq_alert_rules WHERE name='Old primary rule';
INSERT INTO gaugeiq_alert_events (rule_id, message)
 SELECT id, 'Promoted event' FROM gaugeiq_alert_rules WHERE name='Promoted rule';
SQL);
LocationManagementService::deleteLocation($pdo, 1);
assertLocationManagement((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 1 AND source = "Open-Meteo Historical Weather"')->fetchColumn() === 1, 'Promoted historical download was not preserved.');
assertLocationManagement((int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = 2')->fetchColumn() === 0, 'Promoted readings retain secondary ID.');
assertLocationManagement((string)$pdo->query("SELECT name FROM gaugeiq_alert_rules WHERE location_id=1")->fetchColumn() === 'Promoted rule', 'Secondary alert rules were not promoted.');
assertLocationManagement((string)$pdo->query("SELECT message FROM gaugeiq_alert_events ORDER BY id DESC LIMIT 1")->fetchColumn() === 'Promoted event', 'Promoted alert history was not preserved.');
assertLocationManagement((string)$pdo->query("SELECT source FROM gaugeiq_rain_forecasts WHERE location_id=1")->fetchColumn() === 'Promoted', 'Promoted rainfall forecasts were not preserved.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='location_name'")->fetchColumn() === 'New primary', 'Secondary location name was not promoted.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='location_latitude'")->fetchColumn() === '-29.500000', 'Secondary coordinates were not promoted.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='location_timezone'")->fetchColumn() === 'Africa/Johannesburg', 'Secondary timezone was not promoted.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='monitor_last_success_at'")->fetchColumn() === '2026-11-12T01:00:00Z', 'Secondary monitor state was not promoted.');
assertLocationManagement((string)$pdo->query("SELECT value FROM gaugeiq_settings WHERE `key`='second_location_enabled'")->fetchColumn() === '0', 'Secondary feature flag was not disabled after promotion.');
assertLocationManagement((int)$pdo->query("SELECT COUNT(*) FROM gaugeiq_settings WHERE `key`='second_location_name'")->fetchColumn() === 0, 'Stale secondary settings remain.');
echo "Location deletion and primary-promotion tests passed.\\n";
