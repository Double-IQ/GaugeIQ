<?php
declare(strict_types=1);

$config = $configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    header('Location: install/');
    exit;
}
$config = require $configPath;
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/Version.php';
require __DIR__ . '/../app/AdminAuth.php';
require_once __DIR__ . '/../app/WeatherFormulaConfig.php';
date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
AdminAuth::startSession();
$csrfToken = AdminAuth::csrfToken();
$service = new PressureService($config, $db->pdo());
$pdo = $db->pdo();
migrateDatabase($pdo);
$weatherFormulaValues = weatherFormulaValues($pdo);
$weatherFormulaJson = json_encode($weatherFormulaValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$locationName = (string)$config['pressure']['location_name'];
$dashboardLatitude = (string)($config['pressure']['latitude'] ?? '');
$dashboardLongitude = (string)($config['pressure']['longitude'] ?? '');
$dashboardTimezone = (string)($config['app']['timezone'] ?? 'UTC');
$coordinateSettings = $pdo->prepare("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('active_location_latitude', 'active_location_longitude', 'active_location_name', 'active_location_timezone', 'location_latitude', 'location_longitude', 'location_name')");
$coordinateSettings->execute();
$storedCoordinates = [];
foreach ($coordinateSettings->fetchAll() as $coordinateSetting) {
    $storedCoordinates[(string)$coordinateSetting['key']] = (string)$coordinateSetting['value'];
}
$dashboardLatitude = $storedCoordinates['active_location_latitude']
    ?? $storedCoordinates['location_latitude']
    ?? $dashboardLatitude;
$dashboardLongitude = $storedCoordinates['active_location_longitude']
    ?? $storedCoordinates['location_longitude']
    ?? $dashboardLongitude;
$locationName = $storedCoordinates['active_location_name']
    ?? $storedCoordinates['location_name']
    ?? $locationName;
$dashboardTimezone = $storedCoordinates['active_location_timezone'] ?? $dashboardTimezone;

try {
    $changedAtStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = 'active_location_changed_at' LIMIT 1");
    $changedAtStmt->execute();
    $activeLocationChangedAt = $changedAtStmt->fetchColumn();
    if (is_string($activeLocationChangedAt) && $activeLocationChangedAt !== '') {
        $latestStmt = $pdo->prepare(
            "SELECT * FROM gaugeiq_pressure_readings
             WHERE created_at >= ? AND (source IS NULL OR source = 'Open-Meteo')
             ORDER BY id DESC LIMIT 1"
        );
        $latestStmt->execute([$activeLocationChangedAt]);
        $latest = $latestStmt->fetch() ?: null;
        $rangeStmt = $pdo->prepare(
            "SELECT MIN(pressure_hpa) AS pressure_low, MAX(pressure_hpa) AS pressure_high
             FROM gaugeiq_pressure_readings
             WHERE created_at >= ?
               AND (source IS NULL OR source IN ('Open-Meteo', 'Open-Meteo Historical Weather'))"
        );
        $rangeStmt->execute([$activeLocationChangedAt]);
        $pressureHistory = $rangeStmt->fetch() ?: [];
    } else {
        $latest = $pdo->query(
            "SELECT * FROM gaugeiq_pressure_readings
             WHERE source IS NULL OR source = 'Open-Meteo'
             ORDER BY id DESC LIMIT 1"
        )->fetch() ?: null;
        $pressureHistory = $pdo->query(
            "SELECT MIN(pressure_hpa) AS pressure_low, MAX(pressure_hpa) AS pressure_high
             FROM gaugeiq_pressure_readings
             WHERE source IS NULL OR source IN ('Open-Meteo', 'Open-Meteo Historical Weather')"
        )->fetch() ?: [];
    }

    // Dashboard rendering is read-only: display the latest stored reading.
    // New observations are collected by the scheduled server-side monitor, not on app open.

    $current = $latest;
    if ($current) {
        $decodedForecast = json_decode((string)($current['forecast_json'] ?? '[]'), true);
        $current['forecast'] = is_array($decodedForecast) ? $decodedForecast : [];
    }
    // Show the pressure change over approximately one hour, using only
    // readings stored by the regular Open-Meteo cron. The ±30-minute window
    // accommodates the 30-minute collection cadence without fetching on open.
    $change = 0.0;
    if ($latest && !empty($latest['observed_at'])) {
        $latestTimestamp = strtotime((string)$latest['observed_at']);
        if ($latestTimestamp !== false) {
            $targetTimestamp = $latestTimestamp - 3600;
            $windowStart = date('Y-m-d\\TH:i:s', $targetTimestamp - 1800);
            $windowEnd = date('Y-m-d\\TH:i:s', $targetTimestamp + 1800);
            $pressurePriorSql = "SELECT pressure_hpa, observed_at
                                 FROM gaugeiq_pressure_readings
                                 WHERE observed_at >= ? AND observed_at <= ?
                                   AND (source IS NULL OR source = 'Open-Meteo')";
            $pressurePriorParams = [$windowStart, $windowEnd];
            if (isset($activeLocationChangedAt) && is_string($activeLocationChangedAt) && $activeLocationChangedAt !== '') {
                $pressurePriorSql .= ' AND created_at >= ?';
                $pressurePriorParams[] = $activeLocationChangedAt;
            }
            $pressurePriorSql .= ' ORDER BY observed_at ASC, id ASC';
            $pressurePriorStmt = $pdo->prepare($pressurePriorSql);
            $pressurePriorStmt->execute($pressurePriorParams);
            $pressurePriorRows = $pressurePriorStmt->fetchAll();
            $closestPressurePrior = null;
            $closestPressureDistance = PHP_INT_MAX;
            foreach ($pressurePriorRows as $pressurePriorRow) {
                $pressurePriorTimestamp = strtotime((string)($pressurePriorRow['observed_at'] ?? ''));
                if ($pressurePriorTimestamp === false) {
                    continue;
                }
                $distance = abs($pressurePriorTimestamp - $targetTimestamp);
                if ($distance < $closestPressureDistance) {
                    $closestPressureDistance = $distance;
                    $closestPressurePrior = $pressurePriorRow;
                }
            }
            if ($closestPressurePrior !== null) {
                $change = (float)$latest['pressure_hpa'] - (float)$closestPressurePrior['pressure_hpa'];
            }
        }
    }

    $settings = [];
    foreach ($pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('monitor_last_success_at', 'monitor_last_error')") as $setting) {
        $settings[(string)$setting['key']] = (string)$setting['value'];
    }
    $lastMonitorAt = $settings['monitor_last_success_at'] ?? null;
    $monitorError = $settings['monitor_last_error'] ?? '';
    $subscriptionCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_push_subscriptions')->fetchColumn();
    $enabledRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE enabled = 1')->fetchColumn();
    $stabilityThresholdStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = 'stability_alert_threshold' LIMIT 1");
    $stabilityThresholdStmt->execute();
    $stabilityThresholdValue = $stabilityThresholdStmt->fetchColumn();
    $weatherStabilityAlertThreshold = ($stabilityThresholdValue !== false && is_numeric($stabilityThresholdValue)
        && (float)$stabilityThresholdValue >= 0 && (float)$stabilityThresholdValue <= 10)
        ? 10 - (int)$stabilityThresholdValue
        : null;
    $recentAlerts = $pdo->query(
        'SELECT e.id, e.message, e.observed_at, r.name FROM gaugeiq_alert_events e LEFT JOIN gaugeiq_alert_rules r ON r.id = e.rule_id ORDER BY e.id DESC LIMIT 8'
    )->fetchAll();
} catch (Throwable $e) {
    $current = null;
    $latest = null;
    $change = 0.0;
    $error = $e->getMessage();
    $lastMonitorAt = null;
    $monitorError = '';
    $subscriptionCount = 0;
    $enabledRuleCount = 0;
    $recentAlerts = [];
}

$checkMinutes = max(1, (int)$config['pressure']['check_interval_minutes']);
// The cPanel cron is scheduled every 30 minutes in production. Keep monitoring
// health separate from the weather/alert check interval so the actual cron
// cadence is not incorrectly reported as overdue.
$monitorCronMinutes = 30;
$nextMonitorAt = $lastMonitorAt ? strtotime($lastMonitorAt) + ($monitorCronMinutes * 60) : null;
$monitorAge = $lastMonitorAt ? time() - (int)strtotime($lastMonitorAt) : null;
$monitorHealthy = $monitorAge !== null && $monitorAge <= ($monitorCronMinutes * 60 * 1.5);
$cronScript = realpath(__DIR__ . '/../cron/check-pressure.php') ?: (__DIR__ . '/../cron/check-pressure.php');
$cronCommand = '/usr/local/bin/php -q ' . escapeshellarg($cronScript);

$lastRefreshedLabel = 'Last updated — waiting for the first scheduled server check';
if ($current && !empty($current['observed_at'])) {
    $lastRefreshedTimestamp = strtotime((string)$current['observed_at']);
    if ($lastRefreshedTimestamp !== false) {
        $lastRefreshedLabel = 'Last updated at ' . date('d M Y, H:i', $lastRefreshedTimestamp);
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f3f4f6" id="themeColorMeta">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="192x192" href="/branding/icon-192.png">
<link rel="apple-touch-icon" href="/branding/apple-touch-icon.png">
<link rel="stylesheet" href="css/app.css?v=20261029-atmospheric-monitor-polish">
<title>GaugeIQ</title>
</head>
<body data-weather-formulas="<?= htmlspecialchars((string)$weatherFormulaJson, ENT_QUOTES, 'UTF-8') ?>">
<div id="gaugeiqLoadingScreen" class="gaugeiq-loading-screen" role="status" aria-live="polite" aria-label="Loading GaugeIQ">
    <img src="/branding/gaugeiq-logo-dark-background-original.png" alt="GaugeIQ" width="300" height="120" fetchpriority="high">
    <p id="gaugeiqLoadingMessage">Loading your weather dashboard…</p>
    <span class="gaugeiq-loading-indicator" aria-hidden="true"></span>
</div>
<main class="shell">
    <div id="updateNotice" class="update-notice" hidden role="status">
        <strong id="updateNoticeTitle">GaugeIQ update available</strong>
        <span id="updateNoticeText"></span>
        <a id="updateNoticeLink" href="#" target="_blank" rel="noopener">View release</a>
    </div>

    <header>
        <div class="header-actions">
            <button id="gaugeiqMenuButton" class="menu-button" type="button" aria-label="Open GaugeIQ administration"><img src="/branding/gaugeiq-logo-dark-background-original.png" alt="GaugeIQ" width="110" height="32" class="menu-logo"></button>
            <button id="notifyButton" class="secondary" type="button">Enable alerts</button>
        </div>
    </header>

    <?php
    $temperatureC = $current ? (float)($current['temperature_c'] ?? 0.0) : 0.0;
    $dewPointC = $current ? (float)($current['dew_point_c'] ?? 0.0) : 0.0;
    $feelsLikeC = $current && isset($current['feels_like_c']) ? (float)$current['feels_like_c'] : $temperatureC;
    $forecast = $current && is_array($current['forecast'] ?? null) ? $current['forecast'] : [];
    // Daily highs/lows are stored inside forecast_json, not as columns on the
    // current-reading row. Read the first normalised forecast day for today's card.
    $forecastHighValue = $forecast[0]['temperature_max_c'] ?? ($current['forecast_high_c'] ?? null);
    $forecastLowValue = $forecast[0]['temperature_min_c'] ?? ($current['forecast_low_c'] ?? null);
    $forecastHighC = is_numeric($forecastHighValue) ? (float)$forecastHighValue : null;
    $forecastLowC = is_numeric($forecastLowValue) ? (float)$forecastLowValue : null;
    $humidityPercent = $current ? max(0.0, min(100.0, (float)$current['humidity_percent'])) : 0.0;
    $humidityZone = !$current ? 'Unavailable' : ($humidityPercent < 40 ? 'Dry' : ($humidityPercent < 60 ? 'Comfortable' : 'Humid'));
    $humidityZoneClass = !$current ? 'unavailable' : ($humidityPercent >= 75 ? 'humid-high' : strtolower(str_replace(' ', '-', $humidityZone)));
    $rainfallMm = $current ? max(0.0, (float)($current['rainfall_mm'] ?? 0.0)) : 0.0;
    // Sum one stored Open-Meteo rainfall sample per hour over the latest 24 hours.
    // Live refreshes can run every 30 minutes, so keep only the latest sample
    // within each hour to avoid double-counting the same hourly period.
    $rainfall24hMm = 0.0;
    try {
        if ($current && !empty($current['observed_at'])) {
            $currentRainTimestamp = strtotime((string)$current['observed_at']);
            if ($currentRainTimestamp !== false) {
                $rainfallWindowStart = date('Y-m-d\\TH:i:s', $currentRainTimestamp - 86400);
                $rainfallWindowEnd = date('Y-m-d\\TH:i:s', $currentRainTimestamp);
                $rainfall24hStmt = $pdo->prepare(
                    "SELECT rainfall_mm, observed_at
                     FROM gaugeiq_pressure_readings
                     WHERE observed_at > ? AND observed_at <= ?
                       AND (source IS NULL OR source = 'Open-Meteo')
                     ORDER BY observed_at ASC, id ASC"
                );
                $rainfall24hStmt->execute([$rainfallWindowStart, $rainfallWindowEnd]);
                $hourlyRainfall = [];
                foreach ($rainfall24hStmt->fetchAll() as $rainfallRow) {
                    $rainTimestamp = strtotime((string)($rainfallRow['observed_at'] ?? ''));
                    if ($rainTimestamp === false || !is_numeric($rainfallRow['rainfall_mm'] ?? null)) {
                        continue;
                    }
                    $hourKey = date('Y-m-d H', $rainTimestamp);
                    // Ordered ascending, so later samples replace earlier samples in the hour.
                    $hourlyRainfall[$hourKey] = max(0.0, (float)$rainfallRow['rainfall_mm']);
                }
                $rainfall24hMm = array_sum($hourlyRainfall);
            }
        }
    } catch (Throwable $e) {
        // Keep the dashboard available if the optional rolling rainfall query fails.
        $rainfall24hMm = $rainfallMm;
    }
    $cloudCoverPercent = $current ? max(0.0, min(100.0, (float)($current['cloud_cover_percent'] ?? 0.0))) : 0.0;
    $pressureLow = isset($pressureHistory['pressure_low']) ? (float)$pressureHistory['pressure_low'] : ($current ? (float)$current['pressure_hpa'] : 0.0);
    $pressureHigh = isset($pressureHistory['pressure_high']) ? (float)$pressureHistory['pressure_high'] : ($current ? (float)$current['pressure_hpa'] : 0.0);
    $pressureRange = max(0.1, $pressureHigh - $pressureLow);
    $pressureRatio = $current ? max(0.0, min(1.0, ((float)$current['pressure_hpa'] - $pressureLow) / $pressureRange)) : 0.0;
    // Resolve today's forecast by date in GaugeIQ's configured local timezone.
    // Don't blindly use index 0: older stored forecasts can start on a different day.
    $todayDate = (new DateTimeImmutable('now', new DateTimeZone($dashboardTimezone)))->format('Y-m-d');
    $todayForecast = null;
    foreach ($forecast as $forecastDay) {
        if (!is_array($forecastDay)) {
            continue;
        }
        $forecastDate = (string)($forecastDay['date'] ?? '');
        if ($forecastDate === $todayDate) {
            $todayForecast = $forecastDay;
            break;
        }
        if ($todayForecast === null && $forecastDate !== '' && $forecastDate > $todayDate) {
            $todayForecast = $forecastDay;
        }
    }
    $rainForecastValue = null;
    if (is_array($todayForecast)) {
        // Prefer rain-only daily totals; the precipitation total can also include snow.
        if (isset($todayForecast['rain_only_mm']) && is_numeric($todayForecast['rain_only_mm'])) {
            $rainForecastValue = (float)$todayForecast['rain_only_mm'];
        } elseif (isset($todayForecast['rain_mm']) && is_numeric($todayForecast['rain_mm'])) {
            // Backward compatibility for stored forecasts created before rain_only_mm existed.
            $rainForecastValue = (float)$todayForecast['rain_mm'];
        }
    }
    $rainForecastAvailable = $rainForecastValue !== null;
    $rainForecastMm = $rainForecastAvailable ? max(0.0, $rainForecastValue) : 0.0;
    $rainForecastRatioPercent = $rainForecastAvailable ? max(0.0, min(100.0, ($rainForecastMm / 10.0) * 100.0)) : 0.0;
    $rainProbabilityPercent = is_array($todayForecast) && isset($todayForecast['rain_probability_percent']) && is_numeric($todayForecast['rain_probability_percent'])
        ? max(0, min(100, (int)$todayForecast['rain_probability_percent']))
        : null;
    // Open-Meteo's current rain field is the latest current-period observation,
    // not the same thing as the forecast daily accumulation.
    $observedRainfallMm = $current && is_numeric($current['rainfall_mm'] ?? null)
        ? max(0.0, (float)$current['rainfall_mm'])
        : null;

    $forecastCondition = static function (?int $code, bool $isDay = true): array {
        $clearIcon = $isDay ? 'clear-day' : 'clear-night';
        $mostlyClearIcon = $isDay ? 'clear-day' : 'clear-night';
        $partlyCloudyIcon = $isDay ? 'partly-cloudy-day' : 'partly-cloudy-night';
        return match (true) {
            $code === null => ['icon' => '—', 'slug' => null, 'label' => 'Unavailable'],
            $code === 0 => ['icon' => '☀️', 'slug' => $clearIcon, 'label' => 'Clear sky'],
            $code === 1 => ['icon' => '🌤️', 'slug' => $mostlyClearIcon, 'label' => 'Mainly clear'],
            $code === 2 => ['icon' => '⛅', 'slug' => $partlyCloudyIcon, 'label' => 'Partly cloudy'],
            $code === 3 => ['icon' => '☁️', 'slug' => 'overcast', 'label' => 'Overcast'],
            in_array($code, [45, 48], true) => ['icon' => '🌫️', 'slug' => 'fog', 'label' => 'Fog'],
            in_array($code, [51, 53, 55], true) => ['icon' => '🌦️', 'slug' => 'rain', 'label' => 'Drizzle'],
            in_array($code, [56, 57, 66, 67], true) => ['icon' => '🌧️', 'slug' => 'rain', 'label' => 'Freezing precipitation'],
            in_array($code, [61, 63, 65, 80, 81, 82], true) => ['icon' => '🌧️', 'slug' => 'rain', 'label' => 'Rain'],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => ['icon' => '🌨️', 'slug' => 'snow', 'label' => 'Snow'],
            in_array($code, [95, 96, 99], true) => ['icon' => '⛈️', 'slug' => 'thunderstorm', 'label' => 'Thunderstorm'],
            default => ['icon' => '🌤️', 'slug' => $partlyCloudyIcon, 'label' => 'Mixed conditions'],
        };
    };

    // Two separate circular environmental instruments. Keep their centres
    // deliberately apart so the dials and their labels share the same axis.
    $climateGaugePoint = static function (float $cx, float $cy, float $angle, float $radius): array {
        $radians = deg2rad($angle);
        return [$cx + cos($radians) * $radius, $cy + sin($radians) * $radius];
    };
    $tempRatio = $current ? max(0.0, min(1.0, $temperatureC / 40.0)) : 0.0;
    $dewRatio = $current ? max(0.0, min(1.0, $dewPointC / 40.0)) : 0.0;
    $humidityRatio = $current ? $humidityPercent / 100.0 : 0.0;

    $tempStart = 210.0; $tempSweep = 120.0;
    $dewStart = 115.0; $dewSweep = 310.0;
    $humidityStart = 115.0; $humiditySweep = 310.0;
    $dewAngle = $dewStart + $dewRatio * $dewSweep;
    $humidityAngle = $humidityStart + $humidityRatio * $humiditySweep;
        $dewCx = 25.0; $dewCy = 50.0; $smallR = 20.0;
    $humidityCx = 75.0; $humidityCy = 50.0;

    $climateNeedlePath = static function (float $cx, float $cy, float $angle, float $length): string {
        $radians = deg2rad($angle);
        $perpX = -sin($radians);
        $perpY = cos($radians);
        $tipX = $cx + cos($radians) * $length;
        $tipY = $cy + sin($radians) * $length;
        $midX = $cx + cos($radians) * ($length * 0.42);
        $midY = $cy + sin($radians) * ($length * 0.42);
        $baseX = $cx - cos($radians) * 1.4;
        $baseY = $cy - sin($radians) * 1.4;
        $fmt = static fn(array $p): string => implode(' ', array_map(static fn(float $v): string => number_format($v, 3, '.', ''), $p));
        return 'M ' . $fmt([$baseX + $perpX * 0.9, $baseY + $perpY * 0.9])
            . ' L ' . $fmt([$midX + $perpX * 0.28, $midY + $perpY * 0.28])
            . ' L ' . $fmt([$tipX + $perpX * 0.045, $tipY + $perpY * 0.045])
            . ' L ' . $fmt([$tipX - $perpX * 0.045, $tipY - $perpY * 0.045])
            . ' L ' . $fmt([$midX - $perpX * 0.28, $midY - $perpY * 0.28])
            . ' L ' . $fmt([$baseX - $perpX * 0.9, $baseY - $perpY * 0.9]) . ' Z';
    };

    $dewNeedlePath = $climateNeedlePath($dewCx, $dewCy, $dewAngle, $smallR - 2.5);
    $humidityNeedlePath = $climateNeedlePath($humidityCx, $humidityCy, $humidityAngle, $smallR - 2.5);
    ?> 


        <article class="card metric-card temperature-summary-card">
            <div class="temperature-card-heading">
                <h1 id="dashboardLocationName"><?= htmlspecialchars($locationName, ENT_QUOTES) ?></h1>
                <p id="lastRefreshedAt" class="muted last-refreshed-at" role="status"><?= htmlspecialchars($lastRefreshedLabel, ENT_QUOTES) ?></p>
            </div>
            <?php if ($current): ?>
                <div class="temperature-summary-main">
                    <div class="temperature-summary-current">
                        <strong><?= number_format($temperatureC, 1) ?>°C</strong>
                        <span class="temperature-feels-like-direct">Feels like <?= number_format($feelsLikeC, 1) ?>°C</span>
                    </div>
                    <div class="temperature-summary-forecast">
                        <?php
                        $nextHourIsDay = is_array($forecast[0] ?? null) && array_key_exists('next_hour_is_day', $forecast[0])
                            ? (bool)$forecast[0]['next_hour_is_day']
                            : true;
                        $nextHourCondition = $forecast
                            ? $forecastCondition(isset($forecast[0]['next_hour_weather_code']) && is_numeric($forecast[0]['next_hour_weather_code']) ? (int)$forecast[0]['next_hour_weather_code'] : null, $nextHourIsDay)
                            : ['icon' => '—', 'slug' => null, 'label' => 'Unavailable'];
                        ?>
                        <span class="temperature-forecast-icon" role="img" aria-label="<?= htmlspecialchars('Next hour: ' . $nextHourCondition['label'], ENT_QUOTES) ?>" title="<?= htmlspecialchars('Next hour: ' . $nextHourCondition['label'], ENT_QUOTES) ?>">
                            <?php if (is_string($nextHourCondition['slug'] ?? null)): ?>
                                <img src="/weather-icons/<?= htmlspecialchars($nextHourCondition['slug'], ENT_QUOTES) ?>.svg" alt="" aria-hidden="true" width="48" height="48">
                                <span class="temperature-forecast-fallback" aria-hidden="true" hidden><?= htmlspecialchars($nextHourCondition['icon'], ENT_QUOTES) ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars($nextHourCondition['icon'], ENT_QUOTES) ?>
                            <?php endif; ?>
                        </span>
                        <div class="temperature-summary-forecast-details">
                            <div class="temperature-summary-range">
                                <div><span>High</span><strong><?= $forecastHighC !== null ? number_format((float)$forecastHighC, 1) . '°' : '—' ?></strong></div>
                                <div><span>Low</span><strong><?= $forecastLowC !== null ? number_format((float)$forecastLowC, 1) . '°' : '—' ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="temperature-stability-row temperature-comfort-alerts-row temperature-comfort-below" data-weather-stability-alert-threshold="<?= $weatherStabilityAlertThreshold !== null ? htmlspecialchars((string)$weatherStabilityAlertThreshold, ENT_QUOTES) : '' ?>">
                    <div class="temperature-stability-label temperature-stability-inline" id="temperatureStabilityLabel"><span class="temperature-stability-prefix">WVI: </span><span id="temperatureStabilitySummary" class="temperature-stability-summary">—</span></div>
                    <div class="temperature-conditions-inline" id="weatherConditions" aria-live="polite">Trend: <b class="conditions-status steady">→ Steady</b></div>
                </div>
            <?php else: ?><div class="metric-unavailable">Unavailable</div><?php endif; ?>
        </article>

    <?php
    $forecastPreviewDays = [];
    $forecastTodayDate = (new DateTimeImmutable('now', new DateTimeZone($dashboardTimezone)))->format('Y-m-d');
    foreach ($forecast as $forecastDay) {
        if (!is_array($forecastDay) || !is_string($forecastDay['date'] ?? null) || $forecastDay['date'] < $forecastTodayDate) {
            continue;
        }
        $forecastPreviewDays[] = $forecastDay;
        if (count($forecastPreviewDays) >= 3) {
            break;
        }
    }
    ?>


    <a class="card forecast-preview-card" href="forecast.php" aria-label="Open the detailed seven-day weather forecast">
        <div class="section-heading forecast-preview-heading" style="text-align: left; justify-content: flex-start;">
            <div>
                <p class="label">A quick look at the days ahead</p>
            </div>
        </div>
        <div class="forecast-days forecast-preview-days">
            <?php foreach ($forecastPreviewDays as $forecastDay):
                $previewDate = new DateTimeImmutable((string)$forecastDay['date'], new DateTimeZone($dashboardTimezone));
                $previewCondition = $forecastCondition(
                    isset($forecastDay['weather_code']) && is_numeric($forecastDay['weather_code']) ? (int)$forecastDay['weather_code'] : null
                );
                $previewHigh = is_numeric($forecastDay['temperature_max_c'] ?? null) ? number_format((float)$forecastDay['temperature_max_c'], 0) . '°' : '—';
                $previewLow = is_numeric($forecastDay['temperature_min_c'] ?? null) ? number_format((float)$forecastDay['temperature_min_c'], 0) . '°' : '—';
                $previewRainChance = is_numeric($forecastDay['rain_probability_percent'] ?? null) ? (int)$forecastDay['rain_probability_percent'] . '%' : '—';
            ?>
                <div class="forecast-day">
                    <span class="forecast-day-name"><?= htmlspecialchars($previewDate->format('D'), ENT_QUOTES) ?></span>
                    <span class="forecast-icon" role="img" aria-label="<?= htmlspecialchars($previewCondition['label'], ENT_QUOTES) ?>">
                        <?php if (is_string($previewCondition['slug'] ?? null)): ?>
                            <img src="weather-icons/<?= htmlspecialchars($previewCondition['slug'], ENT_QUOTES) ?>.svg?v=20261027-looping-icons" alt="" aria-hidden="true" width="44" height="44">
                        <?php else: ?>
                            <?= htmlspecialchars($previewCondition['icon'], ENT_QUOTES) ?>
                        <?php endif; ?>
                    </span>
                    <span class="forecast-temperature"><?= $previewHigh ?> <span class="forecast-low"><?= $previewLow ?></span></span>
                    <span class="forecast-rain"><?= htmlspecialchars($previewRainChance, ENT_QUOTES) ?> rain</span>
                </div>
            <?php endforeach; ?>
            <?php if ($forecastPreviewDays === []): ?>
                <p class="muted forecast-preview-unavailable">Forecast data will appear after the next scheduled weather update.</p>
            <?php endif; ?>
        </div>
        <div class="forecast-preview-footer"><span aria-hidden="true">View details&nbsp; →</span></div>
    </a>



        

    <?php
    // Compare against the closest stored observation within 30 minutes of the one-hour
    // target. Keep timestamp formatting consistent with Open-Meteo's ISO timestamps
    // (T separator); space-separated bounds silently excluded same-day readings.
    $climatePrior = null;
    try {
        if ($current && !empty($current['observed_at'])) {
            $currentTimestamp = strtotime((string)$current['observed_at']);
            if ($currentTimestamp !== false) {
                $targetTimestamp = $currentTimestamp - 3600;
                $windowStart = date('Y-m-d\\TH:i:s', $targetTimestamp - 1800);
                $windowEnd = date('Y-m-d\\TH:i:s', $targetTimestamp + 1800);
                $climatePriorStmt = $pdo->prepare(
                    "SELECT dew_point_c, humidity_percent, cloud_cover_percent, rainfall_mm, observed_at
                     FROM gaugeiq_pressure_readings
                     WHERE observed_at >= ? AND observed_at <= ?
                       AND (source IS NULL OR source = 'Open-Meteo')
                     ORDER BY observed_at ASC, id ASC"
                );
                $climatePriorStmt->execute([$windowStart, $windowEnd]);
                $climateCandidates = $climatePriorStmt->fetchAll();
                $closestDistance = PHP_INT_MAX;
                foreach ($climateCandidates as $candidate) {
                    $candidateTimestamp = strtotime((string)($candidate['observed_at'] ?? ''));
                    if ($candidateTimestamp === false) {
                        continue;
                    }
                    $distance = abs($candidateTimestamp - $targetTimestamp);
                    if ($distance < $closestDistance) {
                        $closestDistance = $distance;
                        $climatePrior = $candidate;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Environmental change deltas are optional; keep rendering the dashboard
        // with unavailable deltas if this historical lookup fails.
        $climatePrior = null;
    }
    $climateDelta = static function (string $field) use ($current, $climatePrior): ?float {
        if (!$current || !$climatePrior || !is_numeric($current[$field] ?? null) || !is_numeric($climatePrior[$field] ?? null)) {
            return null;
        }
        return (float)$current[$field] - (float)$climatePrior[$field];
    };
    $dewDelta = $climateDelta('dew_point_c');
    $humidityDelta = $climateDelta('humidity_percent');
    $cloudDelta = $climateDelta('cloud_cover_percent');
    $rainDelta = $climateDelta('rainfall_mm');

    $dewBarRatio = max(0.0, min(1.0, $dewPointC / 40.0));
    $humidityBarRatio = $humidityPercent / 100.0;
    $rainBarRatio = max(0.0, min(1.0, $rainfall24hMm / 10.0));
    $cloudBarRatio = $cloudCoverPercent / 100.0;
    $makeClimateBar = static function (string $label, float $value, string $display, float $ratio, float $scale, ?float $delta, int $deltaDecimals = 0) use ($current): array {
        $deltaRatio = $delta === null ? 0.0 : min(1.0, abs($delta) / $scale);
        return [
            'label' => $label,
            'value' => $value,
            'display' => $display,
            'ratio' => $ratio,
            'delta' => $delta,
            'deltaDisplay' => $delta === null ? '—' : (round($delta, $deltaDecimals) == 0.0 ? '-' : (($delta > 0 ? '+' : '') . number_format($delta, $deltaDecimals))),
            'deltaRatio' => $current ? $deltaRatio : 0.0,
        ];
    };
    $pressureBarRatio = $current ? max(0.0, min(1.0, ((float)$current['pressure_hpa'] - 950.0) / 100.0)) : 0.0;
    $climateBars = [
        $makeClimateBar('Air pressure', $current ? (float)$current['pressure_hpa'] : 0.0, $current ? number_format((float)$current['pressure_hpa'], 1) : '—', $pressureBarRatio, 5.0, $current ? $change : null, 1) + ['kind' => 'air-pressure', 'historyKey' => 'pressure_hpa'],
        $makeClimateBar('Dew point', $dewPointC, $current ? number_format($dewPointC, 1) . '°C' : '—', $dewBarRatio, 40.0, $dewDelta, 1) + ['historyKey' => 'dew_point_c'],
        $makeClimateBar('Humidity', $humidityPercent, $current ? number_format($humidityPercent, 0) . '%' : '—', $humidityBarRatio, 100.0, $humidityDelta) + ['historyKey' => 'humidity_percent'],
        $makeClimateBar('Cloud cover', $cloudCoverPercent, $current ? number_format($cloudCoverPercent, 0) . '%' : '—', $cloudBarRatio, 100.0, $cloudDelta) + ['historyKey' => 'cloud_cover_percent'],
    ];
    if ($current && ($rainfall24hMm > 0.0 || ($rainDelta !== null && abs($rainDelta) > 0.0))) {
        $climateBars[] = $makeClimateBar('Rainfall (24h)', $rainfall24hMm, number_format($rainfall24hMm, 2) . ' mm', $rainBarRatio, 10.0, $rainDelta, 2) + ['kind' => 'current-rain', 'historyKey' => 'rainfall_mm'];
    }

    // Draw compact 24-hour sparklines from readings already stored in the database.
    $climateHistory = [];
    try {
        if ($current && !empty($current['observed_at'])) {
            $climateHistoryEnd = strtotime((string)$current['observed_at']);
            if ($climateHistoryEnd !== false) {
                $climateHistoryStart = date('Y-m-d\\TH:i:s', $climateHistoryEnd - 86400);
                $climateHistoryEndText = date('Y-m-d\\TH:i:s', $climateHistoryEnd);
                $climateHistoryStmt = $pdo->prepare(
                    "SELECT pressure_hpa, dew_point_c, humidity_percent, cloud_cover_percent, rainfall_mm, observed_at
                     FROM gaugeiq_pressure_readings
                     WHERE observed_at >= ? AND observed_at <= ?
                       AND (source IS NULL OR source = 'Open-Meteo')
                     ORDER BY observed_at ASC, id ASC"
                );
                $climateHistoryStmt->execute([$climateHistoryStart, $climateHistoryEndText]);
                $climateHistory = $climateHistoryStmt->fetchAll();
            }
        }
    } catch (Throwable $e) {
        $climateHistory = [];
    }
    foreach ($climateBars as &$climateBar) {
        $values = [];
        foreach ($climateHistory as $historyRow) {
            $historyValue = $historyRow[$climateBar['historyKey']] ?? null;
            if (is_numeric($historyValue)) {
                $values[] = (float)$historyValue;
            }
        }
        $points = [];
        $valueCount = count($values);
        if ($valueCount >= 2) {
            $minValue = min($values);
            $maxValue = max($values);
            $range = max(0.0001, $maxValue - $minValue);
            foreach ($values as $pointIndex => $pointValue) {
                $x = $pointIndex / ($valueCount - 1) * 100;
                $y = 22 - (($pointValue - $minValue) / $range * 18);
                $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
            }
        }
        $climateBar['sparkline'] = implode(' ', $points);
        $climateBar['sparklineEndpoint'] = $valueCount >= 2 ? explode(',', end($points)) : null;
        $climateBar['historyCount'] = $valueCount;
    }
    unset($climateBar);
    ?>
    <section class="card climate-gauge-card" aria-labelledby="climateGaugeTitle">
        <div class="section-heading">
            <div>
                <p class="label" id="climateGaugeTitle">Atmospheric Monitor</p>
            </div>
        </div>
        <div class="climate-bars" aria-label="Current environmental conditions and one-hour changes">
            <?php foreach ($climateBars as $bar):
                // Use CSS classes rather than inline styles so the bars also render under
                // a Content Security Policy that blocks inline style attributes.
                // A compact segmented gauge keeps the current reading and its trend visually connected.
                $segmentCount = 60;
                $fillSegments = (int)round(min(1.0, max(0.0, (float)$bar['ratio'])) * $segmentCount);
                $changeSegments = min($fillSegments, (int)round(min(1.0, max(0.0, (float)$bar['deltaRatio'])) * $segmentCount));
                $changeStartSegment = $fillSegments - $changeSegments;
                $deltaClass = $bar['delta'] === null ? 'unavailable' : ($bar['delta'] > 0 ? 'rise' : ($bar['delta'] < 0 ? 'fall' : 'steady'));
            ?>
                <div class="climate-bar-row<?= isset($bar['kind']) ? ' climate-bar-' . htmlspecialchars($bar['kind'], ENT_QUOTES) : '' ?>">
                    <div class="climate-bar-label"><?= htmlspecialchars($bar['label'], ENT_QUOTES) ?></div>
                    <div class="climate-segment-track" role="img" aria-label="<?= htmlspecialchars($bar['label'] . ': ' . $bar['display'] . ', one-hour change ' . $bar['deltaDisplay'], ENT_QUOTES) ?>">
                        <?php for ($segment = 0; $segment < $segmentCount; $segment++):
                            $segmentClass = $segment >= $fillSegments
                                ? ''
                                : ($segment === $segmentCount - 1
                                    ? 'warning-zone'
                                    : ($segment >= $changeStartSegment ? 'change' : 'active'));
                        ?>
                            <span class="climate-segment<?= $segmentClass !== '' ? ' ' . $segmentClass : '' ?>"></span>
                        <?php endfor; ?>
                    </div>
                    <strong class="climate-bar-change <?= $deltaClass ?>"><?= htmlspecialchars($bar['deltaDisplay'], ENT_QUOTES) ?></strong>
                    <strong class="climate-bar-value"><?= htmlspecialchars($bar['display'], ENT_QUOTES) ?></strong>
                    <?php if ($bar['sparkline'] !== ''): ?>
                        <svg class="climate-bar-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none" role="img" aria-label="<?= htmlspecialchars($bar['label'] . ' trend over the last 24 hours, based on ' . $bar['historyCount'] . ' stored readings', ENT_QUOTES) ?>">
                            <line class="climate-sparkline-baseline" x1="0" y1="23.5" x2="100" y2="23.5"></line>
                            <polyline points="<?= htmlspecialchars($bar['sparkline'], ENT_QUOTES) ?>"></polyline>
                            <?php if (is_array($bar['sparklineEndpoint'])): ?>
                                <circle class="climate-sparkline-endpoint" cx="<?= htmlspecialchars($bar['sparklineEndpoint'][0], ENT_QUOTES) ?>" cy="<?= htmlspecialchars($bar['sparklineEndpoint'][1], ENT_QUOTES) ?>" r="1.45"></circle>
                            <?php endif; ?>
                        </svg>
                    <?php else: ?>
                        <div class="climate-bar-sparkline-unavailable">24-hour trend unavailable</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>





    <section class="measurement-grid">


        <?php
        $windSpeed = $current ? max(0.0, (float)$current['wind_speed_kmh']) : 0.0;
$windSpeedChange1h = 0.0;
if ($current && !empty($current['observed_at'])) {
    try {
        $windObservedAt = new DateTimeImmutable((string)$current['observed_at']);
        $windPreviousStmt = $pdo->prepare(
            "SELECT wind_speed_kmh FROM gaugeiq_pressure_readings
             WHERE observed_at <= ? AND observed_at >= ?
               AND (source IS NULL OR source = 'Open-Meteo')
               AND wind_speed_kmh IS NOT NULL
             ORDER BY observed_at DESC LIMIT 1"
        );
        // Stored Open-Meteo timestamps use an ISO 'T' separator. Match that
        // format in the bounds so string comparisons find the prior reading.
        $windPreviousStmt->execute([
            $windObservedAt->modify('-45 minutes')->format('Y-m-d\\TH:i:s'),
            $windObservedAt->modify('-90 minutes')->format('Y-m-d\\TH:i:s')
        ]);
        $windPreviousSpeed = $windPreviousStmt->fetchColumn();
        if ($windPreviousSpeed !== false) {
            $windSpeedChange1h = $windSpeed - (float)$windPreviousSpeed;
        }
    } catch (Throwable $e) {
        $windSpeedChange1h = 0.0;
    }
}
        $windDegrees = $current ? fmod((float)$current['wind_direction_degrees'] + 360.0, 360.0) : 0.0;
        $windDirection = $current ? PressureService::directionLabel($windDegrees) : '—';
        $windToDegrees = fmod($windDegrees + 180.0, 360.0);
        $windGaugePoint = static function (float $angle, float $radius): array {
            $radians = deg2rad($angle);
            return [50.0 + cos($radians) * $radius, 50.0 + sin($radians) * $radius];
        };
        ?>
        <article class="card metric-card wind-gauge-card wind-hud-card">
            <div class="wind-hud-heading">
                <div>
                    <p class="label">Wind</p>
                    <div class="wind-hud-subtitle">WIND DIRECTION</div>
                </div>
                <div class="wind-hud-live"><span aria-hidden="true"></span> LIVE DATA</div>
            </div>
            <div class="wind-hud-readouts">
                <div class="wind-hud-speed">
                    <span class="wind-hud-label">WIND SPEED</span>
                    <div><strong class="wind-speed-readout"><?= $current ? number_format($windSpeed, 1) : '—' ?></strong><small>km/h</small></div>
                </div>
                <div class="wind-hud-bearing">
                    <span class="wind-hud-label">BEARING</span>
                    <strong><?= $current ? number_format($windDegrees, 0) . '°' : '—' ?> <span><?= htmlspecialchars($windDirection, ENT_QUOTES) ?></span></strong>
                </div>
            </div>
            <div class="wind-hud-vector" role="img" aria-label="<?= $current ? htmlspecialchars('Wind from ' . $windDirection . ' at ' . number_format($windDegrees, 0) . ' degrees, travelling towards ' . PressureService::directionLabel($windToDegrees) . ' at ' . number_format($windToDegrees, 0) . ' degrees', ENT_QUOTES) : 'Wind direction unavailable' ?>">
                <svg viewBox="0 0 340 180" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="windHudVectorLine" x1="0" y1="1" x2="0" y2="0">
                            <stop offset="0%" stop-color="#ffad55"></stop>
                            <stop offset="52%" stop-color="#62c9ff"></stop>
                            <stop offset="100%" stop-color="#249bff"></stop>
                        </linearGradient>
                        <linearGradient id="windHudArrow" x1="0" y1="1" x2="0" y2="0">
                            <stop offset="0%" stop-color="#75d8ff"></stop>
                            <stop offset="100%" stop-color="#249bff"></stop>
                        </linearGradient>
                        <radialGradient id="windHudGlass" cx="50%" cy="30%" r="80%">
                            <stop offset="0%" stop-color="#3975a8" stop-opacity=".16"></stop>
                            <stop offset="100%" stop-color="#06152a" stop-opacity=".02"></stop>
                        </radialGradient>
                    </defs>
                    <rect x="1" y="1" width="338" height="178" rx="12" fill="url(#windHudGlass)" stroke="#75a4d3" stroke-opacity=".12"></rect>
                    <g class="wind-hud-vector-grid" aria-hidden="true">
                        <path d="M18 90H322 M170 12V168" stroke-dasharray="2 7"></path>
                        <path d="M55 24L285 156 M55 156L285 24" stroke-dasharray="2 9" opacity=".45"></path>
                    </g>
                    <g class="wind-hud-vector-arrow" data-wind-to-degrees="<?= number_format($windToDegrees, 2, '.', '') ?>" transform="rotate(<?= number_format($windToDegrees, 2, '.', '') ?> 170 90)">
                        <path d="M170 120V61" stroke="url(#windHudVectorLine)" stroke-width="3.5" stroke-linecap="round"></path>
                        <circle cx="170" cy="120" r="10" fill="#ffad55" opacity=".12"></circle>
                        <circle cx="170" cy="120" r="4.5" fill="#ffad55" stroke="#ffe1ba" stroke-width="1"></circle>
                        <path d="M162 67L170 47L178 67L170 62Z" fill="url(#windHudArrow)" stroke="#8bdcff" stroke-width="1"></path>
                        <circle cx="170" cy="90" r="3" fill="#d8f2ff" opacity=".7"></circle>
                    </g>
                    <g class="wind-hud-vector-labels">
                        <text x="18" y="22">FROM</text>
                        <text x="18" y="38" class="wind-hud-from"><?= htmlspecialchars($windDirection, ENT_QUOTES) ?></text>
                        <text x="322" y="22" text-anchor="end">TOWARDS</text>
                        <text x="322" y="38" text-anchor="end" class="wind-hud-to"><?= htmlspecialchars(PressureService::directionLabel($windToDegrees), ENT_QUOTES) ?></text>
                        <text x="170" y="169" text-anchor="middle">ARROW SHOWS WHERE WIND IS TRAVELLING</text>
                    </g>
                </svg>
            </div>
            <div class="wind-hud-speed-scale">
                <div class="wind-hud-scale-heading"><span>WIND SPEED</span><span>0–60 km/h</span></div>
                <svg class="wind-hud-scale" viewBox="0 0 320 30" aria-hidden="true" focusable="false">
                    <rect class="wind-strip-speed-track" x="10" y="3" width="300" height="7" rx="3.5"></rect>
                    <g class="wind-speed-segments">
                    <?php for ($segment = 0; $segment < 120; $segment++):
                        $segmentSpeed = ($segment + 1) / 2;
                        $segmentX = 10 + ($segment / 120.0) * 300;
                        $segmentActive = $current && $windSpeed >= $segmentSpeed;
                        $segmentChange = $windSpeedChange1h > 0.0
                            ? ($segmentSpeed > ($windSpeed - $windSpeedChange1h) && $segmentSpeed <= $windSpeed)
                            : ($windSpeedChange1h < 0.0 && $segmentSpeed > $windSpeed && $segmentSpeed <= min(60.0, $windSpeed - $windSpeedChange1h));
                        $segmentClass = $segmentChange
                            ? ($windSpeedChange1h > 0.0 ? 'active change' : 'change')
                            : ($segmentActive ? 'active' : '');
                    ?>
                        <line class="<?= $segmentClass . ($segmentActive && $windSpeed > 30.0 && !$segmentChange ? ' high-wind' : '') ?>" x1="<?= number_format($segmentX, 3, '.', '') ?>" y1="2" x2="<?= number_format($segmentX, 3, '.', '') ?>" y2="11"></line>
                    <?php endfor; ?>
                    </g>
                    <g class="wind-speed-horizontal-labels">
                        <text x="10" y="27">0</text><text x="85" y="27">15</text><text x="160" y="27">30</text><text x="235" y="27">45</text><text x="310" y="27">60</text>
                    </g>
                </svg>
            </div>
            <p class="wind-hud-help">The orange dot marks where wind comes from; the arrow points where it is going. Use the phone compass to keep the direction aligned as you turn your phone.</p>
            <div class="wind-compass-controls">
                <span class="wind-compass-status" id="windCompassStatus" hidden></span>
                <button type="button" class="secondary wind-compass-button" id="windCompassButton">Use phone compass</button>
            </div>
        </article>




    <a class="card satellite-map-card" href="https://maps.open-meteo.com/?variable=temperature_2m&amp;lat=<?= rawurlencode((string)($config['pressure']['latitude'] ?? -33.0153)) ?>&amp;lon=<?= rawurlencode((string)($config['pressure']['longitude'] ?? 27.9116)) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open interactive Open-Meteo weather map in a new tab">
        <div class="satellite-map-card-main">
            <div class="satellite-map-icon" aria-hidden="true">
                <svg viewBox="0 0 48 48" focusable="false">
                    <circle cx="24" cy="24" r="8"></circle>
                    <path d="M18 18 10 10M30 18l8-8M18 30l-8 8M30 30l8 8"></path>
                    <path d="M7 7l7 1-1 7M41 7l-7-1 1 7M7 41l7-1-1-7M41 41l-7-1 1-7"></path>
                    <path d="M21 21h6v6h-6z"></path>
                </svg>
            </div>
            <div class="satellite-map-copy">
                <h2 id="interactiveWeatherMapsTitle">Interactive Weather Maps</h2>
                <p class="muted">Explore temperature, wind, precipitation and cloud-cover layers in Open-Meteo.</p>
            </div>
            <span class="satellite-map-arrow" aria-hidden="true">↗</span>
        </div>
        <div class="satellite-map-card-footer">
            <span>Open interactive weather map</span>
            <span class="satellite-map-external">Open-Meteo ↗</span>
        </div>
    </a>
    </section>





    <section class="card weather-change-card" aria-labelledby="weatherChangeTitle">
        <div class="section-heading">
            <div>
                <h2 id="weatherChangeTitle">Weather Volatility Index (WVI)</h2>
                <p class="muted">Interprets the degree and speed of changing conditions.</p>
            </div>
            <div class="weather-change-score" id="weatherChangeScore" aria-label="Weather Volatility Index score">—</div>
        </div>
        <div class="weather-change-summary" id="weatherChangeSummary">Analysing recent conditions…</div>
        <button type="button" class="secondary weather-change-reasons-toggle info-icon-button" id="weatherChangeReasonsToggle" aria-label="Show Weather Volatility Index reasons" title="More information" aria-controls="weatherChangeReasons" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 10.5v6"></path>
                <circle cx="12" cy="7.5" r=".8" class="info-icon-dot"></circle>
            </svg>
            <span id="weatherChangeReasonsButtonLabel">More information</span>
        </button>
        <div class="weather-change-reasons" id="weatherChangeReasons" aria-live="polite" hidden></div>
        <details class="algorithm-details">
            <summary>How Weather Volatility Index (WVI) is calculated</summary>
            <p>GaugeIQ compares the current six-hour change with up to <?= (int)$weatherFormulaValues['baseline_days'] ?> previous comparable six-hour windows. This is a short-term local pattern, not a seasonal climate normal.</p>
        </details>
    </section>

    <section class="card conditions-trend-card" aria-labelledby="conditionsTrendTitle">
        <div class="section-heading">
            <div>
                <h2 id="conditionsTrendTitle">Conditions trend</h2>
                <p class="muted">Interprets the change in weather conditions.</p>
            </div>
            <div class="conditions-trend-score" id="conditionsTrendIcon" aria-hidden="true">→</div>
        </div>
        <div class="conditions-trend-summary" id="conditionsTrendSummary">Analysing recent conditions…</div>
        <button type="button" class="secondary weather-change-reasons-toggle info-icon-button" id="conditionsTrendReasonsToggle" aria-label="Show conditions trend reasons" title="More information" aria-controls="conditionsTrendReasons" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 10.5v6"></path>
                <circle cx="12" cy="7.5" r=".8" class="info-icon-dot"></circle>
            </svg>
            <span id="conditionsTrendReasonsButtonLabel">More information</span>
        </button>
        <div class="conditions-trend-reasons" id="conditionsTrendReasons" aria-live="polite" hidden></div>
        <details class="algorithm-details">
            <summary>How the conditions trend is classified</summary>
            <p>This is a separate directional assessment, not the Weather Volatility Index (WVI).</p>
            <p>Falling pressure, developing rain, strengthening wind, increasing cloud cover and more severe weather codes support a Worsening classification. The opposite signals can support Improving. The combined directional score must reach ±2 to classify a trend; otherwise the result is Steady/no clear trend.</p>
        </details>
    </section>

    <section class="card history-card" data-history-timezone="<?= htmlspecialchars($dashboardTimezone, ENT_QUOTES) ?>">
        <div class="section-heading">
            <div>
                <h2 id="historyTitle">Last 24 hours</h2>
                <p id="historyStatus" class="muted">Loading history…</p>
            </div>
        </div>
        <div class="history-range" role="group" aria-label="History range">
            <button type="button" class="history-range-button" data-hours="6">6h</button>
            <button type="button" class="history-range-button active" data-hours="24">24h</button>
            <button type="button" class="history-range-button" data-hours="48">48h</button>
            <button type="button" class="history-range-button" data-hours="168">7d</button>
            <button type="button" class="history-range-button" data-hours="720">30d</button>
        </div>
        <div class="chart-block"><h3>Pressure</h3><canvas id="pressureChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Humidity</h3><canvas id="humidityChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Wind speed</h3><canvas id="windChart" height="220"></canvas></div>
    </section>





    <section class="card alert-history-card" aria-labelledby="alertHistoryTitle">
        <div class="section-heading">
            <div><h2 id="alertHistoryTitle">Alert history</h2><p class="muted">Recent conditions that triggered your saved rules.</p></div>
            <a class="history-link" href="login.php">Manage alerts in Admin</a>
        </div>
        <?php if (!$recentAlerts): ?>
            <p class="muted empty-history">No alerts have been triggered yet.</p>
        <?php else: ?>
            <div class="alert-history-list">
                <?php foreach ($recentAlerts as $alert): ?>
                    <article class="alert-history-item">
                        <div class="alert-history-icon">!</div>
                        <div class="alert-history-content">
                            <strong><?= htmlspecialchars((string)($alert['name'] ?: 'GaugeIQ alert'), ENT_QUOTES) ?></strong>
                            <p><?= htmlspecialchars((string)$alert['message'], ENT_QUOTES) ?></p>
                            <time datetime="<?= htmlspecialchars((string)$alert['observed_at'], ENT_QUOTES) ?>"><?= htmlspecialchars(date('d M, H:i', (int)strtotime((string)$alert['observed_at'])), ENT_QUOTES) ?></time>
                            <form method="post" action="delete-alert-history.php" onsubmit="return confirm('Delete this alert history record?')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"><input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <button type="submit" class="alert-history-delete" aria-label="Delete record" title="Delete record"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-3 6h12l-.8 11.2a2 2 0 0 1-2 1.8H8.8a2 2 0 0 1-2-1.8L6 9Zm4 2v8h2v-8h-2Zm4 0v8h2v-8h-2Z"/></svg></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    

    <p id="status" class="status"></p>
    <p id="notificationEnabledNote" class="notification-enabled-note" hidden>GaugeIQ notifications are enabled for this device.</p>
    <section class="card network-diagnostics" aria-labelledby="networkDiagnosticsTitle">
        <div class="section-heading">
            <div><p class="label" id="networkDiagnosticsTitle">Network diagnostics</p></div>
        </div>
        <div class="network-diagnostics-readings">
            <p id="networkUplinkStatus" class="network-uplink-status" aria-live="polite">Upload speed: Measuring…</p>
            <p id="networkDownlinkStatus" class="network-uplink-status" aria-live="polite">Download speed: Measuring…</p>
            <p id="networkPingStatus" class="network-uplink-status" aria-live="polite">Ping to weather server: Measuring…</p>
            <p class="network-uplink-status">Weather server IP: <strong><?= htmlspecialchars((string)($_SERVER['SERVER_ADDR'] ?? 'Unavailable'), ENT_QUOTES, 'UTF-8') ?></strong></p>
        </div>
    </section>
</main>
<?php
$historyTimezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
$historyAutoStart = (new DateTimeImmutable('now', $historyTimezone))->modify('-1 year')->format('Y-m-d');
$historyAutoEnd = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyWeatherCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE source = ? AND observed_at >= ? AND observed_at <= ?");
$historyWeatherCountStmt->execute(['Open-Meteo Historical Weather', $historyAutoStart . 'T00:00', $historyAutoEnd . 'T23:59']);
$historyWeatherCount = (int)$historyWeatherCountStmt->fetchColumn();
$historyWeatherLatestStmt = $pdo->prepare("SELECT MAX(observed_at) FROM gaugeiq_pressure_readings WHERE source = ?");
$historyWeatherLatestStmt->execute(['Open-Meteo Historical Weather']);
$historyWeatherLatest = (string)($historyWeatherLatestStmt->fetchColumn() ?: '');
$historyForecastCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gaugeiq_rain_forecasts WHERE source = ? AND forecast_at >= ? AND forecast_at <= ?");
$historyForecastCountStmt->execute(['Open-Meteo Historical Forecast', $historyAutoStart . 'T00:00', $historyAutoEnd . 'T23:59']);
$historyForecastCount = (int)$historyForecastCountStmt->fetchColumn();
$historyForecastLatestStmt = $pdo->prepare("SELECT MAX(forecast_at) FROM gaugeiq_rain_forecasts WHERE source = ?");
$historyForecastLatestStmt->execute(['Open-Meteo Historical Forecast']);
$historyForecastLatest = (string)($historyForecastLatestStmt->fetchColumn() ?: '');
?>
<div id="historicalDatasetStatus"
     hidden
     data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"
     data-start="<?= htmlspecialchars($historyAutoStart, ENT_QUOTES) ?>"
     data-end="<?= htmlspecialchars($historyAutoEnd, ENT_QUOTES) ?>"
     data-weather-count="<?= $historyWeatherCount ?>"
     data-forecast-count="<?= $historyForecastCount ?>"
     data-weather-latest="<?= htmlspecialchars($historyWeatherLatest, ENT_QUOTES) ?>"
     data-forecast-latest="<?= htmlspecialchars($historyForecastLatest, ENT_QUOTES) ?>"
     data-latitude="<?= htmlspecialchars($dashboardLatitude, ENT_QUOTES) ?>"
     data-longitude="<?= htmlspecialchars($dashboardLongitude, ENT_QUOTES) ?>"></div>
<script src="js/local-weather.js?v=20261009-location-refresh"></script>
<script src="js/openmeteo-history.js?v=20261011-history-backfill-3"></script>
<script src="js/loading-screen.js?v=20261029-loading-screen-restored"></script>
<script src="js/app.js?v=20261029-loading-screen-restored"></script>
</body>
</html>
