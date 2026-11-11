<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Schema.php';

function assertSchemaMigration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<'SQL'
CREATE TABLE gaugeiq_schema (version INTEGER NOT NULL);
INSERT INTO gaugeiq_schema (version) VALUES (11);
CREATE TABLE gaugeiq_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pressure_hpa REAL NOT NULL,
    humidity_percent REAL NULL,
    wind_speed_kmh REAL NULL,
    wind_direction_degrees REAL NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
INSERT INTO gaugeiq_pressure_readings (pressure_hpa, observed_at, created_at)
VALUES (1012.5, '2026-11-11T00:00:00', '2026-11-11T00:00:01');
CREATE TABLE gaugeiq_alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    metric TEXT NOT NULL,
    condition_type TEXT NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO gaugeiq_alert_rules
(name, metric, condition_type, configuration_json, enabled, cooldown_minutes, created_at, updated_at)
VALUES ('Legacy pressure alert', 'pressure', 'drop', '{"value":3}', 1, 60, '2026-11-11T00:00:00', '2026-11-11T00:00:00');
CREATE TABLE gaugeiq_rain_forecasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    forecast_at TEXT NOT NULL UNIQUE,
    precipitation_probability_percent REAL NOT NULL,
    precipitation_mm REAL NULL,
    rain_mm REAL NULL,
    temperature_c REAL NULL,
    source TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_gaugeiq_rain_forecast_source ON gaugeiq_rain_forecasts(source);
INSERT INTO gaugeiq_rain_forecasts
(forecast_at, precipitation_probability_percent, precipitation_mm, rain_mm, temperature_c, source, created_at)
VALUES ('2026-11-11T03:00', 70, 2.5, 2.5, 19, 'Open-Meteo', '2026-11-11T00:00:00');
SQL);

migrateDatabase($pdo);

$version = (int)$pdo->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn();
assertSchemaMigration($version === 12, 'Schema version was not advanced to 12.');

$readingLocation = (int)$pdo->query('SELECT location_id FROM gaugeiq_pressure_readings LIMIT 1')->fetchColumn();
$ruleLocation = (int)$pdo->query('SELECT location_id FROM gaugeiq_alert_rules LIMIT 1')->fetchColumn();
assertSchemaMigration($readingLocation === 1, 'Existing readings were not preserved as Location 1.');
assertSchemaMigration($ruleLocation === 1, 'Existing alert rules were not preserved as Location 1.');

$forecast = $pdo->query('SELECT location_id, forecast_at FROM gaugeiq_rain_forecasts LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertSchemaMigration((int)$forecast['location_id'] === 1, 'Existing rainfall forecast was not preserved as Location 1.');

$insertSecondForecast = $pdo->prepare(
    'INSERT INTO gaugeiq_rain_forecasts
     (location_id, forecast_at, precipitation_probability_percent, source, created_at)
     VALUES (2, ?, 40, ?, ?)'
);
$insertSecondForecast->execute(['2026-11-11T03:00', 'Open-Meteo', '2026-11-11T00:05:00']);
$count = (int)$pdo->query("SELECT COUNT(*) FROM gaugeiq_rain_forecasts WHERE forecast_at = '2026-11-11T03:00'")->fetchColumn();
assertSchemaMigration($count === 2, 'The same forecast hour cannot be stored for both locations.');

migrateDatabase($pdo);
$repeatVersion = (int)$pdo->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn();
assertSchemaMigration($repeatVersion === 12, 'A repeated schema migration changed the schema version or failed.');

echo "Location schema migration tests passed.\n";
