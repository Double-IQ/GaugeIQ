(() => {
    'use strict';
    const root = document.getElementById('moonTideHud');
    if (!root) return;
    const lat = Number(root.dataset.latitude);
    const lon = Number(root.dataset.longitude);
    const zone = root.dataset.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const validLocation = root.dataset.latitude.trim() !== '' && root.dataset.longitude.trim() !== '' && Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180;
    const $ = id => document.getElementById(id);
    const synodic = 29.530588853;
    const knownNewMoon = Date.UTC(2000, 0, 6, 18, 14);
    const now = new Date();
    const cycle = ((now.getTime() - knownNewMoon) / 86400000 % synodic + synodic) % synodic;
    const phase = cycle / synodic;
    const illumination = (1 - Math.cos(2 * Math.PI * phase)) / 2;
    const names = ['NEW MOON','WAXING CRESCENT','FIRST QUARTER','WAXING GIBBOUS','FULL MOON','WANING GIBBOUS','LAST QUARTER','WANING CRESCENT'];
    const phaseName = names[Math.round(phase * 8) % 8];
    const nextFullDays = ((0.5 - phase + 1) % 1) * synodic;
    const nextFull = new Date(now.getTime() + nextFullDays * 86400000);
    const anomaly = 2 * Math.PI * ((cycle - 1.0) / 27.55455);
    const distance = 385001 - 20905 * Math.cos(anomaly);
    $('mthPhaseName').textContent = phaseName;
    $('mthIllumination').textContent = (illumination * 100).toFixed(1) + '%';
    $('mthLunarAge').textContent = cycle.toFixed(1) + ' DAYS';
    $('mthMoonDistance').textContent = Math.round(distance).toLocaleString() + ' KM';
    $('mthNextFull').textContent = nextFull.toLocaleDateString(undefined, {timeZone:zone,day:'2-digit',month:'short'});
    const disc = $('mthMoonDisc');
    if (disc) {
        disc.style.setProperty('--mth-phase', String(phase));
        disc.style.setProperty('--mth-illumination', String(illumination));
        disc.style.setProperty('--mth-shadow-width', (50 * (1 - illumination)).toFixed(1) + '%');
        disc.style.setProperty('--mth-shadow-x', (phase < 0.02 || phase > 0.98) ? '50%' : (phase < 0.5 ? '0%' : '100%'));
        disc.dataset.phase = phase.toFixed(4);
    }
    function solarEvent(day, latitude, longitude, sunrise) {
        const n = Math.floor((Date.UTC(day.getUTCFullYear(),day.getUTCMonth(),day.getUTCDate()) - Date.UTC(day.getUTCFullYear(),0,0)) / 86400000);
        const lngHour = longitude / 15;
        const t = n + ((sunrise ? 6 : 18) - lngHour) / 24;
        const m = 0.9856 * t - 3.289;
        let l = m + 1.916 * Math.sin(m * Math.PI / 180) + 0.020 * Math.sin(2 * m * Math.PI / 180) + 282.634;
        l = (l % 360 + 360) % 360;
        let ra = Math.atan(0.91764 * Math.tan(l * Math.PI / 180)) * 180 / Math.PI;
        ra = (ra % 360 + 360) % 360;
        ra += Math.floor(l / 90) * 90 - Math.floor(ra / 90) * 90;
        ra /= 15;
        const sinDec = 0.39782 * Math.sin(l * Math.PI / 180);
        const cosDec = Math.cos(Math.asin(sinDec));
        const cosH = (Math.cos(90.833 * Math.PI / 180) - sinDec * Math.sin(latitude * Math.PI / 180)) / (cosDec * Math.cos(latitude * Math.PI / 180));
        if (cosH > 1 || cosH < -1) return null;
        const h = (sunrise ? 360 - Math.acos(cosH) * 180 / Math.PI : Math.acos(cosH) * 180 / Math.PI) / 15;
        const localMean = h + ra - 0.06571 * t - 6.622;
        let utcHour = (localMean - lngHour) % 24;
        if (utcHour < 0) utcHour += 24;
        return new Date(Date.UTC(day.getUTCFullYear(),day.getUTCMonth(),day.getUTCDate()) + utcHour * 3600000);
    }
    function timeLabel(date) {
        if (!date) return 'POLAR';
        try { return new Intl.DateTimeFormat('en-GB',{timeZone:zone,hour:'2-digit',minute:'2-digit',hour12:false}).format(date); }
        catch { return date.toISOString().slice(11,16) + ' UTC'; }
    }
    if (validLocation) {
        const localDateParts = new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now).reduce((out,part) => (out[part.type] = part.value, out), {});
        const locationDay = new Date(Date.UTC(Number(localDateParts.year),Number(localDateParts.month)-1,Number(localDateParts.day)));
        const sunrise = solarEvent(locationDay,lat,lon,true);
        const sunset = solarEvent(locationDay,lat,lon,false);
        $('mthSunrise').textContent = timeLabel(sunrise);
        $('mthSunset').textContent = timeLabel(sunset);
        if (sunrise && sunset) {
            const noon = new Date((sunrise.getTime() + sunset.getTime()) / 2);
            const minutes = Math.round((sunset - sunrise) / 60000);
            $('mthSolarNoon').textContent = timeLabel(noon);
            $('mthDaylight').textContent = Math.floor(minutes/60) + 'H ' + String(minutes%60).padStart(2,'0') + 'M';
        } else {
            $('mthSolarNoon').textContent = '—';
            $('mthDaylight').textContent = 'POLAR DAY/NIGHT';
        }
        const marker = root.querySelector('.mth-location-marker');
        if (marker) {
            marker.style.left = (50 + (lon / 180) * 40).toFixed(2) + '%';
            marker.style.top = (50 - (lat / 90) * 40).toFixed(2) + '%';
            marker.setAttribute('aria-label','Selected location: ' + (root.dataset.locationName || 'GaugeIQ location') + ' (' + lat.toFixed(2) + ', ' + lon.toFixed(2) + ')');
        }
    } else {
        ['mthSunrise','mthSolarNoon','mthSunset','mthDaylight'].forEach(id => $(id).textContent = 'LOCATION NEEDED');
    }

    // Low-precision lunar orbital elements (Meeus/Schlyter-style) and
    // Greenwich mean sidereal time keep the Earth, Moon and location on one clock.
    const rad = Math.PI / 180;
    const norm360 = value => (value % 360 + 360) % 360;
    const orbitalElements = date => {
        const d = (date.getTime() - Date.UTC(2000,0,0,0,0,0)) / 86400000;
        const N = norm360(125.1228 - 0.0529538083 * d);
        const inclination = 5.1454;
        const w = norm360(318.0634 + 0.1643573223 * d);
        const a = 60.2666;
        const e = 0.0549;
        const M = norm360(115.3654 + 13.0649929509 * d);
        const E = M + (180 / Math.PI) * e * Math.sin(M * rad) * (1 + e * Math.cos(M * rad));
        const xv = a * (Math.cos(E * rad) - e);
        const yv = a * Math.sqrt(1 - e * e) * Math.sin(E * rad);
        const v = Math.atan2(yv, xv) / rad;
        const r = Math.sqrt(xv*xv + yv*yv);
        const xh = r * (Math.cos(N*rad)*Math.cos((v+w)*rad) - Math.sin(N*rad)*Math.sin((v+w)*rad)*Math.cos(inclination*rad));
        const yh = r * (Math.sin(N*rad)*Math.cos((v+w)*rad) + Math.cos(N*rad)*Math.sin((v+w)*rad)*Math.cos(inclination*rad));
        const zh = r * Math.sin((v+w)*rad) * Math.sin(inclination*rad);
        const eclLon = norm360(Math.atan2(yh,xh) / rad);
        const eclLat = Math.atan2(zh,Math.sqrt(xh*xh+yh*yh)) / rad;
        const obliq = 23.4393 - 3.563e-7 * d;
        const xe = xh;
        const ye = yh * Math.cos(obliq*rad) - zh * Math.sin(obliq*rad);
        const ze = yh * Math.sin(obliq*rad) + zh * Math.cos(obliq*rad);
        const ra = norm360(Math.atan2(ye,xe) / rad);
        const dec = Math.atan2(ze,Math.sqrt(xe*xe+ye*ye)) / rad;
        return {ra,dec,distanceKm:r*6378.14,eclipticLongitude:eclLon,eclipticLatitude:eclLat};
    };
    const solarCoordinates = date => {
        const d = (date.getTime() - Date.UTC(2000,0,0,0,0,0)) / 86400000;
        const w = 282.9404 + 4.70935e-5*d;
        const M = norm360(356.0470 + 0.9856002585*d);
        const L = norm360(w + M);
        const obliq = 23.4393 - 3.563e-7*d;
        const x = Math.cos(L*rad);
        const y = Math.cos(obliq*rad)*Math.sin(L*rad);
        const z = Math.sin(obliq*rad)*Math.sin(L*rad);
        return {ra:norm360(Math.atan2(y,x)/rad),dec:Math.atan2(z,Math.sqrt(x*x+y*y))/rad,longitude:L};
    };
    const gmstDegrees = date => {
        const jd = date.getTime()/86400000 + 2440587.5;
        const T = (jd - 2451545.0)/36525;
        return norm360(280.46061837 + 360.98564736629*(jd-2451545) + 0.000387933*T*T - T*T*T/38710000);
    };
    const orbitMoon = $('mthOrbitMoon');
    const globe = root.querySelector('.mth-real-earth');
    const locationMarker = $('mthLocationMarker');
    const timeScrubber = $('mthTimeScrubber');
    const trackerDisplay = root.querySelector('.mth-tracker-display');
    const trackerSize = () => trackerDisplay ? Math.min(trackerDisplay.clientWidth,520) : 340;
    function updateEarthMoonTracker(date) {
        if (!globe || !orbitMoon || !date) return;
        const moonPosition = orbitalElements(date);
        const sunPosition = solarCoordinates(date);
        const gmst = gmstDegrees(date);
        const moonHourAngle = (moonPosition.ra - gmst) * rad;
        const sunHourAngle = (sunPosition.ra - gmst) * rad;
        const moonX = Math.sin(moonHourAngle) * Math.cos(moonPosition.dec*rad);
        const moonY = -Math.sin(moonPosition.dec*rad);
        const moonZ = Math.cos(moonHourAngle) * Math.cos(moonPosition.dec*rad);
        const sunX = Math.sin(sunHourAngle) * Math.cos(sunPosition.dec*rad);
        const sunY = -Math.sin(sunPosition.dec*rad);
        const orbitRadius = 124;
        orbitMoon.style.left = (130 + moonX * orbitRadius - 15) + 'px';
        orbitMoon.style.top = (130 + moonY * orbitRadius - 15) + 'px';
        orbitMoon.style.transform = 'none';
        orbitMoon.style.opacity = moonZ < -0.1 ? '.62' : '1';
        const phaseAngle = Math.acos(Math.max(-1,Math.min(1,
            Math.sin(moonPosition.dec*rad)*Math.sin(sunPosition.dec*rad) +
            Math.cos(moonPosition.dec*rad)*Math.cos(sunPosition.dec*rad)*Math.cos((moonPosition.ra-sunPosition.ra)*rad)
        )));
        const lit = (1 + Math.cos(Math.PI - phaseAngle))/2;
        orbitMoon.style.setProperty('--mth-moon-lit',String(lit));
        const sunVector = root.querySelector('.mth-sun-vector');
        if (sunVector) sunVector.style.setProperty('--mth-sun-angle',Math.atan2(sunY,sunX)+'rad');
        const axis = root.querySelector('.mth-tidal-axis');
        if (axis) axis.style.transform = 'translate(-50%,-50%) rotate(' + (Math.atan2(moonY,moonX)*180/Math.PI) + 'deg)';
        if (locationMarker && validLocation) {
            const delta = (lon-gmst)*rad;
            const markerX = Math.cos(lat*rad)*Math.sin(delta);
            const markerY = -Math.sin(lat*rad);
            const markerZ = Math.cos(lat*rad)*Math.cos(delta);
            const globeRadiusPercent = 46;
            locationMarker.style.left = (50 + markerX*globeRadiusPercent) + '%';
            locationMarker.style.top = (50 + markerY*globeRadiusPercent) + '%';
            locationMarker.style.opacity = markerZ > 0 ? '1' : '.22';
            locationMarker.style.zIndex = markerZ > 0 ? '5' : '0';
            locationMarker.title = (root.dataset.locationName || 'Selected location') + (markerZ > 0 ? ' · visible hemisphere' : ' · far side of Earth');
            const locationLabel = (root.dataset.locationName || 'LOCATION').toUpperCase();
            $('mthOrbitLocation').textContent = locationLabel + ' · ' + Math.abs(lat).toFixed(1) + (lat >= 0 ? 'N' : 'S') + ' ' + Math.abs(lon).toFixed(1) + (lon >= 0 ? 'E' : 'W');
        }
        $('mthOrbitDistance').textContent = Math.round(moonPosition.distanceKm).toLocaleString() + ' KM';
        $('mthOrbitPosition').textContent = norm360(moonPosition.eclipticLongitude).toFixed(1) + '° ECL.';
        const hourOffset = (date.getTime()-now.getTime())/3600000;
        $('mthOrbitTime').textContent = Math.abs(hourOffset)<0.01 ? 'NOW · ' + timeLabel(date) : (hourOffset>0?'+':'') + hourOffset.toFixed(2) + ' H · ' + timeLabel(date);
        $('mthOrbitState').textContent = 'CALCULATED POSITION';
        globe.style.setProperty('--mth-earth-turn',norm360(gmst)+'deg');
        // Keep the equirectangular texture's Greenwich meridian aligned with the
        // camera-facing meridian. The texture must move west as Earth rotates east.
        const textureWidth = 300;
        const globeViewportWidth = globe.clientWidth;
        const textureOffset = (globeViewportWidth / 2) - (norm360(gmst) / 360) * textureWidth;
        globe.style.backgroundPosition = textureOffset.toFixed(2) + 'px center';
        globe.style.setProperty('--mth-sun-side',sunX>=0?'right':'left');
        globe.setAttribute('aria-label','Rotating Earth. Moon range ' + Math.round(moonPosition.distanceKm).toLocaleString() + ' kilometres. ' + (root.dataset.locationName || 'Selected location') + ' marker updates with Earth rotation.');
    }
    if (timeScrubber) {
        timeScrubber.addEventListener('input',() => {
            const previewDate = new Date(now.getTime() + Number(timeScrubber.value)*3600000);
            updateEarthMoonTracker(previewDate);
        });
    }
    updateEarthMoonTracker(now);
    const liveTrackerTimer = window.setInterval(() => {
        if (timeScrubber && Number(timeScrubber.value) !== 0) return;
        updateEarthMoonTracker(new Date());
    },60000);

    function tideUrl(mode, start, end, stationId = null) {
        const params = new URLSearchParams({
            mode, latitude: String(lat), longitude: String(lon),
            start: start.toISOString(), end: end.toISOString(), units: 'meters'
        });
        if (stationId) params.set('station', stationId);
        return 'api/tides.php?' + params.toString();
    }
    async function fetchTidePayload(mode, start, end, stationId = null, timeoutMs = 30000) {
        // Scope cached data to the exact prediction window and station.
        const cacheKey = 'gaugeiq-mth-tides-v3:' + mode + ':' + lat.toFixed(3) + ':' + lon.toFixed(3) + ':' + start.toISOString() + ':' + end.toISOString() + ':' + (stationId || 'nearest');
        try {
            const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
            if (cached && cached.savedAt && Date.now() - cached.savedAt < 15 * 60 * 1000) return cached.payload;
        } catch { /* Ignore unavailable or invalid session cache. */ }
        const controller = new AbortController();
        let timedOut = false;
        let timeoutId;
        // Race the whole request (including response parsing) against a hard
        // deadline. Some mobile browsers can leave an aborted fetch pending;
        // the independent rejection guarantees the HUD always leaves loading.
        const requestPromise = (async () => {
            const response = await fetch(tideUrl(mode, start, end, stationId), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller.signal
            });
            let payload;
            try {
                payload = await response.json();
            } catch {
                throw new Error('Tide ' + mode + ' request returned an invalid JSON response (HTTP ' + response.status + ').');
            }
            if (!response.ok) {
                const details = Array.isArray(payload?.errors)
                    ? payload.errors.map(item => [item?.path, item?.message].filter(Boolean).join(': ')).filter(Boolean).join('; ')
                    : '';
                const detail = [payload?.message, details].filter(Boolean).join(' — ');
                throw new Error(detail || 'Tide ' + mode + ' request returned HTTP ' + response.status);
            }
            return payload;
        })();
        const deadlinePromise = new Promise((resolve, reject) => {
            timeoutId = window.setTimeout(() => {
                timedOut = true;
                controller.abort();
                reject(new Error('Tide ' + mode + ' request timed out after ' + Math.round(timeoutMs / 1000) + ' seconds.'));
            }, timeoutMs);
        });
        try {
            const payload = await Promise.race([requestPromise, deadlinePromise]);
            try { sessionStorage.setItem(cacheKey, JSON.stringify({savedAt:Date.now(),payload})); } catch { /* Cache is optional. */ }
            return payload;
        } catch (error) {
            if (timedOut) throw new Error('Tide ' + mode + ' request timed out after ' + Math.round(timeoutMs / 1000) + ' seconds.');
            throw error;
        } finally {
            window.clearTimeout(timeoutId);
        }
    }
    function tideLocalTime(timestamp, includeDay = true) {
        return new Intl.DateTimeFormat(undefined,{
            timeZone:zone,
            ...(includeDay ? {weekday:'short'} : {}),
            hour:'2-digit',minute:'2-digit',hour12:false
        }).format(new Date(timestamp));
    }
    function setGraphUnavailable(messageText) {
        const graph = $('mthTideGraph');
        const messageNode = $('mthGraphMessage');
        if (graph) graph.hidden = true;
        if (messageNode) {
            messageNode.hidden = false;
            messageNode.textContent = messageText;
        }
        const state = $('mthGraphState');
        if (state) {
            state.textContent = 'PREDICTION UNAVAILABLE';
            state.classList.add('mth-state-pending');
        }
        $('mthGraphCurrent').textContent = '—';
        $('mthGraphTrend').textContent = 'NO VERIFIED CURVE AVAILABLE';
        $('mthGraphStation').textContent = 'NO VERIFIED STATION DATA';
        $('mthGraphDistance').textContent = 'PREDICTION OFFLINE';
    }
    function hasUsableTideTimeline(payload) {
        if (!Array.isArray(payload?.timeline)) return false;
        const samples = payload.timeline.map(point => ({
            timestamp: Date.parse(point?.time),
            level: Number(point?.level)
        })).filter(point => Number.isFinite(point.timestamp) && Number.isFinite(point.level))
          .sort((a,b) => a.timestamp - b.timestamp);
        if (samples.length < 2) return false;
        return samples[samples.length - 1].timestamp - samples[0].timestamp >= 20 * 60 * 60 * 1000;
    }
    function drawTideGraph(payload, extremesPayload) {
        const samples = Array.isArray(payload.timeline) ? payload.timeline.map(point => ({
            timestamp: Date.parse(point.time), level: Number(point.level)
        })).filter(point => Number.isFinite(point.timestamp) && Number.isFinite(point.level))
          .sort((a,b) => a.timestamp - b.timestamp) : [];
        if (samples.length < 2) throw new Error('The station did not return enough tide-height samples to draw a 24-hour graph.');
        const startTime = samples[0].timestamp;
        const endTime = samples[samples.length - 1].timestamp;
        if (endTime - startTime < 20 * 60 * 60 * 1000) throw new Error('The tide provider returned less than 20 hours of prediction data.');
        const rawMin = Math.min(...samples.map(point => point.level));
        const rawMax = Math.max(...samples.map(point => point.level));
        const span = Math.max(0.1, rawMax - rawMin);
        const minLevel = rawMin - span * 0.12;
        const maxLevel = rawMax + span * 0.12;
        const x = timestamp => 54 + ((timestamp - startTime) / (endTime - startTime)) * 638;
        const y = level => 184 - ((level - minLevel) / (maxLevel - minLevel)) * 142;
        const path = samples.map((point,index) => (index ? 'L' : 'M') + x(point.timestamp).toFixed(2) + ' ' + y(point.level).toFixed(2)).join(' ');
        const area = path + ' L ' + x(endTime).toFixed(2) + ' 184 L ' + x(startTime).toFixed(2) + ' 184 Z';
        const grid = [0,1,2,3].map(i => {
            const level = maxLevel - (maxLevel - minLevel) * i / 3;
            const yy = 42 + i * 47.33;
            return '<line class="mth-graph-gridline" x1="54" y1="' + yy.toFixed(2) + '" x2="692" y2="' + yy.toFixed(2) + '"/>' +
                '<text class="mth-graph-axis-label" x="45" y="' + (yy + 3).toFixed(2) + '" text-anchor="end">' + level.toFixed(1) + '</text>';
        }).join('');
        const ticks = [0,6,12,18,24].map(hour => {
            const timestamp = startTime + (endTime - startTime) * hour / 24;
            const xx = x(timestamp);
            return '<line class="mth-graph-tick" x1="' + xx.toFixed(2) + '" y1="42" x2="' + xx.toFixed(2) + '" y2="190"/>' +
                '<text class="mth-graph-axis-label" x="' + xx.toFixed(2) + '" y="211" text-anchor="middle">' + tideLocalTime(timestamp,false) + '</text>';
        }).join('');
        const now = Date.now();
        const nowX = x(Math.max(startTime,Math.min(endTime,now)));
        const nearest = samples.reduce((best,point) => Math.abs(point.timestamp-now) < Math.abs(best.timestamp-now) ? point : best,samples[0]);
        const before = samples.filter(point => point.timestamp <= now).slice(-1)[0] || samples[0];
        const after = samples.find(point => point.timestamp > now) || samples[samples.length-1];
        const trend = after.level > before.level + 0.005 ? 'RISING' : (after.level < before.level - 0.005 ? 'FALLING' : 'NEAR SLACK');
        const extrema = Array.isArray(extremesPayload?.extremes) ? extremesPayload.extremes : [];
        const extremeMarks = extrema.map(event => {
            const timestamp = Date.parse(event.time);
            const level = Number(event.level);
            if (!Number.isFinite(timestamp) || !Number.isFinite(level) || timestamp < startTime || timestamp > endTime) return '';
            const isHigh = event.high === true || String(event.label || '').toLowerCase() === 'high';
            const label = isHigh ? 'H' : 'L';
            return '<circle class="mth-graph-extreme-dot" cx="' + x(timestamp).toFixed(2) + '" cy="' + y(level).toFixed(2) + '" r="4"/>' +
                '<text class="mth-graph-extreme-label" x="' + x(timestamp).toFixed(2) + '" y="' + (y(level) + (isHigh ? -10 : 17)).toFixed(2) + '" text-anchor="middle">' + label + '</text>';
        }).join('');
        const graph = $('mthTideGraph');
        graph.innerHTML = '<defs><linearGradient id="mthTideArea" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#72e5e0" stop-opacity=".28"/><stop offset="1" stop-color="#72e5e0" stop-opacity=".015"/></linearGradient></defs>' +
            grid + ticks + '<path class="mth-graph-area" d="' + area + '"/><path class="mth-graph-line" d="' + path + '"/>' +
            '<line class="mth-graph-now-line" x1="' + nowX.toFixed(2) + '" y1="32" x2="' + nowX.toFixed(2) + '" y2="190"/>' +
            '<text class="mth-graph-now-label" x="' + Math.max(65,Math.min(681,nowX)).toFixed(2) + '" y="25" text-anchor="middle">NOW</text>' +
            extremeMarks;
        graph.hidden = false;
        $('mthGraphMessage').hidden = true;
        $('mthGraphCurrent').textContent = nearest.level.toFixed(2) + ' M';
        $('mthGraphTrend').textContent = trend + ' · ' + tideLocalTime(now,false) + ' LOCAL';
        $('mthGraphWindow').textContent = 'NEXT 24 HOURS';
        $('mthGraphDatum').textContent = (payload.datum || 'STATION DATUM').toString().toUpperCase();
        const station = payload.station || {};
        $('mthGraphStation').textContent = (station.name || 'VERIFIED TIDE STATION').toString().toUpperCase();
        const distance = Number(payload.distance);
        $('mthGraphDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST PREDICTION STATION';
        const state = $('mthGraphState');
        state.textContent = 'LIVE PREDICTION';
        state.classList.remove('mth-state-pending');
    }
    async function loadTidePredictions() {
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (!validLocation) {
            status.textContent = 'LOCATION COORDINATES UNAVAILABLE';
            message.textContent = 'Set valid coordinates for the selected GaugeIQ location to search for a nearby tide station.';
            setGraphUnavailable('Set valid coordinates for this GaugeIQ location to load a local tide profile.');
            return;
        }
        const start = new Date();
        const end = new Date(start.getTime() + 24 * 60 * 60 * 1000);
        const extremesEnd = new Date(start.getTime() + 4 * 86400000);

        // Resolve the nearest station once, then request its curve by canonical ID.
        // This avoids a second nearest-station lookup and keeps both predictions aligned.
        const extremesRequest = fetchTidePayload('extremes',start,extremesEnd);
        const timelineRequest = extremesRequest.then(async extremesPayload => {
            const stationId = extremesPayload?.station?.id;
            if (!stationId || !/^[a-z0-9_-]+\/[a-z0-9._@-]+$/i.test(stationId)) {
                throw new Error('The high/low response did not include a usable prediction-station ID for the tide curve.');
            }
            const graphMessage = $('mthGraphMessage');
            if (graphMessage) graphMessage.textContent = 'Loading the 24-hour curve from ' + (extremesPayload.station.name || 'the verified station') + '…';
            // Try the canonical station route first. If that route is unavailable,
            // fall back to the documented coordinate route, but only accept it if
            // Open Waters resolves the exact same station ID.
            try {
                const stationPayload = await fetchTidePayload('timeline',start,end,stationId,15000);
                if (!hasUsableTideTimeline(stationPayload)) {
                    throw new Error('The station-specific endpoint returned fewer than 20 hours of valid tide-height samples.');
                }
                return stationPayload;
            } catch (stationError) {
                const graphMessage = $('mthGraphMessage');
                if (graphMessage) graphMessage.textContent = 'Station curve unavailable; retrying the selected location…';
                const locationPayload = await fetchTidePayload('timeline',start,end,null,20000);
                const locationStationId = locationPayload?.station?.id;
                if (!locationStationId || locationStationId !== stationId) {
                    throw new Error('The station curve request failed (' + (stationError?.message || 'unknown error') + ') and the location fallback did not verify the same station.');
                }
                if (!hasUsableTideTimeline(locationPayload)) {
                    throw new Error('The location fallback returned fewer than 20 hours of valid tide-height samples.');
                }
                return locationPayload;
            }
        });

        const extremesUi = extremesRequest.then(extremesPayload => {
            const station = extremesPayload.station || {};
            const distance = Number(extremesPayload.distance);
            if (!station.name || !Array.isArray(extremesPayload.extremes)) {
                status.textContent = 'INCOMPLETE STATION DATA';
                message.textContent = 'The tide provider returned an incomplete station prediction.';
                $('mthTideStation').textContent = 'INCOMPLETE STATION RESPONSE';
                $('mthTideDistance').textContent = 'VERIFICATION FAILED';
                return;
            }
            if (Number.isFinite(distance) && distance > 150) {
                status.textContent = 'NO SUITABLE COASTAL STATION';
                message.textContent = 'The nearest prediction station is ' + Math.round(distance) + ' km away. Distant predictions are not presented as local.';
                $('mthTideStation').textContent = 'STATION OUTSIDE LOCAL RANGE';
                $('mthTideDistance').textContent = Math.round(distance) + ' KM AWAY';
                return;
            }
            const events = extremesPayload.extremes.map(event => ({...event,timestamp:Date.parse(event.time)}))
                .filter(event => Number.isFinite(event.timestamp) && event.timestamp >= Date.now() - 60000)
                .sort((a,b) => a.timestamp - b.timestamp);
            const nextHigh = events.find(event => event.high === true || String(event.label || '').toLowerCase() === 'high');
            const nextLow = events.find(event => event.low === true || String(event.label || '').toLowerCase() === 'low');
            if (!nextHigh && !nextLow) {
                status.textContent = 'NO UPCOMING TIDE EVENTS';
                message.textContent = 'The station did not return upcoming high or low water events.';
                $('mthTideStation').textContent = station.name.toUpperCase();
                $('mthTideDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST STATION';
                return;
            }
            const height = event => Number.isFinite(Number(event.level)) ? Number(event.level).toFixed(2) + ' m' : '—';
            $('mthNextHigh').textContent = nextHigh ? height(nextHigh) : '—';
            $('mthNextHighTime').textContent = nextHigh ? tideLocalTime(nextHigh.timestamp) : 'NOT AVAILABLE';
            $('mthNextLow').textContent = nextLow ? height(nextLow) : '—';
            $('mthNextLowTime').textContent = nextLow ? tideLocalTime(nextLow.timestamp) : 'NOT AVAILABLE';
            status.textContent = 'HARMONIC PREDICTIONS AVAILABLE';
            message.textContent = 'Upcoming high and low water from the nearest supported prediction station. Heights use that station’s chart datum.';
            $('mthTideStation').textContent = station.name.toUpperCase();
            $('mthTideDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST STATION';
            $('mthTideAttribution').textContent = (station.attribution ? String(station.attribution) : 'Source: Open Waters / Slackwater harmonic tide predictions') + ' · Predictions do not account for weather-driven surge. Not for navigation.';
            root.querySelector('.mth-tides .mth-state-pending')?.classList.remove('mth-state-pending');
            const tideState = root.querySelector('.mth-tides .mth-section-state');
            if (tideState && tideState.textContent.includes('DATA LINK')) tideState.textContent = 'STATION LINKED';
        }).catch(error => {
            status.textContent = 'TIDE DATA UNAVAILABLE';
            message.textContent = 'Nearby high/low tide events could not be loaded. ' + (error?.message || 'Please try again later.');
            $('mthTideStation').textContent = 'NO VERIFIED STATION DATA';
            $('mthTideDistance').textContent = 'PREDICTION OFFLINE';
        });

        const timelineUi = timelineRequest.then(async timelinePayload => {
            let extremesPayload;
            try {
                extremesPayload = await extremesRequest;
            } catch (error) {
                throw new Error('High/low station verification failed: ' + (error?.message || 'prediction request unavailable.'));
            }
            const station = timelinePayload.station || {};
            const verifiedStation = extremesPayload.station || {};
            const verifiedDistance = Number(extremesPayload.distance);
            if (!station.name || !verifiedStation.name || !Array.isArray(extremesPayload.extremes)) {
                throw new Error('The provider did not return matching station and high/low verification data.');
            }
            if (Number.isFinite(verifiedDistance) && verifiedDistance > 150) {
                throw new Error('The nearest supported station is too far away to represent this location.');
            }
            if (verifiedStation.id && station.id && verifiedStation.id !== station.id) {
                throw new Error('The high/low events and tide curve resolved to different stations.');
            }
            drawTideGraph(timelinePayload,extremesPayload);
        }).catch(error => {
            setGraphUnavailable('A verified local tide curve could not be loaded. ' + (error?.message || 'Please try again later.'));
        });

        await Promise.all([extremesUi,timelineUi]);
    }
    loadTidePredictions().catch(error => {
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (status) status.textContent = 'TIDE DATA UNAVAILABLE';
        if (message) message.textContent = 'A verified nearby prediction could not be loaded. ' + (error && error.message ? error.message : 'Please try again later.');
        $('mthTideStation').textContent = 'NO VERIFIED STATION DATA';
        $('mthTideDistance').textContent = 'PREDICTION OFFLINE';
        setGraphUnavailable('A verified local tide curve could not be loaded. ' + (error && error.message ? error.message : 'Please try again later.'));
    });

    const nowText = new Intl.DateTimeFormat(undefined,{timeZone:zone,day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',hour12:false});
    root.querySelector('.mth-live').setAttribute('aria-label','Astronomical calculations updated ' + nowText.format(now));
})();
