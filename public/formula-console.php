<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/WeatherFormulaConfig.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$formulaFields = weatherFormulaFields();
$formulaValues = weatherFormulaValues($pdo);
$formulaNotice = '';
$formulaErrors = [];

function formulaH(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formulaSaveSetting(PDO $pdo, string $key, string $value): void {
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    } else {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    }
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['formula_action'])) {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $formulaErrors[] = 'Your session expired. Please reload this page and try again.';
    } else {
        $action = (string)$_POST['formula_action'];
        $candidate = $action === 'reset' ? weatherFormulaDefaults() : [];
        if ($action !== 'reset') {
            foreach ($formulaFields as $key => $field) {
                $raw = trim((string)($_POST[$key] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    $formulaErrors[] = $field['label'] . ' must be a number.';
                    continue;
                }
                $number = (float)$raw;
                if ($number < $field['min'] || $number > $field['max']) {
                    $formulaErrors[] = $field['label'] . ' must be between ' . $field['min'] . ' and ' . $field['max'] . '.';
                    continue;
                }
                $candidate[$key] = $number;
            }
            $totalStabilityWeight = 0.0;
            foreach (['pressure', 'temperature', 'humidity', 'wind_speed', 'wind_direction', 'dew_point_spread', 'rainfall', 'cloud_cover', 'weather_code'] as $weightSignal) {
                $totalStabilityWeight += (float)($candidate['stability_weight_' . $weightSignal] ?? 0);
            }
            if ($totalStabilityWeight <= 0) {
                $formulaErrors[] = 'At least one WVI signal weight must be greater than zero.';
            }
            if (isset($candidate['baseline_min_windows'], $candidate['baseline_days']) && $candidate['baseline_min_windows'] > $candidate['baseline_days']) {
                $formulaErrors[] = 'Minimum comparable windows cannot exceed the number of look-back days.';
            }
            foreach (['pressure', 'temperature', 'humidity', 'wind_speed', 'wind_direction', 'dew_point_spread', 'rainfall', 'cloud_cover', 'weather_code'] as $signal) {
                $triggerKey = 'stability_trigger_' . $signal;
                $maximumKey = 'stability_max_' . $signal;
                if (isset($candidate[$triggerKey], $candidate[$maximumKey]) && $candidate[$maximumKey] <= $candidate[$triggerKey]) {
                    $formulaErrors[] = str_replace('_', ' ', ucfirst($signal)) . ': full-severity level must be greater than its trigger.';
                }
            }
        }

        if (!$formulaErrors) {
            try {
                $pdo->beginTransaction();
                foreach ($candidate as $key => $number) {
                    if (!array_key_exists($key, $formulaFields)) {
                        continue;
                    }
                    formulaSaveSetting($pdo, 'formula_' . $key, rtrim(rtrim(number_format((float)$number, 4, '.', ''), '0'), '.'));
                }
                $pdo->commit();
                $formulaValues = weatherFormulaValues($pdo);
                $formulaNotice = $action === 'reset'
                    ? 'All formula parameters have been reset to their defaults.'
                    : 'Formula parameters saved. The dashboard will use the new values on its next page load.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formulaErrors[] = 'Could not save formula parameters. Please try again.';
            }
        } else {
            foreach ($candidate as $key => $number) {
                $formulaValues[$key] = $number;
            }
        }
    }
}

