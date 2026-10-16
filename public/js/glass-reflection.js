/* GaugeIQ: interactive specular reflection on the Temperature glass card. */
(() => {
    const card = document.querySelector('.temperature-summary-card');
    if (!card || card.querySelector('.temperature-glass-shine')) return;

    const shine = document.createElement('span');
    shine.className = 'temperature-glass-shine';
    shine.setAttribute('aria-hidden', 'true');
    card.insertBefore(shine, card.firstChild);

    let orientationEnabled = false;
    let permissionRequested = false;

    const setPosition = (x, y, active = true) => {
        const clamp = value => Math.max(4, Math.min(96, value));
        card.style.setProperty('--glass-x', clamp(x) + '%');
        card.style.setProperty('--glass-y', clamp(y) + '%');
        card.classList.toggle('glass-motion-active', active);
    };

    // Desktop: the reflection tracks the pointer across the glass.
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
        // Convert tilt into a gentle, bounded reflection shift; avoid abrupt jumps.
        const x = 50 + Math.max(-35, Math.min(35, event.gamma)) * 1.15;
        const y = 48 + Math.max(-35, Math.min(35, event.beta - 35)) * .9;
        setPosition(x, y);
    };

    const enableOrientation = () => {
        if (orientationEnabled) return;
        orientationEnabled = true;
        window.addEventListener('deviceorientation', handleOrientation, true);
        card.classList.add('glass-motion-active');
    };

    // iOS requires permission to be requested during a user gesture. A tap on
    // the card surface enables tilt; ordinary dashboard controls are excluded.
    card.addEventListener('pointerdown', () => {
        if (permissionRequested || !('DeviceOrientationEvent' in window)) return;
        const orientation = window.DeviceOrientationEvent;
        if (typeof orientation.requestPermission !== 'function') {
            enableOrientation();
            return;
        }
        permissionRequested = true;
        orientation.requestPermission().then(permission => {
            if (permission === 'granted') enableOrientation();
        }).catch(() => {
            // Keep pointer-driven/static glass if motion permission is declined.
        });
    }, { passive: true });
})();
