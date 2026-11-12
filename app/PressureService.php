<?php
declare(strict_types=1);

final class PressureService
{
    public function __construct(
        private array $config,
        private PDO $db,
        private int $locationId = 1
    ) {
        if (!in_array($this->locationId, [1, 2], true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ location.');
        }
    }

    private function locationCoordinates(): array
    {
        if ($this->locationId === 2) {
            $stmt = $this->db->prepare(
                "SELECT `key`, `value` FROM gaugeiq_settings
                 WHERE `key` IN ('second_location_latitude', 'second_location_longitude')"
            );
            $stmt->execute();
            $settings = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
                $settings[(string)$setting['key']] = trim((string)$setting['value']);
            }
            $latitude = $settings['second_location_latitude'] ?? '';
            $longitude = $settings['second_location_longitude'] ?? '';
            if ($latitude === '' || $longitude === '') {
                throw new RuntimeException('Location 2 is enabled but its coordinates are incomplete.');
            }
            return [$latitude, $longitude];
        }

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
            . "&current=temperature_2m,apparent_temperature,dew_point_2m,surface_pressure,relative_humidity_2m,rain,cloud_cover,weather_code,wind_speed_10m,wind_direction_10m&hourly=temperature_2m,apparent_temperature,dew_point_2m,relative_humidity_2m,precipitation_probability,precipitation,rain,showers,snowfall,cloud_cover,wind_speed_10m,wind_direction_10m,wind_gusts_10m,uv_index,visibility,surface_pressure,weather_code,is_day&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,rain_sum,wind_speed_10m_max,wind_gusts_10m_max,sunrise,sunset,uv_index_max,precipitation_hours&forecast_days=7"
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
        $hourlyDayFlags = is_array($hourly['is_day'] ?? null) ? $hourly['is_day'] : [];
        $hourlyFields = [
            'temperature_2m' => 'temperature_c',
            'apparent_temperature' => 'feels_like_c',
            'dew_point_2m' => 'dew_point_c',
            'relative_humidity_2m' => 'humidity_percent',
            'precipitation_probability' => 'precipitation_probability_percent',
            'precipitation' => 'precipitation_mm',
            'rain' => 'rain_mm',
            'showers' => 'showers_mm',
            'snowfall' => 'snowfall_cm',
            'cloud_cover' => 'cloud_cover_percent',
            'wind_speed_10m' => 'wind_speed_kmh',
            'wind_direction_10m' => 'wind_direction_degrees',
            'wind_gusts_10m' => 'wind_gusts_kmh',
            'uv_index' => 'uv_index',
            'visibility' => 'visibility_m',
            'surface_pressure' => 'pressure_hpa',
        ];
        $hoursByDate = [];
        foreach ($hourlyTimes as $hourIndex => $hourTime) {
            if (!is_string($hourTime) || strlen($hourTime) < 13) {
                continue;
            }
            $hour = ['time' => substr($hourTime, 11, 5)];
            foreach ($hourlyFields as $sourceField => $targetField) {
                $values = $hourly[$sourceField] ?? [];
                $hour[$targetField] = isset($values[$hourIndex]) && is_numeric($values[$hourIndex])
                    ? (float)$values[$hourIndex]
                    : null;
            }
            $hour['weather_code'] = isset($hourlyCodes[$hourIndex]) && is_numeric($hourlyCodes[$hourIndex]) ? (int)$hourlyCodes[$hourIndex] : null;
            $hour['is_day'] = isset($hourlyDayFlags[$hourIndex]) && is_numeric($hourlyDayFlags[$hourIndex]) ? (int)$hourlyDayFlags[$hourIndex] === 1 : null;
            $hoursByDate[substr($hourTime, 0, 10)][] = $hour;
        }
        foreach ($forecast as &$forecastDay) {
            $forecastDay['hours'] = $hoursByDate[$forecastDay['date']] ?? [];
        }
        unset($forecastDay);
        $currentTime = strtotime((string)($current['time'] ?? ''));
        $nextHourWeatherCode = null;
        $nextHourIsDay = null;
        foreach ($hourlyTimes as $hourIndex => $hourTime) {
            $hourTimestamp = is_string($hourTime) ? strtotime($hourTime) : false;
            if ($hourTimestamp !== false && ($currentTime === false || $hourTimestamp > $currentTime)
                && isset($hourlyCodes[$hourIndex]) && is_numeric($hourlyCodes[$hourIndex])) {
                $nextHourWeatherCode = (int)$hourlyCodes[$hourIndex];
                if (isset($hourlyDayFlags[$hourIndex]) && is_numeric($hourlyDayFlags[$hourIndex])) {
                    $nextHourIsDay = (int)$hourlyDayFlags[$hourIndex] === 1;
                }
                break;
            }
        }
        if ($nextHourWeatherCode !== null && isset($forecast[0])) {
            $forecast[0]['next_hour_weather_code'] = $nextHourWeatherCode;
            if ($nextHourIsDay !== null) {
                $forecast[0]['next_hour_is_day'] = $nextHourIsDay;
            }
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
            'timezone' => (string)($data['timezone'] ?? 'UTC'),
            'observed_at' => (string)($current['time'] ?? gmdate('c')),
            'forecast' => $forecast,
        ];
    }

