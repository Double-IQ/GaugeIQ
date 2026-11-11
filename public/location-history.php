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
$secondLocationName = gaugeSetting($pdo, 'second_location_name', '') ?? '';
$secondLocationLatitude = gaugeSetting($pdo, 'second_location_latitude', '') ?? '';
$secondLocationLongitude = gaugeSetting($pdo, 'second_location_longitude', '') ?? '';
$secondLocationEnabled = gaugeSetting($pdo, 'second_location_enabled', '0') === '1';
$locationLatitude = gaugeSetting($pdo, 'location_latitude', (string)$config['pressure']['latitude']) ?? '';
$locationLongitude = gaugeSetting($pdo, 'location_longitude', (string)$config['pressure']['longitude']) ?? '';
$notice = isset($_GET['second_saved']) ? 'Second location saved. Independent monitoring will be enabled by the monitoring upgrade.' : (isset($_GET['saved']) ? 'Current location saved.' : '');
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
            if ((string)($_POST['action'] ?? '') === 'save_second_location') {
                saveGaugeSetting($pdo, 'second_location_name', $name);
                saveGaugeSetting($pdo, 'second_location_latitude', number_format($latitude, 6, '.', ''));
                saveGaugeSetting($pdo, 'second_location_longitude', number_format($longitude, 6, '.', ''));
                saveGaugeSetting($pdo, 'second_location_enabled', '1');
                header('Location: location-history.php?second_saved=1');
            } else {
                saveGaugeSetting($pdo, 'location_name', $name);
                saveGaugeSetting($pdo, 'location_latitude', number_format($latitude, 6, '.', ''));
                saveGaugeSetting($pdo, 'location_longitude', number_format($longitude, 6, '.', ''));
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
$historyTimezone = new DateTimeZone((string)($config['app']['timezone'] ?? 'UTC'));
$historicalStart = (new DateTimeImmutable('now', $historyTimezone))->modify('-1 year')->format('Y-m-d');
$historicalEnd = (new DateTimeImmutable('yesterday', $historyTimezone))->format('Y-m-d');
$weatherHistoryCountStmt = $pdo->prepare("SELECT COUNT(*) FROM gaugeiq_pressure_readings WHERE source = ? AND observed_at >= ? AND observed_at <= ?");
$weatherHistoryCountStmt->execute(['Open-Meteo Historical Weather', $historicalStart . 'T00:00', $historicalEnd . 'T23:59']);
$historicalWeatherCount = (int)$weatherHistoryCountStmt->fetchColumn();
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

<section class="card location-card" aria-labelledby="secondLocationTitle">
    <div class="section-heading"><div><h2 id="secondLocationTitle">Second monitoring location</h2><p class="muted">Add another place without replacing your current location. Each location can be identified separately.</p></div></div>
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
</section>

 <section class="card history-management-card" id="historicalDatasetStatus"
    data-weather-count="<?= $historicalWeatherCount ?>"
    aria-labelledby="openMeteoHistoryTitle">
    <div class="section-heading">
        <div>
            <h2 id="openMeteoHistoryTitle">Historical weather data</h2>
            <p class="muted">GaugeIQ automatically downloads the last year of Open-Meteo historical weather for the configured location. Existing weather records are kept.</p>
        </div>
    </div>
    <div class="local-weather-tools">
        <p><strong>Historical Weather:</strong> <span id="historicalWeatherStatus" role="status" aria-live="polite"><?= $historicalWeatherCount >= 5000 ? 'Available — ' . number_format($historicalWeatherCount) . ' hourly records found.' : 'Waiting for the automatic dashboard download to finish.' ?></span></p>
        <p id="historicalDatasetMessage" class="muted" role="status" aria-live="polite">Historical weather records are stored separately from current readings and future forecasts.</p>
    </div>
</section>
</main>
<script src="js/admin.js?v=20261031-admin-theme-v2" defer></script>
<script>
(() => {
 const locate = (latId, lonId, helpId) => {
  const lat=document.getElementById(latId), lon=document.getElementById(lonId), help=document.getElementById(helpId);
  if(!lat||!lon||!help)return;
  if(!navigator.geolocation){help.textContent='This browser does not support device location. Enter coordinates manually.';return;}
  help.textContent='Requesting device coordinates… Please allow location access if prompted.';
  navigator.geolocation.getCurrentPosition(position=>{
   const a=position.coords.latitude,b=position.coords.longitude;
   if(!Number.isFinite(a)||!Number.isFinite(b)||Math.abs(a)>90||Math.abs(b)>180){help.textContent='The device returned invalid coordinates. Enter them manually.';return;}
   lat.value=a.toFixed(6);lon.value=b.toFixed(6);
   help.textContent='Coordinates found (±'+Math.round(position.coords.accuracy)+' m accuracy). Check the location name, then save.';
  },error=>{
   const messages={1:'Location permission was denied. Allow location access for this site in browser settings, then try again.',2:'Your device could not determine its location. Check that Location Services are enabled, then try again.',3:'The location request timed out. Try again with a clearer GPS signal.'};
   help.textContent=messages[error.code]||'Could not determine device location. Enter coordinates manually.';
  },{enableHighAccuracy:true,timeout:20000,maximumAge:0});
 };
 document.getElementById('useCurrentLocation')?.addEventListener('click',()=>locate('location_latitude','location_longitude','locationHelp'));
 document.getElementById('useSecondDeviceLocation')?.addEventListener('click',()=>locate('second_location_latitude','second_location_longitude','secondLocationHelp'));
})();
</script>
</body></html>
