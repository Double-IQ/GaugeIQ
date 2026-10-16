/* GaugeIQ: persistent, device-responsive specular reflection on the Temperature card. */
(() => {
    const card = document.querySelector('.temperature-summary-card');
    if (!card || card.querySelector('.temperature-glass-shine')) return;

    const STORAGE_KEY = 'gaugeiq.glassMotionConsent.v1';
    const shine = document.createElement('span');
    shine.className = 'temperature-glass-shine';
    shine.setAttribute('aria-hidden', 'true');
    card.insertBefore(shine, card.firstChild);

    let orientationEnabled = false;
    let permissionCheckInProgress = false;
    let hasStoredConsent = false;

    try {
        hasStoredConsent = window.localStorage.getItem(STORAGE_KEY) === 'granted';
    } catch (_) {
        // Storage can be unavailable in private browsing; motion still works this session.
    }

    const storeConsent = () => {
        hasStoredConsent = true;
        try {
            window.localStorage.setItem(STORAGE_KEY, 'granted');
        } catch (_) {
            // Do not block the reflection if persistent storage is unavailable.
        }
    };

    const setPosition = (x, y, active = true) => {
        const clamp = value => Math.max(4, Math.min(96, value));
        card.style.setProperty('--glass-x', clamp(x) + '%');
        card.style.setProperty('--glass-y', clamp(y) + '%');
        card.classList.toggle('glass-motion-active', active);
    };

    // Desktop: track the mouse across the glass.
    card.addEventListener('pointermove', event => {
        if (event.pointerType !== 'mouse' || orientationEnabled) return;
        const bounds = card.getBoundingClientRect();
        if (!bounds.width || !bounds.height) return;
        setPosition(((event.clientX - bounds.left) / bounds.width) * 100,
                    ((event.clientY - bounds.top) / bounds.height) * 100);
    });
    card.addEventListener('pointerleave', event => {
        if (event.pointerType === 'mouse' && !orientationEnabled) {
            setPosition(22, 12, false);
        }
    });

    const handleOrientation = event => {
        if (event.gamma === null || event.beta === null) return;
        const x = 50 + Math.max(-35, Math.min(35, event.gamma)) * 1.15;
        const y = 48 + Math.max(-35, Math.min(35, event.beta - 35)) * .9;
        setPosition(x, y);
    };

    const enableOrientation = () => {
        if (!orientationEnabled) {
            orientationEnabled = true;
            window.addEventListener('deviceorientation', handleOrientation, true);
        }
        storeConsent();
        card.classList.add('glass-motion-active');
    };

    const startOrCheckMotion = () => {
        if (permissionCheckInProgress || orientationEnabled ||
            !('DeviceOrientationEvent' in window)) return;

        const orientation = window.DeviceOrientationEvent;
        if (typeof orientation.requestPermission !== 'function') {
            // Browsers without an explicit permission API can start automatically.
            enableOrientation();
            return;
        }

        // iOS requires a user gesture for this check. If the user already
        // granted access, this should return the saved browser permission
        // without another prompt; it also reattaches the listener for this page.
        permissionCheckInProgress = true;
        orientation.requestPermission().then(permission => {
            if (permission === 'granted') {
                enableOrientation();
            } else {
                card.classList.remove('glass-motion-active');
            }
        }).catch(() => {
            // Permission checks can fail outside a valid gesture; try on a later tap.
        }).finally(() => {
            permissionCheckInProgress = false;
        });
    };

    if (hasStoredConsent) {
        // Non-iOS browsers can restore motion immediately. iOS waits for the
        // first natural tap/click because its API requires transient user activation.
        if ('DeviceOrientationEvent' in window &&
            typeof window.DeviceOrientationEvent.requestPermission !== 'function') {
            startOrCheckMotion();
        }
        const resumeFromGesture = () => {
            if (hasStoredConsent && !orientationEnabled) startOrCheckMotion();
        };
        document.addEventListener('pointerdown', resumeFromGesture, { passive: true, capture: true });
        document.addEventListener('touchend', resumeFromGesture, { passive: true, capture: true, once: true });
    } else {
        // First-time opt-in: tapping the glass asks for motion access where required.
        card.addEventListener('pointerdown', startOrCheckMotion, { passive: true });
    }
})();
