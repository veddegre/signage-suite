#!/usr/bin/env php
<?php
/**
 * Probe webcam registry entries — HLS freshness, iframe reachability, rotation skip.
 *
 * Usage: php scripts/diagnose-webcam.php [cam-key] [--refresh]
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/webcam_lib.php';

$filter = isset($argv[1]) && !str_starts_with((string)$argv[1], '--')
    ? webcam_normalize_key((string)$argv[1])
    : '';
$refresh = in_array('--refresh', $argv, true);
echo 'Probe TTL: ' . webcam_probe_ttl_sec() . "s\n";
$registry = webcam_registry();
if ($filter !== '') {
    $registry = array_intersect_key($registry, [$filter => true]);
    if ($registry === []) {
        fwrite(STDERR, "Unknown camera key: {$filter}\n");
        exit(1);
    }
}

foreach ($registry as $key => $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $url = (string)($entry['url'] ?? '');
    $kind = (string)($entry['kind'] ?? 'iframe');
    $name = (string)($entry['name'] ?? $key);
    echo str_repeat('─', 72) . "\n";
    echo "{$key} — {$name}\n";
    echo "  kind: {$kind}\n";
    echo "  url:  {$url}\n";
    echo '  probe mode: ' . (webcam_probe_uses_hls($url, $kind) ? 'hls' : 'iframe/http') . "\n";

    if ($refresh && $url !== '') {
        $cachePath = webcam_status_cache_path($url);
        if (is_file($cachePath)) {
            if (@unlink($cachePath)) {
                echo "  cache: cleared\n";
            } else {
                echo "  cache: could not delete {$cachePath} (try sudo)\n";
            }
        }
    }

    $status = webcam_url_status($url, $kind, true);
    echo '  probe online: ' . ($status['online'] ? 'yes' : 'no') . "\n";
    echo '  skip rotation: ' . ($status['skip_rotation'] ? 'yes' : 'no') . "\n";
    echo '  rotation skip: ' . (webcam_skip_rotation('webcam.php?cam=' . rawurlencode($key)) ? 'yes' : 'no') . "\n";

    if (webcam_is_stream_frame_url($url) || webcam_is_ant_media_play_url($url) || $kind === 'stream') {
        $master = webcam_stream_playlist_url($url);
        echo '  hls master: ' . ($master ?? '(none)') . "\n";
        if ($master !== null) {
            $masterBody = webcam_http_get($master);
            if ($masterBody === null) {
                echo "  hls fetch: failed\n";
            }
            $mediaUrl = $masterBody !== null ? webcam_hls_pick_media_playlist($master, $masterBody) : null;
            $mediaBody = $mediaUrl !== null ? webcam_http_get($mediaUrl) : $masterBody;
            if (is_string($mediaBody) && preg_match('#EXT-X-PROGRAM-DATE-TIME:([^\n]+)#', $mediaBody, $m)) {
                echo '  last segment: ' . trim($m[1]) . "\n";
            }
            echo '  hls live: ' . (is_string($mediaBody) && webcam_hls_playlist_is_live($mediaBody) ? 'yes' : 'no') . "\n";
            if (is_string($mediaBody) && $master !== null) {
                $seg = webcam_hls_last_segment_url($mediaBody, $mediaUrl ?? $master);
                if ($seg !== null) {
                    echo '  hls segment: ' . ($seg) . "\n";
                    echo '  segment ok: ' . (webcam_hls_segment_reachable($seg) ? 'yes' : 'no') . "\n";
                }
            }
            if ($kind === 'stream' && !webcam_is_stream_frame_url($url)) {
                echo '  board playlist: ' . (webcam_hls_proxied_playlist(['key' => $key, 'url' => $url, 'kind' => 'stream']) !== null ? 'ok' : 'unavailable') . "\n";
            }
        }
    }
}

echo str_repeat('─', 72) . "\n";
