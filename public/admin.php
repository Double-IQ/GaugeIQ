<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
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
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css?v=20261101-admin-action-buttons-v2">
<link rel="stylesheet" href="css/alerts.css?v=20261029-notification-devices">
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
    <nav class="admin-header-actions" aria-label="Admin navigation">
        <a class="secondary button-link" href="logout.php">Sign out</a>
        <a class="secondary button-link" href="./">Back to GaugeIQ</a>
    </nav>
</header>

<?php if ($updated !== ''): ?>
<section class="card success"><strong>GaugeIQ was updated to <?= h($updated) ?>.</strong><p>Return to the dashboard to verify the new release.</p></section>
<?php endif; ?>
<?php if ($updateError): ?>
<section class="card error"><strong>GaugeIQ could not complete the update.</strong><p>For details, open <code>storage/update/manual-update.log</code> in cPanel File Manager inside the GaugeIQ project folder.</p></section>
<?php endif; ?>
<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="card monitoring-card" aria-labelledby="monitoringTitle">
    <div class="section-heading">
        <div><h2 id="monitoringTitle">Monitoring status</h2><p class="muted">GaugeIQ's scheduled hourly background monitor.</p></div>
        <span class="status-pill <?= $monitorHealthy ? 'status-good' : 'status-warn' ?>"><?= $monitorHealthy ? '● Monitoring active' : '● Check required' ?></span>
    </div>
    <div class="status-grid">
        <div><span>Last successful check</span><strong><?= $lastMonitorAt ? htmlspecialchars(date('d M, H:i', (int)strtotime($lastMonitorAt)), ENT_QUOTES) : 'Not yet' ?></strong></div>
        <div><span>Next expected check</span><strong><?= $nextMonitorAt ? htmlspecialchars(date('d M, H:i', $nextMonitorAt), ENT_QUOTES) : 'Waiting for cron' ?></strong></div>
        <div><span>Active alert rules</span><strong><?= $enabledRuleCount ?></strong></div>
    </div>
    <?php if ($monitorError): ?><p class="monitor-warning">Location 1: <?= htmlspecialchars($monitorError, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if ($secondLocationEnabled):
        $secondMonitorAge = $secondMonitorSuccess ? time() - (int)strtotime($secondMonitorSuccess) : null;
        $secondMonitorHealthy = $secondMonitorAge !== null && $secondMonitorAge <= ($monitorCronMinutes * 60 * 1.5);
        $secondNextMonitorAt = $secondMonitorSuccess ? strtotime($secondMonitorSuccess) + ($monitorCronMinutes * 60) : null;
    ?>
    <div class="section-heading" style="margin-top:18px"><div><h3><?= h($secondLocationName) ?></h3><p class="muted">Independent scheduled monitoring status.</p></div><span class="status-pill <?= $secondMonitorHealthy ? 'status-good' : 'status-warn' ?>"><?= $secondMonitorHealthy ? '● Monitoring active' : '● Check required' ?></span></div>
    <div class="status-grid">
        <div><span>Last successful check</span><strong><?= $secondMonitorSuccess ? (new DateTimeImmutable('@' . (int)strtotime($secondMonitorSuccess)))->setTimezone($secondTimezone)->format('d M, H:i') : 'Not yet' ?></strong></div>
        <div><span>Next expected check</span><strong><?= $secondNextMonitorAt ? (new DateTimeImmutable('@' . $secondNextMonitorAt))->setTimezone($secondTimezone)->format('d M, H:i') : 'Waiting for cron' ?></strong></div>
        <div><span>Active alert rules</span><strong><?= $secondEnabledRuleCount ?></strong></div>
    </div>
    <?php if ($secondMonitorError): ?><p class="monitor-warning"><?= htmlspecialchars($secondMonitorError, ENT_QUOTES) ?></p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="admin-hero card weather-alerts-link">
    <div>
        <p class="eyebrow">WEATHER ALERTS</p>
        <h2>Alerts</h2>
        <p class="muted">Manage comparison settings, WVI threshold, notification rules and saved alerts.</p>
        <a class="secondary button-link" href="alerts.php?location=1">Manage Location 1 Alerts</a>
        <?php if ($secondLocationEnabled): ?><a class="secondary button-link" href="alerts.php?location=2">Manage <?= h($secondLocationName) ?> Alerts</a><?php endif; ?>
    </div>
    
</section>

<section class="card location-history-link-card">
    <div class="section-heading">
        <div><h2>Location &amp; historical data</h2><p class="muted">Manage GaugeIQ's configured location and check historical dataset status.</p></div>
        <a class="secondary button-link" href="location-history.php">Manage Data</a>
    </div>
</section>

<section class="card appearance-card" aria-labelledby="appearanceTitle">
    <div class="section-heading">
        <div><h2 id="appearanceTitle">Appearance</h2><p class="muted">Choose how GaugeIQ looks on this device.</p></div>
        <select id="themeSelect" class="theme-select" aria-label="Appearance">
            <option value="system">Follow device</option>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
        </select>
    </div>
</section>


<section class="card location-history-link-card formula-console-link-card">
    <div class="section-heading">
        <div><h2>Formula Console</h2><p class="muted">Review GaugeIQ's calculations and tune the parameters used by the Weather Volatility Index and Conditions Trend.</p></div>
        <a class="secondary button-link" href="formula-console.php">Open Formula Console</a>
    </div>
</section>


        <?php endforeach; ?>
        <div class="formula-actions">
            <button type="submit" name="formula_action" value="save">Save formula parameters</button>
            <button type="submit" name="formula_action" value="reset" class="secondary" formnovalidate>Reset to defaults</button>
        </div>
    </form>
    <div class="formula-readonly">
        <h3>Other dashboard values</h3>
        <p><strong>Rain Probability</strong> is supplied by the weather forecast provider; GaugeIQ displays the provider's probability rather than inventing its own probability formula.</p>
        <p><strong>Current temperature, humidity, wind, pressure and forecast values</strong> are observations or forecasts from the configured data provider. The dashboard formats and displays them; they are not calculated from the WVI.</p>
        <p><strong>Weather-code severity mapping</strong> currently uses fixed WMO-code groups in the code (clear/cloudy, fog, drizzle, rain, snow and thunderstorm categories). The group mapping itself is documented here but is not editable on this page yet.</p>
    </div>
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
            <button type="submit">Install update</button>
        </form>
    </div>
    <a class="secondary button-link admin-main-back" href="./">Back to GaugeIQ</a>
</section>


<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script src="js/alerts.js?v=20261016-wvi-alerts" defer></script>
</main>

</body>
</html>