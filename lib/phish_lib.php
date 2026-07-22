<?php
/**
 * Phishing / brand abuse — URLhaus (abuse.ch) + Certificate Transparency (crt.sh).
 */

require_once dirname(__DIR__) . '/config.php';

const PHISH_CACHE_DIR = SIGNAGE_ROOT . '/cache';
const PHISH_URLHAUS_RECENT = 'https://urlhaus-api.abuse.ch/v1/urls/recent/';

function phish_urlhaus_auth_key(): string
{
    return trim((string)cfg('phish.URLHAUS_AUTH_KEY', ''));
}

function phish_urlhaus_cache_ttl(): int
{
    return max(300, (int)cfg('phish.URLHAUS_CACHE_TTL', 900));
}

function phish_ct_cache_ttl(): int
{
    return max(3600, (int)cfg('phish.CT_CACHE_TTL', 86400));
}

function phish_max_url_items(): int
{
    return max(4, min(12, (int)cfg('phish.MAX_URL_ITEMS', 8)));
}

function phish_max_brand_items(): int
{
    return max(2, min(8, (int)cfg('phish.MAX_BRAND_ITEMS', 5)));
}

function phish_ct_lookback_days(): int
{
    return max(1, min(90, (int)cfg('phish.CT_LOOKBACK_DAYS', 14)));
}

function phish_online_only(): bool
{
    return (bool)cfg('phish.ONLINE_ONLY', false);
}

function phish_user_agent(): string
{
    return trim((string)cfg('phish.USER_AGENT', 'HomeSignage/PhishBoard/1.0 (signage-suite)'));
}

/** @return list<string> */
function phish_tags_include(): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim((string)cfg('phish.TAGS_INCLUDE', '')))) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

/** @return list<array<string,mixed>> */
function phish_brand_watch_rows(): array
{
    $raw = cfg('phish.BRAND_WATCH', []);
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['off'])) {
            continue;
        }
        $key = strtolower(trim((string)($row['_key'] ?? $row['key'] ?? '')));
        $root = strtolower(trim((string)($row['root_domain'] ?? '')));
        $root = ltrim($root, '.');
        if ($root === '') {
            continue;
        }
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '') {
            $label = $key !== '' ? $key : $root;
        }
        $keywords = [];
        foreach (preg_split('/[\s,;]+/', strtolower(trim((string)($row['keywords'] ?? '')))) ?: [] as $kw) {
            $kw = trim($kw);
            if ($kw !== '') {
                $keywords[] = $kw;
            }
        }
        if ($keywords === [] && $key !== '') {
            $keywords[] = $key;
        }
        if ($keywords === []) {
            $base = explode('.', $root)[0] ?? $root;
            if ($base !== '') {
                $keywords[] = $base;
            }
        }
        $out[] = [
            'key' => $key !== '' ? $key : $root,
            'label' => $label,
            'root_domain' => $root,
            'keywords' => array_values(array_unique($keywords)),
        ];
    }

    return $out;
}

function phish_defang_host(string $host): string
{
    $host = trim(strtolower($host));
    if ($host === '') {
        return '';
    }

    return str_replace('.', '[.]', $host);
}

function phish_defang_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $url = preg_replace('#^https?://#i', '', $url) ?? $url;
    $url = preg_replace('#/.*$#', '', $url) ?? $url;

    return phish_defang_host($url);
}

function phish_host_from_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    return strtolower(trim((string)($parts['host'] ?? '')));
}

function phish_domain_is_managed(string $host, string $rootDomain): bool
{
    $host = strtolower(trim($host));
    $rootDomain = strtolower(trim(ltrim($rootDomain, '.')));
    if ($host === '' || $rootDomain === '') {
        return false;
    }
    if ($host === $rootDomain) {
        return true;
    }

    return str_ends_with($host, '.' . $rootDomain);
}

