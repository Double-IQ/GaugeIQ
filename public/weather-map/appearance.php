<?php
declare(strict_types=1);
$configPath = dirname(__DIR__, 2) . '/config/local.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('GaugeIQ must be installed before the weather-map test can run.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#061648">
    <title>Weather Map Appearance · GaugeIQ</title>
    <style>
        :root { color-scheme: dark; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif; background: #061648; color: #f8fafc; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #061648; }
        .page { width: min(760px, 100%); margin: 0 auto; padding: max(22px, env(safe-area-inset-top)) 16px 36px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 22px; }
        h1 { margin: 0; font-size: 21px; font-weight: 760; }
        .subtitle { margin-top: 5px; color: #b9c9e8; font-size: 13px; line-height: 1.45; }
        a, button { color: #fff; }
        .back, button { min-height: 40px; border: 1px solid rgba(148,163,184,.4); border-radius: 10px; padding: 0 13px; background: #102b68; color: #fff; font: inherit; font-size: 13px; font-weight: 650; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .panel { margin-top: 14px; padding: 17px; border: 1px solid rgba(148,163,184,.22); border-radius: 15px; background: #0a2058; }
        h2 { margin: 0 0 5px; font-size: 16px; }
        .description { margin: 0 0 16px; color: #b9c9e8; font-size: 12px; line-height: 1.5; }
        .opacity-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin: 14px 0 17px; }
        .opacity-row label { font-size: 13px; font-weight: 650; }
        .opacity-control { display: flex; align-items: center; gap: 10px; width: min(310px, 58%); }
        input[type="range"] { width: 100%; accent-color: #60a5fa; }
        output { min-width: 42px; color: #dbeafe; font-size: 12px; text-align: right; font-variant-numeric: tabular-nums; }
        .colors { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .color-stop { min-width: 0; }
        .color-stop label { display: block; margin-bottom: 7px; color: #cbd5e1; font-size: 11px; line-height: 1.35; }
        input[type="color"] { width: 100%; height: 42px; padding: 3px; border: 1px solid rgba(148,163,184,.4); border-radius: 9px; background: #102b68; cursor: pointer; }
        .gradient { height: 13px; margin-top: 14px; border-radius: 4px; background: linear-gradient(90deg, #0000, #7dd3fc, #2563eb, #7c3aed, #ec4899); }
        .gradient.cloud { background: linear-gradient(90deg, #0000, #e2e8f0, #cbd5e1, #94a3b8, #475569); }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .save { background: #1d4ed8; border-color: #3b82f6; }
        .reset { background: transparent; }
        #status { min-height: 18px; color: #86efac; font-size: 12px; }
        .note { margin: 17px 2px 0; color: #9fb2d4; font-size: 11px; line-height: 1.5; }
        @media (max-width: 480px) { .page { padding-left: 13px; padding-right: 13px; } .panel { padding: 14px; } .colors { gap: 7px; } .color-stop label { font-size: 10px; } input[type="color"] { height: 38px; } .opacity-row { align-items: flex-start; flex-direction: column; gap: 8px; } .opacity-control { width: 100%; } }
    </style>
</head>
<body>
<main class="page">
    <header>
        <div>
            <h1>Weather Map Appearance</h1>
            <div class="subtitle">Adjust the weather overlay colours and transparency.</div>
        </div>
        <a class="back" href="./">Back to map</a>
    </header>

    <section class="panel" aria-labelledby="precip-heading">
        <h2 id="precip-heading">Precipitation</h2>
        <p class="description">Colour scale from light to very heavy rainfall. The zero-rain value remains transparent.</p>
        <div class="opacity-row">
            <label for="precip-opacity">Layer opacity</label>
            <div class="opacity-control">
                <input id="precip-opacity" type="range" min="0" max="100" step="1">
                <output id="precip-opacity-value" for="precip-opacity">78%</output>
            </div>
        </div>
        <div class="colors">
            <div class="color-stop"><label for="precip-c1">Light<br>0.2 mm/h</label><input id="precip-c1" type="color"></div>
            <div class="color-stop"><label for="precip-c2">Moderate<br>1 mm/h</label><input id="precip-c2" type="color"></div>
            <div class="color-stop"><label for="precip-c3">Heavy<br>5 mm/h</label><input id="precip-c3" type="color"></div>
            <div class="color-stop"><label for="precip-c4">Very heavy<br>20 mm/h</label><input id="precip-c4" type="color"></div>
        </div>
        <div class="gradient" id="precip-gradient"></div>
    </section>

    <section class="panel" aria-labelledby="cloud-heading">
        <h2 id="cloud-heading">Cloud cover</h2>
        <p class="description">Colour scale from low to complete cloud cover. Clear sky remains transparent.</p>
        <div class="opacity-row">
            <label for="cloud-opacity">Layer opacity</label>
            <div class="opacity-control">
                <input id="cloud-opacity" type="range" min="0" max="100" step="1">
                <output id="cloud-opacity-value" for="cloud-opacity">34%</output>
            </div>
        </div>
        <div class="colors">
            <div class="color-stop"><label for="cloud-c1">Low<br>25%</label><input id="cloud-c1" type="color"></div>
            <div class="color-stop"><label for="cloud-c2">Moderate<br>50%</label><input id="cloud-c2" type="color"></div>
            <div class="color-stop"><label for="cloud-c3">High<br>75%</label><input id="cloud-c3" type="color"></div>
            <div class="color-stop"><label for="cloud-c4">Overcast<br>100%</label><input id="cloud-c4" type="color"></div>
        </div>
        <div class="gradient cloud" id="cloud-gradient"></div>
    </section>

    <div class="actions">
        <button class="save" id="save" type="button">Save appearance</button>
        <button class="reset" id="reset" type="button">Reset defaults</button>
        <span id="status" role="status" aria-live="polite"></span>
    </div>
    <p class="note">Settings are saved in this browser on this device. Return to the map after saving; reload the map to apply your changes.</p>
</main>
<script>
(() => {
    const KEY = 'gaugeiqWeatherMapAppearance';
    const defaults = {
        precipitationOpacity: 78,
        cloudOpacity: 34,
        precipitationColors: ['#7dd3fc', '#2563eb', '#7c3aed', '#ec4899'],
        cloudColors: ['#e2e8f0', '#cbd5e1', '#94a3b8', '#475569']
    };
    const ids = {
        precipitationOpacity: 'precip-opacity',
        cloudOpacity: 'cloud-opacity'
    };
    let settings = { ...defaults };

    try {
        const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (saved && typeof saved === 'object') settings = { ...defaults, ...saved };
    } catch (error) {
        console.warn('Could not load saved appearance settings.', error);
    }

    function paintGradient(type) {
        const colors = settings[type + 'Colors'];
        const target = document.getElementById(type === 'precipitation' ? 'precip-gradient' : 'cloud-gradient');
        target.style.background = 'linear-gradient(90deg, transparent, ' + colors.join(', ') + ')';
    }

    function render() {
        for (const [key, id] of Object.entries(ids)) {
            const input = document.getElementById(id);
            const value = Math.max(0, Math.min(100, Number(settings[key]) || 0));
            input.value = value;
            document.getElementById(id + '-value').textContent = value + '%';
        }
        ['precipitation', 'cloud'].forEach((type) => {
            const key = type === 'precipitation' ? 'precipitationColors' : 'cloudColors';
            settings[key].forEach((color, index) => {
                document.getElementById((type === 'precipitation' ? 'precip' : 'cloud') + '-c' + (index + 1)).value = color;
            });
            paintGradient(type);
        });
    }

    document.getElementById('precip-opacity').addEventListener('input', event => {
        settings.precipitationOpacity = Number(event.target.value);
        document.getElementById('precip-opacity-value').textContent = event.target.value + '%';
    });
    document.getElementById('cloud-opacity').addEventListener('input', event => {
        settings.cloudOpacity = Number(event.target.value);
        document.getElementById('cloud-opacity-value').textContent = event.target.value + '%';
    });

    ['precipitation', 'cloud'].forEach(type => {
        const prefix = type === 'precipitation' ? 'precip' : 'cloud';
        const key = type === 'precipitation' ? 'precipitationColors' : 'cloudColors';
        for (let i = 0; i < 4; i++) {
            document.getElementById(prefix + '-c' + (i + 1)).addEventListener('input', event => {
                settings[key][i] = event.target.value;
                paintGradient(type);
            });
        }
    });

    document.getElementById('save').addEventListener('click', () => {
        try {
            localStorage.setItem(KEY, JSON.stringify(settings));
            document.getElementById('status').textContent = 'Appearance saved.';
        } catch (error) {
            document.getElementById('status').textContent = 'Could not save settings in this browser.';
        }
    });

    document.getElementById('reset').addEventListener('click', () => {
        settings = JSON.parse(JSON.stringify(defaults));
        render();
        document.getElementById('status').textContent = 'Defaults restored. Save to keep them.';
    });

    render();
})();
</script>
</body>
</html>
