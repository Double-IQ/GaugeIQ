<?php
declare(strict_types=1);

require __DIR__ . '/../../app/Database.php';
require __DIR__ . '/../../app/Schema.php';
require __DIR__ . '/../../app/AdminAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function openMeteoHistoryReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    openMeteoHistoryReply(405, ['error' => 'POST is required.']);
}
if (!is_file(__DIR__ . '/../../config/local.php')) {
    openMeteoHistoryReply(503, ['error' => 'GaugeIQ is not installed.']);
}
$config = require __DIR__ . '/../../config/local.php';
AdminAuth::startSession();
if (!AdminAuth::check()) {
    openMeteoHistoryReply(401, ['error' => 'Sign in as an administrator to import historical weather.']);
}
$raw = file_get_contents('php://input');
$input = json_decode($raw === false ? '' : $raw, true);
if (!is_array($input) || !AdminAuth::verifyCsrf((string)($input['csrf'] ?? ''))) {
    openMeteoHistoryReply(403, ['error' => 'Your session expired. Reload Admin and try again.']);
}

$source = (string)($input['source'] ?? 'weather');
if (!in_array($source, ['weather', 'forecast'], true)) {
    openMeteoHistoryReply(422, ['error' => 'Choose a supported historical data source.']);
}
$startText = (string)($input['start_date'] ?? '');
$endText = (string)($input['end_date'] ?? '');
$start = DateTimeImmutable::createFromFormat('!Y-m-d', $startText, new DateTimeZone('UTC'));
$end = DateTimeImmutable::createFromFormat('!Y-m-d', $endText, new DateTimeZone('UTC'));
$yesterday = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
if (!$start || $start->format('Y-m-d') !== $startText || !$end || $end->format('Y-m-d') !== $endText || $end < $start || $end > $yesterday || $start->diff($end)->days > 366) {
    openMeteoHistoryReply(422, ['error' => 'Choose a valid date range of no more than 367 calendar days, ending no later than yesterday.']);
}
if ($source === 'forecast' && $start < new DateTimeImmutable('2022-01-01', new DateTimeZone('UTC'))) {
    openMeteoHistoryReply(422, ['error' => 'Historical Forecast data is available from 2022 onward. Choose Historical Weather for earlier dates.']);
}

function openMeteoFetch(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to import Open-Meteo history.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'GaugeIQ server-side history importer/1.0 (https://github.com/Double-IQ/GaugeIQ)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Open-Meteo download failed (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '') . '.');
    }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['hourly']['time']) || !is_array($data['hourly']['time'])) {
        throw new RuntimeException('Open-Meteo returned an invalid hourly-data response.');
    }
    return $data;
}

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $settings = $pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('location_latitude','location_longitude','location_name','active_location_latitude','active_location_longitude','active_location_name')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $latitude = isset($settings['location_latitude']) ? (float)$settings['location_latitude'] : (isset($settings['active_location_latitude']) ? (float)$settings['active_location_latitude'] : (float)($config['pressure']['latitude'] ?? NAN));
    $longitude = isset($settings['location_longitude']) ? (float)$settings['location_longitude'] : (isset($settings['active_location_longitude']) ? (float)$settings['active_location_longitude'] : (float)($config['pressure']['longitude'] ?? NAN));
    $locationName = (string)($settings['location_name'] ?? $settings['active_location_name'] ?? $config['pressure']['location_name'] ?? 'Configured GaugeIQ location');
    $timezone = (string)($config['app']['timezone'] ?? 'UTC');
    if (!is_finite($latitude) || $latitude < -90 || $latitude > 90 || !is_finite($longitude) || $longitude < -180 || $longitude > 180) {
        openMeteoHistoryReply(422, ['error' => 'Configure valid location coordinates in GaugeIQ before importing history.']);
    }
    try { new DateTimeZone($timezone); } catch (Throwable) { $timezone = 'UTC'; }

    $endpoint = $source === 'forecast'
        ? 'https://historical-forecast-api.open-meteo.com/v1/forecast'
        : 'https://archive-api.open-meteo.com/v1/archive';
    $params = http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'start_date' => $startText,
        'end_date' => $endText,
        'hourly' => 'temperature_2m,dew_point_2m,surface_pressure,relative_humidity_2m,wind_speed_10m,wind_direction_10m,precipitation,cloud_cover,weather_code',
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
        'timezone' => $timezone,
    ]);
    $data = openMeteoFetch($endpoint . '?' . $params);
    $hourly = $data['hourly'];
    $times = $hourly['time'];
    $sourceLabel = $source === 'forecast' ? 'Open-Meteo Historical Forecast' : 'Open-Meteo Historical Weather';
    $createdAt = gmdate('c');
    $insert = $pdo->prepare(
        'INSERT INTO gaugeiq_pressure_readings
        (temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $exists = $pdo->prepare('SELECT id FROM gaugeiq_pressure_readings WHERE observed_at = ? LIMIT 1');
    $count = 0;
    $skipped = 0;
    $pdo->beginTransaction();
    foreach ($times as $i => $time) {
        if (!is_string($time) || $time === '') { $skipped++; continue; }
        $observedAt = str_contains($time, 'T') ? $time : str_replace(' ', 'T', $time);
        if (strlen($observedAt) === 16) $observedAt .= ':00';
        $exists->execute([$observedAt]);
        if ($exists->fetchColumn() !== false) { $skipped++; continue; }
        $val = static function (string $key) use ($hourly, $i): ?float {
            $value = $hourly[$key][$i] ?? null;
            return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
        };
        $pressure = $val('surface_pressure');
        // GaugeIQ's pressure history is station/surface pressure; never substitute sea-level pressure.
        if ($pressure === null || $pressure <= 0 || $pressure > 1200) { $skipped++; continue; }
        $code = $val('weather_code');
        $insert->execute([
            $val('temperature_2m'), $val('dew_point_2m'), $pressure, $val('relative_humidity_2m'),
            $val('wind_speed_10m'), $val('wind_direction_10m'), $val('precipitation'), $val('cloud_cover'),
            $code === null ? null : (int)$code, $observedAt, $createdAt, $sourceLabel
        ]);
        $count++;
    }
    $pdo->commit();
    openMeteoHistoryReply(200, [
        'success' => true, 'location' => $locationName, 'source' => $sourceLabel,
        'start_date' => $startText, 'end_date' => $endText, 'inserted' => $count, 'skipped' => $skipped,
        'timezone' => $data['timezone'] ?? $timezone
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('GaugeIQ Open-Meteo history import failed: ' . $e->getMessage());
    openMeteoHistoryReply(500, ['error' => 'Unable to import historical weather. ' . $e->getMessage()]);
}
