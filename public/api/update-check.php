<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/GaugeIQUpdater.php';
require_once __DIR__ . '/../../app/Version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

// Release metadata is public; this endpoint reveals no configuration or secrets.

try {
    $updater = new GaugeIQUpdater(dirname(__DIR__, 2));
    $release = $updater->latestReleaseInfo();
    $currentVersion = GaugeIQVersion::VERSION;
    $latestVersion = (string)$release['latest_version'];

    echo json_encode([
        'ok' => true,
        'current_version' => $currentVersion,
        'latest_version' => $latestVersion,
        'update_available' => version_compare($latestVersion, $currentVersion, '>'),
        'package_ready' => (bool)$release['assets_ready'],
        'release_url' => (string)$release['release_url'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('GaugeIQ update check failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to check GitHub Releases right now.',
    ]);
}
