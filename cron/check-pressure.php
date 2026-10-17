<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/PushService.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AlertRuleService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
$service = new PressureService($config, $pdo);

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

/**
 * Evaluate the opt-in daily forecast notification once the user's local time is
 * reached. Uses the same current Open-Meteo forecast already fetched for the
 * regular monitor and never changes the ordinary alert rules.
 */
function evaluateDailyForecastAlert(PDO $pdo, array $config, array $current): void
{
    if (getGaugeIqSetting($pdo, 'daily_forecast_alert_enabled', '0') !== '1') {
        return;
    }

    $scheduledTime = getGaugeIqSetting($pdo, 'daily_forecast_alert_time', '07:00');
    if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $scheduledTime)) {
        $scheduledTime = '07:00';
    }

    try {
        $timezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $scheduledDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $today . ' ' . $scheduledTime, $timezone);
    if (!$scheduledDateTime || $now < $scheduledDateTime) {
        return;
    }
    // Allow the next normal half-hour cron check to deliver the alert. If the
    // chosen time was missed by more than an hour, skip the stale notification.
    $minutesLate = (int)floor(($now->getTimestamp() - $scheduledDateTime->getTimestamp()) / 60);
    if ($minutesLate > 59) {
        return;
    }
    if (getGaugeIqSetting($pdo, 'daily_forecast_alert_last_evaluated_date') === $today) {
        return;
    }

    $selected = [
        'rain' => getGaugeIqSetting($pdo, 'daily_forecast_alert_rain', '1') === '1',
        'wind' => getGaugeIqSetting($pdo, 'daily_forecast_alert_wind', '1') === '1',
        'severe' => getGaugeIqSetting($pdo, 'daily_forecast_alert_severe', '1') === '1',
    ];
    if (!in_array(true, $selected, true)) {
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_evaluated_date', $today);
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_status', 'No forecast conditions selected.');
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
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_error', 'No forecast values were available for the current local date.');
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

    $locationName = getGaugeIqSetting(
        $pdo,
        'location_name',
        (string)($config['pressure']['location_name'] ?? 'your location')
    );
    if ($matches === []) {
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_evaluated_date', $today);
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_status', 'No selected conditions met for ' . $today . '.');
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_error', '');
        printf("Daily forecast alert: no selected conditions met for %s.\n", $today);
        return;
    }

    $message = "Today's forecast for " . ($locationName !== '' ? $locationName : 'your location') .
        ': ' . implode('; ', $matches) . '.';

    try {
        $push = new PushService($config, $pdo);
        $forecastUrl = rtrim((string)$config['app']['base_url'], '/') . '/forecast.php?from=daily-alert&date=' . rawurlencode($today);
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
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_evaluated_date', $today);
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_status', $status);
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_error', '');
        printf("Daily forecast alert: %s\n", $status);
    } catch (Throwable $e) {
        setGaugeIqStatus($pdo, 'daily_forecast_alert_last_error', $e->getMessage());
        error_log('GaugeIQ daily forecast alert failed: ' . $e->getMessage());
        // Leave the date unevaluated so the next scheduled cron may retry.
    }
}

// Refresh the temporary, model-derived hourly backfill at most about once an hour.
// This is deliberately non-fatal: an unavailable fallback must never stop normal
// live monitoring or alert evaluation.
try {
    $backfillStatusQuery = $pdo->prepare(
        "SELECT value FROM gaugeiq_settings WHERE `key` = 'recent_backfill_last_success_at' LIMIT 1"
    );
    $backfillStatusQuery->execute();
    $lastBackfillValue = $backfillStatusQuery->fetchColumn();
    $lastBackfillTime = is_string($lastBackfillValue) ? strtotime($lastBackfillValue) : false;

    if ($lastBackfillTime === false || $lastBackfillTime < time() - 45 * 60) {
        $backfillCount = $service->refreshRecentBackfill(24);
        setGaugeIqStatus($pdo, 'recent_backfill_last_success_at', gmdate('c'));
        setGaugeIqStatus($pdo, 'recent_backfill_last_error', '');
        printf("Recent hourly fallback refreshed: %d rows.\n", $backfillCount);
    }
} catch (Throwable $backfillError) {
    try {
        setGaugeIqStatus($pdo, 'recent_backfill_last_error', $backfillError->getMessage());
    } catch (Throwable) {
        // A fallback-status failure must not interrupt the live monitor.
    }
    fwrite(STDERR, "Recent hourly fallback refresh failed (live monitoring continues): " . $backfillError->getMessage() . "\n");
}

