<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/AlertRuleService.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$rules = new AlertRuleService($pdo);

$updated = trim((string)($_GET['updated'] ?? ''));
$updateError = isset($_GET['update_error']);
$notice = '';
$errors = [];

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function alertLookback(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE key = 'alert_lookback_hours'");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value === false ? 3 : max(1, min(168, (int)$value));
}

function gaugeSetting(PDO $pdo, string $key, ?string $fallback = null): ?string {
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string)$value;
}

function saveGaugeSetting(PDO $pdo, string $key, string $value): void {
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    } else {
        $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    }
    $stmt->execute([$key, $value]);
}

$locationName = gaugeSetting($pdo, 'location_name', (string)$config['pressure']['location_name']) ?? '';
$locationLatitude = gaugeSetting($pdo, 'location_latitude', (string)$config['pressure']['latitude']) ?? '';
$locationLongitude = gaugeSetting($pdo, 'location_longitude', (string)$config['pressure']['longitude']) ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_location') {
                $name = trim((string)($_POST['location_name'] ?? ''));
                $latitude = (float)($_POST['location_latitude'] ?? 0);
                $longitude = (float)($_POST['location_longitude'] ?? 0);

                if ($name === '') {
                    throw new InvalidArgumentException('Please enter a name for the location.');
                }
                if ($latitude < -90 || $latitude > 90) {
                    throw new InvalidArgumentException('Latitude must be between -90 and 90.');
                }
                if ($longitude < -180 || $longitude > 180) {
                    throw new InvalidArgumentException('Longitude must be between -180 and 180.');
                }

                saveGaugeSetting($pdo, 'location_name', $name);
                saveGaugeSetting($pdo, 'location_latitude', number_format($latitude, 6, '.', ''));
                saveGaugeSetting($pdo, 'location_longitude', number_format($longitude, 6, '.', ''));
                $locationName = $name;
                $locationLatitude = number_format($latitude, 6, '.', '');
                $locationLongitude = number_format($longitude, 6, '.', '');
                $notice = 'Current location saved.';
            } elseif ($action === 'save_lookback') {
                $hours = max(1, min(168, (int)($_POST['lookback_hours'] ?? 3)));
                if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (key, value) VALUES ('alert_lookback_hours', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (`key`, `value`) VALUES ('alert_lookback_hours', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                }
                $stmt->execute([(string)$hours]);
                $notice = 'Alert comparison window saved.';
            } elseif ($action === 'delete') {
                $rules->delete((int)$_POST['id']);
                $notice = 'Alert deleted.';
            } elseif ($action === 'edit') {
                $id = (int)$_POST['id'];
                $metric = (string)$_POST['metric'];
                $type = (string)$_POST['condition_type'];
                $configuration = [];
                if (in_array($metric, ['pressure', 'humidity', 'wind_speed', 'weather_change'], true)) {
                    $configuration['value'] = (float)$_POST['value'];
                } elseif ($metric === 'wind_direction') {
                    $configuration['degrees'] = (float)$_POST['degrees'];
                } elseif ($metric === 'wind') {
                    $configuration = [
                        'speed_min' => (float)$_POST['speed_min'],
                        'direction_from' => (float)$_POST['direction_from'],
                        'direction_to' => (float)$_POST['direction_to'],
                    ];
                }
                $rules->update($id, (string)$_POST['name'], $metric, $type, $configuration, isset($_POST['enabled']), (int)$_POST['cooldown_minutes']);
                $notice = 'Alert rule updated.';
            } elseif ($action === 'toggle') {
                $id = (int)$_POST['id'];
                $rule = null;
                foreach ($rules->all() as $candidate) {
                    if ((int)$candidate['id'] === $id) {
                        $rule = $candidate;
                        break;
                    }
                }
                if (!$rule) {
                    throw new RuntimeException('Alert not found.');
                }
                $rules->update(
                    $id,
                    (string)$rule['name'],
                    (string)$rule['metric'],
                    (string)$rule['condition_type'],
                    json_decode((string)$rule['configuration_json'], true, 512, JSON_THROW_ON_ERROR),
                    !(bool)$rule['enabled'],
                    (int)$rule['cooldown_minutes']
                );
                $notice = 'Alert status updated.';
            } elseif ($action === 'create') {
                $metric = (string)$_POST['metric'];
                $type = (string)$_POST['condition_type'];
                $configuration = [];

                if (in_array($metric, ['pressure', 'humidity', 'wind_speed', 'weather_change'], true)) {
                    $configuration['value'] = (float)$_POST['value'];
                } elseif ($metric === 'wind_direction') {
                    $configuration['degrees'] = (float)$_POST['degrees'];
                } elseif ($metric === 'wind') {
                    $configuration = [
                        'speed_min' => (float)$_POST['speed_min'],
                        'direction_from' => (float)$_POST['direction_from'],
                        'direction_to' => (float)$_POST['direction_to'],
                    ];
                }

                $rules->create(
                    (string)$_POST['name'],
                    $metric,
                    $type,
                    $configuration,
                    (int)$_POST['cooldown_minutes']
                );
                $notice = 'Alert created.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$lookbackHours = alertLookback($pdo);
$allRules = $rules->all();
$enabledRuleCount = count(array_filter($allRules, static fn(array $rule): bool => (bool)$rule['enabled']));

$checkMinutes = max(1, (int)$config['pressure']['check_interval_minutes']);
$monitorCronMinutes = 30;
$monitorSettings = [];
foreach ($pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('monitor_last_success_at', 'monitor_last_error')") as $setting) {
    $monitorSettings[(string)$setting['key']] = (string)$setting['value'];
}
$lastMonitorAt = $monitorSettings['monitor_last_success_at'] ?? null;
$monitorError = $monitorSettings['monitor_last_error'] ?? '';
$nextMonitorAt = $lastMonitorAt ? strtotime($lastMonitorAt) + ($monitorCronMinutes * 60) : null;
$monitorAge = $lastMonitorAt ? time() - (int)strtotime($lastMonitorAt) : null;
$monitorHealthy = $monitorAge !== null && $monitorAge <= ($monitorCronMinutes * 60 * 1.5);
$cronScript = realpath(__DIR__ . '/../cron/check-pressure.php') ?: (__DIR__ . '/../cron/check-pressure.php');
$cronCommand = '/usr/local/bin/php -q ' . escapeshellarg($cronScript);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css">
<link rel="stylesheet" href="css/alerts.css">
<title>GaugeIQ Admin</title>
</head>
<body>
<main class="shell admin-shell">
<header class="admin-header">
    <div>
        <p class="eyebrow">GAUGЕIQ ADMIN</p>
        <h1>Administration</h1>
        <p class="muted">Signed in as <?= h(AdminAuth::username()) ?></p>
    </div>
    <a class="secondary button-link" href="logout.php">Sign out</a>
</header>

<?php if ($updated !== ''): ?>
<section class="card success"><strong>GaugeIQ was updated to <?= h($updated) ?>.</strong><p>Return to the dashboard to verify the new release.</p></section>
<?php endif; ?>
<?php if ($updateError): ?>
<section class="card error"><strong>GaugeIQ could not complete the update.</strong><p>The detailed error was written to the server log.</p></section>
<?php endif; ?>
<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="card location-card">
    <div class="section-heading">
        <div>
            <h2>Current location</h2>
            <p class="muted">Change the location GaugeIQ uses for weather data and the name shown on the dashboard.</p>
        </div>
    </div>
    <form method="post" class="location-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_location">
        <div class="location-grid">
            <div class="full">
                <label for="location_name">Location name</label>
                <input id="location_name" name="location_name" type="text" maxlength="120" value="<?= h($locationName) ?>" placeholder="Home">
            </div>
            <div>
                <label for="location_latitude">Latitude</label>
                <input id="location_latitude" name="location_latitude" type="number" min="-90" max="90" step="0.000001" value="<?= h($locationLatitude) ?>" required>
            </div>
            <div>
                <label for="location_longitude">Longitude</label>
                <input id="location_longitude" name="location_longitude" type="number" min="-180" max="180" step="0.000001" value="<?= h($locationLongitude) ?>" required>
            </div>
        </div>
        <div class="location-actions">
            <button type="button" class="secondary" id="useCurrentLocation">Use device location</button>
            <button type="submit">Save location</button>
        </div>
        <p id="locationHelp" class="alert-help muted">Changing the location affects the next weather check. Existing historical readings remain unchanged.</p>
    </form>
</section>

 <section class="card history-management-card" aria-labelledby="historyManagementTitle">
    <div class="section-heading">
        <div>
            <h2 id="historyManagementTitle">Historical data</h2>
            <p class="muted">Download NOAA historical observations directly into GaugeIQ's server database. The same database continues receiving live Open-Meteo readings through the existing cron monitor.</p>
        </div>
    </div>
    <div class="local-weather-tools" aria-label="NOAA historical data import">
        <p><strong>Configured location:</strong> <?= h($locationName) ?> · <?= h($locationLatitude) ?>, <?= h($locationLongitude) ?></p>
        <input type="hidden" id="noaaHistoryCsrf" value="<?= h($csrf) ?>">
        <div class="local-weather-api-options">
            <div>
                <label for="noaaHistoryStart">Start date</label>
                <input id="noaaHistoryStart" type="date" min="1973-01-01" max="<?= h(gmdate('Y-m-d', strtotime('yesterday'))) ?>" value="1973-01-01">
            </div>
            <div>
                <label for="noaaHistoryEnd">End date</label>
                <input id="noaaHistoryEnd" type="date" min="1973-01-01" max="<?= h(gmdate('Y-m-d', strtotime('yesterday'))) ?>" value="<?= h(gmdate('Y-m-d', strtotime('yesterday'))) ?>">
            </div>
        </div>
        <div class="local-weather-actions">
            <button type="button" id="noaaHistoryImport">Download and import NOAA history</button>
        </div>
        <p id="noaaHistoryStatus" class="muted" role="status" aria-live="polite">Historical records will be saved to the same server database as live readings.</p>
        <p class="muted local-weather-help">GaugeIQ finds a nearby NOAA station and imports daily observations. NOAA provides temperature, dew point, pressure, wind speed, and precipitation when available; fields not provided by NOAA remain empty. Imports are deduplicated by observation timestamp. Large date ranges are processed in batches, and live monitoring is not paused.</p>
    </div>
</section>

<section class="card monitoring-card" aria-labelledby="monitoringTitle">
    <div class="section-heading">
        <div><h2 id="monitoringTitle">Monitoring status</h2><p class="muted">GaugeIQ's scheduled background monitor.</p></div>
        <span class="status-pill <?= $monitorHealthy ? 'status-good' : 'status-warn' ?>"><?= $monitorHealthy ? '● Monitoring active' : '● Check required' ?></span>
    </div>
    <div class="status-grid">
        <div><span>Last successful check</span><strong><?= $lastMonitorAt ? htmlspecialchars(date('d M, H:i', (int)strtotime($lastMonitorAt)), ENT_QUOTES) : 'Not yet' ?></strong></div>
        <div><span>Next expected check</span><strong><?= $nextMonitorAt ? htmlspecialchars(date('d M, H:i', $nextMonitorAt), ENT_QUOTES) : 'Waiting for cron' ?></strong></div>
        <div><span>Active alert rules</span><strong><?= $enabledRuleCount ?></strong></div>
    </div>
    <?php if ($monitorError): ?><p class="monitor-warning">The last scheduled check reported an error. <?= htmlspecialchars($monitorError, ENT_QUOTES) ?></p><?php endif; ?>
</section>

<section class="admin-hero card">
    <div>
        <p class="eyebrow">WEATHER ALERTS</p>
        <h2>Alerts</h2>
        <p class="muted">Control what GaugeIQ watches and how far back it compares each new reading.</p>
    </div>
    <div class="alert-summary">
        <strong><?= $enabledRuleCount ?></strong>
        <span>active rule<?= $enabledRuleCount === 1 ? '' : 's' ?></span>
    </div>
</section>

<section class="card alert-window-card">
    <div class="section-heading">
        <div>
            <h2>Comparison window</h2>
            <p class="muted">The alert engine compares each new reading with the most recent reading at least this far back. This is independent of your cron interval.</p>
        </div>
        <span class="status-pill status-good">● Independent of cron</span>
    </div>
    <form method="post" class="lookback-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_lookback">
        <label for="lookback_hours">Compare against a reading from</label>
        <div class="lookback-controls">
            <input id="lookback_hours" name="lookback_hours" type="number" min="1" max="168" step="1" value="<?= $lookbackHours ?>">
            <span>hours ago</span>
            <button type="submit">Save window</button>
        </div>
        <p class="alert-help muted">For example, with a 60-minute cron and a 3-hour window, a new reading is compared with the reading closest to 3 hours earlier. Range: 1–168 hours.</p>
    </form>
</section>

<section class="card">
    <div class="section-heading">
        <div>
            <h2>Create an alert</h2>
            <p class="muted">Set a condition and GaugeIQ will evaluate it whenever the scheduled monitor records a new reading.</p>
        </div>
    </div>
    <form method="post" class="alert-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="alert-grid">
            <div class="full">
                <label for="name">Alert name</label>
                <input id="name" name="name" placeholder="Pressure change" required>
            </div>
            <div>
                <label for="metric">Monitor</label>
                <select id="metric" name="metric">
                    <option value="pressure">Air pressure</option>
                    <option value="humidity">Humidity</option>
                    <option value="wind_speed">Wind speed</option>
                    <option value="weather_change">Weather change score</option>
                    <option value="wind_direction">Wind direction</option>
                    <option value="wind">Wind speed + direction</option>
                </select>
            </div>
            <div>
                <label for="condition_type">Condition</label>
                <select id="condition_type" name="condition_type">
                    <option value="change">Changes by</option>
                    <option value="above">Rises above</option>
                    <option value="below">Falls below</option>
                    <option value="specific">Specific direction</option>
                    <option value="speed_and_direction">Speed AND direction</option>
                </select>
            </div>
            <div id="valueField">
                <label for="value">Value</label>
                <input id="value" name="value" type="number" min="1" max="10" step="1" value="3">
                <p id="weatherChangeHelp" class="alert-help muted" hidden>Alert when the weather change detection score reaches this level (1–10).</p>
            </div>
            <div id="degreesField" class="full" hidden>
                <label>Specific wind direction</label>
                <div class="compass-grid" role="group" aria-label="Specific wind direction">
                    <button type="button" class="compass-choice" data-degrees="0">N</button>
                    <button type="button" class="compass-choice" data-degrees="45">NE</button>
                    <button type="button" class="compass-choice" data-degrees="90">E</button>
                    <button type="button" class="compass-choice" data-degrees="135">SE</button>
                    <button type="button" class="compass-choice" data-degrees="180">S</button>
                    <button type="button" class="compass-choice" data-degrees="225">SW</button>
                    <button type="button" class="compass-choice" data-degrees="270">W</button>
                    <button type="button" class="compass-choice" data-degrees="315">NW</button>
                </div>
                <input id="degrees" name="degrees" type="hidden" value="0">
                <p class="alert-help muted">GaugeIQ stores the selected compass direction as degrees.</p>
            </div>
            <div id="speedField" hidden>
                <label for="speed_min">Minimum wind speed (km/h)</label>
                <input id="speed_min" name="speed_min" type="number" min="0" step="0.1" value="40">
            </div>
            <div id="fromField" class="full" hidden>
                <label>Wind direction range</label>
                <div class="direction-range">
                    <select id="direction_from" name="direction_from">
                        <option value="315">NW</option><option value="0">N</option><option value="45">NE</option><option value="90">E</option><option value="135">SE</option><option value="180">S</option><option value="225">SW</option><option value="270">W</option>
                    </select>
                    <span>→</span>
                    <select id="direction_to" name="direction_to">
                        <option value="0">N</option><option value="45">NE</option><option value="90">E</option><option value="135">SE</option><option value="180">S</option><option value="225">SW</option><option value="270">W</option><option value="315">NW</option>
                    </select>
                </div>
                <p class="alert-help muted">Ranges may cross north, for example NW → N.</p>
            </div>
            <div>
                <label for="cooldown_minutes">Cooldown</label>
                <input id="cooldown_minutes" name="cooldown_minutes" type="number" min="0" value="60">
                <p class="alert-help muted">Minimum time between notifications for this rule.</p>
            </div>
        </div>
        <button type="submit">Add alert</button>
    </form>
</section>

<section class="alerts-list-section">
    <div class="section-heading">
        <div>
            <h2>Saved alerts</h2>
            <p class="muted"><?= count($allRules) ?> configured rule<?= count($allRules) === 1 ? '' : 's' ?>.</p>
        </div>
    </div>
    <?php if (!$allRules): ?>
        <div class="card alert-empty"><div class="alert-empty-icon">✓</div><h3>No alerts configured</h3><p class="muted">Create your first weather alert above. GaugeIQ will evaluate it during the next scheduled check.</p></div>
    <?php endif; ?>

    <?php foreach ($allRules as $rule): ?>
        <?php $ruleConfig = json_decode((string)$rule['configuration_json'], true) ?: []; ?>
        <article class="card alert-card" data-alert-card>
            <div class="alert-card-main">
                <div class="alert-card-title">
                    <span class="alert-rule-dot <?= (int)$rule['enabled'] ? 'enabled' : '' ?>"></span>
                    <h3><?= h((string)$rule['name']) ?></h3>
                    <span class="alert-state <?= (int)$rule['enabled'] ? 'on' : 'off' ?>"><?= (int)$rule['enabled'] ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="alert-meta"><?= h(ucwords(str_replace('_', ' ', (string)$rule['metric']))) ?> · <?= h(ucwords(str_replace('_', ' ', (string)$rule['condition_type']))) ?> · cooldown <?= (int)$rule['cooldown_minutes'] ?> min</div>
                <div class="alert-config readable">
                    <?php if ($rule['metric'] === 'pressure'): ?>Air pressure <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['value'] ?? 0), 1)) ?> hPa
                    <?php elseif ($rule['metric'] === 'humidity'): ?>Humidity <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['value'] ?? 0), 0)) ?>%
                    <?php elseif ($rule['metric'] === 'weather_change'): ?>Weather change score reaches <?= h(number_format((float)($ruleConfig['value'] ?? 0), 0)) ?>/10
                    <?php elseif ($rule['metric'] === 'wind_speed'): ?>Wind speed <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['value'] ?? 0), 1)) ?> km/h
                    <?php elseif ($rule['metric'] === 'wind_direction'): ?>Wind direction <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['degrees'] ?? 0), 0)) ?>°
                    <?php else: ?>Wind <?= h(number_format((float)($ruleConfig['speed_min'] ?? 0), 1)) ?> km/h or faster, from <?= h(PressureService::directionLabel((float)($ruleConfig['direction_from'] ?? 0))) ?> to <?= h(PressureService::directionLabel((float)($ruleConfig['direction_to'] ?? 0))) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="alert-actions">
                <button type="button" class="secondary edit-alert-button" data-edit-alert>Edit</button>
                <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button type="submit" class="secondary"><?= (int)$rule['enabled'] ? 'Disable' : 'Enable' ?></button></form>
                <form method="post" onsubmit="return confirm('Delete this alert?')"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button type="submit" class="danger-button">Delete</button></form>
            </div>
            <form method="post" class="alert-edit-form" data-edit-form hidden>
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                <div class="edit-form-heading"><div><strong>Edit alert rule</strong><span>Update this rule without creating a duplicate.</span></div><button type="button" class="secondary" data-cancel-edit>Cancel</button></div>
                <div class="alert-grid">
                    <div class="full"><label>Alert name</label><input name="name" value="<?= h((string)$rule['name']) ?>" required></div>
                    <div><label>Monitor</label><select name="metric" data-edit-metric>
                        <?php foreach (['pressure'=>'Air pressure','humidity'=>'Humidity','wind_speed'=>'Wind speed','weather_change'=>'Weather change score','wind_direction'=>'Wind direction','wind'=>'Wind speed + direction'] as $v=>$label): ?><option value="<?= $v ?>" <?= $rule['metric']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
                    <div><label>Condition</label><select name="condition_type" data-edit-condition>
                        <?php foreach (['change'=>'Changes by','above'=>'Rises above','below'=>'Falls below','specific'=>'Specific direction','speed_and_direction'=>'Speed AND direction'] as $v=>$label): ?><option value="<?= $v ?>" <?= $rule['condition_type']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
                    <div data-edit-value-field><label>Value</label><input name="value" type="number" step="0.1" value="<?= h((string)($ruleConfig['value'] ?? 3)) ?>"></div>
                    <div data-edit-degrees-field hidden><label>Direction (degrees)</label><input name="degrees" type="number" min="0" max="359" step="1" value="<?= h((string)($ruleConfig['degrees'] ?? 0)) ?>"></div>
                    <div data-edit-speed-field hidden><label>Minimum wind speed (km/h)</label><input name="speed_min" type="number" min="0" step="0.1" value="<?= h((string)($ruleConfig['speed_min'] ?? 40)) ?>"></div>
                    <div data-edit-from-field hidden><label>Direction from</label><select name="direction_from"><?php foreach ([315=>'NW',0=>'N',45=>'NE',90=>'E',135=>'SE',180=>'S',225=>'SW',270=>'W'] as $deg=>$label): ?><option value="<?= $deg ?>" <?= (float)($ruleConfig['direction_from'] ?? 0)===$deg?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                    <div data-edit-to-field hidden><label>Direction to</label><select name="direction_to"><?php foreach ([0=>'N',45=>'NE',90=>'E',135=>'SE',180=>'S',225=>'SW',270=>'W',315=>'NW'] as $deg=>$label): ?><option value="<?= $deg ?>" <?= (float)($ruleConfig['direction_to'] ?? 0)===$deg?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                    <div><label>Cooldown (minutes)</label><input name="cooldown_minutes" type="number" min="0" value="<?= (int)$rule['cooldown_minutes'] ?>"></div>
                    <label class="edit-enabled full"><input type="checkbox" name="enabled" value="1" <?= (int)$rule['enabled'] ? 'checked' : '' ?>> Keep this alert enabled</label>
                </div>
                <button type="submit">Save changes</button>
            </form>
        </article>
    <?php endforeach; ?>