    /**
     * Recover a recent monitoring gap into the normal readings history.
     *
     * Recovery is attempted only when the newest stored observation is older
     * than the configured gap threshold. Open-Meteo's response is streamed to
     * a temporary file, validated, and then only missing hourly observations
     * are inserted into the existing readings table. The temporary file is
     * removed on both success and failure.
     *
     * Returns null when no recovery is needed, otherwise the number of rows
     * inserted (which may be zero if all returned hours already exist).
     */
    public function recoverRecentGap(int $gapHours = 3): ?int
    {
        $gapHours = max(1, min(24, $gapHours));

        $timezoneKey = $this->locationId === 2 ? 'location_timezone_location_2' : 'location_timezone';
        $timezoneQuery = $this->db->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $timezoneQuery->execute([$timezoneKey]);
        $timezoneName = (string)($timezoneQuery->fetchColumn() ?: ($this->config['app']['timezone'] ?? 'UTC'));
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }

        $now = new DateTimeImmutable('now', $timezone);
        $latestQuery = $this->db->prepare(
            'SELECT observed_at FROM gaugeiq_pressure_readings
             WHERE location_id = ? ORDER BY observed_at DESC, id DESC LIMIT 1'
        );
        $latestQuery->execute([$this->locationId]);
        $latestObservedAt = $latestQuery->fetchColumn();
        $latestTimestamp = null;
        if (is_string($latestObservedAt) && $latestObservedAt !== '') {
            try {
                $latestTimestamp = (new DateTimeImmutable($latestObservedAt, $timezone))->getTimestamp();
                if ($latestTimestamp >= $now->getTimestamp() - ($gapHours * 3600)) {
                    return null;
                }
            } catch (Throwable) {
                // An unreadable latest timestamp is treated as a gap so that
                // the recovery path can repair the recent history safely.
            }
        }

        // The monitor records this only after a successful live fetch and
        // database insert. Use it as the recovery anchor so a missed 3+ hour
        // cron interval is not limited to an arbitrary four-hour lookback.
        $successKey = $this->locationId === 2
            ? 'monitor_last_success_at_location_2'
            : 'monitor_last_success_at';
        $successQuery = $this->db->prepare('SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1');
        $successQuery->execute([$successKey]);
        $lastSuccessValue = $successQuery->fetchColumn();
        $lastSuccessTimestamp = null;
        if (is_string($lastSuccessValue) && $lastSuccessValue !== '') {
            try {
                $lastSuccessTimestamp = (new DateTimeImmutable($lastSuccessValue))->getTimestamp();
            } catch (Throwable) {
                // Fall back to the newest stored observation if status is invalid.
            }
        }

