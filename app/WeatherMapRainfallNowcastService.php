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
    private const WET_THRESHOLD_MM_PER_HOUR = 0.1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly int $locationId = 1
    ) {
        if (!in_array($this->locationId, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ location.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for the ECMWF rainfall outlook.');
        }
    }

    /**
     * ECMWF precipitation values are accumulated over each forecast step.
     * Open-Meteo's weather-map renderer displays this precipitation family
     * as a per-hour rate, so the card must apply the same step normalisation.
     */
    public static function precipitationRate(float $accumulatedMm, float $deltaTimeHours): float
    {
        if (!is_finite($accumulatedMm) || !is_finite($deltaTimeHours) || $deltaTimeHours <= 0.0) {
            throw new InvalidArgumentException('A finite precipitation amount and positive forecast-step duration are required.');
        }
        return max(0.0, $accumulatedMm) / $deltaTimeHours;
    }

    public function refreshIfDue(int $intervalSeconds = 1800, bool $force = false, ?array $locationOverride = null, bool $persist = true): array
    {
        $last = $this->setting($this->locationSettingKey('weather_map_rainfall_nowcast_updated_at'));
        $lastTimestamp = $last !== '' ? strtotime($last) : false;
        if (!$force && $lastTimestamp !== false && $lastTimestamp > time() - $intervalSeconds) {
            return ['skipped' => true, 'message' => 'ECMWF rainfall outlook is still current.'];
        }

        [$latitude, $longitude] = $locationOverride !== null
            ? [(string)($locationOverride['latitude'] ?? ''), (string)($locationOverride['longitude'] ?? '')]
            : $this->locationCoordinates();
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
        $scheduledFrames = [];
        foreach ($frames as $frame) {
            $timestamp = strtotime((string)($frame['frame_time'] ?? ''));
            if ($timestamp === false) continue;
            $scheduledFrames[] = $frame + ['timestamp' => $timestamp];
        }
        usort($scheduledFrames, static fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        // Match the map renderer: use the gap to the previous valid time,
        // except for the first frame, which uses the next valid time.
        $futureFrames = [];
        foreach ($scheduledFrames as $index => $frame) {
            $neighbour = $index === 0
                ? ($scheduledFrames[1] ?? null)
                : $scheduledFrames[$index - 1];
            $deltaTimeHours = $neighbour === null
                ? 3.0
                : abs((int)$frame['timestamp'] - (int)$neighbour['timestamp']) / 3600.0;
            if (!is_finite($deltaTimeHours) || $deltaTimeHours <= 0.0) {
                throw new RuntimeException('The scheduled ECMWF forecast-step duration is invalid.');
            }
            if (($frame['frame_type'] ?? '') !== 'future' || (int)$frame['timestamp'] < $now) continue;
            $futureFrames[] = $frame + ['delta_time_hours' => $deltaTimeHours];
        }
        if ($futureFrames === []) {
            throw new RuntimeException('No future ECMWF precipitation frames are scheduled yet.');
        }

        $baseUrl = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('A public HTTPS app.base_url is required for ECMWF rainfall decoding.');
        }



        $frameResults = [];
        $encodingInfo = null;
        foreach ($futureFrames as $frame) {
            $url = (string)($frame['file_url'] ?? '');
            $this->validateFrameUrl($url);
            $fileSize = $this->fetchFileSize($baseUrl, $url);
            $reader = new WeatherMapOmReader(
                fn(int $offset, int $length): string => $this->fetchRange($baseUrl, $url, $offset, $length),
                $fileSize
            );
            $crs = $reader->getString('crs_wkt');
            if (str_contains($crs, 'Gaussian Grid')) {
                throw new RuntimeException('The ECMWF precipitation frame unexpectedly uses a Gaussian grid.');
            }
            $field = $reader->getArrayInfo('precipitation');
            if ($field['dimensions'] !== [721, 1440]) {
                throw new RuntimeException('The ECMWF precipitation field is not the expected 0.25-degree [721, 1440] regular grid.');
            }
            $currentEncoding = [
                'data_type' => (int)$field['data_type'],
                'compression_type' => (int)$field['compression_type'],
                'scale_factor' => (float)$field['scale_factor'],
                'add_offset' => (float)$field['add_offset'],
                'dimensions' => $field['dimensions'],
                'chunks' => $field['chunks'],
            ];
            if ($encodingInfo === null) {
                $encodingInfo = $currentEncoding;
            } elseif ($encodingInfo['data_type'] !== $currentEncoding['data_type'] ||
                $encodingInfo['compression_type'] !== $currentEncoding['compression_type'] ||
                $encodingInfo['dimensions'] !== $currentEncoding['dimensions'] ||
                $encodingInfo['chunks'] !== $currentEncoding['chunks']) {
                throw new RuntimeException('The ECMWF precipitation frame layout changed within the scheduled forecast steps.');
            }
            $samples = WeatherMapOmReader::regularGridNeighborhood(
                (float)$latitudeValue,
                (float)$longitudeValue,
                self::SEARCH_RADIUS_KM,
                (int)$field['dimensions'][0],
                (int)$field['dimensions'][1]
            );
            $indices = array_column($samples, 'index');
            $values = $reader->decodeFloatArrayGridIndices($field, $indices);
            $nearestCell = null;
            foreach ($samples as $cell) {
                if ($nearestCell === null || $cell['distance_km'] < $nearestCell['distance_km']) $nearestCell = $cell;
            }
            if ($nearestCell === null) throw new RuntimeException('The ECMWF regular grid has no cells around the configured location.');

            $stepDurationHours = (float)$frame['delta_time_hours'];
            $localValue = (float)($values[$nearestCell['index']] ?? 0.0);
            $localAmount = is_finite($localValue)
                ? self::precipitationRate($localValue, $stepDurationHours)
                : 0.0;
            $wet15 = 0;
            $wet50 = 0;
            $wet100 = 0;
            $nearestWetDistance = null;
            $nearestWetAmount = null;
            $maximum = 0.0;
            $wetCount = 0;
            $radarCells = [];
            foreach ($samples as $cell) {
                $rawAmount = (float)($values[$cell['index']] ?? 0.0);
                if (!is_finite($rawAmount)) continue;
                $amount = self::precipitationRate($rawAmount, $stepDurationHours);
                $maximum = max($maximum, $amount);
                if ($amount < self::WET_THRESHOLD_MM_PER_HOUR) continue;
                $wetCount++;
                if ($cell['distance_km'] <= 15) $wet15++;
                if ($cell['distance_km'] <= 50) $wet50++;
                if ($cell['distance_km'] <= 100) $wet100++;
                if ($nearestWetDistance === null || $cell['distance_km'] < $nearestWetDistance) {
                    $nearestWetDistance = (float)$cell['distance_km'];
                    $nearestWetAmount = $amount;
                }
                $latitude1 = deg2rad((float)$latitudeValue);
                $latitude2 = deg2rad((float)$cell['latitude']);
                $longitudeDelta = deg2rad((float)$cell['longitude'] - (float)$longitudeValue);
                $bearing = rad2deg(atan2(
                    sin($longitudeDelta) * cos($latitude2),
                    cos($latitude1) * sin($latitude2) -
                        sin($latitude1) * cos($latitude2) * cos($longitudeDelta)
                ));
                $radarCells[] = [
                    'distance_km' => round((float)$cell['distance_km'], 1),
                    'bearing_degrees' => round(fmod($bearing + 360.0, 360.0), 1),
                    'precipitation_mm_per_hour' => round($amount, 2),
                ];
            }

            $frameResults[] = [
                'time' => gmdate('c', (int)$frame['timestamp']),
                'step_duration_hours' => round($stepDurationHours, 2),
                'local_precipitation_mm_per_hour' => round($localAmount, 2),
                'maximum_precipitation_mm_per_hour' => round($maximum, 2),
                'wet_cells_within_15km' => $wet15,
                'wet_cells_within_50km' => $wet50,
                'wet_cells_within_100km' => $wet100,
                'nearest_rain_km' => $nearestWetDistance === null ? null : round($nearestWetDistance, 1),
                'nearest_rain_precipitation_mm_per_hour' => $nearestWetAmount === null ? null : round($nearestWetAmount, 2),
                'wet_cells' => $wetCount,
                'rain_cells' => $radarCells,
            ];
        }

        $wetDistanceFrames = array_values(array_filter(
            $frameResults,
            static fn(array $result): bool => is_numeric($result['nearest_rain_km'] ?? null)
        ));
        $movementTrend = 'not_assessed';
        $distanceChangeKm = null;
        if (count($wetDistanceFrames) >= 2) {
            $movementTrend = 'steady';
            $distanceChangeKm = (float)$wetDistanceFrames[count($wetDistanceFrames) - 1]['nearest_rain_km']
                - (float)$wetDistanceFrames[0]['nearest_rain_km'];
            if ($distanceChangeKm <= -10.0) {
                $movementTrend = 'closing';
            } elseif ($distanceChangeKm >= 10.0) {
                $movementTrend = 'receding';
            }
        }
        $status = 'clear';
        $summary = 'No ECMWF precipitation signal is forecast within 100 km across the available forecast steps.';
        $firstRain = null;
        $statusPriority = ['clear' => 0, 'rain_in_area' => 1, 'rain_nearby' => 2, 'rain_near_location' => 3];
        foreach ($frameResults as $result) {
            $frameStatus = 'clear';
            if ($result['local_precipitation_mm_per_hour'] >= self::WET_THRESHOLD_MM_PER_HOUR ||
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
                $this->locationTimezone()
            )->format('H:i');
            if ($status === 'rain_near_location') {
                $summary = sprintf(
                    'ECMWF forecasts precipitation at or very close to your location around %s (%s mm/h at the nearest grid cell).',
                    $localTime,
                    number_format($firstRain['local_precipitation_mm_per_hour'], 1)
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
            if ($movementTrend === 'closing') {
                $summary .= ' The forecast rain footprint moves closer across the available forecast steps.';
            } elseif ($movementTrend === 'receding') {
                $summary .= ' The forecast rain footprint moves farther away across the available forecast steps.';
            }
        }

        $forecastConsistency = 'no_signal';
        if (count($frameResults) >= 2) {
            $firstWet = (int)($frameResults[0]['wet_cells'] ?? 0);
            $secondWet = (int)($frameResults[1]['wet_cells'] ?? 0);
            if ($firstWet > 0 && $secondWet > 0) {
                $forecastConsistency = 'persistent';
            } elseif ($firstWet === 0 && $secondWet > 0) {
                $forecastConsistency = 'developing';
            } elseif ($firstWet > 0 && $secondWet === 0) {
                $forecastConsistency = 'fading';
            }
        }

        $payload = [
            'schema_version' => 2,
            'source' => 'ECMWF IFS',
            'status' => $status,
            'forecast_consistency' => $forecastConsistency,
            'summary' => $summary,
            'location' => ['latitude' => (float)$latitudeValue, 'longitude' => (float)$longitudeValue],
            'search_radius_km' => self::SEARCH_RADIUS_KM,
            'wet_threshold_mm_per_hour' => self::WET_THRESHOLD_MM_PER_HOUR,
            'rain_footprint_trend' => $movementTrend,
            'nearest_rain_distance_change_km' => $distanceChangeKm === null ? null : round($distanceChangeKm, 1),
            'grid_cells_sampled' => count($samples),
            'encoding' => $encodingInfo,
            'updated_at' => gmdate('c'),
            'frames' => $frameResults,
            'note' => 'Model forecast derived from decoded ECMWF precipitation grid values and normalised to mm per hour; not live radar.',
        ];
        if ($persist) {
            $this->saveSetting($this->locationSettingKey('weather_map_rainfall_nowcast'), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->saveSetting($this->locationSettingKey('weather_map_rainfall_nowcast_updated_at'), gmdate('c'));
            $this->saveSetting($this->locationSettingKey('weather_map_rainfall_nowcast_error'), '');
        }

        return ['skipped' => false, 'payload' => $payload, 'message' => $summary];
    }

    private function locationSettingKey(string $key): string
    {
        return $this->locationId === 2 ? $key . '_location_2' : $key;
    }

    private function locationTimezone(): DateTimeZone
    {
        $key = $this->locationId === 2 ? 'location_timezone_location_2' : 'location_timezone';
        $fallback = (string)($this->config['app']['timezone'] ?? 'UTC');
        $stmt = $this->pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $stored = $stmt->fetchColumn();
        $timezone = is_string($stored) && $stored !== '' ? $stored : $fallback;
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return new DateTimeZone($fallback);
        }
    }

    private function locationCoordinates(): array
    {
        if ($this->locationId === 2) {
            $stmt = $this->pdo->prepare(
                "SELECT `key`, `value` FROM gaugeiq_settings
                 WHERE `key` IN ('second_location_latitude', 'second_location_longitude')"
            );
            $stmt->execute();
            $settings = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[(string)$row['key']] = trim((string)$row['value']);
            }
            if (($settings['second_location_latitude'] ?? '') === '' ||
                ($settings['second_location_longitude'] ?? '') === '') {
                throw new RuntimeException('Location 2 is enabled but its coordinates are incomplete.');
            }
            return [$settings['second_location_latitude'], $settings['second_location_longitude']];
        }

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
        if ($stmt->rowCount() === 0) {
            $exists = $this->pdo->prepare("SELECT 1 FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
            $exists->execute([$key]);
            if ($exists->fetchColumn() === false) {
                $insert = $this->pdo->prepare("INSERT INTO gaugeiq_settings (`key`, value) VALUES (?, ?)");
                $insert->execute([$key, $value]);
            }
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
            !preg_match('#^/data_spatial/ecmwf_ifs025/.+\.om$#', (string)($parts['path'] ?? '')) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('The scheduled ECMWF frame URL is invalid.');
        }
    }
}