try {
    $current = $service->fetchCurrent();
    $previous = $service->record($current);
    setGaugeIqStatus($pdo, 'monitor_last_success_at', gmdate('c'));
    setGaugeIqStatus($pdo, 'monitor_last_error', '');

} catch (Throwable $e) {
    try {
        setGaugeIqStatus($pdo, 'monitor_last_error', $e->getMessage());
    } catch (Throwable) {
        // Preserve the original monitoring error when status storage is unavailable.
    }
    fwrite(STDERR, "Weather monitoring failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Daily forecast notifications are independent of ordinary event-triggered alerts.
// This call occurs before the normal alert code can exit early when no rule matches.
evaluateDailyForecastAlert($pdo, $config, $current);

$lookbackHours = 3;
$lookbackSetting = $pdo->prepare(
    "SELECT value FROM gaugeiq_settings WHERE key = 'alert_lookback_hours'"
);
$lookbackSetting->execute();
$storedLookback = $lookbackSetting->fetchColumn();
if ($storedLookback !== false) {
    $lookbackHours = max(1, min(168, (int)$storedLookback));
}

$baseline = null;
$locationChangedAtQuery = $pdo->prepare(
    "SELECT value FROM gaugeiq_settings WHERE `key` = 'active_location_changed_at' LIMIT 1"
);
$locationChangedAtQuery->execute();
$locationChangedAt = $locationChangedAtQuery->fetchColumn();
$targetTimestamp = strtotime((string)$current['observed_at']);
if ($targetTimestamp !== false) {
    $targetTimestamp -= $lookbackHours * 3600;
    $baselineSql = "SELECT pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at
                    FROM gaugeiq_pressure_readings
                    WHERE observed_at <= ? AND observed_at < ?
                      AND (source IS NULL OR source = 'Open-Meteo')";
    $baselineParams = [
        date('Y-m-d\\TH:i:s', $targetTimestamp),
        (string)$current['observed_at'],
    ];
    if ($locationChangedAt !== false && $locationChangedAt !== null && $locationChangedAt !== '') {
        $baselineSql .= ' AND created_at >= ?';
        $baselineParams[] = (string)$locationChangedAt;
    }
    $baselineSql .= ' ORDER BY observed_at DESC, id DESC LIMIT 1';
    $baselineQuery = $pdo->prepare($baselineSql);
    $baselineQuery->execute($baselineParams);
    $baseline = $baselineQuery->fetch() ?: null;
}

$pressureChange = $baseline === null
    ? 0.0
    : $current['pressure_hpa'] - (float)$baseline['pressure_hpa'];

printf(
    "GaugeIQ: %.1f hPa | %.0f%% humidity | %.1f km/h %s (%s)%s\n",
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

$ruleService = new AlertRuleService($pdo, $alertTimezone);
$ruleMatches = $baseline === null ? [] : $ruleService->evaluate($baseline, $current);
$weatherStabilityMatches = $ruleService->evaluateWeatherStability();
$existingRuleIds = array_map(static fn(array $match): int => (int)$match['id'], $ruleMatches);
foreach ($weatherStabilityMatches as $match) {
    if (!in_array((int)$match['id'], $existingRuleIds, true)) {
        $ruleMatches[] = $match;
    }
}
$alerts = array_map(
    static fn(array $match): string => $match['message'],
    $ruleMatches
);

// Keep the original pressure configuration working for installations that have
// not yet created any alert rules in the Settings screen.
if ($ruleMatches === [] && $baseline !== null && $service->pressureThresholdExceeded($baseline, $current['pressure_hpa'])) {
    $baseline = $pdo->prepare(
        "SELECT value FROM gaugeiq_settings WHERE key = 'last_notified_pressure_hpa'"
    );
    $baseline->execute();
    $baselineValue = $baseline->fetchColumn();
    $baselinePressure = $baselineValue === false ? null : (float)$baselineValue;
    $notificationChange = $baselinePressure === null
        ? $pressureChange
        : $current['pressure_hpa'] - $baselinePressure;

    if ($baselinePressure === null ||
        abs($notificationChange) >= (float)$config['pressure']['threshold_hpa']) {
        $direction = $pressureChange >= 0 ? 'rising' : 'falling';
        $alerts[] = sprintf(
            'Pressure is %s by %.1f hPa to %.1f hPa.',
            $direction,
            abs($pressureChange),
            $current['pressure_hpa']
        );
    }
}
if ($alerts === []) {
    exit;
}

try {
    // Record the alert event independently of push delivery. A triggered rule
    // remains part of GaugeIQ history even when no device is subscribed.
    foreach ($ruleMatches as $match) {
        $ruleService->markTriggered(
            (int)$match['id'],
            (string)$match['message'],
            (string)$current['observed_at']
        );
    }

    $push = new PushService($config, $pdo);
    $sent = $push->send(
        'GaugeIQ weather alert',
        implode(' ', $alerts)
    );

    if ($sent > 0) {
        if ($ruleMatches === [] && $baseline !== null && $service->pressureThresholdExceeded($baseline, $current['pressure_hpa'])) {
            $value = (string)$current['pressure_hpa'];
            $exists = $pdo->query(
                "SELECT 1 FROM gaugeiq_settings WHERE key = 'last_notified_pressure_hpa'"
            )->fetchColumn();

            if ($exists === false) {
                $stmt = $pdo->prepare(
                    "INSERT INTO gaugeiq_settings (key, value) VALUES ('last_notified_pressure_hpa', ?)"
                );
                $stmt->execute([$value]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE gaugeiq_settings SET value = ? WHERE key = 'last_notified_pressure_hpa'"
                );
                $stmt->execute([$value]);
            }
        }
    }

    printf("Weather alert sent to %d device(s).\n", $sent);
} catch (Throwable $e) {
    fwrite(STDERR, "Push delivery failed: " . $e->getMessage() . "\n");
    exit(1);
}