$formulaGroups = [];
foreach ($formulaFields as $key => $field) {
    $formulaGroups[$field['group']][$key] = $field;
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css?v=20261101-admin-action-buttons-v2">
<link rel="stylesheet" href="css/alerts.css?v=20261029-notification-devices">
<title>Formula Console · GaugeIQ Admin</title>
</head>
<body>
<main class="shell admin-shell">
<header class="admin-header">
    <div>
        <p class="eyebrow">GAUGЕIQ ADMIN</p>
        <h1>Formula Console</h1>
        <p class="muted">Signed in as <?= formulaH(AdminAuth::username()) ?></p>
    </div>
    <nav class="admin-header-actions" aria-label="Admin navigation">
        <a class="secondary button-link" href="admin.php">Back to Admin</a>
        <a class="secondary button-link" href="./">Back to GaugeIQ</a>
    </nav>
</header>
<section class="card formula-console-admin" aria-labelledby="formulaConsoleTitle">
    <div class="section-heading">
        <div><p class="eyebrow">PRIVATE ADMIN TOOL</p><h2 id="formulaConsoleTitle">Formula Console</h2><p class="muted">Review the calculations behind GaugeIQ and tune their parameters.</p></div>
    </div>
    <?php if ($formulaNotice !== ''): ?><div class="formula-notice" role="status"><?= formulaH($formulaNotice) ?></div><?php endif; ?>
    <?php if ($formulaErrors): ?><div class="formula-errors" role="alert"><strong>Nothing was saved.</strong><ul><?php foreach ($formulaErrors as $error): ?><li><?= formulaH($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="formula-intro">
        <h3>How to use the Formula Console</h3>
        <p>These controls change the parameters used by the dashboard's Weather Volatility Index (WVI) and Conditions Trend calculations. Push alerts use the same WVI calculation and saved parameters. Values are saved in GaugeIQ's settings database, not just in your browser. Reload the dashboard after saving to see the new calculation.</p>
        <p><strong>Important:</strong> these are tunable engineering parameters, not statistically calibrated forecasts. Change a few values at a time and compare the resulting scores against observed conditions.</p>
    </div>
    <div class="formula-equations">
        <h3>Core formulas</h3>
        <div class="formula-equation"><strong>Per-signal severity</strong><code>severity = 0 below the configured trigger; otherwise clamp(magnitude ÷ full-severity level, 0, 1)</code><p>Magnitude is based on cumulative movement between successive readings, scaled to a six-hour equivalent. Air pressure uses cumulative movement per hour. Wind direction uses the shortest angular movement.</p></div>
        <div class="formula-equation"><strong>Weather Volatility Index (WVI)</strong><code>10 × Σ(signal weight × signal severity) ÷ Σ(weights for valid signals)</code><p>The result is rounded to one decimal place on a 0–10 scale. Missing signals are excluded from the weighted denominator. The configured minimum number of valid signals, readings and observation span must all be met.</p></div>
        <div class="formula-equation"><strong>Conditions Trend</strong><code>Σ(direction strength × signal weight)</code><p>Positive scores indicate Worsening, negative scores indicate Improving, and scores between the configured positive/negative cut-offs are Steady.</p></div>
        <div class="formula-equation"><strong>Local baseline</strong><code>median(previous comparable six-hour weather-change scores)</code><p>This is contextual information only; it does not feed back into or alter the WVI score. A configured minimum number of valid historical windows is required before the median is displayed.</p></div>
    </div>
    <form method="post" class="formula-form">
        <input type="hidden" name="csrf" value="<?= formulaH($csrf) ?>">
        <?php foreach ($formulaGroups as $groupName => $groupFields): ?>
        <section class="formula-group">
            <h3><?= formulaH($groupName) ?></h3>
            <?php foreach ($groupFields as $key => $field): ?>
            <div class="formula-field">
                <div class="formula-field-description">
                    <label for="<?= formulaH($key) ?>"><?= formulaH($field['label']) ?></label>
                    <p class="muted"><?= formulaH($field['help']) ?></p>
                </div>
                <div class="formula-field-control">
                    <input id="<?= formulaH($key) ?>" name="<?= formulaH($key) ?>" type="number" min="<?= formulaH((string)$field['min']) ?>" max="<?= formulaH((string)$field['max']) ?>" step="<?= formulaH((string)$field['step']) ?>" value="<?= formulaH((string)($formulaValues[$key] ?? $field['default'])) ?>" required>
                    <span><?= formulaH($field['unit']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
        <div class="formula-actions">
            <button type="submit" name="formula_action" value="save">Save formula parameters</button>
            <button type="submit" name="formula_action" value="reset" class="secondary" formnovalidate>Reset to defaults</button>
        </div>
    </form>
    <div class="formula-readonly">
        <h3>Other dashboard values</h3>
        <p><strong>Rain Probability</strong> is supplied by the weather forecast provider; GaugeIQ displays the provider's probability rather than inventing its own probability formula.</p>
        <p><strong>Current temperature, humidity, wind, pressure and forecast values</strong> are observations or forecasts from the configured data provider. The dashboard formats and displays them; they are not calculated from the WVI.</p>
        <p><strong>Weather-code severity mapping</strong> currently uses fixed WMO-code groups in the code (clear/cloudy, fog, drizzle, rain, snow and thunderstorm categories). The group mapping itself is documented here but is not editable on this page yet.</p>
    </div>
</section>
</main>
</body>
</html>
