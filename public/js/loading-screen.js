(() => {
    const screen = document.getElementById('gaugeiqLoadingScreen');
    if (!screen) return;

    const minimumVisibleMs = 2000;
    const startedAt = Date.now();
    let dismissed = false;

    function dismiss() {
        if (dismissed) return;
        const remaining = minimumVisibleMs - (Date.now() - startedAt);
        if (remaining > 0) {
            window.setTimeout(dismiss, remaining);
            return;
        }
        dismissed = true;
        screen.classList.add('is-leaving');
        screen.setAttribute('aria-hidden', 'true');
        window.setTimeout(() => {
            screen.hidden = true;
            screen.style.display = 'none';
        }, 200);
    }

    // Restore server-rendered meter widths without CSP-blocked style attributes.
    document.querySelectorAll('[data-meter-width]').forEach((meter) => {
        const width = Number(meter.dataset.meterWidth);
        if (Number.isFinite(width)) meter.style.width = Math.max(0, Math.min(100, width)) + '%';
        if (meter.dataset.meterMinWidth === '2px' && width > 0) meter.style.minWidth = '2px';
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dismiss, { once: true });
    } else {
        window.setTimeout(dismiss, 0);
    }

    // Absolute fallback in case the DOM-ready callback is delayed by mobile Safari.
    window.setTimeout(dismiss, 4500);
})();
