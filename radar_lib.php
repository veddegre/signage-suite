<?php
/**
 * Cloudflare Radar — L3/L7 attack geography.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

const RADAR_CACHE_DIR = __DIR__ . '/cache';
const RADAR_CF_BASE = 'https://api.cloudflare.com/client/v4';

function radar_cache_ttl(): int
{
    return max(60, (int)cfg('radar.CACHE_TTL', 300));
}

function radar_user_agent(): string
{
    return trim((string)cfg('radar.USER_AGENT', 'HomeSignage/RadarBoard/1.0 (signage-suite)'));
}

function radar_date_range(): string
{
    $range = trim((string)cfg('radar.DATE_RANGE', '1d'));
    return in_array($range, ['1d', '7d', '14d', '28d'], true) ? $range : '1d';
}

function radar_highlight_us(): bool
{
    return (bool)cfg('radar.HIGHLIGHT_US', true);
}

function radar_max_countries(): int
{
    return max(4, min(15, (int)cfg('radar.MAX_COUNTRIES', 10)));
}

function radar_cf_token(): string
{
    $token = trim((string)cfg('radar.CF_API_TOKEN', ''));
    if ($token !== '') {
        return $token;
    }
    // Legacy — token saved on combined attacks board before split.
    return trim((string)cfg('attacks.CF_API_TOKEN', ''));
}

function radar_configured(): bool
{
    return radar_cf_token() !== '';
}

function radar_show_l3_targets(): bool
{
    return (bool)cfg('radar.ENABLE_L3_TARGETS', true);
}

function radar_show_l3_origins(): bool
{
    return (bool)cfg('radar.ENABLE_L3_ORIGINS', true);
}

function radar_show_l7_targets(): bool
{
    return (bool)cfg('radar.ENABLE_L7_TARGETS', true);
}

function radar_format_count(int $n): string
{
    if ($n >= 1_000_000_000) {
        return round($n / 1_000_000_000, 1) . 'B';
    }
    if ($n >= 1_000_000) {
        return round($n / 1_000_000, 1) . 'M';
    }
    if ($n >= 10_000) {
        return round($n / 1_000, 0) . 'K';
    }
    return number_format($n);
}

function radar_country_name(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '' || $code === 'XX') {
        return 'Unknown';
    }
    if (class_exists('Locale')) {
        $name = \Locale::getDisplayRegion('und_' . $code, 'en');
        if (is_string($name) && $name !== '' && $name !== $code) {
            return $name;
        }
    }
    static $fallback = [
        'US' => 'United States', 'GB' => 'United Kingdom', 'CN' => 'China', 'RU' => 'Russia',
        'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'UA' => 'Ukraine',
        'BR' => 'Brazil', 'IN' => 'India', 'JP' => 'Japan', 'KR' => 'South Korea',
        'CA' => 'Canada', 'AU' => 'Australia', 'SG' => 'Singapore', 'HK' => 'Hong Kong',
    ];
    return $fallback[$code] ?? $code;
}

/** @return array{body:false|string,code:int,err:string} */
function radar_http_get(string $url, array $headers = [], int $timeout = 20): array
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => (string)($policy['error'] ?? 'blocked URL')];
    }
    if (!function_exists('curl_init')) {
        return ['body' => false, 'code' => 0, 'err' => 'PHP curl extension missing'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . radar_user_agent()], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    return [
        'body' => is_string($body) ? $body : false,
        'code' => $code,
        'err' => $err,
    ];
}

/** @return mixed|null */
function radar_cache_read(string $key)
{
    $file = RADAR_CACHE_DIR . '/' . $key . '.json';
    if (!is_file($file)) {
        return null;
    }
    return json_decode((string)file_get_contents($file), true);
}

