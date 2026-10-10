# GaugeIQ

GaugeIQ is a mobile-first Progressive Web App for monitoring atmospheric pressure, humidity, and wind, with configurable server-side alerts delivered to supported devices.

## Architecture

- PHP 8.2+ backend
- SQLite or MySQL/MariaDB storage
- Open-Meteo weather data for atmospheric pressure
- Web Push subscriptions for browser notifications
- VAPID authentication for server-to-device push
- Server-side cron job for reliable background checking
- Installable PWA for iPhone Safari

## First setup

Production release packages are designed for:

**Upload → open GaugeIQ → follow the installer → done.**

The /install/ wizard can create the initial configuration, initialize the database, generate VAPID credentials, and set the monitoring location. Release packages include Composer's production dependencies in vendor/, so a normal release installation does not require Composer.

For source/development installations, Composer and the migration tools can still be used manually.

For a detailed cPanel deployment, see docs/CPANEL.md.
## iPhone notifications

On iPhone and iPad, Web Push is supported for web apps that have been added to the Home Screen. Notification permission must be requested from a direct user interaction such as tapping GaugeIQ's alert button.

## Weather alerts

GaugeIQ stores each weather observation and evaluates saved alert rules during the server-side cron run. Rules currently support:

- Pressure changes, rising above a value, or falling below a value
- Humidity changes, rising above a value, or falling below a value
- Wind-speed changes, rising above a value, or falling below a value
- Wind-direction changes by a configurable number of degrees
- Wind arriving from a specific compass direction
- Combined minimum wind speed plus a direction range, including ranges that cross north such as NW → N

Each rule can be enabled or disabled and has its own notification cooldown. Triggered rules are recorded in the alert-event table so notification history can be expanded in the dashboard later.

For installations that have not created custom rules yet, the original pressure-threshold configuration remains available as a compatibility fallback.

Expired push subscriptions are removed automatically when the push service reports them as gone.

## Testing push notifications

After an iPhone has enabled alerts, test the real server-to-device push path from the server:

```bash
php bin/test-push.php
```

GaugeIQ intentionally does not expose a public test-push endpoint. A public endpoint could allow an anonymous visitor to trigger notifications to every registered device.

## Server requirements

The current Web Push library requires PHP 8.2+ plus the `curl`, `mbstring`, and `openssl` extensions. SQLite support through `pdo_sqlite` is also required. `bcmath` or `gmp` can improve performance but are optional.

## Security model

The application is designed so that the project root is not the web root. Only `public/` should be publicly served. Configuration, application classes, cron scripts, Composer dependencies, and the SQLite database remain outside the public document root.

The public web root also sends basic browser security headers and disables directory listings.

## Easy installation

A first-run installer is available at `/install/`. It can create the initial configuration, initialize the database, generate VAPID credentials, and set the monitoring location.

The installer supports:

- SQLite storage, created automatically during installation
- Browser location permission for easy coordinate setup
- Direct NOAA historical data download and import into the same server database used by live monitoring

The installer locks itself after successful installation.

Production release packages bundle Composer dependencies, including vendor/, so non-technical cPanel users do not need to install Composer. The first-run installer uses SQLite only and enables the standard pressure, humidity, and wind monitoring defaults without asking users to configure those options.

## Alert settings

The browser settings page is available at `/alerts.php`. It provides a mobile-friendly interface for creating, enabling, disabling, and deleting alert rules. Direction values are stored as degrees internally so the alert engine can handle the 0°/360° boundary correctly.

## Historical weather import and unified timeline

GaugeIQ can download historical observations from NOAA's public Global Summary of the Day dataset from **Admin → Historical data**. It finds a nearby reporting station based on the single location configured in Admin, downloads the selected date range in batches, and imports the observations directly into GaugeIQ's configured server database.

- Historical records and live readings are stored together in `gaugeiq_pressure_readings`.
- Imports match observation timestamps to update existing records instead of creating another record for the same timestamp.
- The dashboard's **All history** graphs query the server database, so imported observations and new cron readings appear in one timeline.
- The scheduled Open-Meteo monitor and existing alert rules continue running; the import does not pause cron.
- NOAA's Global Summary of the Day data is **daily**, with coverage from 1973 onward. It is not hourly history.
- Available NOAA values include temperature, dew point, sea-level pressure, wind speed, and precipitation when reported. Temperature and dew point are converted from Fahrenheit, wind speed from knots to km/h, and precipitation from inches to millimetres. Fields NOAA does not provide remain empty rather than being fabricated.
- NOAA observations with missing pressure are skipped because GaugeIQ's current primary readings table requires a pressure value.
- Each request imports up to five years; larger ranges are processed sequentially. If a batch fails, previously completed batches remain saved and the same range can safely be retried.

The standard short-range graphs and weather-change calculations continue to use recent server readings. The **All history** option displays the full imported-plus-live timeline.

## Weather monitoring and dashboard insights

GaugeIQ records:

- Atmospheric pressure
- Relative humidity
- Wind speed
- Wind direction

Alerts can be configured independently. Wind monitoring supports speed thresholds, direction-change thresholds, and specific compass directions. Direction calculations use degrees and correctly handle the 0°/360° boundary.

### Weather Change score

The dashboard's **Weather Change** score estimates the intensity of change across the latest available observation window (up to six hours). It is a weighted change indicator, not a forecast or probability of rain. The dashboard shows the score in a circle; on mobile, the score and summary are centred. The intensity bar has been removed, and the individual reason cards stay hidden until **Show reasons** is pressed.

For each available signal, GaugeIQ calculates a severity from 0 to 1, applies its configured weight, and normalizes over signals with valid readings:

`score = 10 × weighted severity sum ÷ valid-signal weight sum`

The result is capped at 10 and displayed to one decimal place. Missing readings are excluded rather than treated as zero. Current provisional signal weights and thresholds are documented in the dashboard's **Show calculation and weights** disclosure. These engineering thresholds have not yet been statistically calibrated, so the score should be interpreted as an indicator of recent change rather than a calibrated risk prediction.

When the reason cards are opened, unchanged/zero-severity signals are hidden. Air pressure, temperature, humidity, wind speed, temperature/dew-point gap, rainfall, and cloud cover use an up arrow for increases and a down arrow for decreases; wind direction uses a two-way arrow and reports its shortest angular shift in degrees. Reason cards omit the observation-window duration to keep them concise.

## Project status

Foundation, Web Push delivery, SQLite/MySQL database support, first-run installation, humidity monitoring, wind speed monitoring, wind direction monitoring, configurable alert rules, cooldowns, alert-event storage, and the initial mobile alert settings screen are implemented. The dashboard includes a unified historical-plus-live timeline, recent alert history, light/dark/follow-device appearance controls, scheduled monitoring health information, and release update notifications. Production releases are packaged with Composer dependencies bundled.
