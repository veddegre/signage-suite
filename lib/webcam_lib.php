<?php
/**
 * Webcam board — multi-camera registry, embed validation, probe cache, rotation skip.
 */

require_once __DIR__ . '/../config.php';

const WEBCAM_PROBE_TTL_SEC = 1800;
const WEBCAM_ONLINE_MAX_AGE_MIN = 60;
const WEBCAM_SKIP_MIN_AGE_MIN = 1440;

/** @return array<string,array<string,mixed>> Built-in cameras (always available; overridden by saved CAMS rows). */
function webcam_default_cameras(): array
{
    return [
        'gvsu' => [
            'name' => 'GVSU Campus',
            'url' => 'https://webcams.gvsu.edu:5443/live/play.html?id=dtSQveui8yRVSKvb153147654438870',
            'kind' => 'iframe',
            'attribution' => 'GVSU',
        ],
        'grpm' => [
            'name' => 'GR Public Museum',
            'url' => 'https://api.wetmet.net/widgets/stream/frame.php?uid=7bcde7d22d900d7061461d4953482c4b',
            'kind' => 'iframe',
            'attribution' => 'Grand Rapids Public Museum · WMTA',
        ],
        'grandhaven' => [
            'name' => 'Grand Haven Beach',
            'url' => 'https://share.earthcam.net/tJ90CoLmq7TzrY396Yd88KTssi7iV3ZNicDEymFXa2k!',
            'kind' => 'iframe',
            'attribution' => 'EarthCam · MACkite · Surf Grand Haven',
        ],
    ];
}

function webcam_normalize_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_-]/', '', $key));
    if ($key === 'wetmet') {
        return 'grpm';
    }

    return $key;
}

function webcam_validate_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    if (trim((string)($parts['host'] ?? '')) === '') {
        return null;
    }

    return $url;
}

/** @param array<string,mixed> $row @param array<string,mixed>|null $fallback */
function webcam_normalize_entry(array $row, ?array $fallback = null): ?array
{
    $url = webcam_validate_url((string)($row['url'] ?? ($fallback['url'] ?? '')));
    if ($url === null) {
        return null;
    }
    $name = trim((string)($row['name'] ?? ($fallback['name'] ?? '')));
    if ($name === '') {
        $name = 'Webcam';
    }
    $kind = strtolower(trim((string)($row['kind'] ?? ($fallback['kind'] ?? 'auto'))));
    if (!in_array($kind, ['iframe', 'image', 'widget', 'stream', 'auto'], true)) {
        $kind = 'auto';
    }
    if ($kind === 'auto') {
        $kind = webcam_detect_kind($url);
    }
    $attribution = trim((string)($row['attribution'] ?? ($fallback['attribution'] ?? '')));

    return [
        'name' => $name,
        'url' => $url,
        'kind' => $kind,
        'attribution' => $attribution,
    ];
}

function webcam_detect_kind(string $url): string
{
    if (webcam_is_stream_frame_url($url)) {
        return 'stream';
    }
    if (webcam_is_widget_frame_url($url)) {
        return 'widget';
    }
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
    if (preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $path) === 1) {
        return 'image';
    }

    return 'iframe';
}

function webcam_is_widget_frame_url(string $url): bool
{
    return preg_match('#wetmet\.net/widgets/image/frame\.php#i', $url) === 1;
}

function webcam_is_stream_frame_url(string $url): bool
{
    return preg_match('#wetmet\.net/widgets/stream/frame\.php#i', $url) === 1;
}

function webcam_uses_stream_tag(array $cam): bool
{
    return (string)($cam['kind'] ?? 'iframe') === 'stream';
}

function webcam_is_direct_image_url(string $url): bool
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

    return preg_match('/\.(jpe?g|png|gif|webp)(\?|$)/i', $path) === 1;
}

function webcam_uses_image_tag(array $cam): bool
{
    $kind = (string)($cam['kind'] ?? 'iframe');

    return in_array($kind, ['image', 'widget'], true);
}

function webcam_url_needs_image_proxy(string $url, string $kind): bool
{
    if ($kind === 'widget' || webcam_is_widget_frame_url($url)) {
        return true;
    }

    return $kind === 'image' && !webcam_is_direct_image_url($url);
}

function webcam_image_proxy_url(string $key): string
{
    return 'webcam_img.php?cam=' . rawurlencode(webcam_normalize_key($key));
}

function webcam_board_image_src(array $cam): string
{
    $url = (string)($cam['url'] ?? '');
    $kind = (string)($cam['kind'] ?? 'iframe');
    $key = (string)($cam['key'] ?? '');
    if ($url === '' || !webcam_uses_image_tag($cam)) {
        return '';
    }
    if (webcam_url_needs_image_proxy($url, $kind)) {
        return webcam_image_proxy_url($key);
    }

    return $url;
}

