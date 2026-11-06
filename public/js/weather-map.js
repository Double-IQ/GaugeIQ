import * as maplibregl from 'https://unpkg.com/maplibre-gl@6.10.0/dist/maplibre-gl.mjs';

const OMWeatherMapLayer = await import('https://unpkg.com/@openmeteo/weather-map-layer@0.3.1/dist/index.mjs');

const appearanceDefaults = {
            mapStyle: 'dark',
            mapProjection: 'mercator',
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

        // The map can be restored from the browser back/forward cache after
        // changing Appearance. Re-read saved settings on restoration so the
        // protocol's colour scale (including per-colour cloud alpha) is rebuilt.
        const initialAppearanceSnapshot = JSON.stringify(appearance);
        window.addEventListener('pageshow', event => {
            if (!event.persisted) return;
            try {
                const saved = JSON.parse(localStorage.getItem('gaugeiqWeatherMapAppearance') || 'null');
                const restoredAppearance = saved && typeof saved === 'object'
                    ? { ...appearanceDefaults, ...saved }
                    : { ...appearanceDefaults };
                if (JSON.stringify(restoredAppearance) !== initialAppearanceSnapshot) {
                    window.location.reload();
                }
            } catch (error) {
                console.warn('Could not refresh weather-map appearance after returning from settings.', error);
            }
        });

        function hexToRgba(hex, alpha = 1) {
            const value = /^#[0-9a-f]{6}$/i.test(hex) ? hex.slice(1) : '000000';
            return [
                parseInt(value.slice(0, 2), 16),
                parseInt(value.slice(2, 4), 16),
                parseInt(value.slice(4, 6), 16),
                alpha
            ];
        }

        
const { BrowserBlockCache } = await import('https://unpkg.com/@openmeteo/file-reader@0.0.21/dist/esm/index.browser.js');

const persistentMapCache = new BrowserBlockCache({
    cacheName: 'om-gaugeiq-weather-map-blocks-v1',
    blockSize: 64 * 1024,
    maxBytes: 192 * 1024 * 1024,
    evictionFraction: 0.15
});

// Proxy Open-Meteo .om traffic through GaugeIQ while preserving the HTTP
// contract expected by OmHttpBackend: HEAD metadata with Content-Length,
// followed by byte-range GET requests.
const gaugeIqOriginalFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
    let request;
    try {
        request = input instanceof Request ? new Request(input, init) : new Request(input, init);
    } catch (error) {
        return gaugeIqOriginalFetch(input, init);
    }

    let parsedUrl;
    try {
        parsedUrl = new URL(request.url, window.location.href);
    } catch (error) {
        return gaugeIqOriginalFetch(input, init);
    }

    if (parsedUrl.protocol !== 'https:' ||
        parsedUrl.hostname !== 'openmeteo.s3.amazonaws.com' ||
        !parsedUrl.pathname.startsWith('/data_spatial/ecmwf_ifs/') ||
        !parsedUrl.pathname.endsWith('.om')) {
        return gaugeIqOriginalFetch(input, init);
    }

    const proxyUrl = new URL('/api/weather-map-proxy.php', window.location.origin);
    proxyUrl.searchParams.set('url', parsedUrl.href);

    // OmHttpBackend determines the file size exclusively from a HEAD
    // response's Content-Length. Use a same-origin JSON metadata request and
    // synthesize that HEAD response, avoiding hosting-stack HEAD header quirks.
    if (request.method === 'HEAD') {
        proxyUrl.searchParams.set('metadata', '1');
        return gaugeIqOriginalFetch(proxyUrl.href, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            signal: request.signal
        }).then(async response => {
            if (!response.ok) return response;
            const metadata = await response.json();
            if (!Number.isSafeInteger(Number(metadata.size)) || Number(metadata.size) <= 0) {
                throw new Error('Weather-map proxy returned invalid file-size metadata.');
            }
            const headers = new Headers({
                'Content-Length': String(metadata.size),
                'Accept-Ranges': 'bytes'
            });
            if (metadata.etag) headers.set('ETag', metadata.etag);
            if (metadata.last_modified) headers.set('Last-Modified', metadata.last_modified);
            return new Response(null, { status: 200, headers });
        });
    }

    return gaugeIqOriginalFetch(new Request(proxyUrl.href, request));
};

