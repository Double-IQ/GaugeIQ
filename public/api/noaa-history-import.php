<?php
declare(strict_types=1);

$config = require __DIR__ . '/../../config/local.php';
require __DIR__ . '/../../app/Database.php';
require __DIR__ . '/../../app/Schema.php';
require __DIR__ . '/../../app/AdminAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function historyJson(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
function historyFetch(string $url, int $timeout = 90): string {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required to download NOAA station data.');
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'GaugeIQ actual station history importer/1.0 (https://github.com/Double-IQ/GaugeIQ)',
        CURLOPT_HTTPHEADER => ['Accept: text/plain,text/csv,*/*'],
    ]);
    $body = curl_exec($curl); $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('NOAA station download failed (HTTP ' . $status . ')' . ($error ? ': ' . $error : '') . '.');
    }
    return $body;
}
function historyNumber(array $row, string $key): ?float {
    $raw = trim((string)($row[$key] ?? ''));
    if ($raw === '' || !is_numeric($raw)) return null;
    $v = (float)$raw;
    if (!is_finite($v) || $v <= -9000) return null;
    $q = trim((string)($row[$key . '_Quality_Code'] ?? ''));
    // Do not use observations explicitly flagged by the dataset's QC checks.
    if ($q !== '') return null;
    return $v;
}
function historyDateRange(): array {
    $startText = (string)($_POST['start_date'] ?? '');
    $endText = (string)($_POST['end_date'] ?? '');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startText, new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endText, new DateTimeZone('UTC'));
    $yesterday = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
    if (!$start || $start->format('Y-m-d') !== $startText || !$end || $end->format('Y-m-d') !== $endText) {
        historyJson(422, ['error' => 'Choose valid start and end dates.']);
    }
    if ($end > $yesterday) historyJson(422, ['error' => 'Choose an end date no later than yesterday.']);
    if ($end < $start || (int)$start->diff($end)->days > 366) {
        historyJson(422, ['error' => 'Choose a date range of no more than one year per request.']);
    }
    return [$start, $end];
}
function historyStationList(): array {
    $csv = historyFetch('https://www.ncei.noaa.gov/oa/global-historical-climatology-network/hourly/doc/ghcnh-station-list.csv', 90);
    $handle = fopen('php://temp', 'r+'); fwrite($handle, $csv); rewind($handle);
    $headers = fgetcsv($handle);
    if (!is_array($headers)) { fclose($handle); throw new RuntimeException('NOAA station directory is empty or unreadable.'); }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    $headers = array_map(static fn($v) => strtoupper(trim((string)$v)), $headers);
    $stations = [];
    while (($values = fgetcsv($handle)) !== false) {
        if (count($values) < count($headers)) continue;
        $r = array_combine($headers, array_slice(array_pad($values, count($headers), ''), 0, count($headers)));
        $id = trim((string)($r['GHCN_ID'] ?? $r['STATION'] ?? $r['ID'] ?? ''));
        $lat = $r['LATITUDE'] ?? null; $lon = $r['LONGITUDE'] ?? null;
        if ($id === '' || !is_numeric($lat) || !is_numeric($lon)) continue;
        $stations[] = ['id' => $id, 'name' => trim((string)($r['NAME'] ?? $r['STATION_NAME'] ?? $id)),
            'latitude' => (float)$lat, 'longitude' => (float)$lon,
            'elevation_m' => is_numeric($r['ELEVATION'] ?? null) ? (float)$r['ELEVATION'] : null];
    }
    fclose($handle);
    return $stations;
}
function historyDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 6371 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}
function historyStationFile(string $stationId, int $year): array {
    if (!preg_match('/^[A-Z0-9]{11}$/', $stationId) || $year < 1750 || $year > (int)gmdate('Y')) {
        throw new InvalidArgumentException('Invalid NOAA station or year.');
    }
    $url = 'https://www.ncei.noaa.gov/oa/global-historical-climatology-network/hourly/access/by-year/'
        . $year . '/psv/GHCNh_' . rawurlencode($stationId) . '_' . $year . '.psv';
    $body = historyFetch($url, 120);
    $handle = fopen('php://temp', 'r+'); fwrite($handle, $body); rewind($handle);
    $header = fgetcsv($handle, 0, '|');
    if (!is_array($header)) { fclose($handle); throw new RuntimeException('NOAA station file has no header.'); }
    $header = array_map(static fn($v) => trim((string)$v), $header);
    $rows = [];
    while (($values = fgetcsv($handle, 0, '|')) !== false) {
        if (count($values) < count($header)) continue;
        $r = array_combine($header, array_slice(array_pad($values, count($header), ''), 0, count($header)));
        $rows[] = $r;
    }
    fclose($handle);
    return $rows;
}
function historyObservation(array $r): ?array {
    $time = trim((string)($r['DATE'] ?? $r['DATE_TIME'] ?? ''));
    if ($time === '') {
        $year = trim((string)($r['Year'] ?? $r['YEAR'] ?? ''));
        $month = trim((string)($r['Month'] ?? $r['MONTH'] ?? ''));
        $day = trim((string)($r['Day'] ?? $r['DAY'] ?? ''));
        $hour = trim((string)($r['Hour'] ?? $r['HOUR'] ?? '0'));
        $minute = trim((string)($r['Minute'] ?? $r['MINUTE'] ?? '0'));
        if (!ctype_digit($year) || !ctype_digit($month) || !ctype_digit($day)) return null;
        $time = sprintf('%04d-%02d-%02dT%02d:%02d:00Z', (int)$year, (int)$month, (int)$day, (int)$hour, (int)$minute);
    } else {
        $time = str_replace(' ', 'T', $time);
        if (!preg_match('/Z$|[+-]\d\d:?\d\d$/', $time)) $time .= 'Z';
    }
    try { $dt = new DateTimeImmutable($time); } catch (Throwable) { return null; }
    $temperature = historyNumber($r, 'temperature');
    $dew = historyNumber($r, 'dew_point_temperature');
    $stationPressure = historyNumber($r, 'station_level_pressure');
    $humidity = historyNumber($r, 'relative_humidity');
    $wind = historyNumber($r, 'wind_speed');
    $direction = historyNumber($r, 'wind_direction');
    $rain = historyNumber($r, 'precipitation');
    // Station pressure is required by GaugeIQ's current schema. Never substitute
    // sea-level pressure: that is a different measurement basis.
    if ($stationPressure === null || $stationPressure <= 0 || $stationPressure > 1200) return null;
    // GHCNh temperature values are Celsius; pressure is hPa; wind is m/s; precipitation is mm.
    return [
        'observed_at' => $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'temperature_c' => $temperature,
        'dew_point_c' => $dew,
        'pressure_hpa' => $stationPressure,
        'humidity_percent' => $humidity !== null && $humidity >= 0 && $humidity <= 100 ? $humidity : null,
        'wind_speed_kmh' => $wind !== null && $wind >= 0 ? $wind * 3.6 : null,
        'wind_direction_degrees' => $direction !== null && $direction >= 0 && $direction <= 360 ? $direction : null,
        'rainfall_mm' => $rain !== null && $rain >= 0 ? $rain : null,
        'weather_code' => null,
    ];
}

