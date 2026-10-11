document.addEventListener('DOMContentLoaded', async () => {
    const form = document.querySelector('form[action="admin-update.php"]');
    if (form) {
        form.addEventListener('submit', event => {
            if (!window.confirm('Install this verified GaugeIQ release now?')) {
                event.preventDefault();
            }
        });
    }

    const status = document.getElementById('adminUpdateStatus');
    const details = document.getElementById('adminUpdateDetails');
    if (!status || !details) return;

    try {
        const response = await fetch('api/update-check.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw new Error();

        if (!data.ok) throw new Error(data.error || 'Update check failed.');

        if (!data.update_available) {
            status.textContent = 'GaugeIQ is up to date (v' + data.current_version + ').';
            return;
        }

        if (!data.package_ready) {
            status.textContent = 'Version ' + data.latest_version + ' was found, but GitHub is still preparing its release package. GaugeIQ will check again later.';
            return;
        }

        document.getElementById('adminLatestVersion').textContent = 'Version ' + data.latest_version + ' is available.';
        document.getElementById('adminUpdateVersion').value = data.latest_version;
        details.hidden = false;
        status.textContent = '';
    } catch {
        status.textContent = 'Unable to check for GaugeIQ updates.';
    }
});
