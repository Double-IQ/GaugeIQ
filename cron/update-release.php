<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$configPath = $root . '/config/local.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "GaugeIQ is not installed; release update skipped.\n");
    exit(2);
}

$config = require $configPath;
$driver = strtolower((string)($config['database']['driver'] ?? 'sqlite'));
if ($driver !== 'sqlite') {
    fwrite(STDOUT, "GaugeIQ automatic release installation is currently enabled for SQLite installations only; no changes were made.\n");
    exit(0);
}

require_once $root . '/app/Version.php';
require_once $root . '/app/GaugeIQUpdater.php';

try {
    $currentVersion = GaugeIQVersion::VERSION;
    $updater = new GaugeIQUpdater($root);
    $release = $updater->latestReleaseInfo();
    $latestVersion = (string)$release['latest_version'];

    if (version_compare($latestVersion, $currentVersion, '<=')) {
        printf("GaugeIQ is up to date (v%s).\n", $currentVersion);
        exit(0);
    }

    if (!$release['assets_ready']) {
        printf(
            "GaugeIQ v%s is published, but its package/checksum assets are not ready; the next cron run will retry.\n",
            $latestVersion
        );
        exit(0);
    }

    $result = $updater->installRelease($latestVersion);
    printf("GaugeIQ update completed successfully: v%s -> v%s.\n", $currentVersion, $result['version']);
} catch (Throwable $e) {
    error_log('GaugeIQ scheduled release update failed: ' . $e->getMessage());
    fwrite(STDERR, 'GaugeIQ scheduled release update failed: ' . $e->getMessage() . "\n");
    exit(1);
}
