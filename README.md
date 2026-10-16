# GaugeIQ

GaugeIQ is a mobile-first Progressive Web App for monitoring atmospheric pressure, humidity, and wind, with configurable server-side alerts delivered to supported devices.

## Install GaugeIQ

[![Install GaugeIQ](https://img.shields.io/badge/Install-GaugeIQ-0b8f6a?style=for-the-badge&logo=pwa&logoColor=white)](https://weather.bichet.co.za)

Open GaugeIQ on the device you want to install it on, then follow the platform-specific steps below. The button opens GaugeIQ; the device's browser completes installation after you confirm. This installs the web app when the browser offers its PWA installer, rather than deliberately creating a plain bookmark shortcut.

### Android (Chrome)

1. Open [GaugeIQ](https://weather.bichet.co.za) in **Chrome on Android**.
2. Tap **Install** in GaugeIQ's header. If Chrome provides its native installation prompt, review it and tap **Install**.
3. If the in-page button shows instructions instead, open Chrome's **⋮** menu and choose **Install app** if that option is available.
4. Launch GaugeIQ from the installed app icon. It should open in its own standalone window. If Chrome only offers **Add to Home screen** and does not offer **Install app**, don't use that option if you specifically want the installed PWA experience.

### iPhone and iPad (Safari)

1. Open [GaugeIQ](https://weather.bichet.co.za) in **Safari**. Apple's installation flow does not expose Chrome-style automatic install prompts.
2. Tap **Share**.
3. Choose **Add to Home Screen**. If the **Open as Web App** option appears, make sure it is enabled.
4. Tap **Add**, then launch GaugeIQ from its new Home Screen icon. It should open as a web app rather than in a Safari tab.
5. Once GaugeIQ is running as an installed app, tap **Enable alerts** and allow notifications when prompted. Web Push on iPhone/iPad requires the web app to be added to the Home Screen and a supported iOS/iPadOS version.

The browser or operating system may require confirmation; websites cannot silently install an app. GaugeIQ changes the header button from **Install** to **Enable alerts** when it detects that it is running in standalone app mode.

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

### Daily forecast notification

Admin includes an optional daily forecast notification with a configurable local time and three selectable categories: rain, strong winds, and severe weather. It uses the existing push subscriptions and the daily forecast from Open-Meteo. Rain is flagged at a forecast precipitation probability of at least 50% or a daily precipitation total of at least 1 mm; strong wind is flagged at a daily maximum wind speed of 40 km/h or gusts of 60 km/h; severe weather includes heavy precipitation, violent showers, heavy snow, thunderstorms, or gusts of at least 70 km/h. If multiple selected categories match, GaugeIQ sends them together in one daily notification.

The existing 30-minute monitoring cron checks the chosen time and sends on the first run at or after that time, normally within 30 minutes. It evaluates at most once per local date and skips the alert if the scheduled time was missed by more than one hour. This feature is opt-in and does not change the existing immediate alert rules.

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

The installer locks itself after successful installation.

Production release packages bundle Composer dependencies, including vendor/, so non-technical cPanel users do not need to install Composer. The first-run installer uses SQLite only and enables the standard pressure, humidity, and wind monitoring defaults without asking users to configure those options.

## Alert settings

The browser settings page is available at `/alerts.php`. It provides a mobile-friendly interface for creating, enabling, disabling, and deleting alert rules. Direction values are stored as degrees internally so the alert engine can handle the 0°/360° boundary correctly.

## Historical weather and unified timeline

GaugeIQ keeps live readings and Open-Meteo historical weather in the server-side readings database. Historical forecast probabilities are stored separately so forecasts cannot overwrite weather observations. The dashboard's **All history** graphs show the unified readings timeline. Temporary recent-reading model backfill values are excluded from ordinary history graphs and do not feed server-side alert baselines.

### Temporary recent-reading fallback for dashboard meters

On installation, GaugeIQ makes a best-effort request for the most recent 24 hours of hourly Open-Meteo model values. The scheduled monitor refreshes this separate `Open-Meteo Recent Backfill` dataset about once an hour (using the existing 30-minute cron; only refresh when the previous successful backfill is at least 45 minutes old). On existing installations, the first scheduled run creates it automatically if no recent-backfill success has been recorded.

Weather Volatility Index (WVI) and Conditions Trend first calculate from live monitor readings exactly as before. Only when a live-only calculation cannot produce a result may that meter temporarily retry with recent backfill values in the same six-hour window. Model values retain their actual hourly timestamps, duplicate times prefer live readings, and a visible note identifies when fallback estimates were used. As soon as the live-only calculation can produce a result again, the meter stops using the fallback. These estimates are kept out of ordinary history graphs and do not feed server-side alert baselines. Installation and cron treat this backfill as best-effort, so a provider failure does not interrupt normal live monitoring.

## Weather monitoring and dashboard insights

GaugeIQ records:

- Atmospheric pressure
- Relative humidity
- Wind speed
- Wind direction

Alerts can be configured independently. Wind monitoring supports speed thresholds, direction-change thresholds, and specific compass directions. Direction calculations use degrees and correctly handle the 0°/360° boundary.

### Weather Volatility Index (WVI) score

The dashboard's **Weather Volatility Index (WVI)** is the weighted weather-change calculation across the latest available observation window (up to six hours): 10 means high volatility, and 0 means little detected volatility. It is an indicator of recent change, not a forecast or probability of rain. Push alerts use the same weighted score and alert when the WVI rises to or above the configured threshold. The intensity bar has been removed, and the individual reason cards stay hidden until **More information** is pressed.

For each available signal, GaugeIQ calculates a severity from 0 to 1, applies its configured weight, and normalizes over signals with valid readings:

`changeScore = 10 × weighted severity sum ÷ valid-signal weight sum`

`wviScore = changeScore`

The WVI is rounded to one decimal place and limited to the 0–10 range. Missing readings are excluded rather than treated as zero. Current provisional signal weights and thresholds are documented in the dashboard's **More information** disclosure. These engineering thresholds have not yet been statistically calibrated, so Weather Volatility Index (WVI) should be interpreted as an indicator of recent change rather than a calibrated risk prediction.

When the reason cards are opened, unchanged/zero-severity signals are hidden. Air pressure, temperature, humidity, wind speed, temperature/dew-point gap, rainfall, and cloud cover use an up arrow for increases and a down arrow for decreases; wind direction uses a two-way arrow and reports its shortest angular shift in degrees. Reason cards omit the observation-window duration to keep them concise.

## Project status

Foundation, Web Push delivery, SQLite/MySQL database support, first-run installation, humidity monitoring, wind speed monitoring, wind direction monitoring, configurable alert rules, cooldowns, alert-event storage, and the initial mobile alert settings screen are implemented. The dashboard includes a unified historical-plus-live timeline, recent alert history, light/dark/follow-device appearance controls, scheduled monitoring health information, and release update notifications. Production releases are packaged with Composer dependencies bundled.
