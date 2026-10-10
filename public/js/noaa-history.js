(() => {
    'use strict';
    const endpoint = 'api/noaa-history-import.php';
    const csrf = document.getElementById('stationHistoryCsrf')?.value || '';
    const stationSelect = document.getElementById('stationHistoryStation');
    const stationStatus = document.getElementById('stationHistoryStatus');
    const startInput = document.getElementById('stationHistoryStart');
    const endInput = document.getElementById('stationHistoryEnd');
    const previewButton = document.getElementById('stationHistoryPreview');
    const importButton = document.getElementById('stationHistoryImport');
    const previewPanel = document.getElementById('stationHistoryPreviewResult');
    let selectedStation = null;
    let previewedRange = '';

    function say(message, error = false) {
        if (!stationStatus) return;
        stationStatus.textContent = message;
        stationStatus.dataset.state = error ? 'error' : 'ok';
    }
    function rangeKey() { return (stationSelect?.value || '') + '|' + (startInput?.value || '') + '|' + (endInput?.value || ''); }
    async function post(payload) {
        const body = new URLSearchParams({ csrf, ...payload });
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body, cache: 'no-store', credentials: 'same-origin'
        });
        let data;
        try { data = await response.json(); }
        catch { throw new Error('The server returned an unreadable response. Check the PHP error log.'); }
        if (!response.ok || !data.ok) throw new Error(data.error || 'NOAA station request failed.');
        return data;
    }
    function invalidatePreview() {
        previewedRange = '';
        selectedStation = null;
        if (importButton) importButton.disabled = true;
        if (previewPanel) { previewPanel.hidden = true; previewPanel.textContent = ''; }
    }
    async function loadStations() {
        if (stationSelect) stationSelect.disabled = true;
        say('Finding NOAA observation stations near the configured location…');
        try {
            const data = await post({ action: 'stations' });
            stationSelect.innerHTML = '';
            for (const station of data.stations || []) {
                const option = document.createElement('option');
                option.value = station.id;
                option.textContent = station.name + ' · ' + station.distance_km + ' km · ' + station.id;
                option.dataset.station = JSON.stringify(station);
                stationSelect.appendChild(option);
            }
            if (!stationSelect.options.length) throw new Error('No nearby stations were returned.');
            stationSelect.disabled = false;
            say('Found ' + stationSelect.options.length + ' nearby stations. Choose one, then preview its actual reporting coverage before importing.');
        } catch (error) {
            say(error instanceof Error ? error.message : 'Unable to find NOAA stations.', true);
        }
    }
    function renderPreview(data) {
        if (!previewPanel) return;
        const station = data.station;
        const counts = data.variable_observations || {};
        const lines = [
            'Station: ' + station.name + ' (' + station.id + ')',
            'Distance: ' + station.distance_km + ' km from your configured location',
            'Period checked: ' + data.start_date + ' to ' + data.end_date,
            'Usable records with valid station pressure: ' + Number(data.records_with_valid_station_pressure || 0).toLocaleString(),
            '',
            'Unflagged observation values found in this period:'
        ];
        const labels = {
            temperature: 'Temperature', dew_point_temperature: 'Dew point',
            station_level_pressure: 'Station-level pressure', relative_humidity: 'Relative humidity',
            wind_speed: 'Wind speed', wind_direction: 'Wind direction',
            precipitation: 'Precipitation'
        };
        for (const [key, label] of Object.entries(labels)) lines.push(label + ': ' + Number(counts[key] || 0).toLocaleString());
        lines.push('', 'Only station-level pressure is used for GaugeIQ’s pressure chart. Sea-level pressure is not substituted. Missing values are left missing; flagged values are excluded.');
        previewPanel.textContent = lines.join('\n');
        previewPanel.hidden = false;
    }
    async function preview() {
        const start = startInput?.value || '', end = endInput?.value || '';
        if (!start || !end || start > end) { say('Choose a valid start date and end date.', true); return; }
        if ((new Date(end + 'T00:00:00Z') - new Date(start + 'T00:00:00Z')) / 86400000 > 366) {
            say('Preview no more than one year at a time.', true); return;
        }
        invalidatePreview();
        previewButton.disabled = true;
        if (importButton) importButton.disabled = true;
        say('Checking the selected station’s actual observations and quality flags…');
        try {
            const data = await post({ action: 'preview', station_id: stationSelect.value, start_date: start, end_date: end });
            selectedStation = data.station;
            previewedRange = rangeKey();
            renderPreview(data);
            if (Number(data.records_with_valid_station_pressure || 0) > 0) {
                if (importButton) importButton.disabled = false;
                say('Preview complete. Review the station and coverage above, then import if it looks suitable.');
            } else say('This station has no usable station-pressure observations for the selected range. Try another station or date range.', true);
        } catch (error) { say(error instanceof Error ? error.message : 'Unable to preview station data.', true); }
        finally { previewButton.disabled = false; }
    }
    async function importData() {
        if (!selectedStation || previewedRange !== rangeKey()) {
            say('Preview this station and date range again before importing.', true); return;
        }
        if (!window.confirm('Import actual NOAA station observations from ' + selectedStation.name + ' into GaugeIQ’s server database? Existing pressure readings will not be overwritten.')) return;
        importButton.disabled = true; previewButton.disabled = true;
        say('Downloading and importing actual hourly/synoptic station observations…');
        try {
            const data = await post({
                action: 'import', station_id: selectedStation.id,
                start_date: startInput.value, end_date: endInput.value
            });
            say('Import complete from ' + data.station.name + ' (' + data.station.id + ', ' + data.station.distance_km +
                ' km away): ' + Number(data.readings || 0).toLocaleString() + ' valid station observations processed; ' +
                Number(data.inserted || 0).toLocaleString() + ' added and ' + Number(data.updated || 0).toLocaleString() +
                ' existing timestamps supplemented. Live Open-Meteo monitoring continues unchanged.');
            if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory('all');
        } catch (error) {
            say(error instanceof Error ? error.message : 'Unable to import station observations.', true);
            importButton.disabled = false;
        } finally { previewButton.disabled = false; }
    }
    stationSelect?.addEventListener('change', invalidatePreview);
    startInput?.addEventListener('change', invalidatePreview);
    endInput?.addEventListener('change', invalidatePreview);
    previewButton?.addEventListener('click', preview);
    importButton?.addEventListener('click', importData);
    if (stationSelect) loadStations();
})();