function webcam_http_get(string $url, int $timeout = 12, bool $noCache = false): ?string
{
    $url = webcam_validate_url($url);
    if ($url === null || !function_exists('curl_init')) {
        return null;
    }
    $headers = ['Accept: */*'];
    if ($noCache) {
        $headers[] = 'Cache-Control: no-cache, no-store';
        $headers[] = 'Pragma: no-cache';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'HomeSignage/1.0',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($err !== '' || $code < 200 || $code >= 400 || !is_string($body) || $body === '') {
        return null;
    }

    return $body;
}

function webcam_widget_image_url(string $frameUrl): ?string
{
    $html = webcam_http_get($frameUrl);
    if ($html === null) {
        return null;
    }
    if (preg_match('#background-image:\s*url\((https?://[^)]+)\)#i', $html, $m)) {
        return webcam_validate_url(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }

    return null;
}

function webcam_stream_playlist_url(string $streamFrameUrl): ?string
{
    $html = webcam_http_get($streamFrameUrl);
    if ($html === null) {
        return null;
    }
    if (preg_match("#var vurl = '([^']+)'#", $html, $m)) {
        return webcam_validate_url($m[1]);
    }

    return null;
}

function webcam_hls_js_url(): string
{
    return 'vendor/hls.min.js';
}

function webcam_hls_proxy_url(string $key): string
{
    return 'webcam_hls.php?cam=' . rawurlencode(webcam_normalize_key($key));
}

function webcam_hls_remote_allowed(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    return preg_match('#(^|\.)wetmet\.net$#', $host) === 1
        || str_contains($host, 'amazonaws.com');
}

function webcam_hls_absolute_url(string $base, string $ref): ?string
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $ref) === 1) {
        return webcam_validate_url($ref);
    }
    $parts = parse_url($base);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = (string)($parts['scheme'] ?? 'https');
    $host = (string)($parts['host'] ?? '');
    if ($host === '') {
        return null;
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    if (str_starts_with($ref, '/')) {
        return webcam_validate_url($scheme . '://' . $host . $port . $ref);
    }
    $path = (string)($parts['path'] ?? '/');
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';

    return webcam_validate_url($scheme . '://' . $host . $port . $dir . $ref);
}

function webcam_hls_pick_media_playlist(string $masterUrl, string $masterBody): ?string
{
    $base = preg_replace('#/[^/]*$#', '/', $masterUrl) ?? $masterUrl;
    $picked = null;
    foreach (explode("\n", str_replace("\r", '', $masterBody)) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            continue;
        }
        $picked = webcam_hls_absolute_url($base, $trim);
    }

    return $picked;
}

function webcam_hls_rewrite_playlist(string $body, string $playlistUrl, string $camKey): string
{
    $out = [];
    foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            $out[] = $line;
            continue;
        }
        $abs = webcam_hls_absolute_url($playlistUrl, $trim);
        if ($abs === null || !webcam_hls_remote_allowed($abs)) {
            $out[] = $line;
            continue;
        }
        $out[] = 'webcam_hls.php?cam=' . rawurlencode(webcam_normalize_key($camKey))
            . '&u=' . rawurlencode($abs);
    }

    return implode("\n", $out);
}

function webcam_hls_proxied_playlist(array $cam): ?string
{
    if (!webcam_uses_stream_tag($cam)) {
        return null;
    }
    $masterUrl = webcam_stream_playlist_url((string)$cam['url']);
    if ($masterUrl === null) {
        return null;
    }
    $masterBody = webcam_http_get($masterUrl);
    if ($masterBody === null) {
        return null;
    }
    $mediaUrl = webcam_hls_pick_media_playlist($masterUrl, $masterBody);
    if ($mediaUrl === null) {
        return webcam_hls_rewrite_playlist($masterBody, $masterUrl, (string)$cam['key']);
    }
    $mediaBody = webcam_http_get($mediaUrl);
    if ($mediaBody === null) {
        return null;
    }

    return webcam_hls_rewrite_playlist($mediaBody, $mediaUrl, (string)$cam['key']);
}

