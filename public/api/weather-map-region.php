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
    $host=(string)($_SERVER['HTTP_HOST'] ?? '');
    $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($origin === '' || !hash_equals($scheme.'://'.$host, rtrim($origin,'/'))) {
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
