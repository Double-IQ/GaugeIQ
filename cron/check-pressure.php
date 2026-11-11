<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/PushService.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AlertRuleService.php';
require __DIR__ . '/../app/WeatherMapCacheService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
// Refresh the six rolling ECMWF map-frame references and prefetch their
// regional data chunks hourly. Both operations are non-fatal to monitoring.
try {
    $weatherMapCache = new WeatherMapCacheService($pdo, $config);
    if ($weatherMapCache->refreshScheduleIfDue(3600)) {
        echo "Weather-map frame schedule refreshed (3 past + 3 future).\n";
    }
    $prefetchResult = $weatherMapCache->prefetchRegionalFramesIfDue(3600);
    if (!$prefetchResult['skipped']) {
        echo $prefetchResult['output'] . "\n";
    }
} catch (Throwable $weatherMapError) {
    error_log('GaugeIQ regional weather-map refresh failed: ' . $weatherMapError->getMessage());
    fwrite(STDERR, "Weather-map refresh failed (monitoring continues): " . $weatherMapError->getMessage() . "\n");
}

require_once __DIR__ . '/../app/WeatherMapRainfallNowcastService.php';
$secondLocationEnabled = getGaugeIqSetting($pdo, 'second_location_enabled', '0') === '1';
$monitorLocationIds = $secondLocationEnabled ? [1, 2] : [1];
foreach ($monitorLocationIds as $nowcastLocationId) {
    try {
        $rainfallNowcast = new WeatherMapRainfallNowcastService($pdo, $config, $nowcastLocationId);
        $nowcastResult = $rainfallNowcast->refreshIfDue(3600);
        if (!$nowcastResult['skipped']) {
            $nowcastPayload = $nowcastResult['payload'] ?? [];
            $encoding = is_array($nowcastPayload['encoding'] ?? null) ? $nowcastPayload['encoding'] : [];
            echo sprintf(
                "ECMWF rainfall outlook (Location %d): %s [frames=%d, cells=%d, type=%d, compression=%d, scale=%s, offset=%s]\n",
                $nowcastLocationId,
                (string)$nowcastResult['message'],
                count($nowcastPayload['frames'] ?? []),
                (int)($nowcastPayload['grid_cells_sampled'] ?? 0),
                (int)($encoding['data_type'] ?? -1),
                (int)($encoding['compression_type'] ?? -1),
                (string)($encoding['scale_factor'] ?? 'n/a'),
                (string)($encoding['add_offset'] ?? 'n/a')
            );
        }
    } catch (Throwable $nowcastError) {
        error_log('GaugeIQ ECMWF rainfall outlook failed for Location ' . $nowcastLocationId . ': ' . $nowcastError->getMessage());
        fwrite(STDERR, "ECMWF rainfall outlook unavailable for Location {$nowcastLocationId} (monitoring continues): " . $nowcastError->getMessage() . "\n");
    }
}
function setGaugeIqStatus(PDO $pdo, string $key, string $value): void
{
    $exists = $pdo->prepare('SELECT 1 FROM gaugeiq_settings WHERE `key` = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn() === false) {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)');
    } else {
        $stmt = $pdo->prepare('UPDATE gaugeiq_settings SET `value` = ? WHERE `key` = ?');
        $stmt->execute([$value, $key]);
        return;
    }
    $stmt->execute([$key, $value]);
}

function getGaugeIqSetting(PDO $pdo, string $key, string $fallback = ''): string
{
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string)$value;
}

function gaugeIqLocationSettingKey(string $key, int $locationId): string
{
    return $locationId === 2 ? $key . '_location_2' : $key;
}

/**
 * Evaluate the opt-in daily forecast notification once the user's local time is
 * reached. Uses the same current Open-Meteo forecast already fetched for the
 * regular monitor and never changes the ordinary alert rules.
 */
