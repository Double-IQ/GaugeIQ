<?php
declare(strict_types=1);
$configPath = dirname(__DIR__, 2) . '/config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('GaugeIQ must be installed before the weather-map test can run.');
}
$config = require $configPath;
require dirname(__DIR__, 2) . '/app/Database.php';
$locationId = (int)($_GET['location'] ?? 1);
if (!in_array($locationId, [1, 2], true)) $locationId = 1;
$selectedLatitude = (float)($config['pressure']['latitude'] ?? 0);
$selectedLongitude = (float)($config['pressure']['longitude'] ?? 0);
try {
    $database = new Database($config);
    $coordinateQuery = $database->pdo()->prepare(
        "SELECT `key`, `value` FROM gaugeiq_settings
         WHERE `key` IN (
             'active_location_latitude', 'active_location_longitude',
             'location_latitude', 'location_longitude',
             'second_location_enabled', 'second_location_latitude', 'second_location_longitude'
         )"
    );
    $coordinateQuery->execute();
    $coordinates = [];
    foreach ($coordinateQuery->fetchAll() as $coordinate) {
        $coordinates[(string)$coordinate['key']] = (string)$coordinate['value'];
    }
    if ($locationId === 2 && ($coordinates['second_location_enabled'] ?? '0') === '1') {
        $selectedLatitude = (float)($coordinates['second_location_latitude'] ?? $selectedLatitude);
        $selectedLongitude = (float)($coordinates['second_location_longitude'] ?? $selectedLongitude);
    } else {
        $locationId = 1;
        $selectedLatitude = (float)($coordinates['active_location_latitude'] ?? $coordinates['location_latitude'] ?? $selectedLatitude);
        $selectedLongitude = (float)($coordinates['active_location_longitude'] ?? $coordinates['location_longitude'] ?? $selectedLongitude);
    }
} catch (Throwable $error) {
    // Fall back to the installation's configured primary location.
    $locationId = 1;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#061648">
    <link href="https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.css" rel="stylesheet">
    <title>Weather Map Appearance · GaugeIQ</title>
    <style>
        :root { color-scheme: dark; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif; background: #061648; color: #f8fafc; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #061648; }
        .page { width: min(760px, 100%); margin: 0 auto; padding: max(22px, env(safe-area-inset-top)) 16px 36px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 22px; }
        h1 { margin: 0; font-size: 21px; font-weight: 760; }
        .subtitle { margin-top: 5px; color: #b9c9e8; font-size: 13px; line-height: 1.45; }
        a, button { color: #fff; }
        .back, button { min-height: 40px; border: 1px solid rgba(148,163,184,.4); border-radius: 10px; padding: 0 13px; background: #102b68; color: #fff; font: inherit; font-size: 13px; font-weight: 650; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .panel { margin-top: 14px; padding: 17px; border: 1px solid rgba(148,163,184,.22); border-radius: 15px; background: #0a2058; }
        h2 { margin: 0 0 5px; font-size: 16px; }
        .description { margin: 0 0 16px; color: #b9c9e8; font-size: 12px; line-height: 1.5; }
        .opacity-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin: 14px 0 17px; }
        .opacity-row label { font-size: 13px; font-weight: 650; }
        .opacity-control { display: flex; align-items: center; gap: 10px; width: min(310px, 58%); }
        input[type="range"] { width: 100%; accent-color: #60a5fa; }
        output { min-width: 42px; color: #dbeafe; font-size: 12px; text-align: right; font-variant-numeric: tabular-nums; }
        .colors { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
        .color-stop { min-width: 0; }
        .color-stop .color-opacity { display: block; width: 100%; margin: 7px 0 2px; }
        .color-stop output { display: block; min-width: 0; color: #dbeafe; font-size: 10px; text-align: center; }
        .color-stop label { display: block; margin-bottom: 7px; color: #cbd5e1; font-size: 11px; line-height: 1.35; }
        input[type="color"] { width: 100%; height: 42px; padding: 3px; border: 1px solid rgba(148,163,184,.4); border-radius: 9px; background: #102b68; cursor: pointer; }
        /* Checkerboard makes transparency visible instead of blending every
           preview against the same solid dark panel colour. */
        .gradient {
            display: block;
            width: 100%;
            height: 28px;
            margin-top: 14px;
            border: 1px solid rgba(148,163,184,.45);
            border-radius: 5px;
            background-color: #fff;
            background-image: conic-gradient(#cbd5e1 25%, #fff 0 50%, #cbd5e1 0 75%, #fff 0);
            background-size: 12px 12px;
        }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .save { background: #1d4ed8; border-color: #3b82f6; }
        .reset { background: transparent; }
        #status { min-height: 18px; color: #86efac; font-size: 12px; }
        .note { margin: 17px 2px 0; color: #9fb2d4; font-size: 11px; line-height: 1.5; }
        #region-map { width: 100%; height: 320px; overflow: hidden; border: 1px solid rgba(148,163,184,.35); border-radius: 11px; }
        .region-tools { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin: 12px 0 8px; }
        .region-tools button.active { background:#1d4ed8; border-color:#60a5fa; }
        #region-readout { color:#dbeafe; font-size:12px; line-height:1.5; }
        .region-help { margin:0 0 12px; color:#b9c9e8; font-size:12px; line-height:1.5; }
        @media(max-width:480px){ #region-map{height:280px;} }
        @media (max-width: 480px) { .page { padding-left: 13px; padding-right: 13px; } .panel { padding: 14px; } .colors { gap: 7px; } .color-stop label { font-size: 10px; } input[type="color"] { height: 38px; } }
    </style>
</head>
<body>
<main class="page">
    <header>
        <div>
            <h1>Weather Map Appearance</h1>
            <div class="subtitle">Adjust the weather overlay colours and transparency.</div>
        </div>
        <a class="back" href="../index.php?location=<?= $locationId ?>">Back to map</a>
    </header>

    <section class="panel" aria-labelledby="basemap-heading">
        <h2 id="basemap-heading">Base map appearance</h2>
        <p class="description">Choose the background map style. This does not change the weather overlay colours.</p>
        <div class="opacity-row">
            <label for="map-style">Map theme</label>
            <div class="opacity-control">
                <select id="map-style" aria-label="Base map theme" style="width:100%;min-height:40px;padding:0 10px;border:1px solid rgba(148,163,184,.4);border-radius:9px;background:#102b68;color:#fff;font:inherit;font-size:13px">
                    <option value="dark">Dark</option>
                    <option value="light">Light</option>
                </select>
            </div>
        </div>
        <div class="opacity-row">
            <label for="map-projection">Map view</label>
            <div class="opacity-control">
                <select id="map-projection" aria-label="Map view" style="width:100%;min-height:40px;padding:0 10px;border:1px solid rgba(148,163,184,.4);border-radius:9px;background:#102b68;color:#fff;font:inherit;font-size:13px">
                    <option value="mercator">Flat</option>
                    <option value="globe">Globe</option>
                </select>
            </div>
        </div>
    </section>

    <section class="panel" aria-labelledby="region-heading">
        <h2 id="region-heading">Weather coverage area</h2>
        <p class="description">Choose the rectangular area used to generate precipitation and cloud-cover overlays. The initial area is centred on your selected GaugeIQ location.</p>
        <div id="region-map" role="application" aria-label="Map for selecting the weather coverage rectangle"></div>
        <div class="region-tools">
            <button type="button" id="region-draw">Draw rectangle</button>
            <button type="button" class="reset" id="region-centre">Reset around selected location</button>
        </div>
        <p class="region-help">Select Draw rectangle, then drag from one corner to the opposite corner. When drawing is off, double-tap and hold the second tap, then drag one finger to pan the map.</p>
        <div id="region-readout" aria-live="polite">Loading coverage area…</div>
    </section>

    <section class="panel" aria-labelledby="precip-heading">
        <h2 id="precip-heading">Precipitation</h2>
        <p class="description">Five rainfall-rate colour bands in mm/h: 0–1, 1.1–2, 2.1–5, 5.1–10 and 10+. Zero rainfall remains transparent.</p>
        <p class="description">Overall opacity controls the whole precipitation layer. Each colour opacity below controls that colour independently.</p>
        <div class="opacity-row">
            <label for="precip-overall-opacity">Overall precipitation opacity</label>
            <div class="opacity-control"><input id="precip-overall-opacity" type="range" min="0" max="100" step="1"><output id="precip-overall-opacity-value" for="precip-overall-opacity">100%</output></div>
        </div>
        <div class="colors">
            <div class="color-stop"><label for="precip-c1">Light<br>0.2 mm/h</label><input id="precip-c1" type="color">
                <input id="precip-c1-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Light colour opacity">
                <output id="precip-c1-opacity-value" for="precip-c1-opacity">100%</output></div>
            <div class="color-stop"><label for="precip-c2">Moderate<br>1 mm/h</label><input id="precip-c2" type="color">
                <input id="precip-c2-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Moderate colour opacity">
                <output id="precip-c2-opacity-value" for="precip-c2-opacity">100%</output></div>
            <div class="color-stop"><label for="precip-c3">Heavy<br>5 mm/h</label><input id="precip-c3" type="color">
                <input id="precip-c3-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Heavy colour opacity">
                <output id="precip-c3-opacity-value" for="precip-c3-opacity">100%</output></div>
            <div class="color-stop"><label for="precip-c4">Very heavy<br>5.1–10 mm/h</label><input id="precip-c4" type="color">
                <input id="precip-c4-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Very heavy colour opacity">
                <output id="precip-c4-opacity-value" for="precip-c4-opacity">100%</output></div>
            <div class="color-stop"><label for="precip-c5">Extreme<br>10+ mm/h</label><input id="precip-c5" type="color">
                <input id="precip-c5-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Extreme colour opacity">
                <output id="precip-c5-opacity-value" for="precip-c5-opacity">100%</output></div>
        </div>
        <canvas class="gradient" id="precip-gradient" width="512" height="28" aria-label="Precipitation colour and transparency preview"></canvas>
    </section>

    <section class="panel" aria-labelledby="cloud-heading">
        <h2 id="cloud-heading">Cloud cover</h2>
        <p class="description">Colour scale from low to complete cloud cover. Clear sky remains transparent.</p>
        <p class="description">Overall opacity controls the whole cloud layer. Each colour opacity below controls that colour independently.</p>
        <div class="opacity-row">
            <label for="cloud-overall-opacity">Overall cloud opacity</label>
            <div class="opacity-control"><input id="cloud-overall-opacity" type="range" min="0" max="100" step="1"><output id="cloud-overall-opacity-value" for="cloud-overall-opacity">100%</output></div>
        </div>
        <div class="colors">
            <div class="color-stop"><label for="cloud-c1">Low<br>25%</label><input id="cloud-c1" type="color">
                <input id="cloud-c1-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Low colour opacity">
                <output id="cloud-c1-opacity-value" for="cloud-c1-opacity">100%</output></div>
            <div class="color-stop"><label for="cloud-c2">Moderate<br>50%</label><input id="cloud-c2" type="color">
                <input id="cloud-c2-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Moderate colour opacity">
                <output id="cloud-c2-opacity-value" for="cloud-c2-opacity">100%</output></div>
            <div class="color-stop"><label for="cloud-c3">High<br>75%</label><input id="cloud-c3" type="color">
                <input id="cloud-c3-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="High colour opacity">
                <output id="cloud-c3-opacity-value" for="cloud-c3-opacity">100%</output></div>
            <div class="color-stop"><label for="cloud-c4">Overcast<br>100%</label><input id="cloud-c4" type="color">
                <input id="cloud-c4-opacity" class="color-opacity" type="range" min="0" max="100" step="1" aria-label="Overcast colour opacity">
                <output id="cloud-c4-opacity-value" for="cloud-c4-opacity">100%</output></div>
        </div>
        <canvas class="gradient cloud" id="cloud-gradient" width="512" height="28" aria-label="Cloud cover colour and transparency preview"></canvas>
    </section>

    <div class="actions">
        <button class="save" id="save" type="button">Save appearance</button>
        <button class="reset" id="reset" type="button">Reset defaults</button>
        <span id="status" role="status" aria-live="polite"></span>
    </div>
    <p class="note">Settings are saved in this browser on this device. Return to the map after saving; reload the map to apply your changes.</p>
</main>
<script type="module">
import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.mjs';

(() => {
    const KEY = 'gaugeiqWeatherMapAppearance';
    const LOCATION_ID = <?= json_encode($locationId) ?>;
    const selectedLocation = {
        latitude: <?= json_encode($selectedLatitude) ?>,
        longitude: <?= json_encode($selectedLongitude) ?>
    };
    const defaults = {
        mapStyle: 'dark',
        mapProjection: 'mercator',
        precipitationOpacity: 100,
        cloudOpacity: 100,
        precipitationColors: ['#7dd3fc', '#2563eb', '#7c3aed', '#ec4899', '#be123c'],
        cloudColors: ['#e2e8f0', '#cbd5e1', '#94a3b8', '#475569'],
        precipitationColorOpacities: [100, 100, 100, 100, 100],
        cloudColorOpacities: [100, 100, 100, 100],
        weatherRegionBounds: null
    };
    let settings = { ...defaults };
    const makeDefaultBounds = () => {
        const latitude = Math.max(-85, Math.min(85, Number(selectedLocation.latitude) || 0));
        const longitude = Number(selectedLocation.longitude) || 0;
        const halfLatitude = 2.25;
        const halfLongitude = Math.min(25, 2.25 / Math.max(0.15, Math.cos(latitude * Math.PI / 180)));
        return [
            Math.max(-180, longitude - halfLongitude),
            Math.max(-85, latitude - halfLatitude),
            Math.min(180, longitude + halfLongitude),
            Math.min(85, latitude + halfLatitude)
        ];
    };
    const validBounds = value => Array.isArray(value) && value.length === 4 &&
        value.every(Number.isFinite) && value[0] < value[2] && value[1] < value[3] &&
        value[0] >= -180 && value[2] <= 180 && value[1] >= -85 && value[3] <= 85;
    if (!validBounds(settings.weatherRegionBounds)) settings.weatherRegionBounds = makeDefaultBounds();

    let regionMap = null;
    let drawingRegion = false;
    let regionStart = null;
    let regionBounds = [...settings.weatherRegionBounds];
    const regionSourceId = 'gaugeiq-region-selection';
    function rectangleFeature(bounds) {
        const [west, south, east, north] = bounds;
        return {
            type: 'Feature',
            properties: {},
            geometry: { type: 'Polygon', coordinates: [[
                [west, south], [east, south], [east, north],
                [west, north], [west, south]
            ]] }
        };
    }
    function showRegionBounds(bounds) {
        regionBounds = [...bounds];
        const readout = document.getElementById('region-readout');
        readout.textContent = 'West ' + bounds[0].toFixed(3) + '° · South ' + bounds[1].toFixed(3) +
            '° · East ' + bounds[2].toFixed(3) + '° · North ' + bounds[3].toFixed(3) + '°';
        if (regionMap?.getSource(regionSourceId)) {
            regionMap.getSource(regionSourceId).setData(rectangleFeature(bounds));
        }
    }
    function initRegionMap() {
        // Use an inline raster style instead of fetching a remote style JSON.
        // This avoids a hard dependency on the OpenFreeMap style endpoint, which
        // can fail on some hosting networks while leaving the map completely blank.
        regionMap = new maplibregl.Map({
            container: 'region-map',
            style: {
                version: 8,
                sources: {
                    'gaugeiq-base-tiles': {
                        type: 'raster',
                        // CARTO tile requests are failing from some deployments.
                        // Use the OpenStreetMap standard raster endpoint for this small
                        // interactive selector map instead.
                        tiles: [
                            'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
                        ],
                        tileSize: 256,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>'
                    }
                },
                layers: [{
                    id: 'gaugeiq-base-layer',
                    type: 'raster',
                    source: 'gaugeiq-base-tiles',
                    paint: { 'raster-fade-duration': 0 }
                }]
            },
            center: [selectedLocation.longitude || 0, selectedLocation.latitude || 0],
            zoom: 4,
            minZoom: 1,
            maxZoom: 12,
            attributionControl: true
        });
        regionMap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        regionMap.on('error', event => {
            const message = event?.error?.message || 'The map tiles could not be loaded.';
            document.getElementById('region-readout').textContent = 'Map loading issue: ' + message;
            console.error('GaugeIQ coverage map error:', event?.error || event);
        });
        regionMap.on('load', () => {
            regionMap.resize();
            regionMap.addSource(regionSourceId, { type: 'geojson', data: rectangleFeature(regionBounds) });
            regionMap.addLayer({
                id: 'gaugeiq-region-fill', type: 'fill', source: regionSourceId,
                paint: { 'fill-color': '#38bdf8', 'fill-opacity': 0.16 }
            });
            regionMap.addLayer({
                id: 'gaugeiq-region-outline', type: 'line', source: regionSourceId,
                paint: { 'line-color': '#38bdf8', 'line-width': 2.5, 'line-dasharray': [2, 1] }
            });
            regionMap.fitBounds([[regionBounds[0], regionBounds[1]], [regionBounds[2], regionBounds[3]]], { padding: 35, maxZoom: 7, duration: 0 });
        });
        // Handle drawing on the canvas with Pointer Events so mouse, pen and
        // touch all work consistently. Pointer capture keeps the drag alive
        // even if the pointer briefly leaves the map canvas.
        const mapCanvas = regionMap.getCanvas();
        mapCanvas.style.touchAction = 'none';
        document.getElementById('region-map').style.touchAction = 'none';
        const pointerLngLat = event => {
            const rect = mapCanvas.getBoundingClientRect();
            return regionMap.unproject([
                event.clientX - rect.left,
                event.clientY - rect.top
            ]);
        };
        mapCanvas.addEventListener('pointerdown', event => {
            if (!drawingRegion || event.pointerType === 'touch' || (event.pointerType === 'mouse' && event.button !== 0)) return;
            event.preventDefault();
            regionStart = pointerLngLat(event);
            mapCanvas.setPointerCapture(event.pointerId);
            mapCanvas.style.cursor = 'crosshair';
        });
        mapCanvas.addEventListener('pointermove', event => {
            if (!drawingRegion || !regionStart) return;
            const end = pointerLngLat(event);
            const bounds = [
                Math.min(regionStart.lng, end.lng), Math.min(regionStart.lat, end.lat),
                Math.max(regionStart.lng, end.lng), Math.max(regionStart.lat, end.lat)
            ];
            if (bounds[0] !== bounds[2] && bounds[1] !== bounds[3]) showRegionBounds(bounds);
        });
        const finishRegionPointer = event => {
            if (!regionStart) return;
            const end = pointerLngLat(event);
            const bounds = [
                Math.max(-180, Math.min(regionStart.lng, end.lng)),
                Math.max(-85, Math.min(regionStart.lat, end.lat)),
                Math.min(180, Math.max(regionStart.lng, end.lng)),
                Math.min(85, Math.max(regionStart.lat, end.lat))
            ];
            regionStart = null;
            if (bounds[0] < bounds[2] && bounds[1] < bounds[3]) showRegionBounds(bounds);
        };
        mapCanvas.addEventListener('pointerup', finishRegionPointer);
        mapCanvas.addEventListener('pointercancel', () => { regionStart = null; });

        // Mobile map navigation: double-tap, keep the second tap held, then
        // drag with one finger to pan. A normal one-finger gesture remains
        // available for MapLibre controls; rectangle drawing keeps its own
        // single-finger drag behaviour below.
        let lastTap = null;
        let touchPanActive = false;
        let lastPanPoint = null;
        const touchPoint = touch => ({ x: touch.clientX, y: touch.clientY });
        mapCanvas.addEventListener('touchstart', event => {
            if (drawingRegion || event.touches.length !== 1 || !event.changedTouches.length) return;
            const point = touchPoint(event.changedTouches[0]);
            const now = Date.now();
            const isSecondTap = lastTap && now - lastTap.time < 350 &&
                Math.hypot(point.x - lastTap.x, point.y - lastTap.y) < 28;
            if (isSecondTap) {
                touchPanActive = true;
                lastPanPoint = point;
                lastTap = null;
                event.preventDefault();
                event.stopImmediatePropagation();
            } else {
                lastTap = { ...point, time: now };
            }
        }, { capture: true, passive: false });
        mapCanvas.addEventListener('touchmove', event => {
            if (!touchPanActive || drawingRegion || !event.touches.length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            const point = touchPoint(event.touches[0]);
            if (lastPanPoint) {
                regionMap.panBy([
                    point.x - lastPanPoint.x,
                    point.y - lastPanPoint.y
                ], { animate: false });
            }
            lastPanPoint = point;
        }, { capture: true, passive: false });
        const finishTouchPan = event => {
            if (!touchPanActive) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            touchPanActive = false;
            lastPanPoint = null;
        };
        mapCanvas.addEventListener('touchend', finishTouchPan, { capture: true, passive: false });
        mapCanvas.addEventListener('touchcancel', finishTouchPan, { capture: true, passive: false });

        // Mobile Safari and some embedded browsers still route touch gestures
        // through MapLibre's own touch handlers before pointer capture settles.
        // In drawing mode, take ownership of the touch sequence in capture phase.
        const touchLngLat = touch => {
            const rect = mapCanvas.getBoundingClientRect();
            return regionMap.unproject([
                touch.clientX - rect.left,
                touch.clientY - rect.top
            ]);
        };
        mapCanvas.addEventListener('touchstart', event => {
            if (!drawingRegion || !event.changedTouches.length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            regionStart = touchLngLat(event.changedTouches[0]);
            mapCanvas.style.cursor = 'crosshair';
        }, { capture: true, passive: false });
        mapCanvas.addEventListener('touchmove', event => {
            if (!drawingRegion || !regionStart || !event.changedTouches.length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            const end = touchLngLat(event.changedTouches[0]);
            const bounds = [
                Math.min(regionStart.lng, end.lng), Math.min(regionStart.lat, end.lat),
                Math.max(regionStart.lng, end.lng), Math.max(regionStart.lat, end.lat)
            ];
            if (bounds[0] !== bounds[2] && bounds[1] !== bounds[3]) showRegionBounds(bounds);
        }, { capture: true, passive: false });
        mapCanvas.addEventListener('touchend', event => {
            if (!drawingRegion || !regionStart || !event.changedTouches.length) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            finishRegionPointer({
                clientX: event.changedTouches[0].clientX,
                clientY: event.changedTouches[0].clientY
            });
        }, { capture: true, passive: false });
        mapCanvas.addEventListener('touchcancel', () => { regionStart = null; }, { capture: true, passive: false });
        document.getElementById('region-draw').addEventListener('click', () => {
            drawingRegion = !drawingRegion;
            regionStart = null;
            if (drawingRegion) regionMap.dragPan.disable();
            else regionMap.dragPan.enable();
            document.getElementById('region-draw').classList.toggle('active', drawingRegion);
            document.getElementById('region-draw').textContent = drawingRegion ? 'Finish drawing' : 'Draw rectangle';
            regionMap.getCanvas().style.cursor = drawingRegion ? 'crosshair' : '';
        });
        document.getElementById('region-centre').addEventListener('click', () => {
            drawingRegion = false;
            document.getElementById('region-draw').classList.remove('active');
            document.getElementById('region-draw').textContent = 'Draw rectangle';
            regionMap.dragPan.enable();
            regionMap.getCanvas().style.cursor = '';
            const bounds = makeDefaultBounds();
            showRegionBounds(bounds);
            regionMap.fitBounds([[bounds[0], bounds[1]], [bounds[2], bounds[3]]], { padding: 35, maxZoom: 7 });
        });
        showRegionBounds(regionBounds);
    }

    try {
        const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (saved && typeof saved === 'object') settings = { ...defaults, ...saved };
        const savedLocationBounds = settings.weatherRegionBoundsByLocation?.[String(LOCATION_ID)];
        if (validBounds(savedLocationBounds)) {
            settings.weatherRegionBounds = [...savedLocationBounds];
        } else if (LOCATION_ID === 2 || !validBounds(settings.weatherRegionBounds)) {
            settings.weatherRegionBounds = makeDefaultBounds();
        }
        settings.precipitationOpacity = Math.max(0, Math.min(100, Number(settings.precipitationOpacity ?? 100)));
        settings.cloudOpacity = Math.max(0, Math.min(100, Number(settings.cloudOpacity ?? 100)));
        const savedPrecipitationColors = Array.isArray(settings.precipitationColors) ? settings.precipitationColors : defaults.precipitationColors;
        const savedPrecipitationOpacities = Array.isArray(settings.precipitationColorOpacities) ? settings.precipitationColorOpacities : defaults.precipitationColorOpacities;
        settings.precipitationColors = [...savedPrecipitationColors.slice(0, 4), savedPrecipitationColors[4] ?? defaults.precipitationColors[4]];
        settings.precipitationColorOpacities = [...savedPrecipitationOpacities.slice(0, 4), savedPrecipitationOpacities[4] ?? defaults.precipitationColorOpacities[4]];
        settings.cloudColorOpacities = Array.isArray(settings.cloudColorOpacities) ? settings.cloudColorOpacities : [...defaults.cloudColorOpacities];
    } catch (error) {
        console.warn('Could not load saved appearance settings.', error);
    }
    // Load the saved rectangle before initialising the map; otherwise the map
    // starts with the default bounds even though the settings are saved.
    if (!validBounds(settings.weatherRegionBounds)) settings.weatherRegionBounds = makeDefaultBounds();
    regionBounds = [...settings.weatherRegionBounds];

    function paintGradient(type) {
        const colors = settings[type + 'Colors'];
        const opacityKey = type === 'precipitation' ? 'precipitationColorOpacities' : 'cloudColorOpacities';
        const target = document.getElementById(type === 'precipitation' ? 'precip-gradient' : 'cloud-gradient');
        const precipitationScale = type === 'precipitation';
        const breakpoints = precipitationScale ? [0, 1, 1.1, 2, 2.1, 5, 5.1, 10] : [0, 25, 50, 75, 100];
        const scaleColors = precipitationScale
            ? [null, colors[0], colors[1], colors[1], colors[2], colors[2], colors[3], colors[4]]
            : [null, ...colors];
        const overallOpacity = Math.max(0, Math.min(100, Number(settings[precipitationScale ? 'precipitationOpacity' : 'cloudOpacity'] ?? 100))) / 100;
        const rgbaStops = scaleColors.map((hex, index) => {
            if (index === 0 || !hex) return [0, 0, 0, 0];
            const colorIndex = precipitationScale ? [0, 1, 1, 2, 2, 3, 4][index - 1] : index - 1;
            const value = /^#[0-9a-f]{6}$/i.test(hex) ? hex.slice(1) : '000000';
            return [
                parseInt(value.slice(0, 2), 16),
                parseInt(value.slice(2, 4), 16),
                parseInt(value.slice(4, 6), 16),
                (Math.max(0, Math.min(100, Number(settings[opacityKey]?.[colorIndex] ?? 100))) / 100) * overallOpacity
            ];
        });

        // Mirror Open-Meteo's breakpoint sampler: interpolate RGB and alpha
        // independently, then let the checkerboard show the resulting alpha.
        const width = target.width;
        const height = target.height;
        const context = target.getContext('2d');
        if (!context) return;
        const image = context.createImageData(width, height);
        for (let x = 0; x < width; x++) {
            const value = (x / Math.max(1, width - 1)) * breakpoints[breakpoints.length - 1];
            let index = 0;
            for (let stop = 1; stop < breakpoints.length; stop++) {
                if (breakpoints[stop] <= value) index = stop;
                else break;
            }
            const next = Math.min(rgbaStops.length - 1, index + 1);
            const span = breakpoints[next] - breakpoints[index];
            const t = span > 0 ? Math.max(0, Math.min(1, (value - breakpoints[index]) / span)) : 0;
            const a = rgbaStops[index];
            const b = rgbaStops[next];
            const channels = [
                a[0] + (b[0] - a[0]) * t,
                a[1] + (b[1] - a[1]) * t,
                a[2] + (b[2] - a[2]) * t,
                a[3] + (b[3] - a[3]) * t
            ];
            for (let y = 0; y < height; y++) {
                const offset = (y * width + x) * 4;
                image.data[offset] = channels[0];
                image.data[offset + 1] = channels[1];
                image.data[offset + 2] = channels[2];
                image.data[offset + 3] = 255 * channels[3];
            }
        }
        context.putImageData(image, 0, 0);
    }

    function render() {
        document.getElementById('map-style').value = settings.mapStyle === 'light' ? 'light' : 'dark';
        document.getElementById('map-projection').value = settings.mapProjection === 'globe' ? 'globe' : 'mercator';
        ['precipitation', 'cloud'].forEach(type => {
            const key = type === 'precipitation' ? 'precipitationOpacity' : 'cloudOpacity';
            const prefix = type === 'precipitation' ? 'precip' : 'cloud';
            const value = Math.max(0, Math.min(100, Number(settings[key] ?? 100)));
            document.getElementById(prefix + '-overall-opacity').value = value;
            document.getElementById(prefix + '-overall-opacity-value').textContent = value + '%';
        });
        ['precipitation', 'cloud'].forEach((type) => {
            const key = type === 'precipitation' ? 'precipitationColors' : 'cloudColors';
            settings[key].forEach((color, index) => {
                const prefix = type === 'precipitation' ? 'precip' : 'cloud';
                const colorId = prefix + '-c' + (index + 1);
                document.getElementById(colorId).value = color;
                const opacityKey = type === 'precipitation' ? 'precipitationColorOpacities' : 'cloudColorOpacities';
                const opacity = Math.max(0, Math.min(100, Number(settings[opacityKey]?.[index] ?? 100)));
                document.getElementById(colorId + '-opacity').value = opacity;
                document.getElementById(colorId + '-opacity-value').textContent = opacity + '%';
            });
            paintGradient(type);
        });
    }

    document.getElementById('map-style').addEventListener('change', event => {
        settings.mapStyle = event.target.value === 'light' ? 'light' : 'dark';
    });
    document.getElementById('map-projection').addEventListener('change', event => {
        settings.mapProjection = event.target.value === 'globe' ? 'globe' : 'mercator';
    });

    ['precipitation', 'cloud'].forEach(type => {
        const prefix = type === 'precipitation' ? 'precip' : 'cloud';
        const key = type === 'precipitation' ? 'precipitationOpacity' : 'cloudOpacity';
        document.getElementById(prefix + '-overall-opacity').addEventListener('input', event => {
            settings[key] = Number(event.target.value);
            document.getElementById(prefix + '-overall-opacity-value').textContent = event.target.value + '%';
        });
    });

    ['precipitation', 'cloud'].forEach(type => {
        const prefix = type === 'precipitation' ? 'precip' : 'cloud';
        const key = type === 'precipitation' ? 'precipitationColors' : 'cloudColors';
        for (let i = 0; i < (type === 'precipitation' ? 5 : 4); i++) {
            document.getElementById(prefix + '-c' + (i + 1)).addEventListener('input', event => {
                settings[key][i] = event.target.value;
                paintGradient(type);
            });
        }
    });

    ['precipitation', 'cloud'].forEach(type => {
        const prefix = type === 'precipitation' ? 'precip' : 'cloud';
        const opacityKey = type === 'precipitation' ? 'precipitationColorOpacities' : 'cloudColorOpacities';
        for (let i = 0; i < (type === 'precipitation' ? 5 : 4); i++) {
            const id = prefix + '-c' + (i + 1) + '-opacity';
            document.getElementById(id).addEventListener('input', event => {
                settings[opacityKey][i] = Number(event.target.value);
                document.getElementById(id + '-value').textContent = event.target.value + '%';
                paintGradient(type);
            });
        }
    });

    document.getElementById('save').addEventListener('click', () => {
        try {
            settings.weatherRegionBoundsByLocation = {
                ...(settings.weatherRegionBoundsByLocation && typeof settings.weatherRegionBoundsByLocation === 'object'
                    ? settings.weatherRegionBoundsByLocation : {}),
                [String(LOCATION_ID)]: [...regionBounds]
            };
            if (LOCATION_ID === 1) settings.weatherRegionBounds = [...regionBounds];
            localStorage.setItem(KEY, JSON.stringify(settings));
            document.getElementById('status').textContent = 'Appearance saved.';
        } catch (error) {
            document.getElementById('status').textContent = 'Could not save settings in this browser.';
        }
    });

    document.getElementById('reset').addEventListener('click', () => {
        const existingLocationBounds = settings.weatherRegionBoundsByLocation && typeof settings.weatherRegionBoundsByLocation === 'object'
            ? settings.weatherRegionBoundsByLocation : {};
        settings = JSON.parse(JSON.stringify(defaults));
        settings.weatherRegionBoundsByLocation = { ...existingLocationBounds };
        settings.weatherRegionBounds = makeDefaultBounds();
        settings.weatherRegionBoundsByLocation[String(LOCATION_ID)] = [...settings.weatherRegionBounds];
        showRegionBounds(settings.weatherRegionBounds);
        render();
        document.getElementById('status').textContent = 'Defaults restored. Save to keep them.';
    });

    render();
    try {
        initRegionMap();
        // Ensure MapLibre measures the final visible panel dimensions.
        requestAnimationFrame(() => regionMap?.resize());
    } catch (error) {
        console.error('Could not initialise the GaugeIQ coverage map.', error);
        document.getElementById('region-readout').textContent =
            'The map could not be started: ' + (error?.message || 'Map initialisation failed.');
    }
})();
</script>
</body>
</html>
