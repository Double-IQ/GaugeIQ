<?php
declare(strict_types=1);

$config = require __DIR__ . '/../../config/local.php';
require __DIR__ . '/../../app/Database.php';
require __DIR__ . '/../../app/Schema.php';
require __DIR__ . '/../../app/AdminAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function noaaJson(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function noaaFetch(string $url, int $timeout = 45): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required to download NOAA data.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'GaugeIQ historical weather importer/1.0 (https://github.com/Double-IQ/GaugeIQ)',
        CURLOPT_HTTPHEADER => ['Accept: text/csv,text/plain,*/*'],
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('NOAA download failed (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '') . '.');
    }
    return $body;
}

function noaaNumber(array $row, string $field, float $missingLimit = 9998.0): ?float {
    $value = trim((string)($row[$field] ?? ''));
    if ($value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return $number >= $missingLimit ? null : $number;
}

try {
    if (function_exists('set_time_limit')) { @set_time_limit(240); }
    AdminAuth::requireLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') noaaJson(405, ['error' => 'Use POST to import historical data.']);
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) noaaJson(403, ['error' => 'Your session expired. Refresh Admin and try again.']);

    $startDate = (string)($_POST['start_date'] ?? '');
    $endDate = (string)($_POST['end_date'] ?? '');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, new DateTimeZone('UTC'));
    $yesterday = new DateTimeImmutable('yesterday', new DateTimeZone('UTC'));
    if (!$start || $start->format('Y-m-d') !== $startDate || !$end || $end->format('Y-m-d') !== $endDate) {
        noaaJson(422, ['error' => 'Choose valid start and end dates.']);
    }
    if ($startDate < '1973-01-01') noaaJson(422, ['error' => 'NOAA Global Summary of the Day files are available from 1973 onward.']);
    if ($end > $yesterday) noaaJson(422, ['error' => 'Choose an end date no later than yesterday.']);
    if ($end < $start || (int)$start->diff($end)->days > 1826) noaaJson(422, ['error' => 'Choose a date range of up to five years per import batch.']);

    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $setting = static function (string $key, string $fallback = '') use ($pdo): string {
        $stmt = $pdo->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $fallback : (string)$value;
    };
    $latitude = (float)$setting('location_latitude', (string)($config['pressure']['latitude'] ?? ''));
    $longitude = (float)$setting('location_longitude', (string)($config['pressure']['longitude'] ?? ''));
    if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
        noaaJson(422, ['error' => 'Set a valid single location in Admin before importing NOAA history.']);
    }

    $stationCsv = noaaFetch('https://www.ncei.noaa.gov/pub/data/noaa/isd-history.csv', 60);
    $lines = preg_split('/\r\n|\n|\r/', trim($stationCsv));
    if (!$lines || count($lines) < 2) throw new RuntimeException('NOAA station directory was empty or unreadable.');
    $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', (string)array_shift($lines));
    $headers = array_map(static fn($v) => strtoupper(trim((string)$v)), str_getcsv($headerLine));
    $stations = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $values = str_getcsv($line);
        if (count($values) < count($headers)) continue;
        $row = array_combine($headers, array_slice(array_pad($values, count($headers), ''), 0, count($headers)));
        $lat = isset($row['LAT']) && is_numeric($row['LAT']) ? (float)$row['LAT'] : null;
        $lon = isset($row['LON']) && is_numeric($row['LON']) ? (float)$row['LON'] : null;
        $usaf = trim((string)($row['USAF'] ?? ''));
        $wban = trim((string)($row['WBAN'] ?? ''));
        if ($lat === null || $lon === null || $usaf === '' || $wban === '' || $usaf === '999999' || $wban === '99999') continue;
        $begin = preg_replace('/\D/', '', (string)($row['BEGIN'] ?? ''));
        $finish = preg_replace('/\D/', '', (string)($row['END'] ?? ''));
        if (strlen($begin) < 4 || strlen($finish) < 4 || (int)substr($begin, 0, 4) > (int)$end->format('Y') || (int)substr($finish, 0, 4) < (int)$start->format('Y')) continue;
        $phi1 = deg2rad($latitude); $phi2 = deg2rad($lat);
        $dPhi = deg2rad($lat - $latitude); $dLambda = deg2rad($lon - $longitude);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $distance = 6371 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
        if ($distance > 300) continue;
        $stations[] = [
            'usaf' => $usaf, 'wban' => $wban,
            'name' => trim((string)($row['STATION NAME'] ?? 'NOAA station')),
            'country' => trim((string)($row['CTRY'] ?? '')),
            'distance_km' => $distance,
        ];
    }
    usort($stations, static fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    if (!$stations) noaaJson(422, ['error' => 'NO NOAA Global Summary of the Day station was found within 300 km of your configured location for those dates. Try a shorter or different date range.']);

    $years = range((int)$start->format('Y'), (int)$end->format('Y'));
    $selectedStation = null;
    $parsedRows = [];
    $lastDownloadError = null;
    // Prefer the nearest station that actually has data in the requested period.
    foreach (array_slice($stations, 0, 12) as $station) {
        $candidateRows = [];
        foreach ($years as $year) {
            $url = 'https://www.ncei.noaa.gov/data/global-summary-of-the-day/access/' . $year . '/'
                . $station['usaf'] . '-' . $station['wban'] . '.csv';
            try {
                $csv = noaaFetch($url, 45);
            } catch (Throwable $e) {
                $lastDownloadError = $e->getMessage();
                continue;
            }
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $csv);
            rewind($handle);
            $columns = fgetcsv($handle);
            if (!is_array($columns)) { fclose($handle); continue; }
            $columns = array_map(static fn($v) => strtoupper(trim((string)$v)), $columns);
            while (($values = fgetcsv($handle)) !== false) {
                if (count($values) < count($columns)) continue;
                $row = array_combine($columns, array_slice(array_pad($values, count($columns), ''), 0, count($columns)));
                $date = trim((string)($row['DATE'] ?? ''));
                if ($date < $startDate || $date > $endDate) continue;
                $pressure = noaaNumber($row, 'SLP');
                // GaugeIQ's existing primary weather table requires pressure.
                // Skip rows with NOAA's missing-value sentinel rather than fabricate a reading.
                if ($pressure === null || $pressure <= 0 || $pressure > 1200) continue;
                $temperatureF = noaaNumber($row, 'TEMP');
                $dewpointF = noaaNumber($row, 'DEWP');
                $windKnots = noaaNumber($row, 'WDSP', 98.0);
                $rainInches = noaaNumber($row, 'PRCP', 99.0);
                $candidateRows[] = [
                    'observed_at' => $date . 'T12:00:00Z',
                    'temperature_c' => $temperatureF !== null ? ($temperatureF - 32) * 5 / 9 : null,
                    'dew_point_c' => $dewpointF !== null ? ($dewpointF - 32) * 5 / 9 : null,
                    'pressure_hpa' => $pressure,
                    'humidity_percent' => null,
                    'wind_speed_kmh' => $windKnots !== null ? $windKnots * 1.852 : null,
                    'wind_direction_degrees' => null,
                    'rainfall_mm' => $rainInches !== null ? $rainInches * 25.4 : null,
                    'cloud_cover_percent' => null,
                    'weather_code' => null,
                ];
            }
            fclose($handle);
        }
        if ($candidateRows) {
            $selectedStation = $station;
            $parsedRows = $candidateRows;
            break;
        }
    }
    if (!$selectedStation || !$parsedRows) {
        noaaJson(422, ['error' => 'NOAA did not return usable daily pressure observations for this location and date range.' . ($lastDownloadError ? ' Latest download detail: ' . $lastDownloadError : '')]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO gaugeiq_pressure_readings
         (temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees,
          rainfall_mm, cloud_cover_percent, weather_code, observed_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE gaugeiq_pressure_readings SET
           temperature_c = COALESCE(?, temperature_c),
           dew_point_c = COALESCE(?, dew_point_c),
           pressure_hpa = ?,
           humidity_percent = COALESCE(?, humidity_percent),
           wind_speed_kmh = COALESCE(?, wind_speed_kmh),
           wind_direction_degrees = COALESCE(?, wind_direction_degrees),
           rainfall_mm = COALESCE(?, rainfall_mm),
           cloud_cover_percent = COALESCE(?, cloud_cover_percent),
           weather_code = COALESCE(?, weather_code),
           source = 'NOAA GSOD'
         WHERE observed_at = ?'
    );
    $find = $pdo->prepare('SELECT id FROM gaugeiq_pressure_readings WHERE observed_at = ? ORDER BY id DESC LIMIT 1');
    $inserted = 0; $updated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($parsedRows as $row) {
            $find->execute([$row['observed_at']]);
            $existing = $find->fetchColumn();
            $values = [
                $row['temperature_c'], $row['dew_point_c'], $row['pressure_hpa'], $row['humidity_percent'],
                $row['wind_speed_kmh'], $row['wind_direction_degrees'], $row['rainfall_mm'],
                $row['cloud_cover_percent'], $row['weather_code'],
            ];
            if ($existing !== false) {
                $update->execute([...$values, $row['observed_at']]);
                $updated++;
            } else {
                $insert->execute([...$values, $row['observed_at'], $row['observed_at'], 'NOAA GSOD']);
                $inserted++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    noaaJson(200, [
        'ok' => true,
        'station' => $selectedStation['name'],
        'country' => $selectedStation['country'],
        'distance_km' => round($selectedStation['distance_km'], 1),
        'start_date' => $startDate,
        'end_date' => $endDate,
        'inserted' => $inserted,
        'updated' => $updated,
        'readings' => count($parsedRows),
        'source' => 'NOAA Global Summary of the Day',
        'note' => 'NOAA GSOD is daily data. Temperature and dew point are converted from Fahrenheit, wind speed from knots to km/h, and rainfall from inches to mm. Fields NOAA does not provide remain unavailable; live Open-Meteo monitoring is unchanged.',
    ]);
} catch (Throwable $e) {
    error_log('GaugeIQ NOAA history import failed: ' . $e->getMessage());
    noaaJson(500, ['error' => 'Unable to import NOAA history. ' . $e->getMessage()]);
}
