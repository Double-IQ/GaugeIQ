<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function moonTideRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    moonTideRespond(405, ['message' => 'Method not allowed.']);
}
$locationId = filter_var($_GET['location'] ?? '1', FILTER_VALIDATE_INT);
if ($locationId === false || !in_array((int)$locationId, [1, 2], true)) {
    moonTideRespond(400, ['message' => 'A valid GaugeIQ location is required.']);
}
try {
    $config = require __DIR__ . '/../../config/local.php';
    require_once __DIR__ . '/../../app/Database.php';
    require_once __DIR__ . '/../../app/MoonTideSnapshotService.php';
    $db = new Database($config);
    $service = new MoonTideSnapshotService($db->pdo(), $config);
    // Reuse a fresh hourly snapshot. If it is older than one hour, refresh
    // on the server; the browser never calls astronomy or tide providers.
    try {
        $service->refreshIfDue((int)$locationId, 3600);
    } catch (Throwable $refreshError) {
        error_log('GaugeIQ stale Moon & Tide snapshot refresh failed: ' . $refreshError->getMessage());
    }
    $stmt = $db->pdo()->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute(['moon_tide_snapshot_location_' . (int)$locationId]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) moonTideRespond(503, ['message' => 'The hourly Moon & Tide snapshot has not been generated yet.']);
    $snapshot = json_decode((string)$raw, true);
    if (!is_array($snapshot) || empty($snapshot['generatedAt']) || !isset($snapshot['astronomy'])) {
        moonTideRespond(503, ['message' => 'The saved Moon & Tide snapshot is invalid.']);
    }
    moonTideRespond(200, $snapshot);
} catch (Throwable $error) {
    error_log('GaugeIQ Moon & Tide snapshot API failed: ' . $error->getMessage());
    moonTideRespond(500, ['message' => 'The Moon & Tide snapshot is temporarily unavailable.']);
}
