<?php
/**
 * TomTom Traffic Flow — shared tile fetch + diagnostics for traffic.php / admin.
 * Supports legacy Maps API (v4) and Orbis raster flow tiles (new developer keys).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

function traffic_api_key(): ?string
{
    $k = trim((string)cfg('traffic.TOMTOM_API_KEY', ''));
    if ($k === '' || $k === 'PUT-YOUR-TOMTOM-KEY-HERE') {
        return null;
    }
    return $k;
}

function traffic_flow_styles(): array
{
    return ['relative0-dark', 'relative0', 'relative', 'absolute'];
}

function traffic_api_modes(): array
{
    return ['auto', 'legacy', 'orbis'];
}

function traffic_api_mode_setting(): string
{
    $m = (string)cfg('traffic.TOMTOM_API', 'auto');
    return in_array($m, traffic_api_modes(), true) ? $m : 'auto';
}

function traffic_cache_dir(): string
{
    return __DIR__ . '/cache/traffic_tiles';
}

function traffic_last_error(): ?string
{
    $f = traffic_cache_dir() . '/last_error.txt';
    if (!is_file($f)) {
        return null;
    }
    $t = trim((string)file_get_contents($f));
    return $t !== '' ? $t : null;
}

function traffic_cached_api_mode(): ?string
{
    $f = traffic_cache_dir() . '/api_mode.txt';
    if (!is_file($f)) {
        return null;
    }
    $m = trim((string)file_get_contents($f));
    return in_array($m, ['legacy', 'orbis'], true) ? $m : null;
}

function traffic_set_cached_api_mode(string $mode): void
{
    if (!in_array($mode, ['legacy', 'orbis'], true)) {
        return;
    }
    if (!is_dir(traffic_cache_dir())) {
        @mkdir(traffic_cache_dir(), 0775, true);
    }
    @file_put_contents(traffic_cache_dir() . '/api_mode.txt', $mode, LOCK_EX);
}

function traffic_normalize_flow_style(string $style): string
{
    return in_array($style, traffic_flow_styles(), true) ? $style : 'relative0-dark';
}

function traffic_orbis_style(string $flowStyle): string
{
    $flowStyle = traffic_normalize_flow_style($flowStyle);
    return in_array($flowStyle, ['relative0-dark', 'absolute', 'relative'], true) ? 'dark' : 'light';
}

function traffic_legacy_tile_url(string $style, int $z, int $x, int $y, ?string $key = null): ?string
{
    $key ??= traffic_api_key();
    if ($key === null) {
        return null;
    }
    $style = traffic_normalize_flow_style($style);
    return sprintf(
        'https://api.tomtom.com/traffic/map/4/tile/flow/%s/%d/%d/%d.png?key=%s&thickness=5',
        $style,
        $z,
        $x,
        $y,
        rawurlencode($key)
    );
}

function traffic_orbis_tile_url(string $style, int $z, int $x, int $y, ?string $key = null): ?string
{
    $key ??= traffic_api_key();
    if ($key === null) {
        return null;
    }
    $orbisStyle = traffic_orbis_style($style);
    return sprintf(
        'https://api.tomtom.com/maps/orbis/traffic/tile/flow/%d/%d/%d.png?apiVersion=1&key=%s&style=%s',
        $z,
        $x,
        $y,
        rawurlencode($key),
        rawurlencode($orbisStyle)
    );
}

/** @deprecated Use traffic_legacy_tile_url / traffic_orbis_tile_url */
function traffic_tile_url(string $style, int $z, int $x, int $y, ?string $key = null): ?string
{
    $mode = traffic_cached_api_mode() ?? 'legacy';
    return $mode === 'orbis'
        ? traffic_orbis_tile_url($style, $z, $x, $y, $key)
        : traffic_legacy_tile_url($style, $z, $x, $y, $key);
}

/**
 * @return array{data:?string,http:int,err:string}
 */
function traffic_http_get(string $url): array
{
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
        return [
            'data' => is_string($body) ? $body : null,
            'http' => $httpCode,
            'err' => $fetchErr,
        ];
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 12,
        'ignore_errors' => true,
        'header' => "Accept: image/png,*/*\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : null;
    if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $httpCode = (int)$m[1];
    }
    return [
        'data' => is_string($body) ? $body : null,
        'http' => $httpCode,
        'err' => '',
    ];
}

function traffic_png_ok(?string $data): bool
{
    return is_string($data)
        && str_starts_with($data, "\x89PNG\r\n\x1a\n")
        && strlen($data) > 100;
}

/**
 * @return array{ok:bool,http:int,bytes:int,error:?string,detail:?string,api:?string,data:?string}
 */