function evaluateDailyForecastAlert(PDO $pdo, array $config, array $current, int $locationId = 1): void
{
    if (getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_enabled', $locationId), '0') !== '1') {
        return;
    }

    $scheduledTime = getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_time', $locationId), '07:00');
    if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $scheduledTime)) {
        $scheduledTime = '07:00';
    }

    try {
        $timezoneName = (string)($current['timezone'] ?? $config['app']['timezone'] ?? 'UTC');
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $scheduledDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $today . ' ' . $scheduledTime, $timezone);
    if (!$scheduledDateTime || $now < $scheduledDateTime) {
        return;
    }
    // Allow the next hourly cron check to deliver the alert. If the
    // chosen time was missed by more than an hour, skip the stale notification.
    $minutesLate = (int)floor(($now->getTimestamp() - $scheduledDateTime->getTimestamp()) / 60);
    if ($minutesLate > 59) {
        return;
    }
    if (getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_evaluated_date', $locationId)) === $today) {
        return;
    }

    $selected = [
        'rain' => getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_rain', $locationId), '1') === '1',
        'wind' => getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_wind', $locationId), '1') === '1',
        'severe' => getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_severe', $locationId), '1') === '1',
    ];
    $alwaysNotify = getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_always', $locationId), '0') === '1';
    if (!in_array(true, $selected, true) && !$alwaysNotify) {
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_evaluated_date', $locationId), $today);
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_status', $locationId), 'No forecast conditions selected.');
        return;
    }

    $forecastDays = is_array($current['forecast'] ?? null) ? $current['forecast'] : [];
    $forecast = null;
    foreach ($forecastDays as $day) {
        if (is_array($day) && (string)($day['date'] ?? '') === $today) {
            $forecast = $day;
            break;
        }
    }
    if ($forecast === null && isset($forecastDays[0]) && is_array($forecastDays[0])) {
        $firstDate = (string)($forecastDays[0]['date'] ?? '');
        if ($firstDate === '' || $firstDate >= $today) {
            $forecast = $forecastDays[0];
        }
    }
    if ($forecast === null) {
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_error', $locationId), 'No forecast values were available for the current local date.');
        error_log('GaugeIQ daily forecast alert skipped: no forecast values for ' . $today . '.');
        return;
    }

    $rainProbability = is_numeric($forecast['rain_probability_percent'] ?? null)
        ? (float)$forecast['rain_probability_percent']
        : null;
    $rainMm = is_numeric($forecast['rain_only_mm'] ?? null)
        ? (float)$forecast['rain_only_mm']
        : (is_numeric($forecast['rain_mm'] ?? null) ? (float)$forecast['rain_mm'] : null);
    $windMax = is_numeric($forecast['wind_speed_max_kmh'] ?? null)
        ? (float)$forecast['wind_speed_max_kmh']
        : null;
    $gustMax = is_numeric($forecast['wind_gusts_max_kmh'] ?? null)
        ? (float)$forecast['wind_gusts_max_kmh']
        : null;
    $weatherCode = is_numeric($forecast['weather_code'] ?? null)
        ? (int)$forecast['weather_code']
        : null;

    $matches = [];
    $snowOnlyWeatherCodes = [71, 73, 75, 77, 85, 86];
    $snowForecast = $weatherCode !== null && in_array($weatherCode, $snowOnlyWeatherCodes, true);
    if ($selected['rain'] &&
        (($rainProbability !== null && $rainProbability >= 50.0 && !$snowForecast) ||
         ($rainMm !== null && $rainMm >= 1.0))) {
        $rainDetail = 'Rain expected';
        $rainParts = [];
        if ($rainProbability !== null) {
            $rainParts[] = 'up to ' . (int)round($rainProbability) . '% chance';
        }
        if ($rainMm !== null) {
            $rainParts[] = number_format($rainMm, 1) . ' mm forecast';
        }
        if ($rainParts !== []) {
            $rainDetail .= ' (' . implode(', ', $rainParts) . ')';
        }
        $matches[] = $rainDetail;
    }

    if ($selected['wind'] &&
        (($windMax !== null && $windMax >= 40.0) ||
         ($gustMax !== null && $gustMax >= 60.0))) {
        $windDetail = 'Strong winds';
        $windParts = [];
        if ($windMax !== null) {
            $windParts[] = 'maximum ' . number_format($windMax, 0) . ' km/h';
        }
        if ($gustMax !== null) {
            $windParts[] = 'gusts up to ' . number_format($gustMax, 0) . ' km/h';
        }
        if ($windParts !== []) {
            $windDetail .= ' (' . implode(', ', $windParts) . ')';
        }
        $matches[] = $windDetail;
    }

    $severeWeatherCodes = [65, 67, 75, 82, 86, 95, 96, 99];
    if ($selected['severe'] &&
        (($weatherCode !== null && in_array($weatherCode, $severeWeatherCodes, true)) ||
         ($gustMax !== null && $gustMax >= 70.0))) {
        $matches[] = 'Severe weather risk (heavy precipitation, violent showers, heavy snow or thunderstorms)';
    }

    $locationName = $locationId === 2
        ? getGaugeIqSetting($pdo, 'second_location_name', 'Location 2')
        : getGaugeIqSetting($pdo, 'location_name', (string)($config['pressure']['location_name'] ?? 'your location'));
    if ($matches === [] && !$alwaysNotify) {
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_evaluated_date', $locationId), $today);
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_status', $locationId), 'No selected conditions met for ' . $today . '.');
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_error', $locationId), '');
        printf("Daily forecast alert: no selected conditions met for %s.\n", $today);
        return;
    }

    if ($matches === []) {
        $matches[] = 'No selected adverse conditions are forecast today';
    }

    $message = "Today's forecast for " . ($locationName !== '' ? $locationName : 'your location') .
        ': ' . implode('; ', $matches) . '.';

    try {
        $push = new PushService($config, $pdo);
        $forecastUrl = rtrim((string)$config['app']['base_url'], '/') . '/forecast.php?from=daily-alert&date=' . rawurlencode($today) . '&location=' . $locationId;
        $result = $push->sendDetailed('GaugeIQ daily forecast', $message, $forecastUrl);
        $sent = (int)($result['sent'] ?? 0);
        $subscriptions = (int)($result['subscriptions'] ?? 0);
        $status = $sent > 0
            ? 'Sent to ' . $sent . ' device(s): ' . implode('; ', $matches) . '.'
            : ($subscriptions === 0
                ? 'Conditions matched, but no devices are subscribed.'
                : 'Conditions matched, but push delivery failed for the subscribed device(s).');

        // Mark the date once the scheduled evaluation/push attempt is complete.
        // This prevents the existing half-hour cron from generating duplicates.
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_evaluated_date', $locationId), $today);
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_status', $locationId), $status);
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_error', $locationId), '');
        printf("Daily forecast alert: %s\n", $status);
    } catch (Throwable $e) {
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('daily_forecast_alert_last_error', $locationId), $e->getMessage());
        error_log('GaugeIQ daily forecast alert failed: ' . $e->getMessage());
        // Leave the date unevaluated so the next scheduled cron may retry.
    }
}

