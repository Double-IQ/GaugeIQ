<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength < 1 || $contentLength > 65536) {
    http_response_code(413);
    exit;
}

$body = file_get_contents('php://input');
if (!is_string($body) || strlen($body) > 65536) {
    http_response_code(413);
    exit;
}

// The test payload is discarded; nothing is stored or logged by this endpoint.
http_response_code(204);
