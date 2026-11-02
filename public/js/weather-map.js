import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.mjs';

const OMWeatherMapLayer = await import('https://unpkg.com/@openmeteo/weather-map-layer@0.3.1/dist/index.mjs');

const appearanceDefaults = {
            precipitationOpacity: 78,
            cloudOpacity: 34,
            precipitationColors: ['#7dd3fc', '#2563eb', '#7c3aed', '#ec4899'],
            cloudColors: ['#e2e8f0', '#cbd5e1', '#94a3b8', '#475569']
        };
        let appearance = { ...appearanceDefaults };
        try {
            const savedAppearance = JSON.parse(localStorage.getItem('gaugeiqWeatherMapAppearance') || 'null');
            if (savedAppearance && typeof savedAppearance === 'object') {
                appearance = { ...appearanceDefaults, ...savedAppearance };
            }
        } catch (error) {
            console.warn('Could not read saved weather-map appearance settings.', error);
        }

        function hexToRgba(hex, alpha = 1) {
            const value = /^#[0-9a-f]{6}$/i.test(hex) ? hex.slice(1) : '000000';
            return [
                parseInt(value.slice(0, 2), 16),
                parseInt(value.slice(2, 4), 16),
                parseInt(value.slice(4, 6), 16),
                alpha
            ];
        }

        const protocolOptions = OMWeatherMapLayer.defaultOmProtocolSettings;
        protocolOptions.colorScales = {
            ...protocolOptions.colorScales,
            precipitation: {
                type: 'breakpoint',
                unit: 'mm/h',
                breakpoints: [0, 0.2, 1, 5, 20],
                colors: [
                    [0, 0, 0, 0],
                    ...appearance.precipitationColors.map(color => hexToRgba(color))
                ]
            },
            cloud_cover: {
                type: 'breakpoint',
                unit: '%',
                breakpoints: [0, 25, 50, 75, 100],
                colors: [
                    [0, 0, 0, 0],
                    ...appearance.cloudColors.map(color => hexToRgba(color))
                ]
            }
        };
        maplibregl.addProtocol('om', (params, abortController) =>
            OMWeatherMapLayer.omProtocol(params, abortController, protocolOptions)
        );

        const mapElement = document.getElementById('map');
        const status = document.getElementById('map-status');
        const layerSelect = document.getElementById('layer-select');
        const locationLabel = document.getElementById('location-label');
        const config = {
            latitude: Number(mapElement?.dataset.latitude),
            longitude: Number(mapElement?.dataset.longitude),
            locationName: mapElement?.dataset.locationName || ''
        };
        if (!mapElement || !Number.isFinite(config.latitude) || !Number.isFinite(config.longitude)) {
            status.textContent = 'Map unavailable: location coordinates are missing.';
            throw new Error('GaugeIQ weather map configuration is missing or invalid.');
        }
        locationLabel.textContent = config.locationName + ' · ' + config.latitude.toFixed(3) + ', ' + config.longitude.toFixed(3);

        const savedMapZoom = (() => {
            try {
                const value = Number(localStorage.getItem('gaugeiqWeatherMapZoom'));
                return Number.isFinite(value) && value >= 0 && value <= 12 ? value : 5;
            } catch (error) {
                return 5;
            }
        })();

        let mapInstance = null;
        function createMap() {
        if (mapInstance) { mapInstance.resize(); return; }
        const map = new maplibregl.Map({
            container: 'map',
            style: {
                version: 8,
                sources: {
                    osm: {
                        type: 'raster',
                        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    }
                },
                layers: [
                    { id: 'osm-background', type: 'background', paint: { 'background-color': '#0b1b3b' } },
                    { id: 'osm-raster', type: 'raster', source: 'osm' }
                ]
            },
            center: [config.longitude, config.latitude],
            zoom: savedMapZoom,
            maxZoom: 12,
            cooperativeGestures: true,
            attributionControl: true
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        new maplibregl.Marker({ color: '#f97316' }).setLngLat([config.longitude, config.latitude]).addTo(map);

        let activeSources = [];
        let activeLayers = [];

        function setStatus(message) {
            status.textContent = message;
            status.hidden = !message;
        }

        function showWeatherLayer(selection) {
            activeLayers.forEach(id => {
                if (map.getLayer(id)) map.removeLayer(id);
            });
            activeSources.forEach(id => {
                if (map.getSource(id)) map.removeSource(id);
            });
            activeSources = [];
            activeLayers = [];

            const variables = selection === 'precipitation_cloud_cover'
                ? [
                    { variable: 'cloud_cover', label: 'cloud cover', opacity: Number(appearance.cloudOpacity) / 100 },
                    { variable: 'precipitation', label: 'precipitation', opacity: Number(appearance.precipitationOpacity) / 100 }
                ]
                : [{ variable: selection, label: layerSelect.selectedOptions[0].textContent.toLowerCase(), opacity: selection === 'cloud_cover' ? Number(appearance.cloudOpacity) / 100 : Number(appearance.precipitationOpacity) / 100 }];

            setStatus('Loading ' + layerSelect.selectedOptions[0].textContent.toLowerCase() + '…');

            variables.forEach((item, index) => {
                const sourceId = 'gaugeiq-weather-source-' + index;
                const layerId = 'gaugeiq-weather-layer-' + index;
                const dataUrl = 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/latest.json?time_step=current_time_1H&variable=' + encodeURIComponent(item.variable);

                map.addSource(sourceId, {
                    type: 'raster',
                    url: 'om://' + dataUrl,
                    maxzoom: 12
                });
                map.addLayer({
                    id: layerId,
                    type: 'raster',
                    source: sourceId,
                    paint: { 'raster-opacity': item.opacity }
                });

                activeSources.push(sourceId);
                activeLayers.push(layerId);
            });
        }

        map.on('load', () => showWeatherLayer(layerSelect.value));
        map.on('error', event => {
            const message = event?.error?.message || 'A map resource failed to load.';
            setStatus('Map error: ' + message);
        });
        map.on('idle', () => {
            if (activeSources.length && activeSources.every(id => map.getSource(id))) setStatus('Weather layer loaded · ' + layerSelect.selectedOptions[0].textContent);
        });
        map.on('zoomend', () => {
            try {
                localStorage.setItem('gaugeiqWeatherMapZoom', String(map.getZoom()));
            } catch (error) {
                console.warn('Could not save the weather-map zoom level.', error);
            }
        });
        mapInstance = map;
        window.GaugeIQWeatherMapResize = () => { if (mapInstance) mapInstance.resize(); };
        layerSelect.addEventListener('change', () => {
            if (mapInstance && mapInstance.isStyleLoaded()) showWeatherLayer(layerSelect.value);
        });
        }
    

// The map is part of the dashboard, so initialise it as soon as the module loads.
createMap();
requestAnimationFrame(() => {
    if (window.GaugeIQWeatherMapResize) window.GaugeIQWeatherMapResize();
});
window.addEventListener('resize', () => {
    if (window.GaugeIQWeatherMapResize) window.GaugeIQWeatherMapResize();
});
window.addEventListener('orientationchange', () => {
    setTimeout(() => {
        if (window.GaugeIQWeatherMapResize) window.GaugeIQWeatherMapResize();
    }, 180);
});
