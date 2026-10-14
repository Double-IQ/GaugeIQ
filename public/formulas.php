<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    header('Location: install/');
    exit;
}
$config = require $configPath;
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/Schema.php';
require_once __DIR__ . '/../app/AdminAuth.php';
require_once __DIR__ . '/../app/WeatherFormulaConfig.php';

$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$fields = weatherFormulaFields();
$values = weatherFormulaValues($pdo);
$notice = '';
$errors = [];

function formulaH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formulaSaveSetting(PDO $pdo, string $key, string $value): void
{
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    } else {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    }
    $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please reload this page and try again.';
    } else {
        $action = (string)($_POST['action'] ?? 'save');
        $candidate = $action === 'reset' ? weatherFormulaDefaults() : [];
        if ($action !== 'reset') {
            foreach ($fields as $key => $field) {
                $raw = trim((string)($_POST[$key] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    $errors[] = $field['label'] . ' must be a number.';
                    continue;
                }
                $number = (float)$raw;
                if ($number < $field['min'] || $number > $field['max']) {
                    $errors[] = $field['label'] . ' must be between ' . $field['min'] . ' and ' . $field['max'] . '.';
                    continue;
                }
                $candidate[$key] = $number;
            }
            foreach ([
                'pressure', 'temperature', 'humidity', 'wind_speed', 'wind_direction',
                'dew_point_spread', 'rainfall', 'cloud_cover', 'weather_code'
            ] as $signal) {
                $triggerKey = 'stability_trigger_' . $signal;
                $maximumKey = 'stability_max_' . $signal;
                if (isset($candidate[$triggerKey], $candidate[$maximumKey]) && $candidate[$maximumKey] <= $candidate[$triggerKey]) {
                    $errors[] = str_replace('_', ' ', ucfirst($signal)) . ': full-severity level must be greater than its trigger.';
                }
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                foreach ($candidate as $key => $number) {
                    if (!array_key_exists($key, $fields)) {
                        continue;
                    }
                    formulaSaveSetting($pdo, 'formula_' . $key, rtrim(rtrim(number_format((float)$number, 4, '.', ''), '0'), '.'));
                }
                $pdo->commit();
                $values = weatherFormulaValues($pdo);
                $notice = $action === 'reset'
                    ? 'All formula parameters have been reset to their defaults.'
                    : 'Formula parameters saved. The dashboard will use the new values on its next page load.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Could not save formula parameters. Please try again.';
            }
        } else {
            foreach ($candidate as $key => $number) {
                $values[$key] = $number;
            }
        }
    }
}

$groups = [];
foreach ($fields as $key => $field) {
    $groups[$field['group']][$key] = $field;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css?v=20261014-formula-console-1">
<title>GaugeIQ Formula Console</title>
</head>
<body>
<main class="shell formula-shell">
    <header class="formula-header">
        <div>
            <p class="eyebrow">PRIVATE ADMIN TOOL</p>
            <h1>Formula Console</h1>
            <p class="muted">Review the calculations behind GaugeIQ and tune their parameters.</p>
        </div>
        <nav class="formula-nav" aria-label="Formula console navigation">
            <a class="secondary button-link" href="index.php">Dashboard</a>
            <a class="secondary button-link" href="admin.php">Admin</a>
            <a class="secondary button-link" href="logout.php">Sign out</a>
        </nav>
    </header>

    <?php if ($notice !== ''): ?><section class="card formula-notice" role="status"><?= formulaH($notice) ?></section><?php endif; ?>
    <?php if ($errors): ?><section class="card formula-errors" role="alert"><strong>Nothing was saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= formulaH($error) ?></li><?php endforeach; ?></ul></section><?php endif; ?>

    <section class="card formula-intro">
        <h2>How to use this page</h2>
        <p>These controls change the parameters used by the dashboard's Weather Stability and Conditions Trend algorithms. Values are saved in GaugeIQ's settings database, not just in your browser. Reload the dashboard after saving to see the new calculation.</p>
        <p><strong>Important:</strong> these are tunable engineering parameters, not statistically calibrated forecasts. Change a few values at a time and compare the resulting scores against observed conditions.</p>
    </section>

    <section class="card formula-equations">
        <h2>Core formulas</h2>
        <div class="formula-equation"><strong>Per-signal severity</strong><code>severity = 0, when magnitude is below trigger; otherwise clamp(magnitude / full-severity level, 0, 1)</code></div>
        <div class="formula-equation"><strong>Weather-change score</strong><code>10 × Σ(weight × severity) ÷ Σ(valid signal weights)</code></div>
        <div class="formula-equation"><strong>Weather Stability</strong><code>10 − weather-change score</code><p>Both scores are rounded to one decimal place and bounded to 0–10. Missing signals are excluded from the weighted denominator. At least the configured minimum number of valid signals is required.</p></div>
        <div class="formula-equation"><strong>Conditions Trend</strong><code>Σ(direction strength × signal weight)</code><p>Positive scores indicate Worsening, negative scores indicate Improving, and scores between the configured positive/negative cut-offs are Steady.</p></div>
        <div class="formula-equation"><strong>Local baseline</strong><code>median(previous comparable six-hour weather-change scores)</code><p>The current six-hour change is compared with previous days. A configured minimum number of valid historical windows is required before the median is displayed.</p></div>
    </section>

    <form method="post" class="formula-form">
        <input type="hidden" name="csrf" value="<?= formulaH($csrf) ?>">
        <?php foreach ($groups as $groupName => $groupFields): ?>
        <section class="card formula-group">
            <h2><?= formulaH($groupName) ?></h2>
            <?php foreach ($groupFields as $key => $field): ?>
            <div class="formula-field">
                <div class="formula-field-description">
                    <label for="<?= formulaH($key) ?>"><?= formulaH($field['label']) ?></label>
                    <p class="muted"><?= formulaH($field['help']) ?></p>
                </div>
                <div class="formula-field-control">
                    <input id="<?= formulaH($key) ?>" name="<?= formulaH($key) ?>" type="number" min="<?= formulaH((string)$field['min']) ?>" max="<?= formulaH((string)$field['max']) ?>" step="<?= formulaH((string)$field['step']) ?>" value="<?= formulaH((string)($values[$key] ?? $field['default'])) ?>" required>
                    <span><?= formulaH($field['unit']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
        <div class="formula-actions">
            <button type="submit" name="action" value="save">Save formula parameters</button>
            <button type="submit" name="action" value="reset" class="secondary" formnovalidate data-confirm="Reset every formula parameter to its default value?">Reset to defaults</button>
        </div>
    </form>

    <section class="card formula-readonly">
        <h2>Other dashboard values</h2>
        <p><strong>Rain Probability</strong> is supplied by the weather forecast provider; GaugeIQ displays the provider's probability rather than inventing its own probability formula.</p>
        <p><strong>Current temperature, humidity, wind, pressure and forecast values</strong> are observations or forecasts from the configured data provider. The dashboard formats and displays them; they are not calculated from the Weather Stability score.</p>
        <p><strong>Weather-code severity mapping</strong> currently uses fixed WMO-code groups in the code (clear/cloudy, fog, drizzle, rain, snow and thunderstorm categories). The group mapping itself is documented here but is not editable on this page yet.</p>
    </section>
</main>
</body>
</html>
