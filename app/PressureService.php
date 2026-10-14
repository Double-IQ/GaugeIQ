<?php
declare(strict_types=1);

final class PressureService
{
    public function __construct(private array $config, private PDO $db)
    {
    }

    private function locationCoordinates(): array
    {
        $settings = [];
        $stmt = $this->db->prepare(
            "SELECT `key`, `value` FROM gaugeiq_settings
             WHERE `key` IN ('active_location_latitude', 'active_location_longitude',
                              'location_latitude', 'location_longitude')"
        );
        $stmt->execute();

        foreach ($stmt->fetchAll() as $setting) {
            $settings[(string)$setting['key']] = (string)$setting['value'];
        }

        // Admin's single configured location is authoritative. Older releases
        // may have saved browser-selected coordinates in active_location_*;
        // retain those only as a fallback when the configured value is absent.
        $latitude = $settings['location_latitude']
            ?? $settings['active_location_latitude']
            ?? (string)$this->config['pressure']['latitude'];
        $longitude = $settings['location_longitude']
            ?? $settings['active_location_longitude']
            ?? (string)$this->config['pressure']['longitude'];

        if (trim($latitude) === '') {
            $latitude = $settings['active_location_latitude'] ?? (string)$this->config['pressure']['latitude'];
        }
        if (trim($longitude) === '') {
            $longitude = $settings['active_location_longitude'] ?? (string)$this->config['pressure']['longitude'];
        }

        return [$latitude, $longitude];
    }

