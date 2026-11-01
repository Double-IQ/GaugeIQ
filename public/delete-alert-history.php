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
$stmt=$db->pdo()->prepare('DELETE FROM gaugeiq_alert_events WHERE id = ?'); $stmt->execute([$id]);
$returnTo = (string)($_GET['return_to'] ?? '');
$destination = $returnTo === 'admin' ? 'admin.php' : ($returnTo === 'alerts' ? 'alerts.php' : './');
header('Location: ' . $destination . '?history_deleted=1'); exit;
