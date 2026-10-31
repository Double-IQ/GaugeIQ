<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/AlertRuleService.php';
require __DIR__ . '/../app/PushService.php';

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

function notificationDeviceLabel(?string $userAgent): string {
    $ua = (string)$userAgent;
    $platform = 'Unknown device';
    if (stripos($ua, 'iPhone') !== false) $platform = 'iPhone';
    elseif (stripos($ua, 'iPad') !== false) $platform = 'iPad';
    elseif (stripos($ua, 'Android') !== false) $platform = 'Android device';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $platform = 'Mac';
    elseif (stripos($ua, 'Windows') !== false) $platform = 'Windows PC';
    elseif (stripos($ua, 'Linux') !== false) $platform = 'Linux device';

    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) $browser = 'Microsoft Edge';
    elseif (stripos($ua, 'CriOS/') !== false || stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'FxiOS/') !== false || stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';
    return $platform . ' · ' . $browser;
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

$historyTimezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_lookback') {
                $hours = max(1, min(168, (int)($_POST['lookback_hours'] ?? 3)));
                if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (key, value) VALUES ('alert_lookback_hours', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                } else {
                    $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (`key`, `value`) VALUES ('alert_lookback_hours', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                }
                $stmt->execute([(string)$hours]);
                $notice = 'Alert comparison window saved.';
            } elseif ($action === 'save_daily_forecast_alert') {
                $time = trim((string)($_POST['daily_forecast_alert_time'] ?? '07:00'));
                if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time)) {
                    throw new InvalidArgumentException('Choose a valid daily forecast alert time.');
                }

                $enabled = isset($_POST['daily_forecast_alert_enabled']);
                $rain = isset($_POST['daily_forecast_alert_rain']);
                $wind = isset($_POST['daily_forecast_alert_wind']);
                $severe = isset($_POST['daily_forecast_alert_severe']);
                $alwaysNotify = isset($_POST['daily_forecast_alert_always']);
                if ($enabled && !$rain && !$wind && !$severe && !$alwaysNotify) {
                    throw new InvalidArgumentException('Select at least one forecast condition or enable the all-clear notification option.');
                }

                $pdo->beginTransaction();
                try {
                    saveGaugeSetting($pdo, 'daily_forecast_alert_enabled', $enabled ? '1' : '0');
                    saveGaugeSetting($pdo, 'daily_forecast_alert_time', $time);
                    saveGaugeSetting($pdo, 'daily_forecast_alert_rain', $rain ? '1' : '0');
                    saveGaugeSetting($pdo, 'daily_forecast_alert_wind', $wind ? '1' : '0');
                    saveGaugeSetting($pdo, 'daily_forecast_alert_severe', $severe ? '1' : '0');
                    saveGaugeSetting($pdo, 'daily_forecast_alert_always', $alwaysNotify ? '1' : '0');
                    $pdo->commit();
                } catch (Throwable $saveError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $saveError;
                }
                $notice = $enabled ? 'Daily forecast notification settings saved.' : 'Daily forecast notification disabled.';
            } elseif ($action === 'save_stability_threshold') {
                $thresholdInput = trim((string)($_POST['stability_alert_threshold'] ?? ''));
                if ($thresholdInput === '') {
                    saveGaugeSetting($pdo, 'stability_alert_threshold', '');
                    $notice = 'Stability bar alert threshold disabled.';
                } else {
                    if (!is_numeric($thresholdInput) || (float)$thresholdInput < 0 || (float)$thresholdInput > 10) {
                        throw new InvalidArgumentException('The WVI alert threshold must be between 0 and 10, or left disabled.');
                    }
                    $threshold = (int)round((float)$thresholdInput);
                    saveGaugeSetting($pdo, 'stability_alert_threshold', (string)(10 - $threshold));
                    $notice = 'Stability bar alert threshold saved.';
                }
            } elseif ($action === 'test_device_notification') {
                $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($deviceId === false || $deviceId === null) {
                    throw new InvalidArgumentException('Choose a valid notification device.');
                }
                $pushService = new PushService($config, $pdo);
                $testResult = $pushService->sendDetailed(
                    'GaugeIQ test notification',
                    'Notifications are working for this device.',
                    null,
                    (int)$deviceId
                );
                $deviceResult = $testResult['results'][0] ?? null;
                if (is_array($deviceResult) && !empty($deviceResult['ok'])) {
                    $notice = 'Test notification sent to the selected device.';
                } else {
                    $reason = is_array($deviceResult) ? (string)($deviceResult['reason'] ?? 'Push delivery failed.') : 'Push delivery failed.';
                    throw new RuntimeException('Test notification failed: ' . $reason);
                }
            } elseif ($action === 'delete') {
                $rules->delete((int)$_POST['id']);
                $notice = 'Alert deleted.';
            } elseif ($action === 'edit') {
                $id = (int)$_POST['id'];
                $metric = (string)$_POST['metric'];
                $type = (string)$_POST['condition_type'];
                $configuration = [];
                if (in_array($metric, ['pressure', 'humidity', 'wind_speed', 'weather_stability'], true)) {
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

                if (in_array($metric, ['pressure', 'humidity', 'wind_speed', 'weather_stability'], true)) {
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
$dailyForecastAlertEnabled = gaugeSetting($pdo, 'daily_forecast_alert_enabled', '0') === '1';
$dailyForecastAlertTime = gaugeSetting($pdo, 'daily_forecast_alert_time', '07:00') ?? '07:00';
if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $dailyForecastAlertTime)) {
    $dailyForecastAlertTime = '07:00';
}
$dailyForecastAlertRain = gaugeSetting($pdo, 'daily_forecast_alert_rain', '1') === '1';
$dailyForecastAlertWind = gaugeSetting($pdo, 'daily_forecast_alert_wind', '1') === '1';
$dailyForecastAlertSevere = gaugeSetting($pdo, 'daily_forecast_alert_severe', '1') === '1';
$dailyForecastAlertAlways = gaugeSetting($pdo, 'daily_forecast_alert_always', '0') === '1';
$dailyForecastAlertLastDate = gaugeSetting($pdo, 'daily_forecast_alert_last_evaluated_date', '') ?? '';
$dailyForecastAlertLastStatus = gaugeSetting($pdo, 'daily_forecast_alert_last_status', '') ?? '';
$dailyForecastAlertLastError = gaugeSetting($pdo, 'daily_forecast_alert_last_error', '') ?? '';
$stabilityAlertThresholdValue = gaugeSetting($pdo, 'stability_alert_threshold', '') ?? '';
$stabilityAlertThreshold = $stabilityAlertThresholdValue !== '' && is_numeric($stabilityAlertThresholdValue) ? 10 - (int)$stabilityAlertThresholdValue : null;
$notificationDevices = $pdo->query('SELECT id, created_at, updated_at, user_agent, last_seen_at, last_push_at, last_push_status, last_push_error FROM gaugeiq_push_subscriptions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$recentAlerts = $pdo->query(
    'SELECT e.id, e.message, e.observed_at, r.name FROM gaugeiq_alert_events e LEFT JOIN gaugeiq_alert_rules r ON r.id = e.rule_id ORDER BY e.id DESC LIMIT 8'
)->fetchAll(PDO::FETCH_ASSOC);
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
<link rel="stylesheet" href="css/app.css?v=20261031-silver-light-mode-v1">
<link rel="stylesheet" href="css/alerts.css?v=20261029-notification-devices">
<title>GaugeIQ — Weather Alerts</title>
</head>
<body>
<main class="shell admin-shell">
<header class="admin-header">
    <div>
        <p class="eyebrow">GAUGEIQ ADMIN</p>
        <h1>Weather alerts</h1>
        <p class="muted">Configure thresholds, create rules and manage saved alerts and notification devices.</p>
    </div>
    <a class="secondary button-link" href="admin.php">Back to Admin</a>
</header>
<?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
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

<section class="card alert-window-card">
    <div class="section-heading">
        <div>
            <h2>WVI alert threshold</h2>
            <p class="muted">Choose the WVI score at or above which the WVI indicator starts flashing. This is separate from push-notification alert rules.</p>
        </div>
    </div>
    <form method="post" class="lookback-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_stability_threshold">
        <label for="stability_alert_threshold">Flash when Weather Volatility Index (WVI) rises to or above</label>
        <div class="lookback-controls">
            <select id="stability_alert_threshold" name="stability_alert_threshold">
                <option value="" <?= $stabilityAlertThreshold === null ? 'selected' : '' ?>>Disabled</option>
                <?php for ($thresholdOption = 0; $thresholdOption <= 10; $thresholdOption++): ?>
                    <option value="<?= $thresholdOption ?>" <?= $stabilityAlertThreshold === $thresholdOption ? 'selected' : '' ?>><?= $thresholdOption ?> / 10</option>
                <?php endfor; ?>
            </select>
            <button type="submit">Save threshold</button>
        </div>
        <p class="alert-help muted">The bar keeps its green, yellow or red colour. Reaching this score adds the flashing warning and yellow track border. Leave it disabled if you do not want the bar to flash.</p>
    </form>
</section>

<section class="card notification-devices-card" aria-labelledby="notificationDevicesTitle">
    <div class="section-heading">
        <div>
            <h2 id="notificationDevicesTitle">Devices with notifications enabled</h2>
            <p class="muted">Each listed device has a saved push subscription. Send a test to one device without notifying the others.</p>
        </div>
        <span class="status-pill <?= $notificationDevices ? 'status-good' : 'status-warn' ?>"><?= count($notificationDevices) ?> device<?= count($notificationDevices) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$notificationDevices): ?>
        <p class="muted">No devices are currently registered for push notifications.</p>
    <?php else: ?>
        <div class="notification-device-list">
            <?php foreach ($notificationDevices as $device): ?>
                <div class="notification-device-row">
                    <div class="notification-device-details">
                        <strong><?= h(notificationDeviceLabel($device['user_agent'] ?? null)) ?></strong>
                        <small>Registered: <?= h((string)($device['created_at'] ?? 'Unknown')) ?></small>
                        <small>Last seen: <?= h((string)($device['last_seen_at'] ?? 'Not recorded')) ?></small>
                        <?php if (!empty($device['last_push_at'])): ?>
                            <small>Last push test/alert: <?= h((string)$device['last_push_at']) ?> · <?= h((string)($device['last_push_status'] ?? 'unknown')) ?></small>
                        <?php endif; ?>
                        <?php if (($device['last_push_status'] ?? '') === 'failed' && !empty($device['last_push_error'])): ?>
                            <small class="monitor-warning">Last push error: <?= h((string)$device['last_push_error']) ?></small>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="notification-device-test-form">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="test_device_notification">
                        <input type="hidden" name="device_id" value="<?= (int)$device['id'] ?>">
                        <button type="submit">Test notification</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card daily-forecast-alert-card" aria-labelledby="dailyForecastAlertTitle">
    <div class="section-heading">
        <div>
            <h2 id="dailyForecastAlertTitle">Daily forecast notification</h2>
            <p class="muted">Get one daily notification if any of your selected conditions are forecast for today. This uses the existing push notification setup.</p>
        </div>
        <span class="status-pill <?= $dailyForecastAlertEnabled ? 'status-good' : 'status-warn' ?>"><?= $dailyForecastAlertEnabled ? '● Enabled' : '● Disabled' ?></span>
    </div>
    <form method="post" class="daily-forecast-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_daily_forecast_alert">

        <label class="daily-forecast-enable">
            <input type="checkbox" name="daily_forecast_alert_enabled" value="1" <?= $dailyForecastAlertEnabled ? 'checked' : '' ?>>
            <span><strong>Enable daily forecast notification</strong><small>GaugeIQ checks today's forecast once per local day.</small></span>
        </label>

        <div class="daily-forecast-time-row">
            <div>
                <label for="daily_forecast_alert_time">Notification time</label>
                <input id="daily_forecast_alert_time" name="daily_forecast_alert_time" type="time" value="<?= h($dailyForecastAlertTime) ?>" required>
            </div>
            <p class="alert-help muted">Timezone: <?= h($historyTimezone->getName()) ?></p>
        </div>

        <fieldset class="daily-forecast-options">
            <legend>Notify me when today's forecast includes</legend>
            <label class="daily-forecast-option">
                <input type="checkbox" name="daily_forecast_alert_rain" value="1" <?= $dailyForecastAlertRain ? 'checked' : '' ?>>
                <span><strong>Rain</strong><small>At least 50% forecast chance of precipitation, or 1 mm or more of expected rain.</small></span>
            </label>
            <label class="daily-forecast-option">
                <input type="checkbox" name="daily_forecast_alert_wind" value="1" <?= $dailyForecastAlertWind ? 'checked' : '' ?>>
                <span><strong>Strong winds</strong><small>Daily maximum wind speed of at least 40 km/h, or gusts reaching 60 km/h.</small></span>
            </label>
            <label class="daily-forecast-option">
                <input type="checkbox" name="daily_forecast_alert_severe" value="1" <?= $dailyForecastAlertSevere ? 'checked' : '' ?>>
                <span><strong>Severe weather</strong><small>Heavy precipitation, violent showers, heavy snow, thunderstorms or gusts reaching 70 km/h.</small></span>
            </label>
        </fieldset>

        <label class="daily-forecast-option">
            <input type="checkbox" name="daily_forecast_alert_always" value="1" <?= $dailyForecastAlertAlways ? 'checked' : '' ?>>
            <span><strong>Notify me even when no selected conditions are forecast</strong><small>Send the daily forecast notification anyway, confirming that none of the selected conditions met GaugeIQ's alert thresholds.</small></span>
        </label>

        <button type="submit">Save daily forecast alert</button>
        <p class="alert-help muted">The daily check runs with your existing scheduled monitor. With a 30-minute cron, delivery is at the first check at or after your chosen time (normally within 30 minutes). Only selected conditions trigger a notification; if several match, GaugeIQ combines them into one message.</p>
        <?php if ($dailyForecastAlertLastDate !== ''): ?>
            <p class="daily-forecast-last-status" role="status">Last daily forecast check: <?= h($dailyForecastAlertLastDate) ?><?= $dailyForecastAlertLastStatus !== '' ? ' · ' . h($dailyForecastAlertLastStatus) : '' ?></p>
        <?php endif; ?>
        <?php if ($dailyForecastAlertLastError !== ''): ?>
            <p class="monitor-warning">Daily forecast alert issue: <?= h($dailyForecastAlertLastError) ?></p>
        <?php endif; ?>
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
                    <option value="weather_stability">Weather Volatility Index (WVI)</option>
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
                <input id="value" name="value" type="number" min="0" max="10" step="1" value="3">
                <p id="weatherVolatilityHelp" class="alert-help muted" hidden>Alert when the WVI rises to or above this score (0–10). This uses the same six-hour weighted score shown on the dashboard.</p>
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
                <div class="alert-meta"><?= $rule['metric'] === 'weather_stability' ? 'Weather Volatility Index (WVI)' : h(ucwords(str_replace('_', ' ', (string)$rule['metric']))) ?> · <?= h(ucwords(str_replace('_', ' ', (string)$rule['condition_type']))) ?> · cooldown <?= (int)$rule['cooldown_minutes'] ?> min</div>
                <div class="alert-config readable">
                    <?php if ($rule['metric'] === 'pressure'): ?>Air pressure <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['value'] ?? 0), 1)) ?> hPa
                    <?php elseif ($rule['metric'] === 'humidity'): ?>Humidity <?= h(ucwords(str_replace('_', ' ', $rule['condition_type']))) ?> <?= h(number_format((float)($ruleConfig['value'] ?? 0), 0)) ?>%
                    <?php elseif ($rule['metric'] === 'weather_stability'): ?>Weather Volatility Index (WVI) rises to or above <?= h(number_format((float)($ruleConfig['value'] ?? 0), 0)) ?>/10
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
                        <?php foreach (['pressure'=>'Air pressure','humidity'=>'Humidity','wind_speed'=>'Wind speed','weather_stability'=>'Weather Volatility Index (WVI)','wind_direction'=>'Wind direction','wind'=>'Wind speed + direction'] as $v=>$label): ?><option value="<?= $v ?>" <?= $rule['metric']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
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


</main>
<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script src="js/alerts.js?v=20261016-wvi-alerts" defer></script>
</body>
</html>
