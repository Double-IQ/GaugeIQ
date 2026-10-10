const themeSelect = document.getElementById('themeSelect');
const themeMeta = document.getElementById('themeColorMeta');

function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'system') {
        root.removeAttribute('data-theme');
    } else {
        root.dataset.theme = theme;
    }

    const dark = theme === 'dark'
        || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    if (themeMeta) {
        themeMeta.setAttribute('content', dark ? '#0b1120' : '#f3f4f6');
    }
}

const savedTheme = localStorage.getItem('gaugeiq-theme') || 'system';
if (themeSelect) {
    themeSelect.value = savedTheme;
    themeSelect.addEventListener('change', () => {
        const theme = themeSelect.value;
        localStorage.setItem('gaugeiq-theme', theme);
        applyTheme(theme);
    });
}

const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
colorScheme.addEventListener?.('change', () => {
    if ((localStorage.getItem('gaugeiq-theme') || 'system') === 'system') {
        applyTheme('system');
    }
});

applyTheme(savedTheme);


const windGauge = document.querySelector('.wind-gauge');
const windCompassStatus = document.getElementById('windCompassStatus');
const windCompassButton = document.getElementById('windCompassButton');

const WIND_COMPASS_STORAGE_KEY = 'gaugeiq-compass-enabled';
const WIND_COMPASS_SMOOTHING = 0.18;
const WIND_COMPASS_NORTH_BUFFER = 6;

const storedWindCompassState = localStorage.getItem(WIND_COMPASS_STORAGE_KEY);
const windCompassCanRequestPermission = typeof DeviceOrientationEvent !== 'undefined'
    && typeof DeviceOrientationEvent.requestPermission === 'function';
// A saved "on" preference cannot replace the fresh user gesture required
// by iOS/Safari. Start off there so the permission button is always available.
let windCompassEnabled = windCompassCanRequestPermission
    ? false
    : storedWindCompassState !== null
        ? storedWindCompassState === 'true'
        : true;
let windCompassListening = false;
let windCompassHeading = null;
let windCompassTargetHeading = null;
let windCompassRotation = null;
let windCompassAnimationFrame = null;

function normalizeCompassHeading(value) {
    const heading = Number(value);
    if (!Number.isFinite(heading)) return null;
    return ((heading % 360) + 360) % 360;
}

function shortestCompassDelta(from, to) {
    return ((to - from + 540) % 360) - 180;
}

function compassDirectionLabel(degrees) {
    const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    return directions[Math.round(degrees / 45) % 8];
}

function applyWindCompassHeading(heading) {
    if (!windGauge || !Number.isFinite(heading) || !windCompassEnabled) return;

    const normalized = normalizeCompassHeading(heading);
    if (normalized === null) return;

    windCompassTargetHeading = normalized;

    if (windCompassHeading === null) {
        windCompassHeading = normalized;
        windCompassRotation = normalized;
    }

    if (windCompassAnimationFrame === null) {
        const animate = () => {
            if (!windCompassEnabled || windCompassHeading === null || windCompassTargetHeading === null) {
                windCompassAnimationFrame = null;
                return;
            }

            const delta = shortestCompassDelta(windCompassHeading, windCompassTargetHeading);

            // Keep the displayed heading normalized, but keep the actual
            // compass rotation unwrapped. This prevents a transition such as
            // 5° -> 340° from taking the long way around the circle.
            const nearNorth =
                (windCompassHeading <= WIND_COMPASS_NORTH_BUFFER || windCompassHeading >= 360 - WIND_COMPASS_NORTH_BUFFER) &&
                (windCompassTargetHeading <= WIND_COMPASS_NORTH_BUFFER || windCompassTargetHeading >= 360 - WIND_COMPASS_NORTH_BUFFER);

            const smoothing = nearNorth ? 0.10 : WIND_COMPASS_SMOOTHING;
            const step = delta * smoothing;
            windCompassHeading = normalizeCompassHeading(windCompassHeading + step);
            windCompassRotation += step;

            if (Math.abs(delta) < 0.08) {
                const correction = shortestCompassDelta(windCompassHeading, windCompassTargetHeading);
                windCompassHeading = windCompassTargetHeading;
                windCompassRotation += correction;
            }

            // Rotate the compass disc and its wind indicators together.
            // The disc turns opposite the device heading; the wind indicator
            // keeps its geographic bearing inside that rotating disc.
            const compassDisc = windGauge.querySelector('.wind-compass-orientation');
            if (compassDisc) {
                // Rotate only the dial face. The wind needle is a separate,
                // concentric SVG layer so the centre hub cannot cover or offset it.
                compassDisc.setAttribute(
                    'transform',
                    'rotate(' + (-windCompassRotation).toFixed(2) + ' 50 50)'
                );
            }

            if (windCompassStatus) {
                windCompassStatus.textContent = 'Heading ' + Math.round(windCompassHeading) + '° · ' + compassDirectionLabel(windCompassHeading);
            }

            windCompassAnimationFrame = requestAnimationFrame(animate);
        };

        windCompassAnimationFrame = requestAnimationFrame(animate);
    }
}

function handleWindDeviceOrientation(event) {
    if (!windCompassEnabled) return;

    let heading = null;

    const screenAngle = Number(window.screen?.orientation?.angle ?? window.orientation ?? 0);
    const screenOffset = Number.isFinite(screenAngle) ? screenAngle : 0;

    if (Number.isFinite(event.webkitCompassHeading)) {
        // The native heading describes the device's top edge. Offset it so the
        // dial follows the top edge of the screen in portrait or landscape.
        heading = event.webkitCompassHeading + screenOffset;
    } else if (event.absolute === true && Number.isFinite(event.alpha)) {
        // Relative alpha is not geographic north; only use absolute readings.
        heading = 360 - event.alpha + screenOffset;
    }

    if (heading !== null) {
        applyWindCompassHeading(heading);
    }
}

function updateWindCompassButton() {
    if (!windCompassButton) return;

    // This is an enable-only control: there is no separate/off state button.
    windCompassButton.textContent = 'Turn Compass ON';
    windCompassButton.setAttribute('aria-pressed', windCompassEnabled ? 'true' : 'false');
    windCompassButton.disabled = windCompassEnabled;
    if (windCompassStatus && !windCompassEnabled) windCompassStatus.hidden = true;
}

