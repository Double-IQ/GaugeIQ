(() => {
    'use strict';

    document.querySelectorAll('[id^="historicalDatasetStatus"]').forEach(panel => {
    {

    const csrf = panel.dataset.csrf || '';
    const start = panel.dataset.start || '';
    const end = panel.dataset.end || '';
    const weatherStatus = panel.querySelector('[id^="historicalWeatherStatus"]');
    const message = panel.querySelector('[id^="historicalDatasetMessage"]');
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
            // Keep each request small so the rolling 365-day archive
            // import can commit progressively instead of waiting on one large request.
            chunkEnd.setUTCDate(chunkEnd.getUTCDate() + 6);
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
                body: JSON.stringify({ csrf, source, start_date: from, end_date: to, location_id: Number(panel.dataset.locationId || 1) })
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

        const expectedHours = Math.max(1, Math.round((new Date(to + 'T00:00:00Z').getTime() - new Date(from + 'T00:00:00Z').getTime()) / 3600000) + 24);
        if (covered >= expectedHours) {
            setStatus(statusElement, 'Available — ' + covered.toLocaleString() + ' of ' + expectedHours.toLocaleString() + ' expected hourly records. ' + inserted.toLocaleString() + ' added; ' + skipped.toLocaleString() + ' already present or unavailable.');
        } else {
            setStatus(statusElement, 'Partial — ' + covered.toLocaleString() + ' of ' + expectedHours.toLocaleString() + ' expected hourly records. ' + inserted.toLocaleString() + ' added; ' + skipped.toLocaleString() + ' already present or unavailable.');
        }
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
        const weatherLatest = panel.dataset.weatherLatest || '';
        const repairStart = recentRepairStart();
        let completed = [];

        try {
            const expectedHours = Math.max(1, Math.round((new Date(end + 'T00:00:00Z').getTime() - new Date(start + 'T00:00:00Z').getTime()) / 3600000) + 24);
            // Do not label a partial year as complete. Existing timestamps are
            // skipped by the importer, so retrying the full window safely fills gaps.
            if (weatherCount < expectedHours) {
                const count = await importSource('weather', 'Historical Weather', weatherStatus);
                completed.push('weather (' + count.toLocaleString() + ' records)');
            } else if (coverageIsStale(weatherLatest)) {
                const count = await importSource('weather', 'Recent historical weather backfill', weatherStatus, repairStart, end);
                completed.push('recent weather backfill (' + count.toLocaleString() + ' records)');
            } else {
                setStatus(weatherStatus, 'Available — ' + weatherCount.toLocaleString() + ' hourly records found through ' + String(weatherLatest).slice(0, 10) + '.');
            }


            if (completed.length) {
                setStatus(message, 'Automatic historical download/backfill finished for the configured location. ' + completed.join(' and ') + '.');
            } else {
                setStatus(message, 'Historical weather data is present through the latest required date. No duplicate import was needed.');
            }
        } catch (error) {
            setStatus(message, (error instanceof Error ? error.message : 'Historical download failed.') + ' Existing records are preserved and duplicate timestamps are skipped.');
            if (message) message.dataset.state = 'error';
        }
    }

    run();
    }
    });
})();
