<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configDir = $root . '/config';
$configPath = $configDir . '/local.php';
$lockPath = $configDir . '/installed.lock';

require_once $root . '/app/Database.php';
require_once $root . '/app/Schema.php';

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

header('Cache-Control: no-store');

if (is_file($lockPath)) {
    http_response_code(404);
    exit('GaugeIQ is already installed.');
}

$errors = [];
$success = false;
$defaults = [
    'location_name' => 'My location',
    'latitude' => '',
    'longitude' => '',
    'admin_username' => 'admin',
    'admin_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($defaults as $key => $default) {
        $defaults[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!filter_var($defaults['latitude'], FILTER_VALIDATE_FLOAT) ||
        (float)$defaults['latitude'] < -90 || (float)$defaults['latitude'] > 90) {
        $errors[] = 'Enter a valid latitude.';
    }

    if (!filter_var($defaults['longitude'], FILTER_VALIDATE_FLOAT) ||
        (float)$defaults['longitude'] < -180 || (float)$defaults['longitude'] > 180) {
        $errors[] = 'Enter a valid longitude.';
    }

    if ($defaults['admin_username'] === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $defaults['admin_username'])) {
        $errors[] = 'Choose an administrator username using 3–64 letters, numbers, dots, underscores, or hyphens.';
    }

    if (strlen($defaults['admin_password']) < 12) {
        $errors[] = 'Administrator password must be at least 12 characters long.';
    }

    if (!$errors) {
        try {
            if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
                throw new RuntimeException('GaugeIQ Web Push dependencies are missing. The installation package must include vendor/ or Composer dependencies must be installed first.');
            }

            $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();

            $config = [
                'app' => [
                    'name' => 'GaugeIQ',
                    'base_url' => rtrim(
                        'https://' . ($_SERVER['HTTP_HOST'] ?? '') .
                        rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'),
                        '/'
                    ),
                    'timezone' => 'Africa/Johannesburg',
                ],
                'database' => [
                    'driver' => 'sqlite',
                    'path' => $root . '/storage/gaugeiq.sqlite',
                ],
                'pressure' => [
                    'latitude' => (float)$defaults['latitude'],
                    'longitude' => (float)$defaults['longitude'],
                    'location_name' => $defaults['location_name'] ?: 'My location',
                    'threshold_hpa' => 3.0,
                    'check_interval_minutes' => 30,
                ],
                'humidity' => [
                    'enabled' => true,
                    'mode' => 'change',
                    'threshold_percent' => 10.0,
                ],
                'wind' => [
                    'enabled' => true,
                    'speed_mode' => 'above',
                    'speed_threshold_kmh' => 40.0,
                    'direction_change_degrees' => 45.0,
                    'specific_directions' => [],
                ],
                'push' => [
                    'subject' => '',
                    'public_key' => $vapid['publicKey'],
                    'private_key' => $vapid['privateKey'],
                ],
            ];

            $config['push']['subject'] = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            $db = new Database($config);
            migrateDatabase($db->pdo());

            $adminStmt = $db->pdo()->prepare('INSERT INTO gaugeiq_admin_users (username, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)');
            $now = gmdate('c');
            $adminStmt->execute([$defaults['admin_username'], password_hash($defaults['admin_password'], PASSWORD_DEFAULT), $now, $now]);

            if (!is_dir($configDir) && !mkdir($configDir, 0750, true) && !is_dir($configDir)) {
                throw new RuntimeException('Unable to create the configuration directory.');
            }

            $php = "<?php

declare(strict_types=1);

return " . var_export($config, true) . ";
";
            if (is_file($configPath)) {
                throw new RuntimeException('config/local.php already exists. The installer will not overwrite it.');
            }

            if (file_put_contents($configPath, $php, LOCK_EX) === false) {
                throw new RuntimeException('GaugeIQ could not write config/local.php.');
            }

            $lockContents = "GaugeIQ installed " . gmdate('c') . "
";
            if (file_put_contents($lockPath, $lockContents, LOCK_EX) === false) {
                @unlink($configPath);
                throw new RuntimeException('GaugeIQ could not lock the installer.');
            }

            $success = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title>Set up GaugeIQ</title>
<link rel="stylesheet" href="install.css">
</head>
<body>
<main class="shell">
    <p class="step">GAUGЕIQ SETUP</p>
    <h1>Welcome to GaugeIQ</h1>
    <p class="muted">Let's get your weather monitoring and notifications ready.</p>

    <?php if ($success): ?>
        <section class="card success">
            <strong>GaugeIQ is installed.</strong>
            <p>Your database and configuration are ready.</p>
            <p>Open the GaugeIQ home page and enable notifications on your iPhone.</p>
            <a class="button" href="../index.php">Open GaugeIQ</a>
        </section>
    <?php else: ?>
        <?php if ($errors): ?>
            <section class="card error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <form method="post" class="card">
            <p class="step">1 · LOCATION</p>
            <label for="location_name">Location name</label>
            <input id="location_name" name="location_name" value="<?= htmlspecialchars($defaults['location_name'], ENT_QUOTES) ?>" placeholder="My home">

            <div class="grid">
                <div>
                    <label for="latitude">Latitude</label>
                    <input id="latitude" name="latitude" value="<?= htmlspecialchars($defaults['latitude'], ENT_QUOTES) ?>" inputmode="decimal" required>
                </div>
                <div>
                    <label for="longitude">Longitude</label>
                    <input id="longitude" name="longitude" value="<?= htmlspecialchars($defaults['longitude'], ENT_QUOTES) ?>" inputmode="decimal" required>
                </div>
            </div>

            <button type="button" class="secondary" id="locationButton">Use my current location</button>

            <section class="history-option" aria-labelledby="noaaHeading">
                <p class="step" style="margin-top:28px">2 · HISTORICAL WEATHER DATA</p>
                <h2 id="noaaHeading">Download a historical file from NOAA</h2>
                <p class="muted">Open NOAA's official data search to choose a station, dataset, and date range, then download the historical data file.</p>
                <a class="button secondary-link" href="https://www.ncei.noaa.gov/access/search/data-search" target="_blank" rel="noopener noreferrer">Open NOAA historical data search ↗</a>
                <p class="muted">This opens NOAA in a new tab. Downloading a file does not automatically import it into GaugeIQ.</p>
            </section>

            <p class="step" style="margin-top:28px">3 · ADMINISTRATOR</p>
            <label for="admin_username">Administrator username</label>
            <input id="admin_username" name="admin_username" value="<?= htmlspecialchars($defaults['admin_username'], ENT_QUOTES) ?>" autocomplete="username" required>
            <label for="admin_password">Administrator password</label>
            <input id="admin_password" name="admin_password" type="password" autocomplete="new-password" minlength="12" required>
            <p class="muted">Use at least 12 characters. This account protects administration and future update controls.</p>

            <p class="step" style="margin-top:28px">4 · DATABASE</p>
            <p class="muted">GaugeIQ will use SQLite. The database file is created automatically during installation.</p>

            <button type="submit">Install GaugeIQ</button>
        </form>
    <?php endif; ?>
</main>
<script src="install.js" defer></script>
</body>
</html>
