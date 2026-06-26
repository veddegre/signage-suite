<?php
/**
 * Internet attack visibility — SANS DShield.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const ATTACKS_CACHE_DIR = SIGNAGE_ROOT . '/cache';
const ATTACKS_DSHIELD_BASE = 'https://isc.sans.edu/api';

function attacks_cache_ttl(): int
{
    return max(60, (int)cfg('attacks.CACHE_TTL', 300));
}

function attacks_user_agent(): string
{
    return trim((string)cfg('attacks.USER_AGENT', 'HomeSignage/AttacksBoard/1.0 (signage-suite)'));
}

function attacks_dshield_enabled(): bool
{
    return (bool)cfg('attacks.ENABLE_DSHIELD', true);
}

function attacks_highlight_us(): bool
{
    return (bool)cfg('attacks.HIGHLIGHT_US', true);
}

function attacks_max_countries(): int
{
    return max(4, min(15, (int)cfg('attacks.MAX_COUNTRIES', 10)));
}

function attacks_max_ips(): int
{
    return max(4, min(12, (int)cfg('attacks.MAX_IPS', 8)));
}

function attacks_max_ports(): int
{
    return max(4, min(12, (int)cfg('attacks.MAX_PORTS', 8)));
}

function attacks_format_count(int $n): string
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

function attacks_country_name(string $code): string
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
        'BG' => 'Bulgaria', 'TR' => 'Turkey', 'RO' => 'Romania', 'PL' => 'Poland',
        'IT' => 'Italy', 'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland',
    ];
    return $fallback[$code] ?? $code;
}

/** @return array{body:false|string,code:int,err:string} */
function attacks_http_get(string $url, array $headers = [], int $timeout = 20): array
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
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . attacks_user_agent()], $headers),
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
function attacks_cache_read(string $key)
{
    $file = ATTACKS_CACHE_DIR . '/' . $key . '.json';
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($file), true);
    return $decoded;
}