/** @param mixed $data */
function radar_cache_write(string $key, $data): void
{
    if (!is_dir(RADAR_CACHE_DIR)) {
        @mkdir(RADAR_CACHE_DIR, 0775, true);
    }
    @file_put_contents(RADAR_CACHE_DIR . '/' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** @return mixed|null */
function radar_fetch_json(string $cacheKey, string $url, array $headers = [], int $timeout = 25)
{
    if (radar_cache_ttl() > 0) {
        $cached = radar_cache_read($cacheKey);
        $file = RADAR_CACHE_DIR . '/' . $cacheKey . '.json';
        if ($cached !== null && is_file($file) && (time() - filemtime($file)) < radar_cache_ttl()) {
            return $cached;
        }
    }

    $res = radar_http_get($url, $headers, $timeout);
    if ($res['body'] !== false && $res['code'] === 200) {
        $decoded = json_decode($res['body'], true);
        if ($decoded !== null) {
            radar_cache_write($cacheKey, $decoded);
            return $decoded;
        }
    }
    $GLOBALS['diag'][$cacheKey] = $res['err'] !== '' ? 'curl: ' . $res['err'] : 'HTTP ' . $res['code'];
    return radar_cache_read($cacheKey);
}

/** @return list<array{code:string,name:string,percent:float,rank:int}> */
function radar_parse_locations(?array $response, string $codeKey, string $nameKey): array
{
    if (!is_array($response) || empty($response['success'])) {
        return [];
    }
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        return [];
    }
    $top = $result['top_0'] ?? [];
    if (!is_array($top)) {
        return [];
    }
    $out = [];
    foreach ($top as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string)($row[$codeKey] ?? '')));
        if ($code === '') {
            continue;
        }
        $out[] = [
            'code' => $code,
            'name' => trim((string)($row[$nameKey] ?? '')) ?: radar_country_name($code),
            'percent' => (float)($row['value'] ?? 0),
            'rank' => (int)($row['rank'] ?? count($out) + 1),
        ];
    }
    return $out;
}

/** @return list<array{code:string,name:string,percent:float,rank:int}> */
function radar_fetch_locations(string $layer, string $direction): array
{
    if (!radar_configured()) {
        return [];
    }
    $path = match ($layer . ':' . $direction) {
        'l3:target' => '/radar/attacks/layer3/top/locations/target',
        'l3:origin' => '/radar/attacks/layer3/top/locations/origin',
        'l7:target' => '/radar/attacks/layer7/top/locations/target',
        default => '',
    };
    if ($path === '') {
        return [];
    }
    $params = http_build_query([
        'limit' => radar_max_countries(),
        'dateRange' => radar_date_range(),
        'format' => 'json',
    ]);
    $cacheKey = 'radar_cf_' . $layer . '_' . $direction . '_' . radar_date_range();
    $url = RADAR_CF_BASE . $path . '?' . $params;
    $raw = radar_fetch_json($cacheKey, $url, ['Authorization: Bearer ' . radar_cf_token()]);
    if (!is_array($raw)) {
        return [];
    }
    $codeKey = $direction === 'origin' ? 'originCountryAlpha2' : 'targetCountryAlpha2';
    $nameKey = $direction === 'origin' ? 'originCountryName' : 'targetCountryName';
    return radar_parse_locations($raw, $codeKey, $nameKey);
}

/** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
function radar_countries_for_display(array $rows): array
{
    if (!radar_highlight_us()) {
        return array_slice($rows, 0, radar_max_countries());
    }
    $limit = radar_max_countries();
    $us = null;
    $rest = [];
    foreach ($rows as $row) {
        if (($row['code'] ?? '') === 'US') {
            $us = $row;
            continue;
        }
        $rest[] = $row;
    }
    $slice = array_slice($rest, 0, $limit);
    if ($us !== null) {
        array_unshift($slice, $us);
        if (count($slice) > $limit + 1) {
            $slice = array_slice($slice, 0, $limit + 1);
        }
    }
    return $slice;
}

/** @return array<string,mixed> */
function radar_fetch_all(): array
{
    $l3Targets = radar_show_l3_targets() ? radar_fetch_locations('l3', 'target') : [];
    $l3Origins = radar_show_l3_origins() ? radar_fetch_locations('l3', 'origin') : [];
    $l7Targets = radar_show_l7_targets() ? radar_fetch_locations('l7', 'target') : [];

    $heroSource = $l7Targets[0] ?? $l3Targets[0] ?? $l3Origins[0] ?? null;
    $hero = $heroSource ? [
        'code' => $heroSource['code'],
        'name' => $heroSource['name'],
        'percent' => $heroSource['percent'],
        'layer' => isset($l7Targets[0]) ? 'L7' : 'L3',
        'role' => ($heroSource === ($l3Origins[0] ?? null) && !isset($l7Targets[0]) && !isset($l3Targets[0])) ? 'origin' : 'target',
    ] : null;

    return [
        'configured' => radar_configured(),
        'hero' => $hero,
        'l3_targets' => radar_countries_for_display($l3Targets),
        'l3_origins' => radar_countries_for_display($l3Origins),
        'l7_targets' => radar_countries_for_display($l7Targets),
    ];
}
