<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
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

$enabledRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE location_id = 1 AND enabled = 1')->fetchColumn();
$secondLocationEnabled = gaugeSetting($pdo, 'second_location_enabled', '0') === '1';
$secondLocationName = gaugeSetting($pdo, 'second_location_name', 'Location 2') ?? 'Location 2';
$secondTimezoneName = gaugeSetting($pdo, 'location_timezone_location_2', (string)($config['app']['timezone'] ?? 'UTC')) ?? 'UTC';
try { $secondTimezone = new DateTimeZone($secondTimezoneName); } catch (Throwable) { $secondTimezone = new DateTimeZone('UTC'); }
$secondMonitorSuccess = null;
$secondMonitorError = '';
$secondEnabledRuleCount = 0;
if ($secondLocationEnabled) {
    $secondMonitorStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $secondMonitorStmt->execute(['monitor_last_success_at_location_2']);
    $secondMonitorValue = $secondMonitorStmt->fetchColumn();
    $secondMonitorSuccess = is_string($secondMonitorValue) && $secondMonitorValue !== '' ? $secondMonitorValue : null;
    $secondErrorStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $secondErrorStmt->execute(['monitor_last_error_location_2']);
    $secondErrorValue = $secondErrorStmt->fetchColumn();
    $secondMonitorError = $secondErrorValue === false ? '' : (string)$secondErrorValue;
    $secondRulesStmt = $pdo->prepare('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE location_id = 2 AND enabled = 1');
    $secondRulesStmt->execute();
    $secondEnabledRuleCount = (int)$secondRulesStmt->fetchColumn();
}

$checkMinutes = max(1, (int)$config['pressure']['check_interval_minutes']);
$monitorCronMinutes = 60;
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
<meta name="theme-color" content="#07131d">
<link rel="stylesheet" href="css/app.css?v=20261101-admin-action-buttons-v2">
<link rel="stylesheet" href="css/alerts.css?v=20261029-notification-devices">
<link rel="stylesheet" href="css/admin-console.css?v=20261116-console-v1">
<link rel="stylesheet" href="css/admin-interactions.css?v=20261116-hud-feedback">
<title>GaugeIQ Administration</title>
</head>
<body class="gaugeiq-admin-page">
<main class="shell admin-shell admin-console">
<header class="admin-console-header">
    <a class="admin-brand" href="./" aria-label="GaugeIQ dashboard">
        <img src="branding/gaugeiq-logo-dark-background-original.png" alt="GaugeIQ">
    </a>
    <div class="admin-console-heading">
        <p class="admin-kicker">SYSTEM CONTROL CENTRE</p>
        <h1>Administration</h1>
        <p class="admin-user">Signed in as <?= h(AdminAuth::username()) ?></p>
    </div>
    <nav class="admin-console-nav" aria-label="Admin navigation">
        <a class="admin-console-link" href="./">Back to GaugeIQ</a>
        <a class="admin-console-link admin-signout" href="logout.php">Sign out</a>
    </nav>
</header>