    public function fetchCurrent(): array
    {
        [$latitude, $longitude] = $this->locationCoordinates();
        $lat = rawurlencode($latitude);
        $lon = rawurlencode($longitude);

        $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}"
            . "&current=temperature_2m,apparent_temperature,dew_point_2m,surface_pressure,relative_humidity_2m,rain,cloud_cover,weather_code,wind_speed_10m,wind_direction_10m&hourly=weather_code&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,rain_sum,wind_speed_10m_max,wind_gusts_10m_max&forecast_days=4"
            . "&wind_speed_unit=kmh&timezone=auto";

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Unable to retrieve weather data: cURL is not available.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to retrieve weather data: cURL could not be initialized.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['User-Agent: GaugeIQ/0.2'],
        ]);

        $json = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($json === false) {
            $detail = $curlError !== '' ? $curlError : 'No cURL error was reported.';
            $status = $httpStatus > 0 ? " HTTP status {$httpStatus}." : '';
            throw new RuntimeException(
                "Unable to retrieve weather data: {$detail}{$status}"
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                "Unable to retrieve weather data: HTTP status {$httpStatus}."
            );
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $current = $data['current'] ?? [];

        foreach ([
            'temperature_2m' => 'Temperature',
            'apparent_temperature' => 'Feels like',
            'dew_point_2m' => 'Dew point',
            'surface_pressure' => 'Pressure',
            'relative_humidity_2m' => 'Humidity',
            'rain' => 'Rainfall',
            'cloud_cover' => 'Cloud cover',
            'weather_code' => 'Weather condition',
            'wind_speed_10m' => 'Wind speed',
            'wind_direction_10m' => 'Wind direction',
        ] as $field => $label) {
            if (!isset($current[$field]) || !is_numeric($current[$field])) {
                throw new RuntimeException("{$label} data was not returned by the weather service.");
            }
        }

        $forecast = $this->normaliseForecast($data['daily'] ?? []);
        $hourly = $data['hourly'] ?? [];
        $hourlyTimes = is_array($hourly['time'] ?? null) ? $hourly['time'] : [];
        $hourlyCodes = is_array($hourly['weather_code'] ?? null) ? $hourly['weather_code'] : [];
        $currentTime = strtotime((string)($current['time'] ?? ''));
        $nextHourWeatherCode = null;
        foreach ($hourlyTimes as $hourIndex => $hourTime) {
            $hourTimestamp = is_string($hourTime) ? strtotime($hourTime) : false;
            if ($hourTimestamp !== false && ($currentTime === false || $hourTimestamp > $currentTime)
                && isset($hourlyCodes[$hourIndex]) && is_numeric($hourlyCodes[$hourIndex])) {
                $nextHourWeatherCode = (int)$hourlyCodes[$hourIndex];
                break;
            }
        }
        if ($nextHourWeatherCode !== null && isset($forecast[0])) {
            $forecast[0]['next_hour_weather_code'] = $nextHourWeatherCode;
        }

        return [
            'temperature_c' => (float)$current['temperature_2m'],
            'feels_like_c' => (float)$current['apparent_temperature'],
            'forecast_high_c' => isset($data['daily']['temperature_2m_max'][0]) ? (float)$data['daily']['temperature_2m_max'][0] : null,
            'forecast_low_c' => isset($data['daily']['temperature_2m_min'][0]) ? (float)$data['daily']['temperature_2m_min'][0] : null,
            'dew_point_c' => (float)$current['dew_point_2m'],
            'pressure_hpa' => (float)$current['surface_pressure'],
            'humidity_percent' => (float)$current['relative_humidity_2m'],
            'rainfall_mm' => (float)$current['rain'],
            'cloud_cover_percent' => (float)$current['cloud_cover'],
            'weather_code' => (int)$current['weather_code'],
            'wind_speed_kmh' => (float)$current['wind_speed_10m'],
            'wind_direction_degrees' => (float)$current['wind_direction_10m'],
            'observed_at' => (string)($current['time'] ?? gmdate('c')),
            'forecast' => $forecast,
        ];
    }

    /**
     * Refresh the last 24 hours of model-derived hourly values used only as a
     * temporary fallback when live monitoring has too few recent readings.
     * These rows are deliberately kept under their own source label.
     */
    public function refreshRecentBackfill(int $pastHours = 24): int
    {
        $pastHours = max(6, min(24, $pastHours));
        [$latitude, $longitude] = $this->locationCoordinates();

        $timezoneName = (string)($this->config['app']['timezone'] ?? 'UTC');
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m',
            'hourly' => 'temperature_2m,apparent_temperature,dew_point_2m,surface_pressure,relative_humidity_2m,wind_speed_10m,wind_direction_10m,rain,cloud_cover,weather_code',
            'past_hours' => $pastHours,
            'forecast_days' => 1,
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'precipitation_unit' => 'mm',
            'timezone' => $timezone->getName(),
        ]);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Recent weather backfill requires PHP cURL.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Could not initialise cURL for recent weather backfill.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['User-Agent: GaugeIQ/0.2'],
        ]);

        $json = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($json === false) {
            throw new RuntimeException(
                'Recent weather backfill request failed: ' .
                ($curlError !== '' ? $curlError : 'no cURL error was reported.') .
                ($httpStatus > 0 ? ' HTTP status ' . $httpStatus . '.' : '')
            );
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('Recent weather backfill returned HTTP status ' . $httpStatus . '.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $hourly = $data['hourly'] ?? null;
        $times = is_array($hourly) ? ($hourly['time'] ?? []) : [];
        if (!is_array($hourly) || !is_array($times) || $times === []) {
            throw new RuntimeException('Open-Meteo returned no hourly values for recent weather backfill.');
        }

        $now = new DateTimeImmutable('now', $timezone);
        $cutoff = $now->modify('-' . ($pastHours + 1) . ' hours');
        $futureLimit = $now->modify('+5 minutes');
        $source = 'Open-Meteo Recent Backfill';
        $createdAt = gmdate('c');
        $number = static function (mixed $value): ?float {
            return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
        };

        $findExisting = $this->db->prepare(
            'SELECT id FROM gaugeiq_pressure_readings WHERE observed_at = ? AND source = ? LIMIT 1'
        );
        $update = $this->db->prepare(
            'UPDATE gaugeiq_pressure_readings
             SET temperature_c = ?, feels_like_c = ?, dew_point_c = ?, pressure_hpa = ?,
                 humidity_percent = ?, wind_speed_kmh = ?, wind_direction_degrees = ?,
                 rainfall_mm = ?, cloud_cover_percent = ?, weather_code = ?, forecast_json = NULL,
                 created_at = ?
             WHERE id = ?'
        );
        $insert = $this->db->prepare(
            'INSERT INTO gaugeiq_pressure_readings
             (temperature_c, feels_like_c, dew_point_c, pressure_hpa, humidity_percent,
              wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent,
              weather_code, forecast_json, observed_at, created_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
        );

        $written = 0;
        $this->db->beginTransaction();
        try {
            foreach ($times as $index => $time) {
                if (!is_string($time) || $time === '') {
                    continue;
                }

                try {
                    $observedDate = new DateTimeImmutable($time, $timezone);
                } catch (Throwable) {
                    continue;
                }
                if ($observedDate < $cutoff || $observedDate > $futureLimit) {
                    continue;
                }

                $pressure = $number($hourly['surface_pressure'][$index] ?? null);
                if ($pressure === null || $pressure <= 0 || $pressure > 1200) {
                    continue;
                }

                $temperature = $number($hourly['temperature_2m'][$index] ?? null);
                $feelsLike = $number($hourly['apparent_temperature'][$index] ?? null);
                $dewPoint = $number($hourly['dew_point_2m'][$index] ?? null);
                $humidity = $number($hourly['relative_humidity_2m'][$index] ?? null);
                $windSpeed = $number($hourly['wind_speed_10m'][$index] ?? null);
                $windDirection = $number($hourly['wind_direction_10m'][$index] ?? null);
                $rain = $number($hourly['rain'][$index] ?? null);
                $cloudCover = $number($hourly['cloud_cover'][$index] ?? null);
                $weatherCode = $number($hourly['weather_code'][$index] ?? null);
                $observedAt = $observedDate->format('Y-m-d\\TH:i:s');

                $values = [
                    $temperature, $feelsLike, $dewPoint, $pressure, $humidity,
                    $windSpeed, $windDirection, $rain, $cloudCover,
                    $weatherCode === null ? null : (int)$weatherCode,
                ];

                $findExisting->execute([$observedAt, $source]);
                $existingId = $findExisting->fetchColumn();
                if ($existingId !== false) {
                    $update->execute([...$values, $createdAt, (int)$existingId]);
                } else {
                    $insert->execute([...$values, $observedAt, $createdAt, $source]);
                }
                $written++;
            }

            if ($written === 0) {
                throw new RuntimeException('Open-Meteo returned no valid hourly pressure readings in the recent backfill window.');
            }

            // Keep the table bounded; this dataset is a temporary recent fallback,
            // not a second long-term historical archive.
            $pruneBefore = $now->modify('-30 hours')->format('Y-m-d\\TH:i:s');
            $prune = $this->db->prepare(
                'DELETE FROM gaugeiq_pressure_readings WHERE source = ? AND observed_at < ?'
            );
            $prune->execute([$source, $pruneBefore]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $written;
    }

    private function normaliseForecast(array $daily): array
    {
        $days = [];
        $times = $daily['time'] ?? [];
        $codes = $daily['weather_code'] ?? [];
        $highs = $daily['temperature_2m_max'] ?? [];
        $lows = $daily['temperature_2m_min'] ?? [];
        $rainProbabilities = $daily['precipitation_probability_max'] ?? [];
        $rainTotals = $daily['precipitation_sum'] ?? [];
        $rainOnlyTotals = $daily['rain_sum'] ?? [];
        $windSpeedMaxima = $daily['wind_speed_10m_max'] ?? [];
        $windGustMaxima = $daily['wind_gusts_10m_max'] ?? [];

        foreach ($times as $index => $time) {
            if (!is_string($time) || $time === '') {
                continue;
            }

            $days[] = [
                'date' => $time,
                'weather_code' => isset($codes[$index]) && is_numeric($codes[$index]) ? (int)$codes[$index] : null,
                'temperature_max_c' => isset($highs[$index]) && is_numeric($highs[$index]) ? (float)$highs[$index] : null,
                'temperature_min_c' => isset($lows[$index]) && is_numeric($lows[$index]) ? (float)$lows[$index] : null,
                'rain_probability_percent' => isset($rainProbabilities[$index]) && is_numeric($rainProbabilities[$index]) ? max(0, min(100, (int)round((float)$rainProbabilities[$index]))) : null,
                'rain_mm' => isset($rainTotals[$index]) && is_numeric($rainTotals[$index]) ? max(0.0, (float)$rainTotals[$index]) : 0.0,
                'rain_only_mm' => isset($rainOnlyTotals[$index]) && is_numeric($rainOnlyTotals[$index]) ? max(0.0, (float)$rainOnlyTotals[$index]) : null,
                'wind_speed_max_kmh' => isset($windSpeedMaxima[$index]) && is_numeric($windSpeedMaxima[$index]) ? max(0.0, (float)$windSpeedMaxima[$index]) : null,
                'wind_gusts_max_kmh' => isset($windGustMaxima[$index]) && is_numeric($windGustMaxima[$index]) ? max(0.0, (float)$windGustMaxima[$index]) : null,
            ];
        }

        return array_slice($days, 0, 4);
    }

    public function record(array $current): ?array
    {
        $changedAtQuery = $this->db->prepare(
            "SELECT value FROM gaugeiq_settings WHERE `key` = 'active_location_changed_at' LIMIT 1"
        );
        $changedAtQuery->execute();
        $changedAt = $changedAtQuery->fetchColumn();
        if (is_string($changedAt) && $changedAt !== '') {
            $previousQuery = $this->db->prepare(
                "SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees
                 FROM gaugeiq_pressure_readings
                 WHERE created_at >= ? AND observed_at < ?
                   AND (source IS NULL OR source = 'Open-Meteo')
                 ORDER BY observed_at DESC, id DESC LIMIT 1"
            );
            $previousQuery->execute([$changedAt, (string)$current['observed_at']]);
            $previous = $previousQuery->fetch();
        } else {
            $previousQuery = $this->db->prepare(
                "SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees
                 FROM gaugeiq_pressure_readings
                 WHERE observed_at < ? AND (source IS NULL OR source = 'Open-Meteo')
                 ORDER BY observed_at DESC, id DESC LIMIT 1"
            );
            $previousQuery->execute([(string)$current['observed_at']]);
            $previous = $previousQuery->fetch();
        }

        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_pressure_readings
             (temperature_c, feels_like_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, forecast_json, observed_at, created_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $current['temperature_c'],
            $current['feels_like_c'] ?? null,
            $current['dew_point_c'],
            $current['pressure_hpa'],
            $current['humidity_percent'],
            $current['wind_speed_kmh'],
            $current['wind_direction_degrees'],
            $current['rainfall_mm'],
            $current['cloud_cover_percent'],
            $current['weather_code'],
            json_encode($current['forecast'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $current['observed_at'],
            gmdate('c'),
            'Open-Meteo',
        ]);

        return $previous ?: null;
    }

    public function pressureThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null) {
            return false;
        }

        return abs($current - (float)$previous['pressure_hpa'])
            >= (float)$this->config['pressure']['threshold_hpa'];
    }

    public function humidityThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['humidity']['enabled'])) {
            return false;
        }

        $previousValue = (float)$previous['humidity_percent'];
        $mode = (string)($this->config['humidity']['mode'] ?? 'change');
        $threshold = (float)($this->config['humidity']['threshold_percent'] ?? 10);

        return match ($mode) {
            'above' => $current >= $threshold && $previousValue < $threshold,
            'below' => $current <= $threshold && $previousValue > $threshold,
            'change' => abs($current - $previousValue) >= $threshold,
            default => false,
        };
    }

    public function windSpeedThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['wind']['enabled'])) {
            return false;
        }

        $mode = (string)($this->config['wind']['speed_mode'] ?? 'above');
        $threshold = (float)($this->config['wind']['speed_threshold_kmh'] ?? 40);

        return match ($mode) {
            'above' => $current >= $threshold && (float)$previous['wind_speed_kmh'] < $threshold,
            'below' => $current <= $threshold && (float)$previous['wind_speed_kmh'] > $threshold,
            'change' => abs($current - (float)$previous['wind_speed_kmh']) >= $threshold,
            default => false,
        };
    }

    public function windDirectionChanged(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['wind']['enabled'])) {
            return false;
        }

        $threshold = (float)($this->config['wind']['direction_change_degrees'] ?? 45);
        return $this->circularDifference(
            (float)$previous['wind_direction_degrees'],
            $current
        ) >= $threshold;
    }

    public function windDirectionMatches(float $current): bool
    {
        if (empty($this->config['wind']['enabled'])) {
            return false;
        }

        $directions = $this->config['wind']['specific_directions'] ?? [];
        if (!is_array($directions) || $directions === []) {
            return false;
        }

        foreach ($directions as $direction) {
            if ($this->circularDifference((float)$direction, $current) <= 22.5) {
                return true;
            }
        }

        return false;
    }

    public function circularDifference(float $a, float $b): float
    {
        $difference = abs(fmod($a - $b, 360.0));
        return min($difference, 360.0 - $difference);
    }

    public static function directionLabel(float $degrees): string
    {
        $labels = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return $labels[(int)round($degrees / 45) % 8];
    }
}
