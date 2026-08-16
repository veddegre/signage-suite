<?php
/**
 * Cached, resized RSS story photos so kiosk Chromium never decodes 4–8K
 * news images (a common GPU hang on Raspberry Pi).
 */

require_once __DIR__ . '/security_lib.php';

function rss_image_cache_dir(): string
{
    return SIGNAGE_ROOT . '/cache/rss_img';
}

function rss_image_id(string $url): string
{
    return hash('sha256', trim($url));
}

function rss_image_map_path(string $id): string
{
    return rss_image_cache_dir() . '/' . $id . '.url';
}

function rss_image_body_path(string $id): string
{
    return rss_image_cache_dir() . '/' . $id . '.jpg';
}

function rss_image_remember(string $url): string
{
    $url = trim($url);
    $id = rss_image_id($url);
    $dir = rss_image_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $map = rss_image_map_path($id);
    if (!is_file($map)) {
        @file_put_contents($map, $url, LOCK_EX);
    }

    return $id;
}

/** Local kiosk URL for a remote story photo (empty if none). */
function rss_image_proxy_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    $id = rss_image_remember($url);

    return 'rss_img.php?i=' . rawurlencode($id);
}

function rss_image_lookup(string $id): ?string
{
    $id = strtolower(preg_replace('/[^a-f0-9]/', '', $id) ?? '');
    if (strlen($id) !== 64) {
        return null;
    }
    $map = rss_image_map_path($id);
    if (!is_file($map)) {
        return null;
    }
    $url = trim((string)@file_get_contents($map));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }

    return $url;
}

function rss_image_downscale(string $body): ?string
{
    if ($body === '') {
        return null;
    }
    if (!function_exists('imagecreatefromstring')) {
        return strlen($body) <= 400000 ? $body : null;
    }
    $src = @imagecreatefromstring($body);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, 1920 / max(1, $w), 1080 / max(1, $h));
    if ($scale >= 0.98 && strlen($body) < 350000) {
        return $body;
    }
    $scale = min($scale, 0.98);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 78);

    return (string)ob_get_clean();
}

/** @return array{body:string,mime:string}|null */
function rss_image_fetch_resized(string $url): ?array
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return null;
    }
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, signage_curl_merge_options([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_USERAGENT => 'HomeSignage/1.0 (RSS image)',
        CURLOPT_ENCODING => '',
    ]));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200 || $body === '') {
        return null;
    }
    if (strlen($body) > 8 * 1024 * 1024) {
        return null;
    }
    $out = rss_image_downscale($body);
    if ($out === null || $out === '') {
        return null;
    }

    return ['body' => $out, 'mime' => 'image/jpeg'];
}

function rss_image_stream(string $id): void
{
    $id = strtolower(preg_replace('/[^a-f0-9]/', '', $id) ?? '');
    $url = rss_image_lookup($id);
    if ($url === null) {
        http_response_code(404);
        exit;
    }
    $cache = rss_image_body_path($id);
    $ttl = 6 * 3600;
    if (is_file($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . (string)filesize($cache));
        readfile($cache);
        exit;
    }
    $got = rss_image_fetch_resized($url);
    if ($got === null) {
        http_response_code(502);
        exit;
    }
    @file_put_contents($cache, $got['body'], LOCK_EX);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . (string)strlen($got['body']));
    echo $got['body'];
    exit;
}
