(() => {
    'use strict';

    document.querySelectorAll('[id^="historicalDatasetStatus"]').forEach(panel => {
        const csrf = panel.dataset.csrf || '';
        const start = panel.dataset.start || '';
        const end = panel.dataset.end || '';
        const locationId = Number(panel.dataset.locationId || 1);
        const alreadyCompleted = panel.dataset.initialImportCompleted === '1';
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
                chunkEnd.setUTCDate(chunkEnd.getUTCDate() + 6);
                if (chunkEnd > last) chunkEnd.setTime(last.getTime());
                chunks.push([dateString(cursor), dateString(chunkEnd)]);
                cursor = nextDay(dateString(chunkEnd));
            }
            return chunks;
        }

        async function postImport(payload, errorLabel) {
            const response = await fetch('api/openmeteo-history-import.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf, location_id: locationId, ...payload })
            });
            let result;
            try {
                result = await response.json();
            } catch {
                throw new Error(errorLabel + ' returned an unreadable server response.');
            }
            if (!response.ok || !result.success) throw new Error(result.error || errorLabel + ' failed.');
            return result;
        }

        async function importInitialHistory() {
            const chunks = chunksBetween(start, end);
            let inserted = 0;
            let skipped = 0;
            let covered = 0;
            for (let index = 0; index < chunks.length; index += 1) {
                const [from, to] = chunks[index];
                setStatus(weatherStatus, 'Downloading one-month historical weather (' + (index + 1) + '/' + chunks.length + ')…');
                const result = await postImport({ source: 'weather', start_date: from, end_date: to }, 'Historical weather import');
                inserted += Number(result.inserted) || 0;
                skipped += Number(result.skipped) || 0;
                covered += Number(result.covered_rows) || 0;
            }
            return { inserted, skipped, covered };
        }

        async function markCompleted() {
            await postImport({ action: 'complete' }, 'Saving history import status');
            panel.dataset.initialImportCompleted = '1';
        }

        async function run() {
            const weatherCount = Number(panel.dataset.weatherCount) || 0;
            const weatherLatest = panel.dataset.weatherLatest || '';
            const expectedHours = Math.max(1, Math.round((new Date(end + 'T00:00:00Z').getTime() - new Date(start + 'T00:00:00Z').getTime()) / 3600000) + 24);
            if (alreadyCompleted) {
                setStatus(weatherStatus, 'Complete — one-month initial history already downloaded. No repeat download needed.');
                setStatus(message, 'This location’s initial historical import is complete. Reopening GaugeIQ will not download it again.');
                return;
            }

            try {
                if (weatherCount >= Math.floor(expectedHours * 0.9)) {
                    setStatus(weatherStatus, 'Existing one-month history found — recording completion without downloading it again.');
                } else {
                    const result = await importInitialHistory();
                    setStatus(weatherStatus, 'Initial history complete — ' + result.covered.toLocaleString() + ' records covered; ' + result.inserted.toLocaleString() + ' added and ' + result.skipped.toLocaleString() + ' already present or unavailable.');
                }
                await markCompleted();
                setStatus(message, 'The one-month initial historical download is complete and saved for this location. It will not run again when you reopen the app.');
            } catch (error) {
                setStatus(message, (error instanceof Error ? error.message : 'Historical download failed.') + ' Existing records are preserved. The import can safely resume on the next visit.');
                if (message) message.dataset.state = 'error';
            }
        }

        run();
    });
})();