</section>

<section class="card admin-updates">
    <div class="section-heading">
        <div><h2>Updates</h2><p id="adminUpdateStatus" class="muted">Checking for the latest release…</p></div>
    </div>
    <div id="adminUpdateDetails" hidden>
        <p><strong id="adminLatestVersion"></strong></p>
        <form method="post" action="admin-update.php">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="version" id="adminUpdateVersion">
            <input type="hidden" name="package_url" id="adminPackageUrl">
            <input type="hidden" name="checksum_url" id="adminChecksumUrl">
            <button type="submit">Install update</button>
        </form>
    </div>
    <a class="secondary button-link" href="./">Back to GaugeIQ</a>
</section>

<script src="js/noaa-history.js?v=20261011-server-import" defer></script>
<script src="js/admin.js" defer></script>
<script src="js/alerts.js?v=4" defer></script>
</main>
<script>
document.getElementById('useCurrentLocation')?.addEventListener('click', () => {
    const help = document.getElementById('locationHelp');
    if (!navigator.geolocation) {
        if (help) help.textContent = 'This browser does not provide location services.';
        return;
    }
    if (help) help.textContent = 'Requesting your device location…';
    navigator.geolocation.getCurrentPosition(
        (position) => {
            document.getElementById('location_latitude').value = position.coords.latitude.toFixed(6);
            document.getElementById('location_longitude').value = position.coords.longitude.toFixed(6);
            if (help) help.textContent = 'Device coordinates loaded. Enter the location name, then save.';
        },
        (error) => {
            if (help) help.textContent = error.code === 1
                ? 'Location access was denied. You can enter the coordinates manually.'
                : 'Could not determine your device location. You can enter the coordinates manually.';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
    );
});
</script>
</body>
</html>