function webcam_hls_serve(string $camKey): void
{
    $cam = webcam_resolve_camera($camKey);
    if ($cam['off'] || trim((string)$cam['url']) === '' || !webcam_uses_stream_tag($cam)) {
        http_response_code(404);
        exit;
    }

    $fetch = webcam_validate_url((string)($_GET['u'] ?? ''));
    if ($fetch !== null) {
        if (!webcam_hls_remote_allowed($fetch)) {
            http_response_code(403);
            exit;
        }
        $body = webcam_http_get($fetch, 20, true);
        if ($body === null) {
            http_response_code(502);
            exit;
        }
        $path = strtolower((string)parse_url($fetch, PHP_URL_PATH));
        $type = 'application/octet-stream';
        if (str_contains($path, '.m3u8')) {
            $type = 'application/vnd.apple.mpegurl';
        } elseif (preg_match('/\.(ts|mp2t)(\?|$)/', $path) === 1) {
            $type = 'video/mp2t';
        }
        header('Content-Type: ' . $type);
        header('Cache-Control: no-store, max-age=0');
        header('Content-Length: ' . (string)strlen($body));
        echo $body;
        exit;
    }

    $playlist = webcam_hls_proxied_playlist($cam);
    if ($playlist === null) {
        http_response_code(502);
        exit;
    }
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store, max-age=0');
    echo $playlist;
    exit;
}