function phish_host_suspicious(string $host, array $keywords, string $rootDomain): bool
{
    $host = strtolower(trim($host));
    if ($host === '' || phish_domain_is_managed($host, $rootDomain)) {
        return false;
    }
    foreach ($keywords as $keyword) {
        $keyword = strtolower(trim((string)$keyword));
        if ($keyword === '') {
            continue;
        }
        if (!str_contains($host, $keyword)) {
            continue;
        }
        if (preg_match('/(?:login|signin|secure|verify|support|account|auth|update|portal|pay|billing|sso)/', $host) === 1) {
            return true;
        }
        if (str_contains($host, $keyword . '-') || str_contains($host, '-' . $keyword)) {
            return true;
        }
    }

    return false;
}

/** @return array<string,mixed>|null */
function phish_normalize_urlhaus_row(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $url = trim((string)($row['url'] ?? ''));
    $host = phish_host_from_url($url);
    if ($host === '') {
        $host = strtolower(trim((string)($row['host'] ?? '')));
    }
    if ($host === '') {
        return null;
    }
    $tags = [];
    foreach ($row['tags'] ?? [] as $tag) {
        if (is_string($tag) && trim($tag) !== '') {
            $tags[] = trim($tag);
        }
    }

    return [
        'host' => $host,
        'host_defang' => phish_defang_host($host),
        'threat' => trim((string)($row['threat'] ?? '')),
        'status' => strtolower(trim((string)($row['url_status'] ?? ''))),
        'tags' => $tags,
        'tags_label' => implode(' · ', array_slice($tags, 0, 4)),
        'added' => trim((string)($row['date_added'] ?? '')),
        'reference' => trim((string)($row['urlhaus_reference'] ?? '')),
        'online' => strtolower(trim((string)($row['url_status'] ?? ''))) === 'online',
    ];
}

/** @param list<array<string,mixed>> $rows */
function phish_filter_urlhaus_rows(array $rows): array
{
    $tagsInclude = phish_tags_include();
    $onlineOnly = phish_online_only();
    $max = phish_max_url_items();
    $out = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = phish_normalize_urlhaus_row($row);
        if ($norm === null) {
            continue;
        }
        if ($onlineOnly && empty($norm['online'])) {
            continue;
        }
        if ($tagsInclude !== []) {
            $blob = strtolower(implode(' ', $norm['tags']) . ' ' . (string)$norm['threat']);
            $hit = false;
            foreach ($tagsInclude as $needle) {
                if ($needle !== '' && str_contains($blob, $needle)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }
        $out[] = $norm;
        if (count($out) >= $max * 3) {
            break;
        }
    }

    return array_slice($out, 0, $max);
}

/** @return list<array<string,mixed>>|null */
function phish_fetch_urlhaus_recent(): ?array
{
    if (!is_dir(PHISH_CACHE_DIR)) {
        @mkdir(PHISH_CACHE_DIR, 0775, true);
    }
    $cacheFile = PHISH_CACHE_DIR . '/phish_urlhaus_recent.json';
    $ttl = phish_urlhaus_cache_ttl();
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['urls']) && is_array($cached['urls'])) {
            return $cached['urls'];
        }
    }

    $headers = ['Accept: application/json', 'User-Agent: ' . phish_user_agent()];
    $auth = phish_urlhaus_auth_key();
    if ($auth !== '') {
        $headers[] = 'Auth-Key: ' . $auth;
    }

    $ch = curl_init(PHISH_URLHAUS_RECENT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && ($decoded['query_status'] ?? '') === 'ok' && is_array($decoded['urls'] ?? null)) {
            @file_put_contents($cacheFile, $body, LOCK_EX);
            return $decoded['urls'];
        }
    }

    $GLOBALS['diag']['urlhaus'] = $auth === '' ? 'URLhaus Auth-Key missing' : ($err !== '' ? "curl: $err" : "HTTP $code");

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && is_array($cached['urls'] ?? null)) {
            return $cached['urls'];
        }
    }

    return null;
}

