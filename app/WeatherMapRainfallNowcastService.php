<?php
declare(strict_types=1);

require_once __DIR__ . '/WeatherMapOmReader.php';

/**
 * Reads the already scheduled ECMWF precipitation fields around GaugeIQ's
 * configured location. This is a model outlook, not a live radar observation.
 */
final class WeatherMapRainfallNowcastService
{
    private const USER_AGENT = 'GaugeIQ-WeatherMap/1.0';
    private const SEARCH_RADIUS_KM = 100.0;
    private const WET_THRESHOLD_MM = 0.1;

    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for the ECMWF rainfall outlook.');
        }
    }

    public function refreshIfDue(int $intervalSeconds = 1800, bool $force = false): array
    {
        $last = $this->setting('weather_map_rainfall_nowcast_updated_at');
        $lastTimestamp = $last !== '' ? strtotime($last) : false;
        if (!$force && $lastTimestamp !== false && $lastTimestamp > time() - $intervalSeconds) {
            return ['skipped' => true, 'message' => 'ECMWF rainfall outlook is still current.'];
        }

        [$latitude, $longitude] = $this->locationCoordinates();
        $latitudeValue = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $longitudeValue = filter_var($longitude, FILTER_VALIDATE_FLOAT);
        if ($latitudeValue === false || $longitudeValue === false ||
            $latitudeValue < -90 || $latitudeValue > 90 || $longitudeValue < -180 || $longitudeValue > 180) {
            throw new RuntimeException('The configured GaugeIQ location coordinates are invalid.');
        }

        $stmt = $this->pdo->query(
            "SELECT frame_type, frame_time, file_url
             FROM gaugeiq_weather_map_frames
             ORDER BY frame_time ASC"
        );
        $frames = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($frames === []) throw new RuntimeException('No scheduled ECMWF weather-map frames are available.');

        $now = time();
        $futureFrames = [];
        foreach ($frames as $frame) {
            $timestamp = strtotime((string)($frame['frame_time'] ?? ''));
            if ($timestamp === false || $timestamp < $now || $timestamp > $now + 2 * 3600) continue;
            $futureFrames[] = $frame + ['timestamp' => $timestamp];
        }
        if ($futureFrames === []) {
            throw new RuntimeException('No ECMWF precipitation frames cover the next two hours yet.');
        }

        $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('A public HTTPS app.base_url is required for ECMWF rainfall decoding.');
        }



        $frameResults = [];
        foreach ($futureFrames as $frame) {
            $url = (string)($frame['file_url'] ?? '');
            $this->validateFrameUrl($url);
            $fileSize = $this->fetchFileSize($baseUrl, $url);
            $reader = new WeatherMapOmReader(
                fn(int $offset, int $length): string => $this->fetchRange($baseUrl, $url, $offset, $length),
                $fileSize
            );
            if (!str_contains($reader->getString('crs_wkt'), 'Gaussian Grid')) {
                throw new RuntimeException('The ECMWF precipitation frame does not use a Gaussian grid.');
            }
            $field = $reader->getArrayInfo('precipitation');
            $pointCount = (int)($field['dimensions'][1] ?? 0);
            $samples = WeatherMapOmReader::gaussianNeighborhood(
                $pointCount,
                (float)$latitudeValue,
                (float)$longitudeValue,
                self::SEARCH_RADIUS_KM
            );
            $indices = array_column($samples, 'index');
            $values = $reader->decodeFloatArrayIndices($field, $indices);
            $nearestCell = null;
            foreach ($samples as $cell) {
                if ($nearestCell === null || $cell['distance_km'] < $nearestCell['distance_km']) $nearestCell = $cell;
            }
            if ($nearestCell === null) throw new RuntimeException('The ECMWF Gaussian grid has no cells around the configured location.');

            $localAmount = max(0.0, (float)($values[$nearestCell['index']] ?? 0.0));
            $wet15 = 0;
            $wet50 = 0;
            $wet100 = 0;
            $nearestWetDistance = null;
            $maximum = 0.0;
            $wetCount = 0;
            foreach ($samples as $cell) {
                $amount = max(0.0, (float)($values[$cell['index']] ?? 0.0));
                $maximum = max($maximum, $amount);
                if ($amount < self::WET_THRESHOLD_MM) continue;
                $wetCount++;
                if ($cell['distance_km'] <= 15) $wet15++;
                if ($cell['distance_km'] <= 50) $wet50++;
                if ($cell['distance_km'] <= 100) $wet100++;
                if ($nearestWetDistance === null || $cell['distance_km'] < $nearestWetDistance) {
                    $nearestWetDistance = (float)$cell['distance_km'];
                }
            }

            $frameResults[] = [
                'time' => gmdate('c', (int)$frame['timestamp']),
                'local_precipitation_mm' => round($localAmount, 2),
                'maximum_precipitation_mm' => round($maximum, 2),
                'wet_cells_within_15km' => $wet15,
                'wet_cells_within_50km' => $wet50,
                'wet_cells_within_100km' => $wet100,
                'nearest_rain_km' => $nearestWetDistance === null ? null : round($nearestWetDistance, 1),
                'wet_cells' => $wetCount,
            ];
        }

        $status = 'clear';
        $summary = 'No ECMWF precipitation signal is forecast within 100 km over the next two hours.';
        $firstRain = null;
        $statusPriority = ['clear' => 0, 'rain_in_area' => 1, 'rain_nearby' => 2, 'rain_near_location' => 3];
        foreach ($frameResults as $result) {
            $frameStatus = 'clear';
            if ($result['local_precipitation_mm'] >= self::WET_THRESHOLD_MM ||
                $result['wet_cells_within_15km'] > 0) {
                $frameStatus = 'rain_near_location';
            } elseif ($result['wet_cells_within_50km'] > 0) {
                $frameStatus = 'rain_nearby';
            } elseif ($result['wet_cells_within_100km'] > 0) {
                $frameStatus = 'rain_in_area';
            }
            if ($statusPriority[$frameStatus] > $statusPriority[$status]) {
                $status = $frameStatus;
                $firstRain = $result;
            }
            if ($status === 'rain_near_location') break;
        }

        if ($firstRain !== null) {
            $localTime = (new DateTimeImmutable($firstRain['time']))->setTimezone(
                new DateTimeZone((string)($this->config['app']['timezone'] ?? 'UTC'))
            )->format('H:i');
            if ($status === 'rain_near_location') {
                $summary = sprintf(
                    'ECMWF forecasts precipitation at or very close to your location around %s (%s mm at the nearest grid cell).',
                    $localTime,
                    number_format($firstRain['local_precipitation_mm'], 1)
                );
            } elseif ($status === 'rain_nearby') {
                $summary = sprintf(
                    'ECMWF forecasts nearby rain around %s; the closest wet grid cell is about %s km away.',
                    $localTime,
                    number_format((float)$firstRain['nearest_rain_km'], 0)
                );
            } else {
                $summary = sprintf(
                    'ECMWF forecasts precipitation in the surrounding area around %s, but not close to your location.',
                    $localTime
                );
            }
        }

        $payload = [
            'source' => 'ECMWF IFS',
            'status' => $status,
            'summary' => $summary,
            'location' => ['latitude' => (float)$latitudeValue, 'longitude' => (float)$longitudeValue],
            'search_radius_km' => self::SEARCH_RADIUS_KM,
            'wet_threshold_mm' => self::WET_THRESHOLD_MM,
            'updated_at' => gmdate('c'),
            'frames' => $frameResults,
            'note' => 'Model forecast derived from decoded ECMWF precipitation grid values; not live radar.',
        ];
        $this->saveSetting('weather_map_rainfall_nowcast', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->saveSetting('weather_map_rainfall_nowcast_updated_at', gmdate('c'));
        $this->saveSetting('weather_map_rainfall_nowcast_error', '');

        return ['skipped' => false, 'payload' => $payload, 'message' => $summary];
    }

    private function locationCoordinates(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `key`, `value` FROM gaugeiq_settings
             WHERE `key` IN ('active_location_latitude', 'active_location_longitude',
                              'location_latitude', 'location_longitude')"
        );
        $stmt->execute();
        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $settings[(string)$row['key']] = (string)$row['value'];
        $latitude = $settings['location_latitude'] ?? $settings['active_location_latitude'] ?? (string)($this->config['pressure']['latitude'] ?? '');
        $longitude = $settings['location_longitude'] ?? $settings['active_location_longitude'] ?? (string)($this->config['pressure']['longitude'] ?? '');
        if (trim($latitude) === '') $latitude = $settings['active_location_latitude'] ?? (string)($this->config['pressure']['latitude'] ?? '');
        if (trim($longitude) === '') $longitude = $settings['active_location_longitude'] ?? (string)($this->config['pressure']['longitude'] ?? '');
        return [$latitude, $longitude];
    }

    private function setting(string $key): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? '' : (string)$value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare("UPDATE gaugeiq_settings SET value = ? WHERE `key` = ?");
        $stmt->execute([$value, $key]);
        if ($stmt->rowCount() === 0 && $this->setting($key) === '') {
            $insert = $this->pdo->prepare("INSERT INTO gaugeiq_settings (`key`, value) VALUES (?, ?)");
            $insert->execute([$key, $value]);
        }
    }

    private function proxyUrl(string $baseUrl, string $upstreamUrl, bool $metadata = false): string
    {
        $query = ['url' => $upstreamUrl];
        if ($metadata) $query['metadata'] = '1';
        return $baseUrl . '/api/weather-map-proxy.php?' .
            http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function fetchFileSize(string $baseUrl, string $url): int
    {
        $curl = curl_init($this->proxyUrl($baseUrl, $url, true));
        if ($curl === false) throw new RuntimeException('Unable to initialise ECMWF metadata request.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('ECMWF metadata request failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').'));
        }
        $metadata = json_decode($body, true);
        $size = is_array($metadata) ? (int)($metadata['size'] ?? 0) : 0;
        if ($size < 27) throw new RuntimeException('ECMWF metadata returned an invalid .om file size.');
        return $size;
    }

    private function fetchRange(string $baseUrl, string $url, int $offset, int $length): string
    {
        $curl = curl_init($this->proxyUrl($baseUrl, $url));
        if ($curl === false) throw new RuntimeException('Unable to initialise ECMWF byte-range request.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RANGE => $offset . '-' . ($offset + $length - 1),
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        ]);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status !== 206 || strlen($body) !== $length) {
            throw new RuntimeException('ECMWF byte-range request failed' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').'));
        }
        return $body;
    }

    private function validateFrameUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            ($parts['host'] ?? '') !== 'openmeteo.s3.amazonaws.com' ||
            !preg_match('#^/data_spatial/ecmwf_ifs/.+\.om$#', (string)($parts['path'] ?? '')) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('The scheduled ECMWF frame URL is invalid.');
        }
    }
}
