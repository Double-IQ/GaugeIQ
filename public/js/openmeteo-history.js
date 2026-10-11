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

    async function importSource(source, label, statusElement, from = start, to = end) {
        const chunks = chunksBetween(from, to);
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

    function recentRepairStart() {
        const endTime = new Date(end + 'T00:00:00Z').getTime();
        const requestedStart = endTime - 6 * 24 * 60 * 60 * 1000;
        const configuredStart = new Date(start + 'T00:00:00Z').getTime();
        return dateString(new Date(Math.max(requestedStart, configuredStart)));
    }

    function coverageIsStale(latestValue) {
        const latestDate = String(latestValue || '').slice(0, 10);
        return latestDate === '' || latestDate < end;
    }

    async function run() {
        const weatherCount = Number(panel.dataset.weatherCount) || 0;
        const forecastCount = Number(panel.dataset.forecastCount) || 0;
        const weatherLatest = panel.dataset.weatherLatest || '';
        const forecastLatest = panel.dataset.forecastLatest || '';
        const repairStart = recentRepairStart();
        let completed = [];

        try {
            if (weatherCount < 5000) {
                const count = await importSource('weather', 'Historical Weather', weatherStatus);
                completed.push('weather (' + count.toLocaleString() + ' records)');
            } else if (coverageIsStale(weatherLatest)) {
                const count = await importSource('weather', 'Recent historical weather backfill', weatherStatus, repairStart, end);
                completed.push('recent weather backfill (' + count.toLocaleString() + ' records)');
            } else {
                setStatus(weatherStatus, 'Available — ' + weatherCount.toLocaleString() + ' hourly records found through ' + String(weatherLatest).slice(0, 10) + '.');
            }

            if (forecastCount < 5000) {
                const count = await importSource('forecast', 'Historical Forecast', forecastStatus);
                completed.push('forecast (' + count.toLocaleString() + ' records)');
            } else if (coverageIsStale(forecastLatest)) {
                const count = await importSource('forecast', 'Recent historical forecast backfill', forecastStatus, repairStart, end);
                completed.push('recent forecast backfill (' + count.toLocaleString() + ' records)');
            } else {
                setStatus(forecastStatus, 'Available — ' + forecastCount.toLocaleString() + ' hourly forecasts found through ' + String(forecastLatest).slice(0, 10) + '.');
            }

            if (completed.length) {
                setStatus(message, 'Automatic historical download/backfill finished for the configured location. ' + completed.join(' and ') + '.');
            } else {
                setStatus(message, 'Both historical datasets are present through the latest required date. No duplicate import was needed.');
            }
        } catch (error) {
            setStatus(message, (error instanceof Error ? error.message : 'Historical download failed.') + ' Existing records are preserved and duplicate timestamps are skipped.');
            if (message) message.dataset.state = 'error';
        }
    }

    run();
})();