function webcam_stream_api_response(array $cam): void
{
    header('Content-Type: application/json; charset=UTF-8');
    if ($cam['off'] || trim((string)$cam['url']) === '' || !webcam_uses_stream_tag($cam)) {
        echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo json_encode([
        'ok' => webcam_hls_proxied_playlist($cam) !== null,
        'playlist' => webcam_hls_proxy_url((string)$cam['key']),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function webcam_resolve_remote_image_url(array $cam): ?string
{
    $url = webcam_validate_url((string)($cam['url'] ?? ''));
    if ($url === null) {
        return null;
    }
    $kind = (string)($cam['kind'] ?? 'iframe');
    if ($kind === 'widget' || webcam_is_widget_frame_url($url)) {
        return webcam_widget_image_url($url);
    }
    if ($kind === 'image') {
        return $url;
    }

    return null;
}

/** Stream the current still frame for image/widget cameras (same-origin proxy). */
function webcam_stream_image(string $camKey): void
{
    $cam = webcam_resolve_camera($camKey);
    if ($cam['off'] || trim((string)$cam['url']) === '' || !webcam_uses_image_tag($cam)) {
        http_response_code(404);
        exit;
    }

    $remote = webcam_resolve_remote_image_url($cam);
    if ($remote === null) {
        http_response_code(502);
        exit;
    }

    $body = webcam_http_get($remote, 20, true);
    if ($body === null) {
        http_response_code(502);
        exit;
    }

    $path = strtolower(parse_url($remote, PHP_URL_PATH) ?: '');
    $type = 'image/jpeg';
    if (preg_match('/\.png(\?|$)/i', $path) === 1) {
        $type = 'image/png';
    } elseif (preg_match('/\.gif(\?|$)/i', $path) === 1) {
        $type = 'image/gif';
    } elseif (preg_match('/\.webp(\?|$)/i', $path) === 1) {
        $type = 'image/webp';
    }

    header('Content-Type: ' . $type);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Length: ' . (string)strlen($body));
    echo $body;
    exit;
}

/** Upgrade legacy GRPM saves to the WMTA live stream iframe URL. */
function webcam_apply_grpm_defaults(array $entry, string $key): array
{
    $key = webcam_normalize_key($key);
    if ($key !== 'grpm') {
        return $entry;
    }
    $defaults = webcam_default_cameras()['grpm'];
    $url = (string)($entry['url'] ?? '');
    $kind = (string)($entry['kind'] ?? '');
    $legacyImageUid = '7c402384eafaef2215a0e9f556797ee8';
    $needsUpgrade = $url === ''
        || str_contains($url, $legacyImageUid)
        || webcam_is_widget_frame_url($url)
        || in_array($kind, ['widget', 'image', 'stream'], true);
    if ($needsUpgrade) {
        return array_merge($entry, [
            'name' => (string)$defaults['name'],
            'url' => (string)$defaults['url'],
            'kind' => (string)$defaults['kind'],
            'attribution' => (string)$defaults['attribution'],
        ]);
    }

    return $entry;
}

/** @return array<string,array<string,mixed>> */
function webcam_registry(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $out = webcam_default_cameras();
    $saved = cfg('webcam.CAMS', []);
    if (is_array($saved)) {
        foreach ($saved as $k => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['off'])) {
                unset($out[webcam_normalize_key((string)$k)]);
                continue;
            }
            $key = webcam_normalize_key((string)($row['_key'] ?? $k));
            if ($key === '') {
                continue;
            }
            $entry = webcam_normalize_entry($row, is_array($out[$key] ?? null) ? $out[$key] : null);
            if ($entry !== null) {
                $out[$key] = webcam_apply_grpm_defaults($entry, $key);
            }
        }
    }

    $legacy = webcam_validate_url((string)cfg('webcam.EMBED_URL', ''));
    if ($legacy !== null && empty($saved)) {
        $out['legacy'] = webcam_normalize_entry([
            'name' => trim((string)cfg('webcam.TITLE', 'Webcam')),
            'url' => $legacy,
            'attribution' => trim((string)cfg('webcam.ATTRIBUTION', '')),
        ]) ?? null;
        if ($out['legacy'] === null) {
            unset($out['legacy']);
        }
    }

    foreach ($out as $key => $entry) {
        if (!is_array($entry) || trim((string)($entry['url'] ?? '')) === '') {
            unset($out[$key]);
        }
    }

    return $cache = $out;
}

/** @return array<string,array<string,mixed>> */
function webcam_registry_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(webcam_registry());
}

/** @return array{key:string,name:string,url:string,kind:string,attribution:string,off:bool} */
function webcam_resolve_camera(?string $camKey = null): array
{
    $registry = webcam_registry();
    if ($registry === []) {
        return [
            'key' => '',
            'name' => 'Not available',
            'url' => '',
            'kind' => 'iframe',
            'attribution' => '',
            'off' => true,
        ];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => webcam_normalize_key((string)$k);
    $resolved = admin_resolve_display_registry_key($registry, (string)($camKey ?? ''), $normalize);
    if ($resolved === null || !isset($registry[$resolved])) {
        return [
            'key' => webcam_normalize_key((string)($camKey ?? '')),
            'name' => 'Not available',
            'url' => '',
            'kind' => 'iframe',
            'attribution' => '',
            'off' => true,
        ];
    }

    $entry = $registry[$resolved];

    return [
        'key' => (string)$resolved,
        'name' => (string)($entry['name'] ?? $resolved),
        'url' => (string)$entry['url'],
        'kind' => (string)($entry['kind'] ?? 'iframe'),
        'attribution' => (string)($entry['attribution'] ?? ''),
        'off' => false,
    ];
}

function webcam_rotation_dwell(string $key, array $entry): int
{
    $kind = (string)($entry['kind'] ?? 'iframe');
    if (in_array($kind, ['image', 'widget'], true)) {
        return 90;
    }
    if ($kind === 'stream') {
        return 120;
    }

    return 120;
}

function webcam_active_key(): string
{
    $fromQuery = webcam_normalize_key((string)($_GET['cam'] ?? ''));
    if ($fromQuery !== '' && $fromQuery !== 'all') {
        return $fromQuery;
    }

    $cam = webcam_resolve_camera($fromQuery !== '' ? $fromQuery : null);

    return (string)($cam['key'] ?? '');
}

/**
 * @return list<array{key:string,name:string,url:string,kind:string,attribution:string}>
 * @deprecated Use webcam_resolve_camera() — one camera per rotation slot.
 */
function webcam_active_cameras(): array
{
    $cam = webcam_resolve_camera((string)($_GET['cam'] ?? ''));
    if ($cam['off'] || trim($cam['url']) === '') {
        return [];
    }

    return [[
        'key' => $cam['key'],
        'name' => $cam['name'],
        'url' => $cam['url'],
        'kind' => $cam['kind'],
        'attribution' => $cam['attribution'],
    ]];
}

/** @deprecated Use webcam_active_cameras() */
function webcam_embed_url(): ?string
{
    $cams = webcam_active_cameras();

    return $cams !== [] ? (string)$cams[0]['url'] : null;
}

function webcam_cam_url(string $key): string
{
    $key = webcam_normalize_key($key);
    if ($key === '' || $key === 'all') {
        $first = array_key_first(webcam_registry());

        return $first !== null ? 'webcam.php?cam=' . rawurlencode((string)$first) : 'webcam.php';
    }

    return 'webcam.php?cam=' . rawurlencode($key);
}

function webcam_cam_label(string $key): string
{
    $key = webcam_normalize_key($key);
    if ($key === '' || $key === 'all') {
        return 'Webcam';
    }
    $entry = webcam_registry()[$key] ?? null;
    if (!is_array($entry)) {
        return 'Webcam — ' . $key;
    }
    $name = trim((string)($entry['name'] ?? ''));

    return 'Webcam — ' . ($name !== '' ? $name : $key);
}

function webcam_status_cache_path(string $url): string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/webcam_probe_' . substr(sha1($url), 0, 16) . '.json';
}

/** @return array{last_ok:?int,last_fail:?int,last_probe:?int} */
function webcam_status_read_cache(string $url): array
{
    $f = webcam_status_cache_path($url);
    if (!is_file($f)) {
        return ['last_ok' => null, 'last_fail' => null, 'last_probe' => null];
    }
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) {
        return ['last_ok' => null, 'last_fail' => null, 'last_probe' => null];
    }

    return [
        'last_ok' => isset($j['last_ok']) ? (int)$j['last_ok'] : null,
        'last_fail' => isset($j['last_fail']) ? (int)$j['last_fail'] : null,
        'last_probe' => isset($j['last_probe']) ? (int)$j['last_probe'] : null,
    ];
}

