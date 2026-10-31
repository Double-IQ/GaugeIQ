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
header('Location: ' . (($_GET['return_to'] ?? '') === 'admin' ? 'admin.php?history_deleted=1' : './?history_deleted=1')); exit;
