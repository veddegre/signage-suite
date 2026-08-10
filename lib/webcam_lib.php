<?php
/**
 * Webcam board — multi-camera registry, embed validation, probe cache, rotation skip.
 */

require_once __DIR__ . '/../config.php';

const WEBCAM_PROBE_TTL_DEFAULT_SEC = 1800;
const WEBCAM_PROBE_TTL_MIN_SEC = 60;
const WEBCAM_PROBE_TTL_MAX_SEC = 86400;
const WEBCAM_ONLINE_MAX_AGE_MIN = 60;

function webcam_probe_ttl_sec(): int
{
    static $ttl = null;
    if ($ttl !== null) {
        return $ttl;
    }
    $raw = getenv('SIGNAGE_WEBCAM_PROBE_TTL_SEC');
    if ($raw !== false && $raw !== '') {
        $v = (int)$raw;
    } else {
        $v = (int)cfg('webcam.PROBE_TTL_SEC', WEBCAM_PROBE_TTL_DEFAULT_SEC);
    }
    if ($v <= 0) {
        $v = WEBCAM_PROBE_TTL_DEFAULT_SEC;
    }

    return $ttl = max(WEBCAM_PROBE_TTL_MIN_SEC, min(WEBCAM_PROBE_TTL_MAX_SEC, $v));
}

/** Camera keys removed from the registry, rotation quick-add, and playlists. */
function webcam_retired_keys(): array
{
    return ['gvsu'];
}

function webcam_is_retired_key(string $key): bool
{
    return in_array(webcam_normalize_key($key), webcam_retired_keys(), true);
}

/** Whether a rotation playlist URL targets a retired webcam slot. */
function webcam_rotation_url_is_retired(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('/[?&]cam=([^&#]+)/i', $url, $m)) {
        return false;
    }

    return webcam_is_retired_key(rawurldecode($m[1]));
}

/** @return array<string,array<string,mixed>> Built-in cameras (always available; overridden by saved CAMS rows). */
function webcam_default_cameras(): array
{
    return [
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
        'anaheim' => [
            'name' => 'Anaheim · Disneyland Area',
            'url' => 'https://www.earthcam.com/usa/california/anaheim/?cam=anaheim',
            'kind' => 'stream',
            'attribution' => 'EarthCam · Hilton Anaheim',
        ],
        'muskegon' => [
            'name' => 'Muskegon Surf Cam',
            'url' => 'https://stream.muskegonsurfcam.com/substream/stream.m3u8',
            'kind' => 'stream',
            'attribution' => 'Muskegon Surf Cam · Pere Marquette Beach',
        ],
    ];
}

function webcam_is_builtin_key(string $key): bool
{
    return isset(webcam_default_cameras()[webcam_normalize_key($key)]);
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
    if ($kind === 'iframe' && webcam_is_ant_media_play_url($url)) {
        $kind = 'stream';
    }
    $attribution = trim((string)($row['attribution'] ?? ($fallback['attribution'] ?? '')));

    return [
        'name' => $name,
        'url' => $url,
        'kind' => $kind,
        'attribution' => $attribution,
    ];
}

function webcam_is_earthcam_share_url(string $url): bool
{
    return preg_match('~^https://share\.earthcam\.net/[^/?#]+~i', trim($url)) === 1;
}

/** Public earthcam.com cam page (not share.earthcam.net). */
function webcam_is_earthcam_dotcom_page_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!preg_match('#(^|\.)earthcam\.com$#', $host)) {
        return false;
    }
    if (preg_match('~[?&]cam=~i', (string)($parts['query'] ?? ''))) {
        return true;
    }

    return preg_match('#^/(usa|world)/#i', (string)($parts['path'] ?? '')) === 1;
}

/** Signed HLS playlist URL from an earthcam.com cam page (token refreshed on each fetch). */
function webcam_earthcam_dotcom_stream_url(string $pageUrl): ?string
{
    if (!webcam_is_earthcam_dotcom_page_url($pageUrl)) {
        return null;
    }
    $pageUrl = webcam_validate_url($pageUrl);
    if ($pageUrl === null) {
        return null;
    }
    $html = webcam_http_get($pageUrl, 15, true, $pageUrl);
    if ($html === null) {
        return null;
    }
    if (!preg_match('/"stream"\s*:\s*"([^"]+)"/', $html, $m)) {
        return null;
    }
    $stream = json_decode('"' . $m[1] . '"');
    if (!is_string($stream) || $stream === '') {
        $stream = str_replace('\/', '/', $m[1]);
    }

    return webcam_validate_url($stream);
}