        $anchorTimestamp = max(
            $latestTimestamp ?? 0,
            $lastSuccessTimestamp ?? 0
        );
        if ($anchorTimestamp <= 0) {
            // First-run fallback: recover from the newest stored observation.
            $anchorTimestamp = $latestTimestamp ?? 0;
        }
        if ($anchorTimestamp <= 0) {
            return null;
        }

        // Start at the next full hour after the last known successful point.
        // The current, incomplete hour is left to the normal live monitor.
        $currentHourTimestamp = $now->setTime((int)$now->format('H'), 0, 0)->getTimestamp();
        $firstMissingHour = (int)(floor($anchorTimestamp / 3600) * 3600) + 3600;
        if ($firstMissingHour >= $currentHourTimestamp) {
            return null;
        }

        $cutoff = (new DateTimeImmutable('@' . $firstMissingHour))->setTimezone($timezone);
        // Open-Meteo's past_hours window is sized to the actual outage, with
        // a small overlap for API timestamp alignment; never assume four hours.
        $pastHours = max(4, (int)ceil(($now->getTimestamp() - $firstMissingHour) / 3600) + 1);
        $pastHours = min(92, $pastHours);

        [$latitude, $longitude] = $this->locationCoordinates();
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
            'timezone' => 'auto',
        ]);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Recent pressure recovery requires PHP cURL.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'gaugeiq-pressure-recovery-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a temporary file for recent pressure recovery.');
        }

        try {
            $output = fopen($temporaryPath, 'wb');
            if ($output === false) {
                throw new RuntimeException('Could not open the temporary pressure-recovery file.');
            }

            $curl = curl_init($url);
            if ($curl === false) {
                fclose($output);
                throw new RuntimeException('Could not initialise cURL for recent pressure recovery.');
            }

            curl_setopt_array($curl, [
                CURLOPT_FILE => $output,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['User-Agent: GaugeIQ/0.2'],
            ]);

            $downloaded = curl_exec($curl);
            $curlError = curl_error($curl);
            $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            fclose($output);

            if ($downloaded === false) {
                throw new RuntimeException(
                    'Recent pressure recovery download failed: ' .
                    ($curlError !== '' ? $curlError : 'no cURL error was reported.') .
                    ($httpStatus > 0 ? ' HTTP status ' . $httpStatus . '.' : '')
                );
            }
            if ($httpStatus < 200 || $httpStatus >= 300) {
                throw new RuntimeException('Recent pressure recovery returned HTTP status ' . $httpStatus . '.');
            }

            $json = file_get_contents($temporaryPath);
            if ($json === false || $json === '') {
                throw new RuntimeException('The downloaded recent pressure-recovery file was empty or unreadable.');
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $responseTimezoneName = (string)($data['timezone'] ?? $timezoneName);
            try {
                $timezone = new DateTimeZone($responseTimezoneName);
            } catch (Throwable) {
                $timezone = new DateTimeZone('UTC');
            }

            $hourly = $data['hourly'] ?? null;
            $times = is_array($hourly) ? ($hourly['time'] ?? []) : [];
            if (!is_array($hourly) || !is_array($times) || $times === []) {
                throw new RuntimeException('Open-Meteo returned no hourly values for recent pressure recovery.');
            }

            $now = new DateTimeImmutable('now', $timezone);
            // Only import completed hours after the last successful monitor run.
            // The live current observation is
            // recorded by the normal monitor immediately after this method.
            $currentHour = $now->setTime((int)$now->format('H'), 0, 0);
            $createdAt = gmdate('c');
            $number = static function (mixed $value): ?float {
                return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
            };

            $findExisting = $this->db->prepare(
                'SELECT 1 FROM gaugeiq_pressure_readings
                 WHERE location_id = ? AND (observed_at = ? OR observed_at = ?) LIMIT 1'
            );
            $insert = $this->db->prepare(
                'INSERT INTO gaugeiq_pressure_readings
                 (location_id, temperature_c, feels_like_c, dew_point_c, pressure_hpa, humidity_percent,
                  wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent,
                  weather_code, forecast_json, observed_at, created_at, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
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
                    if ($observedDate < $cutoff || $observedDate >= $currentHour) {
                        continue;
                    }

                    $pressure = $number($hourly['surface_pressure'][$index] ?? null);
                    if ($pressure === null || $pressure <= 0 || $pressure > 1200) {
                        continue;
                    }

                    // Match the API's minute-precision timestamps, while
                    // also recognising older rows stored with seconds.
                    $observedAt = $observedDate->format('Y-m-d\\TH:i');
                    $observedAtWithSeconds = $observedDate->format('Y-m-d\\TH:i:s');
                    $findExisting->execute([$this->locationId, $observedAt, $observedAtWithSeconds]);
                    if ($findExisting->fetchColumn() !== false) {
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

                    $insert->execute([
                        $this->locationId,
                        $temperature,
                        $feelsLike,
                        $dewPoint,
                        $pressure,
                        $humidity,
                        $windSpeed,
                        $windDirection,
                        $rain,
                        $cloudCover,
                        $weatherCode === null ? null : (int)$weatherCode,
                        $observedAt,
                        $createdAt,
                        'Open-Meteo',
                    ]);
                    $written++;
                }

                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            return $written;
        } finally {
            // The recovered observations are now in the database; the
            // downloaded response is only a temporary transfer artifact.
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
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
        $sunrises = $daily['sunrise'] ?? [];
        $sunsets = $daily['sunset'] ?? [];
        $uvMaxima = $daily['uv_index_max'] ?? [];
        $precipitationHours = $daily['precipitation_hours'] ?? [];

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
                'sunrise' => isset($sunrises[$index]) && is_string($sunrises[$index]) ? substr($sunrises[$index], 11, 5) : null,
                'sunset' => isset($sunsets[$index]) && is_string($sunsets[$index]) ? substr($sunsets[$index], 11, 5) : null,
                'uv_index_max' => isset($uvMaxima[$index]) && is_numeric($uvMaxima[$index]) ? (float)$uvMaxima[$index] : null,
                'precipitation_hours' => isset($precipitationHours[$index]) && is_numeric($precipitationHours[$index]) ? (float)$precipitationHours[$index] : null,
                'hours' => [],
            ];
        }

        return array_slice($days, 0, 7);
    }

    public function record(array $current): ?array
    {
        $changedAtKey = $this->locationId === 2 ? 'second_location_changed_at' : 'active_location_changed_at';
        $changedAtQuery = $this->db->prepare(
            "SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1"
        );
        $changedAtQuery->execute([$changedAtKey]);
        $changedAt = $changedAtQuery->fetchColumn();
        if (is_string($changedAt) && $changedAt !== '') {
            $previousQuery = $this->db->prepare(
                "SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees
                 FROM gaugeiq_pressure_readings
                 WHERE location_id = ? AND created_at >= ? AND observed_at < ?
                   AND (source IS NULL OR source = 'Open-Meteo')
                 ORDER BY observed_at DESC, id DESC LIMIT 1"
            );
            $previousQuery->execute([$this->locationId, $changedAt, (string)$current['observed_at']]);
            $previous = $previousQuery->fetch();
        } else {
            $previousQuery = $this->db->prepare(
                "SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees
                 FROM gaugeiq_pressure_readings
                 WHERE location_id = ? AND observed_at < ? AND (source IS NULL OR source = 'Open-Meteo')
                 ORDER BY observed_at DESC, id DESC LIMIT 1"
            );
            $previousQuery->execute([$this->locationId, (string)$current['observed_at']]);
            $previous = $previousQuery->fetch();
        }

        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_pressure_readings
             (location_id, temperature_c, feels_like_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, rainfall_mm, cloud_cover_percent, weather_code, forecast_json, observed_at, created_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->locationId,
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
