(() => {
    'use strict';

    const panel = document.getElementById('historicalDatasetStatus');
    if (!panel) return;

    const csrf = panel.dataset.csrf || '';
    const start = panel.dataset.start || '';
    const end = panel.dataset.end || '';
    const weatherStatus = document.getElementById('historicalWeatherStatus');
    const forecastStatus = document.getElementById('historicalForecastStatus');
    const message = document.getElementById('historicalDatasetMessage');
    const setStatus = (element, text) => { if (element) element.textContent = text; };

    const dateString = date => date.toISOString().slice(0, 10);
    const nextDay = value => {
        const date = new Date(value + 'T00:00:00Z');
        date.setUTCDate(date.getUTCDate() + 1);
        return date;
    };

    function chunksBetween(from, to) {
        const chunks = [];
        let cursor = new Date(from + 'T00:00:00Z');
        const last = new Date(to + 'T00:00:00Z');
        while (cursor <= last) {
            const chunkEnd = new Date(cursor);
            chunkEnd.setUTCDate(chunkEnd.getUTCDate() + 365);
            if (chunkEnd > last) chunkEnd.setTime(last.getTime());
            chunks.push([dateString(cursor), dateString(chunkEnd)]);
            cursor = nextDay(dateString(chunkEnd));
        }
        return chunks;
    }

    async function importSource(source, label, statusElement) {
        const chunks = chunksBetween(start, end);
        let inserted = 0;
        let skipped = 0;
        let covered = 0;

        for (let index = 0; index < chunks.length; index += 1) {
            const [from, to] = chunks[index];
            setStatus(statusElement, 'Downloading ' + label.toLowerCase() + ' (' + (index + 1) + '/' + chunks.length + '): ' + from + ' to ' + to + '…');
            const response = await fetch('api/openmeteo-history-import.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf, source, start_date: from, end_date: to })
            });
            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error(label + ' import returned an unreadable server response.');
            }
            if (!response.ok || !result.success) {
                throw new Error(result.error || label + ' import failed.');
            }
            inserted += Number(result.inserted) || 0;
            skipped += Number(result.skipped) || 0;
            covered += Number(result.covered_rows) || 0;
        }

        setStatus(statusElement, 'Available — ' + covered.toLocaleString() + ' hourly records. ' + inserted.toLocaleString() + ' added; ' + skipped.toLocaleString() + ' already present or unavailable.');
        return covered;
    }

    async function run() {
        const weatherCount = Number(panel.dataset.weatherCount) || 0;
        const forecastCount = Number(panel.dataset.forecastCount) || 0;
        let completed = [];

        try {
            if (weatherCount < 5000) {
                const count = await importSource('weather', 'Historical Weather', weatherStatus);
                completed.push('weather (' + count.toLocaleString() + ' records)');
            } else {
                setStatus(weatherStatus, 'Available — ' + weatherCount.toLocaleString() + ' hourly records found.');
            }

            if (forecastCount < 5000) {
                const count = await importSource('forecast', 'Historical Forecast', forecastStatus);
                completed.push('forecast (' + count.toLocaleString() + ' records)');
            } else {
                setStatus(forecastStatus, 'Available — ' + forecastCount.toLocaleString() + ' hourly forecasts found.');
            }

            if (completed.length) {
                setStatus(message, 'Automatic historical download finished for the configured location. ' + completed.join(' and ') + '.');
            } else {
                setStatus(message, 'Both historical datasets are already present for the configured location and date range. No duplicate import was needed.');
            }
        } catch (error) {
            setStatus(message, (error instanceof Error ? error.message : 'Historical download failed.') + ' Existing records are preserved and duplicate timestamps are skipped.');
            if (message) message.dataset.state = 'error';
        }
    }

    run();
})();
