# Hourly server-side preparation

GaugeIQ's hourly preparation cron keeps weather-map and Moon/Tide data ready on the server before a client opens the app.

## What it does

- Refreshes the ECMWF IFS 0.25° frame schedule when the schedule is older than one hour (4 past frames and 2 future frames).
- Checks/prepares server-side decoded regional caches for both configured map locations. It preserves each location's saved region bounds and only reprocesses missing, invalid, or newly scheduled data.
- Refreshes Moon/Tide snapshots for Location 1 and Location 2 only when their stored snapshots are at least one hour old.
- Uses a filesystem lock so a slow large-region run cannot overlap the next hourly invocation.
- Logs progress to stdout; task failures are logged and return a non-zero exit code. A failed schedule refresh leaves the previous schedule in place, and regional cache preparation is still attempted against that schedule.

## Install the schedule

From the server's crontab (`crontab -e`), add one entry. Replace the paths with the actual installation path and PHP CLI binary:

```cron
0 * * * * /usr/bin/php /path/to/GaugeIQ/cron/hourly-weather-preparation.php >> /path/to/GaugeIQ/storage/hourly-weather-preparation.log 2>&1
```

This runs at the top of every hour. The script is CLI-only; do not expose it through a web route.

If the server's PHP CLI path differs, check it with `which php`. Ensure the cron user can write to GaugeIQ's storage directory and has the PHP cURL extension enabled.

## Run once manually

```bash
php /path/to/GaugeIQ/cron/hourly-weather-preparation.php
```

The cron can take longer than an hour on a first run with a large region. The lock makes the next scheduled invocation skip if the previous run is still active; it does not kill or restart the existing run.
