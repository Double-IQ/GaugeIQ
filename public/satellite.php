<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    header('Location: install/');
    exit;
}
$config = require $configPath;
$locationName = (string)($config['pressure']['location_name'] ?? 'Your location');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#f3f4f6" id="themeColorMeta">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="192x192" href="/branding/icon-192.png">
<link rel="apple-touch-icon" href="/branding/apple-touch-icon.png">
<link rel="stylesheet" href="css/app.css?v=20261017-forecast-under-temperature">
<title>MeteoSat-12 · GaugeIQ</title>
</head>
<body>
<main class="shell">
    <header class="forecast-detail-header">
        <div>
            <p class="eyebrow">GAUGEIQ WEATHER</p>
            <h1>MeteoSat-12</h1>
            <p class="muted forecast-location"><?= htmlspecialchars($locationName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="secondary forecast-back-link" href="index.php">← Dashboard</a>
    </header>

    <section class="card satellite-card" aria-labelledby="satelliteStreamTitle">
        <div class="section-heading">
            <div>
                <h2 id="satelliteStreamTitle">Live satellite stream</h2>
                <p class="muted">EUMETSAT Meteosat-12 Africa view.</p>
            </div>
        </div>
        <div class="satellite-player">
            <iframe
                src="https://www.youtube-nocookie.com/embed/U3jRSL3y8Vc?rel=0&playsinline=1"
                title="EUMETSAT Meteosat-12 Africa satellite stream"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen></iframe>
        </div>
        <p class="satellite-caption">The video is embedded here, so you can watch it without navigating away from GaugeIQ.</p>
    </section>
</main>
<script src="js/app.js?v=20261016-top-card-wvi"></script>
</body>
</html>
