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
    $('mthNextFull').textContent = nextFull.toLocaleDateString(undefined, {day:'2-digit',month:'short'});
    const disc = $('mthMoonDisc');
    if (disc) {
        disc.style.setProperty('--mth-phase', String(phase));
        disc.style.setProperty('--mth-illumination', String(illumination));\n        disc.style.setProperty('--mth-shadow-width', (50 * (1 - Math.abs(2 * illumination - 1))) .toFixed(1) + '%');\n        disc.style.setProperty('--mth-shadow-x', phase < 0.5 ? '0%' : '100%');
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
        const sunrise = solarEvent(now,lat,lon,true);
        const sunset = solarEvent(now,lat,lon,false);
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
            marker.style.setProperty('--mth-lat', String(Math.max(-70,Math.min(70,lat))));
            marker.style.setProperty('--mth-lon', String(lon));
            marker.setAttribute('aria-label','Selected location: ' + (root.dataset.locationName || 'GaugeIQ location'));
        }
    } else {
        ['mthSunrise','mthSolarNoon','mthSunset','mthDaylight'].forEach(id => $(id).textContent = 'LOCATION NEEDED');
    }
    const moon = root.querySelector('.mth-orbit-moon');
    if (moon) moon.style.setProperty('--mth-orbit-angle', (phase * 360).toFixed(1) + 'deg');
    const nowText = new Intl.DateTimeFormat(undefined,{timeZone:zone,day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',hour12:false});
    root.querySelector('.mth-live').setAttribute('aria-label','Astronomical calculations updated ' + nowText.format(now));
})();
