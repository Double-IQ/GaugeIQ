<?php
declare(strict_types=1);
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/LocationManagementService.php';
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
$secondLocationName = gaugeSetting($pdo, 'second_location_name', '') ?? '';
$secondLocationLatitude = gaugeSetting($pdo, 'second_location_latitude', '') ?? '';
$secondLocationLongitude = gaugeSetting($pdo, 'second_location_longitude', '') ?? '';
$secondLocationEnabled = gaugeSetting($pdo, 'second_location_enabled', '0') === '1';
$locationLatitude = gaugeSetting($pdo, 'location_latitude', (string)$config['pressure']['latitude']) ?? '';
$locationLongitude = gaugeSetting($pdo, 'location_longitude', (string)$config['pressure']['longitude']) ?? '';
$notice = isset($_GET['primary_deleted']) ? 'The previous primary location and its data were deleted. The second location and its complete history have been promoted to the primary location.' : (isset($_GET['second_deleted']) ? 'Second location and its associated monitoring data were deleted.' : (isset($_GET['second_saved']) ? 'Second location saved. Independent monitoring will begin on the next scheduled check.' : (isset($_GET['saved']) ? 'Current location saved.' : '')));
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'delete_location') {
                $deleteLocationId = (int)($_POST['location_id'] ?? 0);
                if ($deleteLocationId === 2 && !$secondLocationEnabled) {
                    throw new InvalidArgumentException('There is no second location to delete.');
                }
                if ($deleteLocationId === 1 && !$secondLocationEnabled) {
                    throw new InvalidArgumentException('Configure a second location before deleting the primary location.');
                }
                $promoted = LocationManagementService::deleteLocation($pdo, $deleteLocationId);
                header('Location: location-history.php?' . ($promoted ? 'primary_deleted=1' : 'second_deleted=1'));
                exit;
            }

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
            if ($action === 'save_second_location') {
                $newLatitude = number_format($latitude, 6, '.', '');
                $newLongitude = number_format($longitude, 6, '.', '');
                $secondCoordinatesChanged = $secondLocationLatitude === '' || $secondLocationLongitude === ''
                    || abs((float)$secondLocationLatitude - $latitude) > 0.000001
                    || abs((float)$secondLocationLongitude - $longitude) > 0.000001;
                if ($secondCoordinatesChanged) {
                    saveGaugeSetting($pdo, 'second_location_changed_at', gmdate('c'));
                    foreach ([
                        'location_timezone_location_2',
                        'recent_backfill_last_success_at_location_2',
                        'historical_weather_import_completed_at_location_2',
                        'monitor_last_success_at_location_2',
                        'monitor_last_error_location_2',
                        'daily_forecast_alert_last_evaluated_date_location_2',
                        'daily_forecast_alert_last_status_location_2',
                        'daily_forecast_alert_last_error_location_2',
                        'recent_backfill_last_error_location_2',
                        'weather_map_rainfall_nowcast_error_location_2',
                        'ecmwf_rainfall_alert_last_status_location_2',
                        'ecmwf_rainfall_alert_last_error_location_2',
                        'weather_map_rainfall_nowcast_location_2',
                        'weather_map_rainfall_nowcast_updated_at_location_2',
                        'ecmwf_rainfall_alert_last_sent_at_location_2',
                    ] as $resetKey) {
                        saveGaugeSetting($pdo, $resetKey, '');
                    }
                    $pdo->prepare('DELETE FROM gaugeiq_rain_forecasts WHERE location_id = 2')->execute();
                }
                saveGaugeSetting($pdo, 'second_location_name', $name);
                saveGaugeSetting($pdo, 'second_location_latitude', $newLatitude);
                saveGaugeSetting($pdo, 'second_location_longitude', $newLongitude);
                saveGaugeSetting($pdo, 'second_location_enabled', '1');
                header('Location: location-history.php?second_saved=1');
            } else {
                $newLatitude = number_format($latitude, 6, '.', '');
                $newLongitude = number_format($longitude, 6, '.', '');
                $primaryCoordinatesChanged = $locationLatitude !== '' && $locationLongitude !== ''
                    && (abs((float)$locationLatitude - $latitude) > 0.000001
                        || abs((float)$locationLongitude - $longitude) > 0.000001);
                if ($primaryCoordinatesChanged) {
                    saveGaugeSetting($pdo, 'active_location_changed_at', gmdate('c'));
                    foreach ([
                        'location_timezone',
                        'recent_backfill_last_success_at',
                        'historical_weather_import_completed_at_location_1',
                        'monitor_last_success_at',
                        'monitor_last_error',
                        'daily_forecast_alert_last_evaluated_date',
                        'daily_forecast_alert_last_status',
                        'daily_forecast_alert_last_error',
                        'recent_backfill_last_error',
                        'weather_map_rainfall_nowcast_error',
                        'ecmwf_rainfall_alert_last_status',
                        'ecmwf_rainfall_alert_last_error',
                        'weather_map_rainfall_nowcast',
                        'weather_map_rainfall_nowcast_updated_at',
                        'ecmwf_rainfall_alert_last_sent_at',
                    ] as $resetKey) {
                        saveGaugeSetting($pdo, $resetKey, '');
                    }
                    $pdo->prepare('DELETE FROM gaugeiq_rain_forecasts WHERE location_id = 1')->execute();
                }
                saveGaugeSetting($pdo, 'location_name', $name);
                saveGaugeSetting($pdo, 'location_latitude', $newLatitude);
                saveGaugeSetting($pdo, 'location_longitude', $newLongitude);
                header('Location: location-history.php?saved=1');
            }
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $locationName = trim((string)($_POST['location_name'] ?? $locationName));
    $locationLatitude = trim((string)($_POST['location_latitude'] ?? $locationLatitude));
    $locationLongitude = trim((string)($_POST['location_longitude'] ?? $locationLongitude));
}
$historyPanels = [];
$historyLocations = [
    ['id' => 1, 'name' => $locationName, 'timezone_key' => 'location_timezone', 'changed_key' => 'active_location_changed_at'],
];
if ($secondLocationEnabled) {
    $historyLocations[] = ['id' => 2, 'name' => $secondLocationName, 'timezone_key' => 'location_timezone_location_2', 'changed_key' => 'second_location_changed_at'];
}
foreach ($historyLocations as $historyLocation) {
    $timezoneName = gaugeSetting($pdo, $historyLocation['timezone_key'], '') ?? '';
    if ($timezoneName === '') $timezoneName = (string)($config['app']['timezone'] ?? 'UTC');
    try { $locationTimezone = new DateTimeZone($timezoneName); } catch (Throwable) { $locationTimezone = new DateTimeZone('UTC'); }
    $historicalStart = (new DateTimeImmutable('now', $locationTimezone))->modify('-1 month')->format('Y-m-d');
    $historicalEnd = (new DateTimeImmutable('yesterday', $locationTimezone))->format('Y-m-d');
    $changedAt = gaugeSetting($pdo, $historyLocation['changed_key'], '') ?? '';
    $where = 'location_id = ? AND source = ? AND observed_at >= ? AND observed_at <= ?';
    $params = [$historyLocation['id'], 'Open-Meteo Historical Weather', $historicalStart . 'T00:00', $historicalEnd . 'T23:59'];
    if ($changedAt !== '') { $where .= ' AND created_at >= ?'; $params[] = $changedAt; }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE ' . $where);
    $countStmt->execute($params);
    $latestStmt = $pdo->prepare('SELECT MAX(observed_at) FROM gaugeiq_pressure_readings WHERE ' . $where);
    $latestStmt->execute($params);
    $historyPanels[] = [
        'id' => $historyLocation['id'],
        'name' => $historyLocation['name'],
        'start' => $historicalStart,
        'end' => $historicalEnd,
        'count' => (int)$countStmt->fetchColumn(),
        'latest' => (string)($latestStmt->fetchColumn() ?: ''),
        'import_completed' => (gaugeSetting($pdo, 'historical_weather_import_completed_at_location_' . $historyLocation['id'], '') ?? '') !== '',
    ];
}
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
<div><p class="eyebrow">GAUGЕIQ ADMIN</p><h1>Location &amp; historical data</h1><p class="muted">Configure GaugeIQ's monitored locations and check historical weather data.</p></div>
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
    <?php if ($secondLocationEnabled): ?>
    <form method="post" class="location-actions" onsubmit="return window.confirm('Permanently delete ' + <?= json_encode($locationName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + ' and all of its stored readings, historical downloads, rainfall forecasts and alert history? The second location will be promoted and its data preserved.');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete_location">
        <input type="hidden" name="location_id" value="1">
        <button type="submit" class="secondary">Delete current location</button>
    </form>
    <?php endif; ?>
