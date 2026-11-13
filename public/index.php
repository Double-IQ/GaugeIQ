<?php
declare(strict_types=1);

// HTML must be revalidated so installed PWAs receive current asset references.
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

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
$pdo = $db->pdo();
migrateDatabase($pdo);
$secondLocationEnabled = false;
$secondLocationFlag = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = 'second_location_enabled' LIMIT 1");
$secondLocationFlag->execute();
$secondLocationEnabled = $secondLocationFlag->fetchColumn() === '1';
$locationId = (int)($_GET['location'] ?? 1);
if (!in_array($locationId, [1, 2], true) || ($locationId === 2 && !$secondLocationEnabled)) {
    $locationId = 1;
}
$locationStatusSuffix = $locationId === 2 ? '_location_2' : '';
$service = new PressureService($config, $pdo, $locationId);
function gaugeIqDashboardSetting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

$weatherFormulaValues = weatherFormulaValues($pdo);
$weatherFormulaJson = json_encode($weatherFormulaValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$locationName = (string)$config['pressure']['location_name'];
$dashboardLatitude = (string)($config['pressure']['latitude'] ?? '');
$dashboardLongitude = (string)($config['pressure']['longitude'] ?? '');
$dashboardTimezone = (string)($config['app']['timezone'] ?? 'UTC');
$coordinateSettings = $pdo->prepare("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('active_location_latitude', 'active_location_longitude', 'active_location_name', 'active_location_timezone', 'location_latitude', 'location_longitude', 'location_name', 'second_location_latitude', 'second_location_longitude', 'second_location_name')");
$coordinateSettings->execute();
$storedCoordinates = [];
foreach ($coordinateSettings->fetchAll() as $coordinateSetting) {
    $storedCoordinates[(string)$coordinateSetting['key']] = (string)$coordinateSetting['value'];
}
if ($locationId === 2) {
    $dashboardLatitude = $storedCoordinates['second_location_latitude'] ?? '';
    $dashboardLongitude = $storedCoordinates['second_location_longitude'] ?? '';
    $locationName = $storedCoordinates['second_location_name'] ?? 'Location 2';
    $dashboardTimezone = gaugeIqDashboardSetting($pdo, 'location_timezone_location_2') ?? $dashboardTimezone;
    try { new DateTimeZone($dashboardTimezone); } catch (Throwable) { $dashboardTimezone = (string)($config['app']['timezone'] ?? 'UTC'); }
} else {
    $dashboardLatitude = $storedCoordinates['active_location_latitude']
        ?? $storedCoordinates['location_latitude']
        ?? $dashboardLatitude;
    $dashboardLongitude = $storedCoordinates['active_location_longitude']
        ?? $storedCoordinates['location_longitude']
        ?? $dashboardLongitude;
    $locationName = $storedCoordinates['active_location_name']
        ?? $storedCoordinates['location_name']
        ?? $locationName;
    $dashboardTimezone = gaugeIqDashboardSetting($pdo, 'location_timezone')
        ?? $storedCoordinates['active_location_timezone']
        ?? $dashboardTimezone;
    try { new DateTimeZone($dashboardTimezone); } catch (Throwable) { $dashboardTimezone = (string)($config['app']['timezone'] ?? 'UTC'); }
}
$dashboardLatitudeFloat = is_numeric($dashboardLatitude) ? (float)$dashboardLatitude : 0.0;
$dashboardLongitudeFloat = is_numeric($dashboardLongitude) ? (float)$dashboardLongitude : 0.0;
date_default_timezone_set($dashboardTimezone);
$activeLocationChangedAt = '';

try {
    $changedAtKey = $locationId === 2 ? 'second_location_changed_at' : 'active_location_changed_at';
    $changedAtStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $changedAtStmt->execute([$changedAtKey]);
    $changedAtValue = $changedAtStmt->fetchColumn();
    $activeLocationChangedAt = is_string($changedAtValue) ? $changedAtValue : '';
    $latestSql = "SELECT * FROM gaugeiq_pressure_readings
                  WHERE location_id = ? AND (source IS NULL OR source = 'Open-Meteo')";
    $latestParams = [$locationId];
    if ($activeLocationChangedAt !== '') {
        $latestSql .= ' AND created_at >= ?';
        $latestParams[] = $activeLocationChangedAt;
    }
    $latestSql .= ' ORDER BY id DESC LIMIT 1';
    $latestStmt = $pdo->prepare($latestSql);
    $latestStmt->execute($latestParams);
    $latest = $latestStmt->fetch() ?: null;

    // Use recorded pressure extrema from the latest 30 days, anchored to the
    // newest observation and scoped to the active location.
    $pressureRangeEnd = $latest && !empty($latest['observed_at'])
        ? strtotime((string)$latest['observed_at'])
        : time();
    if ($pressureRangeEnd === false) {
        $pressureRangeEnd = time();
    }
    $pressureRangeStartText = date('Y-m-d\\TH:i:s', $pressureRangeEnd - (30 * 86400));
    $pressureRangeEndText = date('Y-m-d\\TH:i:s', $pressureRangeEnd);
    $pressureRangeSql = "SELECT MIN(pressure_hpa) AS pressure_low, MAX(pressure_hpa) AS pressure_high
                         FROM gaugeiq_pressure_readings
                         WHERE location_id = ? AND observed_at >= ? AND observed_at <= ?
                           AND (source IS NULL OR source IN ('Open-Meteo', 'Open-Meteo Historical Weather'))";
    $pressureRangeParams = [$locationId, $pressureRangeStartText, $pressureRangeEndText];
    if ($activeLocationChangedAt !== '') {
        $pressureRangeSql .= ' AND created_at >= ?';
        $pressureRangeParams[] = $activeLocationChangedAt;
    }
    $pressureRangeStmt = $pdo->prepare($pressureRangeSql);
    $pressureRangeStmt->execute($pressureRangeParams);
    $pressureHistory = $pressureRangeStmt->fetch() ?: [];

    // Dashboard rendering is read-only: display the latest stored reading.
    // New observations are collected by the scheduled server-side monitor, not on app open.

    $current = $latest;
    if ($current) {
        $decodedForecast = json_decode((string)($current['forecast_json'] ?? '[]'), true);
        $current['forecast'] = is_array($decodedForecast) ? $decodedForecast : [];
    }
    // Show the pressure change over approximately one hour, using only
    // readings stored by the hourly Open-Meteo cron. The ±30-minute window
    // accommodates the hourly collection cadence without fetching on open.
    $change = 0.0;
    if ($latest && !empty($latest['observed_at'])) {
        $latestTimestamp = strtotime((string)$latest['observed_at']);
        if ($latestTimestamp !== false) {
            $targetTimestamp = $latestTimestamp - 3600;
            $windowStart = date('Y-m-d\\TH:i:s', $targetTimestamp - 1800);
            $windowEnd = date('Y-m-d\\TH:i:s', $targetTimestamp + 1800);
            $pressurePriorSql = "SELECT pressure_hpa, observed_at
                                 FROM gaugeiq_pressure_readings
                                 WHERE location_id = ? AND observed_at >= ? AND observed_at <= ?
                                   AND (source IS NULL OR source = 'Open-Meteo')";
            $pressurePriorParams = [$locationId, $windowStart, $windowEnd];
            if ($activeLocationChangedAt !== '') {
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
    $monitorSuccessKey = 'monitor_last_success_at' . $locationStatusSuffix;
    $monitorErrorKey = 'monitor_last_error' . $locationStatusSuffix;
    $lastMonitorAt = gaugeIqDashboardSetting($pdo, $monitorSuccessKey);
    $monitorError = gaugeIqDashboardSetting($pdo, $monitorErrorKey);
    $subscriptionCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_push_subscriptions')->fetchColumn();
    $enabledRuleStmt = $pdo->prepare('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE location_id = ? AND enabled = 1');
    $enabledRuleStmt->execute([$locationId]);
    $enabledRuleCount = (int)$enabledRuleStmt->fetchColumn();
    $stabilityThresholdStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stabilityThresholdStmt->execute(['stability_alert_threshold' . $locationStatusSuffix]);
    $stabilityThresholdValue = $stabilityThresholdStmt->fetchColumn();
    $weatherStabilityAlertThreshold = ($stabilityThresholdValue !== false && is_numeric($stabilityThresholdValue)
        && (float)$stabilityThresholdValue >= 0 && (float)$stabilityThresholdValue <= 10)
        ? 10.0 - (float)$stabilityThresholdValue
        : null;
} catch (Throwable $e) {
    $current = null;
    $latest = null;
    $change = 0.0;
    $error = $e->getMessage();
    $lastMonitorAt = null;
    $monitorError = '';
    $subscriptionCount = 0;
    $enabledRuleCount = 0;
}

$checkMinutes = max(1, (int)$config['pressure']['check_interval_minutes']);
// The cPanel background monitor is scheduled hourly.
$monitorCronMinutes = 60;
$nextMonitorAt = $lastMonitorAt ? strtotime($lastMonitorAt) + ($monitorCronMinutes * 60) : null;
$monitorAge = $lastMonitorAt ? time() - (int)strtotime($lastMonitorAt) : null;
$monitorHealthy = $monitorAge !== null && $monitorAge <= ($monitorCronMinutes * 60 * 1.5);
$cronScript = realpath(__DIR__ . '/../cron/check-pressure.php') ?: (__DIR__ . '/../cron/check-pressure.php');
$cronCommand = '/usr/local/bin/php -q ' . escapeshellarg($cronScript);

$weatherMapJsVersion = (string) (filemtime(__DIR__ . '/js/weather-map.js') ?: time());

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
<link rel="preconnect" href="https://unpkg.com" crossorigin>
<link rel="preconnect" href="https://tiles.openfreemap.org">
<link rel="preconnect" href="https://openmeteo.s3.amazonaws.com">
<link rel="modulepreload" href="https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.mjs" crossorigin>
<link rel="modulepreload" href="https://unpkg.com/@openmeteo/weather-map-layer@0.3.1/dist/index.mjs" crossorigin>
<link rel="modulepreload" href="js/weather-map.js?v=<?= htmlspecialchars($weatherMapJsVersion, ENT_QUOTES, 'UTF-8') ?>" crossorigin>
<link rel="stylesheet" href="css/app.css?v=20261113-radar-rain-footprint">
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.css">
<script type="module" src="js/weather-map.js?v=<?= htmlspecialchars($weatherMapJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<title>GaugeIQ</title>
</head>
<body data-weather-formulas="<?= htmlspecialchars((string)$weatherFormulaJson, ENT_QUOTES, 'UTF-8') ?>" data-monitor-last-success-at="<?= htmlspecialchars((string)($lastMonitorAt ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-location-id="<?= $locationId ?>">
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
    <?php if ($secondLocationEnabled): ?>
    <nav class="location-switcher" aria-label="Monitoring location">
        <a class="secondary button-link<?= $locationId === 1 ? ' active' : '' ?>" href="?location=1" <?= $locationId === 1 ? 'aria-current="page"' : '' ?>><?= htmlspecialchars((string)($storedCoordinates['location_name'] ?? $config['pressure']['location_name'] ?? 'Location 1'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="secondary button-link<?= $locationId === 2 ? ' active' : '' ?>" href="?location=2" <?= $locationId === 2 ? 'aria-current="page"' : '' ?>><?= htmlspecialchars((string)($storedCoordinates['second_location_name'] ?? 'Location 2'), ENT_QUOTES, 'UTF-8') ?></a>
    </nav>
    <?php endif; ?>

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
    // The hourly monitor stores one sample per hour; keep the latest sample
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
                     WHERE location_id = ? AND observed_at > ? AND observed_at <= ?
                       AND (source IS NULL OR source = 'Open-Meteo')" .
                       ($activeLocationChangedAt !== '' ? " AND created_at >= ?" : "") .
                     " ORDER BY observed_at ASC, id ASC"
                );
                $rainfallParams = [$locationId, $rainfallWindowStart, $rainfallWindowEnd];
                if ($activeLocationChangedAt !== '') $rainfallParams[] = $activeLocationChangedAt;
                $rainfall24hStmt->execute($rainfallParams);
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

    $rainfallNowcast = null;
    $nowcastIsStale = true;
    try {
        $nowcastStmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
        $nowcastStmt->execute([$locationId === 2 ? 'weather_map_rainfall_nowcast_location_2' : 'weather_map_rainfall_nowcast']);
        $nowcastJson = $nowcastStmt->fetchColumn();
        if (is_string($nowcastJson) && $nowcastJson !== '') {
            $decodedNowcast = json_decode($nowcastJson, true);
            $updatedTimestamp = is_array($decodedNowcast) ? strtotime((string)($decodedNowcast['updated_at'] ?? '')) : false;
            if (is_array($decodedNowcast) && $updatedTimestamp !== false && $updatedTimestamp >= time() - 3 * 3600) {
                // Use only fresh model data for plotted echoes and rainfall amounts.
                $rainfallNowcast = $decodedNowcast;
                $nowcastIsStale = false;
            }
        }
    } catch (Throwable $nowcastReadError) {
        // Keep the radar panel visible even when its scheduled payload cannot be read.
    }
    if (!is_array($rainfallNowcast)) {
        // Do not hide the entire card while the hourly cron catches up. Show an
        // empty radar shell instead of presenting expired forecast data as current.
        $rainfallNowcast = [
            'status' => 'waiting',
            'frames' => [],
            'updated_at' => null,
            'schema_version' => 2,
            'rain_footprint_trend' => 'not_assessed',
            'forecast_consistency' => 'no_signal',
        ];
    }

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
    $dewRatio = $current ? max(0.0, min(1.0, $dewPointC / 25.0)) : 0.0;
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
                    <div class="top-status-indicators" aria-label="Notification, weather alert and scheduled data update status">
                        <button id="topNotificationStatus" class="top-status-indicator top-notification-button is-off" type="button" aria-label="Notifications not enabled on this device" title="Notifications not enabled on this device" aria-haspopup="dialog">
                            <svg class="top-status-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="top-status-dash"></span>
                        </button>
                        <span class="top-status-indicator <?= $enabledRuleCount > 0 ? 'is-on' : 'is-off' ?>" role="img" aria-label="Alerts <?= $enabledRuleCount > 0 ? 'enabled' : 'disabled' ?>" title="Alerts <?= $enabledRuleCount > 0 ? 'enabled' : 'disabled' ?>">
                            <svg class="top-status-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg><span class="top-status-dash"></span>
                        </span>
                        <span class="top-status-indicator <?= $lastMonitorAt === null ? 'is-off' : ($monitorHealthy ? 'is-on' : 'is-error') ?>" role="img" aria-label="Scheduled data update <?= $lastMonitorAt === null ? 'status unavailable' : ($monitorHealthy ? 'running normally' : 'overdue or failing') ?>" title="Scheduled data update: <?= $lastMonitorAt === null ? 'no successful run recorded' : ($monitorHealthy ? 'last successful run ' . date('d M, H:i', (int)strtotime($lastMonitorAt)) : 'last successful run ' . date('d M, H:i', (int)strtotime($lastMonitorAt)) . ($monitorError !== '' ? ' · latest run reported an error' : ' · overdue')) ?>">
                            <svg class="top-status-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5"/><path d="M4.9 19a9 9 0 0 0 14.2-2M4 17v-5h5"/><path d="M19.1 5A9 9 0 0 0 4.9 7"/></svg><span class="top-status-dash"></span>
                        </span>
                    </div>
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


    <?php if (is_array($rainfallNowcast)): ?>
        <?php
        $nowcastStatus = (string)($rainfallNowcast['status'] ?? 'clear');
        $nowcastFrames = is_array($rainfallNowcast['frames'] ?? null) ? array_values(array_filter($rainfallNowcast['frames'], 'is_array')) : [];
        $nowcastUpdatedAt = strtotime((string)($rainfallNowcast['updated_at'] ?? ''));
        $radarFrame = $nowcastFrames[0] ?? [];
        $radarCells = is_array($radarFrame['rain_cells'] ?? null) ? $radarFrame['rain_cells'] : [];
        $radarNearestKm = is_numeric($radarFrame['nearest_rain_km'] ?? null) ? (float)$radarFrame['nearest_rain_km'] : null;
        $radarNearestAmount = is_numeric($radarFrame['nearest_rain_precipitation_mm_per_hour'] ?? $radarFrame['nearest_rain_precipitation_mm'] ?? null) ? (float)($radarFrame['nearest_rain_precipitation_mm_per_hour'] ?? $radarFrame['nearest_rain_precipitation_mm']) : null;
        $radarLocalAmount = !$nowcastIsStale && is_numeric($radarFrame['local_precipitation_mm_per_hour'] ?? $radarFrame['local_precipitation_mm'] ?? null) ? max(0.0, (float)($radarFrame['local_precipitation_mm_per_hour'] ?? $radarFrame['local_precipitation_mm'])) : null;
        $rainfallDisplayUnit = (int)($rainfallNowcast['schema_version'] ?? 1) >= 2 ? 'mm/h' : 'mm';
        $radarTrend = (string)($rainfallNowcast['rain_footprint_trend'] ?? 'not_assessed');
        $radarTrendLabel = match ($radarTrend) {
            'closing' => 'Moving closer',
            'receding' => 'Moving away',
            'steady' => 'Staying roughly the same',
            default => 'Movement unavailable',
        };
        $consistency = (string)($rainfallNowcast['forecast_consistency'] ?? 'no_signal');
        $consistencyLabel = match ($consistency) {
            'persistent' => 'Persistent',
            'developing' => 'Developing',
            'fading' => 'Fading',
            default => 'No consistent rain signal',
        };
        $radarCellMarkup = '';
        $radarCentreLatitude = $dashboardLatitudeFloat;
        $radarCentreLongitude = $dashboardLongitudeFloat;
        $radarLongitudeScale = max(0.08, cos(deg2rad($radarCentreLatitude)));
        foreach ($radarCells as $radarCell) {
            if (!is_array($radarCell) ||
                !is_numeric($radarCell['latitude'] ?? null) ||
                !is_numeric($radarCell['longitude'] ?? null)) {
                continue;
            }
            $amount = is_numeric($radarCell['precipitation_mm_per_hour'] ?? $radarCell['precipitation_mm'] ?? null)
                ? max(0.0, (float)($radarCell['precipitation_mm_per_hour'] ?? $radarCell['precipitation_mm']))
                : 0.0;
            if ($amount < 0.1) continue;
            $cellColour = $amount >= 5.0 ? '#E7C84B' : ($amount >= 1.0 ? '#45D69D' : '#36B9D6');

            // Project real ECMWF grid-cell centres into a local north-up radar plane.
            $deltaLongitude = fmod((float)$radarCell['longitude'] - $radarCentreLongitude + 540.0, 360.0) - 180.0;
            $eastKm = $deltaLongitude * 111.32 * $radarLongitudeScale;
            $northKm = ((float)$radarCell['latitude'] - $radarCentreLatitude) * 110.574;
            $cellX = 160.0 + ($eastKm * 1.36);
            $cellY = 160.0 - ($northKm * 1.36);
            $cellOpacity = min(0.88, 0.48 + (log1p($amount) * 0.12));
            $cellTitle = number_format($amount, 2) . ' ' . $rainfallDisplayUnit .
                ' · ' . number_format((float)($radarCell['distance_km'] ?? 0.0), 1) . ' km';
            $radarCellMarkup .= '<ellipse class="rainfall-radar-cell rainfall-radar-grid-cell" cx="' .
                number_format($cellX, 2, '.', '') . '" cy="' . number_format($cellY, 2, '.', '') .
                '" rx="20.5" ry="19.5" fill="' . $cellColour . '" fill-opacity="' .
                number_format($cellOpacity, 2, '.', '') . '" data-rainfall="' .
                number_format($amount, 2, '.', '') . '"><title>' .
                htmlspecialchars($cellTitle, ENT_QUOTES, 'UTF-8') . '</title></ellipse>';
        }
        $frameLeadLabels = ['+3h', '+6h'];
        ?>
        <article class="card wind-hud-card rainfall-nowcast-card rainfall-radar-card" data-status="<?= htmlspecialchars($nowcastStatus, ENT_QUOTES) ?>">
            <div class="wind-hud-heading rainfall-nowcast-heading">
                <div>
                    <p class="label">ECMWF IFS · Next 6 hours</p>
                    <h2>Rainfall outlook</h2>
                </div>
                <div class="rainfall-radar-compass-controls">
                    <button type="button" class="rainfall-radar-compass-button" id="rainfallRadarCompassButton" aria-pressed="false">Enable Compass</button>
                    <span id="rainfallRadarCompassStatus" role="status" aria-live="polite">North-up</span>
                </div>
            </div>
            <div class="rainfall-radar-layout">
                <div class="rainfall-radar-scope-wrap">
                    <div class="rainfall-radar-topline"><span>FORECAST SCAN · +3 HOURS</span><span>100 KM RANGE</span></div>
                    <svg class="rainfall-radar-scope" viewBox="0 0 320 320" role="img" aria-label="ECMWF forecast precipitation footprint around your location, rendered from the model grid">
                        <defs>
                            <radialGradient id="rainfallRadarBackground"><stop offset="0%" stop-color="#102E3B"/><stop offset="100%" stop-color="#07131D"/></radialGradient>
                            <linearGradient id="rainfallRadarSweepGradient" x1="0" y1="1" x2="1" y2="0"><stop offset="0%" stop-color="#36E0A0" stop-opacity="0.01"/><stop offset="100%" stop-color="#36E0A0" stop-opacity="0.34"/></linearGradient>
                            <clipPath id="rainfallRadarClip"><circle cx="160" cy="160" r="140"/></clipPath>
                            <filter id="rainfallRadarGlow"><feGaussianBlur stdDeviation="2.5" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
                            <filter id="rainfallRadarRainSoftness" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="3.2"/></filter>
                        </defs>
                        <circle cx="160" cy="160" r="143" fill="#0A202B" stroke="#315263" stroke-width="1.5"/>
                        <g clip-path="url(#rainfallRadarClip)">
                            <circle cx="160" cy="160" r="140" fill="url(#rainfallRadarBackground)"/>
                            <circle cx="160" cy="160" r="21" fill="none" stroke="#376071" stroke-dasharray="3 4"/>
                            <circle cx="160" cy="160" r="70" fill="none" stroke="#376071" stroke-dasharray="3 4"/>
                            <circle cx="160" cy="160" r="140" fill="none" stroke="#376071" stroke-dasharray="3 4"/>
                            <path d="M20 160H300 M160 20V300 M61 61L259 259 M259 61L61 259" stroke="#376071" stroke-opacity=".42" stroke-width="1"/>
                            <g id="rainfallRadarEchoes" class="rainfall-radar-cells" transform="rotate(0 160 160)" filter="url(#rainfallRadarRainSoftness)"><?= $radarCellMarkup ?></g>
                            <g class="rainfall-radar-sweep">
                                <path d="M160 160 L160 20 A140 140 0 0 1 280 88 Z" fill="url(#rainfallRadarSweepGradient)"/>
                                <path d="M160 160L280 88" stroke="#51E6B0" stroke-width="1.8" filter="url(#rainfallRadarGlow)"/>
                            </g>
                            <circle cx="160" cy="160" r="5" fill="#F4FBFF" stroke="#47D9B0" stroke-width="3" filter="url(#rainfallRadarGlow)"/>
                            <circle cx="160" cy="160" r="12" fill="none" stroke="#47D9B0" stroke-width="1"/>
                        </g>
                        <g class="rainfall-radar-labels" fill="#8CB3C4" font-size="9" font-family="sans-serif">
                            <text x="160" y="12" text-anchor="middle">N</text><text x="309" y="163" text-anchor="end">E</text><text x="160" y="316" text-anchor="middle">S</text><text x="11" y="163">W</text>
                            <text x="168" y="140">15 km</text><text x="168" y="94">50 km</text><text x="168" y="29">100 km</text>
                        </g>
                    </svg>
                    <div class="rainfall-radar-legend">
                        <span><i class="rainfall-legend-low"></i>Lower</span><span><i class="rainfall-legend-mid"></i>Moderate</span><span><i class="rainfall-legend-high"></i>Higher</span>
                    </div>
                    <?php if ($radarCells === []): ?>
                        <p class="rainfall-radar-empty"><?= $nowcastIsStale ? 'Waiting for the next scheduled ECMWF refresh. Expired forecast echoes are not shown.' : (!empty($radarFrame) && array_key_exists('rain_cells', $radarFrame) ? 'No wet grid cells forecast within 100 km at this step.' : (!empty($radarFrame) ? 'Spatial radar detail will appear after the next scheduled ECMWF refresh.' : 'Waiting for the next scheduled ECMWF forecast scan.')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="rainfall-radar-readouts">
                    <div class="rainfall-radar-readout-primary">
                        <span>AT YOUR LOCATION</span>
                        <strong><?= $radarLocalAmount === null ? '—' : number_format($radarLocalAmount, 1) ?><?php if ($radarLocalAmount !== null): ?> <small><?= htmlspecialchars($rainfallDisplayUnit, ENT_QUOTES) ?></small><?php endif; ?></strong>
                    </div>
                    <div class="rainfall-radar-readout-grid">
                        <div><span>NEAREST RAIN</span><strong><?= $radarNearestKm === null ? '—' : number_format($radarNearestKm, 0) . ' km' ?></strong><?php if ($radarNearestAmount !== null): ?><small><?= number_format($radarNearestAmount, 2) ?> <?= htmlspecialchars($rainfallDisplayUnit, ENT_QUOTES) ?> at cell</small><?php endif; ?></div>
                        <div><span>RAIN MOVEMENT</span><strong><?= htmlspecialchars($radarTrendLabel, ENT_QUOTES) ?></strong></div>
                    </div>
                </div>
            </div>
            <div class="rainfall-radar-timeline">
                <div class="rainfall-radar-section-label"><strong>FORECAST SCAN</strong><span>LOCAL TIME</span></div>
                <?php if ($nowcastIsStale): ?><p class="rainfall-radar-empty">Forecast steps will appear after the next scheduled update.</p><?php endif; ?>
                <?php foreach ($nowcastFrames as $frameIndex => $nowcastFrame):
                    $frameTimestamp = strtotime((string)($nowcastFrame['time'] ?? ''));
                    if ($frameTimestamp === false) continue;
                    $localAmount = is_numeric($nowcastFrame['local_precipitation_mm_per_hour'] ?? $nowcastFrame['local_precipitation_mm'] ?? null) ? max(0.0, (float)($nowcastFrame['local_precipitation_mm_per_hour'] ?? $nowcastFrame['local_precipitation_mm'])) : 0.0;
                    $barWidth = min(100.0, ($localAmount / 10.0) * 100.0);
                    $leadLabel = $frameLeadLabels[$frameIndex] ?? ('+' . (3 * ($frameIndex + 1)) . 'h');
                ?>
                    <div class="rainfall-radar-time-step">
                        <div class="rainfall-radar-time-heading"><strong><?= htmlspecialchars($leadLabel, ENT_QUOTES) ?></strong><span><?= htmlspecialchars((new DateTimeImmutable('@' . $frameTimestamp))->setTimezone(new DateTimeZone($dashboardTimezone))->format('H:i'), ENT_QUOTES) ?></span></div>
                        <div class="rainfall-radar-bar"><span style="width:<?= number_format($barWidth, 1, '.', '') ?>%"></span></div>
                        <div class="rainfall-radar-amount"><strong><?= number_format($localAmount, 2) ?> <?= htmlspecialchars($rainfallDisplayUnit, ENT_QUOTES) ?></strong><small>At your location</small></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="rainfall-radar-consistency">
                <span class="rainfall-radar-consistency-dot" data-consistency="<?= htmlspecialchars($consistency, ENT_QUOTES) ?>"></span>
                <div><strong>Forecast consistency · <?= htmlspecialchars($consistencyLabel, ENT_QUOTES) ?></strong><small>How the rain signal changes between the two model steps — not a probability.</small></div>
            </div>
            <p class="rainfall-nowcast-footnote">
                <?= $nowcastIsStale ? 'Awaiting scheduled refresh · ' : ($nowcastUpdatedAt !== false ? 'Analysis updated ' . htmlspecialchars((new DateTimeImmutable('@' . $nowcastUpdatedAt))->setTimezone(new DateTimeZone($dashboardTimezone))->format('H:i'), ENT_QUOTES) . ' · ' : '') ?>
                ECMWF model forecast, not live radar · 25 km grid
            </p>
        </article>
    <?php endif; ?>
    <a class="card forecast-preview-card" href="forecast.php?location=<?= $locationId ?>" aria-label="Open the detailed seven-day weather forecast">
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
                $climatePriorSql = "SELECT temperature_c, dew_point_c, humidity_percent, cloud_cover_percent, rainfall_mm, observed_at
                                    FROM gaugeiq_pressure_readings
                                    WHERE location_id = ? AND observed_at >= ? AND observed_at <= ?
                                      AND (source IS NULL OR source = 'Open-Meteo')";
                $climatePriorParams = [$locationId, $windowStart, $windowEnd];
                if ($activeLocationChangedAt !== '') {
                    $climatePriorSql .= ' AND created_at >= ?';
                    $climatePriorParams[] = $activeLocationChangedAt;
                }
                $climatePriorSql .= ' ORDER BY observed_at ASC, id ASC';
                $climatePriorStmt = $pdo->prepare($climatePriorSql);
                $climatePriorStmt->execute($climatePriorParams);
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
    $dewPointGapC = $current ? max(0.0, $temperatureC - $dewPointC) : 0.0;
    $dewPointGapDelta = $current && $climatePrior
        && is_numeric($current['temperature_c'] ?? null)
        && is_numeric($current['dew_point_c'] ?? null)
        && is_numeric($climatePrior['temperature_c'] ?? null)
        && is_numeric($climatePrior['dew_point_c'] ?? null)
        ? $dewPointGapC - max(0.0, (float)$climatePrior['temperature_c'] - (float)$climatePrior['dew_point_c'])
        : null;
    $humidityDelta = $climateDelta('humidity_percent');
    $cloudDelta = $climateDelta('cloud_cover_percent');
    $rainDelta = $climateDelta('rainfall_mm');

    $dewBarRatio = max(0.0, min(1.0, $dewPointC / 25.0));
    $dewPointGapRatio = max(0.0, min(1.0, $dewPointGapC / 5.0));
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
    $pressureRange = max(0.1, $pressureHigh - $pressureLow);
    $pressureBarRatio = $current
        ? max(0.0, min(1.0, ((float)$current['pressure_hpa'] - $pressureLow) / $pressureRange))
        : 0.0;
    $climateBars = [
        $makeClimateBar('Air pressure', $current ? (float)$current['pressure_hpa'] : 0.0, $current ? number_format((float)$current['pressure_hpa'], 1) : '—', $pressureBarRatio, 5.0, $current ? $change : null, 1) + ['kind' => 'air-pressure', 'historyKey' => 'pressure_hpa'],
        $makeClimateBar('Dew point', $dewPointC, $current ? number_format($dewPointC, 1) . '°C' : '—', $dewBarRatio, 25.0, $dewDelta, 1) + ['historyKey' => 'dew_point_c'],
        $makeClimateBar('Dew point gap', $dewPointGapC, $current ? number_format($dewPointGapC, 1) . '°C' : '—', $dewPointGapRatio, 5.0, $dewPointGapDelta, 1) + ['historyKey' => 'dew_point_gap_c'],
        $makeClimateBar('Humidity', $humidityPercent, $current ? number_format($humidityPercent, 0) . '%' : '—', $humidityBarRatio, 100.0, $humidityDelta) + ['historyKey' => 'humidity_percent'],
        $makeClimateBar('Cloud cover', $cloudCoverPercent, $current ? number_format($cloudCoverPercent, 0) . '%' : '—', $cloudBarRatio, 100.0, $cloudDelta) + ['historyKey' => 'cloud_cover_percent'],
    ];
    if ($current && ($rainfall24hMm > 0.0 || ($rainDelta !== null && abs($rainDelta) > 0.0))) {
        $climateBars[] = $makeClimateBar('Rainfall (24h)', $rainfall24hMm, number_format($rainfall24hMm, 2) . ' mm', $rainBarRatio, 10.0, $rainDelta, 2) + ['kind' => 'current-rain', 'historyKey' => 'rainfall_mm'];
    }

    // Draw compact 24-hour sparklines from readings already stored in the database.
    $climateHistory = [];
    try {
        if ($locationId > 0) {
            $climateLatestSql = "SELECT MAX(observed_at) FROM gaugeiq_pressure_readings WHERE location_id = ?";
            $climateLatestParams = [$locationId];
            if ($activeLocationChangedAt !== '') {
                $climateLatestSql .= ' AND created_at >= ?';
                $climateLatestParams[] = $activeLocationChangedAt;
            }
            $climateLatestStmt = $pdo->prepare($climateLatestSql);
            $climateLatestStmt->execute($climateLatestParams);
            $climateHistoryEnd = strtotime((string)($climateLatestStmt->fetchColumn() ?: ''));
            if ($climateHistoryEnd !== false) {
                $climateHistoryStart = date('Y-m-d\\TH:i:s', $climateHistoryEnd - 86400);
                $climateHistoryEndText = date('Y-m-d\\TH:i:s', $climateHistoryEnd);
                $climateHistorySql = "SELECT pressure_hpa, temperature_c, dew_point_c, humidity_percent, cloud_cover_percent, rainfall_mm, wind_speed_kmh, observed_at
                                      FROM gaugeiq_pressure_readings
                                      WHERE location_id = ? AND observed_at >= ? AND observed_at <= ?";
                $climateHistoryParams = [$locationId, $climateHistoryStart, $climateHistoryEndText];
                if ($activeLocationChangedAt !== '') {
                    $climateHistorySql .= ' AND created_at >= ?';
                    $climateHistoryParams[] = $activeLocationChangedAt;
                }
                $climateHistorySql .= ' ORDER BY observed_at ASC, id ASC';
                $climateHistoryStmt = $pdo->prepare($climateHistorySql);
                $climateHistoryStmt->execute($climateHistoryParams);
                $climateHistory = $climateHistoryStmt->fetchAll();
            }
        }
    } catch (Throwable $e) {
        $climateHistory = [];
    }
    foreach ($climateBars as &$climateBar) {
        $values = [];
        foreach ($climateHistory as $historyRow) {
            if ($climateBar['historyKey'] === 'dew_point_gap_c') {
                $historyValue = is_numeric($historyRow['temperature_c'] ?? null)
                    && is_numeric($historyRow['dew_point_c'] ?? null)
                    ? max(0.0, (float)$historyRow['temperature_c'] - (float)$historyRow['dew_point_c'])
                    : null;
            } else {
                $historyValue = $historyRow[$climateBar['historyKey']] ?? null;
            }
            if (is_numeric($historyValue)) {
                $values[] = (float)$historyValue;
            }
        }
        $points = [];
        $valueCount = count($values);
        if ($valueCount >= 2) {
            $fixedDewGapScale = $climateBar['historyKey'] === 'dew_point_gap_c';
            $minValue = $fixedDewGapScale ? 0.0 : min($values);
            $range = $fixedDewGapScale ? 5.0 : max(0.0001, max($values) - $minValue);
            foreach ($values as $pointIndex => $pointValue) {
                $x = $pointIndex / ($valueCount - 1) * 100;
                $normalisedValue = ($pointValue - $minValue) / $range;
                $y = 22 - (max(0.0, min(1.0, $normalisedValue)) * 18);
                $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
            }
        }
        $climateBar['sparkline'] = implode(' ', $points);
        $climateBar['sparklineArea'] = $valueCount >= 2 ? '0,23.5 ' . implode(' ', $points) . ' 100,23.5' : '';
        $climateBar['sparklineStartpoint'] = $valueCount >= 2 ? explode(',', $points[0]) : null;
        $climateBar['sparklineEndpoint'] = $valueCount >= 2 ? explode(',', end($points)) : null;
        $climateBar['historyCount'] = $valueCount;
        if ($climateBar['historyKey'] === 'dew_point_c' && $valueCount >= 2) {
            // Map the 15.5°C colour boundary into this sparkline's value scale.
            $dewPointThresholdPosition = (15.5 - $minValue) / $range;
            $climateBar['dewPointThresholdOffset'] = max(0.0, min(1.0, $dewPointThresholdPosition));
            $climateBar['dewPointAllBlue'] = max($values) < 15.5;
            $climateBar['dewPointAllOrange'] = min($values) >= 15.5;
        }
    }
    unset($climateBar);
    ?>
    <section class="card climate-gauge-card" aria-labelledby="climateGaugeTitle">
        <div class="section-heading climate-monitor-heading">
            <div>
                <p class="label" id="climateGaugeTitle">Atmospheric Monitor</p>
                <div class="wind-hud-subtitle">Environmental Telemetry</div>
            </div>
            <details class="climate-graph-selector">
                <summary aria-label="Choose Atmospheric Monitor graphs">Graphs <span aria-hidden="true">⌄</span></summary>
                <div class="climate-graph-selector-menu" aria-label="Choose graphs to display">
                    <label><input type="checkbox" data-climate-graph-option="pressure_hpa" checked> Air pressure</label>
                    <label><input type="checkbox" data-climate-graph-option="dew_point_c" checked> Dew point</label>
                    <label><input type="checkbox" data-climate-graph-option="dew_point_gap_c" checked> Dew point gap</label>
                    <label><input type="checkbox" data-climate-graph-option="humidity_percent" checked> Humidity</label>
                    <label><input type="checkbox" data-climate-graph-option="cloud_cover_percent" checked> Cloud cover</label>
                    <label><input type="checkbox" data-climate-graph-option="rainfall_mm" checked> Rainfall (24h)</label>
                    <label><input type="checkbox" data-climate-graph-option="wvi"> WVI</label>
                </div>
            </details>
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
                $gaugeRatio = min(1.0, max(0.0, (float)$bar['ratio']));
                $gaugeAngle = M_PI - (M_PI * $gaugeRatio);
                $gaugeEndX = 50 + (46 * cos($gaugeAngle));
                $gaugeEndY = 48 - (46 * sin($gaugeAngle));
                $needleEndX = 50 + (36 * cos($gaugeAngle));
                $needleEndY = 48 - (36 * sin($gaugeAngle));
                $needleBaseX = 50;
                $needleBaseY = 48;
                $needleHalfWidth = 3.9;
                $needlePerpX = $needleHalfWidth * sin($gaugeAngle);
                $needlePerpY = $needleHalfWidth * cos($gaugeAngle);
                $needlePath = 'M ' . number_format($needleBaseX + $needlePerpX, 2, '.', '') . ' ' . number_format($needleBaseY + $needlePerpY, 2, '.', '') .
                    ' L ' . number_format($needleEndX, 2, '.', '') . ' ' . number_format($needleEndY, 2, '.', '') .
                    ' L ' . number_format($needleBaseX - $needlePerpX, 2, '.', '') . ' ' . number_format($needleBaseY - $needlePerpY, 2, '.', '') . ' Z';
                $mobileGaugePath = 'M 4 48 A 46 46 0 0 1 ' . number_format($gaugeEndX, 2, '.', '') . ' ' . number_format($gaugeEndY, 2, '.', '');
                $deltaClass = $bar['delta'] === null ? 'unavailable' : ($bar['delta'] > 0 ? 'rise' : ($bar['delta'] < 0 ? 'fall' : 'steady'));
                // A pressure drop of 0.8 hPa or more over the last hour is the
                // threshold for switching the pressure graph and change indicator to orange.
                $pressureFalling = ($bar['kind'] ?? '') === 'air-pressure'
                    && $bar['delta'] !== null
                    && (float)$bar['delta'] < 0;
                $pressureDropWarning = ($bar['kind'] ?? '') === 'air-pressure'
                    && $bar['delta'] !== null
                    && (float)$bar['delta'] <= -0.8;
                $dewPointGapWarning = ($bar['historyKey'] ?? '') === 'dew_point_gap_c'
                    && (float)$bar['value'] <= 3.0;
            ?>
                <div class="climate-bar-row<?= isset($bar['kind']) ? ' climate-bar-' . htmlspecialchars($bar['kind'], ENT_QUOTES) : '' ?><?= $pressureDropWarning ? ' pressure-drop-warning' : '' ?><?= $pressureFalling ? ' pressure-falling' : '' ?><?= $dewPointGapWarning ? ' dew-point-gap-warning' : '' ?>" data-climate-graph="<?= htmlspecialchars((string)$bar['historyKey'], ENT_QUOTES) ?>">
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
                    <svg class="climate-mobile-gauge" viewBox="0 0 100 50" preserveAspectRatio="xMidYMid meet" role="img" aria-label="<?= htmlspecialchars($bar['label'] . ': ' . $bar['display'], ENT_QUOTES) ?>">
                        <path class="climate-mobile-gauge-track" d="M 4 48 A 46 46 0 0 1 96 48"></path>
                        <path class="climate-mobile-gauge-value" d="<?= htmlspecialchars($mobileGaugePath, ENT_QUOTES) ?>"></path>
                        <g class="climate-mobile-gauge-ticks" aria-hidden="true">
                            <?php for ($tickIndex = 0; $tickIndex <= 12; $tickIndex++):
                                $tickAngle = M_PI - (($tickIndex / 12) * M_PI);
                                $tickIsMajor = $tickIndex % 3 === 0;
                                $tickInnerRadius = $tickIsMajor ? 36.8 : 39.8;
                                $tickOuterRadius = 43.2;
                                $tickX1 = 50 + ($tickInnerRadius * cos($tickAngle));
                                $tickY1 = 48 - ($tickInnerRadius * sin($tickAngle));
                                $tickX2 = 50 + ($tickOuterRadius * cos($tickAngle));
                                $tickY2 = 48 - ($tickOuterRadius * sin($tickAngle));
                            ?>
                                <line class="<?= $tickIsMajor ? 'major' : 'minor' ?>"
                                      x1="<?= number_format($tickX1, 2, '.', '') ?>" y1="<?= number_format($tickY1, 2, '.', '') ?>"
                                      x2="<?= number_format($tickX2, 2, '.', '') ?>" y2="<?= number_format($tickY2, 2, '.', '') ?>"></line>
                            <?php endfor; ?>
                        </g>
                        <path class="climate-mobile-gauge-needle" d="<?= htmlspecialchars($needlePath, ENT_QUOTES) ?>"></path>
                        <circle class="climate-mobile-gauge-endpoint" cx="<?= number_format($gaugeEndX, 2, '.', '') ?>" cy="<?= number_format($gaugeEndY, 2, '.', '') ?>" r="2"></circle>
                        <circle class="climate-mobile-gauge-pivot" cx="50" cy="48" r="4.5"></circle>
                    </svg>
                    <strong class="climate-bar-change <?= $deltaClass ?>"><?= htmlspecialchars($bar['deltaDisplay'], ENT_QUOTES) ?></strong>
                    <strong class="climate-bar-value"><?= htmlspecialchars($bar['display'], ENT_QUOTES) ?></strong>
                    <?php if ($bar['sparkline'] !== ''): ?>
                        <svg class="climate-bar-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none" role="img" aria-label="<?= htmlspecialchars($bar['label'] . ' trend over the last 24 hours, based on ' . $bar['historyCount'] . ' stored readings', ENT_QUOTES) ?>">
                            <?php if (($bar['historyKey'] ?? '') === 'dew_point_c'): ?>
                                <defs>
                                    <?php
                                    $dewOffset = (float)($bar['dewPointThresholdOffset'] ?? 0.5);
                                    $dewBoundary = number_format($dewOffset * 100, 4, '.', '') . '%';
                                    $dewLineStops = ($bar['dewPointAllBlue'] ?? false)
                                        ? '<stop offset="0%" stop-color="#35c4eb"/><stop offset="100%" stop-color="#35c4eb"/>'
                                        : (($bar['dewPointAllOrange'] ?? false)
                                            ? '<stop offset="0%" stop-color="#ffc184"/><stop offset="100%" stop-color="#ffc184"/>'
                                            : '<stop offset="' . $dewBoundary . '" stop-color="#35c4eb"/><stop offset="' . $dewBoundary . '" stop-color="#ffc184"/>');
                                    $dewAreaStops = ($bar['dewPointAllBlue'] ?? false)
                                        ? '<stop offset="0%" stop-color="#35c4eb" stop-opacity=".09"/><stop offset="100%" stop-color="#35c4eb" stop-opacity=".09"/>'
                                        : (($bar['dewPointAllOrange'] ?? false)
                                            ? '<stop offset="0%" stop-color="#ffc184" stop-opacity=".09"/><stop offset="100%" stop-color="#ffc184" stop-opacity=".09"/>'
                                            : '<stop offset="' . $dewBoundary . '" stop-color="#35c4eb" stop-opacity=".09"/><stop offset="' . $dewBoundary . '" stop-color="#ffc184" stop-opacity=".09"/>');
                                    ?>
                                    <linearGradient id="dewPointZoneLine" x1="0" y1="22" x2="0" y2="4" gradientUnits="userSpaceOnUse"><?= $dewLineStops ?></linearGradient>
                                    <linearGradient id="dewPointZoneArea" x1="0" y1="22" x2="0" y2="4" gradientUnits="userSpaceOnUse"><?= $dewAreaStops ?></linearGradient>
                                </defs>
                            <?php endif; ?>
                            <polygon class="climate-sparkline-area" points="<?= htmlspecialchars($bar['sparklineArea'], ENT_QUOTES) ?>"<?= ($bar['historyKey'] ?? '') === 'dew_point_c' ? ' style="fill:url(#dewPointZoneArea)"' : '' ?>></polygon>
                            <line class="climate-sparkline-baseline" x1="0" y1="23.5" x2="100" y2="23.5"></line>
                            <?php if (is_array($bar['sparklineEndpoint'])): ?>
                                <line class="climate-sparkline-current-guide" x1="<?= htmlspecialchars($bar['sparklineEndpoint'][0], ENT_QUOTES) ?>" y1="<?= htmlspecialchars($bar['sparklineEndpoint'][1], ENT_QUOTES) ?>" x2="<?= htmlspecialchars($bar['sparklineEndpoint'][0], ENT_QUOTES) ?>" y2="23.5"></line>
                            <?php endif; ?>
                            <polyline points="<?= htmlspecialchars($bar['sparkline'], ENT_QUOTES) ?>"<?= ($bar['historyKey'] ?? '') === 'dew_point_c' ? ' style="stroke:url(#dewPointZoneLine)"' : '' ?>></polyline>
                            <?php if (is_array($bar['sparklineStartpoint'])): ?>
                                <circle class="climate-sparkline-startpoint" cx="<?= htmlspecialchars($bar['sparklineStartpoint'][0], ENT_QUOTES) ?>" cy="<?= htmlspecialchars($bar['sparklineStartpoint'][1], ENT_QUOTES) ?>" r="1"></circle>
                            <?php endif; ?>
                            <?php if (is_array($bar['sparklineEndpoint'])): ?>
                                <circle class="climate-sparkline-endpoint" cx="<?= htmlspecialchars($bar['sparklineEndpoint'][0], ENT_QUOTES) ?>" cy="<?= htmlspecialchars($bar['sparklineEndpoint'][1], ENT_QUOTES) ?>" r="1.6"></circle>
                            <?php endif; ?>
                        </svg>
                    <?php else: ?>
                        <div class="climate-bar-sparkline-unavailable">24-hour trend unavailable</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="climate-bar-row climate-bar-wvi" data-climate-graph="wvi">
                <div class="climate-bar-label">Weather Volatility Index</div>
                <div class="climate-segment-track" role="img" aria-label="Weather Volatility Index segmented gauge"><?php for ($segment = 0; $segment < 60; $segment++): ?><span class="climate-segment"></span><?php endfor; ?></div>
                <svg class="climate-mobile-gauge" viewBox="0 0 100 50" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Weather Volatility Index gauge">
                    <path class="climate-mobile-gauge-track" d="M 4 48 A 46 46 0 0 1 96 48"></path>
                    <path class="climate-mobile-gauge-value" id="climateWviGaugeValue" d="M 4 48"></path>
                    <g class="climate-mobile-gauge-ticks" aria-hidden="true">
                        <?php for ($tickIndex = 0; $tickIndex <= 12; $tickIndex++):
                            $tickAngle = M_PI - (($tickIndex / 12) * M_PI);
                            $tickIsMajor = $tickIndex % 3 === 0;
                            $tickInnerRadius = $tickIsMajor ? 36.8 : 39.8;
                            $tickOuterRadius = 43.2;
                            $tickX1 = 50 + ($tickInnerRadius * cos($tickAngle));
                            $tickY1 = 48 - ($tickInnerRadius * sin($tickAngle));
                            $tickX2 = 50 + ($tickOuterRadius * cos($tickAngle));
                            $tickY2 = 48 - ($tickOuterRadius * sin($tickAngle));
                        ?>
                            <line class="<?= $tickIsMajor ? 'major' : 'minor' ?>"
                                  x1="<?= number_format($tickX1, 2, '.', '') ?>" y1="<?= number_format($tickY1, 2, '.', '') ?>"
                                  x2="<?= number_format($tickX2, 2, '.', '') ?>" y2="<?= number_format($tickY2, 2, '.', '') ?>"></line>
                        <?php endfor; ?>
                    </g>
                    <path class="climate-mobile-gauge-needle" id="climateWviNeedle" d="M 50 48 L 50 12"></path>
                    <circle class="climate-mobile-gauge-endpoint" id="climateWviEndpoint" cx="4" cy="48" r="2"></circle>
                    <circle class="climate-mobile-gauge-pivot" cx="50" cy="48" r="4.5"></circle>
                </svg>
                <strong class="climate-bar-change" id="climateWviChange">—</strong>
                <strong class="climate-bar-value" id="climateWviValue">—</strong>
                <svg class="climate-bar-sparkline" viewBox="0 0 100 24" preserveAspectRatio="none" role="img" aria-label="WVI trend over the last 24 hours">
                    <polygon class="climate-sparkline-area" id="climateWviArea" points=""></polygon>
                    <line class="climate-sparkline-baseline" x1="0" y1="23.5" x2="100" y2="23.5"></line>
                    <line class="climate-sparkline-current-guide" id="climateWviGuide" x1="100" y1="4" x2="100" y2="23.5"></line>
                    <polyline id="climateWviLine" points=""></polyline>
                    <circle class="climate-sparkline-startpoint" id="climateWviStart" cx="0" cy="22" r="0"></circle>
                    <circle class="climate-sparkline-endpoint" id="climateWviEnd" cx="100" cy="22" r="0"></circle>
                </svg>
            </div>
        </div>
    </section>





    <section class="measurement-grid">


        <?php
        $windSpeed = $current ? max(0.0, (float)$current['wind_speed_kmh']) : 0.0;
$windSpeedChange1h = null;
if ($current && !empty($current['observed_at'])) {
    try {
        $windObservedAt = new DateTimeImmutable((string)$current['observed_at']);
        $windPreviousSql = "SELECT wind_speed_kmh FROM gaugeiq_pressure_readings
                            WHERE location_id = ? AND observed_at <= ? AND observed_at >= ?
                              AND (source IS NULL OR source = 'Open-Meteo')
                              AND wind_speed_kmh IS NOT NULL";
        if ($activeLocationChangedAt !== '') $windPreviousSql .= ' AND created_at >= ?';
        $windPreviousSql .= ' ORDER BY observed_at DESC LIMIT 1';
        $windPreviousStmt = $pdo->prepare($windPreviousSql);
        // Stored Open-Meteo timestamps use an ISO 'T' separator. Match that
        // format in the bounds so string comparisons find the prior reading.
        $windPreviousParams = [
            $locationId,
            $windObservedAt->modify('-45 minutes')->format('Y-m-d\\TH:i:s'),
            $windObservedAt->modify('-90 minutes')->format('Y-m-d\\TH:i:s')
        ];
        if ($activeLocationChangedAt !== '') $windPreviousParams[] = $activeLocationChangedAt;
        $windPreviousStmt->execute($windPreviousParams);
        $windPreviousSpeed = $windPreviousStmt->fetchColumn();
        if ($windPreviousSpeed !== false) {
            $windSpeedChange1h = $windSpeed - (float)$windPreviousSpeed;
        }
    } catch (Throwable $e) {
        $windSpeedChange1h = null;
    }
}
        $windSpeedChangeDisplay = $windSpeedChange1h === null
            ? '—'
            : (round($windSpeedChange1h, 1) == 0.0 ? '-' : (($windSpeedChange1h > 0 ? '+' : '') . number_format($windSpeedChange1h, 1)));
        $windSpeedChangeClass = $windSpeedChange1h === null
            ? 'unavailable'
            : ($windSpeedChange1h > 0 ? 'rise' : ($windSpeedChange1h < 0 ? 'fall' : 'steady'));
        $windSpeedDisplay = $current ? number_format($windSpeed, 1) . ' km/h' : '—';
        $windDegrees = $current ? fmod((float)$current['wind_direction_degrees'] + 360.0, 360.0) : 0.0;
        $windDirection = $current ? PressureService::directionLabel($windDegrees) : '—';
        $windToDegrees = fmod($windDegrees + 180.0, 360.0);
        $windGaugePoint = static function (float $angle, float $radius): array {
            $radians = deg2rad($angle);
            return [50.0 + cos($radians) * $radius, 50.0 + sin($radians) * $radius];
        };

        // Match the Atmospheric Monitor's semicircular gauge and use the same
        // stored 24-hour readings for the wind-speed trend graph.
        $windSpeedRatio = $current ? max(0.0, min(1.0, $windSpeed / 60.0)) : 0.0;
        $windSpeedGaugeAngle = M_PI - (M_PI * $windSpeedRatio);
        $windSpeedGaugeEndX = 50.0 + (46.0 * cos($windSpeedGaugeAngle));
        $windSpeedGaugeEndY = 48.0 - (46.0 * sin($windSpeedGaugeAngle));
        $windSpeedNeedleEndX = 50.0 + (36.0 * cos($windSpeedGaugeAngle));
        $windSpeedNeedleEndY = 48.0 - (36.0 * sin($windSpeedGaugeAngle));
        $windNeedleHalfWidth = 3.9;
        $windNeedlePerpX = $windNeedleHalfWidth * sin($windSpeedGaugeAngle);
        $windNeedlePerpY = $windNeedleHalfWidth * cos($windSpeedGaugeAngle);
        $windSpeedNeedlePath = 'M ' . number_format(50 + $windNeedlePerpX, 2, '.', '') . ' ' . number_format(48 + $windNeedlePerpY, 2, '.', '') .
            ' L ' . number_format($windSpeedNeedleEndX, 2, '.', '') . ' ' . number_format($windSpeedNeedleEndY, 2, '.', '') .
            ' L ' . number_format(50 - $windNeedlePerpX, 2, '.', '') . ' ' . number_format(48 - $windNeedlePerpY, 2, '.', '') . ' Z';
        $windSpeedGaugePath = 'M 4 48 A 46 46 0 0 1 ' . number_format($windSpeedGaugeEndX, 2, '.', '') . ' ' . number_format($windSpeedGaugeEndY, 2, '.', '');

        $windSpeedTrendValues = [];
        foreach ($climateHistory as $windHistoryRow) {
            if (is_numeric($windHistoryRow['wind_speed_kmh'] ?? null)) {
                $windSpeedTrendValues[] = (float)$windHistoryRow['wind_speed_kmh'];
            }
        }
        $windSpeedTrendCount = count($windSpeedTrendValues);
        $windSpeedTrendPoints = [];
        if ($windSpeedTrendCount >= 2) {
            $windSpeedTrendMin = min($windSpeedTrendValues);
            $windSpeedTrendRange = max(0.0001, max($windSpeedTrendValues) - $windSpeedTrendMin);
            foreach ($windSpeedTrendValues as $windPointIndex => $windPointValue) {
                $windX = $windPointIndex / ($windSpeedTrendCount - 1) * 100;
                $windY = 22 - (($windPointValue - $windSpeedTrendMin) / $windSpeedTrendRange * 18);
                $windSpeedTrendPoints[] = number_format($windX, 2, '.', '') . ',' . number_format($windY, 2, '.', '');
            }
        }
        $windSpeedTrendLine = implode(' ', $windSpeedTrendPoints);
        $windSpeedTrendArea = $windSpeedTrendCount >= 2 ? '0,23.5 ' . $windSpeedTrendLine . ' 100,23.5' : '';
        $windSpeedTrendStart = $windSpeedTrendCount >= 2 ? explode(',', $windSpeedTrendPoints[0]) : null;
        $windSpeedTrendEnd = $windSpeedTrendCount >= 2 ? explode(',', end($windSpeedTrendPoints)) : null;
        ?>
        <article class="card metric-card wind-gauge-card wind-hud-card">
            <div class="wind-hud-heading">
                <div>
                    <p class="label">Wind</p>
                    <div class="wind-hud-subtitle">WIND DIRECTION</div>
                </div>
                <button type="button" class="wind-hud-compass-switch" id="windCompassButton" aria-pressed="false">Enable Compass</button>
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
            <p class="wind-hud-help">The orange dot marks where wind comes from; the arrow points where it is going. Use the phone compass to keep the direction aligned as you turn your phone.</p>
            <div class="wind-hud-speed-history" aria-label="Wind speed gauge and 24-hour trend">
                <div class="wind-hud-speed-history-content climate-gauge-card wind-speed-instrument-scope">
                    <div class="climate-bar-row wind-speed-instrument-row">
                        <div class="climate-bar-label">WIND SPEED</div>
                        <div class="climate-segment-track" role="img" aria-label="<?= htmlspecialchars('Wind speed segmented gauge, ' . number_format($windSpeed, 1) . ' km/h', ENT_QUOTES) ?>"><?php for ($segment = 0; $segment < 60; $segment++): ?><span class="climate-segment<?= $current && $segment < (int)round($windSpeedRatio * 60) ? ' active' : '' ?>"></span><?php endfor; ?></div>
                        <svg class="climate-mobile-gauge" viewBox="0 0 100 50" preserveAspectRatio="xMidYMid meet" role="img" aria-label="<?= $current ? htmlspecialchars('Wind speed ' . number_format($windSpeed, 1) . ' kilometres per hour', ENT_QUOTES) : 'Wind speed unavailable' ?>">
                            <path class="climate-mobile-gauge-track" d="M 4 48 A 46 46 0 0 1 96 48"></path>
                            <path class="climate-mobile-gauge-value" d="<?= htmlspecialchars($windSpeedGaugePath, ENT_QUOTES) ?>"></path>
                            <g class="climate-mobile-gauge-ticks" aria-hidden="true">
                                <?php for ($tickIndex = 0; $tickIndex <= 12; $tickIndex++):
                                    $tickAngle = M_PI - (($tickIndex / 12) * M_PI);
                                    $tickIsMajor = $tickIndex % 3 === 0;
                                    $tickInnerRadius = $tickIsMajor ? 36.8 : 39.8;
                                    $tickOuterRadius = 43.2;
                                    $tickX1 = 50 + ($tickInnerRadius * cos($tickAngle));
                                    $tickY1 = 48 - ($tickInnerRadius * sin($tickAngle));
                                    $tickX2 = 50 + ($tickOuterRadius * cos($tickAngle));
                                    $tickY2 = 48 - ($tickOuterRadius * sin($tickAngle));
                                ?>
                                    <line class="<?= $tickIsMajor ? 'major' : 'minor' ?>"
                                          x1="<?= number_format($tickX1, 2, '.', '') ?>" y1="<?= number_format($tickY1, 2, '.', '') ?>"
                                          x2="<?= number_format($tickX2, 2, '.', '') ?>" y2="<?= number_format($tickY2, 2, '.', '') ?>"></line>
                                <?php endfor; ?>
                            </g>
                            <path class="climate-mobile-gauge-needle" d="<?= htmlspecialchars($windSpeedNeedlePath, ENT_QUOTES) ?>"></path>
                            <circle class="climate-mobile-gauge-endpoint" cx="<?= number_format($windSpeedGaugeEndX, 2, '.', '') ?>" cy="<?= number_format($windSpeedGaugeEndY, 2, '.', '') ?>" r="2"></circle>
                            <circle class="climate-mobile-gauge-pivot" cx="50" cy="48" r="4.5"></circle>
                        </svg>
                        <strong class="climate-bar-change <?= $windSpeedChangeClass ?>"><?= htmlspecialchars($windSpeedChangeDisplay, ENT_QUOTES) ?></strong>
                        <strong class="climate-bar-value"><?= htmlspecialchars($windSpeedDisplay, ENT_QUOTES) ?></strong>
                        <?php if ($windSpeedTrendCount >= 2): ?>
                            <svg class="climate-bar-sparkline <?= $windSpeed < 15 ? 'wind-speed-band-blue' : 'wind-speed-band-orange' ?>" viewBox="0 0 100 24" preserveAspectRatio="none" role="img" aria-label="<?= htmlspecialchars('Wind speed trend over the last 24 hours, based on ' . $windSpeedTrendCount . ' stored readings', ENT_QUOTES) ?>">
                                <polygon class="climate-sparkline-area" points="<?= htmlspecialchars($windSpeedTrendArea, ENT_QUOTES) ?>"></polygon>
                                <line class="climate-sparkline-baseline" x1="0" y1="23.5" x2="100" y2="23.5"></line>
                                <line class="climate-sparkline-current-guide" x1="<?= htmlspecialchars($windSpeedTrendEnd[0], ENT_QUOTES) ?>" y1="<?= htmlspecialchars($windSpeedTrendEnd[1], ENT_QUOTES) ?>" x2="<?= htmlspecialchars($windSpeedTrendEnd[0], ENT_QUOTES) ?>" y2="23.5"></line>
                                <polyline points="<?= htmlspecialchars($windSpeedTrendLine, ENT_QUOTES) ?>"></polyline>
                                <circle class="climate-sparkline-startpoint" cx="<?= htmlspecialchars($windSpeedTrendStart[0], ENT_QUOTES) ?>" cy="<?= htmlspecialchars($windSpeedTrendStart[1], ENT_QUOTES) ?>" r="1"></circle>
                                <circle class="climate-sparkline-endpoint" cx="<?= htmlspecialchars($windSpeedTrendEnd[0], ENT_QUOTES) ?>" cy="<?= htmlspecialchars($windSpeedTrendEnd[1], ENT_QUOTES) ?>" r="1.6"></circle>
                            </svg>
                        <?php else: ?>
                            <div class="climate-bar-sparkline-unavailable">24-hour wind history unavailable</div>
                        <?php endif; ?>
                    </div>
                </div>
            <div class="wind-compass-controls">
                <span class="wind-compass-status" id="windCompassStatus" hidden></span>
            </div>
        </article>


    <section id="weatherMapView" class="weather-map-dashboard-card card" aria-labelledby="interactiveWeatherMapsTitle">
        <header class="gaugeiq-weather-map-toolbar">
            <div class="gaugeiq-weather-map-heading">
                <strong id="interactiveWeatherMapsTitle">Weather Map</strong>
            </div>
            <div class="gaugeiq-weather-map-controls">
                <select id="layer-select" aria-label="Weather layer">
                    <option value="temperature_2m">Temperature</option>
                    <option value="precipitation_cloud_cover" selected>Combined</option>
                    <option value="precipitation">Precipitation only</option>
                    <option value="cloud_cover">Cloud cover only</option>
                </select>
                <a class="gaugeiq-weather-map-action" href="weather-map/appearance.php?location=<?= $locationId ?>">Appearance</a>
            </div>
        </header>
        <div class="gaugeiq-weather-map-timeline">
            <button id="map-animation-toggle" class="gaugeiq-weather-map-action" type="button" aria-pressed="false" disabled>Play</button>
            <div class="gaugeiq-weather-map-progress-wrap">
                <span id="map-frame-time" aria-live="polite">Preparing five weather frames…</span>
                <div id="map-frame-progress" class="gaugeiq-weather-map-progress" role="progressbar" aria-label="Weather animation progress" aria-valuemin="1" aria-valuemax="5" aria-valuenow="1" aria-valuetext="Frame 1 of 5">
                    <span class="is-current"></span><span></span><span></span><span></span><span></span>
                </div>
            </div>
        </div>
</div>
        <div id="map" class="gaugeiq-weather-map-canvas"
             data-latitude="<?= htmlspecialchars($dashboardLatitude, ENT_QUOTES, 'UTF-8') ?>"
             data-longitude="<?= htmlspecialchars($dashboardLongitude, ENT_QUOTES, 'UTF-8') ?>"
             data-location-id="<?= $locationId ?>">
            <div class="gaugeiq-weather-map-status" id="map-status" role="status">Loading interactive weather map…</div>
        </div>
    </section>
    <details class="weather-map-credits-card">
        <summary class="weather-map-credits-toggle">
            <span class="weather-map-credits-heading">
                <span class="weather-map-credits-kicker">TRANSPARENCY &amp; SOURCES</span>
                <span class="weather-map-credits-title" id="weatherMapCreditsTitle">Credits &amp; Data Sources</span>
                <span class="weather-map-credits-intro">Map imagery and forecast layers, with source information and licensing.</span>
            </span>
            <span class="weather-map-credits-chevron" aria-hidden="true"></span>
        </summary>
        <div class="weather-map-credits-content">
            <div class="weather-map-credit-grid">
                <article class="weather-map-credit-source map-source">
                    <span class="weather-map-credit-icon" aria-hidden="true">⌖</span>
                    <div>
                        <span class="weather-map-credit-label">MAP &amp; GEOGRAPHIC DATA</span>
                        <p><a href="https://openfreemap.org/" target="_blank" rel="noopener noreferrer">OpenFreeMap</a>, <a href="https://openmaptiles.org/" target="_blank" rel="noopener noreferrer">OpenMapTiles</a> and <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>.</p>
                    </div>
                </article>
                <article class="weather-map-credit-source meteo-source">
                    <span class="weather-map-credit-icon" aria-hidden="true">☁</span>
                    <div>
                        <span class="weather-map-credit-label">WEATHER DATA</span>
                        <p><a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Weather data by Open-Meteo.com</a>.</p>
                        <small>Weather data is processed and visually styled by GaugeIQ.</small>
                    </div>
                </article>
                <article class="weather-map-credit-source ecmwf-source">
                    <span class="weather-map-credit-icon" aria-hidden="true">◉</span>
                    <div>
                        <span class="weather-map-credit-label">WEATHER MODEL &amp; LICENCE</span>
                        <p class="ecmwf-required-credit">This service is based on data and products of the European Centre for Medium-Range Weather Forecasts (ECMWF).</p>
                        <small>Source: <a href="https://www.ecmwf.int/" target="_blank" rel="noopener noreferrer">ECMWF</a>. Data is made available under the <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">Creative Commons Attribution 4.0 International (CC BY 4.0) licence</a>.</small>
                    </div>
                </article>
            </div>
            <p class="weather-map-credit-disclaimer">ECMWF does not accept any liability whatsoever for any error or omission in the data, their availability, or for any loss or damage arising from their use.</p>
        </div>
    </details>




    <section class="card weather-change-card wind-hud-card" aria-labelledby="weatherChangeTitle">
        <div class="section-heading weather-change-heading">
            <div>
                <h2 id="weatherChangeTitle">Weather Volatility Index (WVI)</h2>
                <p class="muted">Interprets the degree and speed of changing conditions.</p>
            </div>
        </div>
        <div class="weather-change-metrics">
            <div class="weather-change-score" id="weatherChangeScore" aria-label="Weather Volatility Index score">—</div>
            <div class="weather-change-level" id="weatherChangeLevel" role="img" aria-label="WVI level">
                <span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <button type="button" class="secondary weather-change-reasons-toggle info-icon-button" id="weatherChangeReasonsToggle" aria-label="Show Weather Volatility Index reasons" title="More information" aria-controls="weatherChangeReasons" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 10.5v6"></path>
                <circle cx="12" cy="7.5" r=".8" class="info-icon-dot"></circle>
            </svg>
            <span id="weatherChangeReasonsButtonLabel">More information</span>
        </button>
        <div class="weather-change-reasons" id="weatherChangeReasons" aria-live="polite" hidden>
            <div class="weather-change-summary" id="weatherChangeSummary">Analysing recent conditions…</div>
        </div>
        <details class="algorithm-details">
            <summary>How the WVI is calculated</summary>
            <p>GaugeIQ measures cumulative movement in nine weather signals across the latest six-hour observation window. Each signal is converted to a severity using its configured trigger and full-severity level, then combined using the configured weights to produce a score from 0 to 10. Reversals and repeated changes count towards the result. The local baseline is a separate comparison against previous comparable six-hour windows; it does not form part of the WVI score.</p>
        </details>
    </section>

    <section class="card conditions-trend-card" aria-labelledby="conditionsTrendTitle">
        <div class="conditions-trend-heading">
            <h2 id="conditionsTrendTitle">Conditions trend</h2>
            <p class="muted">Interprets the change in weather conditions.</p>
        </div>
        <div class="conditions-trend-result">
            <div class="conditions-trend-score" id="conditionsTrendIcon" aria-hidden="true">→</div>
            <strong class="conditions-trend-label" id="conditionsTrendLabel"></strong>
        </div>
        <button type="button" class="secondary weather-change-reasons-toggle info-icon-button" id="conditionsTrendReasonsToggle" aria-label="Show conditions trend reasons" title="More information" aria-controls="conditionsTrendReasons" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 10.5v6"></path>
                <circle cx="12" cy="7.5" r=".8" class="info-icon-dot"></circle>
            </svg>
            <span id="conditionsTrendReasonsButtonLabel">More information</span>
        </button>
        <div class="conditions-trend-reasons" id="conditionsTrendReasons" aria-live="polite" hidden>
            <p class="conditions-trend-summary" id="conditionsTrendSummary"></p>
            <div id="conditionsTrendReasonItems"></div>
        </div>
        <details class="algorithm-details">
            <summary>How the conditions trend is classified</summary>
            <p>This is a separate directional assessment, not the Weather Volatility Index (WVI).</p>
            <p>Falling pressure, developing rain, strengthening wind, increasing cloud cover and more severe weather codes support a Worsening classification. The opposite signals can support Improving. The combined directional score must reach ±2 to classify a trend; otherwise the result is Steady/no clear trend.</p>
        </details>
    </section>

    <section class="card history-card" data-history-timezone="<?= htmlspecialchars($dashboardTimezone, ENT_QUOTES) ?>">
        <button type="button" class="history-toggle" id="historyToggle" aria-expanded="false" aria-controls="historyContent">
            <span class="section-heading">
                <span>
                    <span class="history-title" id="historyTitle">Last 24 hours</span>
                    <span id="historyStatus" class="muted">Press to view historical graphs</span>
                </span>
                <span class="history-toggle-indicator" aria-hidden="true">+</span>
            </span>
        </button>
        <div id="historyContent" hidden>
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
        </div>
    </section>

    </section>
    <dialog id="notificationRepairDialog" class="notification-repair-dialog" aria-labelledby="notificationRepairTitle">
        <div class="notification-repair-content">
            <button id="notificationRepairClose" class="notification-repair-close" type="button" aria-label="Close">×</button>
            <p class="eyebrow">GAUGЕIQ NOTIFICATIONS</p>
            <h2 id="notificationRepairTitle">Notification connection needs repair</h2>
            <p>Your device still allows GaugeIQ notifications, but its saved connection no longer matches GaugeIQ's current push credentials. Weather alerts may not reach this device until the connection is repaired.</p>
            <p>Repairing will replace this device's old push subscription. It will not change your notification permission or affect other devices.</p>
            <p id="notificationRepairError" class="notification-repair-error" role="alert" hidden></p>
            <button id="notificationRepairButton" type="button">Repair connection</button>
        </div>
    </dialog>
    <p id="status" class="status"></p>
</main>
<?php
try { $historyTimezone = new DateTimeZone($dashboardTimezone); } catch (Throwable) { $historyTimezone = new DateTimeZone('UTC'); }
$historyNow = new DateTimeImmutable('now', $historyTimezone);
$historyMonthStart = $historyNow->modify('first day of this month')->modify('-1 month');
$historyAutoStart = $historyMonthStart->modify('+' . (min((int)$historyNow->format('j'), (int)$historyMonthStart->format('t')) - 1) . ' days')->format('Y-m-d');
$historyAutoEnd = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$historyCountSql = "SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE location_id = ? AND source = ? AND observed_at >= ? AND observed_at <= ?";
$historyCountParams = [$locationId, 'Open-Meteo Historical Weather', $historyAutoStart . 'T00:00', $historyAutoEnd . 'T23:59'];
$historyLatestSql = "SELECT MAX(observed_at) FROM gaugeiq_pressure_readings WHERE location_id = ? AND source = ?";
$historyLatestParams = [$locationId, 'Open-Meteo Historical Weather'];
if ($activeLocationChangedAt !== '') {
    $historyCountSql .= ' AND created_at >= ?';
    $historyCountParams[] = $activeLocationChangedAt;
    $historyLatestSql .= ' AND created_at >= ?';
    $historyLatestParams[] = $activeLocationChangedAt;
}
$historyWeatherCountStmt = $pdo->prepare($historyCountSql);
$historyWeatherCountStmt->execute($historyCountParams);
$historyWeatherCount = (int)$historyWeatherCountStmt->fetchColumn();
$historyWeatherLatestStmt = $pdo->prepare($historyLatestSql);
$historyWeatherLatestStmt->execute($historyLatestParams);
$historyWeatherLatest = (string)($historyWeatherLatestStmt->fetchColumn() ?: '');
?>
<div id="historicalDatasetStatus"
     hidden
     data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"
     data-location-id="<?= $locationId ?>"
     data-start="<?= htmlspecialchars($historyAutoStart, ENT_QUOTES) ?>"
     data-end="<?= htmlspecialchars($historyAutoEnd, ENT_QUOTES) ?>"
     data-weather-count="<?= $historyWeatherCount ?>"
     data-weather-latest="<?= htmlspecialchars($historyWeatherLatest, ENT_QUOTES) ?>"
     data-latitude="<?= htmlspecialchars($dashboardLatitude, ENT_QUOTES) ?>"
     data-longitude="<?= htmlspecialchars($dashboardLongitude, ENT_QUOTES) ?>"></div>
<script src="js/local-weather.js?v=20261009-location-refresh"></script>
<script src="js/openmeteo-history.js?v=20261111-location-history"></script>
<script src="js/loading-screen.js?v=20261029-loading-screen-restored"></script>
<script src="js/app.js?v=20261113-radar-compass-axis"></script>
</body>
</html>