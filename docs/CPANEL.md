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

The admin session uses secure, HTTP-only, SameSite cookies and CSRF protection. The administrator area is required for application updates.

From **Admin → Updates**, GaugeIQ can install a published release after downloading the official package and checksum. The updater:

- accepts only HTTPS GitHub release downloads
- verifies the SHA-256 checksum before installation
- validates the archive structure and rejects unsafe paths
- preserves config/local.php, config/installed.lock, and storage/
- creates an application backup before replacement
- attempts a rollback if replacement fails
- runs database migrations when the updated application is next loaded

Keep the administrator password private. Do not share the admin session or expose update endpoints to unauthenticated users.

## 14. Updating GaugeIQ

GaugeIQ can check the official GitHub Releases feed and display an update notification on the dashboard when a newer release is available. The notification links to the published release so the package and checksum can be reviewed before installation.

For a manual update:

1. Back up config/local.php.
2. Keep the existing storage/ directory and database.
3. Upload the new release files.
4. Keep your existing config/local.php.
5. Keep your existing VAPID keys.
6. Run php database/migrate.php if the release includes database migrations.
7. Run php bin/check-requirements.php.
8. Verify the dashboard and cron monitoring.

The bundled vendor/ directory means Composer is not required when updating from an official release package.

Do not replace the existing SQLite database with a new empty database and do not regenerate VAPID keys.

The in-app release check is informational; it must not expose configuration, VAPID credentials, database contents, or internal exception details.


## 15. Automatically sync cPanel with GitHub main

For the deployment at `weather.bichet.co.za`, the Git working copy is:

```
/home/bicheveb/GaugeIQ
```

The subdomain document root should remain:

```
/home/bicheveb/GaugeIQ/public
```

The repository includes `cron/update-from-github.sh`. It fetches `origin/main` and updates the checkout only when Git can do a safe fast-forward. It does not reset the checkout or overwrite edits to tracked files. If the branch has diverged, tracked local edits exist, or the fetch fails, it stops and writes the reason to:

```
/home/bicheveb/logs/gaugeiq-auto-update.log
```

### Add the separate deployment cron

In cPanel, open **Cron Jobs** and add a job that runs every 30 minutes. Use this command:

```
/bin/bash /home/bicheveb/GaugeIQ/cron/update-from-github.sh
```

Set the minute field to `*/30` and the hour, day, month, and weekday fields to `*`.

This deployment cron is independent of `cron/check-pressure.php`, which continues to collect/check weather data on its own schedule. Do not replace or remove the weather-monitor cron.

The Git updater advances tracked files from `main`; it does not run Composer or perform arbitrary database migrations. Review changes that introduce dependencies or require manual migration steps. Keep local configuration and persistent storage backed up as usual.


## Security rules

- Use HTTPS.
- Keep config/, app/, database/, cron/, bin/, storage/, and vendor/ outside the document root.
- Never commit config/local.php.
- Never publish the VAPID private key.
- Do not make storage/ publicly accessible.
- Do not expose a public endpoint that can send arbitrary push notifications.
- Treat release ZIP files and their SHA-256 checksum as a matched pair.
- Never replace config/local.php or persistent storage during an update.
