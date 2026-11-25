<?php
declare(strict_types=1);

// Fixed-source proxy for NASA's public-domain Blue Marble July 2004 texture.
// Cache the image on the server so browser/PWA clients use a same-origin URL.
$source = 'https://assets.science.nasa.gov/content/dam/science/esd/eo/images/bmng/bmng-base/july/world.200407.3x5400x2700.jpg';
$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gaugeiq-cache';
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'earth-blue-marble-july-2004.jpg';
$maxAge = 30 * 24 * 60 * 60;

if (is_file($cacheFile) && filesize($cacheFile) > 100000 && filemtime($cacheFile) > time() - $maxAge) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($cacheFile);
    exit;
}

if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    http_response_code(503);
    exit('Earth texture cache unavailable');
}

$data = false;
if (function_exists('curl_init')) {
    $ch = curl_init($source);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'GaugeIQ/1.0 (NASA Blue Marble texture cache)',
    ]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) $data = false;
} else {
    $context = stream_context_create(['http' => [
        'timeout' => 25,
        'follow_location' => 1,
        'user_agent' => 'GaugeIQ/1.0 (NASA Blue Marble texture cache)',
    ]]);
    $data = @file_get_contents($source, false, $context);
}

if (!is_string($data) || strlen($data) < 100000 || substr($data, 0, 2) !== "\xFF\xD8") {
    http_response_code(502);
    exit('NASA Earth texture could not be retrieved');
}

$tempFile = $cacheFile . '.tmp';
if (@file_put_contents($tempFile, $data, LOCK_EX) !== false) {
    @rename($tempFile, $cacheFile);
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $data;
