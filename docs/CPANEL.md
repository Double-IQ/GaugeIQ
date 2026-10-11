# GaugeIQ on cPanel

This guide is for a normal cPanel hosting account with PHP, Composer or SSH, SQLite, and cron support. GaugeIQ can also use MySQL/MariaDB when configured by the installer or local configuration.

## 1. Keep the application outside the public web root

The safest layout is:

```
/home/ACCOUNT/GaugeIQ/
    app/
    bin/
    config/
    cron/
    database/
    public/
    vendor/
    storage/
```

Only `GaugeIQ/public/` should be the website document root.

Do **not** point a domain or subdomain at the GaugeIQ project root. If the project root is publicly accessible, files such as `config/local.php`, the SQLite database, and PHP source outside `public/` could become exposed.

If cPanel allows you to choose the document root, use:

```
/home/ACCOUNT/GaugeIQ/public
```

## 2. Upload GaugeIQ

Upload the repository to a directory outside `public_html` when possible, for example:

```
/home/ACCOUNT/GaugeIQ
```

If your domain's document root is already configured as `/home/ACCOUNT/GaugeIQ/public`, no files need to be copied into `public_html`.

## 3. Create the local configuration

Copy:

```
config/config.example.php
```

to:

```
config/local.php
```

Edit `config/local.php` and set:

- `app.base_url` to the exact HTTPS URL where GaugeIQ will run.
- `pressure.latitude` and `pressure.longitude` to the monitoring location.
- `pressure.location_name` to the displayed location name.
- `pressure.threshold_hpa` to the pressure-change threshold.
- `push.subject` to a stable contact address or HTTPS URL.

Leave the VAPID keys empty until they are generated.

**Never put `local.php` inside `public/`.**

## 4. Install Composer dependencies

From the GaugeIQ project root:

```
composer install --no-dev --optimize-autoloader
```

If Composer is not available as `composer`, use the Composer command supplied by your hosting provider or cPanel Terminal.

## 5. Create the SQLite database

Run:

```
php database/migrate.php
```

This creates:

```
storage/gaugeiq.sqlite
```

The PHP process needs write access to the `storage/` directory.

## 6. Generate VAPID keys

Run:

```
php bin/generate-vapid.php
```

Copy the generated `public_key` and `private_key` into `config/local.php`.

Generate these keys once. Do not regenerate them during normal updates, because existing browser subscriptions depend on the same VAPID identity.

## 7. Check the server

Run:

```
php bin/check-requirements.php
```

The required PHP extensions are:

- `pdo_sqlite`
- `curl`
- `mbstring`
- `openssl`

Fix any `MISSING` result before continuing.

## 8. Configure the cron job

GaugeIQ checks pressure on the server, so the cron job must run even when the iPhone is asleep.

Use cPanel **Cron Jobs** and run the checker every 15 minutes:

```
php /home/ACCOUNT/GaugeIQ/cron/check-pressure.php
```

Use the PHP CLI binary provided by your host if cPanel requires an absolute executable path. You can check it from Terminal with:

```
which php
```

Do not run the cron URL through the browser. Run the PHP file from the server.

## 9. Connect the iPhone

1. Open GaugeIQ in Safari over HTTPS.
2. Add it to the Home Screen.
3. Open the Home Screen version of GaugeIQ.
4. Tap **Enable alerts**.
5. Allow notifications when iOS asks.

The browser subscription is then saved in GaugeIQ's SQLite database.

## 10. Configure weather alerts

After installation, open:

```
https://YOUR-GAUGЕIQ-URL/alerts.php
```

Create the alerts you want GaugeIQ to evaluate. Examples include:

- Pressure changing by 3 hPa or more
- Humidity rising above 75%
- Wind speed rising above 40 km/h
- Wind direction changing by 45°
- Wind arriving from NW through N while wind speed is at least 40 km/h

The alert cooldown is per rule. It prevents the same rule from generating a notification on every cron run while its condition remains active.

## 11. Test Web Push from the server

After the iPhone has enabled alerts, run:

```
php bin/test-push.php
```

You should see a message such as:

```
GaugeIQ test alert sent to 1 device(s).
```

The test command is deliberately server-side. GaugeIQ does not expose a public test-push endpoint that an anonymous visitor could use to trigger notifications to every registered device.

## 12. Installing a production release

