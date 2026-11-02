<?php
declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('GaugeIQ must be installed before the weather-map test can run.');
}

$config = require $configPath;
$latitude = (float)($config['pressure']['latitude'] ?? -32.986992);
$longitude = (float)($config['pressure']['longitude'] ?? 27.932365);
$locationName = (string)($config['pressure']['location_name'] ?? 'Configured location');

try {
    require_once dirname(__DIR__, 2) . '/app/Database.php';
    $pdo = (new Database($config))->pdo();
    $stmt = $pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('active_location_latitude', 'active_location_longitude', 'active_location_name', 'location_latitude', 'location_longitude', 'location_name')");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[(string)$row['key']] = (string)$row['value'];
    }
    $latitude = (float)($settings['active_location_latitude'] ?? $settings['location_latitude'] ?? $latitude);
    $longitude = (float)($settings['active_location_longitude'] ?? $settings['location_longitude'] ?? $longitude);
    $locationName = $settings['active_location_name'] ?? $settings['location_name'] ?? $locationName;
} catch (Throwable $error) {
    // Keep the configured installation coordinates if the settings lookup is unavailable.
}

$mapConfig = json_encode([
    'latitude' => $latitude,
    'longitude' => $longitude,
    'locationName' => $locationName,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#061648">
    <title>GaugeIQ Weather Map Test</title>
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.css">
    <style>
        :root { color-scheme: dark; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif; background: #061648; color: #f8fafc; }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { display: flex; flex-direction: column; background: #061648; }
        .toolbar { z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: max(12px, env(safe-area-inset-top)) 14px 12px; background: #081d55; border-bottom: 1px solid rgba(148,163,184,.25); }
        .heading { min-width: 0; }
        h1 { margin: 0; font-size: 17px; font-weight: 750; }
        .location { margin-top: 3px; color: #b9c9e8; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .controls { display: flex; align-items: center; gap: 8px; }
        select, .back { min-height: 40px; border: 1px solid rgba(148,163,184,.4); border-radius: 10px; padding: 0 11px; background: #102b68; color: #fff; font: inherit; font-size: 13px; font-weight: 650; }
        .back { display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
        #map { position: relative; flex: 1; min-height: 0; width: 100%; background: #0b1b3b; }
        .status { position: absolute; z-index: 1; left: 12px; bottom: 14px; max-width: calc(100% - 24px); padding: 8px 11px; border: 1px solid rgba(148,163,184,.25); border-radius: 9px; background: rgba(6,22,72,.88); color: #dbeafe; font-size: 12px; pointer-events: none; }
        .maplibregl-ctrl-group { border-radius: 10px !important; overflow: hidden; }
        @media (max-width: 520px) { .toolbar { align-items: flex-start; flex-wrap: wrap; } .controls { width: 100%; } select { flex: 1; } }
    </style>
</head>
<body>
    <header class="toolbar">
        <div class="heading">
            <h1>Weather Map Test</h1>
            <div class="location" id="location-label">Loading configured location…</div>
        </div>
        <div class="controls">
            <select id="layer-select" aria-label="Weather layer">
                <option value="temperature_2m">Temperature</option>
                <option value="precipitation">Precipitation</option>
                <option value="wind_speed_10m">Wind speed</option>
            </select>
            <a class="back" href="../">GaugeIQ</a>
        </div>
    </header>
    <main id="map" aria-label="Interactive weather map">
        <div class="status" id="map-status" role="status">Starting map…</div>
    </main>

    <script>
        window.GAUGEIQ_MAP_CONFIG = <?= $mapConfig ?>;
    </script>
    <script type="module">
        import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.mjs';

        const { omProtocol } = await import('https://unpkg.com/@openmeteo/weather-map-layer@0.3.1/dist/index.mjs');
        maplibregl.addProtocol('om', omProtocol);

        const config = window.GAUGEIQ_MAP_CONFIG;
        const status = document.getElementById('map-status');
        const layerSelect = document.getElementById('layer-select');
        const locationLabel = document.getElementById('location-label');
        locationLabel.textContent = config.locationName + ' · ' + config.latitude.toFixed(3) + ', ' + config.longitude.toFixed(3);

        const map = new maplibregl.Map({
            container: 'map',
            style: 'https://static-assets.open-meteo.com/map-assets/styles/minimal-planet-maps.json',
            center: [config.longitude, config.latitude],
            zoom: 5,
            maxZoom: 12,
            attributionControl: true
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        new maplibregl.Marker({ color: '#f97316' }).setLngLat([config.longitude, config.latitude]).addTo(map);

        let activeSource = null;
        let activeLayer = null;

        function setStatus(message) {
            status.textContent = message;
            status.hidden = !message;
        }

        function showWeatherLayer(variable) {
            if (activeLayer && map.getLayer(activeLayer)) map.removeLayer(activeLayer);
            if (activeSource && map.getSource(activeSource)) map.removeSource(activeSource);

            activeSource = 'gaugeiq-weather-source';
            activeLayer = 'gaugeiq-weather-layer';
            setStatus('Loading ' + layerSelect.selectedOptions[0].textContent.toLowerCase() + '…');

            const dataUrl = 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/latest.json?time_step=current_time_1H&variable=' + encodeURIComponent(variable);
            map.addSource(activeSource, {
                type: 'raster',
                url: 'om://' + dataUrl,
                maxzoom: 12
            });
            map.addLayer({
                id: activeLayer,
                type: 'raster',
                source: activeSource,
                paint: { 'raster-opacity': 0.78 }
            }, 'waterway-tunnel');
        }

        map.on('load', () => showWeatherLayer(layerSelect.value));
        map.on('error', event => {
            const message = event?.error?.message || 'A map resource failed to load.';
            setStatus('Map error: ' + message);
        });
        map.on('idle', () => {
            if (activeSource && map.getSource(activeSource)) setStatus('Weather layer loaded · ' + layerSelect.selectedOptions[0].textContent);
        });
        layerSelect.addEventListener('change', () => {
            if (map.isStyleLoaded()) showWeatherLayer(layerSelect.value);
        });
    </script>
</body>
</html>