try {
    if (function_exists('set_time_limit')) @set_time_limit(240);
    AdminAuth::requireLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') historyJson(405, ['error' => 'Use POST.']);
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) historyJson(403, ['error' => 'Your session expired. Refresh Admin and try again.']);
    $action = (string)($_POST['action'] ?? 'stations');
    $db = new Database($config); $pdo = $db->pdo(); migrateDatabase($pdo);
    $setting = static function (string $key, string $fallback = '') use ($pdo): string {
        $q = $pdo->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $q->execute([$key]); $v = $q->fetchColumn(); return $v === false ? $fallback : (string)$v;
    };
    $latRaw = $setting('location_latitude', (string)($config['pressure']['latitude'] ?? ''));
    $lonRaw = $setting('location_longitude', (string)($config['pressure']['longitude'] ?? ''));
    if (!is_numeric($latRaw) || !is_numeric($lonRaw)) historyJson(422, ['error' => 'Set a valid configured location in Admin first.']);
    $lat = (float)$latRaw; $lon = (float)$lonRaw;
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180) historyJson(422, ['error' => 'Set a valid configured location in Admin first.']);

    if ($action === 'stations') {
        $stations = historyStationList();
        foreach ($stations as &$station) $station['distance_km'] = round(historyDistance($lat, $lon, $station['latitude'], $station['longitude']), 1);
        unset($station);
        usort($stations, static fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        $stations = array_values(array_filter(array_slice($stations, 0, 20), static fn($s) => $s['distance_km'] <= 500));
        if (!$stations) historyJson(422, ['error' => 'No NOAA GHCNh stations were found within 500 km of the configured location.']);
        historyJson(200, ['ok' => true, 'stations' => $stations, 'location' => ['latitude' => $lat, 'longitude' => $lon],
            'note' => 'These are actual fixed-station observation sources. Preview the requested dates to check reporting coverage before importing.']);
    }

    [$start, $end] = historyDateRange();
    $stationId = trim((string)($_POST['station_id'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{11}$/', $stationId)) historyJson(422, ['error' => 'Select a valid NOAA station from the nearby station list.']);
    $stations = historyStationList();
    $station = null;
    foreach ($stations as $candidate) if ($candidate['id'] === $stationId) { $station = $candidate; break; }
    if ($station === null) historyJson(422, ['error' => 'The selected station is not in NOAA’s current station directory.']);
    $station['distance_km'] = round(historyDistance($lat, $lon, $station['latitude'], $station['longitude']), 1);
    $yearRows = [];
    for ($year = (int)$start->format('Y'); $year <= (int)$end->format('Y'); $year++) {
        try { $yearRows[$year] = historyStationFile($stationId, $year); }
        catch (Throwable $e) {
            // A station may not have a file for every requested year. A 404
            // means no file for that station/year, not a reason to discard
            // other years in the selected range.
            if (str_contains($e->getMessage(), 'HTTP 404')) $yearRows[$year] = [];
            else throw $e;
        }
    }
    $observations = []; $variableCounts = ['temperature' => 0, 'dew_point_temperature' => 0, 'station_level_pressure' => 0, 'relative_humidity' => 0, 'wind_speed' => 0, 'wind_direction' => 0, 'precipitation' => 0];
    foreach ($yearRows as $rows) foreach ($rows as $raw) {
        $timeText = trim((string)($raw['DATE'] ?? $raw['DATE_TIME'] ?? ''));
        if ($timeText === '') {
            $timeText = sprintf('%04d-%02d-%02d', (int)($raw['Year'] ?? 0), (int)($raw['Month'] ?? 0), (int)($raw['Day'] ?? 0));
        } else $timeText = substr(str_replace(' ', 'T', $timeText), 0, 10);
        if ($timeText < $start->format('Y-m-d') || $timeText > $end->format('Y-m-d')) continue;
        foreach (array_keys($variableCounts) as $variable) if (historyNumber($raw, $variable) !== null) $variableCounts[$variable]++;
        $record = historyObservation($raw);
        if ($record !== null) $observations[] = $record;
    }
    if ($action === 'preview') {
        historyJson(200, ['ok' => true, 'station' => $station, 'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'),
            'records_with_valid_station_pressure' => count($observations), 'variable_observations' => $variableCounts,
            'note' => 'Counts reflect values with no non-empty quality flag. GaugeIQ requires station-level pressure for its current schema; sea-level pressure is not substituted. Missing fields remain missing.']);
    }
    if ($action !== 'import') historyJson(422, ['error' => 'Unknown historical import action.']);
    if (!$observations) historyJson(422, ['error' => 'No valid station-level pressure observations were found for this station and date range. Choose another station or range.']);

    $insert = $pdo->prepare('INSERT INTO gaugeiq_pressure_readings
        (temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $find = $pdo->prepare('SELECT id, source, temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code FROM gaugeiq_pressure_readings WHERE observed_at = ? ORDER BY id DESC LIMIT 1');
    $update = $pdo->prepare('UPDATE gaugeiq_pressure_readings SET
        temperature_c = COALESCE(temperature_c, ?), dew_point_c = COALESCE(dew_point_c, ?),
        humidity_percent = COALESCE(humidity_percent, ?), wind_speed_kmh = COALESCE(wind_speed_kmh, ?),
        wind_direction_degrees = COALESCE(wind_direction_degrees, ?), rainfall_mm = COALESCE(rainfall_mm, ?),
        cloud_cover_percent = COALESCE(cloud_cover_percent, ?)
        WHERE id = ?');
    $inserted = 0; $updated = 0; $skipped = 0;
    $pdo->beginTransaction();
    try {
        foreach ($observations as $r) {
            $find->execute([$r['observed_at']]); $existing = $find->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // Never replace an existing live pressure value/source with a historical one.
                $update->execute([$r['temperature_c'], $r['dew_point_c'], $r['humidity_percent'], $r['wind_speed_kmh'],
                    $r['wind_direction_degrees'], $r['rainfall_mm'], $r['cloud_cover_percent'], $existing['id']]);
                $updated++;
                continue;
            }
            $insert->execute([$r['temperature_c'], $r['dew_point_c'], $r['pressure_hpa'], $r['humidity_percent'],
                $r['wind_speed_kmh'], $r['wind_direction_degrees'], $r['rainfall_mm'], $r['cloud_cover_percent'],
                $r['weather_code'], $r['observed_at'], $r['observed_at'], 'NOAA GHCNh ' . $stationId]);
            $inserted++;
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    historyJson(200, ['ok' => true, 'station' => $station, 'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'),
        'inserted' => $inserted, 'updated' => $updated, 'readings' => count($observations),
        'source' => 'NOAA GHCNh actual station observations',
        'note' => 'Imported actual fixed-station observations only. Rows with flagged or missing station pressure were excluded. Existing readings and their pressure/source are preserved; missing historical fields may be filled without overwriting existing values.']);
} catch (Throwable $e) {
    error_log('GaugeIQ NOAA hourly station history import failed: ' . $e->getMessage());
    historyJson(500, ['error' => 'Unable to process NOAA hourly station history. ' . $e->getMessage()]);
}
