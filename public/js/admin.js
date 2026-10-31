(() => {
    const root = document.documentElement;
    const select = document.getElementById('themeSelect');
    const meta = document.querySelector('meta[name="theme-color"]');
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const readPreference = () => {
        try { return localStorage.getItem('gaugeiq-theme') || 'system'; }
        catch (_) { return 'system'; }
    };
    const savePreference = theme => {
        try { localStorage.setItem('gaugeiq-theme', theme); } catch (_) {}
    };
    const applyTheme = theme => {
        const dark = theme === 'dark' || (theme === 'system' && media.matches);
        root.dataset.theme = theme === 'system' ? (dark ? 'dark' : 'light') : theme;
        root.style.colorScheme = dark ? 'dark' : 'light';
        root.style.setProperty('--text', dark ? '#f3f4f6' : '#26313f');
        root.style.setProperty('--muted', dark ? '#9ca3af' : '#687586');
        root.style.setProperty('--background', dark ? '#0b1120' : '#e7e9ed');
        root.style.setProperty('--card', dark ? '#111827' : '#f5f6f8');
        root.style.setProperty('--surface', dark ? '#1f2937' : '#e4e7eb');
        root.style.setProperty('--border', dark ? '#374151' : '#cbd1d9');
        root.style.setProperty('--accent', dark ? '#2563eb' : '#26313f');
        if (meta) meta.setAttribute('content', dark ? '#0b1120' : '#e7e9ed');
    };
    const saved = readPreference();
    if (select) {
        select.value = saved;
        select.addEventListener('change', () => {
            const theme = ['system', 'light', 'dark'].includes(select.value) ? select.value : 'system';
            savePreference(theme);
            applyTheme(theme);
        });
    }
    applyTheme(saved);
    const onSystemChange = () => {
        if (readPreference() === 'system') applyTheme('system');
    };
    if (media.addEventListener) media.addEventListener('change', onSystemChange);
    else if (media.addListener) media.addListener(onSystemChange);
})();
 
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