/** Client token from a share.earthcam.net viewer URL (includes trailing punctuation such as !). */
function webcam_earthcam_share_token(string $url): ?string
{
    $url = trim($url);
    if (!webcam_is_earthcam_share_url($url)) {
        return null;
    }
    if (!preg_match('~^https://share\.earthcam\.net/([^/?#]+)~i', $url, $m)) {
        return null;
    }
    $token = trim(rawurldecode($m[1]));

    return $token !== '' ? $token : null;
}

/** @return array<string,mixed>|null */
function webcam_earthcam_api_json(string $shareUrl): ?array
{
    $token = webcam_earthcam_share_token($shareUrl);
    if ($token === null) {
        return null;
    }
    static $mem = [];
    $cacheKey = md5($token);
    $ttl = 45;
    if (isset($mem[$cacheKey]) && (time() - $mem[$cacheKey]['t']) < $ttl) {
        return $mem[$cacheKey]['j'];
    }
    $apiUrl = 'https://share.earthcam.net/api/' . $token;
    if (!preg_match('#^https://share\.earthcam\.net/api/[a-zA-Z0-9!._-]+$#', $apiUrl)) {
        return null;
    }
    $body = webcam_http_get($apiUrl, 12, true, 'https://share.earthcam.net/', 'application/json');
    if ($body === null) {
        return null;
    }
    $j = json_decode($body, true);
    if (!is_array($j)) {
        return null;
    }
    $mem[$cacheKey] = ['t' => time(), 'j' => $j];

    return $j;
}

function webcam_earthcam_monitor_image_url(string $shareUrl): ?string
{
    $j = webcam_earthcam_api_json($shareUrl);
    if ($j === null) {
        return null;
    }
    $candidates = [];
    $clientMon = $j['client']['monitor']['image'] ?? $j['client']['image'] ?? null;
    if (is_string($clientMon) && $clientMon !== '') {
        $candidates[] = $clientMon;
    }
    foreach (($j['projects'] ?? []) as $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach (($project['servers'] ?? []) as $server) {
            if (!is_array($server)) {
                continue;
            }
            $img = $server['monitor']['image'] ?? $server['image'] ?? null;
            if (is_string($img) && $img !== '') {
                $candidates[] = $img;
            }
        }
    }
    foreach ($candidates as $img) {
        $valid = webcam_validate_url($img);
        if ($valid !== null) {
            return $valid;
        }
    }

    return null;
}

/** Safari / WebKit (not Chromium) — EarthCam first paint often shows bmp404 until a reload. */
function webcam_request_is_safari_webkit(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return false;
    }
    if (preg_match('/Chrome|Chromium|CriOS|Edg|OPR|SamsungBrowser/i', $ua)) {
        return false;
    }

    return preg_match('/Safari|AppleWebKit/i', $ua) === 1;
}

/** @param array<string,mixed> $cam */
function webcam_earthcam_iframe_warmup(array $cam): bool
{
    if (webcam_uses_image_tag($cam) || webcam_uses_stream_tag($cam)) {
        return false;
    }

    return webcam_is_earthcam_share_url((string)($cam['url'] ?? ''))
        && webcam_request_is_safari_webkit();
}