async function enableWindCompass() {
    if (!('DeviceOrientationEvent' in window)) {
        if (windCompassStatus) windCompassStatus.textContent = 'Compass unavailable';
        if (windCompassButton) windCompassButton.disabled = true;
        return;
    }

    try {
        if (typeof DeviceOrientationEvent.requestPermission === 'function') {
            const permission = await DeviceOrientationEvent.requestPermission(true);
            if (permission !== 'granted') {
                throw new Error('Compass permission was not granted.');
            }
        }

        windCompassEnabled = true;
        localStorage.setItem(WIND_COMPASS_STORAGE_KEY, 'true');

        if (!windCompassListening) {
            window.addEventListener('deviceorientation', handleWindDeviceOrientation, true);
            window.addEventListener('deviceorientationabsolute', handleWindDeviceOrientation, true);
            windCompassListening = true;
        }

        updateWindCompassButton();
        if (windCompassStatus) {
            windCompassStatus.hidden = false;
            windCompassStatus.textContent = 'Finding heading…';
        }
    } catch (error) {
        if (windCompassStatus) {
            windCompassStatus.textContent = error instanceof Error ? error.message : 'Compass unavailable';
        }
    }
}

if (windGauge) {
    const initialCompassDisc = windGauge?.querySelector('.wind-compass-orientation');
    if (initialCompassDisc) initialCompassDisc.setAttribute('transform', 'rotate(0 50 50)');

    if (windCompassStatus) windCompassStatus.hidden = true;

    if (windCompassButton) {
        windCompassButton.addEventListener('click', async () => {
            if (!windCompassEnabled) await enableWindCompass();
        });
        updateWindCompassButton();
    }

    if (windCompassEnabled &&
        typeof DeviceOrientationEvent !== 'undefined' &&
        typeof DeviceOrientationEvent.requestPermission !== 'function') {
        enableWindCompass();
    }
}

const status = document.getElementById('status');
const notifyButton = document.getElementById('notifyButton');
const historyStatus = document.getElementById('historyStatus');

async function getRegistration() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('Service workers are unavailable.');
    }
    return navigator.serviceWorker.ready;
}

function base64UrlToUint8Array(base64UrlData) {
    const padding = '='.repeat((4 - (base64UrlData.length % 4)) % 4);
    const base64 = (base64UrlData + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

async function enablePushNotifications() {
    if (!('PushManager' in window) || !('Notification' in window)) {
        throw new Error('This browser does not support Web Push notifications.');
    }
    if (Notification.permission === 'denied') {
        throw new Error('Notifications are blocked for GaugeIQ. Enable them in iPhone Settings.');
    }

    const response = await fetch('../api/push-config.php', { cache: 'no-store' });
    if (!response.ok) throw new Error('GaugeIQ push notifications are not configured yet.');

    const { publicKey } = await response.json();
    const registration = await getRegistration();
    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(publicKey)
        });
    }

    const saveResponse = await fetch('../api/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription.toJSON())
    });

    if (!saveResponse.ok) throw new Error('GaugeIQ could not save this device for notifications.');

    status.textContent = 'Notifications are enabled for GaugeIQ.';
    notifyButton.innerHTML = '<span class="alerts-led" aria-hidden="true"></span><span>Alerts on</span>';
    notifyButton.classList.add('alerts-on');
    notifyButton.disabled = true;
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
        status.textContent = 'PWA service worker could not be registered.';
    });
}

async function showAlertsOnIfAlreadyEnabled() {
    if (!('Notification' in window) || !('PushManager' in window) || Notification.permission !== 'granted') return;
    try {
        const registration = await getRegistration();
        if (await registration.pushManager.getSubscription()) {
            notifyButton.innerHTML = '<span class="alerts-led" aria-hidden="true"></span><span>Alerts on</span>';
            notifyButton.classList.add('alerts-on');
            notifyButton.disabled = true;
        }
    } catch {
        // Leave the normal enable button available if the existing subscription cannot be checked.
    }
}

showAlertsOnIfAlreadyEnabled();

if (!('Notification' in window) || !('PushManager' in window)) {
    notifyButton.disabled = true;
    notifyButton.textContent = 'Notifications unavailable';
} else {
    notifyButton.addEventListener('click', async () => {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('Notification permission was not granted.');
            await enablePushNotifications();
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Unable to enable notifications.';
        }
    });
}

function formatChartTime(value, hours, timeZone) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    if (hours === 'all' || hours >= 168) {
        const options = { day: 'numeric', month: 'short' };
        if (timeZone) options.timeZone = timeZone;
        try { return date.toLocaleDateString([], options); }
        catch { return date.toLocaleDateString([], { day: 'numeric', month: 'short' }); }
    }

    const options = { hour: '2-digit', minute: '2-digit', hour12: false };
    if (timeZone) options.timeZone = timeZone;
    try { return date.toLocaleTimeString([], options); }
    catch { return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }); }
}

