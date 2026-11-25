<?php
declare(strict_types=1);

/**
 * Same-origin Open-Meteo proxy for GaugeIQ activity cards.
 * Only the two documented forecast hosts and a small parameter allowlist are accepted.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
header('X-Content-Type-Options: nosniff');

function fail(int $status, string $message): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$endpoint = (string)($_GET['endpoint'] ?? '');
$hosts = ['marine' => 'https://marine-api.open-meteo.com/v1/marine', 'forecast' => 'https://api.open-meteo.com/v1/forecast'];
if (!isset($hosts[$endpoint])) fail(400, 'Unsupported forecast endpoint.');
$lat = filter_var($_GET['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lon = filter_var($_GET['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lon === false || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) fail(400, 'Invalid coordinates.');
$hourly = (string)($_GET['hourly'] ?? '');
$allowed = $endpoint === 'marine'
    ? ['wave_height','wave_direction','wave_period','wind_wave_height','wind_wave_direction','wind_wave_period','swell_wave_height','swell_wave_direction','swell_wave_period','sea_surface_temperature','sea_level_height_msl']
    : ['wind_speed_10m','wind_gusts_10m','wind_direction_10m','precipitation_probability','cloud_base','cape'];
$fields = array_values(array_unique(array_filter(explode(',', $hourly), static fn($v) => in_array($v, $allowed, true))));
if (!$fields || count($fields) !== count(explode(',', $hourly))) fail(400, 'Invalid forecast variables.');
$days = filter_var($_GET['forecast_days'] ?? 7, FILTER_VALIDATE_INT);
if ($days === false || $days < 1 || $days > 7) fail(400, 'Invalid forecast range.');
$timezone = (string)($_GET['timezone'] ?? 'auto');
if ($timezone !== 'auto' && !in_array($timezone, timezone_identifiers_list(), true)) fail(400, 'Invalid timezone.');
$query = http_build_query(['latitude'=>$lat,'longitude'=>$lon,'hourly'=>implode(',', $fields),'forecast_days'=>$days,'timezone'=>$timezone]);
$ch = curl_init($hosts[$endpoint] . '?' . $query);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_USERAGENT=>'GaugeIQ/1.0']);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);
if ($body === false) fail(502, 'Forecast provider connection failed: ' . $error);
if ($status !== 200) fail(502, 'Forecast provider returned HTTP ' . $status . '.');
json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE) fail(502, 'Forecast provider returned invalid JSON.');
echo $body;