<?php if ($updated !== ''): ?>
<section class="admin-notice admin-notice-success" role="status"><strong>GaugeIQ was updated to <?= h($updated) ?>.</strong><p>Return to the dashboard to verify the new release.</p></section>
<?php endif; ?>
<?php if ($updateError): ?>
<section class="admin-notice admin-notice-error" role="alert"><strong>GaugeIQ could not complete the update.</strong><p>For details, open <code>storage/update/manual-update.log</code> in cPanel File Manager inside the GaugeIQ project folder.</p></section>
<?php endif; ?>
<?php if ($notice): ?><div class="admin-notice"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="admin-notice admin-notice-error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="admin-panel admin-monitor-panel" aria-labelledby="monitoringTitle">
    <div class="admin-panel-heading">
        <div><p class="admin-kicker">LIVE SYSTEM OVERVIEW</p><h2 id="monitoringTitle">System monitor</h2><p class="admin-muted">GaugeIQ's scheduled hourly background monitor.</p></div>
        <span class="admin-status-pill <?= $monitorHealthy ? 'is-good' : 'is-warn' ?>"><span aria-hidden="true"></span><?= $monitorHealthy ? 'Monitoring active' : 'Check required' ?></span>
    </div>
    <div class="admin-metrics-grid">
        <div class="admin-metric"><span>Last successful check</span><strong><?= $lastMonitorAt ? htmlspecialchars(date('H:i', (int)strtotime($lastMonitorAt)), ENT_QUOTES) : 'Not yet' ?></strong><small><?= $lastMonitorAt ? htmlspecialchars(date('d M Y', (int)strtotime($lastMonitorAt)), ENT_QUOTES) : 'No successful check recorded' ?></small></div>
        <div class="admin-metric"><span>Next expected check</span><strong><?= $nextMonitorAt ? htmlspecialchars(date('H:i', $nextMonitorAt), ENT_QUOTES) : 'Waiting' ?></strong><small><?= $nextMonitorAt ? htmlspecialchars(date('d M Y', $nextMonitorAt), ENT_QUOTES) : 'Awaiting scheduled monitor' ?></small></div>
        <div class="admin-metric"><span>Active alert rules</span><strong><?= $enabledRuleCount ?></strong><small>Location 1</small></div>
    </div>
    <?php if ($monitorError): ?><p class="admin-monitor-warning">Location 1 · <?= htmlspecialchars($monitorError, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($secondLocationEnabled):
        $secondMonitorAge = $secondMonitorSuccess ? time() - (int)strtotime($secondMonitorSuccess) : null;
        $secondMonitorHealthy = $secondMonitorAge !== null && $secondMonitorAge <= ($monitorCronMinutes * 60 * 1.5);
        $secondNextMonitorAt = $secondMonitorSuccess ? strtotime($secondMonitorSuccess) + ($monitorCronMinutes * 60) : null;
    ?>
    <div class="admin-location-monitor">
        <div class="admin-location-monitor-heading"><div><h3><?= h($secondLocationName) ?></h3><p class="admin-muted">Independent scheduled monitoring</p></div><span class="admin-status-pill <?= $secondMonitorHealthy ? 'is-good' : 'is-warn' ?>"><span aria-hidden="true"></span><?= $secondMonitorHealthy ? 'Monitoring active' : 'Check required' ?></span></div>
        <div class="admin-metrics-grid">
            <div class="admin-metric"><span>Last successful check</span><strong><?= $secondMonitorSuccess ? (new DateTimeImmutable('@' . (int)strtotime($secondMonitorSuccess)))->setTimezone($secondTimezone)->format('H:i') : 'Not yet' ?></strong><small><?= $secondMonitorSuccess ? (new DateTimeImmutable('@' . (int)strtotime($secondMonitorSuccess)))->setTimezone($secondTimezone)->format('d M Y') : 'No successful check recorded' ?></small></div>
            <div class="admin-metric"><span>Next expected check</span><strong><?= $secondNextMonitorAt ? (new DateTimeImmutable('@' . $secondNextMonitorAt))->setTimezone($secondTimezone)->format('H:i') : 'Waiting' ?></strong><small><?= $secondNextMonitorAt ? (new DateTimeImmutable('@' . $secondNextMonitorAt))->setTimezone($secondTimezone)->format('d M Y') : 'Awaiting scheduled monitor' ?></small></div>
            <div class="admin-metric"><span>Active alert rules</span><strong><?= $secondEnabledRuleCount ?></strong><small><?= h($secondLocationName) ?></small></div>
        </div>
        <?php if ($secondMonitorError): ?><p class="admin-monitor-warning"><?= htmlspecialchars($secondMonitorError, ENT_QUOTES) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<div class="admin-section-label"><span>CONTROL MODULES</span><i></i></div>
<div class="admin-module-grid">
    <a class="admin-module admin-module-alerts weather-alerts-link" href="alerts.php?location=1">
        <div class="admin-module-icon" aria-hidden="true">◉</div><p class="admin-kicker">WEATHER ALERTS</p><h2>Alerts</h2>
    </a>

    <a class="admin-module location-history-link-card" href="location-history.php">
        <div class="admin-module-icon" aria-hidden="true">⌖</div><p class="admin-kicker">CONFIGURATION</p><h2>Location &amp; history</h2>
    </a>

    <a class="admin-module formula-console-link-card" href="formula-console.php">
        <div class="admin-module-icon" aria-hidden="true">◈</div><p class="admin-kicker">FORECAST ENGINE</p><h2>Formula Console</h2>
    </a>

    <label class="admin-module appearance-card" for="themeSelect">
        <div class="admin-module-icon" aria-hidden="true">⚙</div><p class="admin-kicker">DISPLAY PREFERENCES</p><h2>Appearance</h2>
        <span class="admin-theme-label">Interface theme</span>
        <select id="themeSelect" class="theme-select admin-theme-select" aria-label="Appearance">
            <option value="system">Follow device</option><option value="light">Light</option><option value="dark">Dark</option>
        </select>
    </label>
</div>

<section class="admin-panel admin-updates-panel" aria-labelledby="updatesTitle">
    <div class="admin-updates-icon" aria-hidden="true">↻</div>
    <div class="admin-updates-copy"><p class="admin-kicker">MAINTENANCE</p><h2 id="updatesTitle">Software updates</h2><p id="adminUpdateStatus" class="admin-muted">Checking for the latest release…</p></div>
    <div id="adminUpdateDetails" class="admin-update-details" hidden>
        <p><strong id="adminLatestVersion"></strong></p>
        <form method="post" action="admin-update.php">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="version" id="adminUpdateVersion">
            <button class="admin-console-button button-link" type="submit">Install update <span aria-hidden="true">→</span></button>
        </form>
    </div>
</section>

<footer class="admin-console-footer"><span>GAUGЕIQ · ADMINISTRATION</span><a href="./">Return to dashboard</a></footer>

<script src="js/admin-interactions.js?v=20261116-hud-feedback" defer></script>
<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script src="js/alerts.js?v=20261016-wvi-alerts" defer></script>
</main>
</body>
</html>