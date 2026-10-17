<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    header('Location: install/');
    exit;
}
$config = require $configPath;
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';

date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);

$locationName = (string)($config['pressure']['location_name'] ?? 'Your location');
$timezoneName = (string)($config['app']['timezone'] ?? 'UTC');
$coordinateSettings = $pdo->prepare("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('active_location_name', 'location_name', 'active_location_changed_at')");
$coordinateSettings->execute();
$settings = [];
foreach ($coordinateSettings->fetchAll() as $setting) {
    $settings[(string)$setting['key']] = (string)$setting['value'];
}
$locationName = $settings['active_location_name'] ?? $settings['location_name'] ?? $locationName;

$latest = null;
$changedAt = $settings['active_location_changed_at'] ?? '';
if ($changedAt !== '') {
    $stmt = $pdo->prepare("SELECT forecast_json, observed_at FROM gaugeiq_pressure_readings WHERE created_at >= ? AND (source IS NULL OR source = 'Open-Meteo') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$changedAt]);
    $latest = $stmt->fetch() ?: null;
}
if ($latest === null) {
    $latest = $pdo->query("SELECT forecast_json, observed_at FROM gaugeiq_pressure_readings WHERE source IS NULL OR source = 'Open-Meteo' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
}
$forecast = [];
if ($latest) {
    $decoded = json_decode((string)($latest['forecast_json'] ?? '[]'), true);
    $forecast = is_array($decoded) ? $decoded : [];
}
$today = (new DateTimeImmutable('now', new DateTimeZone($timezoneName)))->format('Y-m-d');
$fromDailyAlert = ($_GET['from'] ?? '') === 'daily-alert';
$requestedDate = isset($_GET['date']) && is_string($_GET['date']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : $today;
$forecast = array_values(array_filter($forecast, static fn($day): bool =>
    is_array($day) && is_string($day['date'] ?? null) && $day['date'] >= $today
));
$forecast = array_slice($forecast, 0, 7);
$forecastDates = array_column($forecast, 'date');
$highlightDate = in_array($requestedDate, $forecastDates, true) ? $requestedDate : $today;
if (!in_array($highlightDate, $forecastDates, true) && $forecast !== []) {
    $highlightDate = (string)$forecast[0]['date'];
}

$condition = static function (?int $code, bool $isDay = true): array {
    $clearIcon = $isDay ? 'clear-day' : 'clear-night';
    $partlyIcon = $isDay ? 'partly-cloudy-day' : 'partly-cloudy-night';
    return match (true) {
        $code === null => ['icon' => '—', 'slug' => null, 'label' => 'Unavailable'],
        $code === 0 => ['icon' => '☀️', 'slug' => $clearIcon, 'label' => 'Clear sky'],
        $code === 1 => ['icon' => '🌤️', 'slug' => $clearIcon, 'label' => 'Mainly clear'],
        $code === 2 => ['icon' => '⛅', 'slug' => $partlyIcon, 'label' => 'Partly cloudy'],
        $code === 3 => ['icon' => '☁️', 'slug' => 'overcast', 'label' => 'Overcast'],
        in_array($code, [45, 48], true) => ['icon' => '🌫️', 'slug' => 'fog', 'label' => 'Fog'],
        in_array($code, [51, 53, 55, 56, 57], true) => ['icon' => '🌦️', 'slug' => 'rain', 'label' => 'Drizzle or freezing drizzle'],
        in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true) => ['icon' => '🌧️', 'slug' => 'rain', 'label' => 'Rain or showers'],
        in_array($code, [71, 73, 75, 77, 85, 86], true) => ['icon' => '🌨️', 'slug' => 'snow', 'label' => 'Snow'],
        in_array($code, [95, 96, 99], true) => ['icon' => '⛈️', 'slug' => 'thunderstorm', 'label' => 'Thunderstorm'],
        default => ['icon' => '🌤️', 'slug' => $partlyIcon, 'label' => 'Mixed conditions'],
    };
};
$formatValue = static function (array $day, string $key, int $decimals = 0, string $suffix = ''): string {
    return isset($day[$key]) && is_numeric($day[$key]) ? number_format((float)$day[$key], $decimals) . $suffix : '—';
};
$formatHour = static function (?string $time): string {
    if (!$time) return '—';
    $timestamp = strtotime($time);
    return $timestamp === false ? $time : date('H:i', $timestamp);
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f3f4f6" id="themeColorMeta">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="192x192" href="/branding/icon-192.png">
<link rel="apple-touch-icon" href="/branding/apple-touch-icon.png">
<link rel="stylesheet" href="css/app.css?v=20261017-seven-day-forecast">
<title>7-day forecast · GaugeIQ</title>
</head>
<body>
<main class="shell forecast-detail-shell">
    <header class="forecast-detail-header">
        <div>
            <p class="eyebrow">GAUGEIQ WEATHER</p>
            <h1><?= $fromDailyAlert ? 'Your daily forecast' : '7-day forecast' ?></h1>
            <p class="muted forecast-location"><?= htmlspecialchars($locationName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="secondary forecast-back-link" href="index.php">← Dashboard</a>
    </header>

    <section class="card forecast-detail-intro<?= $fromDailyAlert ? ' forecast-from-notification' : '' ?>">
        <?php if ($fromDailyAlert): ?>
            <div class="forecast-notification-banner">
                <span class="forecast-notification-kicker">YOUR DAILY WEATHER ALERT</span>
                <h2>Here’s the forecast behind your notification</h2>
                <p>Explore today's conditions, the hours ahead and the details that matter for planning your day.</p>
            </div>
        <?php else: ?>
            <h2>Weather for the week ahead</h2>
            <p class="muted">Daily outlooks and hourly conditions, including temperature, rain, wind, humidity, cloud cover, UV and visibility.</p>
        <?php endif; ?>
        <?php if (!empty($latest['observed_at'])): ?>
            <p class="forecast-data-time">Forecast last saved with the weather update at <?= htmlspecialchars(date('d M Y, H:i', strtotime((string)$latest['observed_at'])), ENT_QUOTES, 'UTF-8') ?>.</p>
        <?php endif; ?>
    </section>

    <?php if ($forecast === []): ?>
        <section class="card">
            <h2>Forecast not available yet</h2>
            <p class="muted">GaugeIQ displays forecasts saved by its scheduled weather monitor. The seven-day forecast will appear after a successful scheduled update.</p>
        </section>
    <?php else: ?>
        <?php foreach ($forecast as $index => $day):
            $date = new DateTimeImmutable((string)$day['date'], new DateTimeZone($timezoneName));
            $dailyCondition = $condition(isset($day['weather_code']) && is_numeric($day['weather_code']) ? (int)$day['weather_code'] : null);
            $hours = is_array($day['hours'] ?? null) ? $day['hours'] : [];
        ?>
            <details class="card forecast-detail-day<?= $fromDailyAlert && (string)$day['date'] === $highlightDate ? ' forecast-alert-focus' : '' ?>" <?= ($index === 0 && !$fromDailyAlert) || ($fromDailyAlert && (string)$day['date'] === $highlightDate) ? 'open' : '' ?>>
                <summary>
                    <div class="forecast-detail-summary-main">
                        <span class="forecast-detail-date"><?= htmlspecialchars($date->format('l, j M'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="forecast-detail-condition">
                            <?php if (is_string($dailyCondition['slug'] ?? null)): ?>
                                <img src="weather-icons/<?= htmlspecialchars($dailyCondition['slug'], ENT_QUOTES, 'UTF-8') ?>.svg" alt="" width="42" height="42">
                            <?php else: ?>
                                <?= htmlspecialchars($dailyCondition['icon'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                            <span><?= htmlspecialchars($dailyCondition['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    </div>
                    <div class="forecast-detail-summary-temps">
                        <strong><?= $formatValue($day, 'temperature_max_c', 0, '°') ?></strong>
                        <span><?= $formatValue($day, 'temperature_min_c', 0, '°') ?></span>
                        <span class="forecast-detail-expand" aria-hidden="true">⌄</span>
                    </div>
                </summary>
                <div class="forecast-detail-content">
                    <div class="forecast-detail-metrics">
                        <div><span>Rain chance</span><strong><?= $formatValue($day, 'rain_probability_percent', 0, '%') ?></strong></div>
                        <div><span>Precipitation</span><strong><?= $formatValue($day, 'rain_mm', 1, ' mm') ?></strong></div>
                        <div><span>Max wind</span><strong><?= $formatValue($day, 'wind_speed_max_kmh', 0, ' km/h') ?></strong></div>
                        <div><span>Max gusts</span><strong><?= $formatValue($day, 'wind_gusts_max_kmh', 0, ' km/h') ?></strong></div>
                        <div><span>UV index</span><strong><?= $formatValue($day, 'uv_index_max', 1) ?></strong></div>
                        <div><span>Rain duration</span><strong><?= $formatValue($day, 'precipitation_hours', 0, ' h') ?></strong></div>
                        <div><span>Sunrise</span><strong><?= htmlspecialchars((string)($day['sunrise'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div><span>Sunset</span><strong><?= htmlspecialchars((string)($day['sunset'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    </div>

                    <?php if ($hours !== []): ?>
                        <h3 class="forecast-hourly-title">Hourly forecast</h3>
                        <div class="forecast-hourly-scroll" tabindex="0" aria-label="<?= htmlspecialchars('Hourly forecast for ' . $date->format('l, j M'), ENT_QUOTES, 'UTF-8') ?>">
                            <table class="forecast-hourly-table">
                                <thead><tr>
                                    <th>Time</th><th>Conditions</th><th>Temp.</th><th>Feels</th><th>Rain chance</th><th>Rain</th><th>Wind</th><th>Gusts</th><th>Direction</th><th>Humidity</th><th>Cloud</th><th>UV</th><th>Pressure</th><th>Visibility</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($hours as $hour):
                                    $hourCondition = $condition(isset($hour['weather_code']) && is_numeric($hour['weather_code']) ? (int)$hour['weather_code'] : null, !empty($hour['is_day']));
                                    $windDirection = is_numeric($hour['wind_direction_degrees'] ?? null) ? (int)round((float)$hour['wind_direction_degrees']) . '°' : '—';
                                    $visibility = is_numeric($hour['visibility_m'] ?? null) ? ((float)$hour['visibility_m'] >= 1000 ? number_format((float)$hour['visibility_m'] / 1000, 1) . ' km' : number_format((float)$hour['visibility_m'], 0) . ' m') : '—';
                                ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars((string)($hour['time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></th>
                                        <td><span class="forecast-hour-condition"><?php if (is_string($hourCondition['slug'] ?? null)): ?><img src="weather-icons/<?= htmlspecialchars($hourCondition['slug'], ENT_QUOTES, 'UTF-8') ?>.svg" alt="" width="24" height="24"><?php else: ?><?= htmlspecialchars($hourCondition['icon'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?><span><?= htmlspecialchars($hourCondition['label'], ENT_QUOTES, 'UTF-8') ?></span></span></td>
                                        <td><?= $formatValue($hour, 'temperature_c', 1, '°') ?></td>
                                        <td><?= $formatValue($hour, 'feels_like_c', 1, '°') ?></td>
                                        <td><?= $formatValue($hour, 'precipitation_probability_percent', 0, '%') ?></td>
                                        <td><?= $formatValue($hour, 'precipitation_mm', 1, ' mm') ?></td>
                                        <td><?= $formatValue($hour, 'wind_speed_kmh', 0, ' km/h') ?></td>
                                        <td><?= $formatValue($hour, 'wind_gusts_kmh', 0, ' km/h') ?></td>
                                        <td><?= htmlspecialchars($windDirection, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $formatValue($hour, 'humidity_percent', 0, '%') ?></td>
                                        <td><?= $formatValue($hour, 'cloud_cover_percent', 0, '%') ?></td>
                                        <td><?= $formatValue($hour, 'uv_index', 1) ?></td>
                                        <td><?= $formatValue($hour, 'pressure_hpa', 0, ' hPa') ?></td>
                                        <td><?= htmlspecialchars($visibility, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="muted forecast-hourly-unavailable">Hourly detail will appear after GaugeIQ stores a fresh forecast with hourly values.</p>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <p class="muted forecast-disclaimer">Forecasts are model guidance and can change as new weather data arrives. GaugeIQ refreshes stored forecasts through its scheduled monitor; opening this page does not trigger a weather fetch.</p>
    <?php endif; ?>
</main>
<script src="js/app.js?v=20261016-top-card-wvi"></script>
</body>
</html>
