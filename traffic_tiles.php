<?php
/**
 * TomTom Traffic Flow tile proxy — keeps the API key server-side.
 * Used by traffic.php Leaflet layer: traffic_tiles.php?style=…&z=&x=&y=
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

$key = (string)cfg('traffic.TOMTOM_API_KEY', '');
if ($key === '' || $key === 'PUT-YOUR-TOMTOM-KEY-HERE') {
    http_response_code(503);
    exit;
}

$flowStyles = ['relative0-dark', 'relative0', 'relative', 'absolute'];
$style = (string)($_GET['style'] ?? 'relative0-dark');
if (!in_array($style, $flowStyles, true)) {
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

$url = sprintf(
    'https://api.tomtom.com/traffic/map/4/tile/flow/%s/%d/%d/%d.png?key=%s&thickness=5',
    rawurlencode($style),
    $z,
    $x,
    $y,
    rawurlencode($key)
);
$policy = signage_fetch_url_allowed($url);
if (!$policy['ok']) {
    http_response_code(502);
    exit;
}

$cacheDir = __DIR__ . '/cache/traffic_tiles';
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

$ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
$data = @file_get_contents($url, false, $ctx);
if (!is_string($data) || strlen($data) < 64) {
    if (is_file($cacheFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=60');
        readfile($cacheFile);
        exit;
    }
    http_response_code(502);
    exit;
}

@file_put_contents($cacheFile, $data, LOCK_EX);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=120');
echo $data;
