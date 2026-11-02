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

        
class PersistentMapBlockCache {
    constructor() {
        this.cacheName = 'gaugeiq-weather-map-blocks-v1';
        this.blockSizeBytes = 64 * 1024;
        this.maxBytes = 192 * 1024 * 1024;
        this.cachePromise = null;
        this.inflight = new Map();
    }

    blockSize() { return this.blockSizeBytes; }

    async openCache() {
        this.cachePromise ??= caches.open(this.cacheName);
        return this.cachePromise;
    }

    cacheRequest(key) {
        return new Request(new URL('/__gaugeiq_weather_map_cache__/' + encodeURIComponent(String(key)), location.origin));
    }

    async size(key) {
        const response = await (await this.openCache()).match(this.cacheRequest(key));
        const value = response?.headers.get('X-Om-File-Size');
        return value ? Number(value) : undefined;
    }

    async get(key, fetchFn, fileSize, signal) {
        if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
        const cache = await this.openCache();
        const request = this.cacheRequest(key);
        const cached = await cache.match(request);
        if (cached) return new Uint8Array(await cached.arrayBuffer());

        let entry = this.inflight.get(String(key));
        if (!entry) {
            const controller = new AbortController();
            entry = { controller, subscribers: 0, promise: null };
            entry.promise = (async () => {
                const data = await fetchFn(controller.signal);
                const buffer = data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);
                const headers = {
                    'Content-Type': 'application/octet-stream',
                    'Content-Length': String(data.byteLength),
                    'X-Om-Created-At': String(Date.now())
                };
                if (fileSize !== undefined) headers['X-Om-File-Size'] = String(fileSize);
                await cache.put(request, new Response(buffer, { status: 200, headers }));
                this.evictIfNeeded(cache).catch(error => console.warn('Weather-map cache eviction failed.', error));
                return data;
            })().finally(() => {
                if (this.inflight.get(String(key)) === entry) this.inflight.delete(String(key));
            });
            this.inflight.set(String(key), entry);
        }

        entry.subscribers++;
        return new Promise((resolve, reject) => {
            let settled = false;
            const finish = (callback, value) => {
                if (settled) return;
                settled = true;
                if (signal) signal.removeEventListener('abort', onAbort);
                entry.subscribers = Math.max(0, entry.subscribers - 1);
                callback(value);
            };
            const onAbort = () => {
                finish(reject, new DOMException('Aborted', 'AbortError'));
                if (entry.subscribers === 0) entry.controller.abort();
            };
            if (signal) {
                if (signal.aborted) return onAbort();
                signal.addEventListener('abort', onAbort, { once: true });
            }
            entry.promise.then(
                data => finish(resolve, data),
                error => finish(reject, error)
            );
        });
    }

    async prefetch(key, fetchFn, fileSize, signal) {
        await this.get(key, fetchFn, fileSize, signal).catch(() => {});
    }

    async evictIfNeeded(cache) {
        const keys = await cache.keys();
        const entries = [];
        let total = 0;
        for (const request of keys) {
            const response = await cache.match(request);
            const size = Number(response?.headers.get('Content-Length') || 0);
            total += size;
            entries.push({
                request,
                size,
                created: Number(response?.headers.get('X-Om-Created-At') || 0)
            });
        }
        if (total <= this.maxBytes) return;
        entries.sort((a, b) => a.created - b.created);
        for (const entry of entries) {
            if (total <= this.maxBytes * 0.85) break;
            await cache.delete(entry.request);
            total -= entry.size;
        }
    }

    async clear() {
        for (const entry of this.inflight.values()) entry.controller.abort();
        this.inflight.clear();
        this.cachePromise = null;
        await caches.delete(this.cacheName);
    }
}

const protocolOptions = OMWeatherMapLayer.defaultOmProtocolSettings;
protocolOptions.fileReaderConfig = {
    ...protocolOptions.fileReaderConfig,
    cache: new PersistentMapBlockCache()
};
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
        let animationLoadTimeout = null;

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
            if (animationLoadTimeout !== null) {
                clearTimeout(animationLoadTimeout);
                animationLoadTimeout = null;
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
                const nextFrame = (animationCurrentFrame + 1) % animationFrames.length;
                const frame = animationFrames[nextFrame];
                if (!frame || !frame.sourceIds.every(id => map.isSourceLoaded(id))) return;
                animationCurrentFrame = nextFrame;
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
                let pastIndex = -1;
                let futureIndex = -1;
                parsedTimes.forEach((timestamp, index) => {
                    if (timestamp <= now) pastIndex = index;
                    if (futureIndex === -1 && timestamp >= now) futureIndex = index;
                });
                const currentIndex = timeRangeSelect.value === 'past'
                    ? (pastIndex >= 0 ? pastIndex : 0)
                    : (futureIndex >= 0 ? futureIndex : Math.max(0, pastIndex));

                let frameSpecs = [];
                if (timeRangeSelect.value === 'past') {
                    const currentTime = parsedTimes[currentIndex];
                    const firstFrameTime = currentTime - (5 * 60 * 60 * 1000);
                    const previousRunTime = Math.floor((firstFrameTime - 60 * 60 * 1000) / (6 * 60 * 60 * 1000)) * (6 * 60 * 60 * 1000) - (6 * 60 * 60 * 1000);
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
                    const frame = { time: frameSpec.time, layerIds: [], sourceIds: [] };
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
                            paint: { 'raster-opacity': 0.001 }
                        });

                        animationSources.push(sourceId);
                        frame.sourceIds.push(sourceId);
                        animationLayers.push({ id: layerId, frameIndex: framePosition, opacity: item.opacity });
                        frame.layerIds.push(layerId);
                    });
                    animationFrames.push(frame);
                });

                animationPreparing = false;
                animationCurrentFrame = 0;
                animationLoadTimeout = setTimeout(() => {
                    if (token !== animationToken) return;
                    clearAnimation();
                    setStatus('The first weather frame did not load in time. Please try again.');
                }, 60000);
                const firstFrameLoaded = () => {
                    const firstFrame = animationFrames[0];
                    return !!firstFrame && firstFrame.sourceIds.every(id => map.isSourceLoaded(id));
                };
                const waitForInitialFrame = () => {
                    if (token !== animationToken) return;
                    if (!firstFrameLoaded()) {
                        map.once('idle', waitForInitialFrame);
                        return;
                    }
                    if (animationLoadTimeout !== null) {
                        clearTimeout(animationLoadTimeout);
                        animationLoadTimeout = null;
                    }
                    activeLayers.forEach(id => {
                        if (map.getLayer(id)) map.setPaintProperty(id, 'raster-opacity', 0);
                    });
                    setStatus('');
                    runAnimation();
                };
                map.once('idle', waitForInitialFrame);
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
