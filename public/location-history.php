<?php
declare(strict_types=1);
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function gaugeSetting(PDO $pdo, string $key, ?string $fallback = null): ?string {
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $fallback : (string)$value;
}
function saveGaugeSetting(PDO $pdo, string $key, string $value): void {
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    } else {
        $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    }
    $stmt->execute([$key, $value]);
}
$locationName = gaugeSetting($pdo, 'location_name', (string)$config['pressure']['location_name']) ?? '';
$locationLatitude = gaugeSetting($pdo, 'location_latitude', (string)$config['pressure']['latitude']) ?? '';
$locationLongitude = gaugeSetting($pdo, 'location_longitude', (string)$config['pressure']['longitude']) ?? '';
$notice = isset($_GET['saved']) ? 'Current location saved.' : '';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $name = trim((string)($_POST['location_name'] ?? ''));
            $latitudeInput = trim((string)($_POST['location_latitude'] ?? ''));
            $longitudeInput = trim((string)($_POST['location_longitude'] ?? ''));
            if ($name === '') throw new InvalidArgumentException('Please enter a name for the location.');
            if ($latitudeInput === '' || !is_numeric($latitudeInput)) throw new InvalidArgumentException('Enter a valid latitude for the configured location.');
            if ($longitudeInput === '' || !is_numeric($longitudeInput)) throw new InvalidArgumentException('Enter a valid longitude for the configured location.');
            $latitude = (float)$latitudeInput;
            $longitude = (float)$longitudeInput;
            if ($latitude < -90 || $latitude > 90) throw new InvalidArgumentException('Latitude must be between -90 and 90.');
            if ($longitude < -180 || $longitude > 180) throw new InvalidArgumentException('Longitude must be between -180 and 180.');
            saveGaugeSetting($pdo, 'location_name', $name);
            saveGaugeSetting($pdo, 'location_latitude', number_format($latitude, 6, '.', ''));
            saveGaugeSetting($pdo, 'location_longitude', number_format($longitude, 6, '.', ''));
            header('Location: location-history.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $locationName = trim((string)($_POST['location_name'] ?? $locationName));
    $locationLatitude = trim((string)($_POST['location_latitude'] ?? $locationLatitude));
    $locationLongitude = trim((string)($_POST['location_longitude'] ?? $locationLongitude));
}
$historyTimezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
$historicalStart = (new DateTimeImmutable('now', $historyTimezone))->modify('-1 year')->format('Y-m-d');
$historicalEnd = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$weatherHistoryCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE source = ? AND observed_at >= ? AND observed_at <= ?");
$weatherHistoryCountStmt->execute(['Open-Meteo Historical Weather', $historicalStart . 'T00:00', $historicalEnd . 'T23:59']);
$historicalWeatherCount = (int)$weatherHistoryCountStmt->fetchColumn();
$historicalForecastCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gaugeiq_rain_forecasts WHERE forecast_at >= ? AND forecast_at <= ?");
$historicalForecastCountStmt->execute([$historicalStart . 'T00:00', $historicalEnd . 'T23:59']);
$historicalForecastCount = (int)$historicalForecastCountStmt->fetchColumn();
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css?v=20261031-silver-light-mode-v1">
<link rel="stylesheet" href="css/alerts.css?v=20261029-notification-devices">
<title>GaugeIQ — Location &amp; Historical Data</title>
</head><body>
<main class="shell admin-shell">
<header class="admin-header">
<div><p class="eyebrow">GAUGЕIQ ADMIN</p><h1>Location &amp; historical data</h1><p class="muted">Configure the location used by GaugeIQ and check the imported historical datasets.</p></div>
<a class="secondary button-link" href="admin.php">Back to Admin</a>
</header>
<?php if ($notice): ?><div class="notice" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<section class="card location-card">
    <div class="section-heading">
        <div>
            <h2>Current location</h2>
            <p class="muted">Change the location GaugeIQ uses for weather data and the name shown on the dashboard.</p>
        </div>
    </div>
    <form method="post" class="location-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_location">
        <div class="location-grid">
            <div class="full">
                <label for="location_name">Location name</label>
                <input id="location_name" name="location_name" type="text" maxlength="120" value="<?= h($locationName) ?>" placeholder="Home">
            </div>
            <div>
                <label for="location_latitude">Latitude</label>
                <input id="location_latitude" name="location_latitude" type="number" min="-90" max="90" step="0.000001" value="<?= h($locationLatitude) ?>" required>
            </div>
            <div>
                <label for="location_longitude">Longitude</label>
                <input id="location_longitude" name="location_longitude" type="number" min="-180" max="180" step="0.000001" value="<?= h($locationLongitude) ?>" required>
            </div>
        </div>
        <div class="location-actions">
            <button type="button" class="secondary" id="useCurrentLocation">Use device location</button>
            <button type="submit">Save location</button>
        </div>
        <p id="locationHelp" class="alert-help muted">Changing the location affects the next weather check. Existing historical readings remain unchanged.</p>
    </form>
</section>

 <section class="card history-management-card" id="historicalDatasetStatus"
    data-weather-count="<?= $historicalWeatherCount ?>"
    data-forecast-count="<?= $historicalForecastCount ?>"
    aria-labelledby="openMeteoHistoryTitle">
    <div class="section-heading">
        <div>
            <h2 id="openMeteoHistoryTitle">Historical weather datasets</h2>
            <p class="muted">GaugeIQ automatically downloads the last year of Open-Meteo historical weather and historical forecasts for the configured location. Your existing weather records are kept; forecast probabilities are stored separately.</p>
        </div>
    </div>
    <div class="local-weather-tools">
        <p><strong>Historical Weather:</strong> <span id="historicalWeatherStatus" role="status" aria-live="polite"><?= $historicalWeatherCount >= 5000 ? 'Available — ' . number_format($historicalWeatherCount) . ' hourly records found.' : 'Waiting for the automatic dashboard download to finish.' ?></span></p>
        <p><strong>Historical Forecast:</strong> <span id="historicalForecastStatus" role="status" aria-live="polite"><?= $historicalForecastCount >= 5000 ? 'Available — ' . number_format($historicalForecastCount) . ' hourly forecasts found.' : 'Waiting for the automatic dashboard download to finish.' ?></span></p>
        <p id="historicalDatasetMessage" class="muted" role="status" aria-live="polite">Forecast probabilities are kept in a separate dataset so they cannot replace historical weather observations.</p>
    </div>
</section>
</main>
<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script>
document.getElementById('useCurrentLocation')?.addEventListener('click', () => {
    const help = document.getElementById('locationHelp');
    if (!navigator.geolocation) {
        if (help) help.textContent = 'This browser does not provide location services.';
        return;
    }
    if (help) help.textContent = 'Requesting your device location…';
    navigator.geolocation.getCurrentPosition(
        (position) => {
            document.getElementById('location_latitude').value = position.coords.latitude.toFixed(6);
            document.getElementById('location_longitude').value = position.coords.longitude.toFixed(6);
            if (help) help.textContent = 'Device coordinates loaded. Enter the location name, then save.';
        },
        (error) => {
            if (help) help.textContent = error.code === 1
                ? 'Location access was denied. You can enter the coordinates manually.'
                : 'Could not determine your device location. You can enter the coordinates manually.';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 300000 }
    );
});
</script>
</body></html>