function drawChart(canvas, values, unit, decimals = 1, hours = 24, timeZone = null) {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 600;
    const height = canvas.clientHeight || 220;
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    const grouped = new Map();
    values.forEach(item => {
        if (!Number.isFinite(item.value)) return;
        const timestamp = new Date(item.time).getTime();
        const key = Number.isFinite(timestamp) ? timestamp : 'invalid-' + grouped.size;
        if (!grouped.has(key)) grouped.set(key, []);
        grouped.get(key).push(item.value);
    });

    const valid = [...grouped.entries()]
        .map(([timestamp, readings]) => ({
            value: readings.reduce((sum, value) => sum + value, 0) / readings.length,
            time: Number.isFinite(timestamp) ? new Date(timestamp).toISOString() : values.find(item => Number.isFinite(item.value))?.time
        }))
        .sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime());
    if (valid.length === 0) {
        ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--muted');
        ctx.font = '14px system-ui';
        ctx.fillText('No history recorded yet.', 16, 32);
        return;
    }

    const styles = getComputedStyle(document.documentElement);
    const text = styles.getPropertyValue('--muted').trim();
    const line = styles.getPropertyValue('--accent').trim();
    const border = styles.getPropertyValue('--border').trim();
    const min = Math.min(...valid.map(item => item.value));
    const max = Math.max(...valid.map(item => item.value));
    const range = max - min || 1;
    const pad = { top: 18, right: 14, bottom: 34, left: 14 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(pad.left, pad.top + plotH);
    ctx.lineTo(width - pad.right, pad.top + plotH);
    ctx.stroke();

    ctx.strokeStyle = line;
    ctx.lineWidth = 2.5;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();

    const firstTime = new Date(valid[0].time).getTime();
    const lastTime = new Date(valid[valid.length - 1].time).getTime();
    const timeRange = lastTime - firstTime || 1;

    const points = valid.map(item => {
        const timestamp = new Date(item.time).getTime();
        return {
            x: valid.length === 1
                ? pad.left + (plotW / 2)
                : pad.left + ((timestamp - firstTime) / timeRange) * plotW,
            y: pad.top + (1 - ((item.value - min) / range)) * plotH
        };
    });

    if (points.length > 1) {
        ctx.moveTo(points[0].x, points[0].y);

        // Connect real readings with a smooth curve without horizontal
        // plateaus at either endpoint. The control points follow the slope
        // through neighboring readings so the line begins and ends moving
        // naturally instead of pausing horizontally before changing value.
        for (let index = 0; index < points.length - 1; index += 1) {
            const current = points[index];
            const next = points[index + 1];

            if (Math.abs(next.y - current.y) < 0.001) {
                ctx.lineTo(next.x, next.y);
                continue;
            }

            const previous = points[index - 1] || current;
            const following = points[index + 2] || next;

            const incomingSlope = (next.y - previous.y) / Math.max(1, next.x - previous.x);
            const outgoingSlope = (following.y - current.y) / Math.max(1, following.x - current.x);
            const handle = (next.x - current.x) / 3;

            const cp1 = {
                x: current.x + handle,
                y: current.y + incomingSlope * handle
            };
            const cp2 = {
                x: next.x - handle,
                y: next.y - outgoingSlope * handle
            };

            ctx.bezierCurveTo(
                cp1.x,
                cp1.y,
                cp2.x,
                cp2.y,
                next.x,
                next.y
            );
        }
        ctx.stroke();
    }
    if (valid.length === 1) {
        const item = valid[0];
        const x = pad.left + (plotW / 2);
        const y = pad.top + (1 - ((item.value - min) / range)) * plotH;
        ctx.fillStyle = line;
        ctx.beginPath();
        ctx.arc(x, y, 5, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.fillStyle = text;
    ctx.font = '12px system-ui';
    ctx.fillText(max.toFixed(decimals) + unit, pad.left, 13);
    ctx.fillText(min.toFixed(decimals) + unit, pad.left, height - 4);

    const first = valid[0];
    const last = valid[valid.length - 1];
    const firstLabel = formatChartTime(first.time, hours, timeZone);
    const lastLabel = formatChartTime(last.time, hours, timeZone);
    ctx.fillText(firstLabel, pad.left, height - 17);
    const lastWidth = ctx.measureText(lastLabel).width;
    ctx.fillText(lastLabel, width - pad.right - lastWidth, height - 17);
}

function weatherChangeDirectionLabel(delta, unit, positive = 'increased', negative = 'decreased') {
    if (Math.abs(delta) < 0.05) return 'held steady';
    return delta > 0
        ? positive + ' by ' + Math.abs(delta).toFixed(unit === '°C' ? 1 : 1) + unit
        : negative + ' by ' + Math.abs(delta).toFixed(unit === '°C' ? 1 : 1) + unit;
}

const WEATHER_CHANGE_WEIGHTS = {
    pressure: 2,
    temperature: 1,
    humidity: 1,
    windSpeed: 2,
    windDirection: 1,
    dewPointSpread: 1,
    rainfall: 2,
    cloudCover: 1,
    weatherCode: 1
};
function weatherNumeric(value) {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function weatherSeverity(magnitude, trigger, maximum) {
    if (!Number.isFinite(magnitude) || magnitude < trigger) return 0;
    return Math.max(0, Math.min(1, magnitude / maximum));
}

function weatherCircularDifference(a, b) {
    const difference = Math.abs(((a - b) % 360 + 360) % 360);
    return Math.min(difference, 360 - difference);
}

function weatherCodeSeverity(code) {
    if ([95, 96, 99].includes(code)) return 5;
    if ([65, 67, 75, 82, 86].includes(code)) return 4;
    if ([61, 63, 66, 71, 73, 77, 80, 81, 85].includes(code)) return 3;
    if ([45, 48, 51, 53, 55, 56, 57].includes(code)) return 2;
    if ([2, 3].includes(code)) return 1;
    return 0;
}

function weatherReadingsWindow(readings) {
    const fields = {
        temperature: 'temperature_c',
        dewPoint: 'dew_point_c',
        pressure: 'pressure_hpa',
        humidity: 'humidity_percent',
        wind: 'wind_speed_kmh',
        direction: 'wind_direction_degrees',
        rain: 'rainfall_mm',
        cloud: 'cloud_cover_percent',
        weatherCode: 'weather_code'
    };
    return readings
        .map(item => {
            const row = {};
            Object.entries(fields).forEach(([key, source]) => { row[key] = weatherNumeric(item[source]); });
            row.time = new Date(item.created_at || item.observed_at).getTime();
            return row;
        })
        .filter(row => Number.isFinite(row.time))
        .sort((a, b) => a.time - b.time);
}

function weatherFieldPair(readings, field) {
    const available = readings.filter(row => row[field] !== null && Number.isFinite(row[field]));
    if (available.length < 2) return null;
    const first = available[0];
    const last = available[available.length - 1];
    const hours = (last.time - first.time) / 3600000;
    if (!Number.isFinite(hours) || hours <= 0) return null;
    return { first: first[field], last: last[field], delta: last[field] - first[field], hours };
}

function weatherChangeScore(readings) {
    const valid = weatherReadingsWindow(readings);
    if (valid.length < 3) {
        return { score: null, summary: 'Gathering more readings…', components: [], reasons: [], coverage: 0 };
    }

    const totalHours = (valid[valid.length - 1].time - valid[0].time) / 3600000;
    if (totalHours < 1) {
        return { score: null, summary: 'Gathering a longer observation window…', components: [], reasons: [], coverage: 0 };
    }

    const components = [];
    const addComponent = (key, label, pair, severity, evidence) => {
        if (!pair || !Number.isFinite(severity)) return;
        components.push({
            key, label, weight: WEATHER_CHANGE_WEIGHTS[key],
            severity: Math.max(0, Math.min(1, severity)), evidence, pair
        });
    };

    const pressure = weatherFieldPair(valid, 'pressure');
    if (pressure) {
        const rate = pressure.delta / pressure.hours;
        addComponent('pressure', 'Air pressure', pressure, weatherSeverity(Math.abs(rate), 0.3, 1.5),
            'changed ' + (pressure.delta < 0 ? 'by −' : 'by +') + Math.abs(pressure.delta).toFixed(1) + ' hPa (' + (rate < 0 ? '−' : '+') + Math.abs(rate).toFixed(2) + ' hPa/hour)');
    }

    const temperature = weatherFieldPair(valid, 'temperature');
    if (temperature) {
        const scaled = Math.abs(temperature.delta) * 6 / temperature.hours;
        addComponent('temperature', 'Temperature', temperature, weatherSeverity(scaled, 1, 3),
            'changed ' + (temperature.delta < 0 ? 'by −' : 'by +') + Math.abs(temperature.delta).toFixed(1) + '°C over ' + temperature.hours.toFixed(1) + ' hours (six-hour equivalent ' + scaled.toFixed(1) + '°C)');
    }

    const humidity = weatherFieldPair(valid, 'humidity');
    if (humidity) {
        const scaled = Math.abs(humidity.delta) * 6 / humidity.hours;
        addComponent('humidity', 'Humidity', humidity, weatherSeverity(scaled, 4, 12),
            'changed ' + (humidity.delta < 0 ? 'by −' : 'by +') + Math.abs(humidity.delta).toFixed(0) + ' percentage points (six-hour equivalent ' + scaled.toFixed(1) + ')');
    }

    const wind = weatherFieldPair(valid, 'wind');
    if (wind) {
        const scaled = Math.abs(wind.delta) * 6 / wind.hours;
        addComponent('windSpeed', 'Wind speed', wind, weatherSeverity(scaled, 8, 20),
            'changed ' + (wind.delta < 0 ? 'by −' : 'by +') + Math.abs(wind.delta).toFixed(1) + ' km/h (six-hour equivalent ' + scaled.toFixed(1) + ')');
    }

    const direction = weatherFieldPair(valid, 'direction');
    if (direction) {
        const shift = weatherCircularDifference(direction.first, direction.last);
        addComponent('windDirection', 'Wind direction', direction, weatherSeverity(shift, 20, 90),
            'shifted by ' + Math.round(shift) + '° using the shortest compass angle');
    }

    const firstTemp = weatherFieldPair(valid, 'temperature');
    const firstDew = weatherFieldPair(valid, 'dewPoint');
    if (firstTemp && firstDew) {
        const spreadFirst = firstTemp.first - firstDew.first;
        const spreadLast = firstTemp.last - firstDew.last;
        const narrowing = spreadFirst - spreadLast;
        addComponent('dewPointSpread', 'Temperature/dew-point gap', {
            first: spreadFirst, last: spreadLast, delta: spreadLast - spreadFirst,
            hours: Math.min(firstTemp.hours, firstDew.hours)
        }, weatherSeverity(narrowing, 1, 3),
        'gap ' + (narrowing >= 0 ? 'narrowed by ' : 'widened by ') + Math.abs(narrowing).toFixed(1) + '°C');
    }

    const rain = weatherFieldPair(valid, 'rain');
    if (rain) {
        const scaled = Math.abs(rain.delta) * 6 / rain.hours;
        addComponent('rainfall', 'Rainfall', rain, weatherSeverity(scaled, 0.2, 2),
            'reading changed by ' + (rain.delta < 0 ? '−' : '+') + Math.abs(rain.delta).toFixed(2) + ' mm (six-hour equivalent ' + scaled.toFixed(2) + ' mm)');
    }

    const cloud = weatherFieldPair(valid, 'cloud');
    if (cloud) {
        const scaled = Math.abs(cloud.delta) * 6 / cloud.hours;
        addComponent('cloudCover', 'Cloud cover', cloud, weatherSeverity(scaled, 15, 50),
            'changed by ' + Math.abs(cloud.delta).toFixed(0) + ' percentage points (six-hour equivalent ' + scaled.toFixed(0) + ')');
    }

    const codes = weatherFieldPair(valid, 'weatherCode');
    if (codes) {
        const severityChange = Math.abs(weatherCodeSeverity(codes.last) - weatherCodeSeverity(codes.first));
        const changed = codes.first !== codes.last;
        const severity = changed ? Math.max(severityChange > 0 ? severityChange / 5 : 0.25, 0.25) : 0;
        addComponent('weatherCode', 'Reported weather condition', codes, severity,
            changed ? 'condition code changed from ' + codes.first + ' to ' + codes.last + ' (severity-category difference ' + severityChange + ')' : 'condition code stayed the same');
    }

    if (components.length < 5) {
        return {
            score: null,
            summary: 'Insufficient valid signals (' + components.length + '/9). At least 5 are required.',
            components, reasons: [], coverage: components.length
        };
    }

    const availableWeight = components.reduce((sum, component) => sum + component.weight, 0);
    const weightedSeverity = components.reduce((sum, component) => sum + component.weight * component.severity, 0);
    const score = Math.round((10 * weightedSeverity / availableWeight) * 10) / 10;
    components.forEach(component => {
        component.contribution = 10 * component.weight * component.severity / availableWeight;
    });

    let summary;
    if (score < 2) summary = 'Stable · no material change detected';
    else if (score < 4) summary = 'Minor change in recent conditions';
    else if (score < 6) summary = 'Moderate change in recent conditions';
    else if (score < 8) summary = 'Significant change in recent conditions';
    else summary = 'Very significant change in recent conditions';

    return {
        score, summary, components, coverage: components.length,
        availableWeight, weightedSeverity,
        reasons: components.filter(component => component.severity > 0)
            .sort((a, b) => b.contribution - a.contribution)
    };
}

function weatherConditionsTrend(readings) {
    const valid = weatherReadingsWindow(readings);
    if (valid.length < 3 || (valid[valid.length - 1].time - valid[0].time) < 3600000) {
        return { score: null, label: 'Gathering readings…', arrow: '→', reasons: [], mixed: false };
    }

    let score = 0;
    const reasons = [];
    const addSignal = (strength, worsening, improving, weight, label) => {
        if (strength === null || strength === 0) return;
        const value = strength * weight;
        score += value;
        reasons.push({ label, direction: value > 0 ? 'worsening' : 'improving', points: value, weight, explanation: value > 0 ? worsening : improving });
    };

    const pressure = weatherFieldPair(valid, 'pressure');
    if (pressure) {
        const rate = pressure.delta / pressure.hours;
        if (rate <= -0.3) addSignal(1, 'Pressure is falling, which can precede unsettled weather.', 'Pressure is rising, which can support more settled weather.', 2, 'Pressure');
        else if (rate >= 0.3) addSignal(-1, 'Pressure is falling, which can precede unsettled weather.', 'Pressure is rising, which can support more settled weather.', 2, 'Pressure');
    }

    const rain = weatherFieldPair(valid, 'rain');
    if (rain) {
        const scaled = rain.delta * 6 / rain.hours;
        if (rain.first < 0.2 && rain.last >= 0.2) addSignal(1, 'Rain has appeared in the latest reading.', 'Rainfall has eased or stopped.', 2, 'Rainfall');
        else if (scaled >= 0.2) addSignal(0.5, 'The recent rainfall reading has increased.', 'The recent rainfall reading has decreased.', 2, 'Rainfall');
        else if (scaled <= -0.2) addSignal(-0.5, 'The recent rainfall reading has increased.', 'The recent rainfall reading has decreased.', 2, 'Rainfall');
    }

    const wind = weatherFieldPair(valid, 'wind');
    if (wind) {
        const scaled = wind.delta * 6 / wind.hours;
        if (scaled >= 8) addSignal(1, 'Wind speed is increasing.', 'Wind speed is easing.', 1, 'Wind speed');
        else if (scaled <= -8) addSignal(-1, 'Wind speed is increasing.', 'Wind speed is easing.', 1, 'Wind speed');
    }

    const cloud = weatherFieldPair(valid, 'cloud');
    if (cloud) {
        const scaled = cloud.delta * 6 / cloud.hours;
        if (scaled >= 20) addSignal(1, 'Cloud cover is increasing.', 'Cloud cover is decreasing.', 1, 'Cloud cover');
        else if (scaled <= -20) addSignal(-1, 'Cloud cover is increasing.', 'Cloud cover is decreasing.', 1, 'Cloud cover');
    }

    const codes = weatherFieldPair(valid, 'weatherCode');
    if (codes) {
        const delta = weatherCodeSeverity(codes.last) - weatherCodeSeverity(codes.first);
        if (delta >= 1) addSignal(1, 'The reported weather category has become more severe.', 'The reported weather category has become less severe.', 2, 'Weather code');
        else if (delta <= -1) addSignal(-1, 'The reported weather category has become more severe.', 'The reported weather category has become less severe.', 2, 'Weather code');
    }

    const mixed = reasons.some(reason => reason.points > 0) && reasons.some(reason => reason.points < 0);
    let label = 'Steady';
    let arrow = '→';
    if (score >= 2) { label = 'Worsening'; arrow = '↘'; }
    else if (score <= -2) { label = 'Improving'; arrow = '↗'; }
    const explanation = mixed && label === 'Steady'
        ? 'Signals are mixed; there is no clear overall trend.'
        : label === 'Steady'
            ? 'No strong directional change is detected in the available readings.'
            : label === 'Worsening'
                ? 'The available signals lean towards more unsettled conditions.'
                : 'The available signals lean towards more settled conditions.';

    return { score, label, arrow, reasons, mixed, explanation };
}

function weatherLocalBaseline(readings, currentScore, latestTime) {
    const windowHours = 6;
    const dayMs = 24 * 60 * 60 * 1000;
    const windowMs = windowHours * 60 * 60 * 1000;
    const historicalScores = [];

    for (let day = 1; day <= 14; day++) {
        const windowEnd = latestTime - day * dayMs;
        const windowReadings = readings.filter(item => {
            const timestamp = new Date(item.created_at || item.observed_at).getTime();
            return Number.isFinite(timestamp) && timestamp >= windowEnd - windowMs && timestamp <= windowEnd;
        });
        const result = weatherChangeScore(windowReadings);
        if (Number.isFinite(result.score)) historicalScores.push(result.score);
    }

    if (historicalScores.length < 5) {
        return {
            count: historicalScores.length,
            text: 'Building recent local baseline: ' + historicalScores.length + ' comparable six-hour windows available; at least 5 are needed.'
        };
    }

    historicalScores.sort((a, b) => a - b);
    const middle = Math.floor(historicalScores.length / 2);
    const median = historicalScores.length % 2
        ? historicalScores[middle]
        : (historicalScores[middle - 1] + historicalScores[middle]) / 2;
    const difference = currentScore - median;
    let comparison = 'within the recent pattern';
    if (difference >= 2) comparison = 'more change than usual in the recent readings';
    else if (difference <= -2) comparison = 'less change than usual in the recent readings';

    return {
        count: historicalScores.length,
        median,
        text: 'Recent local pattern: median ' + median.toFixed(1) + '/10 across ' +
            historicalScores.length + ' comparable six-hour windows. Current change is ' + comparison +
            '. This is a short-term baseline, not a seasonal climate normal.'
    };
}

async function loadWeatherChange() {
    const scoreElement = document.getElementById('weatherChangeScore');
    const summaryElement = document.getElementById('weatherChangeSummary');
    const reasonsElement = document.getElementById('weatherChangeReasons');
    if (!scoreElement || !summaryElement || !reasonsElement) return;

    try {
        const selectedLocation = document.getElementById('weatherLocationSelect')?.value || 'server-current';
        const isLocalHistory = selectedLocation !== 'server-current' && Boolean(window.GaugeIQLocalWeather);
        let allReadings;
        if (isLocalHistory) {
            const localReadings = await window.GaugeIQLocalWeather.readingsFor(selectedLocation, 'all');
            allReadings = localReadings.map(item => ({
                ...item,
                created_at: item.created_at || item.timestamp || item.observed_at,
                observed_at: item.observed_at || item.timestamp
            }));
        } else {
            const response = await fetch('../api/history.php?hours=336', { cache: 'no-store' });
            if (!response.ok) throw new Error('Unable to load recent readings.');
            const data = await response.json();
            allReadings = Array.isArray(data.readings) ? data.readings : [];
        }
        // Imported station observations belong in history graphs, not the live
        // short-window change score. Legacy source-less live records remain eligible.
        const liveReadings = allReadings.filter(item => item.source == null || item.source === 'Open-Meteo');
        const latestTime = liveReadings.reduce((latest, item) => {
            const timestamp = new Date(item.created_at || item.observed_at).getTime();
            return Number.isFinite(timestamp) ? Math.max(latest, timestamp) : latest;
        }, 0);
        const currentWindowStart = latestTime - 6 * 60 * 60 * 1000;
        const readings = liveReadings.filter(item => {
            const timestamp = new Date(item.created_at || item.observed_at).getTime();
            return Number.isFinite(timestamp) && timestamp >= currentWindowStart && timestamp <= latestTime;
        });
        const result = weatherChangeScore(readings);
        const trend = weatherConditionsTrend(readings);
        const baselineElement = document.getElementById('weatherBaselineStatus');
        if (baselineElement && latestTime > 0 && Number.isFinite(result.score)) {
            const baseline = weatherLocalBaseline(liveReadings, result.score, latestTime);
            const ageMinutes = Math.max(0, Math.round((Date.now() - latestTime) / 60000));
            baselineElement.textContent = (isLocalHistory ? 'Selected location history: ' : '') + baseline.text + (ageMinutes > 90
                ? ' Latest stored reading is about ' + ageMinutes + ' minutes old; the result may be stale.'
                : '');
        } else if (baselineElement) {
            baselineElement.textContent = latestTime > 0
                ? 'Not enough valid current readings to compare against the recent local pattern.'
                : 'No historical readings are available yet to establish a local baseline.';
        }

        const conditionsElement = document.getElementById('weatherConditions');
        const trendIcon = document.getElementById('conditionsTrendIcon');
        const trendSummary = document.getElementById('conditionsTrendSummary');
        const trendReasons = document.getElementById('conditionsTrendReasons');

        if (result.score === null) {
            scoreElement.textContent = '—';
            summaryElement.textContent = result.summary;
            reasonsElement.innerHTML = '<div class="weather-change-reason"><span>GaugeIQ needs at least three readings spanning one hour and at least five of nine valid signals. ' + result.coverage + '/9 signals are available in this window.</span></div>';
            document.getElementById('weatherChangeTrack')?.setAttribute('aria-valuenow', '0');
            document.getElementById('weatherChangeFill')?.style.setProperty('width', '0%');
        } else {
            scoreElement.textContent = result.score.toFixed(1);
            scoreElement.setAttribute('aria-label', 'Weather change intensity ' + result.score.toFixed(1) + ' out of 10');
            summaryElement.textContent = result.summary + ' · ' + result.score.toFixed(1) + '/10 · ' + result.coverage + '/9 signals available';
            const level = result.score >= 6 ? 'high' : result.score >= 3 ? 'moderate' : 'low';
            scoreElement.dataset.level = level;
            document.getElementById('weatherChangeTrack')?.setAttribute('aria-valuenow', String(result.score));
            const changeFill = document.getElementById('weatherChangeFill');
            if (changeFill) {
                changeFill.style.width = (result.score * 10) + '%';
                changeFill.dataset.level = level;
            }
            reasonsElement.innerHTML = result.components
                .filter(component => Number.isFinite(component.severity) && component.severity > 0)
                .map(component => {
                    const pair = component.pair;
                    const arrow = delta => delta > 0 ? '↑' : '↓';
                    let description;

                    if (pair && ['pressure', 'humidity', 'windSpeed', 'temperature', 'dewPointSpread', 'rainfall', 'cloudCover'].includes(component.key)) {
                        const delta = Number(pair.delta);
                        if (!Number.isFinite(delta) || delta === 0) return '';
                        const unit = {
                            pressure: ' hPa',
                            humidity: ' percentage points',
                            windSpeed: ' km/h',
                            temperature: '°C',
                            dewPointSpread: '°C',
                            rainfall: ' mm',
                            cloudCover: ' percentage points'
                        }[component.key];
                        const precision = component.key === 'humidity' || component.key === 'cloudCover' ? 0
                            : component.key === 'rainfall' ? 2 : 1;
                        let label = component.label;
                        if (component.key === 'dewPointSpread') label = 'Temperature/dew-point gap';
                        description = '<strong>' + label + '</strong>: <span class="weather-change-direction-arrow" aria-label="' +
                            (delta > 0 ? 'increased' : 'decreased') + '">' + arrow(delta) + '</span> ' +
                            Math.abs(delta).toFixed(precision) + unit;
                    } else if (component.key === 'windDirection' && pair) {
                        const shift = weatherCircularDifference(pair.first, pair.last);
                        if (shift === 0) return '';
                        description = '<strong>Wind direction</strong>: <span class="weather-change-direction-arrow" aria-label="direction shifted">↔</span> shifted by ' +
                            Math.round(shift) + '°';
                    } else {
                        description = '<strong>' + component.label + '</strong>: ' + component.evidence;
                    }

                    return description ? '<div class="weather-change-reason"><span>' + description + '</span></div>' : '';
                }).join('');
            const stabilityFill = document.getElementById('weatherStabilityFill');
            const stabilityTrack = stabilityFill?.closest('.temperature-stability-track');
            if (stabilityFill) {
                stabilityFill.style.width = (100 - result.score * 10) + '%';
                const alertThreshold = Number(stabilityTrack?.dataset.weatherChangeAlertThreshold);
                const warningActive = Number.isFinite(alertThreshold) && result.score >= alertThreshold;
                stabilityFill.classList.toggle('warning', warningActive);
                stabilityTrack?.classList.toggle('warning', warningActive);
            }
        }

        if (trend.score === null) {
            if (trendSummary) trendSummary.textContent = 'Gathering more valid readings…';
            if (trendReasons) trendReasons.innerHTML = '<div class="weather-change-reason">A directional trend needs at least three readings spanning one hour.</div>';
            if (trendIcon) trendIcon.textContent = '→';
            if (conditionsElement) conditionsElement.innerHTML = 'Conditions: <b>→ Gathering readings…</b>';
        } else {
            if (trendIcon) {
                trendIcon.textContent = trend.arrow;
                trendIcon.dataset.trend = trend.label.toLowerCase();
                trendIcon.setAttribute('aria-label', trend.label);
            }
            if (trendSummary) trendSummary.textContent = trend.label + ' · ' + trend.explanation;
            if (trendReasons) {
                trendReasons.innerHTML = trend.reasons.length
                    ? trend.reasons.map(reason => '<div class="weather-change-reason"><span><strong>' + reason.label + '</strong> (' + (reason.points > 0 ? '+' : '') + reason.points + ' weighted trend points (weight ' + reason.weight + '): ' + reason.explanation + '</span></div>').join('')
                    : '<div class="weather-change-reason">No pressure, rain, wind, cloud or weather-code signal crossed the current trend thresholds.</div>';
            }
            if (conditionsElement) conditionsElement.innerHTML = 'Conditions: <b>' + trend.arrow + ' ' + trend.label + '</b>';
        }
    } catch {
        scoreElement.textContent = '—';
        summaryElement.textContent = 'Weather change indicator unavailable.';
        reasonsElement.innerHTML = '<div class="weather-change-reason">Recent readings could not be loaded. The meters have not been calculated.</div>';
        const trendSummary = document.getElementById('conditionsTrendSummary');
        const trendReasons = document.getElementById('conditionsTrendReasons');
        if (trendSummary) trendSummary.textContent = 'Conditions trend unavailable.';
        if (trendReasons) trendReasons.innerHTML = '';
        const conditionsElement = document.getElementById('weatherConditions');
        if (conditionsElement) conditionsElement.innerHTML = 'Conditions: <b>→ Unavailable</b>';
    }
}


async function selectDashboardLocation(locationId, location, reloadAfterSync = false) {
    const selector = document.getElementById('weatherLocationSelect');
    const name = document.getElementById('dashboardLocationName');
    if (locationId === 'server-current') {
        location = {
            name: selector?.dataset.configLocationName || 'Configured GaugeIQ location',
            latitude: Number(selector?.dataset.configLatitude),
            longitude: Number(selector?.dataset.configLongitude),
            timezone: selector?.dataset.configTimezone || 'auto'
        };
    }
    if (!location || !Number.isFinite(Number(location.latitude)) || !Number.isFinite(Number(location.longitude))) {
        if (name) name.textContent = 'Location coordinates unavailable';
        return;
    }
    if (name) name.textContent = 'Loading ' + location.name + '…';
    try {
        const syncEndpoint = selector?.dataset.locationSyncEndpoint;
        const csrf = selector?.dataset.locationCsrf;
        if (!syncEndpoint || !csrf) throw new Error('Location sync is not configured. Reload GaugeIQ and try again.');
        const serverCoordinatesChanged =
            Number(selector?.dataset.serverLatitude) !== Number(location.latitude) ||
            Number(selector?.dataset.serverLongitude) !== Number(location.longitude);
        const syncResponse = await fetch(syncEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({
                csrf,
                name: String(location.name || 'Saved location').slice(0, 120),
                latitude: Number(location.latitude),
                longitude: Number(location.longitude),
                timezone: String(location.timezone || 'auto').slice(0, 80)
            })
        });
        const syncResult = await syncResponse.json();
        if (!syncResponse.ok || !syncResult.success) {
            throw new Error(syncResult.error || 'Unable to sync this location to the server; cron will keep using the previous location.');
        }
        if (selector) {
            selector.dataset.serverLatitude = String(location.latitude);
            selector.dataset.serverLongitude = String(location.longitude);
            selector.dataset.serverLocationName = String(location.name || 'Saved location');
            selector.dataset.serverTimezone = String(location.timezone || 'auto');
        }
        // A full page render updates all server-rendered gauges and their derived
        // indicators together. Do this on explicit user selection, or if a
        // remembered local location had drifted from the server's active one.
        if (reloadAfterSync || serverCoordinatesChanged) {
            window.location.reload();
            return;
        }
        const p = new URLSearchParams({
            latitude: String(location.latitude), longitude: String(location.longitude),
            current: 'temperature_2m,apparent_temperature,dew_point_2m,surface_pressure,relative_humidity_2m,rain,cloud_cover,weather_code,wind_speed_10m,wind_direction_10m',
            daily: 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum',
            forecast_days: '4', wind_speed_unit: 'kmh',
            timezone: location.timezone && location.timezone !== 'auto' ? location.timezone : 'auto'
        });
        const response = await fetch('https://api.open-meteo.com/v1/forecast?' + p, {cache:'no-store'});
        const data = await response.json();
        if (!response.ok || data.error || !data.current) throw new Error(data.reason || 'Weather data unavailable.');
        const c = data.current, d = data.daily || {};
        const fmt = (v, n=1) => Number.isFinite(Number(v)) ? Number(v).toFixed(n) : '—';
        const put = (q, v) => { const e=document.querySelector(q); if(e)e.textContent=v; };
        put('.temperature-summary-current > strong', fmt(c.temperature_2m)+'°C');
        put('.temperature-feels-like-row span', 'Feels like '+fmt(c.apparent_temperature)+'°C');
        const ranges=document.querySelectorAll('.temperature-summary-range div strong');
        if(ranges[0])ranges[0].textContent=fmt(d.temperature_2m_max?.[0])+'°';
        if(ranges[1])ranges[1].textContent=fmt(d.temperature_2m_min?.[0])+'°';
        put('.pressure-readout-row .metric-value', fmt(c.surface_pressure)+' hPa');
        put('.wind-heading-readout', Math.round(c.wind_direction_10m)+'° '+['N','NE','E','SE','S','SW','W','NW'][Math.round((((c.wind_direction_10m%360)+360)%360)/45)%8]);
        put('.wind-speed-readout', fmt(c.wind_speed_10m)+' km/h wind');
        const arrows=document.querySelector('.wind-direction-arrows');
        if(arrows){
            const bearing=((Number(c.wind_direction_10m)%360)+360)%360;
            arrows.setAttribute('data-wind-degrees',String(bearing));
            arrows.setAttribute('transform','rotate('+bearing+' 50 50)');
        }
        const score=document.getElementById('rainProbabilityScore'), fill=document.getElementById('rainProbabilityFill');
        const prob=Number(d.precipitation_probability_max?.[0]);
        if(score)score.textContent=Number.isFinite(prob)?Math.round(prob)+'%':'—';
        if(fill)fill.style.width=(Number.isFinite(prob)?Math.max(0,Math.min(100,prob)):0)+'%';
        if(name)name.textContent=location.name+' · '+Number(location.latitude).toFixed(3)+', '+Number(location.longitude).toFixed(3);
        if(window.GaugeIQLoadHistory)window.GaugeIQLoadHistory(document.querySelector('.history-range-button.active')?.dataset.hours||24);
    } catch (error) {
        if(name)name.textContent=location.name+' · dashboard refresh failed';
        const status=document.getElementById('localWeatherStatus');
        if(status){status.textContent=error.message||'Unable to load location.';status.dataset.state='error';}
    }
}
window.GaugeIQSelectDashboardLocation = selectDashboardLocation;

async function loadHistory(hours = 24) {
    if (!historyStatus) return;

    historyStatus.textContent = 'Loading history…';

    try {
        const selectedLocation = document.getElementById('weatherLocationSelect')?.value || 'server-current';
        const isLocal = selectedLocation !== 'server-current';
        let readings;
        let chartTimezone = null;

        if (isLocal && window.GaugeIQLocalWeather) {
            readings = await window.GaugeIQLocalWeather.readingsFor(selectedLocation, hours);
            const locations = await window.GaugeIQLocalWeather.allLocations();
            chartTimezone = locations.find(location => location.id === selectedLocation)?.timezone || null;
        } else {
            const requestedHours = hours === 'all' ? 'all' : Math.min(8760, Math.max(1, Number(hours) || 24));
            const response = await fetch('../api/history.php?hours=' + encodeURIComponent(requestedHours), { cache: 'no-store' });
            if (!response.ok) throw new Error('History unavailable.');
            const data = await response.json();
            readings = Array.isArray(data.readings) ? data.readings : [];
        }

        const charts = [
            ['pressureChart', readings.map(r => ({ value: r.pressure_hpa == null ? NaN : Number(r.pressure_hpa), time: r.timestamp || r.observed_at })), ' hPa', 1],
            ['humidityChart', readings.map(r => ({ value: r.humidity_percent == null ? NaN : Number(r.humidity_percent), time: r.timestamp || r.observed_at })), '%', 0],
            ['windChart', readings.map(r => ({ value: r.wind_speed_kmh == null ? NaN : Number(r.wind_speed_kmh), time: r.timestamp || r.observed_at })), ' km/h', 1]
        ];

        charts.forEach(([id, values, unit, decimals]) => {
            const canvas = document.getElementById(id);
            if (canvas) drawChart(canvas, values, unit, decimals, hours, chartTimezone);
        });

        const rangeLabel = hours === 'all' ? 'all history'
            : hours === 168 ? '7 days'
            : hours === 720 ? '30 days'
            : hours === 2160 ? '90 days'
            : hours === 8760 ? '1 year'
            : hours + ' hours';
        const sourceLabel = isLocal ? 'local' : 'server';
        historyStatus.textContent = readings.length
            ? rangeLabel.charAt(0).toUpperCase() + rangeLabel.slice(1) + ' · ' + readings.length + ' readings · ' + sourceLabel
            : (isLocal ? 'No local readings in this time range. Try a longer range or All imported history.' : 'No readings have been recorded yet.');

        const title = document.getElementById('historyTitle');
        if (title) title.textContent = 'History · ' + rangeLabel;
    } catch (error) {
        historyStatus.textContent = error instanceof Error && error.message
            ? error.message
            : 'Historical readings are currently unavailable.';
    }
}

window.GaugeIQLoadHistory = loadHistory;

document.querySelectorAll('.history-range-button').forEach(button => {
    button.addEventListener('click', () => {
        if (button.hidden) return;
        document.querySelectorAll('.history-range-button').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        loadHistory(button.dataset.hours === 'all' ? 'all' : Number(button.dataset.hours));
    });
});

const weatherChangeReasonsToggle = document.getElementById('weatherChangeReasonsToggle');
const weatherChangeReasonsPanel = document.getElementById('weatherChangeReasons');
if (weatherChangeReasonsToggle && weatherChangeReasonsPanel) {
    weatherChangeReasonsToggle.addEventListener('click', () => {
        const willShow = weatherChangeReasonsPanel.hidden;
        weatherChangeReasonsPanel.hidden = !willShow;
        weatherChangeReasonsToggle.setAttribute('aria-expanded', String(willShow));
        weatherChangeReasonsToggle.textContent = willShow ? 'Hide reasons' : 'Show reasons';
    });
}

loadHistory();
loadWeatherChange();

async function checkForGaugeIQUpdate() {
    const notice = document.getElementById('updateNotice');
    const title = document.getElementById('updateNoticeTitle');
    const message = document.getElementById('updateNoticeText');
    const link = document.getElementById('updateNoticeLink');
    if (!notice || !title || !message || !link) return;

    try {
        const response = await fetch('../api/update-check.php', { cache: 'no-store' });
        if (!response.ok) return;

        const data = await response.json();
        if (!data.update_available) return;

        title.textContent = 'GaugeIQ update available';
        message.textContent = 'Version ' + data.latest_version + ' is available.';
        link.href = data.release_url || '#';
        notice.hidden = false;
    } catch {
        // Update checks are optional and must never interrupt the dashboard.
    }
}

checkForGaugeIQUpdate();

const cronCopyButton = document.getElementById('cronCopyButton');
const cronCommand = document.getElementById('cronCommand');
const cronCopyStatus = document.getElementById('cronCopyStatus');
if (cronCopyButton && cronCommand) {
    cronCopyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(cronCommand.textContent.trim());
            cronCopyStatus.textContent = 'Command copied. Paste it into cPanel Cron Jobs.';
            cronCopyButton.textContent = 'Copied';
            setTimeout(() => { cronCopyButton.textContent = 'Copy command'; }, 1800);
        } catch {
            cronCopyStatus.textContent = 'Copy failed. Select the command and copy it manually.';
        }
    });
}


