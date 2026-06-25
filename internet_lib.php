<?php
/**
 * Internet infrastructure — BGP/ASN outages (IODA) and DNS root probes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

const INTERNET_CACHE_DIR = __DIR__ . '/cache';
const INTERNET_IODA_BASE = 'https://api.ioda.inetintel.cc.gatech.edu/v2';

/** @return list<string> */
function internet_root_letters(): array
{
    return ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm'];
}

/** @return array<string, string> */
function internet_root_operators(): array
{
    return [
        'a' => 'Verisign',
        'b' => 'USC-ISI',
        'c' => 'Cogent',
        'd' => 'UMD',
        'e' => 'NASA',
        'f' => 'ISC',
        'g' => 'US DoD',
        'h' => 'US Army',
        'i' => 'Netnod',
        'j' => 'Verisign',
        'k' => 'RIPE NCC',
        'l' => 'ICANN',
        'm' => 'WIDE',
    ];
}

function internet_cache_ttl(): int
{
    return max(60, (int)cfg('internet.CACHE_TTL', 300));
}

function internet_dns_cache_ttl(): int
{
    return max(30, (int)cfg('internet.DNS_CACHE_TTL', 180));
}

function internet_us_only(): bool
{
    return (bool)cfg('internet.US_ONLY', false);
}

function internet_lookback_days(): int
{
    return max(1, min(30, (int)cfg('internet.LOOKBACK_DAYS', 7)));
}

function internet_max_bgp_items(): int
{
    return max(4, min(12, (int)cfg('internet.MAX_BGP_ITEMS', 8)));
}

function internet_user_agent(): string
{
    return trim((string)cfg('internet.USER_AGENT', 'HomeSignage/InternetBoard/1.0 (signage-suite)'));
}

function internet_bgp_enabled(): bool
{
    return (bool)cfg('internet.ENABLE_BGP', true);
}

function internet_dns_enabled(): bool
{
    return (bool)cfg('internet.ENABLE_DNS_ROOTS', true);
}

/** @return array{from:int,until:int} */
function internet_time_window(): array
{
    $until = time();
    return ['from' => $until - internet_lookback_days() * 86400, 'until' => $until];
}

/** @param array<string,scalar|null> $params @return array<string,mixed>|null */
function internet_ioda_fetch(string $path, array $params = []): ?array
{
    if (!is_dir(INTERNET_CACHE_DIR)) {
        @mkdir(INTERNET_CACHE_DIR, 0775, true);
    }

    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    ksort($params);
    $cacheKey = 'internet_ioda_' . md5($path . '|' . json_encode($params));
    $cacheFile = INTERNET_CACHE_DIR . '/' . $cacheKey . '.json';

    if (internet_cache_ttl() > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < internet_cache_ttl()) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = INTERNET_IODA_BASE . $path . '?' . http_build_query($params);
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        $GLOBALS['diag']['ioda'] = (string)($policy['error'] ?? 'blocked URL');
        return internet_ioda_read_stale($cacheFile);
    }
    if (!function_exists('curl_init')) {
        $GLOBALS['diag']['ioda'] = 'PHP curl extension missing';
        return internet_ioda_read_stale($cacheFile);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . internet_user_agent(), 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (is_string($body) && $code === 200) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            @file_put_contents($cacheFile, $body, LOCK_EX);
            return $decoded;
        }
    }
    $GLOBALS['diag']['ioda'] = $err !== '' ? "curl: $err" : "HTTP $code";
    return internet_ioda_read_stale($cacheFile);
}

/** @return array<string,mixed>|null */
function internet_ioda_read_stale(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($cacheFile), true);
    return is_array($decoded) ? $decoded : null;
}

/** @return list<array<string,mixed>> */
function internet_ioda_data_list(?array $response): array
{
    if (!is_array($response)) {
        return [];
    }
    $data = $response['data'] ?? [];
    return is_array($data) ? $data : [];
}

