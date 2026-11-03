<?php
declare(strict_types=1);

require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$configPath = __DIR__ . '/../config/local.php';
$completionMarker = __DIR__ . '/../config/.vapid-recovery-complete';
$error = '';
$success = false;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (is_file($completionMarker)) {
    $error = 'VAPID recovery has already been completed. This recovery page cannot generate another key pair.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your admin session expired. Sign in again and retry.';
    } elseif (!isset($_POST['confirm'])) {
        $error = 'Confirm that you understand existing devices must reconnect before continuing.';
    } elseif (!is_file($configPath) || !is_readable($configPath) || !is_writable($configPath)) {
        $error = 'GaugeIQ could not safely access config/local.php. No keys were changed.';
    } else {
        $tempPath = dirname($configPath) . '/.local-vapid-' . bin2hex(random_bytes(8)) . '.php';
        try {
            $source = file_get_contents($configPath);
            if ($source === false) {
                throw new RuntimeException('GaugeIQ could not read its local configuration.');
            }

            $currentConfig = require $configPath;
            if (!is_array($currentConfig) || !isset($currentConfig['push']) || !is_array($currentConfig['push'])) {
                throw new RuntimeException('The local push configuration could not be verified.');
            }

            $keys = VAPID::createVapidKeys();
            $newPush = $currentConfig['push'];
            $newPush['public_key'] = (string)$keys['publicKey'];
            $newPush['private_key'] = (string)$keys['privateKey'];

            $updatedSource = preg_replace_callback(
                '/([\'"]push[\'"]\\s*=>\\s*)\\[[^\\]]*\\]/s',
                static fn(array $match): string => $match[1] . var_export($newPush, true),
                $source,
                1,
                $replacementCount
            );

            if (!is_string($updatedSource) || $replacementCount !== 1) {
                throw new RuntimeException('GaugeIQ could not safely locate the push settings. No keys were changed.');
            }

            if (file_put_contents($tempPath, $updatedSource, LOCK_EX) === false) {
                throw new RuntimeException('GaugeIQ could not write the temporary configuration. No keys were changed.');
            }

            $permissions = fileperms($configPath);
            if ($permissions !== false) {
                @chmod($tempPath, $permissions & 0777);
            }

            $verifiedConfig = require $tempPath;
            if (!is_array($verifiedConfig)
                || !hash_equals((string)$keys['publicKey'], (string)($verifiedConfig['push']['public_key'] ?? ''))
                || !hash_equals((string)$keys['privateKey'], (string)($verifiedConfig['push']['private_key'] ?? ''))) {
                throw new RuntimeException('The new configuration failed verification. The live configuration was not changed.');
            }

            if (!rename($tempPath, $configPath)) {
                throw new RuntimeException('GaugeIQ could not safely replace config/local.php. No keys were changed.');
            }

            @file_put_contents($completionMarker, gmdate('c') . PHP_EOL, LOCK_EX);
            @chmod($completionMarker, 0600);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configPath, true);
            }

            // The completion marker makes this endpoint permanently one-use.
            $success = true;
        } catch (Throwable $exception) {
            $error = 'VAPID recovery failed: ' . $exception->getMessage();
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css?v=20261031-silver-light-mode-v1">
<title>GaugeIQ — Push connection recovery</title>
</head>
<body>
<main class="shell admin-shell">
<header class="admin-header">
    <div>
        <p class="eyebrow">GAUGEIQ ADMIN</p>
        <h1>Push connection recovery</h1>
        <p class="muted">Restore the server's Web Push credentials.</p>
    </div>
    <a class="secondary button-link" href="alerts.php">Back to Alerts</a>
</header>

<?php if ($success): ?>
    <section class="card success">
        <h2>VAPID credentials updated</h2>
        <p>The new key pair has been saved directly to GaugeIQ's private configuration. The private key was not displayed.</p>
        <p>Next, open GaugeIQ on each device, choose <strong>Enable alerts</strong> to replace its old subscription, then send a test notification from the Alerts admin page.</p>
        <a class="secondary button-link" href="alerts.php">Return to Alerts</a>
    </section>
<?php elseif ($error !== ''): ?>
    <section class="card error"><p><?= h($error) ?></p></section>
    <a class="secondary button-link" href="alerts.php">Return to Alerts</a>
<?php else: ?>
    <section class="card">
        <h2>Replace the push credentials?</h2>
        <p>The previous VAPID key pair is unavailable, and the push service is rejecting the existing subscriptions. This one-time action creates a new pair and updates only the push keys in <code>config/local.php</code>.</p>
        <p><strong>After this change, each device must open GaugeIQ and tap Enable alerts</strong> so it can replace its old browser subscription. Weather data, alert rules and registered device records will not be deleted.</p>
        <p>The private key stays on the server and is never shown on this page.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label class="daily-forecast-option">
                <input type="checkbox" name="confirm" value="1" required>
                <span><strong>I understand that each device must reconnect after the key change.</strong></span>
            </label>
            <button type="submit">Generate and save new push credentials</button>
        </form>
    </section>
<?php endif; ?>
</main>
</body>
</html>