function webcam_detect_kind(string $url): string
{
    if (webcam_is_ant_media_play_url($url)) {
        return 'stream';
    }
    if (webcam_is_earthcam_dotcom_page_url($url)) {
        return 'stream';
    }
    if (webcam_is_stream_frame_url($url)) {
        return 'stream';
    }
    if (webcam_is_widget_frame_url($url)) {
        return 'widget';
    }
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
    if (preg_match('/\.m3u8(\?|$)/i', $path) === 1) {
        return 'stream';
    }
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

/** WetMet signed HLS is browser-only; signage embeds their frame player instead of proxying HLS. */
function webcam_wetmet_stream_frame_url(string $url): bool
{
    return webcam_is_stream_frame_url($url);
}

/** @param array<string,mixed> $cam */
function webcam_board_is_available(array $cam): bool
{
    if (!empty($cam['off']) || trim((string)($cam['url'] ?? '')) === '') {
        return false;
    }
    $url = (string)($cam['url'] ?? '');
    $kind = (string)($cam['kind'] ?? 'iframe');
    if (webcam_wetmet_stream_frame_url($url) || webcam_uses_stream_tag($cam)) {
        return webcam_url_status($url, $kind, true)['online'];
    }
    if ($kind === 'widget' || webcam_is_widget_frame_url($url) || $kind === 'image') {
        return webcam_url_status($url, $kind, true)['online'];
    }

    return true;
}

/** @param array<string,mixed> $cam */
function webcam_stream_prefers_iframe_embed(array $cam): bool
{
    return webcam_wetmet_stream_frame_url((string)($cam['url'] ?? ''));
}

function webcam_is_ant_media_play_url(string $url): bool
{
    return preg_match('~/live/play\.html(?:[?#]|$)~i', $url) === 1
        && preg_match('~[?&]id=([^&#]+)~', $url) === 1;
}

function webcam_ant_media_stream_id(string $url): ?string
{
    if (!preg_match('~[?&]id=([^&#]+)~', $url, $m)) {
        return null;
    }
    $id = trim(rawurldecode($m[1]));

    return $id !== '' ? $id : null;
}

/** Ant Media Server adaptive HLS master playlist for a play.html URL. */
function webcam_ant_media_hls_master_url(string $playUrl): ?string
{
    $playUrl = webcam_validate_url($playUrl);
    if ($playUrl === null) {
        return null;
    }
    $id = webcam_ant_media_stream_id($playUrl);
    if ($id === null) {
        return null;
    }
    $parts = parse_url($playUrl);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = (string)($parts['scheme'] ?? 'https');
    $host = (string)($parts['host'] ?? '');
    if ($host === '') {
        return null;
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return webcam_validate_url($scheme . '://' . $host . $port . '/live/streams/' . rawurlencode($id) . '_adaptive.m3u8');
}

/** True when an HLS media playlist looks like a live stream (not a stale ENDLIST snapshot). */
function webcam_hls_body_is_playlist(string $body): bool
{
    if ($body === '' || str_contains($body, '<html') || str_contains($body, '<HTML')) {
        return false;
    }

    return str_contains($body, '#EXTM3U');
}

function webcam_hls_playlist_is_live(string $body): bool
{
    if (!webcam_hls_body_is_playlist($body) || str_contains($body, '#EXT-X-ENDLIST')) {
        return false;
    }
    if (preg_match('#EXT-X-PROGRAM-DATE-TIME:([^\n]+)#', $body, $m)) {
        $ts = strtotime(trim($m[1]));
        if ($ts !== false && (time() - $ts) > 120) {
            return false;
        }
    }

    return str_contains($body, '#EXTINF');
}

function webcam_hls_last_segment_url(string $body, string $playlistUrl): ?string
{
    if (!webcam_hls_body_is_playlist($body)) {
        return null;
    }
    $base = preg_replace('#/[^/]*$#', '/', $playlistUrl) ?? $playlistUrl;
    $last = null;
    foreach (explode("\n", str_replace("\r", '', $body)) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            continue;
        }
        $last = webcam_hls_absolute_url($base, $trim);
    }

    return $last;
}

function webcam_hls_segment_reachable(string $segmentUrl): bool
{
    $segmentUrl = webcam_validate_url($segmentUrl);
    if ($segmentUrl === null || !function_exists('curl_init')) {
        return false;
    }
    $ctx = webcam_http_context($segmentUrl);
    $headers = ['Range: bytes=0-4095'];
    if ($ctx['referer'] !== null && $ctx['referer'] !== '') {
        $headers[] = 'Referer: ' . $ctx['referer'];
    }
    require_once __DIR__ . '/security_lib.php';
    $ch = curl_init($segmentUrl);
    curl_setopt_array($ch, signage_curl_merge_options([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $ctx['ua'],
        CURLOPT_HTTPHEADER => $headers,
    ]));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);

    return $err === ''
        && ($code === 200 || $code === 206)
        && is_string($body)
        && strlen($body) >= 512;
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

function webcam_is_wetmet_host(string $host): bool
{
    return preg_match('#(^|\.)wetmet\.net$#', strtolower($host)) === 1;
}

/** @return array{ua:string,referer:?string} */
function webcam_http_context(string $url, ?string $referer = null): array
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $ua = 'HomeSignage/1.0';
    if (webcam_is_wetmet_host($host)) {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        if ($referer === null) {
            $referer = 'https://api.wetmet.net/';
        }
    } elseif ($referer === null && preg_match('#(^|\.)earthcam\.com$#', $host)) {
        $referer = 'https://www.earthcam.com/';
    } elseif ($referer === null && str_contains($host, 'earthcam.net')) {
        $referer = 'https://share.earthcam.net/';
    } elseif ($referer === null && preg_match('#(^|\.)muskegonsurfcam\.com$#', $host)) {
        $referer = 'https://muskegonsurfcam.com/';
    }

    return ['ua' => $ua, 'referer' => $referer];
}

