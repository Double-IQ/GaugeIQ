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
require_once __DIR__ . '/../../app/AdminAuth.php';
require_once __DIR__ . '/../../app/Database.php';
require_once __DIR__ . '/../../app/PushService.php';
require_once __DIR__ . '/../../app/Schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
AdminAuth::startSession();

if (!AdminAuth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Admin login is required for the server push test.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}
if (!AdminAuth::verifyCsrf((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token. Refresh GaugeIQ and try again.']);
    exit;
}

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);
    $push = new PushService($config, $pdo);
    $result = $push->sendDetailed(
        'GaugeIQ server test',
        'This notification was sent through GaugeIQ\'s server-side Web Push service.'
    );
    echo json_encode(['ok' => true, ...$result]);
} catch (Throwable $e) {
    error_log('GaugeIQ server push test failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Server push failed. Check the PHP error log for details.']);
}
