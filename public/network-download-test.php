<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Encoding: identity');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Random bytes avoid compression making the measured transfer unrealistically fast.
$payload = random_bytes(256 * 1024);
header('Content-Length: ' . strlen($payload));
echo $payload;
