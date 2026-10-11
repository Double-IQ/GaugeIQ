<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../../config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'GaugeIQ is not installed.']);
    exit;
}
$config = require $configPath;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$publicKey = trim((string)($config['push']['public_key'] ?? ''));
if ($publicKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Push notifications are not configured.']);
    exit;
}

echo json_encode(['publicKey' => $publicKey], JSON_UNESCAPED_SLASHES);
