<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function tideApiRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    tideApiRespond(405, ['message' => 'Method not allowed.']);
}

$latitude = filter_var($_GET['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($_GET['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false ||
    !is_finite((float)$latitude) || !is_finite((float)$longitude) ||
    abs((float)$latitude) > 90 || abs((float)$longitude) > 180) {
    tideApiRespond(400, ['message' => 'Valid latitude and longitude are required.']);
}

$mode = (string)($_GET['mode'] ?? 'extremes');
if (!in_array($mode, ['extremes', 'timeline'], true)) {
    tideApiRespond(400, ['message' => 'Unsupported tide prediction mode.']);
}

$start = (string)($_GET['start'] ?? '');
$end = (string)($_GET['end'] ?? '');
$startTime = strtotime($start);
$endTime = strtotime($end);
if ($startTime === false || $endTime === false ||
    $endTime <= $startTime || $endTime - $startTime > 7 * 86400) {
    tideApiRespond(400, ['message' => 'A valid prediction window of up to 7 days is required.']);
}

if (!function_exists('curl_init')) {
    tideApiRespond(503, ['message' => 'The GaugeIQ server does not have cURL enabled for tide requests.']);
}

$queryParams = [
    'start' => gmdate('Y-m-d\\TH:i:s\\Z', $startTime),
    'end' => gmdate('Y-m-d\\TH:i:s\\Z', $endTime),
    'units' => 'meters',
];
$stationId = trim((string)($_GET['station'] ?? ''));
if ($stationId !== '') {
    if ($mode !== 'timeline' || !preg_match('/^([a-z0-9_-]+)\\/([a-z0-9._@-]+)$/i', $stationId, $stationMatch)) {
        tideApiRespond(400, ['message' => 'A valid prediction station ID is required for a station-specific tide timeline.']);
    }
    // Reuse the exact station resolved by the high/low request. This avoids
    // a second nearest-station lookup and keeps the curve on the same datum.
    $url = 'https://api.openwaters.io/tides/stations/' . rawurlencode($stationMatch[1]) . '/' . rawurlencode($stationMatch[2]) . '/timeline?' . http_build_query($queryParams);
} else {
    $queryParams['latitude'] = (float)$latitude;
    $queryParams['longitude'] = (float)$longitude;
    $url = 'https://api.openwaters.io/tides/' . $mode . '?' . http_build_query($queryParams);
}
$curl = curl_init($url);
if ($curl === false) {
    tideApiRespond(503, ['message' => 'Unable to initialise the tide prediction request.']);
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_USERAGENT => 'GaugeIQ-TideMonitor/1.0',
]);
$body = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
$errorCode = curl_errno($curl);
curl_close($curl);

if ($body === false) {
    error_log('GaugeIQ tide API upstream ' . $mode . ' request failed (cURL ' . $errorCode . '): ' . $error);
    tideApiRespond(502, ['message' => 'GaugeIQ could not reach the tide prediction provider.']);
}

$decoded = json_decode((string)$body, true);
if (!is_array($decoded)) {
    error_log('GaugeIQ tide API returned a non-JSON response (HTTP ' . $status . ').');
    tideApiRespond(502, ['message' => 'The tide prediction provider returned an invalid response.']);
}

if ($status < 200 || $status >= 300) {
    error_log('GaugeIQ tide provider returned HTTP ' . $status . ' for ' . $mode . ' request.');
}

tideApiRespond($status >= 200 && $status < 600 ? $status : 502, $decoded);
