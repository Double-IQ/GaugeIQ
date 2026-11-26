<?php
declare(strict_types=1);
$configPath=__DIR__.'/../../config/local.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function regionApiFail(int $status,string $message): never {
    http_response_code($status); echo json_encode(['error'=>$message]); exit;
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST'); regionApiFail(405,'Method not allowed.');
    }
    if (!is_file($configPath)) regionApiFail(503,'GaugeIQ is not installed.');
    $origin=(string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host=strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $originParts=$origin!==''?parse_url($origin):false;
    $originHost=is_array($originParts)?strtolower((string)($originParts['host'] ?? '')):'';
    $originPort=is_array($originParts)?(int)($originParts['port'] ?? 0):0;
    $hostParts=explode(':',$host);
    $hostName=strtolower((string)($hostParts[0] ?? ''));
    $hostPort=isset($hostParts[1])?(int)$hostParts[1]:0;
    if ($originHost==='' || $originHost!==$hostName ||
        ($originPort!==0 && $hostPort!==0 && $originPort!==$hostPort) ||
        !in_array((string)($originParts['scheme'] ?? ''),['https','http'],true)) {
        regionApiFail(403,'Cross-origin settings updates are not allowed.');
    }
    $payload=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($payload)) regionApiFail(400,'Invalid settings payload.');
    $locationId=filter_var($payload['location_id'] ?? null,FILTER_VALIDATE_INT);
    if (!in_array($locationId,[1,2],true)) regionApiFail(400,'Invalid GaugeIQ location.');
    require_once __DIR__.'/../../app/Database.php';
    require_once __DIR__.'/../../app/WeatherMapRegionSettings.php';
    $config=require $configPath;
    $pdo=(new Database($config))->pdo();
    WeatherMapRegionSettings::save($pdo,$locationId,$payload['bounds'] ?? []);
    echo json_encode(['saved'=>true,'location_id'=>$locationId]);
} catch (InvalidArgumentException|JsonException $error) {
    regionApiFail(400,$error->getMessage());
} catch (Throwable $error) {
    error_log('GaugeIQ weather-map region save failed: '.$error->getMessage());
    regionApiFail(503,'Weather-map coverage could not be saved on the server.');
}
