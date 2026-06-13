<?php
/**
 * TomTom Traffic Flow — shared tile fetch + diagnostics for traffic.php / admin.
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

function traffic_tile_url(string $style, int $z, int $x, int $y, ?string $key = null): ?string
{
    $key ??= traffic_api_key();
    if ($key === null) {
        return null;
    }
    if (!in_array($style, traffic_flow_styles(), true)) {
        $style = 'relative0-dark';
    }
    return sprintf(
        'https://api.tomtom.com/traffic/map/4/tile/flow/%s/%d/%d/%d.png?key=%s&thickness=5',
        $style,
        $z,
        $x,
        $y,
        rawurlencode($key)
    );
}

/**
 * Fetch one TomTom flow tile (no disk cache).
 * @return array{ok:bool,http:int,bytes:int,error:?string,detail:?string}
 */
function traffic_fetch_tile(string $style, int $z, int $x, int $y): array
{
    $url = traffic_tile_url($style, $z, $x, $y);
    if ($url === null) {
        return ['ok' => false, 'http' => 0, 'bytes' => 0, 'error' => 'API key not configured', 'detail' => null];
    }

    $policy = signage_fetch_url_allowed($url);
    if (!$policy['ok']) {
        return [
            'ok' => false,
            'http' => 0,
            'bytes' => 0,
            'error' => 'Outbound fetch blocked',
            'detail' => $policy['error'] ?? null,
        ];
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

    if ($fetchErr !== '') {
        return [
            'ok' => false,
            'http' => $httpCode,
            'bytes' => is_string($data) ? strlen($data) : 0,
            'error' => 'Network error',
            'detail' => $fetchErr,
        ];
    }

    $pngOk = is_string($data)
        && str_starts_with($data, "\x89PNG\r\n\x1a\n")
        && strlen($data) > 100;

    if ($pngOk) {
        return [
            'ok' => true,
            'http' => $httpCode ?: 200,
            'bytes' => strlen($data),
            'error' => null,
            'detail' => null,
        ];
    }

    $snippet = is_string($data) ? substr(preg_replace('/\s+/', ' ', $data), 0, 220) : '';
    $errFile = traffic_cache_dir() . '/last_error.txt';
    if (!is_dir(traffic_cache_dir())) {
        @mkdir(traffic_cache_dir(), 0775, true);
    }
    @file_put_contents(
        $errFile,
        date('c') . " HTTP $httpCode" . ($snippet !== '' ? " body: $snippet" : '') . "\n",
        LOCK_EX
    );

    $hint = null;
    if ($httpCode === 403) {
        $hint = 'HTTP 403 — key lacks Traffic API product or domain restrictions block server-side use. '
            . 'In developer.tomtom.com → your key → enable Traffic API and remove domain whitelist for this server key.';
    } elseif ($httpCode === 401) {
        $hint = 'HTTP 401 — invalid API key.';
    } elseif ($snippet !== '') {
        $hint = $snippet;
    }

    return [
        'ok' => false,
        'http' => $httpCode,
        'bytes' => is_string($data) ? strlen($data) : 0,
        'error' => 'TomTom did not return a PNG',
        'detail' => $hint,
    ];
}

/** Test tile for the default Allendale / Grand Rapids viewport. */
function traffic_test_connection(): array
{
    $style = (string)cfg('traffic.FLOW_STYLE', 'relative0-dark');
    if (!in_array($style, traffic_flow_styles(), true)) {
        $style = 'relative0-dark';
    }
    return traffic_fetch_tile($style, 11, 536, 753);
}