</section>

<section class="card location-card" aria-labelledby="secondLocationTitle">
    <div class="section-heading"><div><h2 id="secondLocationTitle">Second monitoring location</h2><p class="muted">Add another place without replacing your current location. Each location keeps separate readings, history, rainfall outlooks and alert rules. Push notifications identify which location triggered them.</p></div></div>
    <form method="post" class="location-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_second_location">
        <div class="location-grid">
            <div class="full"><label for="second_location_name">Location name</label><input id="second_location_name" name="location_name" type="text" maxlength="120" value="<?= h($secondLocationName) ?>" placeholder="Holiday home" required></div>
            <div><label for="second_location_latitude">Latitude</label><input id="second_location_latitude" name="location_latitude" type="number" min="-90" max="90" step="0.000001" value="<?= h($secondLocationLatitude) ?>" required></div>
            <div><label for="second_location_longitude">Longitude</label><input id="second_location_longitude" name="location_longitude" type="number" min="-180" max="180" step="0.000001" value="<?= h($secondLocationLongitude) ?>" required></div>
        </div>
        <div class="location-actions"><button type="button" class="secondary" id="useSecondDeviceLocation">Use device location</button><button type="submit">Save second location</button></div>
        <p id="secondLocationHelp" class="alert-help muted"><?= $secondLocationEnabled ? 'Second location saved.' : 'No second location has been saved yet.' ?> Saving this location does not change your current location.</p>
    </form>
    <?php if ($secondLocationEnabled): ?>
    <form method="post" class="location-actions" onsubmit="return window.confirm('Permanently delete ' + <?= json_encode($secondLocationName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> + ' and all of its stored readings, historical downloads, rainfall forecasts and alert history?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete_location">
        <input type="hidden" name="location_id" value="2">
        <button type="submit" class="secondary">Delete second location</button>
    </form>
    <?php endif; ?>
