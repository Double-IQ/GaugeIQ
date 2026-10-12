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
if ($version === '') {
    header('Location: admin.php?update_error=1');
    exit;
}

try {
    $updater = new GaugeIQUpdater(dirname(__DIR__));
    $result = $updater->installRelease($version);
    header('Location: admin.php?updated=' . rawurlencode($result['version']));
    exit;
} catch (Throwable $e) {
    $logDirectory = dirname(__DIR__) . '/storage/update';
    if (!is_dir($logDirectory)) { mkdir($logDirectory, 0750, true); }
    $logFile = $logDirectory . '/manual-update.log';
    $logEntry = '[' . date('c') . '] ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    $logged = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    if ($logged === false) { error_log('GaugeIQ update failed: ' . $e->getMessage()); }
    header('Location: admin.php?update_error=1');
    exit;
}
