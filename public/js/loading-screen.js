(() => {
    const screen = document.getElementById('gaugeiqLoadingScreen');
    const message = document.getElementById('gaugeiqLoadingMessage');
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
        window.setTimeout(() => { screen.hidden = true; }, 180);
    }

    // Restore server-rendered meter widths without CSP-blocked style attributes.
    document.querySelectorAll('[data-meter-width]').forEach((meter) => {
        const width = Number(meter.dataset.meterWidth);
        if (Number.isFinite(width)) meter.style.width = Math.max(0, Math.min(100, width)) + '%';
        if (meter.dataset.meterMinWidth === '2px' && width > 0) {
            meter.style.minWidth = '2px';
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.setTimeout(dismiss, 160), { once: true });
    } else {
        window.setTimeout(dismiss, 160);
    }
    window.setTimeout(dismiss, 4500);
})();