function evaluateEcmwfRainfallOutlookAlert(PDO $pdo, array $config, int $locationId = 1): void
{
    if (getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_enabled', $locationId), '0') !== '1') {
        return;
    }

    $radiusKm = (int)getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_radius_km', $locationId), '50');
    if (!in_array($radiusKm, [15, 50, 100], true)) $radiusKm = 50;

    $json = getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('weather_map_rainfall_nowcast', $locationId), '');
    $payload = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    if (!is_array($payload)) return;

    $updatedAt = strtotime((string)($payload['updated_at'] ?? ''));
    if ($updatedAt === false || $updatedAt < time() - 3 * 3600) return;

    $candidates = [];
    foreach (($payload['frames'] ?? []) as $frame) {
        if (!is_array($frame) || !is_numeric($frame['nearest_rain_km'] ?? null)) continue;
        $distance = (float)$frame['nearest_rain_km'];
        if ($distance <= $radiusKm) {
            $frameTime = strtotime((string)($frame['time'] ?? ''));
            if ($frameTime !== false) $candidates[] = ['time' => $frameTime, 'distance' => $distance];
        }
    }
    if ($candidates === []) return;

    usort($candidates, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
    $target = $candidates[0];
    $lastSent = strtotime(getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_sent_at', $locationId), ''));
    if ($lastSent !== false && $lastSent >= time() - 3 * 3600) return;

    try {
        $timezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }
    $locationName = $locationId === 2
        ? getGaugeIqSetting($pdo, 'second_location_name', 'Location 2')
        : getGaugeIqSetting($pdo, 'location_name', (string)($config['pressure']['location_name'] ?? 'your location'));
    $forecastTime = (new DateTimeImmutable('@' . $target['time']))->setTimezone($timezone)->format('H:i');
    $message = sprintf(
        'ECMWF IFS forecasts rain within %d km of %s around %s. The nearest forecast rain is approximately %.0f km away. This is model guidance, not live radar.',
        $radiusKm,
        $locationName !== '' ? $locationName : 'your location',
        $forecastTime,
        $target['distance']
    );

    try {
        $push = new PushService($config, $pdo);
        $result = $push->sendDetailed(
            'GaugeIQ rainfall outlook',
            $message,
            rtrim((string)$config['app']['base_url'], '/') . '/'
        );
        $sent = (int)($result['sent'] ?? 0);
        $subscriptions = (int)($result['subscriptions'] ?? 0);
        if ($sent > 0) {
            setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_sent_at', $locationId), gmdate('c'));
            setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_status', $locationId), 'Sent to ' . $sent . ' device(s): rain forecast within ' . $radiusKm . ' km around ' . $forecastTime . '.');
            setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_error', $locationId), '');
            printf("ECMWF rainfall alert: sent to %d device(s).\n", $sent);
        } else {
            $status = $subscriptions === 0
                ? 'Rainfall condition matched, but no devices are subscribed.'
                : 'Rainfall condition matched, but push delivery failed.';
            setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_status', $locationId), $status);
            printf("ECMWF rainfall alert: %s\n", $status);
        }
    } catch (Throwable $error) {
        setGaugeIqStatus($pdo, gaugeIqLocationSettingKey('ecmwf_rainfall_alert_last_error', $locationId), $error->getMessage());
        error_log('GaugeIQ ECMWF rainfall alert failed: ' . $error->getMessage());
    }
}

// Each location has its own hourly observations, backfill status, baseline,
// alert rules and notification state. A failure at one location must not stop
// the other location from being checked.
$successfulLocations = 0;
$failedLocations = 0;
foreach ($monitorLocationIds as $locationId) {
    $statusSuffix = $locationId === 2 ? '_location_2' : '';
    $service = new PressureService($config, $pdo, $locationId);
    $locationName = $locationId === 2
        ? getGaugeIqSetting($pdo, 'second_location_name', 'Location 2')
        : getGaugeIqSetting($pdo, 'location_name', (string)($config['pressure']['location_name'] ?? 'Location 1'));

    try {
        $backfillSuccessKey = 'recent_backfill_last_success_at' . $statusSuffix;
        $backfillErrorKey = 'recent_backfill_last_error' . $statusSuffix;
        $lastBackfillValue = getGaugeIqSetting($pdo, $backfillSuccessKey, '');
        $lastBackfillTime = $lastBackfillValue !== '' ? strtotime($lastBackfillValue) : false;
        if ($lastBackfillTime === false || $lastBackfillTime < time() - 45 * 60) {
            $locationTimezone = getGaugeIqSetting($pdo, 'location_timezone' . $statusSuffix, (string)($config['app']['timezone'] ?? 'UTC'));
            $backfillCount = $service->refreshRecentBackfill(24, $locationTimezone);
            setGaugeIqStatus($pdo, $backfillSuccessKey, gmdate('c'));
            setGaugeIqStatus($pdo, $backfillErrorKey, '');
            printf("Recent hourly fallback refreshed for %s: %d rows.\n", $locationName, $backfillCount);
        }
    } catch (Throwable $backfillError) {
        try {
            setGaugeIqStatus($pdo, $backfillErrorKey, $backfillError->getMessage());
        } catch (Throwable) {
            // Fallback status is non-fatal.
        }
        fwrite(STDERR, "Recent hourly fallback failed for {$locationName} (live monitoring continues): " . $backfillError->getMessage() . "\n");
    }

    try {
        $current = $service->fetchCurrent();
        $locationTimezone = (string)($current['timezone'] ?? $config['app']['timezone'] ?? 'UTC');
        try {
            new DateTimeZone($locationTimezone);
            setGaugeIqStatus($pdo, 'location_timezone' . $statusSuffix, $locationTimezone);
        } catch (Throwable) {
            $locationTimezone = (string)($config['app']['timezone'] ?? 'UTC');
        }
        $previous = $service->record($current);
        setGaugeIqStatus($pdo, 'monitor_last_success_at' . $statusSuffix, gmdate('c'));
        setGaugeIqStatus($pdo, 'monitor_last_error' . $statusSuffix, '');

        evaluateDailyForecastAlert($pdo, $config, $current, $locationId);
        evaluateEcmwfRainfallOutlookAlert($pdo, $config, $locationId);

        $lookbackHours = max(1, min(168, (int)getGaugeIqSetting($pdo, gaugeIqLocationSettingKey('alert_lookback_hours', $locationId), '3')));
        $baseline = null;
        $targetTimestamp = strtotime((string)$current['observed_at']);
        if ($targetTimestamp !== false) {
            $targetTimestamp -= $lookbackHours * 3600;
            $baselineSql = "SELECT pressure_hpa, humidity_percent, dew_point_c, temperature_c,
                                   wind_speed_kmh, wind_direction_degrees, observed_at
                            FROM gaugeiq_pressure_readings
                            WHERE location_id = ? AND observed_at <= ? AND observed_at < ?
                              AND (source IS NULL OR source = 'Open-Meteo')";
            $baselineParams = [
                $locationId,
                date('Y-m-d\\TH:i:s', $targetTimestamp),
                (string)$current['observed_at'],
            ];
            if ($locationId === 1) {
                $locationChangedAt = getGaugeIqSetting($pdo, 'active_location_changed_at', '');
                if ($locationChangedAt !== '') {
                    $baselineSql .= ' AND created_at >= ?';
                    $baselineParams[] = $locationChangedAt;
                }
            }
            $baselineSql .= ' ORDER BY observed_at DESC, id DESC LIMIT 1';
            $baselineQuery = $pdo->prepare($baselineSql);
            $baselineQuery->execute($baselineParams);
            $baseline = $baselineQuery->fetch() ?: null;
        }

        $pressureChange = $baseline === null ? 0.0 : (float)$current['pressure_hpa'] - (float)$baseline['pressure_hpa'];
        printf(
            "GaugeIQ %s: %.1f hPa | %.0f%% humidity | %.1f km/h %s (%s)%s\n",
            $locationName,
            $current['pressure_hpa'],
            $current['humidity_percent'],
            $current['wind_speed_kmh'],
            PressureService::directionLabel($current['wind_direction_degrees']),
            $current['wind_direction_degrees'],
            $current['observed_at'],
            $previous === null ? '' : ' | pressure change ' . number_format($pressureChange, 1) . ' hPa'
        );

        try {
            $alertTimezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
        } catch (Throwable) {
            $alertTimezone = new DateTimeZone('UTC');
        }
        $ruleService = new AlertRuleService($pdo, $alertTimezone, $locationId);
        $ruleMatches = $baseline === null ? [] : $ruleService->evaluate($baseline, $current);
        $weatherStabilityMatches = $ruleService->evaluateWeatherStability();
        $existingRuleIds = array_map(static fn(array $match): int => (int)$match['id'], $ruleMatches);
        foreach ($weatherStabilityMatches as $match) {
            if (!in_array((int)$match['id'], $existingRuleIds, true)) {
                $ruleMatches[] = $match;
            }
        }

        if ($ruleMatches !== []) {
            foreach ($ruleMatches as $match) {
                $ruleService->markTriggered(
                    (int)$match['id'],
                    '[' . $locationName . '] ' . (string)$match['message'],
                    (string)$current['observed_at']
                );
            }
            $messages = array_map(
                static fn(array $match): string => '[' . $locationName . '] ' . (string)$match['message'],
                $ruleMatches
            );
            $push = new PushService($config, $pdo);
            $sent = $push->send('GaugeIQ weather alert', implode(' ', $messages));
            printf("Weather alert for %s sent to %d device(s).\n", $locationName, $sent);
        }

        $successfulLocations++;
    } catch (Throwable $e) {
        $failedLocations++;
        try {
            setGaugeIqStatus($pdo, 'monitor_last_error' . $statusSuffix, $e->getMessage());
        } catch (Throwable) {
            // Preserve the original monitoring exception.
        }
        error_log('GaugeIQ monitoring failed for ' . $locationName . ': ' . $e->getMessage());
        fwrite(STDERR, "Weather monitoring failed for {$locationName}: " . $e->getMessage() . "\n");
    }
}

if ($successfulLocations === 0 || $failedLocations > 0) {
    exit(1);
}
