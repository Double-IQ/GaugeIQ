(() => {
    'use strict';

    // Weather observations and historical imports now live exclusively in the server database.
    // Keep a single dashboard location option; there is no browser-side weather database.
    function setupServerLocation() {
        const select = document.getElementById('weatherLocationSelect');
        if (!select) return;
        const option = document.createElement('option');
        option.value = 'server-current';
        option.textContent = select.dataset.serverLocationName || select.dataset.configLocationName || 'Configured GaugeIQ location';
        select.replaceChildren(option);
        select.value = 'server-current';
        window.GaugeIQSelectDashboardLocation?.('server-current', null);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupServerLocation, { once: true });
    } else {
        setupServerLocation();
    }
})();