function webcam_http_get(string $url, int $timeout = 12, bool $noCache = false, ?string $referer = null, string $accept = '*/*'): ?string
{
    $url = webcam_validate_url($url);
    if ($url === null || !function_exists('curl_init')) {
        return null;
    }
    $ctx = webcam_http_context($url, $referer);
    $headers = ['Accept: ' . $accept];
    if ($noCache) {
        $headers[] = 'Cache-Control: no-cache, no-store';
        $headers[] = 'Pragma: no-cache';
    }
    if ($ctx['referer'] !== null && $ctx['referer'] !== '') {
        $headers[] = 'Referer: ' . $ctx['referer'];
    }
    $ch = curl_init($url);
    require_once __DIR__ . '/security_lib.php';
    curl_setopt_array($ch, signage_curl_merge_options([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $ctx['ua'],
        CURLOPT_HTTPHEADER => $headers,
    ]));
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
    if (preg_match('#\.m3u8(\?|$)#i', $streamFrameUrl)) {
        return webcam_validate_url($streamFrameUrl);
    }
    if (webcam_is_ant_media_play_url($streamFrameUrl)) {
        return webcam_ant_media_hls_master_url($streamFrameUrl);
    }
    if (webcam_is_earthcam_dotcom_page_url($streamFrameUrl)) {
        return webcam_earthcam_dotcom_stream_url($streamFrameUrl);
    }
    $html = webcam_http_get($streamFrameUrl);
    if ($html === null) {
        return null;
    }
    if (preg_match("#var vurl = '([^']+)'#", $html, $m)
        || preg_match('#var vurl = "([^"]+)"#', $html, $m)) {
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
        || preg_match('#(^|\.)gvsu\.edu$#', $host) === 1
        || preg_match('#(^|\.)earthcam\.com$#', $host) === 1
        || preg_match('#(^|\.)earthcam\.net$#', $host) === 1
        || preg_match('#(^|\.)muskegonsurfcam\.com$#', $host) === 1
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

function webcam_hls_is_media_playlist(string $body): bool
{
    return str_contains($body, '#EXTINF:');
}

function webcam_hls_pick_media_playlist(string $masterUrl, string $masterBody): ?string
{
    if (!webcam_hls_body_is_playlist($masterBody)) {
        return null;
    }
    if (webcam_hls_is_media_playlist($masterBody)) {
        return null;
    }
    $base = preg_replace('#/[^/]*$#', '/', $masterUrl) ?? $masterUrl;
    $picked = null;
    foreach (explode("\n", str_replace("\r", '', $masterBody)) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) {
            continue;
        }
        $candidate = webcam_hls_absolute_url($base, $trim);
        if ($candidate !== null && preg_match('#\.m3u8(\?|$)#i', $candidate)) {
            $picked = $candidate;
        }
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
        if (!webcam_hls_playlist_is_live($masterBody)) {
            return null;
        }

        return webcam_hls_rewrite_playlist($masterBody, $masterUrl, (string)$cam['key']);
    }
    $mediaBody = webcam_http_get($mediaUrl);
    if ($mediaBody === null) {
        return null;
    }
    if (!webcam_hls_playlist_is_live($mediaBody)) {
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
    if ($cam['off'] || trim((string)$cam['url']) === '') {
        echo json_encode(['ok' => false], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (isset($_GET['wetmet']) && (string)$_GET['wetmet'] === '1'
        && webcam_stream_prefers_iframe_embed($cam)) {
        $playlist = webcam_stream_playlist_url((string)$cam['url']);
        $live = $playlist !== null && webcam_probe_hls_playlist_live($playlist);
        echo json_encode([
            'ok' => $live,
            'playlist' => $live ? $playlist : null,
            'direct' => true,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!webcam_uses_stream_tag($cam)) {
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
    if (webcam_is_earthcam_share_url($url)) {
        return webcam_earthcam_monitor_image_url($url);
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

/** earthcam.com cam pages use proxied HLS (signed playlist URL parsed from the page). */
function webcam_apply_earthcam_dotcom_defaults(array $entry, string $key): array
{
    $url = (string)($entry['url'] ?? '');
    if (!webcam_is_earthcam_dotcom_page_url($url)) {
        return $entry;
    }
    $kind = (string)($entry['kind'] ?? '');
    if ($kind === '' || $kind === 'auto' || $kind === 'iframe') {
        $entry['kind'] = 'stream';
    }

    return $entry;
}

/** EarthCam share links use the live iframe embed (not proxied stills). */
function webcam_apply_earthcam_iframe_defaults(array $entry, string $key): array
{
    $url = (string)($entry['url'] ?? '');
    if (!webcam_is_earthcam_share_url($url)) {
        return $entry;
    }
    if ((string)($entry['kind'] ?? '') === 'image') {
        $entry['kind'] = 'iframe';
    }

    return $entry;
}

/** Upgrade legacy GRPM saves to the WMTA live WetMet iframe (browser-side player). */
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
    foreach (array_keys($out) as $key) {
        if (webcam_is_retired_key((string)$key)) {
            unset($out[$key]);
        }
    }
    $saved = cfg('webcam.CAMS', []);
    if (is_array($saved)) {
        foreach ($saved as $k => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = webcam_normalize_key((string)($row['_key'] ?? $k));
            if ($key === '' || webcam_is_retired_key($key)) {
                continue;
            }
            if (!empty($row['off'])) {
                unset($out[$key]);
                continue;
            }
            $entry = webcam_normalize_entry($row, is_array($out[$key] ?? null) ? $out[$key] : null);
            if ($entry !== null) {
                $entry = webcam_apply_grpm_defaults($entry, $key);
                $entry = webcam_apply_earthcam_dotcom_defaults($entry, $key);
                $out[$key] = webcam_apply_earthcam_iframe_defaults($entry, $key);
            }
        }
    }

    foreach ($out as $key => $entry) {
        if (webcam_is_retired_key((string)$key)
            || !is_array($entry)
            || trim((string)($entry['url'] ?? '')) === '') {
            unset($out[$key]);
        }
    }

    foreach ($out as $key => $entry) {
        if (is_array($entry)) {
            $entry = webcam_apply_earthcam_dotcom_defaults($entry, (string)$key);
            $out[$key] = webcam_apply_earthcam_iframe_defaults($entry, (string)$key);
        }
    }

    $out = webcam_apply_builtin_operator_access($out);

    return $cache = $out;
}

/**
 * Built-in camera feeds are shared with display operators — schema documents them
 * as always available for rotation quick-add and kiosk use (even when a super
 * admin saved an override row with an owner).
 *
 * @param array<string,array<string,mixed>> $registry
 * @return array<string,array<string,mixed>>
 */
function webcam_apply_builtin_operator_access(array $registry): array
{
    $defaults = webcam_default_cameras();
    foreach ($registry as $key => $entry) {
        if (!is_array($entry) || !isset($defaults[$key]) || !empty($entry['off'])) {
            continue;
        }
        $roles = $entry['shared_roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        foreach (['operator'] as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        sort($roles);
        $entry['shared_roles'] = $roles;
        $registry[$key] = $entry;
    }

    return $registry;
}

/**
 * Rows for the Webcam admin Cameras table — merged registry, including built-ins
 * operators may add to rotation even when they are not stored under webcam.CAMS.
 *
 * @return list<array<string,mixed>>
 */
function webcam_admin_cams_rows(): array
{
    require_once __DIR__ . '/users_lib.php';

    $rows = [];
    foreach (webcam_registry() as $key => $entry) {
        if (!is_array($entry) || trim((string)($entry['url'] ?? '')) === '' || !empty($entry['off'])) {
            continue;
        }
        if (!admin_is_super() && !admin_entry_visible($entry)) {
            continue;
        }
        $row = ['_key' => $key] + $entry;
        if (webcam_is_builtin_key($key)) {
            $row['_builtin'] = true;
        }
        if (!admin_is_super() && admin_entry_owner($entry) !== admin_user_id()) {
            $row['_readonly'] = true;
        }
        $rows[] = $row;
    }
    usort($rows, static fn($a, $b) => strcmp((string)($a['_key'] ?? ''), (string)($b['_key'] ?? '')));

    return $rows;
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

/** Bump when probe logic changes — invalidates stale cache entries on deploy. */
const WEBCAM_PROBE_CACHE_VER = 5;

function webcam_status_cache_path(string $url): string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/webcam_probe_v' . WEBCAM_PROBE_CACHE_VER . '_' . substr(sha1($url), 0, 16) . '.json';
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

function webcam_probe_uses_hls(string $url, string $kind): bool
{
    if (webcam_is_stream_frame_url($url)) {
        return true;
    }
    if (webcam_is_earthcam_dotcom_page_url($url)) {
        return true;
    }
    if ($kind === 'stream' || webcam_is_ant_media_play_url($url)) {
        return true;
    }

    return false;
}

function webcam_probe_hls_playlist_live(string $pageOrPlaylistUrl): bool
{
    $master = webcam_stream_playlist_url($pageOrPlaylistUrl);
    if ($master === null) {
        return false;
    }
    $masterBody = webcam_http_get($master);
    if ($masterBody === null || !webcam_hls_body_is_playlist($masterBody)) {
        return false;
    }
    $mediaUrl = webcam_hls_pick_media_playlist($master, $masterBody);
    $mediaBody = $mediaUrl !== null ? webcam_http_get($mediaUrl) : $masterBody;
    if ($mediaBody === null || !webcam_hls_playlist_is_live($mediaBody)) {
        return false;
    }
    $segmentUrl = webcam_hls_last_segment_url($mediaBody, $mediaUrl ?? $master);
    if ($segmentUrl === null) {
        return false;
    }

    return webcam_hls_segment_reachable($segmentUrl);
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
    if (webcam_probe_uses_hls($url, $kind)) {
        return webcam_probe_hls_playlist_live($url);
    }
    $ctx = webcam_http_context($url);
    $probeHeaders = [];
    if ($ctx['referer'] !== null && $ctx['referer'] !== '') {
        $probeHeaders[] = 'Referer: ' . $ctx['referer'];
    }
    require_once __DIR__ . '/security_lib.php';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => $kind === 'image',
        CURLOPT_NOBODY => $kind !== 'image',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $ctx['ua'],
    ];
    if ($probeHeaders !== []) {
        $opts[CURLOPT_HTTPHEADER] = $probeHeaders;
    }
    curl_setopt_array($ch, signage_curl_merge_options($opts));
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
function webcam_url_status(string $url, string $kind = 'iframe', bool $forceProbe = false): array
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
    $needsProbe = $forceProbe
        || ($cache['last_probe'] ?? null) === null
        || ($now - (int)$cache['last_probe']) >= webcam_probe_ttl_sec();
    // Don't hold skip/offline for the full TTL when last success is already stale.
    if (!$needsProbe && ($cache['last_ok'] ?? null) !== null) {
        $okAgeMin = (int)round(($now - (int)$cache['last_ok']) / 60);
        if ($okAgeMin >= WEBCAM_ONLINE_MAX_AGE_MIN) {
            $needsProbe = true;
        }
    }

    if ($needsProbe) {
        $ok = webcam_probe_url($url, $kind);
        if ($ok) {
            $cache['last_ok'] = $now;
            $cache['last_fail'] = null;
        } else {
            $cache['last_fail'] = $now;
        }
        $cache['last_probe'] = $now;
        webcam_status_write_cache($url, $cache);
    }

    $lastOk = $cache['last_ok'];
    $lastFail = $cache['last_fail'];
    $lastProbe = $cache['last_probe'];
    $probeAgeSec = $lastProbe !== null ? $now - (int)$lastProbe : PHP_INT_MAX;
    $lastProbeOk = $lastOk !== null && ($lastFail === null || $lastOk > $lastFail);
    $online = $probeAgeSec < webcam_probe_ttl_sec() && $lastProbeOk;
    $skipRotation = !$online;

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
    if ($rotationUrl !== null && webcam_rotation_url_is_retired($rotationUrl)) {
        return true;
    }
    $pick = $rotationUrl !== null
        ? webcam_parse_cam_from_rotation_url($rotationUrl)
        : (string)(array_key_first(webcam_registry()) ?? '');
    if ($pick === '' || webcam_is_retired_key($pick)) {
        return true;
    }

    $entry = webcam_registry()[$pick] ?? null;
    if (!is_array($entry)) {
        return true;
    }

    return webcam_url_status((string)$entry['url'], (string)($entry['kind'] ?? 'iframe'), true)['skip_rotation'];
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
