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
                    ...appearance.precipitationColors.map((color, index) => hexToRgba(color, Math.max(0, Math.min(100, Number(appearance.precipitationColorOpacities?.[index] ?? 100))) / 100))
                ]
            },
            cloud_cover: {
                type: 'breakpoint',
                unit: '%',
                breakpoints: [0, 25, 50, 75, 100],
                colors: [
                    [0, 0, 0, 0],
                    ...appearance.cloudColors.map((color, index) => hexToRgba(color, Math.max(0, Math.min(100, Number(appearance.cloudColorOpacities?.[index] ?? 100))) / 100))
                ]
            }
        };
        maplibregl.addProtocol('om', (params, abortController) =>
            OMWeatherMapLayer.omProtocol(params, abortController, protocolOptions)
        );

        const mapElement = document.getElementById('map');
        const status = document.getElementById('map-status');
        const layerSelect = document.getElementById('layer-select');
        const timeRangeSelect = document.getElementById('map-time-range');
        const animationToggle = document.getElementById('map-animation-toggle');
        const frameTimeLabel = document.getElementById('map-frame-time');
        const config = {
            latitude: Number(mapElement?.dataset.latitude),
            longitude: Number(mapElement?.dataset.longitude)
        };
        if (!mapElement || !Number.isFinite(config.latitude) || !Number.isFinite(config.longitude)) {
            status.textContent = 'Map unavailable: location coordinates are missing.';
            throw new Error('GaugeIQ weather map configuration is missing or invalid.');
        }

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
            style: 'https://tiles.openfreemap.org/styles/dark',
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
        let activeLayerOpacities = [];
        let animationSources = [];
        let animationLayers = [];
        let animationFrames = [];
        let animationTimer = null;
        let animationToken = 0;
        let animationPreparing = false;
        let animationCurrentFrame = 0;

        function setStatus(message) {
            status.textContent = message;
            status.hidden = !message;
        }

        function weatherVariables(selection) {
            return selection === 'precipitation_cloud_cover'
                ? [
                    { variable: 'cloud_cover', opacity: Number(appearance.cloudOpacity) / 100 },
                    { variable: 'precipitation', opacity: Number(appearance.precipitationOpacity) / 100 }
                ]
                : [{
                    variable: selection,
                    opacity: selection === 'cloud_cover'
                        ? Number(appearance.cloudOpacity) / 100
                        : Number(appearance.precipitationOpacity) / 100
                }];
        }

        function clearAnimation() {
            animationToken++;
            if (animationTimer !== null) {
                clearInterval(animationTimer);
                animationTimer = null;
            }
            animationLayers.forEach(item => {
                if (map.getLayer(item.id)) map.removeLayer(item.id);
            });
            animationSources.forEach(id => {
                if (map.getSource(id)) map.removeSource(id);
            });
            animationLayers = [];
            animationSources = [];
            animationFrames = [];
            animationPreparing = false;
            animationCurrentFrame = 0;
            if (map.isStyleLoaded()) {
                activeLayers.forEach((id, index) => {
                    if (map.getLayer(id)) map.setPaintProperty(id, 'raster-opacity', activeLayerOpacities[index] ?? 1);
                });
            }
            animationToggle.disabled = false;
            animationToggle.textContent = 'Play';
            animationToggle.setAttribute('aria-pressed', 'false');
        }

        function updateFrameLabel(index) {
            const frame = animationFrames[index];
            if (!frame) return;
            const label = new Date(frame.time).toLocaleString(undefined, {
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
            frameTimeLabel.textContent = 'Frame ' + (index + 1) + '/6 · ' + label;
        }

        function showFrame(index) {
            animationLayers.forEach(item => {
                if (map.getLayer(item.id)) {
                    map.setPaintProperty(item.id, 'raster-opacity', item.frameIndex === index ? item.opacity : 0);
                }
            });
            updateFrameLabel(index);
        }

        function runAnimation() {
            if (!animationFrames.length || animationTimer !== null) return;
            showFrame(animationCurrentFrame);
            animationToggle.disabled = false;
            animationToggle.textContent = 'Pause';
            animationToggle.setAttribute('aria-pressed', 'true');
            animationTimer = setInterval(() => {
                animationCurrentFrame = (animationCurrentFrame + 1) % animationFrames.length;
                showFrame(animationCurrentFrame);
            }, 1200);
        }

        async function prepareAnimation() {
            clearAnimation();
            const token = animationToken;
            animationPreparing = true;
            animationToggle.disabled = true;
            setStatus('Preparing six hourly weather frames…');

            try {
                const metadataResponse = await fetch(
                    'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/latest.json',
                    { cache: 'no-cache' }
                );
                if (!metadataResponse.ok) throw new Error('Weather-map time data could not be loaded.');
                const metadata = await metadataResponse.json();
                if (token !== animationToken) return;

                const validTimes = Array.isArray(metadata.valid_times) ? metadata.valid_times : [];
                const parsedTimes = validTimes.map(value => Date.parse(value));
                if (!parsedTimes.length || parsedTimes.some(value => !Number.isFinite(value))) {
                    throw new Error('The latest forecast does not contain usable hourly timestamps.');
                }

                const now = Date.now();
                let currentIndex = 0;
                let nearestDistance = Infinity;
                parsedTimes.forEach((timestamp, index) => {
                    const distance = Math.abs(timestamp - now);
                    if (distance < nearestDistance) {
                        nearestDistance = distance;
                        currentIndex = index;
                    }
                });

                let frameSpecs = [];
                if (timeRangeSelect.value === 'past') {
                    const currentTime = parsedTimes[currentIndex];
                    const firstFrameTime = currentTime - (5 * 60 * 60 * 1000);
                    const previousRunTime = Math.floor((firstFrameTime - 60 * 60 * 1000) / (6 * 60 * 60 * 1000)) * (6 * 60 * 60 * 1000);
                    const runDate = new Date(previousRunTime);
                    const runFolder = [
                        runDate.getUTCFullYear(),
                        String(runDate.getUTCMonth() + 1).padStart(2, '0'),
                        String(runDate.getUTCDate()).padStart(2, '0')
                    ].join('/') + '/' + String(runDate.getUTCHours()).padStart(2, '0') + '00Z';

                    frameSpecs = Array.from({ length: 6 }, (_, offset) => {
                        const timestamp = firstFrameTime + (offset * 60 * 60 * 1000);
                        const frameDate = new Date(timestamp);
                        const timeToken = frameDate.toISOString().slice(0, 10) + 'T' +
                            String(frameDate.getUTCHours()).padStart(2, '0') +
                            String(frameDate.getUTCMinutes()).padStart(2, '0');
                        return {
                            time: timestamp,
                            directFile: 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/' +
                                runFolder + '/' + timeToken + '.om'
                        };
                    });
                } else {
                    if (currentIndex + 5 >= validTimes.length) {
                        throw new Error('The next six hourly forecast frames are not yet available in the latest ECMWF run.');
                    }
                    frameSpecs = Array.from({ length: 6 }, (_, offset) => ({
                        time: parsedTimes[currentIndex + offset],
                        validIndex: currentIndex + offset
                    }));
                }
                const variables = weatherVariables(layerSelect.value);
                frameSpecs.forEach((frameSpec, framePosition) => {
                    const frame = { time: frameSpec.time, layerIds: [] };
                    variables.forEach((item, variableIndex) => {
                        const sourceId = 'gaugeiq-animation-source-' + token + '-' + framePosition + '-' + variableIndex;
                        const layerId = 'gaugeiq-animation-layer-' + token + '-' + framePosition + '-' + variableIndex;
                        const dataUrl = frameSpec.directFile
                            ? frameSpec.directFile + '?variable=' + encodeURIComponent(item.variable) + '&delta_time_hours=1'
                            : 'https://openmeteo.s3.amazonaws.com/data_spatial/ecmwf_ifs/latest.json?time_step=valid_times_' +
                                frameSpec.validIndex + '&variable=' + encodeURIComponent(item.variable);

                        map.addSource(sourceId, {
                            type: 'raster',
                            url: 'om://' + dataUrl,
                            maxzoom: 12
                        });
                        map.addLayer({
                            id: layerId,
                            type: 'raster',
                            source: sourceId,
                            paint: { 'raster-opacity': framePosition === 0 ? item.opacity : 0.001 }
                        });

                        animationSources.push(sourceId);
                        animationLayers.push({ id: layerId, frameIndex: framePosition, opacity: item.opacity });
                        frame.layerIds.push(layerId);
                    });
                    animationFrames.push(frame);
                });

                animationPreparing = false;
                animationCurrentFrame = 0;
                const waitForTiles = () => {
                    if (token !== animationToken) return;
                    const allLoaded = animationSources.every(id => map.isSourceLoaded(id));
                    if (!allLoaded) {
                        map.once('idle', waitForTiles);
                        return;
                    }
                    activeLayers.forEach(id => {
                        if (map.getLayer(id)) map.setPaintProperty(id, 'raster-opacity', 0);
                    });
                    setStatus('');
                    runAnimation();
                };
                map.once('idle', waitForTiles);
            } catch (error) {
                if (token !== animationToken) return;
                animationPreparing = false;
                animationToggle.disabled = false;
                animationToggle.textContent = 'Play';
                animationToggle.setAttribute('aria-pressed', 'false');
                setStatus(error?.message || 'Weather animation could not be prepared.');
            }
        }

        function showWeatherLayer(selection) {
            clearAnimation();
            activeLayers.forEach(id => {
                if (map.getLayer(id)) map.removeLayer(id);
            });
            activeSources.forEach(id => {
                if (map.getSource(id)) map.removeSource(id);
            });
            activeSources = [];
            activeLayers = [];
            activeLayerOpacities = [];

            const variables = weatherVariables(selection);
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
                activeLayerOpacities.push(item.opacity);
            });
            frameTimeLabel.textContent = 'Six hourly frames';
        }

        function toggleAnimation() {
            if (animationPreparing) return;
            if (animationTimer !== null) {
                clearInterval(animationTimer);
                animationTimer = null;
                animationToggle.textContent = 'Play';
                animationToggle.setAttribute('aria-pressed', 'false');
                return;
            }
            if (animationFrames.length && animationLayers.length) {
                runAnimation();
                return;
            }
            prepareAnimation();
        }

        map.on('load', () => showWeatherLayer(layerSelect.value));
        map.on('error', event => {
            const message = event?.error?.message || 'A map resource failed to load.';
            setStatus('Map error: ' + message);
        });
        map.once('idle', () => {
            if (!animationSources.length && activeSources.length && activeSources.every(id => map.getSource(id))) {
                setStatus('Weather layer loaded · ' + layerSelect.selectedOptions[0].textContent);
            }
        });
        timeRangeSelect.addEventListener('change', () => {
            clearAnimation();
            frameTimeLabel.textContent = timeRangeSelect.value === 'past' ? 'Past 6 hours' : 'Next 6 hours';
            setStatus('');
        });
        animationToggle.addEventListener('click', toggleAnimation);
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
