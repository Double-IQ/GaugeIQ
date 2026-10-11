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
require_once __DIR__ . '/../../app/Database.php';
require_once __DIR__ . '/../../app/Schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['endpoint']) || !is_string($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid push subscription.']);
    exit;
}

$endpoint = trim($input['endpoint']);
$keys = $input['keys'] ?? null;
if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL) || !is_array($keys)
    || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid push subscription.']);
    exit;
}

$subscription = [
    'endpoint' => $endpoint,
    'expirationTime' => $input['expirationTime'] ?? null,
    'keys' => [
        'p256dh' => $keys['p256dh'],
        'auth' => $keys['auth'],
    ],
];

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $now = gmdate('c');
    $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 512);
    $json = json_encode($subscription, JSON_THROW_ON_ERROR);

    $existing = $pdo->prepare('SELECT id FROM gaugeiq_push_subscriptions WHERE endpoint = ?');
    $existing->execute([$endpoint]);
    $id = $existing->fetchColumn();

    if ($id === false) {
        $stmt = $pdo->prepare(
            'INSERT INTO gaugeiq_push_subscriptions
                (endpoint, subscription_json, created_at, updated_at, user_agent, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$endpoint, $json, $now, $now, $userAgent !== '' ? $userAgent : null, $now]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE gaugeiq_push_subscriptions
             SET subscription_json = ?, updated_at = ?, user_agent = ?, last_seen_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$json, $now, $userAgent !== '' ? $userAgent : null, $now, (int)$id]);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('GaugeIQ public push subscription save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'GaugeIQ could not save this notification subscription.']);
}