let gaugeIqHiddenAt = null;

function refreshGaugeIqWhenReturning() {
    if (gaugeIqHiddenAt === null) return;
    const hiddenFor = Date.now() - gaugeIqHiddenAt;
    gaugeIqHiddenAt = null;
    if (hiddenFor >= 60 * 1000) {
        window.location.reload();
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        gaugeIqHiddenAt = Date.now();
    } else if (document.visibilityState === 'visible') {
        refreshGaugeIqWhenReturning();
    }
});

window.addEventListener('pageshow', () => {
    refreshGaugeIqWhenReturning();
});


const deviceViewButton = document.getElementById('deviceViewButton');
const deviceList = document.getElementById('deviceList');

function formatDeviceDate(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

async function getCurrentPushEndpointHash() {
    try {
        if (!('PushManager' in window)) return null;
        const registration = await getRegistration();
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return null;
        const bytes = new TextEncoder().encode(subscription.endpoint);
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
    } catch {
        return null;
    }
}

async function loadDeviceList() {
    deviceList.innerHTML = '<p class="muted">Loading devices…</p>';
    try {
        const response = await fetch('../api/push-devices.php', { cache: 'no-store' });
        if (!response.ok) throw new Error('Unable to load registered devices.');
        const data = await response.json();
        const currentHash = await getCurrentPushEndpointHash();
        const devices = Array.isArray(data.devices) ? data.devices : [];

        if (!devices.length) {
            deviceList.innerHTML = '<p class="muted">No registered notification devices.</p>';
            return;
        }

        deviceList.innerHTML = devices.map(device => {
            const current = currentHash && currentHash === device.endpoint_hash;
            const pushStatus = device.last_push_status === 'sent'
                ? 'Last notification accepted by push service'
                : device.last_push_status === 'failed'
                    ? 'Last notification failed: ' + (device.last_push_error || 'unknown error')
                    : 'No notification delivery attempt recorded yet';

            return '<div class="device-item">' +
                '<div class="device-item-heading"><strong>' + device.device + (current ? ' · This device' : '') + '</strong><button type="button" class="device-delete" data-device-id="' + device.id + '">Delete</button></div>' +
                '<div class="device-details"><span>Browser</span><strong>' + device.browser + '</strong><span>Registered</span><strong>' + formatDeviceDate(device.created_at) + '</strong><span>Last seen</span><strong>' + formatDeviceDate(device.last_seen_at || device.updated_at) + '</strong><span>Delivery</span><strong>' + pushStatus + '</strong></div>' +
                '</div>';
        }).join('');

        deviceList.querySelectorAll('.device-delete').forEach(button => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Delete this notification device?')) return;
                try {
                    const csrf = document.querySelector('input[name="csrf"]')?.value || '';
                    const body = new URLSearchParams({ id: button.dataset.deviceId, csrf });
                    const response = await fetch('../api/push-devices.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
                    if (!response.ok) throw new Error('Unable to delete the device.');
                    await loadDeviceList();
                    status.textContent = 'Notification device removed.';
                } catch (error) {
                    status.textContent = error instanceof Error ? error.message : 'Unable to delete the device.';
                }
            });
        });
    } catch (error) {
        deviceList.innerHTML = '<p class="muted">' + (error instanceof Error ? error.message : 'Unable to load devices.') + '</p>';
    }
}

if (deviceViewButton && deviceList) {
    deviceViewButton.addEventListener('click', async () => {
        const open = deviceList.hidden;
        deviceList.hidden = !open;
        deviceViewButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        deviceViewButton.textContent = open ? 'Hide' : 'View';
        if (open) await loadDeviceList();
    });
}
