<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/AlertRuleService.php';

function assertAlertTest(bool $condition, string $message): void
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
SQL);

$service = new AlertRuleService($pdo, new DateTimeZone('Africa/Johannesburg'));

$service->create('Dew point rises', 'dew_point', 'above', ['value' => 15], 0);
$matches = $service->evaluate(
    ['dew_point_c' => 14.0],
    ['dew_point_c' => 16.0]
);
assertAlertTest(count($matches) === 1, 'Dew point threshold-crossing alert did not trigger.');
assertAlertTest(str_contains($matches[0]['message'], 'Dew point rose above 15.0°C'), 'Dew point alert message was incorrect.');

$pdo->exec('DELETE FROM gaugeiq_alert_rules');
$service->create('Dew point changes', 'dew_point', 'change', ['value' => 1.5], 0);
$matches = $service->evaluate(
    ['dew_point_c' => 10.0],
    ['dew_point_c' => 12.0]
);
assertAlertTest(count($matches) === 1, 'Dew point change alert did not trigger.');

$pdo->exec('DELETE FROM gaugeiq_alert_rules');
$service->create('Dew point gap changes', 'dew_point_gap', 'change', ['value' => 1.5], 0);
$matches = $service->evaluate(
    ['temperature_c' => 24.0, 'dew_point_c' => 16.0],
    ['temperature_c' => 25.5, 'dew_point_c' => 16.0]
);
assertAlertTest(count($matches) === 1, 'Dew point gap change alert did not trigger.');

$pdo->exec('DELETE FROM gaugeiq_alert_rules');
$service->create('Dew point gap falls below', 'dew_point_gap', 'below', ['value' => 5], 0);
$matches = $service->evaluate(
    ['temperature_c' => 22.0, 'dew_point_c' => 16.0],
    ['temperature_c' => 20.0, 'dew_point_c' => 16.0]
);
assertAlertTest(count($matches) === 1, 'Dew point gap threshold-crossing alert did not trigger.');

foreach ([
    ['dew_point', 'drop', ['value' => 2]],
    ['dew_point_gap', 'specific', ['value' => 2]],
    ['dew_point_gap', 'below', ['value' => -1]],
    ['dew_point', 'above', ['value' => 61]],
] as [$metric, $type, $configuration]) {
    $rejected = false;
    try {
        $service->create('Invalid dew point rule', $metric, $type, $configuration, 0);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    assertAlertTest($rejected, "Invalid {$metric} condition {$type} was accepted.");
}

echo "Dew point and dew point gap alert tests passed.\n";
