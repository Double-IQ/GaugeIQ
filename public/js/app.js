// Show the branded screen on every fresh page load, then dismiss it as soon as
// the dashboard has initialised; do not wait for charts, video, or optional checks.
(() => {
    const screen = document.getElementById('gaugeiqLoadingScreen');
    const message = document.getElementById('gaugeiqLoadingMessage');
    if (!screen) return;

    const minimumVisibleMs = 2000;
    let dismissed = false;
    let reloadStarted = false;
    let hiddenAt = 0;
    let loadingStartedAt = Date.now();

    function showLoading(text) {
        if (message && text) message.textContent = text;
        loadingStartedAt = Date.now();
        dismissed = false;
        screen.hidden = false;
        screen.style.display = '';
        screen.classList.remove('is-leaving');
        screen.setAttribute('aria-hidden', 'false');
    }

    function dismissLoading() {
        if (dismissed || reloadStarted) return;
        const remaining = minimumVisibleMs - (Date.now() - loadingStartedAt);
        if (remaining > 0) {
            window.setTimeout(dismissLoading, remaining);
            return;
        }
        dismissed = true;
        screen.classList.add('is-leaving');
        window.setTimeout(() => {
            screen.hidden = true;
        }, 180);
    }

    // DOM readiness is sufficient; optional requests and external media can finish later.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.setTimeout(dismissLoading, 160), { once: true });
    } else {
        window.setTimeout(dismissLoading, 160);
    }

    // A bfcache-restored page may contain stale suspended dashboard data.
    // Show the screen and request a fresh server-rendered dashboard instead.
    function refreshOnResume() {
        if (reloadStarted) return;
        reloadStarted = true;
        showLoading('Refreshing data…');
        window.setTimeout(() => window.location.reload(), minimumVisibleMs);

        // If the reload stalls or fails on a mobile connection, release the overlay.
        window.setTimeout(() => {
            if (document.visibilityState === 'visible' && !screen.hidden && reloadStarted) {
                reloadStarted = false;
                dismissLoading();
            }
        }, minimumVisibleMs + 6000);
    }

    window.addEventListener('pageshow', event => {
        if (event.persisted) refreshOnResume();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
            return;
        }
        if (document.visibilityState === 'visible' && hiddenAt && Date.now() - hiddenAt >= 30000) {
            refreshOnResume();
        }
    });

    // Never leave the screen covering the dashboard if readiness events misbehave.
    window.setTimeout(dismissLoading, 4500);
})();

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

function initClimateGraphSelector() {
    const selector = document.querySelector('.climate-graph-selector');
    const options = [...document.querySelectorAll('[data-climate-graph-option]')];
    const rows = [...document.querySelectorAll('[data-climate-graph]')];
    if (!selector || options.length === 0 || rows.length === 0) return;

    const storageKey = 'gaugeiq-atmospheric-graphs-v1';
    const availableKeys = options.map(option => option.dataset.climateGraphOption);
    let selectedKeys;
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        selectedKeys = Array.isArray(saved)
            ? new Set(saved.filter(key => availableKeys.includes(key)))
            : new Set(availableKeys.filter(key => key !== 'wvi'));
    } catch {
        selectedKeys = new Set(availableKeys.filter(key => key !== 'wvi'));
    }

    const applySelection = () => {
        options.forEach(option => {
            option.checked = selectedKeys.has(option.dataset.climateGraphOption);
        });
        rows.forEach(row => {
            row.hidden = !selectedKeys.has(row.dataset.climateGraph);
        });
    };

    options.forEach(option => {
        option.addEventListener('change', () => {
            const key = option.dataset.climateGraphOption;
            if (option.checked) selectedKeys.add(key);
            else selectedKeys.delete(key);
            try {
                localStorage.setItem(storageKey, JSON.stringify([...selectedKeys]));
            } catch {
                // The current selection still applies for this page view.
            }
            applySelection();
        });
    });

    applySelection();
}

initClimateGraphSelector();


const windGauge = document.querySelector('.wind-hud-vector');
const windCompassStatus = document.getElementById('windCompassStatus');
const windCompassButton = document.getElementById('windCompassButton');

const WIND_COMPASS_STORAGE_KEY = 'gaugeiq-compass-enabled-v2';
const WIND_COMPASS_SMOOTHING = 0.18;
const WIND_COMPASS_NORTH_BUFFER = 6;

const storedWindCompassState = localStorage.getItem(WIND_COMPASS_STORAGE_KEY);
const windCompassCanRequestPermission = typeof DeviceOrientationEvent !== 'undefined'
    && typeof DeviceOrientationEvent.requestPermission === 'function';
// A saved "on" preference cannot replace the fresh user gesture required
// by iOS/Safari. Start off there so the permission button is always available.
let windCompassEnabled = windCompassCanRequestPermission
    ? false
    : storedWindCompassState === 'true';
let windCompassListening = false;
// Once the browser supplies iOS's native compass heading, ignore fallback alpha
// readings from the parallel absolute-orientation event stream.
let windCompassNativeHeadingSeen = false;
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

            // Rotate the wind vector relative to the phone heading while the
            // glass instrument panel remains fixed in the centre of the card.
            const windNeedle = windGauge.querySelector('.wind-hud-vector-arrow');
            if (windNeedle) {
                const windToDegrees = normalizeCompassHeading(windNeedle.dataset.windToDegrees);
                if (windToDegrees !== null) {
                    const relativeBearing = shortestCompassDelta(windCompassHeading, windToDegrees);
                    windNeedle.setAttribute('transform', 'rotate(' + relativeBearing.toFixed(2) + ' 170 90)');
                }
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
        // Prefer the iPhone's native compass reading. Once seen, don't let a
        // second orientation event stream overwrite it with a different alpha
        // value and make the dial appear to jump.
        windCompassNativeHeadingSeen = true;
        heading = event.webkitCompassHeading; // Native iOS heading is already the compass heading; do not apply screen rotation twice.
    } else if (!windCompassNativeHeadingSeen && event.absolute === true && Number.isFinite(event.alpha)) {
        // Fallback for devices without a native compass heading. Never use
        // relative alpha because it does not reliably identify geographic north.
        heading = 360 - event.alpha + screenOffset;
    }

    if (heading !== null) {
        applyWindCompassHeading(heading);
    }
}

function updateWindCompassButton() {
    if (!windCompassButton) return;

    windCompassButton.textContent = windCompassEnabled ? 'Disable Compass' : 'Enable Compass';
    windCompassButton.setAttribute('aria-pressed', windCompassEnabled ? 'true' : 'false');
    windCompassButton.classList.toggle('is-enabled', windCompassEnabled);
    windCompassButton.disabled = !('DeviceOrientationEvent' in window);
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
            const permission = await DeviceOrientationEvent.requestPermission();
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
            windCompassStatus.hidden = false;
            windCompassStatus.textContent = error instanceof Error ? error.message : 'Compass unavailable';
        }
        updateWindCompassButton();
    }
}