const protocolOptions = OMWeatherMapLayer.defaultOmProtocolSettings;
protocolOptions.fileReaderConfig = {
    ...protocolOptions.fileReaderConfig,
    cache: persistentMapCache
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
            style: appearance.mapStyle === 'light'
                ? 'https://tiles.openfreemap.org/styles/positron'
                : 'https://tiles.openfreemap.org/styles/dark',
            center: [config.longitude, config.latitude],
            zoom: savedMapZoom,
            maxZoom: 12,
            cooperativeGestures: true,
            attributionControl: false
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        const locationMarker = document.createElement('div');
        locationMarker.className = 'gaugeiq-map-location-marker';
        locationMarker.setAttribute('aria-hidden', 'true');
        new maplibregl.Marker({ element: locationMarker, anchor: 'center' })
            .setLngLat([config.longitude, config.latitude])
            .addTo(map);

        let weatherLayerToken = 0;
        let activeSources = [];
        let activeLayers = [];
        let activeLayerOpacities = [];
        let animationSources = [];
        let animationLayers = [];
        let animationFrames = [];
        let animationTimer = null;
        let animationPausedForMapInteraction = false;
        let animationToken = 0;
        let animationPreparing = false;
        let animationCurrentFrame = 0;
        let animationLoadTimeout = null;
        let animationLoadPoll = null;
        let animationLoadError = null;
        let animationFrameSourceAdder = null;

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
            animationPausedForMapInteraction = false;
            if (animationLoadTimeout !== null) {
                clearTimeout(animationLoadTimeout);
                animationLoadTimeout = null;
            }
            if (animationLoadPoll !== null) {
                clearInterval(animationLoadPoll);
                animationLoadPoll = null;
            }
            animationLoadError = null;
            animationFrameSourceAdder = null;
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

        function isAnimationFrameLoaded(frame) {
            return Boolean(
                frame &&
                frame.sourceIds.length > 0 &&
                frame.sourceIds.every(id => map.getSource(id) && map.isSourceLoaded(id))
            );
        }

        function selectedAnimationFrames() {
            return animationFrames;
        }

        function updateFrameLabel(index) {
            const frame = selectedAnimationFrames()[index];
            if (!frame) return;
            const label = new Date(frame.time).toLocaleString(undefined, {
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
            frameTimeLabel.textContent = (frame.type === 'past' ? 'Past' : 'Forecast') + ' · Frame ' + (index + 1) + '/6 · ' + label;
        }

        function removeAnimationFrameSources(frame) {
            if (!frame) return;
            frame.layerIds.forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            frame.sourceIds.forEach(id => { if (map.getSource(id)) map.removeSource(id); });
            const layerIds = new Set(frame.layerIds), sourceIds = new Set(frame.sourceIds);
            animationLayers = animationLayers.filter(item => !layerIds.has(item.id));
            animationSources = animationSources.filter(id => !sourceIds.has(id));
            frame.layerIds = [];
            frame.sourceIds = [];
        }

        function showFrame(index) {
            const frame = selectedAnimationFrames()[index];
            if (!frame) return;
            animationLayers.forEach(item => {
                if (map.getLayer(item.id)) {
                    map.setPaintProperty(item.id, 'raster-opacity', item.frameId === frame.id ? item.opacity : 0);
                }
            });
            updateFrameLabel(index);
        }

        function runAnimation() {
            const frames = selectedAnimationFrames();
            if (!frames.length || animationTimer !== null) return;

            // A manual restart after a failed frame gets one fresh retry cycle.
            const retryFrame = frames[(animationCurrentFrame + 1) % frames.length];
            if (retryFrame && retryFrame.loadAttempts >= 2 &&
                (retryFrame.loadError || Date.now() - (retryFrame.loadStartedAt || 0) > 45000)) {
                removeAnimationFrameSources(retryFrame);
                retryFrame.loadAttempts = 0;
                retryFrame.loadError = null;
            }

            showFrame(animationCurrentFrame);
            animationToggle.disabled = false;
            animationToggle.textContent = 'Pause';
            animationToggle.setAttribute('aria-pressed', 'true');
            animationTimer = setInterval(() => {
                const nextIndex = (animationCurrentFrame + 1) % frames.length;
                const nextFrame = frames[nextIndex];
                if (!nextFrame) return;

                if (!isAnimationFrameLoaded(nextFrame)) {
                    const age = Date.now() - (nextFrame.loadStartedAt || Date.now());
                    if (nextFrame.loadError || age > 45000) {
                        if ((nextFrame.loadAttempts || 0) < 2) {
                            removeAnimationFrameSources(nextFrame);
                            animationFrameSourceAdder(nextFrame);
                            setStatus('Retrying the next weather frame…');
                        } else {
                            clearInterval(animationTimer);
                            animationTimer = null;
                            animationToggle.textContent = 'Play';
                            animationToggle.setAttribute('aria-pressed', 'false');
                            setStatus('A weather frame could not be loaded. Press Play to retry.');
                        }
                    } else {
                        setStatus(nextFrame.type === 'past'
                            ? 'Loading the next past weather frame…'
                            : 'Loading the next forecast weather frame…');
                    }
                    return;
                }

                // Cross-fade between already-loaded raster layers. Keep all six
                // frame sources alive throughout playback so the outgoing image
                // remains underneath until the incoming frame is fully visible.
                animationCurrentFrame = nextIndex;
                showFrame(animationCurrentFrame);
                setStatus('');

            }, 1200);
        }

        async function prepareAnimation(autoStart = true) {
            clearAnimation();
            const token = animationToken;
            animationPreparing = true;
            animationToggle.disabled = true;
            setStatus('Preparing 6 hourly weather frames (3 past + 3 forecast)…');

            try {
                const scheduleResponse = await fetch('/api/weather-map-frames.php', { cache: 'no-store' });
                if (!scheduleResponse.ok) throw new Error('The scheduled weather-map frames could not be loaded.');
                const schedule = await scheduleResponse.json();
                if (token !== animationToken) return;

                if (!Array.isArray(schedule.frames) || schedule.frames.length !== 6) {
                    throw new Error('The server has not supplied all 6 scheduled weather frames.');
                }

                const scheduledFrames = schedule.frames.map(item => ({
                    id: item.type + '-' + item.slot,
                    type: item.type,
                    time: Date.parse(item.time),
                    url: item.url
                }));
                if (scheduledFrames.some(frame =>
                    !Number.isFinite(frame.time) ||
                    !/^https:\/\/openmeteo\.s3\.amazonaws\.com\/data_spatial\/ecmwf_ifs\/.+\.om$/.test(frame.url)
                )) {
                    throw new Error('The scheduled weather-map frame list contains an invalid frame.');
                }

                const pastFrames = scheduledFrames.filter(frame => frame.type === 'past');
                const futureFrames = scheduledFrames.filter(frame => frame.type === 'future');
                if (pastFrames.length !== 3 || futureFrames.length !== 3) {
                    throw new Error('The server schedule must contain three past and three forecast frames.');
                }

                // Open-Meteo requires delta_time_hours for accumulated variables
                // such as precipitation. Derive it from each run's actual valid-time
                // spacing; the first frame uses the interval to the following frame.
                const frameGroups = [futureFrames, pastFrames];
                frameGroups.forEach(group => {
                    group.sort((a, b) => a.time - b.time);
                    group.forEach((frame, index) => {
                        const neighbour = index === 0 ? group[1] : group[index - 1];
                        const deltaHours = neighbour
                            ? Math.abs(frame.time - neighbour.time) / 3600000
                            : 1;
                        if (!Number.isFinite(deltaHours) || deltaHours <= 0) {
                            throw new Error('The scheduled weather-map frame interval is invalid.');
                        }
                        frame.deltaTimeHours = deltaHours;
                    });
                });

                const variables = weatherVariables(layerSelect.value);
                animationFrames = [...pastFrames, ...futureFrames].map(frame => ({
                    ...frame,
                    layerIds: [],
                    sourceIds: []
                }));
                const addFrameSources = frame => {
                    if (!frame || frame.sourceIds.length) return;
                    variables.forEach((item, variableIndex) => {
                        frame.loadAttempts = (frame.loadAttempts || 0) + (variableIndex === 0 ? 1 : 0);
                        frame.loadStartedAt = Date.now();
                        frame.loadError = null;
                        const sourceId = 'gaugeiq-animation-source-' + token + '-' + frame.id + '-a' + frame.loadAttempts + '-' + variableIndex;
                        const layerId = 'gaugeiq-animation-layer-' + token + '-' + frame.id + '-a' + frame.loadAttempts + '-' + variableIndex;
                        const dataUrl = new URL(frame.url);
                        dataUrl.searchParams.set('delta_time_hours', String(frame.deltaTimeHours));
                        dataUrl.searchParams.set('variable', item.variable);

                        map.addSource(sourceId, {
                            type: 'raster',
                            url: 'om://' + dataUrl.href,
                            maxzoom: 12
                        });
                        map.addLayer({
                            id: layerId,
                            type: 'raster',
                            source: sourceId,
                            paint: {
                                'raster-opacity': 0.001,
                                'raster-opacity-transition': { duration: 500, delay: 0 }
                            }
                        });

                        animationSources.push(sourceId);
                        frame.sourceIds.push(sourceId);
                        animationLayers.push({ id: layerId, frameId: frame.id, opacity: item.opacity });
                        frame.layerIds.push(layerId);
                    });
                        };

                animationFrameSourceAdder = addFrameSources;
                animationPreparing = false;
                animationCurrentFrame = 0;
                animationLoadError = null;
                const initialFrame = () => selectedAnimationFrames()[animationCurrentFrame];
                const initialNextFrame = () => selectedAnimationFrames()[(animationCurrentFrame + 1) % selectedAnimationFrames().length];
                // Load every scheduled frame before enabling playback. This avoids
                // network/source creation during animation and guarantees the
                // six-frame sequence is ready before the first transition.
                selectedAnimationFrames().forEach(addFrameSources);
                const isFrameLoaded = frame => isAnimationFrameLoaded(frame);
                animationLoadTimeout = setTimeout(() => {
                    if (token !== animationToken) return;
                    const requiredFrames = selectedAnimationFrames();
                    const pendingSources = requiredFrames.flatMap(frame =>
                        frame ? frame.sourceIds.filter(id => !map.getSource(id) || !map.isSourceLoaded(id)) : []
                    );
                    const detail = [
                        animationLoadError,
                        pendingSources.length ? 'Still loading: ' + pendingSources.join(', ') : ''
                    ].filter(Boolean).join(' | ');
                    clearAnimation();
                    setStatus('The first hourly weather frame could not be loaded.' + (detail ? ' ' + detail : ''));
                }, 120000);

                const waitForInitialFrame = () => {
                    if (token !== animationToken) return;
                    if (!selectedAnimationFrames().every(isFrameLoaded)) return;
                    if (animationLoadTimeout !== null) {
                        clearTimeout(animationLoadTimeout);
                        animationLoadTimeout = null;
                    }
                    if (animationLoadPoll !== null) {
                        clearInterval(animationLoadPoll);
                        animationLoadPoll = null;
                    }
                    activeLayers.forEach(id => {
                        if (map.getLayer(id)) map.setPaintProperty(id, 'raster-opacity', 0);
                    });
                    animationToggle.disabled = false;
                    setStatus('');
                    showFrame(animationCurrentFrame);
                    // Keep the visible frame and the next frame loaded.
                    if (autoStart) runAnimation();
                };
                waitForInitialFrame();
                animationLoadPoll = setInterval(waitForInitialFrame, 500);
            } catch (error) {
                if (token !== animationToken) return;
                animationPreparing = false;
                animationToggle.disabled = false;
                animationToggle.textContent = 'Play';
                animationToggle.setAttribute('aria-pressed', 'false');
                setStatus(error?.message || 'Weather animation could not be prepared.');
            }
        }

        async function resolveCurrentOmUrls(variables) {
            const response = await fetch('/api/weather-map-frames.php', { cache: 'no-store' });
            if (!response.ok) throw new Error('The scheduled weather-map frames could not be loaded.');
            const schedule = await response.json();
            const frames = Array.isArray(schedule.frames) ? schedule.frames : [];
            if (frames.length !== 6) throw new Error('The server has not supplied all 6 scheduled weather frames.');

            const now = Date.now();
            const currentFrame = frames
                .filter(frame => Number.isFinite(Date.parse(frame.time)))
                .sort((a, b) => Math.abs(Date.parse(a.time) - now) - Math.abs(Date.parse(b.time) - now))[0];
            if (!currentFrame) throw new Error('No usable current weather-map frame was found.');

            const frameGroup = frames
                .filter(frame => frame.type === currentFrame.type && Number.isFinite(Date.parse(frame.time)))
                .sort((a, b) => Date.parse(a.time) - Date.parse(b.time));
            const currentIndex = frameGroup.findIndex(frame =>
                frame.slot === currentFrame.slot && frame.time === currentFrame.time
            );
            const neighbour = currentIndex === 0
                ? frameGroup[1]
                : frameGroup[currentIndex - 1];
            const deltaTimeHours = neighbour
                ? Math.abs(Date.parse(currentFrame.time) - Date.parse(neighbour.time)) / 3600000
                : 1;
            if (!Number.isFinite(deltaTimeHours) || deltaTimeHours <= 0) {
                throw new Error('The current weather-map frame interval is invalid.');
            }

            return variables.map(item => {
                const url = new URL(currentFrame.url);
                url.searchParams.set('delta_time_hours', String(deltaTimeHours));
                url.searchParams.set('variable', item.variable);
                return url.href;
            });
        }

        function addWeatherSources(urls, variables, suffix, opacityScale) {
            const sourceIds = [];
            const layerIds = [];
            variables.forEach((item, index) => {
                const sourceId = 'gaugeiq-weather-source-' + suffix + '-' + index;
                const layerId = 'gaugeiq-weather-layer-' + suffix + '-' + index;
                map.addSource(sourceId, {
                    type: 'raster',
                    url: 'om://' + urls[index],
                    maxzoom: 12
                });
                map.addLayer({
                    id: layerId,
                    type: 'raster',
                    source: sourceId,
                    paint: { 'raster-opacity': item.opacity * opacityScale }
                });
                sourceIds.push(sourceId);
                layerIds.push(layerId);
            });
            return { sourceIds, layerIds };
        }

        function waitForWeatherSources(sourceIds, token, onLoaded, onTimeout) {
            let finished = false;
            const timeout = setTimeout(() => {
                if (finished || token !== weatherLayerToken) return;
                finished = true;
                onTimeout();
            }, 60000);
            const check = () => {
                if (finished) return;
                if (token !== weatherLayerToken) {
                    finished = true;
                    clearTimeout(timeout);
                    return;
                }
                if (sourceIds.every(id => map.isSourceLoaded(id))) {
                    finished = true;
                    clearTimeout(timeout);
                    onLoaded();
                    return;
                }
                map.once('idle', check);
            };
            map.once('idle', check);
        }

        function showWeatherLayer(selection) {
            clearAnimation();
            const token = ++weatherLayerToken;
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
            const isCombined = selection === 'precipitation_cloud_cover';
            let savedSnapshot = null;
            if (isCombined) {
                try {
                    const saved = JSON.parse(localStorage.getItem('gaugeiqWeatherMapCombinedSnapshot') || 'null');
                    if (saved && Array.isArray(saved.urls) && saved.urls.length === variables.length &&
                        Number.isFinite(saved.savedAt)) {
                        savedSnapshot = saved;
                    } else {
                        localStorage.removeItem('gaugeiqWeatherMapCombinedSnapshot');
                    }
                } catch (error) {
                    console.warn('Could not read the saved Combined weather-map snapshot.', error);
                }
            }

            if (savedSnapshot) {
                const cached = addWeatherSources(savedSnapshot.urls, variables, 'cached-' + token, 1);
                activeSources = cached.sourceIds;
                activeLayers = cached.layerIds;
                activeLayerOpacities = variables.map(item => item.opacity);
                const ageHours = Math.max(0, Math.floor((Date.now() - savedSnapshot.savedAt) / 3600000));
                const ageLabel = ageHours < 1 ? 'less than an hour old'
                    : ageHours < 24 ? ageHours + ' hours old'
                    : Math.floor(ageHours / 24) + ' days old';
                setStatus('Showing saved Combined map · ' + ageLabel + ' · updating…');
            } else {
                setStatus('Loading ' + layerSelect.selectedOptions[0].textContent.toLowerCase() + '…');
            }

            frameTimeLabel.textContent = 'Six hourly frames';

            (async () => {
                try {
                    const latestUrls = await resolveCurrentOmUrls(variables);
                    if (token !== weatherLayerToken) return;

                    const latest = addWeatherSources(latestUrls, variables, 'latest-' + token, savedSnapshot ? 0.001 : 1);
                    if (!savedSnapshot) {
                        activeSources = latest.sourceIds;
                        activeLayers = latest.layerIds;
                        activeLayerOpacities = variables.map(item => item.opacity);
                    }

                    waitForWeatherSources(latest.sourceIds, token, () => {
                        latest.layerIds.forEach((id, index) => {
                            if (map.getLayer(id)) map.setPaintProperty(id, 'raster-opacity', variables[index].opacity);
                        });

                        if (savedSnapshot) {
                            activeLayers.forEach(id => {
                                if (map.getLayer(id)) map.removeLayer(id);
                            });
                            activeSources.forEach(id => {
                                if (map.getSource(id)) map.removeSource(id);
                            });
                            activeSources = latest.sourceIds;
                            activeLayers = latest.layerIds;
                            activeLayerOpacities = variables.map(item => item.opacity);
                        }

                        if (isCombined) {
                            try {
                                localStorage.setItem('gaugeiqWeatherMapCombinedSnapshot', JSON.stringify({
                                    urls: latestUrls,
                                    savedAt: Date.now()
                                }));
                            } catch (error) {
                                console.warn('Could not save the Combined weather-map snapshot.', error);
                            }
                        }
                        setStatus('Weather layer loaded · ' + layerSelect.selectedOptions[0].textContent);
                    }, () => {
                        if (savedSnapshot) {
                            latest.layerIds.forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
                            latest.sourceIds.forEach(id => { if (map.getSource(id)) map.removeSource(id); });
                            setStatus('Showing saved Combined map · latest update is taking longer than expected.');
                        } else {
                            setStatus('Weather layer took too long to load. Please try again.');
                        }
                    });
                } catch (error) {
                    if (token !== weatherLayerToken) return;
                    setStatus(savedSnapshot
                        ? 'Showing saved Combined map · latest update unavailable.'
                        : (error?.message || 'Weather map could not be loaded.'));
                }
            })();
        }

        function toggleAnimation() {
            if (animationPreparing) return;
            if (animationPausedForMapInteraction) {
                animationPausedForMapInteraction = false;
                animationToggle.textContent = 'Play';
                animationToggle.setAttribute('aria-pressed', 'false');
                return;
            }
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

        map.on('load', () => {
            map.setProjection({ type: appearance.mapProjection === 'globe' ? 'globe' : 'mercator' });
            // Prepare one chronological sequence: three actual past frames followed
            // by three forecast frames. All six frames are loaded before playback.
            // Playback remains under the user's control.
            prepareAnimation(false);
        });
        // Avoid changing raster layers while MapLibre is re-rendering the map
        // during zoom, pan, pitch or rotation gestures. Resume only if playback
        // was active before the user interacted with the map.
        map.on('movestart', () => {
            if (animationTimer === null) return;
            clearInterval(animationTimer);
            animationTimer = null;
            animationPausedForMapInteraction = true;
        });
        map.on('moveend', () => {
            if (!animationPausedForMapInteraction) return;
            animationPausedForMapInteraction = false;
            runAnimation();
        });
        map.on('error', event => {
            const message = event?.error?.message || 'A map resource failed to load.';
            const sourceId = event?.sourceId || event?.source?.id || '';
            if (String(sourceId).startsWith('gaugeiq-animation-source-')) {
                const frame = animationFrames.find(item => item.sourceIds.includes(sourceId));
                if (frame) frame.loadError = message;
                if (animationLoadTimeout !== null) {
                    animationLoadError = (frame ? frame.id + ' · ' : '') + sourceId + ' · ' + message;
                }
            }
            setStatus('Map error' + (sourceId ? ' (' + sourceId + ')' : '') + ': ' + message);
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
            if (mapInstance && mapInstance.isStyleLoaded()) {
                clearAnimation();
                showWeatherLayer(layerSelect.value);
            }
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
