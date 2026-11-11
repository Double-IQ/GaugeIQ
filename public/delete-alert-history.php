<?php
declare(strict_types=1);
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
$config = require __DIR__ . '/../config/local.php';
AdminAuth::startSession();
AdminAuth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request.'); }
$db = new Database($config); migrateDatabase($db->pdo());
$id=(int)($_POST['id']??0);
if($id<1){http_response_code(400);exit('Invalid alert history record.');}
$returnTo = (string)($_GET['return_to'] ?? '');
$locationId = (int)($_GET['location'] ?? 0);
if ($returnTo === 'alerts' && in_array($locationId, [1, 2], true)) {
    $stmt = $db->pdo()->prepare(
        'DELETE FROM gaugeiq_alert_events
         WHERE id = ? AND rule_id IN (
             SELECT id FROM gaugeiq_alert_rules WHERE location_id = ?
         )'
    );
    $stmt->execute([$id, $locationId]);
} else {
    $stmt = $db->pdo()->prepare('DELETE FROM gaugeiq_alert_events WHERE id = ?');
    $stmt->execute([$id]);
}
$destination = $returnTo === 'admin' ? 'admin.php' : ($returnTo === 'alerts' ? 'alerts.php' : './');
$query = '?history_deleted=1';
if ($returnTo === 'alerts' && in_array($locationId, [1, 2], true)) $query .= '&location=' . $locationId;
header('Location: ' . $destination . $query); exit;
