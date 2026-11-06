<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!is_file($configPath)) {
    http_response_code(503);
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}

try {
    $config = require $configPath;
    require_once __DIR__ . '/../../app/Database.php';

    $pdo = (new Database($config))->pdo();
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute(['monitor_last_success_at']);
    $lastSuccess = $stmt->fetchColumn();

    echo json_encode([
        'last_success_at' => is_string($lastSuccess) && $lastSuccess !== '' ? $lastSuccess : null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('GaugeIQ monitor status API failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to read GaugeIQ monitor status.']);
}
