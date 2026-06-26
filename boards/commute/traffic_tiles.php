<?php
/**
 * TomTom Traffic Flow tile proxy — keeps the API key server-side.
 * Used by traffic.php Leaflet layer: traffic_tiles.php?style=…&z=&x=&y=
 */

require_once dirname(__DIR__, 2) . '/lib/traffic_lib.php';

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
$apiMode = traffic_cached_api_mode() ?? 'auto';
$cacheFile = $cacheDir . '/' . hash('sha256', $apiMode . '/' . $style . '/' . $z . '/' . $x . '/' . $y) . '.png';
$ttl = 120;
$bust = isset($_GET['t']);

if (!$bust && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=120');
    readfile($cacheFile);
    exit;
}

$result = traffic_fetch_tile($style, $z, $x, $y);
if (!$result['ok'] || !is_string($result['data'])) {
    if (is_file($cacheFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=60');
        readfile($cacheFile);
        exit;
    }
    $http = (int)($result['http'] ?? 0);
    http_response_code($http >= 400 && $http < 600 ? $http : 502);
    exit;
}

@file_put_contents($cacheFile, $result['data'], LOCK_EX);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=120');
echo $result['data'];
