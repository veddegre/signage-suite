<?php
/**
 * TomTom Traffic Flow tile proxy — keeps the API key server-side.
 * Used by traffic.php Leaflet layer: traffic_tiles.php?style=…&z=&x=&y=
 */

require_once __DIR__ . '/traffic_lib.php';

if (traffic_api_key() === null) {
    http_response_code(503);
    exit;
}

$style = (string)($_GET['style'] ?? 'relative0-dark');
if (!in_array($style, traffic_flow_styles(), true)) {
    $style = 'relative0-dark';
}

$z = isset($_GET['z']) ? (int)$_GET['z'] : -1;
$x = isset($_GET['x']) ? (int)$_GET['x'] : -1;
$y = isset($_GET['y']) ? (int)$_GET['y'] : -1;
if ($z < 0 || $z > 22 || $x < 0 || $y < 0) {
    http_response_code(400);
    exit;
}
$maxTile = 1 << $z;
if ($x >= $maxTile || $y >= $maxTile) {
    http_response_code(400);
    exit;
}

$cacheDir = traffic_cache_dir();
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/' . hash('sha256', $style . '/' . $z . '/' . $x . '/' . $y) . '.png';
$ttl = 120;
$bust = isset($_GET['t']);

if (!$bust && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=120');
    readfile($cacheFile);
    exit;
}

$url = traffic_tile_url($style, $z, $x, $y);
if ($url === null) {
    http_response_code(503);
    exit;
}

$policy = signage_fetch_url_allowed($url);
if (!$policy['ok']) {
    http_response_code(502);
    exit;
}

$data = null;
$httpCode = 0;
$fetchErr = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: image/png,*/*'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $fetchErr = curl_error($ch);
    curl_close($ch);
    if (is_string($body)) {
        $data = $body;
    }
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : null;
    if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $httpCode = (int)$m[1];
    }
}

$pngOk = is_string($data) && str_starts_with($data, "\x89PNG\r\n\x1a\n") && strlen($data) > 100;
if (!$pngOk) {
    $snippet = is_string($data) ? substr(preg_replace('/\s+/', ' ', $data), 0, 180) : '';
    @file_put_contents(
        $cacheDir . '/last_error.txt',
        date('c') . " HTTP $httpCode"
            . ($fetchErr !== '' ? " curl: $fetchErr" : '')
            . ($snippet !== '' ? " body: $snippet" : '') . "\n",
        LOCK_EX
    );
    if (is_file($cacheFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=60');
        readfile($cacheFile);
        exit;
    }
    http_response_code($httpCode >= 400 && $httpCode < 600 ? $httpCode : 502);
    exit;
}

@file_put_contents($cacheFile, $data, LOCK_EX);
@unlink($cacheDir . '/last_error.txt');
header('Content-Type: image/png');
header('Cache-Control: public, max-age=120');
echo $data;
