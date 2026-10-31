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

$enabledRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE enabled = 1')->fetchColumn();

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
    <a class="secondary button-link" href="formulas.php">Formula Console</a>
    <a class="secondary button-link" href="logout.php">Sign out</a>
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

<section class="card location-history-link-card">
    <div class="section-heading">
        <div><h2>Location &amp; historical data</h2><p class="muted">Manage GaugeIQ's configured location and check historical dataset status.</p></div>
        <a class="secondary button-link" href="location-history.php">Open location &amp; history</a>
    </div>
</section>

    <section class="card alert-history-card" aria-labelledby="alertHistoryTitle">
        <div class="section-heading">
            <div><h2 id="alertHistoryTitle">Alert history</h2><p class="muted">Recent conditions that triggered your saved rules.</p></div>
        </div>
        <?php if (!$recentAlerts): ?>
            <p class="muted empty-history">No alerts have been triggered yet.</p>
        <?php else: ?>
            <div class="alert-history-list">
                <?php foreach ($recentAlerts as $alert): ?>
                    <article class="alert-history-item">
                        <div class="alert-history-icon">!</div>
                        <div class="alert-history-content">
                            <strong><?= h((string)($alert['name'] ?: 'GaugeIQ alert')) ?></strong>
                            <p><?= h((string)$alert['message']) ?></p>
                            <time datetime="<?= h((string)$alert['observed_at']) ?>"><?= h(date('d M, H:i', (int)strtotime((string)$alert['observed_at']))) ?></time>
                            <form method="post" action="delete-alert-history.php?return_to=admin" onsubmit="return confirm('Delete this alert history record?')">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <button type="submit" class="alert-history-delete" aria-label="Delete record" title="Delete record"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-3 6h12l-.8 11.2a2 2 0 0 1-2 1.8H8.8a2 2 0 0 1-2-1.8L6 9Zm4 2v8h2v-8h-2Zm4 0v8h2v-8h-2Z"/></svg></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<section class="admin-hero card weather-alerts-link">
    <div>
        <p class="eyebrow">WEATHER ALERTS</p>
        <h2>Alerts</h2>
        <p class="muted">Manage comparison settings, WVI threshold, notification rules and saved alerts.</p>
        <a class="secondary button-link" href="alerts.php">Open Weather Alerts</a>
    </div>
    <div class="alert-summary">
        <strong><?= $enabledRuleCount ?></strong>
        <span>active rule<?= $enabledRuleCount === 1 ? '' : 's' ?></span>
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
    <a class="secondary button-link" href="./">Back to GaugeIQ</a>
</section>


<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script src="js/alerts.js?v=20261016-wvi-alerts" defer></script>
</main>

</body>
</html>