/** @param mixed $data */
function attacks_cache_write(string $key, $data): void
{
    if (!is_dir(ATTACKS_CACHE_DIR)) {
        @mkdir(ATTACKS_CACHE_DIR, 0775, true);
    }
    @file_put_contents(ATTACKS_CACHE_DIR . '/' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** @return mixed|null */
function attacks_fetch_json(string $cacheKey, string $url, array $headers = [], int $timeout = 25)
{
    if (attacks_cache_ttl() > 0) {
        $cached = attacks_cache_read($cacheKey);
        $file = ATTACKS_CACHE_DIR . '/' . $cacheKey . '.json';
        if ($cached !== null && is_file($file) && (time() - filemtime($file)) < attacks_cache_ttl()) {
            return $cached;
        }
    }

    $res = attacks_http_get($url, $headers, $timeout);
    if ($res['body'] !== false && $res['code'] === 200) {
        $decoded = json_decode($res['body'], true);
        if ($decoded !== null) {
            attacks_cache_write($cacheKey, $decoded);
            return $decoded;
        }
    }
    $GLOBALS['diag'][$cacheKey] = $res['err'] !== '' ? 'curl: ' . $res['err'] : 'HTTP ' . $res['code'];
    return attacks_cache_read($cacheKey);
}

/** @param mixed $raw @return list<array<string,mixed>> */
function attacks_listify($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    if ($raw === []) {
        return [];
    }
    if (array_is_list($raw)) {
        return $raw;
    }
    $out = [];
    foreach ($raw as $item) {
        if (is_array($item)) {
            $out[] = $item;
        }
    }
    usort($out, static fn($a, $b) => ($a['rank'] ?? 0) <=> ($b['rank'] ?? 0));
    return $out;
}

/** @return array{status:string,label:string,class:string}|null */
function attacks_fetch_infocon(): ?array
{
    if (!attacks_dshield_enabled()) {
        return null;
    }
    $raw = attacks_fetch_json('attacks_infocon', ATTACKS_DSHIELD_BASE . '/infocon?json');
    if (!is_array($raw)) {
        return null;
    }
    $status = strtolower(trim((string)($raw['status'] ?? 'green')));
    return [
        'status' => $status,
        'label' => strtoupper($status),
        'class' => match ($status) {
            'red' => 'crit',
            'orange' => 'warn',
            'yellow' => 'warn',
            default => 'ok',
        },
    ];
}

/** @return list<array{code:string,name:string,targets:int,reports:int,sources:int,persistance:float}> */
function attacks_fetch_countries(): array
{
    if (!attacks_dshield_enabled()) {
        return [];
    }
    $raw = attacks_fetch_json('attacks_countries', ATTACKS_DSHIELD_BASE . '/country?json', [], 45);
    $rows = attacks_listify($raw);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper(trim((string)($row['country'] ?? '')));
        if ($code === '') {
            continue;
        }
        $out[] = [
            'code' => $code,
            'name' => attacks_country_name($code),
            'targets' => (int)($row['targets'] ?? 0),
            'reports' => (int)($row['reports'] ?? 0),
            'sources' => (int)($row['sources'] ?? 0),
            'persistance' => (float)($row['persistance'] ?? 0),
        ];
    }
    usort($out, static fn($a, $b) => $b['targets'] <=> $a['targets']);
    return $out;
}

/** @param list<array<string,mixed>> $countries @return list<array<string,mixed>> */
function attacks_countries_for_display(array $countries): array
{
    $limit = attacks_max_countries();
    $us = null;
    $rest = [];
    foreach ($countries as $row) {
        if (attacks_highlight_us() && ($row['code'] ?? '') === 'US') {
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

/** @return list<array{ip:string,reports:int,targets:int,rank:int}> */
function attacks_fetch_top_ips(): array
{
    if (!attacks_dshield_enabled()) {
        return [];
    }
    $raw = attacks_fetch_json('attacks_topips', ATTACKS_DSHIELD_BASE . '/topips?json');
    $rows = attacks_listify($raw);
    $out = [];
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $ip = trim((string)($row['source'] ?? ''));
        if ($ip === '') {
            continue;
        }
        $out[] = [
            'rank' => (int)($row['rank'] ?? $i + 1),
            'ip' => $ip,
            'reports' => (int)($row['reports'] ?? 0),
            'targets' => (int)($row['targets'] ?? 0),
        ];
        if (count($out) >= attacks_max_ips()) {
            break;
        }
    }
    return $out;
}

/** @return list<array{port:int,records:int,targets:int,sources:int,rank:int}> */
function attacks_fetch_top_ports(): array
{
    if (!attacks_dshield_enabled()) {
        return [];
    }
    $limit = attacks_max_ports();
    foreach ([0, 1] as $daysAgo) {
        $date = gmdate('Y-m-d', strtotime('-' . $daysAgo . ' day'));
        $url = ATTACKS_DSHIELD_BASE . '/topports/records/' . $limit . '/' . $date . '?json';
        $raw = attacks_fetch_json('attacks_topports_' . $date, $url);
        $rows = attacks_listify($raw);
        if ($rows === []) {
            continue;
        }
        $out = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $port = (int)($row['targetport'] ?? 0);
            if ($port <= 0) {
                continue;
            }
            $out[] = [
                'rank' => (int)($row['rank'] ?? $i + 1),
                'port' => $port,
                'records' => (int)($row['records'] ?? 0),
                'targets' => (int)($row['targets'] ?? 0),
                'sources' => (int)($row['sources'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        if ($out !== []) {
            return $out;
        }
    }
    return [];
}

function attacks_port_label(int $port): string
{
    return match ($port) {
        22 => 'SSH',
        23 => 'Telnet',
        25 => 'SMTP',
        53 => 'DNS',
        80 => 'HTTP',
        110 => 'POP3',
        143 => 'IMAP',
        443 => 'HTTPS',
        445 => 'SMB',
        3389 => 'RDP',
        8080 => 'HTTP-alt',
        default => '',
    };
}

/** @return array{dshield:array<string,mixed>} */
function attacks_fetch_all(): array
{
    $countries = attacks_fetch_countries();
    $displayCountries = attacks_countries_for_display($countries);
    $hero = $displayCountries[0] ?? ($countries[0] ?? null);

    return [
        'dshield' => [
            'infocon' => attacks_fetch_infocon(),
            'countries' => $displayCountries,
            'hero' => $hero,
            'top_ips' => attacks_fetch_top_ips(),
            'top_ports' => attacks_fetch_top_ports(),
        ],
    ];
}
