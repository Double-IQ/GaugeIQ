<?php
declare(strict_types=1);

// Temporary, standalone ECMWF rainfall decoder diagnostic.
// This deliberately runs only the rainfall outlook, not the regular monitor.
$config = require __DIR__ . '/../config/local.php';
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/Schema.php';
require_once __DIR__ . '/../app/WeatherMapRainfallNowcastService.php';

date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $service = new WeatherMapRainfallNowcastService($pdo, $config);
    // Durban is used only for this diagnostic run; do not persist its result
    // or alter GaugeIQ's configured location/dashboard outlook.
    $testLocation = ['latitude' => -29.8587, 'longitude' => 31.0218];
    $result = $service->refreshIfDue(0, true, $testLocation, false);
    $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
    $encoding = is_array($payload['encoding'] ?? null) ? $payload['encoding'] : [];

    printf(
        "ECMWF rainfall diagnostic (Durban): %s [lat=%.4f, lon=%.4f, frames=%d, cells=%d, type=%d, compression=%d, scale=%s, offset=%s]\n",
        (string)($result['message'] ?? 'No result message'),
        (float)$testLocation['latitude'],
        (float)$testLocation['longitude'],
        count($payload['frames'] ?? []),
        (int)($payload['grid_cells_sampled'] ?? 0),
        (int)($encoding['data_type'] ?? -1),
        (int)($encoding['compression_type'] ?? -1),
        (string)($encoding['scale_factor'] ?? 'n/a'),
        (string)($encoding['add_offset'] ?? 'n/a')
    );

    foreach (($payload['frames'] ?? []) as $frame) {
        printf(
            "Frame %s: local=%s mm, max=%s mm, wet_cells=%d, nearest_rain=%s km\n",
            (string)($frame['time'] ?? 'unknown'),
            (string)($frame['local_precipitation_mm'] ?? 'n/a'),
            (string)($frame['maximum_precipitation_mm'] ?? 'n/a'),
            (int)($frame['wet_cells'] ?? 0),
            ($frame['nearest_rain_km'] ?? null) === null ? 'none' : (string)$frame['nearest_rain_km']
        );
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ECMWF rainfall diagnostic failed: " . $error->getMessage() . "\n");
    exit(1);
}
