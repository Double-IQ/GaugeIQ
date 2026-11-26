<?php
declare(strict_types=1);

/**
 * Hourly GaugeIQ server preparation.
 *
 * Refreshes the ECMWF frame schedule when stale, prepares server-side decoded
 * weather-map caches for both configured regions, and refreshes Moon/Tide
 * snapshots only when their stored snapshots are older than one hour.
 *
 * Run from CLI only. A lock prevents overlapping hourly runs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "GaugeIQ hourly preparation: config/local.php is missing.\n");
    exit(1);
}
$config = require $configPath;

require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/Schema.php';
require_once __DIR__ . '/../app/WeatherMapCacheService.php';
require_once __DIR__ . '/../app/MoonTideSnapshotService.php';

date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));

$storageDirectory = (string)($config['weather_map_cache']['directory'] ?? (__DIR__ . '/../storage/weather-map-cache'));
$lockDirectory = dirname($storageDirectory);
if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0750, true) && !is_dir($lockDirectory)) {
    fwrite(STDERR, "GaugeIQ hourly preparation: cannot create lock directory.\n");
    exit(1);
}
$lockPath = rtrim($lockDirectory, '/') . '/hourly-weather-preparation.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo '[' . gmdate('c') . '] Another hourly preparation is running; this run is skipped.\n';
    if (is_resource($lockHandle)) fclose($lockHandle);
    exit(0);
}

$log = static function (string $message): void {
    echo '[' . gmdate('c') . '] ' . $message . PHP_EOL;
};
$failures = [];

try {
    $pdo = (new Database($config))->pdo();
    migrateDatabase($pdo);
    $weatherMap = new WeatherMapCacheService($pdo, $config);

    // Refresh the six-frame schedule (4 past + 2 future) if its hourly TTL
    // has elapsed. A failed refresh leaves the previous schedule intact.
    try {
        $changed = $weatherMap->refreshScheduleIfDue(3600);
        $log('Weather-map schedule: ' . ($changed ? 'refreshed' : 'still current'));
    } catch (Throwable $error) {
        $failures[] = 'Schedule: ' . $error->getMessage();
        $log('ERROR schedule refresh: ' . $error->getMessage());
    }

    // Prepare each configured region independently. This is cache-aware and
    // only downloads/decodes missing, invalid, or newly scheduled frame data.
    // Force a cache check each cron run; the cache itself prevents redundant work.
    try {
        $result = $weatherMap->prefetchRegionalFramesIfDue(0);
        $log('Regional weather-map preparation: ' . ($result['output'] ?? 'completed'));
    } catch (Throwable $error) {
        $failures[] = 'Regional prefetch: ' . $error->getMessage();
        $log('ERROR regional prefetch: ' . $error->getMessage());
    }

    // Astronomy and tide data are server-owned snapshots. Reuse snapshots
    // under one hour old and refresh stale ones once per cron run.
    $moonTide = new MoonTideSnapshotService($pdo, $config);
    foreach ([1, 2] as $locationId) {
        try {
            $snapshot = $moonTide->refreshIfDue($locationId, 3600);
            $generatedAt = (string)($snapshot['generatedAt'] ?? 'unknown');
            $log('Moon/Tide snapshot Location ' . $locationId . ': current (generated ' . $generatedAt . ')');
        } catch (Throwable $error) {
            $failures[] = 'Moon/Tide Location ' . $locationId . ': ' . $error->getMessage();
            $log('ERROR Moon/Tide Location ' . $locationId . ': ' . $error->getMessage());
        }
    }

    if ($failures !== []) {
        $log('Completed with ' . count($failures) . ' task failure(s).');
        exit(1);
    }
    $log('Hourly preparation completed successfully.');
} catch (Throwable $error) {
    $log('FATAL hourly preparation: ' . $error->getMessage());
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
