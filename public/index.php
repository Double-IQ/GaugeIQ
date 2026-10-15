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
    $change = 0.0;
    if ($latest) {
        $previousStmt = $pdo->prepare(
            "SELECT pressure_hpa FROM gaugeiq_pressure_readings
             WHERE id < ? AND (source IS NULL OR source = 'Open-Meteo')
             ORDER BY id DESC LIMIT 1"
        );
        $previousStmt->execute([(int)$latest['id']]);
        $previousPressure = $previousStmt->fetchColumn();
        $change = $previousPressure === false ? 0.0 : (float)$latest['pressure_hpa'] - (float)$previousPressure;
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
        ? (int)$stabilityThresholdValue
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
<link rel="stylesheet" href="css/app.css?v=20261015-remove-air-alerts-1">
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
        <div class="dashboard-heading">
            <h1 id="dashboardLocationName"><?= htmlspecialchars($locationName, ENT_QUOTES) ?></h1>
            <p id="lastRefreshedAt" class="muted last-refreshed-at" role="status"><?= htmlspecialchars($lastRefreshedLabel, ENT_QUOTES) ?></p>
        </div>
        <div class="header-actions">
            <button id="gaugeiqMenuButton" class="menu-button" type="button" aria-label="Open GaugeIQ administration"><img src="/branding/gaugeiq-logo-dark-background-original.png" alt="GaugeIQ" width="110" height="32" style="display:block;width:110px;height:auto;max-width:100%;object-fit:contain"></button>
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
            <div class="temperature-card-heading"><p class="label">Temperature</p></div>
            <?php if ($current): ?>
                <div class="temperature-summary-main">
                    <div class="temperature-summary-current">
                        <strong><?= number_format($temperatureC, 1) ?>°C</strong>
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
                <div class="temperature-feels-like-row temperature-feels-like-below"><span>Feels like <?= number_format($feelsLikeC, 1) ?>°C</span></div>
                <div class="temperature-stability-row temperature-comfort-alerts-row temperature-comfort-below" data-weather-stability-alert-threshold="<?= $weatherStabilityAlertThreshold !== null ? htmlspecialchars((string)$weatherStabilityAlertThreshold, ENT_QUOTES) : '' ?>">
                    <div class="temperature-conditions-inline" id="weatherConditions" aria-live="polite">Trend: <b class="conditions-status steady">→ Steady</b></div>
                </div>
                <div class="temperature-comfort-alerts-row temperature-status-bottom-row">
                    <div class="temperature-stability-label temperature-stability-inline" id="temperatureStabilityLabel"><span class="temperature-stability-prefix">Stability: </span><span id="temperatureStabilitySummary">Gathering readings…</span></div>
                </div>
            <?php else: ?><div class="metric-unavailable">Unavailable</div><?php endif; ?>
        </article>

        <article class="card metric-card pressure-card">
            <div class="pressure-card-heading">
                <div>
                    <p class="label">Air pressure</p>
                    <?php if ($current): ?>
                        <div class="pressure-readout-row">
                            <div class="metric-value"><?= number_format($current['pressure_hpa'], 1) ?><span> hPa</span></div>
                            <div class="pressure-range-gauge" aria-label="<?= $current ? htmlspecialchars('Current pressure ' . number_format((float)$current['pressure_hpa'], 1) . ' hPa, historical low ' . number_format($pressureLow, 1) . ', high ' . number_format($pressureHigh, 1), ENT_QUOTES) : 'Pressure range unavailable' ?>">
                                <svg viewBox="0 16 100 50" aria-hidden="true" focusable="false">
                                    <path class="pressure-range-track" d="M 18 55 A 32 32 0 0 1 82 55"></path>
                                    <g class="pressure-range-ticks">
                                        <?php for ($tick = 0; $tick <= 10; $tick++):
                                            $tickRatio = $tick / 10.0;
                                            $tickAngle = 180.0 + ($tickRatio * 180.0);
                                            $tickOuterX = 50 + cos(deg2rad($tickAngle)) * 33;
                                            $tickOuterY = 55 + sin(deg2rad($tickAngle)) * 33;
                                            $tickInnerRadius = $tick % 2 === 0 ? 27.5 : 29.5;
                                            $tickInnerX = 50 + cos(deg2rad($tickAngle)) * $tickInnerRadius;
                                            $tickInnerY = 55 + sin(deg2rad($tickAngle)) * $tickInnerRadius;
                                        ?>
                                            <line class="<?= $tick % 2 === 0 ? 'major' : '' ?>"
                                                  x1="<?= number_format($tickOuterX, 3, '.', '') ?>" y1="<?= number_format($tickOuterY, 3, '.', '') ?>"
                                                  x2="<?= number_format($tickInnerX, 3, '.', '') ?>" y2="<?= number_format($tickInnerY, 3, '.', '') ?>"></line>
                                        <?php endfor; ?>
                                    </g>
                                    <line class="pressure-range-needle" x1="50" y1="55"
                                          x2="<?= number_format(50 + cos(deg2rad(180 + $pressureRatio * 180)) * 28, 3, '.', '') ?>"
                                          y2="<?= number_format(55 + sin(deg2rad(180 + $pressureRatio * 180)) * 28, 3, '.', '') ?>"></line>
                                    <circle class="pressure-range-hub" cx="50" cy="55" r="2.8"></circle>
                                    <text x="14" y="63">L</text><text x="86" y="63">H</text>
                                </svg>
                            </div>
                        </div>
                        <div class="metric-trend <?= $change > 0 ? 'rise' : ($change < 0 ? 'fall' : 'steady') ?>">
                            <?= $change > 0 ? '↑ Rising' : ($change < 0 ? '↓ Falling' : '→ Stable') ?>
                            <?php if ($latest): ?><strong><?= $change >= 0 ? '+' : '' ?><?= number_format($change, 1) ?> hPa</strong><?php endif; ?>
                        </div>
                    <?php else: ?><div class="metric-unavailable">Unavailable</div><?php endif; ?>
                </div>
       </div>
        </article>

    <section class="measurement-grid">


        <?php
        $windSpeed = $current ? max(0.0, (float)$current['wind_speed_kmh']) : 0.0;
        $windDegrees = $current ? fmod((float)$current['wind_direction_degrees'] + 360.0, 360.0) : 0.0;
        $windDirection = $current ? PressureService::directionLabel($windDegrees) : '—';
        $windToDegrees = fmod($windDegrees + 180.0, 360.0);
        $windGaugePoint = static function (float $angle, float $radius): array {
            $radians = deg2rad($angle);
            return [50.0 + cos($radians) * $radius, 50.0 + sin($radians) * $radius];
        };
        ?>
        <article class="card metric-card wind-gauge-card">
            <div class="wind-gauge-heading">
                <div>
                    <p class="label">Wind</p>
                    <div class="wind-summary-line">Speed &amp; direction</div>
                </div>
                <div class="wind-compass-controls">
                    <span class="wind-compass-status" id="windCompassStatus" hidden></span>
                    <button type="button" class="secondary wind-compass-button" id="windCompassButton">Turn Compass ON</button>
                </div>
            </div>
            <div class="wind-gauge" role="img" aria-label="<?= $current ? htmlspecialchars(number_format($windSpeed, 1) . ' kilometers per hour, ' . $windDirection . ', ' . number_format($windDegrees, 0) . ' degrees', ENT_QUOTES) : 'Wind data unavailable' ?>">
                <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                    <g class="wind-compass-orientation">
                        <defs>
                            <radialGradient id="windCompassGlass" cx="38%" cy="28%" r="72%">
                                <stop offset="0%" stop-color="#ffffff" stop-opacity=".30"></stop>
                                <stop offset="34%" stop-color="#ffffff" stop-opacity=".10"></stop>
                                <stop offset="72%" stop-color="#ffffff" stop-opacity=".035"></stop>
                                <stop offset="100%" stop-color="#ffffff" stop-opacity=".12"></stop>
                            </radialGradient>
                        </defs>
                        <circle class="wind-gauge-glass-overlay" cx="50" cy="50" r="47"></circle>
                        <g class="wind-speed-segments" aria-label="Outer wind speed scale, 0 to 60 kilometres per hour">
                            <?php for ($segment = 0; $segment < 120; $segment++):
                                $segmentAngle = ($segment * 3.0) - 90.0;
                                $segmentStart = $windGaugePoint($segmentAngle, 44.7);
                                $segmentEnd = $windGaugePoint($segmentAngle, 47.8);
                                $segmentActive = $current && $windSpeed >= (($segment + 1) / 2);
                            ?>
                                <line class="<?= $segmentActive ? 'active' : '' ?>" x1="<?= number_format($segmentStart[0], 3, '.', '') ?>" y1="<?= number_format($segmentStart[1], 3, '.', '') ?>" x2="<?= number_format($segmentEnd[0], 3, '.', '') ?>" y2="<?= number_format($segmentEnd[1], 3, '.', '') ?>"></line>
                            <?php endfor; ?>
                        </g>
                        <g class="wind-speed-scale-labels" aria-label="Wind speed scale labels in kilometres per hour">
                            <?php for ($speedMark = 0; $speedMark <= 60; $speedMark += 10):
                                $speedAngle = ($speedMark * 6.0) - 90.0;
                                [$speedX, $speedY] = $windGaugePoint($speedAngle, 49.1);
                            ?>
                                <text x="<?= number_format($speedX, 3, '.', '') ?>" y="<?= number_format($speedY, 3, '.', '') ?>"><?= $speedMark ?></text>
                            <?php endfor; ?>
                        </g>
                        <circle class="wind-compass-ring" cx="50" cy="50" r="29.5"></circle>
                        <g class="wind-compass-ticks">
                            <?php
                            $compassLabels = [
                                0 => 'N', 45 => 'NE', 90 => 'E', 135 => 'SE',
                                180 => 'S', 225 => 'SW', 270 => 'W', 315 => 'NW'
                            ];
                            for ($degree = 0; $degree < 360; $degree += 5):
                                $svgAngle = $degree - 90.0;
                                $majorTick = $degree % 30 === 0;
                                $mediumTick = $degree % 10 === 0;
                                $tickStart = $windGaugePoint($svgAngle, $majorTick ? 41.7 : ($mediumTick ? 40.4 : 39.7));
                                $tickEnd = $windGaugePoint($svgAngle, 37.5);
                            ?>
                                <line class="<?= $majorTick ? 'major' : ($mediumTick ? 'medium' : '') ?> <?= $degree === 0 ? 'north-marker' : '' ?>" x1="<?= number_format($tickStart[0], 3, '.', '') ?>" y1="<?= number_format($tickStart[1], 3, '.', '') ?>" x2="<?= number_format($tickEnd[0], 3, '.', '') ?>" y2="<?= number_format($tickEnd[1], 3, '.', '') ?>"></line>
                            <?php endfor; ?>
                        </g>
                        <g class="wind-degree-labels">
                            <?php for ($degree = 0; $degree < 360; $degree += 30):
                                [$degreeX, $degreeY] = $windGaugePoint($degree - 90.0, 35.3);
                            ?>
                                <text class="<?= $degree === 0 ? 'north-degree' : '' ?>" x="<?= number_format($degreeX, 3, '.', '') ?>" y="<?= number_format($degreeY, 3, '.', '') ?>"><?= $degree ?></text>
                            <?php endfor; ?>
                        </g>
                        <g class="wind-compass-labels">
                            <?php foreach ($compassLabels as $degree => $label):
                                [$labelX, $labelY] = $windGaugePoint($degree - 90.0, 25.5);
                            ?>
                                <text class="<?= strlen($label) > 1 ? 'minor' : '' ?> <?= $label === 'N' ? 'north-label' : '' ?>" x="<?= number_format($labelX, 3, '.', '') ?>" y="<?= number_format($labelY, 3, '.', '') ?>"><?= $label ?></text>
                            <?php endforeach; ?>
                        </g>
                        <g class="wind-direction-arrows" data-wind-degrees="<?= number_format($windDegrees, 2, '.', '') ?>" transform="rotate(<?= number_format($windDegrees, 2, '.', '') ?> 50 50)">
                            <circle class="wind-direction-from-dot" cx="50" cy="19.5" r="2.1"></circle>
                            <path class="wind-direction-to-marker" d="M50 82.5 L56 72 L52 75 L52 64 L48 64 L48 75 L44 72 Z"></path>
                        </g>
                    </g>

                    <circle class="wind-center" cx="50" cy="50" r="18.5"></circle>
                </svg>
                <div class="wind-gauge-center">
                    <strong class="wind-heading-readout"><?= $current ? number_format($windDegrees, 0) . '° ' . htmlspecialchars($windDirection, ENT_QUOTES) : '—' ?></strong>
                    <small class="wind-speed-readout"><?= $current ? number_format($windSpeed, 1) . ' km/h wind' : 'Wind data unavailable' ?></small>
                </div>
            </div>
        </article>
    </section>

    <?php
    $dewBarRatio = max(0.0, min(1.0, $dewPointC / 40.0));
    $humidityBarRatio = $humidityPercent / 100.0;
    $rainBarRatio = max(0.0, min(1.0, $rainfallMm / 10.0));
    $cloudBarRatio = $cloudCoverPercent / 100.0;
    $climateBars = [
        ['label' => 'Dew point °C', 'value' => $dewPointC, 'display' => $current ? number_format($dewPointC, 1) : '—', 'ratio' => $dewBarRatio, 'decimals' => 1],
        ['label' => 'Humidity %', 'value' => $humidityPercent, 'display' => $current ? number_format($humidityPercent, 1) : '—', 'ratio' => $humidityBarRatio, 'decimals' => 1],
        ['label' => 'Cloud cover %', 'value' => $cloudCoverPercent, 'display' => $current ? number_format($cloudCoverPercent, 0) : '—', 'ratio' => $cloudBarRatio, 'decimals' => 0],
    ];
    if ($current && $rainfallMm > 0.0) {
        $climateBars[] = ['label' => 'Rainfall (mm)', 'value' => $rainfallMm, 'display' => number_format($rainfallMm, 2), 'ratio' => $rainBarRatio, 'decimals' => 2, 'kind' => 'current-rain'];
    }
    ?>
    <section class="card climate-gauge-card" aria-labelledby="climateGaugeTitle">
        <div class="section-heading">
            <div>
                <h2 id="climateGaugeTitle">Environmental conditions</h2>
                <p class="muted">Current dew point, humidity, rainfall and cloud cover.</p>
            </div>
        </div>
        <div class="climate-bars" aria-label="Current environmental conditions">
            <?php foreach ($climateBars as $bar): ?>
                <div class="climate-bar-row<?= isset($bar['kind']) ? ' climate-bar-' . htmlspecialchars($bar['kind'], ENT_QUOTES) : '' ?>">
                    <div class="climate-bar-label"><?= htmlspecialchars($bar['label'], ENT_QUOTES) ?></div>
                    <div class="climate-segment-track" role="img" aria-label="<?= htmlspecialchars($bar['label'] . ': ' . $bar['display'], ENT_QUOTES) ?>">
                        <?php for ($segment = 0; $segment < 40; $segment++):
                            $segmentThreshold = ($segment + 1) / 40.0;
                            $segmentActive = $current && $bar['ratio'] >= $segmentThreshold;
                            $segmentDanger = $segment >= 36;
                        ?>
                            <span class="climate-segment<?= $segmentActive ? ' active' : '' ?><?= $segmentDanger ? ' danger' : '' ?>"></span>
                        <?php endfor; ?>
                    </div>
                    <strong class="climate-bar-value"><?= htmlspecialchars($bar['display'], ENT_QUOTES) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card rain-probability-card" aria-labelledby="rainProbabilityTitle">
        <div class="section-heading">
            <div>
                <h2 id="rainProbabilityTitle">Rain probability</h2>
                <p class="muted">Today's forecast maximum precipitation probability.</p>
            </div>
            <div class="rain-probability-score" id="rainProbabilityScore"><?= $rainProbabilityPercent !== null ? htmlspecialchars((string)$rainProbabilityPercent, ENT_QUOTES) . '%' : '—' ?></div>
        </div>
        <div class="rainfall-forecast-block">
            <div class="rain-probability-meta">
                <span>Expected rainfall today</span>
                <strong><?= $rainForecastAvailable ? number_format($rainForecastMm, 1) : '—' ?> mm</strong>
            </div>
            <div class="insight-meter-track rainfall-forecast-track" role="progressbar" aria-label="Expected rainfall today" aria-valuemin="0" aria-valuemax="10" <?= $rainForecastAvailable ? 'aria-valuenow="' . number_format(min(10.0, $rainForecastMm), 1, '.', '') . '"' : 'aria-valuetext="Forecast rainfall unavailable"' ?> id="rainfallForecastTrack">
                <div class="insight-meter-fill rain-meter-fill" id="rainfallForecastFill" style="width:<?= number_format($rainForecastRatioPercent, 1, '.', '') ?>%;min-width:<?= $rainForecastAvailable && $rainForecastMm > 0 ? '2px' : '0' ?>"></div>
            </div>
            <p class="rainfall-forecast-scale muted">The full bar represents 10 mm or more of rain today.</p>
        </div>
        <details class="algorithm-details">
            <summary>How this meter is calculated</summary>
            <p><strong>Current method:</strong> use the provider's daily maximum precipitation probability directly (0–100%). Display the predicted daily rainfall amount separately in millimetres.</p>
            <p><strong>Weights:</strong> forecast probability 100% of the displayed probability. Recent rain, humidity, dew point, pressure and cloud cover are not yet used to adjust the percentage; they will be supporting signals only after calibration against observed outcomes.</p>
            <p><strong>Limitation:</strong> this is the forecast's daily maximum probability, not a calibrated GaugeIQ prediction for a particular hour. Forecast calibration needs saved forecast snapshots and later observations.</p>
        </details>
    </section>

    <section class="card weather-change-card" aria-labelledby="weatherChangeTitle">
        <div class="section-heading">
            <div>
                <h2 id="weatherChangeTitle">Weather Stability</h2>
                <p class="muted">Interprets the degree and speed of changing conditions.</p>
            </div>
            <div class="weather-change-score" id="weatherChangeScore" aria-label="Weather stability score">—</div>
        </div>
        <div class="weather-change-summary" id="weatherChangeSummary">Analysing recent conditions…</div>
        <button type="button" class="secondary weather-change-reasons-toggle info-icon-button" id="weatherChangeReasonsToggle" aria-label="Show weather stability reasons" title="More information" aria-controls="weatherChangeReasons" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M12 10.5v6"></path>
                <circle cx="12" cy="7.5" r=".8" class="info-icon-dot"></circle>
            </svg>
            <span id="weatherChangeReasonsButtonLabel">More information</span>
        </button>
        <div class="weather-change-reasons" id="weatherChangeReasons" aria-live="polite" hidden></div>
        <details class="algorithm-details">
            <summary>How Weather Stability is calculated</summary>
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
            <p>This is a separate directional assessment, not the Weather Stability score.</p>
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

    <section class="card satellite-card" aria-labelledby="satelliteTitle">
        <div class="section-heading">
            <div>
                <h2 id="satelliteTitle">MeteoSat-12</h2>
                <p class="muted">Updated every 10 minutes</p>
            </div>
        </div>
        <div class="satellite-player">
            <iframe
                src="https://www.youtube.com/embed/U3jRSL3y8Vc?autoplay=1&mute=1&rel=0&playsinline=1&controls=1&enablejsapi=1"
                title="EUMETSAT Earth view - Africa"
                allow="autoplay; encrypted-media; picture-in-picture"
                allowfullscreen></iframe>
        </div>
        <p class="satellite-caption">Live Earth imagery from EUMETSAT's Meteosat-12 Africa stream.</p>
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

    <section class="card appearance-card" aria-labelledby="appearanceTitle"><div class="section-heading"><div><h2 id="appearanceTitle">Appearance</h2><p class="muted">Choose how GaugeIQ looks on this device.</p></div><select id="themeSelect" class="theme-select" aria-label="Appearance"><option value="system">Follow device</option><option value="light">Light</option><option value="dark">Dark</option></select></div></section>

    <p id="status" class="status"></p>
    <p id="notificationEnabledNote" class="notification-enabled-note" hidden>GaugeIQ notifications are enabled for this device.</p>
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
<script src="js/app.js?v=20261015-trend-label-1"></script>
</body>
</html>
