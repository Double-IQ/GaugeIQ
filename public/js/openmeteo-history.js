(() => {
    'use strict';

    const form = document.getElementById('openMeteoHistoryForm');
    if (!form) return;
    const status = document.getElementById('openMeteoHistoryStatus');
    const submit = document.getElementById('openMeteoHistoryImport');
    const csrf = document.getElementById('openMeteoHistoryCsrf')?.value || '';
    const startInput = document.getElementById('openMeteoHistoryStart');
    const endInput = document.getElementById('openMeteoHistoryEnd');
    const sourceInput = document.getElementById('openMeteoHistorySource');

    const dateText = date => date.toISOString().slice(0, 10);
    const dayAfter = value => {
        const date = new Date(value + 'T00:00:00Z');
        date.setUTCDate(date.getUTCDate() + 1);
        return date;
    };
    const yesterday = new Date();
    yesterday.setUTCHours(0, 0, 0, 0);
    yesterday.setUTCDate(yesterday.getUTCDate() - 1);
    const endDefault = dateText(yesterday);
    const startDefault = dateText(new Date(Date.UTC(yesterday.getUTCFullYear() - 1, yesterday.getUTCMonth(), yesterday.getUTCDate())));
    if (endInput && !endInput.value) endInput.value = endDefault;
    if (startInput && !startInput.value) startInput.value = startDefault;
    if (endInput) endInput.max = endDefault;

    function setStatus(message, error = false) {
        if (!status) return;
        status.textContent = message;
        status.dataset.state = error ? 'error' : 'ok';
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const startText = startInput?.value || '';
        const endText = endInput?.value || '';
        const source = sourceInput?.value === 'forecast' ? 'forecast' : 'weather';
        if (!startText || !endText || startText > endText || endText > endDefault) {
            setStatus('Choose a valid date range ending no later than yesterday.', true);
            return;
        }
        if (source === 'forecast' && startText < '2022-01-01') {
            setStatus('Historical Forecast starts in 2022. Choose Historical Weather for earlier dates.', true);
            return;
        }

        submit.disabled = true;
        let inserted = 0;
        let skipped = 0;
        try {
            let cursor = new Date(startText + 'T00:00:00Z');
            const finalDate = new Date(endText + 'T00:00:00Z');
            const chunks = [];
            while (cursor <= finalDate) {
                const chunkEnd = new Date(cursor);
                chunkEnd.setUTCDate(chunkEnd.getUTCDate() + 366);
                if (chunkEnd > finalDate) chunkEnd.setTime(finalDate.getTime());
                chunks.push([dateText(cursor), dateText(chunkEnd)]);
                cursor = dayAfter(dateText(chunkEnd));
            }

            for (let index = 0; index < chunks.length; index++) {
                const [from, to] = chunks[index];
                setStatus('Importing server-side historical weather (' + (index + 1) + '/' + chunks.length + '): ' + from + ' to ' + to + '…');
                const response = await fetch('api/openmeteo-history-import.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ csrf, source, start_date: from, end_date: to })
                });
                let result;
                try { result = await response.json(); }
                catch { throw new Error('The server returned an unreadable response for ' + from + '.'); }
                if (!response.ok || !result.success) throw new Error(result.error || 'Historical import failed for ' + from + '.');
                inserted += Number(result.inserted) || 0;
                skipped += Number(result.skipped) || 0;
            }
            setStatus('Import complete for the configured location. ' + inserted.toLocaleString() +
                ' readings added; ' + skipped.toLocaleString() + ' timestamps skipped because they were already present or had no valid surface pressure. The dashboard history now reads from the server database.');
        } catch (error) {
            setStatus((error instanceof Error ? error.message : 'Historical import failed.') +
                (inserted ? ' Partial progress: ' + inserted.toLocaleString() + ' readings were saved before the error; retrying is safe.' : ''), true);
        } finally {
            submit.disabled = false;
        }
    });
})();
