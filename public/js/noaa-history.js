(() => {
    'use strict';

    const button = document.getElementById('noaaHistoryImport');
    const status = document.getElementById('noaaHistoryStatus');
    const startInput = document.getElementById('noaaHistoryStart');
    const endInput = document.getElementById('noaaHistoryEnd');
    const csrfInput = document.getElementById('noaaHistoryCsrf');

    function statusText(message, error = false) {
        if (!status) return;
        status.textContent = message;
        status.dataset.state = error ? 'error' : 'ok';
    }

    function plusYears(dateString, years) {
        const date = new Date(dateString + 'T12:00:00Z');
        date.setUTCFullYear(date.getUTCFullYear() + years);
        date.setUTCDate(date.getUTCDate() - 1);
        return date.toISOString().slice(0, 10);
    }

    async function importBatch(startDate, endDate) {
        const body = new URLSearchParams({
            csrf: csrfInput?.value || '',
            start_date: startDate,
            end_date: endDate
        });
        const response = await fetch('api/noaa-history-import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
            cache: 'no-store',
            credentials: 'same-origin'
        });
        let data;
        try { data = await response.json(); }
        catch { throw new Error('The server returned an unreadable response. Check the PHP error log.'); }
        if (!response.ok || !data.ok) throw new Error(data.error || 'NOAA import failed.');
        return data;
    }

    button?.addEventListener('click', async () => {
        const start = startInput?.value || '';
        const end = endInput?.value || '';
        if (!start || !end || start > end) {
            statusText('Choose a valid start date and end date.', true);
            return;
        }
        if (start < '1973-01-01') {
            statusText('NOAA Global Summary of the Day data is available from 1973 onward.', true);
            return;
        }
        button.disabled = true;
        if (startInput) startInput.disabled = true;
        if (endInput) endInput.disabled = true;
        let cursor = start;
        let totalInserted = 0;
        let totalUpdated = 0;
        let totalReadings = 0;
        let stationLabel = '';
        let distance = null;
        let batches = 0;
        try {
            while (cursor <= end) {
                const chunkEnd = plusYears(cursor, 5) < end ? plusYears(cursor, 5) : end;
                batches += 1;
                statusText('Downloading and importing NOAA history (' + batches + '): ' + cursor + ' to ' + chunkEnd + '…');
                const result = await importBatch(cursor, chunkEnd);
                totalInserted += Number(result.inserted || 0);
                totalUpdated += Number(result.updated || 0);
                totalReadings += Number(result.readings || 0);
                stationLabel = result.station + (result.country ? ', ' + result.country : '');
                distance = result.distance_km;
                const next = new Date(chunkEnd + 'T12:00:00Z');
                next.setUTCDate(next.getUTCDate() + 1);
                cursor = next.toISOString().slice(0, 10);
            }
            statusText('NOAA import complete. Station: ' + stationLabel + (distance !== null ? ' (' + distance + ' km away)' : '') +
                '. ' + totalReadings.toLocaleString() + ' observations processed; ' +
                totalInserted.toLocaleString() + ' added and ' + totalUpdated.toLocaleString() +
                ' matching timestamps updated. Live monitoring continues using the same server database. ' +
                'NOAA history is daily, not hourly.');
            if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory('all');
        } catch (error) {
            statusText((error instanceof Error ? error.message : 'Unable to import NOAA history.') +
                (totalReadings ? ' Earlier completed batches were retained; you can retry the same range safely.' : ''), true);
        } finally {
            button.disabled = false;
            if (startInput) startInput.disabled = false;
            if (endInput) endInput.disabled = false;
        }
    });
})();