function traffic_try_url(string $url, string $api): array
{
    $policy = signage_fetch_url_allowed($url);
    if (!$policy['ok']) {
        return [
            'ok' => false,
            'http' => 0,
            'bytes' => 0,
            'error' => 'Outbound fetch blocked',
            'detail' => $policy['error'] ?? null,
            'api' => $api,
            'data' => null,
        ];
    }

    $resp = traffic_http_get($url);
    if ($resp['err'] !== '') {
        return [
            'ok' => false,
            'http' => $resp['http'],
            'bytes' => is_string($resp['data']) ? strlen($resp['data']) : 0,
            'error' => 'Network error',
            'detail' => $resp['err'],
            'api' => $api,
            'data' => null,
        ];
    }

    if (traffic_png_ok($resp['data'])) {
        return [
            'ok' => true,
            'http' => $resp['http'] ?: 200,
            'bytes' => strlen($resp['data']),
            'error' => null,
            'detail' => null,
            'api' => $api,
            'data' => $resp['data'],
        ];
    }

    $snippet = is_string($resp['data']) ? substr(preg_replace('/\s+/', ' ', $resp['data']), 0, 220) : '';
    return [
        'ok' => false,
        'http' => $resp['http'],
        'bytes' => is_string($resp['data']) ? strlen($resp['data']) : 0,
        'error' => 'TomTom did not return a PNG',
        'detail' => $snippet !== '' ? $snippet : null,
        'api' => $api,
        'data' => null,
    ];
}

function traffic_modes_to_try(): array
{
    $setting = traffic_api_mode_setting();
    if ($setting === 'legacy') {
        return ['legacy'];
    }
    if ($setting === 'orbis') {
        return ['orbis'];
    }

    $modes = [];
    $cached = traffic_cached_api_mode();
    if ($cached !== null) {
        $modes[] = $cached;
    }
    foreach (['orbis', 'legacy'] as $mode) {
        if (!in_array($mode, $modes, true)) {
            $modes[] = $mode;
        }
    }
    return $modes;
}

function traffic_log_error(array $attempts): void
{
    if (!is_dir(traffic_cache_dir())) {
        @mkdir(traffic_cache_dir(), 0775, true);
    }
    $parts = [];
    foreach ($attempts as $a) {
        $parts[] = ($a['api'] ?? '?') . ' HTTP ' . (int)($a['http'] ?? 0)
            . ($a['detail'] ?? '' ? ' ' . $a['detail'] : '');
    }
    @file_put_contents(traffic_cache_dir() . '/last_error.txt', date('c') . ' ' . implode(' | ', $parts) . "\n", LOCK_EX);
}

/**
 * Fetch one TomTom flow tile (no disk cache).
 * @return array{ok:bool,http:int,bytes:int,error:?string,detail:?string,api:?string,data:?string}
 */
function traffic_fetch_tile(string $style, int $z, int $x, int $y): array
{
    if (traffic_api_key() === null) {
        return [
            'ok' => false,
            'http' => 0,
            'bytes' => 0,
            'error' => 'API key not configured',
            'detail' => null,
            'api' => null,
            'data' => null,
        ];
    }

    $style = traffic_normalize_flow_style($style);
    $attempts = [];
    foreach (traffic_modes_to_try() as $mode) {
        $url = $mode === 'orbis'
            ? traffic_orbis_tile_url($style, $z, $x, $y)
            : traffic_legacy_tile_url($style, $z, $x, $y);
        if ($url === null) {
            continue;
        }
        $result = traffic_try_url($url, $mode);
        if ($result['ok']) {
            traffic_set_cached_api_mode($mode);
            @unlink(traffic_cache_dir() . '/last_error.txt');
            return $result;
        }
        $attempts[] = $result;
    }

    traffic_log_error($attempts);
    $last = $attempts !== [] ? $attempts[count($attempts) - 1] : null;
    $hint = $last['detail'] ?? null;
    if (($last['http'] ?? 0) === 403) {
        $hint = 'HTTP 403 on both legacy and Orbis tile APIs — confirm Traffic Flow API is enabled on the key '
            . 'and domain whitelisting is off. New TomTom keys usually need the Orbis endpoint (auto mode tries both).';
    } elseif ($hint === null && count($attempts) > 1) {
        $hint = 'Tried Orbis and legacy tile APIs — neither returned a PNG.';
    }

    return [
        'ok' => false,
        'http' => (int)($last['http'] ?? 0),
        'bytes' => (int)($last['bytes'] ?? 0),
        'error' => $last['error'] ?? 'TomTom tile fetch failed',
        'detail' => $hint,
        'api' => $last['api'] ?? null,
        'data' => null,
    ];
}

/** Test tile for the default Allendale / Grand Rapids viewport. */
function traffic_test_connection(): array
{
    $style = (string)cfg('traffic.FLOW_STYLE', 'relative0-dark');
    return traffic_fetch_tile($style, 11, 536, 753);
}