A published GaugeIQ release package includes the Composer production dependencies in vendor/. Do not use GitHub's **Code → Download ZIP** for installation; that source archive does not contain vendor/.

For a new installation:

1. Set the domain/subdomain document root to the GaugeIQ public/ directory.
2. Upload and extract the release package outside the public web root.
3. Open the GaugeIQ URL.
4. Follow the /install/ wizard.
5. Complete the monitoring location and database settings.
6. Add GaugeIQ to the iPhone Home Screen and enable notifications.
7. Configure the 15-minute cron job.

You do not need to run Composer when using an official production release package.

## 13. Administrator and updates

The installer creates an administrator account. Use:

```
https://YOUR-GAUGЕIQ-URL/login.php
```

The admin session uses secure, HTTP-only, SameSite cookies and CSRF protection. Administrator login is required to install an update.

From **Admin → Updates**, GaugeIQ checks the latest stable GitHub Release. If a newer release is published and its package assets are ready, Admin offers the existing **Install update** action. The server resolves the official release URLs itself; the browser cannot choose arbitrary package URLs.

The updater:

- verifies the official release package's SHA-256 checksum before installation
- accepts only the expected versioned GaugeIQ release assets over HTTPS
- validates archive paths and rejects entries outside the expected root or symbolic links
- preserves `config/local.php`, `config/installed.lock`, and `storage/`
- makes an application backup and a consistent SQLite database snapshot before replacing files
- runs the database migration with the PHP CLI interpreter and checks the installed version, dependencies, configuration, and database
- attempts to restore the application and SQLite snapshot if installation, migration, or health checks fail
- retains the three most recent successful update backups

The updater never regenerates the VAPID keys or replaces the SQLite database with an empty one. Keep the administrator password private and do not expose update endpoints to unauthenticated users.

## 14. Automatic release updates

The existing scheduled deployment script checks the official GitHub Releases feed and automatically installs a newer stable release when its package and checksum assets are ready. It retries on the next scheduled run if GitHub has published the release but is still preparing those assets.

A new release is picked up on the next cron run, so the delay depends on the configured schedule. For example, a 30-minute schedule normally means a new release is found within about 30 minutes.

Only published stable Releases with the versioned ZIP and SHA-256 checksum are installed. A commit pushed to `main` on its own is no longer a production update after the release-based workflow is deployed. Publish a versioned GitHub Release to distribute production changes.

The existing **Admin → Updates → Install update** action remains available for a manual update; it uses the same updater as the scheduled task.

## 15. Scheduled release updates for `weather.bichet.co.za`

The GaugeIQ repository checkout is:

```
/home/bicheveb/GaugeIQ
```

The subdomain document root should remain:

```
/home/bicheveb/GaugeIQ/public
```

Keep the existing cPanel deployment cron command:

```
/bin/bash /home/bicheveb/GaugeIQ/cron/update-from-github.sh
```

Set it to the interval you prefer (currently every 30 minutes). Despite the legacy filename, `update-from-github.sh` no longer runs `git pull`; it invokes the shared PHP release updater and logs results to:

```
/home/bicheveb/logs/gaugeiq-auto-update.log
```

The script uses cPanel's PHP 8.4 CLI at `/opt/cpanel/ea-php84/root/usr/bin/php` when available. If your hosting account changes PHP versions, set `GAUGEIQ_PHP_BIN` in the cron environment or update the path in the script.

This release-update cron is independent of `cron/check-pressure.php`, which continues collecting weather readings and processing notifications on its own schedule. Do not replace or remove the weather-monitor cron.

The existing Git-based script can deploy this transition one final time. After the new script is deployed, production updates come from published GitHub Releases using the release-package updater, not commits pulled directly from `main`. The scheduled automatic installer is currently enabled for SQLite installations because it can create and restore a consistent database snapshot; non-SQLite installations are not automatically updated by this cron runner.
 
## Security rules

- Use HTTPS.
- Keep config/, app/, database/, cron/, bin/, storage/, and vendor/ outside the document root.
- Never commit config/local.php.
- Never publish the VAPID private key.
- Do not make storage/ publicly accessible.
- Do not expose a public endpoint that can send arbitrary push notifications.
- Treat release ZIP files and their SHA-256 checksum as a matched pair.
- Never replace config/local.php or persistent storage during an update.