function disableWindCompass() {
    windCompassEnabled = false;
    localStorage.setItem(WIND_COMPASS_STORAGE_KEY, 'false');

    if (windCompassListening) {
        window.removeEventListener('deviceorientation', handleWindDeviceOrientation, true);
        window.removeEventListener('deviceorientationabsolute', handleWindDeviceOrientation, true);
        windCompassListening = false;
    }

    if (windCompassAnimationFrame !== null) {
        cancelAnimationFrame(windCompassAnimationFrame);
        windCompassAnimationFrame = null;
    }

    windCompassNativeHeadingSeen = false;
    windCompassHeading = null;
    windCompassTargetHeading = null;
    windCompassRotation = null;

    // Return the vector to its north-up, absolute-bearing orientation.
    const windNeedle = windGauge?.querySelector('.wind-hud-vector-arrow');
    if (windNeedle) {
        const windToDegrees = normalizeCompassHeading(windNeedle.dataset.windToDegrees);
        if (windToDegrees !== null) {
            windNeedle.setAttribute('transform', 'rotate(' + windToDegrees.toFixed(2) + ' 170 90)');
        }
    }

    if (windCompassStatus) windCompassStatus.hidden = true;
    updateWindCompassButton();
}

if (windGauge) {
    const initialWindNeedle = windGauge.querySelector('.wind-hud-vector-arrow');
    if (initialWindNeedle) {
        const windToDegrees = normalizeCompassHeading(initialWindNeedle.dataset.windToDegrees);
        if (windToDegrees !== null) {
            initialWindNeedle.setAttribute('transform', 'rotate(' + windToDegrees.toFixed(2) + ' 170 90)');
        }
    }

    if (windCompassStatus) windCompassStatus.hidden = true;

    if (windCompassButton) {
        windCompassButton.addEventListener('click', async () => {
            if (windCompassEnabled) {
                disableWindCompass();
            } else {
                await enableWindCompass();
            }
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
const notificationEnabledNote = document.getElementById('notificationEnabledNote');
const temperatureAlertsStatus = document.getElementById('temperatureAlertsStatus');
const historyStatus = document.getElementById('historyStatus');
let deferredInstallPrompt = null;
let installedThisSession = false;

function isInstalledPWA() {
    return installedThisSession || window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function updateInstallButton() {
    if (!notifyButton) return;
    const installed = isInstalledPWA();
    notifyButton.textContent = installed ? 'Enable alerts' : 'Install';
    notifyButton.setAttribute('aria-label', installed ? 'Enable GaugeIQ alerts' : 'Install GaugeIQ as an app');
}

function setTemperatureAlertsStatus(state) {
    if (!temperatureAlertsStatus) return;
    const labels = { enabled: 'Enabled', disabled: 'Disabled', na: 'N/A' };
    temperatureAlertsStatus.dataset.state = state;
    temperatureAlertsStatus.innerHTML = 'Alerts: <b>' + (labels[state] || labels.na) + '</b>';
}

function showNotificationsEnabledUI() {
    if (notifyButton) notifyButton.hidden = true;
    if (notificationEnabledNote) notificationEnabledNote.hidden = false;
    setTemperatureAlertsStatus('enabled');
}

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

    if (status) status.textContent = '';
    showNotificationsEnabledUI();
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
        status.textContent = 'PWA service worker could not be registered.';
    });
}

function isSamsungInternet() {
    return /SamsungBrowser/i.test(navigator.userAgent);
}

window.addEventListener('beforeinstallprompt', event => {
    // Samsung Internet has a reported Android PWA wrapper issue that can trigger
    // Play Protect's outdated-target warning. Prefer Chrome's installer there.
    event.preventDefault();
    deferredInstallPrompt = isSamsungInternet() ? null : event;
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    installedThisSession = true;
    updateInstallButton();
    if (status) status.textContent = 'GaugeIQ has been installed. Open it from your home screen to enable alerts.';
});

window.matchMedia('(display-mode: standalone)').addEventListener?.('change', updateInstallButton);
window.addEventListener('pageshow', updateInstallButton);
updateInstallButton();

async function installGaugeIQ() {
    if (isInstalledPWA()) return false;

    if (deferredInstallPrompt) {
        const installPrompt = deferredInstallPrompt;
        deferredInstallPrompt = null;
        await installPrompt.prompt();
        const choice = await installPrompt.userChoice;
        if (choice.outcome === 'accepted') {
            if (status) status.textContent = 'Installation accepted. Follow any remaining browser prompts to finish installing GaugeIQ.';
        } else if (status) {
            status.textContent = 'Installation was cancelled. You can install GaugeIQ later from this button or your browser menu.';
        }
        return true;
    }

    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (status) {
        status.textContent = isIOS
            ? 'To install GaugeIQ, open this page in Safari, tap Share, choose Add to Home Screen, and enable Open as Web App if shown.'
            : 'To install GaugeIQ as an app, open this page in Chrome and use the menu’s “Install app” option if available. If only “Add to Home screen” is offered, the browser has not provided the PWA installer.';
    }
    return true;
}

async function showAlertsOnIfAlreadyEnabled() {
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
        setTemperatureAlertsStatus('na');
        return;
    }
    setTemperatureAlertsStatus('disabled');
    if (Notification.permission !== 'granted') return;
    try {
        const registration = await getRegistration();
        if (await registration.pushManager.getSubscription()) showNotificationsEnabledUI();
    } catch {
        // Leave the status as Disabled if an active subscription cannot be confirmed.
    }
}

showAlertsOnIfAlreadyEnabled();

if (notifyButton) {
    notifyButton.addEventListener('click', async () => {
        try {
            const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) ||
                (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (!isInstalledPWA() && isIOS) {
                window.location.href = 'https://github.com/Double-IQ/GaugeIQ#install-gaugeiq';
                return;
            }
            if (!isInstalledPWA()) {
                await installGaugeIQ();
                return;
            }
            if (!('Notification' in window) || !('PushManager' in window)) {
                throw new Error('This browser does not support Web Push notifications.');
            }
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('Notification permission was not granted.');
            await enablePushNotifications();
        } catch (error) {
            if (status) status.textContent = error instanceof Error ? error.message : 'Unable to install GaugeIQ or enable notifications.';
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

const WEATHER_FORMULA_CONFIG = (() => {
    try {
        return JSON.parse(document.body?.dataset.weatherFormulas || '{}');
    } catch {
        return {};
    }
})();
function weatherFormula(key, fallback) {
    const value = Number(WEATHER_FORMULA_CONFIG[key]);
    return Number.isFinite(value) ? value : fallback;
}

const WEATHER_CHANGE_WEIGHTS = {
    pressure: weatherFormula('stability_weight_pressure', 2),
    temperature: weatherFormula('stability_weight_temperature', 1),
    humidity: weatherFormula('stability_weight_humidity', 1),
    windSpeed: weatherFormula('stability_weight_wind_speed', 2),
    windDirection: weatherFormula('stability_weight_wind_direction', 1),
    dewPointSpread: weatherFormula('stability_weight_dew_point_spread', 1),
    rainfall: weatherFormula('stability_weight_rainfall', 2),
    cloudCover: weatherFormula('stability_weight_cloud_cover', 1),
    weatherCode: weatherFormula('stability_weight_weather_code', 1)
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

function weatherReadingTimestamp(item) {
    // Prefer the source's observation timestamp so hourly backfilled estimates
    // remain in their true time window instead of appearing newly created.
    const value = item?.timestamp || item?.observed_at || item?.created_at;
    const timestamp = new Date(value).getTime();
    return Number.isFinite(timestamp) ? timestamp : NaN;
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
    const sorted = readings
        .map(item => {
            const row = { source: item.source ?? null };
            Object.entries(fields).forEach(([key, source]) => { row[key] = weatherNumeric(item[source]); });
            row.time = weatherReadingTimestamp(item);
            return row;
        })
        .filter(row => Number.isFinite(row.time))
        .sort((a, b) => a.time - b.time);

    // Prefer actual live readings at duplicated timestamps and prevent model
    // backfill duplicates from giving one hourly period extra weight.
    const byTime = new Map();
    sorted.forEach(row => {
        const existing = byTime.get(row.time);
        if (!existing ||
            (existing.source === 'Open-Meteo Recent Backfill' && row.source !== 'Open-Meteo Recent Backfill') ||
            existing.source === row.source) {
            byTime.set(row.time, row);
        }
    });
    return [...byTime.values()].sort((a, b) => a.time - b.time);
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

function weatherGradientScore(valid) {
    // Sum absolute movement between successive timestamped readings so reversals
    // contribute to volatility even when the first and last values are similar.
    const components = [];
    const add = (key, label, severity, evidence) => {
        if (!Number.isFinite(severity)) return;
        components.push({
            key, label, weight: WEATHER_CHANGE_WEIGHTS[key],
            severity: Math.max(0, Math.min(1, severity)), evidence
        });
    };
    const movement = field => {
        const rows = valid.filter(row => Number.isFinite(row[field]));
        if (rows.length < 2) return null;
        let distance = 0;
        for (let i = 1; i < rows.length; i++) distance += Math.abs(rows[i][field] - rows[i - 1][field]);
        const hours = (rows[rows.length - 1].time - rows[0].time) / 3600000;
        return hours > 0 ? { distance, hours, scaled: distance * 6 / hours } : null;
    };

    const pressure = movement('pressure');
    if (pressure) add('pressure', 'Air pressure', weatherSeverity(pressure.distance / pressure.hours, weatherFormula('stability_trigger_pressure', 0.3), weatherFormula('stability_max_pressure', 1.5)), 'cumulative movement ' + pressure.distance.toFixed(1) + ' hPa (six-hour equivalent ' + pressure.scaled.toFixed(1) + ' hPa)');
    const definitions = [
        ['temperature', 'Temperature', 'temperature', 1, 3, '°C'],
        ['humidity', 'Humidity', 'humidity', 4, 12, ' percentage points'],
        ['windSpeed', 'Wind speed', 'wind', 8, 20, ' km/h'],
        ['rainfall', 'Rainfall', 'rain', 0.2, 2, ' mm'],
        ['cloudCover', 'Cloud cover', 'cloud', 15, 50, ' percentage points']
    ];
    definitions.forEach(([key, label, field, trigger, max, unit]) => {
        const item = movement(field);
        if (item) add(key, label, weatherSeverity(item.scaled, weatherFormula('stability_trigger_' + ({
            temperature: 'temperature', humidity: 'humidity', wind: 'wind_speed', rain: 'rainfall', cloud: 'cloud_cover'
        }[field]), trigger), weatherFormula('stability_max_' + ({
            temperature: 'temperature', humidity: 'humidity', wind: 'wind_speed', rain: 'rainfall', cloud: 'cloud_cover'
        }[field]), max)), 'cumulative movement ' + item.distance.toFixed(1) + unit + ' (six-hour equivalent ' + item.scaled.toFixed(1) + unit + ')');
    });

    const directions = valid.filter(row => Number.isFinite(row.direction));
    if (directions.length >= 2) {
        let distance = 0;
        for (let i = 1; i < directions.length; i++) distance += weatherCircularDifference(directions[i - 1].direction, directions[i].direction);
        const hours = (directions[directions.length - 1].time - directions[0].time) / 3600000;
        if (hours > 0) add('windDirection', 'Wind direction', weatherSeverity(distance * 6 / hours, weatherFormula('stability_trigger_wind_direction', 20), weatherFormula('stability_max_wind_direction', 90)), 'cumulative shortest-angle movement ' + Math.round(distance) + '° (six-hour equivalent ' + (distance * 6 / hours).toFixed(1) + '°)');
    }

    const spreads = valid.filter(row => Number.isFinite(row.temperature) && Number.isFinite(row.dewPoint))
        .map(row => ({ time: row.time, value: row.temperature - row.dewPoint }));
    if (spreads.length >= 2) {
        let distance = 0;
        for (let i = 1; i < spreads.length; i++) distance += Math.abs(spreads[i].value - spreads[i - 1].value);
        const hours = (spreads[spreads.length - 1].time - spreads[0].time) / 3600000;
        if (hours > 0) add('dewPointSpread', 'Temperature/dew-point gap', weatherSeverity(distance * 6 / hours, weatherFormula('stability_trigger_dew_point_spread', 1), weatherFormula('stability_max_dew_point_spread', 3)), 'cumulative gap movement ' + distance.toFixed(1) + '°C (six-hour equivalent ' + (distance * 6 / hours).toFixed(1) + '°C)');
    }

    const codes = valid.filter(row => Number.isFinite(row.weatherCode));
    if (codes.length >= 2) {
        let distance = 0;
        for (let i = 1; i < codes.length; i++) distance += Math.abs(weatherCodeSeverity(codes[i].weatherCode) - weatherCodeSeverity(codes[i - 1].weatherCode));
        const hours = (codes[codes.length - 1].time - codes[0].time) / 3600000;
        if (hours > 0) {
            const scaled = distance * 6 / hours;
            add('weatherCode', 'Reported weather condition', distance > 0 ? Math.max(0.25, weatherSeverity(scaled, weatherFormula('stability_trigger_weather_code', 1), weatherFormula('stability_max_weather_code', 5))) : 0, 'cumulative severity-category movement ' + distance.toFixed(1) + ' (six-hour equivalent ' + scaled.toFixed(1) + ')');
        }
    }

    if (components.length < weatherFormula('stability_min_signals', 5)) return { score: null, components, coverage: components.length };
    const availableWeight = components.reduce((sum, component) => sum + component.weight, 0);
    const weightedSeverity = components.reduce((sum, component) => sum + component.weight * component.severity, 0);
    const score = Math.round((10 * weightedSeverity / availableWeight) * 10) / 10;
    components.forEach(component => { component.contribution = 10 * component.weight * component.severity / availableWeight; });
    return { score, components, coverage: components.length, availableWeight, weightedSeverity };
}

function weatherChangeScore(readings) {
    const valid = weatherReadingsWindow(readings);
    if (valid.length < weatherFormula('stability_min_readings', 3)) {
        return { score: null, summary: 'Gathering more readings…', components: [], reasons: [], coverage: 0 };
    }

    const totalHours = (valid[valid.length - 1].time - valid[0].time) / 3600000;
    if (totalHours < weatherFormula('stability_min_hours', 1)) {
        return { score: null, summary: 'Gathering a longer observation window…', components: [], reasons: [], coverage: 0 };
    }

    const gradient = weatherGradientScore(valid);
    if (!Number.isFinite(gradient.score)) {
        return {
            score: null,
            summary: 'Insufficient valid signals to calculate the gradient score (' + gradient.coverage + '/9).',
            components: gradient.components,
            gradientComponents: gradient.components,
            reasons: [],
            coverage: gradient.coverage
        };
    }

    // WVI is calculated exclusively from cumulative gradient movement.
    const score = gradient.score;
    const components = gradient.components;
    const availableWeight = gradient.availableWeight;
    const weightedSeverity = gradient.weightedSeverity;

    let summary;
    if (score < 2) summary = 'Stable · no material change detected';
    else if (score < 4) summary = 'Minor change in recent conditions';
    else if (score < 6) summary = 'Moderate change in recent conditions';
    else if (score < 8) summary = 'Significant change in recent conditions';
    else summary = 'Very significant change in recent conditions';

    return {
        score, gradientScore: gradient.score, summary, components,
        gradientComponents: gradient.components, coverage: components.length,
        availableWeight, weightedSeverity,
        reasons: components.filter(component => component.severity > 0)
            .sort((a, b) => b.contribution - a.contribution)
    };
}

function weatherConditionsTrend(readings) {
    const valid = weatherReadingsWindow(readings);
    if (valid.length < weatherFormula('trend_min_readings', 3) || (valid[valid.length - 1].time - valid[0].time) < weatherFormula('trend_min_hours', 1) * 3600000) {
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
        if (rate <= -weatherFormula('trend_pressure_threshold', 0.3)) addSignal(1, 'Pressure is falling, which can precede unsettled weather.', 'Pressure is rising, which can support more settled weather.', weatherFormula('trend_pressure_weight', 2), 'Pressure');
        else if (rate >= weatherFormula('trend_pressure_threshold', 0.3)) addSignal(-1, 'Pressure is falling, which can precede unsettled weather.', 'Pressure is rising, which can support more settled weather.', weatherFormula('trend_pressure_weight', 2), 'Pressure');
    }

    const rain = weatherFieldPair(valid, 'rain');
    if (rain) {
        const scaled = rain.delta * 6 / rain.hours;
        if (rain.first < weatherFormula('trend_rain_onset', 0.2) && rain.last >= weatherFormula('trend_rain_onset', 0.2)) addSignal(1, 'Rain has appeared in the latest reading.', 'Rainfall has eased or stopped.', weatherFormula('trend_rain_weight', 2), 'Rainfall');
        else if (scaled >= weatherFormula('trend_rain_change_threshold', 0.2)) addSignal(weatherFormula('trend_rain_partial_strength', 0.5), 'The recent rainfall reading has increased.', 'The recent rainfall reading has decreased.', weatherFormula('trend_rain_weight', 2), 'Rainfall');
        else if (scaled <= -weatherFormula('trend_rain_change_threshold', 0.2)) addSignal(-weatherFormula('trend_rain_partial_strength', 0.5), 'The recent rainfall reading has increased.', 'The recent rainfall reading has decreased.', weatherFormula('trend_rain_weight', 2), 'Rainfall');
    }

    const wind = weatherFieldPair(valid, 'wind');
    if (wind) {
        const scaled = wind.delta * 6 / wind.hours;
        if (scaled >= weatherFormula('trend_wind_threshold', 8)) addSignal(1, 'Wind speed is increasing.', 'Wind speed is easing.', weatherFormula('trend_wind_weight', 1), 'Wind speed');
        else if (scaled <= -weatherFormula('trend_wind_threshold', 8)) addSignal(-1, 'Wind speed is increasing.', 'Wind speed is easing.', weatherFormula('trend_wind_weight', 1), 'Wind speed');
    }

    const cloud = weatherFieldPair(valid, 'cloud');
    if (cloud) {
        const scaled = cloud.delta * 6 / cloud.hours;
        if (scaled >= weatherFormula('trend_cloud_threshold', 20)) addSignal(1, 'Cloud cover is increasing.', 'Cloud cover is decreasing.', weatherFormula('trend_cloud_weight', 1), 'Cloud cover');
        else if (scaled <= -weatherFormula('trend_cloud_threshold', 20)) addSignal(-1, 'Cloud cover is increasing.', 'Cloud cover is decreasing.', weatherFormula('trend_cloud_weight', 1), 'Cloud cover');
    }

    const codes = weatherFieldPair(valid, 'weatherCode');
    if (codes) {
        const delta = weatherCodeSeverity(codes.last) - weatherCodeSeverity(codes.first);
        if (delta >= weatherFormula('trend_code_threshold', 1)) addSignal(1, 'The reported weather category has become more severe.', 'The reported weather category has become less severe.', weatherFormula('trend_code_weight', 2), 'Weather code');
        else if (delta <= -weatherFormula('trend_code_threshold', 1)) addSignal(-1, 'The reported weather category has become more severe.', 'The reported weather category has become less severe.', weatherFormula('trend_code_weight', 2), 'Weather code');
    }

    const mixed = reasons.some(reason => reason.points > 0) && reasons.some(reason => reason.points < 0);
    let label = 'Steady';
    let arrow = '→';
    if (score >= weatherFormula('trend_classification_threshold', 2)) { label = 'Worsening'; arrow = '↘'; }
    else if (score <= -weatherFormula('trend_classification_threshold', 2)) { label = 'Improving'; arrow = '↗'; }
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

    for (let day = 1; day <= weatherFormula('baseline_days', 14); day++) {
        const windowEnd = latestTime - day * dayMs;
        const windowReadings = readings.filter(item => {
            const timestamp = weatherReadingTimestamp(item);
            return Number.isFinite(timestamp) && timestamp >= windowEnd - windowMs && timestamp <= windowEnd;
        });
        const result = weatherChangeScore(windowReadings);
        if (Number.isFinite(result.score)) historicalScores.push(result.score);
    }

    if (historicalScores.length < weatherFormula('baseline_min_windows', 5)) {
        return {
            count: historicalScores.length,
            text: 'Building recent local baseline: ' + historicalScores.length + ' comparable six-hour windows available; at least ' + weatherFormula('baseline_min_windows', 5) + ' are needed.'
        };
    }

    historicalScores.sort((a, b) => a - b);
    const middle = Math.floor(historicalScores.length / 2);
    const median = historicalScores.length % 2
        ? historicalScores[middle]
        : (historicalScores[middle - 1] + historicalScores[middle]) / 2;
    const difference = currentScore - median;
    let comparison = 'within the recent pattern';
    if (difference >= weatherFormula('baseline_deviation', 2)) comparison = 'more change than usual in the recent readings';
    else if (difference <= -weatherFormula('baseline_deviation', 2)) comparison = 'less change than usual in the recent readings';

    return {
        count: historicalScores.length,
        median,
        text: 'Recent local pattern: median ' + median.toFixed(1) + '/10. Current change is ' + comparison + '.'
    };
}

function updateWeatherChangeLevel(score, level) {
    const track = document.getElementById('weatherChangeLevel');
    if (!track) return;
    const segments = [...track.children];
    const filled = Number.isFinite(score) ? Math.max(0, Math.min(10, Math.round(score))) : 0;
    track.dataset.level = level || 'unavailable';
    track.setAttribute('aria-label', Number.isFinite(score)
        ? 'WVI level ' + score.toFixed(1) + ' out of 10'
        : 'WVI level unavailable');
    segments.forEach((segment, index) => segment.classList.toggle('is-active', index < filled));
}

function validReadingsForWvi(readings, windowEnd) {
    if (!Number.isFinite(windowEnd) || !Array.isArray(readings)) return [];
    return readings.filter(item => {
        const timestamp = weatherReadingTimestamp(item);
        return Number.isFinite(timestamp) &&
            timestamp >= windowEnd - 6 * 3600000 &&
            timestamp <= windowEnd + 300000;
    });
}

function updateAtmosphericWvi(result, readings, referenceTime) {
    const valueElement = document.getElementById('climateWviValue');
    const changeElement = document.getElementById('climateWviChange');
    const gaugeValue = document.getElementById('climateWviGaugeValue');
    const needle = document.getElementById('climateWviNeedle');
    const gaugeEndpoint = document.getElementById('climateWviEndpoint');
    const graphLine = document.getElementById('climateWviLine');
    const graphArea = document.getElementById('climateWviArea');
    const graphGuide = document.getElementById('climateWviGuide');
    const graphStart = document.getElementById('climateWviStart');
    const graphEnd = document.getElementById('climateWviEnd');
    const gauge = document.querySelector('.climate-bar-wvi .climate-mobile-gauge');
    if (!valueElement || !gaugeValue || !needle || !gaugeEndpoint || !graphLine || !graphArea || !graphGuide || !graphStart || !graphEnd) return;

    const score = Number.isFinite(result?.score) ? Math.max(0, Math.min(10, result.score)) : null;
    const wviBand = score !== null && score >= 6 ? 'orange' : 'blue';
    const wviRow = valueElement.closest('.climate-bar-wvi');
    if (wviRow) wviRow.dataset.wviBand = score === null ? 'unavailable' : wviBand;
    const gaugeSegments = wviRow ? wviRow.querySelectorAll('.climate-segment') : [];
    const filledSegments = score === null ? 0 : Math.round((score / 10) * gaugeSegments.length);
    gaugeSegments.forEach((segment, index) => {
        segment.classList.toggle('active', index < filledSegments);
        segment.classList.remove('change', 'warning-zone');
    });
    valueElement.textContent = score === null ? '—' : score.toFixed(1);
    if (changeElement) {
        const previousTime = Number.isFinite(referenceTime) ? referenceTime - 3600000 : NaN;
        const previousReadings = validReadingsForWvi(readings, previousTime);
        const previousScore = previousReadings.length ? weatherChangeScore(previousReadings).score : null;
        const delta = Number.isFinite(score) && Number.isFinite(previousScore) ? score - previousScore : null;
        changeElement.textContent = delta === null ? '—' : (Math.abs(delta) < 0.05 ? '-' : (delta > 0 ? '+' : '') + delta.toFixed(1));
        changeElement.classList.toggle('rise', delta !== null && delta > 0.05);
        changeElement.classList.toggle('fall', delta !== null && delta < -0.05);
        changeElement.classList.toggle('steady', delta !== null && Math.abs(delta) <= 0.05);
        changeElement.classList.toggle('unavailable', delta === null);
        changeElement.setAttribute('aria-label', delta === null ? 'WVI change over the last hour unavailable' : 'WVI change over the last hour ' + delta.toFixed(1));
    }
    if (score === null) {
        gaugeValue.setAttribute('d', 'M 4 48');
        needle.setAttribute('d', 'M 50 48 L 50 12');
        gaugeEndpoint.setAttribute('cx', '4');
        gaugeEndpoint.setAttribute('cy', '48');
    } else {
        const angle = Math.PI - (Math.PI * score / 10);
        const endX = 50 + (46 * Math.cos(angle));
        const endY = 48 - (46 * Math.sin(angle));
        const needleX = 50 + (35 * Math.cos(angle));
        const needleY = 48 - (35 * Math.sin(angle));
        const halfWidth = 2.8;
        const perpX = halfWidth * Math.sin(angle);
        const perpY = halfWidth * Math.cos(angle);
        gaugeValue.setAttribute('d', 'M 4 48 A 46 46 0 0 1 ' + endX.toFixed(2) + ' ' + endY.toFixed(2));
        gaugeEndpoint.setAttribute('cx', endX.toFixed(2));
        gaugeEndpoint.setAttribute('cy', endY.toFixed(2));
        needle.setAttribute('d',
            'M ' + (50 + perpX).toFixed(2) + ' ' + (48 + perpY).toFixed(2) +
            ' L ' + needleX.toFixed(2) + ' ' + needleY.toFixed(2) +
            ' L ' + (50 - perpX).toFixed(2) + ' ' + (48 - perpY).toFixed(2) + ' Z'
        );
    }

    const validReadings = Array.isArray(readings) ? readings : [];
    const points = [];
    if (Number.isFinite(referenceTime)) {
        for (let hoursAgo = 24; hoursAgo >= 0; hoursAgo--) {
            const sampleTime = referenceTime - hoursAgo * 3600000;
            const windowReadings = validReadingsForWvi(validReadings, sampleTime);
            const historicalScore = weatherChangeScore(windowReadings).score;
            if (!Number.isFinite(historicalScore)) continue;
            const x = ((24 - hoursAgo) / 24) * 100;
            const y = 22 - (Math.max(0, Math.min(10, historicalScore)) / 10) * 18;
            points.push(x.toFixed(2) + ',' + y.toFixed(2));
        }
    }

    const line = points.join(' ');
    graphLine.setAttribute('points', line);
    graphArea.setAttribute('points', points.length >= 2 ? '0,23.5 ' + line + ' 100,23.5' : '');
    if (points.length) {
        const [startX, startY] = points[0].split(',');
        const [endX, endY] = points[points.length - 1].split(',');
        graphStart.setAttribute('cx', startX);
        graphStart.setAttribute('cy', startY);
        graphEnd.setAttribute('cx', endX);
        graphEnd.setAttribute('cy', endY);
        graphGuide.setAttribute('x1', endX);
        graphGuide.setAttribute('x2', endX);
        graphGuide.setAttribute('y1', endY);
        graphGuide.setAttribute('y2', '23.5');
    }
    graphStart.setAttribute('r', points.length > 1 ? '1' : '0');
    graphEnd.setAttribute('r', points.length > 1 ? '1.6' : '0');
    graphGuide.setAttribute('y1', points.length ? graphEnd.getAttribute('cy') : '23.5');
    const graphSvg = graphLine.closest('svg');
    if (graphSvg) {
        graphSvg.setAttribute('aria-label', points.length >= 2
            ? 'WVI trend over the last 24 hours, based on ' + points.length + ' six-hour rolling scores'
            : 'WVI trend unavailable: insufficient historical readings');
    }
    if (gauge) gauge.setAttribute('aria-label', score === null
        ? 'Weather Volatility Index gauge unavailable'
        : 'Weather Volatility Index ' + score.toFixed(1));
}

async function loadWeatherChange() {
    const scoreElement = document.getElementById('weatherChangeScore');
    const summaryElement = document.getElementById('weatherChangeSummary');
    const reasonsElement = document.getElementById('weatherChangeReasons');
    if (!scoreElement || !summaryElement || !reasonsElement) return;

    try {
        const response = await fetch('../api/history.php?hours=360&include_recent_backfill=1', { cache: 'no-store' });
        if (!response.ok) throw new Error('Unable to load recent readings.');
        const data = await response.json();
        const allReadings = Array.isArray(data.readings) ? data.readings : [];
        // Archived historical observations stay out of the live meter window.
        // Recent model backfill is considered only when live-only calculations
        // cannot produce their required result.
        const liveReadings = allReadings.filter(item => item.source == null || item.source === 'Open-Meteo');
        // Keep live meters live-only, but let the local stability baseline use
        // archived observed weather as well. Historical forecasts and temporary
        // recent-model backfill are not treated as observed baseline readings.
        const baselineReadings = allReadings.filter(item =>
            item.source == null ||
            item.source === 'Open-Meteo' ||
            item.source === 'Open-Meteo Historical Weather'
        );
        const responseTime = new Date(data.to || Date.now()).getTime();
        const referenceTime = Number.isFinite(responseTime) ? responseTime : Date.now();
        const currentWindowStart = referenceTime - 6 * 60 * 60 * 1000;
        const inCurrentWindow = item => {
            const timestamp = weatherReadingTimestamp(item);
            return Number.isFinite(timestamp) && timestamp >= currentWindowStart && timestamp <= referenceTime + 300000;
        };
        const readings = liveReadings.filter(inCurrentWindow);
        const liveResult = weatherChangeScore(readings);
        const liveTrend = weatherConditionsTrend(readings);
        let result = liveResult;
        let trend = liveTrend;
        let usedRecentBackfillForScore = false;
        let usedRecentBackfillForTrend = false;
        const recentBackfill = allReadings.filter(item =>
            item.source === 'Open-Meteo Recent Backfill' && inCurrentWindow(item)
        );

        if ((liveResult.score === null || liveTrend.score === null) && recentBackfill.length > 0) {
            const combinedReadings = [...readings, ...recentBackfill];
            if (liveResult.score === null) {
                result = weatherChangeScore(combinedReadings);
                usedRecentBackfillForScore = true;
            }
            if (liveTrend.score === null) {
                trend = weatherConditionsTrend(combinedReadings);
                usedRecentBackfillForTrend = true;
            }
        }

        updateAtmosphericWvi(result, liveReadings, referenceTime);

        const latestTime = liveReadings.reduce((latest, item) => {
            const timestamp = weatherReadingTimestamp(item);
            return Number.isFinite(timestamp) ? Math.max(latest, timestamp) : latest;
        }, 0);
        const baselineElement = document.getElementById('weatherBaselineStatus');
        if (baselineElement && latestTime > 0 && Number.isFinite(result.score) && !usedRecentBackfillForScore) {
            const baseline = weatherLocalBaseline(baselineReadings, result.score, latestTime);
            const ageMinutes = Math.max(0, Math.round((referenceTime - latestTime) / 60000));
            baselineElement.textContent = baseline.text + (ageMinutes > 90
                ? ' Latest live reading is about ' + ageMinutes + ' minutes old; the result may be stale.'
                : '');
        } else if (baselineElement) {
            if (usedRecentBackfillForScore) {
                baselineElement.textContent = 'Recent hourly model estimates are temporarily included because the live reading window is insufficient. The live-only local baseline comparison is paused until enough live readings return.';
            } else {
                baselineElement.textContent = latestTime > 0
                    ? 'Not enough valid current readings to compare against the recent local pattern.'
                    : 'No live readings are available yet to establish a local baseline.';
            }
        }

        const conditionsElement = document.getElementById('weatherConditions');
        const trendIcon = document.getElementById('conditionsTrendIcon');
        const trendSummary = document.getElementById('conditionsTrendSummary');
        const trendReasons = document.getElementById('conditionsTrendReasons');

        if (result.score === null) {
            scoreElement.textContent = '—';
            updateWeatherChangeLevel(null, 'unavailable');
            summaryElement.textContent = result.summary + (usedRecentBackfillForScore
                ? ' Recent hourly model estimates were included, but the available signals are still insufficient.'
                : '');
            reasonsElement.replaceChildren(summaryElement);
            reasonsElement.insertAdjacentHTML('beforeend', '<div class="weather-change-reason"><span>GaugeIQ needs at least three readings spanning one hour and at least five of nine valid signals. ' + result.coverage + '/9 signals are available in this window.</span></div>');
            document.getElementById('weatherChangeTrack')?.setAttribute('aria-valuenow', '0');
            document.getElementById('weatherChangeFill')?.style.setProperty('width', '0%');
        } else {
            // The weighted change score is the Weather Volatility Index (WVI):
            // 0 = little recent change; 10 = high recent volatility. Push alerts use this same score.
            const volatilityScore = Math.round(result.score * 10) / 10;
            scoreElement.textContent = volatilityScore.toFixed(1);
            updateWeatherChangeLevel(volatilityScore, volatilityScore >= 6 ? 'moderate' : 'high');
            scoreElement.setAttribute('aria-label', 'Weather Volatility Index score ' + volatilityScore.toFixed(1) + ' out of 10');

            let volatilitySummary;
            if (volatilityScore > 8) volatilitySummary = 'Very high volatility · Substantial changes detected';
            else if (volatilityScore > 6) volatilitySummary = 'High volatility · Significant changes detected';
            else if (volatilityScore > 4) volatilitySummary = 'Moderate volatility · Noticeable changes detected';
            else if (volatilityScore > 2) volatilitySummary = 'Low volatility · Only minor changes detected';
            else volatilitySummary = 'Very low volatility · Small change detected';

            const summaryParts = volatilitySummary.split('·');
            const summaryLabel = document.createElement('span');
            summaryLabel.textContent = summaryParts.shift().trim();
            summaryElement.replaceChildren(summaryLabel);
            const summaryDetail = document.createElement('span');
            summaryDetail.className = 'weather-change-summary-change';
            summaryDetail.textContent = summaryParts.join('·').trim() + (usedRecentBackfillForScore
                ? (summaryParts.length ? ' · ' : '') + 'Temporary recent hourly model estimates used because live readings were insufficient.'
                : '');
            if (summaryDetail.textContent) {
                summaryElement.append(document.createTextNode(' · '), summaryDetail);
            }
            const level = volatilityScore >= 7 ? 'low' : volatilityScore >= 6 ? 'moderate' : 'high';
            const topStabilityLabel = document.getElementById('temperatureStabilityLabel');
            if (topStabilityLabel) {
                const stabilitySummaryElement = document.getElementById('temperatureStabilitySummary');
                if (stabilitySummaryElement) {
                    stabilitySummaryElement.textContent = volatilityScore.toFixed(1);
                    stabilitySummaryElement.className = 'temperature-stability-summary';
                    stabilitySummaryElement.dataset.level = level;
                    stabilitySummaryElement.dataset.outlineLevel = volatilityScore >= 7 ? 'low' : volatilityScore >= 6 ? 'moderate' : 'normal';
                }
            }
            scoreElement.dataset.level = level;
            document.getElementById('weatherChangeTrack')?.setAttribute('aria-valuenow', String(volatilityScore));
            const stabilityScoreFill = document.getElementById('weatherChangeFill');
            if (stabilityScoreFill) {
                stabilityScoreFill.style.width = (volatilityScore * 10) + '%';
                stabilityScoreFill.dataset.level = level;
            }
            const reasonsHtml = result.components
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
                        const evidence = String(component.evidence || '');
                        const movementMatch = evidence.match(/^cumulative(?: shortest-angle| severity-category)? movement ([\d.]+)(.*?)(?: \(six-hour equivalent ([\d.]+)(.*?)\))?$/i);
                        const gapMatch = evidence.match(/^cumulative gap movement ([\d.]+)(.*)$/i);
                        const match = movementMatch || gapMatch;
                        if (match) {
                            const cumulative = match[1] + (match[2] || '');
                            const equivalent = movementMatch && movementMatch[3]
                                ? movementMatch[3] + (movementMatch[4] || '')
                                : '—';
                            return '<div class="weather-change-reason weather-change-detail">' +
                                '<strong class="weather-change-detail-title">' + component.label + '</strong>' +
                                '<div class="weather-change-detail-values">' +
                                '<span class="weather-change-detail-cumulative"><small>Cumulative</small><b>' + cumulative + '</b></span>' +
                                '<span class="weather-change-detail-equivalent"><small>6-hour equivalent</small><b>' + equivalent + '</b></span>' +
                                '</div></div>';
                        }
                        description = '<strong>' + component.label + '</strong>: ' + evidence;
                    }

                    return description ? '<div class="weather-change-reason"><span>' + description + '</span></div>' : '';
                }).join('');
            reasonsElement.replaceChildren(summaryElement);
            reasonsElement.insertAdjacentHTML('beforeend', reasonsHtml);
            const stabilityRow = document.querySelector('.temperature-stability-row');
            const thresholdValue = stabilityRow?.dataset.weatherStabilityAlertThreshold;
            const alertThreshold = thresholdValue === undefined || thresholdValue === ''
                ? NaN
                : Number(thresholdValue);
            const warningActive = Number.isFinite(alertThreshold) && volatilityScore >= alertThreshold;
            document.getElementById('temperatureStabilitySummary')?.classList.toggle('warning', warningActive);
        }

        if (trend.score === null) {
            if (trendSummary) trendSummary.textContent = 'Gathering more valid readings…' + (usedRecentBackfillForTrend
                ? ' Recent hourly model estimates were included, but the available signals are still insufficient.'
                : '');
            if (trendReasons) trendReasons.innerHTML = '<div class="weather-change-reason">A directional trend needs at least three readings spanning one hour.</div>';
            if (trendIcon) trendIcon.textContent = '→';
            if (conditionsElement) conditionsElement.innerHTML = 'Trend: <b class="conditions-status gathering">→ Gathering readings…</b>';
        } else {
            if (trendIcon) {
                trendIcon.textContent = trend.arrow;
                trendIcon.dataset.trend = trend.label.toLowerCase();
                trendIcon.setAttribute('aria-label', trend.label);
            }
            if (trendSummary) trendSummary.textContent = trend.label + ' · ' + trend.explanation + (usedRecentBackfillForTrend
                ? ' · Temporary recent hourly model estimates used because live readings were insufficient.'
                : '');
            if (trendReasons) {
                trendReasons.innerHTML = trend.reasons.length
                    ? trend.reasons.map(reason => {
                        const explanation = String(reason.explanation || '');
                        const repeatsLabel = explanation.toLowerCase().startsWith(reason.label.toLowerCase() + ' ');
                        const detail = repeatsLabel ? explanation.slice(reason.label.length).trimStart() : explanation;
                        const separator = repeatsLabel ? ' ' : ': ';
                        return '<div class="weather-change-reason"><span><strong>' + reason.label + '</strong>' + separator + detail + '</span></div>';
                    }).join('')
                    : '<div class="weather-change-reason">No pressure, rain, wind, cloud or weather-code signal crossed the current trend thresholds.</div>';
            }
            if (conditionsElement) conditionsElement.innerHTML = 'Trend: <b class="conditions-status ' + trend.label.toLowerCase() + '">' + trend.arrow + ' ' + trend.label + '</b>';
        }
    } catch {
        updateAtmosphericWvi(null, [], Date.now());
        scoreElement.textContent = '—';
        summaryElement.textContent = 'Weather Volatility Index (WVI) indicator unavailable.';
        const topStabilityLabel = document.getElementById('temperatureStabilityLabel');
        if (topStabilityLabel) {
            const stabilitySummaryElement = document.getElementById('temperatureStabilitySummary');
            if (stabilitySummaryElement) {
                stabilitySummaryElement.textContent = 'Unavailable';
                stabilitySummaryElement.dataset.level = 'low';
                stabilitySummaryElement.dataset.outlineLevel = 'normal';
                stabilitySummaryElement.classList.remove('warning');
            }
        }
        updateWeatherChangeLevel(null, 'unavailable');
        reasonsElement.replaceChildren(summaryElement);
        reasonsElement.insertAdjacentHTML('beforeend', '<div class="weather-change-reason">Recent readings could not be loaded. The meters have not been calculated.</div>');
        const trendSummary = document.getElementById('conditionsTrendSummary');
        const trendReasons = document.getElementById('conditionsTrendReasons');
        if (trendSummary) trendSummary.textContent = 'Conditions trend unavailable.';
        if (trendReasons) trendReasons.innerHTML = '';
        const conditionsElement = document.getElementById('weatherConditions');
        if (conditionsElement) conditionsElement.innerHTML = 'Trend: <b class="conditions-status unavailable">→ Unavailable</b>';
    }
}


async function selectDashboardLocation(locationId, location, reloadAfterSync = false) {
    const selector = document.getElementById('weatherLocationSelect');
    const name = document.getElementById('dashboardLocationName');
    if (locationId === 'server-current') {
        location = {
            name: selector?.dataset.serverLocationName || selector?.dataset.configLocationName || 'Configured GaugeIQ location',
            latitude: Number(selector?.dataset.serverLatitude),
            longitude: Number(selector?.dataset.serverLongitude),
            timezone: selector?.dataset.serverTimezone || selector?.dataset.configTimezone || 'auto'
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
        // Weather observations are captured by the scheduled server cron only.
        // Reload to display the latest stored reading; never fetch live weather here.
        window.location.reload();
        return;
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
        const requestedHours = Math.min(720, Math.max(1, Number(hours) || 24));
        const response = await fetch('../api/history.php?hours=' + encodeURIComponent(requestedHours), { cache: 'no-store' });
        if (!response.ok) throw new Error('History unavailable.');
        const data = await response.json();
        const readings = Array.isArray(data.readings) ? data.readings : [];
        const chartTimezone = document.querySelector('.history-card')?.dataset.historyTimezone || null;

        const charts = [
            ['pressureChart', readings.map(r => ({ value: r.pressure_hpa == null ? NaN : Number(r.pressure_hpa), time: r.timestamp || r.observed_at })), ' hPa', 1],
            ['humidityChart', readings.map(r => ({ value: r.humidity_percent == null ? NaN : Number(r.humidity_percent), time: r.timestamp || r.observed_at })), '%', 0],
            ['windChart', readings.map(r => ({ value: r.wind_speed_kmh == null ? NaN : Number(r.wind_speed_kmh), time: r.timestamp || r.observed_at })), ' km/h', 1]
        ];

        charts.forEach(([id, values, unit, decimals]) => {
            const canvas = document.getElementById(id);
            if (canvas) drawChart(canvas, values, unit, decimals, hours, chartTimezone);
        });

        const rangeLabel = hours >= 720 ? '30 days' : hours === 168 ? '7 days' : hours + ' hours';
        historyStatus.textContent = readings.length
            ? rangeLabel.charAt(0).toUpperCase() + rangeLabel.slice(1) + ' · ' + readings.length + ' server readings'
            : 'No server readings have been recorded for this time range yet.';

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
        loadHistory(Math.min(720, Number(button.dataset.hours) || 24));
    });
});

const weatherChangeReasonsToggle = document.getElementById('weatherChangeReasonsToggle');
const weatherChangeReasonsPanel = document.getElementById('weatherChangeReasons');
if (weatherChangeReasonsToggle && weatherChangeReasonsPanel) {
    weatherChangeReasonsToggle.addEventListener('click', () => {
        const willShow = weatherChangeReasonsPanel.hidden;
        weatherChangeReasonsPanel.hidden = !willShow;
        weatherChangeReasonsToggle.setAttribute('aria-expanded', String(willShow));
        weatherChangeReasonsToggle.setAttribute('aria-label', willShow ? 'Hide Weather Volatility Index reasons' : 'Show Weather Volatility Index reasons');
        weatherChangeReasonsToggle.title = willShow ? 'Hide information' : 'More information';
        const label = document.getElementById('weatherChangeReasonsButtonLabel');
        if (label) label.textContent = willShow ? 'Less information' : 'More information';
    });
}

const conditionsTrendReasonsToggle = document.getElementById('conditionsTrendReasonsToggle');
const conditionsTrendReasonsPanel = document.getElementById('conditionsTrendReasons');
if (conditionsTrendReasonsToggle && conditionsTrendReasonsPanel) {
    conditionsTrendReasonsToggle.addEventListener('click', () => {
        const willShow = conditionsTrendReasonsPanel.hidden;
        conditionsTrendReasonsPanel.hidden = !willShow;
        conditionsTrendReasonsToggle.setAttribute('aria-expanded', String(willShow));
        conditionsTrendReasonsToggle.setAttribute('aria-label', willShow ? 'Hide conditions trend reasons' : 'Show conditions trend reasons');
        conditionsTrendReasonsToggle.title = willShow ? 'Hide information' : 'More information';
        const label = document.getElementById('conditionsTrendReasonsButtonLabel');
        if (label) label.textContent = willShow ? 'Less information' : 'More information';
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


// Returning to the app does not trigger a weather refresh. The scheduled
// server monitor remains the sole source of new observations.


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


// Keep the existing header control as a simple button to the original page.
// Navigation is bound externally because the site's CSP blocks inline handlers.
(() => {
    const button = document.getElementById('gaugeiqMenuButton');
    if (!button) return;
    button.addEventListener('click', () => {
        window.location.href = 'login.php';
    });
})();


// Keep animated weather SVGs running when Safari pauses off-screen images or
// suspends the PWA in the background. A fragment gives each image a fresh SVG
// document instance without changing the requested file or its HTTP cache key.
(() => {
    const selector = 'img[src*="weather-icons/"]';
    const images = [...document.querySelectorAll(selector)];
    if (!images.length) return;

    function sourceFor(image) {
        if (!image.dataset.weatherIconSource) {
            // Keep the original URL so each resume can force a fresh SVG document.
            image.dataset.weatherIconSource = (image.getAttribute('src') || '').split('#')[0].replace(/([?&])_gqanim=\d+/, '');
        }
        return image.dataset.weatherIconSource;
    }

    function restartAnimation(image) {
        const source = sourceFor(image);
        if (!source || image.hidden) return;
        // Safari can leave an off-screen SVG's SMIL timeline suspended. A unique
        // query value forces a new image document when it returns to the viewport.
        const separator = source.includes('?') ? '&' : '?';
        image.setAttribute('src', source + separator + '_gqanim=' + Date.now());
    }

    function isInViewport(image) {
        const rect = image.getBoundingClientRect();
        return rect.bottom > 0 && rect.right > 0
            && rect.top < window.innerHeight && rect.left < window.innerWidth;
    }

    // Preserve the emoji fallback on the main temperature card.
    images.forEach(image => {
        const fallback = image.parentElement?.querySelector('.temperature-forecast-fallback');
        if (fallback) {
            const showFallback = () => {
                image.hidden = true;
                fallback.hidden = false;
            };
            image.addEventListener('error', showFallback, { once: true });
            if (image.complete && image.naturalWidth === 0) showFallback();
        }
    });

    if ('IntersectionObserver' in window) {
        const visibility = new WeakMap();
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                const wasVisible = visibility.get(entry.target);
                const visible = entry.isIntersecting;
                visibility.set(entry.target, visible);
                if (visible && wasVisible === false) restartAnimation(entry.target);
            });
        }, { threshold: 0.01 });
        images.forEach(image => observer.observe(image));
    }

    function resumeVisibleAnimations() {
        images.forEach(image => {
            if (isInViewport(image)) restartAnimation(image);
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') resumeVisibleAnimations();
    });
    window.addEventListener('pageshow', resumeVisibleAnimations);
})();


// Measure device-to-server upload, server-to-device download, and round-trip latency.
// Tests are transient and use same-origin endpoints; no results are stored.
function formatNetworkSpeed(bitsPerSecond) {
    const units = [[1_000_000_000, 'Gb/s'], [1_000_000, 'Mb/s'], [1_000, 'kb/s'], [1, 'b/s']];
    const [divisor, unit] = units.find(([threshold]) => bitsPerSecond >= threshold) || units[3];
    const speed = bitsPerSecond / divisor;
    return speed.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: speed >= 100 ? 0 : speed >= 10 ? 1 : 2
    }) + ' ' + unit;
}

async function updateNetworkDiagnostics() {
    const uploadOutput = document.getElementById('networkUplinkStatus');
    const downloadOutput = document.getElementById('networkDownlinkStatus');
    const pingOutput = document.getElementById('networkPingStatus');
    if (!uploadOutput && !downloadOutput && !pingOutput) return;

    const testRequest = async (url, options = {}, consumeResponse = response => response) => {
        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin', ...options, signal: controller.signal });
            // Keep the timeout active while the response body is being read too.
            return await consumeResponse(response);
        } finally {
            window.clearTimeout(timeoutId);
        }
    };

    if (uploadOutput) {
        try {
            const payload = new Uint8Array(32 * 1024);
            const startedAt = performance.now();
            const response = await testRequest('network-speed-test.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/octet-stream' },
                body: payload
            });
            if (!response.ok) throw new Error('Upload test failed');
            const elapsed = (performance.now() - startedAt) / 1000;
            if (elapsed <= 0) throw new Error('Invalid timing');
            uploadOutput.textContent = 'Upload speed: ~' + formatNetworkSpeed(payload.byteLength * 8 / elapsed);
            uploadOutput.dataset.state = 'measured';
        } catch {
            uploadOutput.textContent = 'Upload speed: unavailable';
            uploadOutput.dataset.state = 'error';
        }
    }

    if (downloadOutput) {
        try {
            const startedAt = performance.now();
            const data = await testRequest('network-download-test.php', {}, async response => {
                if (!response.ok) throw new Error('Download test failed');
                return await response.arrayBuffer();
            });
            const elapsed = (performance.now() - startedAt) / 1000;
            if (elapsed <= 0 || data.byteLength === 0) throw new Error('Invalid download timing');
            downloadOutput.textContent = 'Download speed: ~' + formatNetworkSpeed(data.byteLength * 8 / elapsed);
            downloadOutput.dataset.state = 'measured';
        } catch {
            downloadOutput.textContent = 'Download speed: unavailable';
            downloadOutput.dataset.state = 'error';
        }
    }

    if (pingOutput) {
        try {
            const startedAt = performance.now();
            const response = await testRequest('network-ping-test.php');
            if (!response.ok) throw new Error('Ping test failed');
            const milliseconds = performance.now() - startedAt;
            pingOutput.textContent = 'Ping to weather server: ' + milliseconds.toFixed(0) + ' ms';
            pingOutput.dataset.state = 'measured';
        } catch {
            pingOutput.textContent = 'Ping to weather server: unavailable';
            pingOutput.dataset.state = 'error';
        }
    }
}

updateNetworkDiagnostics();
