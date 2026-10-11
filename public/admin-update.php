<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/AdminAuth.php';
require_once __DIR__ . '/../app/GaugeIQUpdater.php';
require_once __DIR__ . '/../app/Version.php';

AdminAuth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid update request.');
}

$version = trim((string)($_POST['version'] ?? ''));

try {
    $updater = new GaugeIQUpdater(dirname(__DIR__));
    $result = $updater->installRelease($version);
    header('Location: admin.php?updated=' . rawurlencode($result['version']));
    exit;
} catch (Throwable $e) {
    error_log('GaugeIQ update failed: ' . $e->getMessage());
    header('Location: admin.php?update_error=1');
    exit;
}
