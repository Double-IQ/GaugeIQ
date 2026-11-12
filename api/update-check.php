<?php
declare(strict_types=1);

require __DIR__ . '/../app/Version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $current = GaugeIQVersion::VERSION;
    $response = @file_get_contents('https://api.github.com/repos/Double-IQ/GaugeIQ/releases/latest', false, stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: GaugeIQ/{$current}\r\n",
            'timeout' => 5,
        ],
    ]));

    if ($response === false) {
        throw new RuntimeException('Release check failed.');
    }

    $release = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    $tag = ltrim((string)($release['tag_name'] ?? ''), 'v');
    if ($tag === '') {
        throw new RuntimeException('Release version missing.');
    }

    $assets = [];
    foreach (($release['assets'] ?? []) as $asset) {
        $name = (string)($asset['name'] ?? '');
        $url = (string)($asset['browser_download_url'] ?? '');
        if ($name !== '' && $url !== '') {
            $assets[$name] = $url;
        }
    }

    $package = "GaugeIQ-{$tag}.zip";
    $checksum = "{$package}.sha256";

    echo json_encode([
        'current_version' => $current,
        'latest_version' => $tag,
        'update_available' => version_compare($tag, $current, '>'),
        'release_url' => (string)($release['html_url'] ?? ''),
        'package_url' => $assets[$package] ?? null,
        'checksum_url' => $assets[$checksum] ?? null,
        'published_at' => $release['published_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Unable to check for GaugeIQ updates.']);
}