/** @param array{last_ok:?int,last_fail:?int,last_probe:?int} $data */
function webcam_status_write_cache(string $url, array $data): void
{
    @file_put_contents(webcam_status_cache_path($url), json_encode([
        'last_ok' => $data['last_ok'],
        'last_fail' => $data['last_fail'],
        'last_probe' => $data['last_probe'],
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function webcam_probe_url(string $url, string $kind = 'iframe'): bool
{
    $url = webcam_validate_url($url);
    if ($url === null || !function_exists('curl_init')) {
        return false;
    }
    if ($kind === 'widget' || webcam_is_widget_frame_url($url)) {
        $img = webcam_widget_image_url($url);

        return $img !== null && webcam_probe_url($img, 'image');
    }
    if ($kind === 'stream' || webcam_is_stream_frame_url($url)) {
        return webcam_stream_playlist_url($url) !== null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => $kind === 'image',
        CURLOPT_NOBODY => $kind !== 'image',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HomeSignage/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($kind === 'image') {
        return $err === '' && $code >= 200 && $code < 400 && is_string($body) && $body !== '';
    }

    return $err === '' && $code >= 200 && $code < 400;
}

/**
 * @return array{online:bool,skip_rotation:bool,embed_configured:bool}
 */
function webcam_url_status(string $url, string $kind = 'iframe'): array
{
    $url = webcam_validate_url($url);
    if ($url === null) {
        return [
            'online' => false,
            'skip_rotation' => true,
            'embed_configured' => false,
        ];
    }

    $cache = webcam_status_read_cache($url);
    $now = time();
    $needsProbe = ($cache['last_probe'] ?? null) === null
        || ($now - (int)$cache['last_probe']) >= WEBCAM_PROBE_TTL_SEC;

    if ($needsProbe) {
        $ok = webcam_probe_url($url, $kind);
        if ($ok) {
            $cache['last_ok'] = $now;
        } else {
            $cache['last_fail'] = $now;
        }
        $cache['last_probe'] = $now;
        webcam_status_write_cache($url, $cache);
    }

    $lastOk = $cache['last_ok'];
    $lastFail = $cache['last_fail'];
    $okAgeMin = $lastOk ? (int)round(($now - $lastOk) / 60) : null;
    $online = $okAgeMin !== null && $okAgeMin < WEBCAM_ONLINE_MAX_AGE_MIN;
    $skipRotation = !$online && (
        ($lastOk === null && $lastFail !== null && ($now - $lastFail) / 60 >= WEBCAM_SKIP_MIN_AGE_MIN)
        || ($okAgeMin !== null && $okAgeMin >= WEBCAM_SKIP_MIN_AGE_MIN)
    );

    return [
        'online' => $online,
        'skip_rotation' => $skipRotation,
        'embed_configured' => true,
    ];
}

function webcam_parse_cam_from_rotation_url(string $url): string
{
    if (preg_match('/[?&]cam=([^&#]+)/i', $url, $m)) {
        $key = webcam_normalize_key(rawurldecode($m[1]));
        if ($key === 'all') {
            $key = (string)(array_key_first(webcam_registry()) ?? '');
        }

        return $key;
    }

    return (string)(array_key_first(webcam_registry()) ?? '');
}

function webcam_skip_rotation(?string $rotationUrl = null): bool
{
    $pick = $rotationUrl !== null
        ? webcam_parse_cam_from_rotation_url($rotationUrl)
        : (string)(array_key_first(webcam_registry()) ?? '');
    if ($pick === '') {
        return true;
    }

    $entry = webcam_registry()[$pick] ?? null;
    if (!is_array($entry)) {
        return true;
    }

    return webcam_url_status((string)$entry['url'], (string)($entry['kind'] ?? 'iframe'))['skip_rotation'];
}

/** @deprecated */
function webcam_embed_status(): array
{
    $cams = webcam_active_cameras();
    if ($cams === []) {
        return [
            'online' => false,
            'skip_rotation' => true,
            'embed_configured' => false,
        ];
    }

    return webcam_url_status((string)$cams[0]['url'], (string)$cams[0]['kind']);
}
