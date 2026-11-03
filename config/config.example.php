<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'GaugeIQ',
        'base_url' => 'https://example.com/gaugeiq',
        'timezone' => 'Africa/Johannesburg',
    ],

    'database' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/../storage/gaugeiq.sqlite',

        // MySQL / MariaDB example:
        // 'driver' => 'mysql',
        // 'host' => '127.0.0.1',
        // 'port' => '3306',
        // 'name' => 'gaugeiq',
        // 'username' => 'gaugeiq_user',
        // 'password' => 'change-me',
        // 'charset' => 'utf8mb4',
    ],

    'pressure' => [
        'latitude' => -33.0153,
        'longitude' => 27.9116,
        'location_name' => 'East London',
        'threshold_hpa' => 3.0,
        'check_interval_minutes' => 15,
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
        'subject' => 'mailto:admin@example.com',
        'public_key' => '',
        'private_key' => '',
    ],

    // Optional shared Open-Meteo map cache. The defaults are 256 MiB and
    // GaugeIQ/storage/weather-map-cache; keep the directory outside public/.
    'weather_map_cache' => [
        // 'directory' => '/absolute/path/to/GaugeIQ/storage/weather-map-cache',
        'max_bytes' => 268435456,
    ],
];