/** @return list<string> */
function phish_fetch_ct_names(string $rootDomain): array
{
    $rootDomain = strtolower(trim(ltrim($rootDomain, '.')));
    if ($rootDomain === '') {
        return [];
    }
    if (!is_dir(PHISH_CACHE_DIR)) {
        @mkdir(PHISH_CACHE_DIR, 0775, true);
    }
    $safe = preg_replace('/[^a-z0-9.-]+/', '_', $rootDomain) ?? 'domain';
    $cacheFile = PHISH_CACHE_DIR . '/phish_ct_' . $safe . '.json';
    $ttl = phish_ct_cache_ttl();
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['names']) && is_array($cached['names'])) {
            return $cached['names'];
        }
    }

    $query = rawurlencode('%.' . $rootDomain);
    $url = 'https://crt.sh/?q=' . $query . '&output=json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . phish_user_agent()],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $names = [];
    if ($body !== false && $code === 200) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $value = trim((string)($entry['name_value'] ?? $entry['common_name'] ?? ''));
                if ($value === '') {
                    continue;
                }
                foreach (preg_split('/\s+/u', $value) ?: [] as $name) {
                    $name = strtolower(trim($name));
                    $name = ltrim($name, '*.');
                    if ($name !== '' && str_contains($name, '.')) {
                        $names[$name] = true;
                    }
                }
            }
        }
    } else {
        $GLOBALS['diag']['crt_' . $safe] = $err !== '' ? "curl: $err" : "HTTP $code";
    }

    $out = array_keys($names);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    @file_put_contents($cacheFile, json_encode(['names' => $out], JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $out;
}

/** @return list<array<string,mixed>> */
function phish_scan_brand_watch(): array
{
    $rows = phish_brand_watch_rows();
    if ($rows === []) {
        return [];
    }
    $lookback = phish_ct_lookback_days();
    $cutoff = time() - ($lookback * 86400);
    $max = phish_max_brand_items();
    $hits = [];

    foreach ($rows as $watch) {
        $names = phish_fetch_ct_names((string)$watch['root_domain']);
        $suspicious = [];
        foreach ($names as $name) {
            if (!phish_host_suspicious($name, $watch['keywords'], (string)$watch['root_domain'])) {
                continue;
            }
            $suspicious[] = $name;
        }
        $suspicious = array_values(array_unique($suspicious));
        sort($suspicious, SORT_NATURAL | SORT_FLAG_CASE);
        $hits[] = [
            'label' => (string)$watch['label'],
            'root_domain' => (string)$watch['root_domain'],
            'keywords' => $watch['keywords'],
            'hits' => array_slice($suspicious, 0, $max),
            'hit_count' => count($suspicious),
            'status' => $suspicious === [] ? 'clear' : 'warn',
            'lookback_days' => $lookback,
            'cutoff' => $cutoff,
        ];
    }

    usort($hits, static fn(array $a, array $b): int => ((int)$b['hit_count']) <=> ((int)$a['hit_count']));

    return $hits;
}

/** @return array<string,mixed> */
function phish_board_data(): array
{
    $rawUrls = phish_fetch_urlhaus_recent();
    $urls = $rawUrls ? phish_filter_urlhaus_rows($rawUrls) : [];
    $brands = phish_scan_brand_watch();
    $brandHits = 0;
    foreach ($brands as $brand) {
        $brandHits += (int)($brand['hit_count'] ?? 0);
    }
    $onlineCount = 0;
    foreach ($urls as $url) {
        if (!empty($url['online'])) {
            $onlineCount++;
        }
    }
    $tagCounts = [];
    foreach ($urls as $url) {
        foreach ($url['tags'] ?? [] as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }
    }
    arsort($tagCounts);

    return [
        'urls' => $urls,
        'brands' => $brands,
        'brand_hits' => $brandHits,
        'online_count' => $onlineCount,
        'top_tags' => array_slice($tagCounts, 0, 5, true),
        'has_urls' => $urls !== [],
        'has_brands' => $brands !== [],
        'has_data' => $urls !== [] || $brands !== [],
        'needs_auth' => phish_urlhaus_auth_key() === '' && !$urls,
    ];
}

function phish_format_added(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return '';
    }
    $t = strtotime($iso);

    return $t ? date('M j · G:i', $t) . ' UTC' : $iso;
}
