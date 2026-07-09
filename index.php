<?php
// Apache entry point. The compiled React bundle remains a static index.html.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://api.brain-map.org https://mouse.brain-map.org; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
$file = __DIR__ . '/index.html';
if (!is_file($file)) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>App not built</title><h1>Frontend build is missing</h1><p>Upload index.html and the assets directory from the provided package.</p>';
    exit;
}
readfile($file);