function internet_datasource_label(string $ds): string
{
    return match ($ds) {
        'bgp' => 'BGP',
        'ping-slash24' => 'Active probe',
        'ping-slash24-loss' => 'Packet loss',
        'ping-slash24-latency' => 'Latency',
        'gtr', 'gtr-norm' => 'Google traffic',
        'merit-nt' => 'Darknet',
        'mozilla' => 'Mozilla',
        default => strtoupper(str_replace('-', ' ', $ds)),
    };
}

function internet_format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return round($seconds / 60) . 'm';
    }
    if ($seconds < 86400) {
        return round($seconds / 3600, 1) . 'h';
    }
    return round($seconds / 86400, 1) . 'd';
}

/** @param array<string,mixed> $event @return array<string,mixed>|null */
function internet_normalize_event(array $event): ?array
{
    $name = trim((string)($event['location_name'] ?? ''));
    if ($name === '') {
        $name = trim((string)($event['location'] ?? ''));
    }
    if ($name === '') {
        return null;
    }
    $ds = trim((string)($event['datasource'] ?? ''));
    $start = (int)($event['start'] ?? 0);
    $duration = max(0, (int)($event['duration'] ?? 0));
    $score = isset($event['score']) ? (float)$event['score'] : null;
    return [
        'kind' => 'event',
        'title' => $name,
        'subtitle' => internet_datasource_label($ds),
        'datasource' => $ds,
        'time' => $start,
        'duration' => $duration,
        'score' => $score,
        'level' => $score !== null && $score >= 100000 ? 'critical' : ($score !== null && $score >= 10000 ? 'warn' : 'normal'),
        'ongoing' => !empty($event['overlaps_window']),
        'detail' => $duration > 0 ? 'Duration ' . internet_format_duration($duration) : '',
    ];
}