</section>

<?php foreach ($historyPanels as $historyPanel): ?>
<section class="card history-management-card" id="historicalDatasetStatus<?= (int)$historyPanel['id'] ?>"
    data-location-id="<?= (int)$historyPanel['id'] ?>"
    data-csrf="<?= h($csrf) ?>"
    data-start="<?= h($historyPanel['start']) ?>"
    data-end="<?= h($historyPanel['end']) ?>"
    data-weather-count="<?= (int)$historyPanel['count'] ?>"
    data-weather-latest="<?= h($historyPanel['latest']) ?>"
    data-import-completed="<?= $historyPanel['import_completed'] ? '1' : '0' ?>"
    aria-labelledby="openMeteoHistoryTitle<?= (int)$historyPanel['id'] ?>">
    <div class="section-heading">
        <div>
            <h2 id="openMeteoHistoryTitle<?= (int)$historyPanel['id'] ?>">Historical weather data — <?= h($historyPanel['name']) ?></h2>
            <p class="muted">GaugeIQ imports one rolling calendar month of Open-Meteo historical weather once for this location. Existing weather records are kept.</p>
        </div>
    </div>
    <div class="local-weather-tools">
        <p><strong>Historical Weather:</strong> <span id="historicalWeatherStatus<?= (int)$historyPanel['id'] ?>" role="status" aria-live="polite"><?= (int)$historyPanel['count'] > 0 ? 'Checking hourly coverage…' : 'Waiting for the automatic historical download to start.' ?></span></p>
        <p id="historicalDatasetMessage<?= (int)$historyPanel['id'] ?>" class="muted" role="status" aria-live="polite">Historical weather records are stored separately from current readings and future forecasts.</p>
    </div>
</section>
<?php endforeach; ?>
</main>
<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script src="js/location-geolocation.js?v=20261111-location-fix" defer></script>
<script src="js/openmeteo-history.js?v=20261112-location-history-fix" defer></script>
</body></html>
