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

    async function loadTidePredictions() {
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (!validLocation) {
            status.textContent = 'LOCATION COORDINATES UNAVAILABLE';
            message.textContent = 'Set valid coordinates for the selected GaugeIQ location to search for a nearby tide station.';
            return;
        }
        const cacheKey = 'gaugeiq-mth-tides-v1:' + lat.toFixed(3) + ':' + lon.toFixed(3);
        let payload = null;
        try {
            const cached = JSON.parse(sessionStorage.getItem(cacheKey) || 'null');
            if (cached && cached.savedAt && Date.now() - cached.savedAt < 30 * 60 * 1000) payload = cached.payload;
        } catch { /* Ignore unavailable or invalid session cache. */ }
        if (!payload) {
            const start = new Date();
            const end = new Date(start.getTime() + 4 * 86400000);
            const params = new URLSearchParams({
                latitude: String(lat), longitude: String(lon),
                start: start.toISOString(), end: end.toISOString(), units: 'meters'
            });
            const response = await fetch('https://api.openwaters.io/tides/extremes?' + params.toString(), {
                headers: { Accept: 'application/json' }, cache: 'no-store'
            });
            if (!response.ok) throw new Error('Tide service returned HTTP ' + response.status);
            payload = await response.json();
            try { sessionStorage.setItem(cacheKey, JSON.stringify({savedAt:Date.now(),payload})); } catch { /* Cache is optional. */ }
        }
        const station = payload.station || {};
        const distance = Number(payload.distance);
        if (!station.name || !Array.isArray(payload.extremes)) throw new Error('The tide service returned incomplete station data.');
        if (Number.isFinite(distance) && distance > 150) {
            status.textContent = 'NO SUITABLE COASTAL STATION';
            message.textContent = 'The nearest prediction station is ' + Math.round(distance) + ' km away. Tide data is hidden rather than presenting a distant station as local.';
            $('mthTideStation').textContent = 'STATION OUTSIDE LOCAL RANGE';
            $('mthTideDistance').textContent = Math.round(distance) + ' KM AWAY';
            return;
        }
        const events = payload.extremes.map(event => ({
            ...event, timestamp: Date.parse(event.time)
        })).filter(event => Number.isFinite(event.timestamp) && event.timestamp >= Date.now() - 60000)
          .sort((a,b) => a.timestamp - b.timestamp);
        const nextHigh = events.find(event => event.high === true || String(event.label || '').toLowerCase() === 'high');
        const nextLow = events.find(event => event.low === true || String(event.label || '').toLowerCase() === 'low');
        if (!nextHigh && !nextLow) throw new Error('No upcoming high or low tide events were returned.');
        const localTime = timestamp => new Intl.DateTimeFormat(undefined,{timeZone:zone,weekday:'short',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(timestamp));
        const height = event => Number.isFinite(Number(event.level)) ? Number(event.level).toFixed(2) + ' m' : '—';
        $('mthNextHigh').textContent = nextHigh ? height(nextHigh) : '—';
        $('mthNextHighTime').textContent = nextHigh ? localTime(nextHigh.timestamp) : 'NOT AVAILABLE';
        $('mthNextLow').textContent = nextLow ? height(nextLow) : '—';
        $('mthNextLowTime').textContent = nextLow ? localTime(nextLow.timestamp) : 'NOT AVAILABLE';
        status.textContent = 'HARMONIC PREDICTIONS AVAILABLE';
        message.textContent = 'Upcoming high and low water from the nearest supported prediction station. Heights use that station’s chart datum.';
        $('mthTideStation').textContent = station.name.toUpperCase();
        $('mthTideDistance').textContent = Number.isFinite(distance) ? distance.toFixed(1) + ' KM FROM LOCATION' : 'NEAREST STATION';
        $('mthTideAttribution').textContent = (station.attribution ? String(station.attribution) : 'Source: Open Waters / Slackwater harmonic tide predictions') + ' · Predictions do not account for weather-driven surge. Not for navigation.';
        root.querySelector('.mth-tides .mth-state-pending')?.classList.remove('mth-state-pending');
        const state = root.querySelector('.mth-tides .mth-section-state');
        if (state && state.textContent.includes('DATA LINK')) state.textContent = 'STATION LINKED';
    }
    loadTidePredictions().catch(error => {
        const status = $('mthTideStatus');
        const message = $('mthTideMessage');
        if (status) status.textContent = 'TIDE DATA UNAVAILABLE';
        if (message) message.textContent = 'A verified nearby prediction could not be loaded. ' + (error && error.message ? error.message : 'Please try again later.');
        $('mthTideStation').textContent = 'NO VERIFIED STATION DATA';
        $('mthTideDistance').textContent = 'PREDICTION OFFLINE';
    });

    const moon = root.querySelector('.mth-orbit-moon');
    if (moon) moon.style.animationDelay = (-phase * 18).toFixed(2) + 's';
    const nowText = new Intl.DateTimeFormat(undefined,{timeZone:zone,day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',hour12:false});
    root.querySelector('.mth-live').setAttribute('aria-label','Astronomical calculations updated ' + nowText.format(now));
})();