/** @param array<string,mixed> $alert @return array<string,mixed>|null */
function internet_normalize_alert(array $alert): ?array
{
    $entity = $alert['entity'] ?? null;
    if (!is_array($entity)) {
        return null;
    }
    $name = trim((string)($entity['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $type = trim((string)($entity['type'] ?? ''));
    $ds = trim((string)($alert['datasource'] ?? ''));
    $level = trim((string)($alert['level'] ?? ''));
    $time = (int)($alert['time'] ?? 0);
    $condition = trim((string)($alert['condition'] ?? ''));
    $subtitle = internet_datasource_label($ds);
    if ($type === 'asn') {
        $subtitle .= ' · ASN';
    } elseif ($type === 'country') {
        $subtitle .= ' · Country';
    }
    return [
        'kind' => 'alert',
        'title' => $name,
        'subtitle' => $subtitle,
        'datasource' => $ds,
        'time' => $time,
        'duration' => 0,
        'score' => null,
        'level' => $level === 'critical' ? 'critical' : 'normal',
        'ongoing' => false,
        'detail' => $condition !== '' ? $condition : '',
    ];
}

/** @return list<array<string,mixed>> */
function internet_bgp_fetch_items(): array
{
    if (!internet_bgp_enabled()) {
        return [];
    }

    $window = internet_time_window();
    $base = [
        'from' => $window['from'],
        'until' => $window['until'],
        'limit' => 200,
    ];
    if (internet_us_only()) {
        $base['relatedTo'] = 'country/US';
    }

    $items = [];

    foreach (internet_ioda_data_list(internet_ioda_fetch('/outages/alerts', $base + ['datasource' => 'bgp'])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = internet_normalize_alert($row);
        if ($norm !== null) {
            $items[] = $norm;
        }
    }

    foreach (internet_ioda_data_list(internet_ioda_fetch('/outages/events', $base + ['entityType' => 'asn'])) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ds = (string)($row['datasource'] ?? '');
        if ($ds !== '' && $ds !== 'bgp' && !internet_us_only()) {
            // Prefer BGP events globally; keep all signals when US-scoped.
        }
        $norm = internet_normalize_event($row);
        if ($norm !== null) {
            $items[] = $norm;
        }
    }

    if ($items === []) {
        foreach (internet_ioda_data_list(internet_ioda_fetch('/outages/events', $base)) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = internet_normalize_event($row);
            if ($norm !== null) {
                $items[] = $norm;
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        $aScore = $a['score'] ?? null;
        $bScore = $b['score'] ?? null;
        if ($aScore !== null || $bScore !== null) {
            return ($bScore ?? 0) <=> ($aScore ?? 0);
        }
        return ($b['time'] ?? 0) <=> ($a['time'] ?? 0);
    });

    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $key = ($item['kind'] ?? '') . '|' . ($item['title'] ?? '') . '|' . ($item['time'] ?? 0);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $item;
        if (count($out) >= internet_max_bgp_items()) {
            break;
        }
    }
    return $out;
}

function internet_dig_path(): ?string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved !== '' ? $resolved : null;
    }
    $resolved = '';
    foreach (['/usr/bin/dig', '/bin/dig'] as $candidate) {
        if (is_executable($candidate)) {
            $resolved = $candidate;
            break;
        }
    }
    if ($resolved === '') {
        $which = trim((string)@shell_exec('command -v dig 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            $resolved = $which;
        }
    }
    return $resolved !== '' ? $resolved : null;
}

/** @return array{ok:bool,latency_ms:?int,hostname:string,error:string} */
function internet_probe_root(string $letter): array
{
    $letter = strtolower($letter);
    $host = $letter . '.root-servers.net';
    $dig = internet_dig_path();
    if ($dig === null) {
        return ['ok' => false, 'latency_ms' => null, 'hostname' => '', 'error' => 'dig not found'];
    }

    $cmd = escapeshellarg($dig) . ' +time=2 +tries=1 +stats @' . escapeshellarg($host)
        . ' hostname.bind chaos txt 2>&1';
    $output = (string)@shell_exec($cmd);
    $ok = str_contains($output, 'status: NOERROR');
    $latency = null;
    if (preg_match('/Query time:\s*(\d+)\s*msec/i', $output, $m)) {
        $latency = (int)$m[1];
    }
    $hostname = '';
    if (preg_match('/"([^"]+)"/', $output, $m)) {
        $hostname = trim($m[1]);
    }
    $error = '';
    if (!$ok) {
        if (preg_match('/status:\s*(\w+)/', $output, $m)) {
            $error = $m[1];
        } else {
            $error = 'timeout';
        }
    }
    return ['ok' => $ok, 'latency_ms' => $latency, 'hostname' => $hostname, 'error' => $error];
}

/** @return list<array<string,mixed>> */
function internet_dns_roots_fetch(): array
{
    if (!internet_dns_enabled()) {
        return [];
    }

    if (!is_dir(INTERNET_CACHE_DIR)) {
        @mkdir(INTERNET_CACHE_DIR, 0775, true);
    }
    $cacheFile = INTERNET_CACHE_DIR . '/internet_dns_roots.json';
    if (internet_dns_cache_ttl() > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < internet_dns_cache_ttl()) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $operators = internet_root_operators();
    $rows = [];
    foreach (internet_root_letters() as $letter) {
        $probe = internet_probe_root($letter);
        $rows[] = [
            'letter' => strtoupper($letter),
            'host' => $letter . '.root-servers.net',
            'operator' => $operators[$letter] ?? '',
            'ok' => (bool)$probe['ok'],
            'latency_ms' => $probe['latency_ms'],
            'hostname' => (string)$probe['hostname'],
            'error' => (string)$probe['error'],
        ];
    }

    @file_put_contents($cacheFile, json_encode($rows, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $rows;
}

/** @param list<array<string,mixed>> $roots */
function internet_dns_summary(array $roots): array
{
    $total = count($roots);
    $up = count(array_filter($roots, static fn($r) => !empty($r['ok'])));
    $down = array_values(array_filter($roots, static fn($r) => empty($r['ok'])));
    return [
        'total' => $total,
        'up' => $up,
        'down' => $down,
        'all_ok' => $total > 0 && $up === $total,
    ];
}

/** @param list<array<string,mixed>> $items */
function internet_bgp_summary(array $items): array
{
    $critical = array_values(array_filter($items, static fn($i) => ($i['level'] ?? '') === 'critical'));
    return [
        'count' => count($items),
        'critical' => count($critical),
        'has_issues' => $critical !== [],
    ];
}
