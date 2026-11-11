<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/AlertRuleService.php';

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
SQL);

$locationOne = new AlertRuleService($pdo, new DateTimeZone('Africa/Johannesburg'), 1);
$locationTwo = new AlertRuleService($pdo, new DateTimeZone('Africa/Johannesburg'), 2);

$locationOne->create('Location 1 pressure drop', 'pressure', 'drop', ['value' => 3], 0);
$locationTwo->create('Location 2 humidity rise', 'humidity', 'above', ['value' => 70], 0);

$oneRules = $locationOne->all();
$twoRules = $locationTwo->all();
assertLocationTest(count($oneRules) === 1, 'Location 1 did not return only its own alert rule.');
assertLocationTest(count($twoRules) === 1, 'Location 2 did not return only its own alert rule.');
assertLocationTest($oneRules[0]['name'] === 'Location 1 pressure drop', 'Location 1 returned another location\'s rule.');
assertLocationTest($twoRules[0]['name'] === 'Location 2 humidity rise', 'Location 2 returned another location\'s rule.');

$locationTwo->delete((int)$oneRules[0]['id']);
assertLocationTest(count($locationOne->all()) === 1, 'Location 2 was able to delete a Location 1 rule.');
assertLocationTest(count($locationTwo->all()) === 1, 'Location 2 lost its own alert rule after a cross-location delete attempt.');

echo "Independent location alert-rule tests passed.\n";
