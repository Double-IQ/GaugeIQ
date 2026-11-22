(() => {
    'use strict';
    // Card markup is delivered in the dashboard HTML. Its share is shown as
    // an uncompressed payload estimate; additional downloads use Resource Timing.
    const byteLength = value => window.TextEncoder
        ? new TextEncoder().encode(value).length
        : new Blob([value]).size;
    const formatBytes = bytes => !Number.isFinite(bytes) || bytes <= 0 ? '0 KB'
        : bytes >= 1024 * 1024 ? (bytes / (1024 * 1024)).toFixed(2) + ' MB'
        : (bytes / 1024).toFixed(1) + ' KB';
    const selectors = {
        weather: '.temperature-summary-card',
        radar: '.rainfall-radar-card',
        'weather-map': '#weatherMapView',
        'moon-tide': '#moonTideHud'
    };
    const outputIds = {
        weather: 'weatherDataUsage',
        radar: 'radarDataUsage',
        'weather-map': 'weatherMapDataUsage',
        'moon-tide': 'moonTideDataUsage'
    };
    const payload = {};
    Object.entries(selectors).forEach(([key, selector]) => {
        const card = document.querySelector(selector);
        if (!card) return;
        const clone = card.cloneNode(true);
        clone.querySelectorAll('.data-usage-footer').forEach(node => node.remove());
        payload[key] = byteLength(clone.outerHTML);
    });
    const network = {
        'weather-map': { downloaded: 0, cached: 0, seen: new Set() },
        'moon-tide': { downloaded: 0, cached: 0, seen: new Set() }
    };
    const classify = url => url.includes('/api/weather-map-tile.php') ? 'weather-map'
        : (url.includes('/api/tides.php') || url.includes('lroc_color_2k.jpg')) ? 'moon-tide' : null;
    const consume = entries => {
        entries.forEach(entry => {
            const key = classify(entry.name);
            if (!key) return;
            const identity = entry.name + '|' + entry.startTime;
            if (network[key].seen.has(identity)) return;
            network[key].seen.add(identity);
            const transfer = Number(entry.transferSize) || 0;
            const body = Number(entry.encodedBodySize) || 0;
            if (transfer > 0) network[key].downloaded += transfer;
            else if (body > 0) network[key].cached += body;
        });
        render();
    };
    function render() {
        Object.entries(outputIds).forEach(([key, id]) => {
            const node = document.getElementById(id);
            if (!node) return;
            const pageText = '~' + formatBytes(payload[key] || 0) + ' page data';
            if (key === 'weather-map' || key === 'moon-tide') {
                const stats = network[key];
                const label = key === 'weather-map' ? 'tiles downloaded' : 'downloaded';
                node.textContent = pageText + ' · ' + formatBytes(stats.downloaded) + ' ' + label
                    + (stats.cached ? ' · ' + formatBytes(stats.cached) + ' cached' : '');
            } else {
                node.textContent = pageText + ' · included in dashboard load';
            }
        });
    }
    if (window.performance && performance.getEntriesByType) {
        consume(performance.getEntriesByType('resource'));
        if ('PerformanceObserver' in window) {
            try {
                const observer = new PerformanceObserver(list => consume(list.getEntries()));
                observer.observe({ type: 'resource', buffered: true });
            } catch { /* Keep the initial Resource Timing snapshot. */ }
        }
    }
    render();
})();
