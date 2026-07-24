<?php
/**
 * CISA Known Exploited Vulnerabilities (KEV) catalog.
 * https://www.cisa.gov/known-exploited-vulnerabilities-catalog
 */

require_once dirname(__DIR__) . '/config.php';

const KEV_CACHE_DIR = SIGNAGE_ROOT . '/cache';
const KEV_FEED_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

function kev_cache_ttl(): int
{
    return max(900, (int)cfg('kev.CACHE_TTL', 3600));
}

function kev_user_agent(): string
{
    return trim((string)cfg('kev.USER_AGENT', 'HomeSignage/KEVBoard/1.0 (signage-suite)'));
}

function kev_max_items(): int
{
    return max(4, min(12, (int)cfg('kev.MAX_ITEMS', 8)));
}

function kev_warn_days(): int
{
    return max(0, min(90, (int)cfg('kev.WARN_DAYS', 14)));
}

function kev_show_ransomware(): bool
{
    return (bool)cfg('kev.SHOW_RANSOMWARE', true);
}

/** @return list<string> */
function kev_watch_vendors(): array
{
    $raw = strtolower(trim((string)cfg('kev.WATCH_VENDORS', '')));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

function kev_plain(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (strlen($text) > 280) {
        $text = rtrim(substr($text, 0, 277)) . '…';
    }

    return $text;
}

function kev_parse_date(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $t = strtotime($raw);

    return $t !== false ? $t : 0;
}

function kev_days_until(string $raw): ?int
{
    $t = kev_parse_date($raw);
    if ($t <= 0) {
        return null;
    }
    $today = strtotime('today');

    return (int)floor(($t - $today) / 86400);
}

function kev_format_relative_date(string $raw): string
{
    $days = kev_days_until($raw);
    if ($days === null) {
        return '';
    }
    if ($days < 0) {
        return abs($days) . 'd overdue';
    }
    if ($days === 0) {
        return 'due today';
    }
    if ($days === 1) {
        return 'due tomorrow';
    }
    if ($days <= 14) {
        return 'due in ' . $days . 'd';
    }

    return date('M j', kev_parse_date($raw));
}

/** @return array<string,mixed>|null */
function kev_normalize(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $id = trim((string)($row['cveID'] ?? ''));
    if ($id === '' || !preg_match('/^CVE-\d{4}-\d+$/i', $id)) {
        return null;
    }
    $vendor = trim((string)($row['vendorProject'] ?? ''));
    $product = trim((string)($row['product'] ?? ''));
    $ransom = trim((string)($row['knownRansomwareCampaignUse'] ?? ''));
    $due = trim((string)($row['dueDate'] ?? ''));
    $added = trim((string)($row['dateAdded'] ?? ''));

    return [
        'id' => strtoupper($id),
        'vendor' => $vendor,
        'product' => $product,
        'name' => kev_plain((string)($row['vulnerabilityName'] ?? '')),
        'summary' => kev_plain((string)($row['shortDescription'] ?? '')),
        'action' => kev_plain((string)($row['requiredAction'] ?? '')),
        'added' => $added,
        'due' => $due,
        'due_days' => kev_days_until($due),
        'added_ts' => kev_parse_date($added),
        'ransomware' => $ransom !== '' && strcasecmp($ransom, 'Unknown') !== 0,
        'url' => 'https://nvd.nist.gov/vuln/detail/' . rawurlencode(strtoupper($id)),
    ];
}

/** @return array{vulnerabilities:list<array<string,mixed>>}|null */
function kev_fetch_catalog(): ?array
{
    if (!is_dir(KEV_CACHE_DIR)) {
        @mkdir(KEV_CACHE_DIR, 0775, true);
    }
    $cacheFile = KEV_CACHE_DIR . '/kev_catalog.json';
    $ttl = kev_cache_ttl();
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $ch = curl_init(KEV_FEED_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . kev_user_agent()],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            @file_put_contents($cacheFile, $body, LOCK_EX);

            return $decoded;
        }
    }

    $GLOBALS['diag']['kev'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    return null;
}

/** @return list<array<string,mixed>> */
function kev_all_entries(): array
{
    $catalog = kev_fetch_catalog();
    if (!is_array($catalog)) {
        return [];
    }
    $rows = [];
    foreach ($catalog['vulnerabilities'] ?? [] as $row) {
        $norm = kev_normalize(is_array($row) ? $row : null);
        if ($norm !== null) {
            $rows[] = $norm;
        }
    }
    usort($rows, static fn(array $a, array $b): int => ((int)($b['added_ts'] ?? 0)) <=> ((int)($a['added_ts'] ?? 0)));

    return $rows;
}

function kev_row_matches_watch(array $row, array $watchVendors): bool
{
    if ($watchVendors === []) {
        return true;
    }
    $blob = strtolower(($row['vendor'] ?? '') . ' ' . ($row['product'] ?? '') . ' ' . ($row['name'] ?? ''));

    foreach ($watchVendors as $needle) {
        if ($needle !== '' && str_contains($blob, $needle)) {
            return true;
        }
    }

    return false;
}

/** @return array<string,mixed> */
function kev_board_data(): array
{
    $max = kev_max_items();
    $warnDays = kev_warn_days();
    $watch = kev_watch_vendors();
    $showRansom = kev_show_ransomware();
    $all = kev_all_entries();

    $recent = array_slice($all, 0, $max);
    $dueSoon = [];
    foreach ($all as $row) {
        $days = $row['due_days'];
        if ($days === null || $days > $warnDays) {
            continue;
        }
        if (!$showRansom && !empty($row['ransomware'])) {
            continue;
        }
        if (!kev_row_matches_watch($row, $watch)) {
            continue;
        }
        $dueSoon[] = $row;
    }
    usort($dueSoon, static fn(array $a, array $b): int => ((int)($a['due_days'] ?? 999)) <=> ((int)($b['due_days'] ?? 999)));
    $dueSoon = array_slice($dueSoon, 0, $max);

    $watchRows = [];
    if ($watch !== []) {
        foreach ($all as $row) {
            if (!kev_row_matches_watch($row, $watch)) {
                continue;
            }
            if (!$showRansom && !empty($row['ransomware'])) {
                continue;
            }
            $watchRows[] = $row;
            if (count($watchRows) >= $max) {
                break;
            }
        }
    }

    $hero = $dueSoon[0] ?? $recent[0] ?? null;
    $list = $dueSoon !== [] ? $dueSoon : ($watchRows !== [] ? $watchRows : $recent);
    if ($hero !== null && isset($list[0]) && ($list[0]['id'] ?? '') === ($hero['id'] ?? '')) {
        $list = array_slice($list, 1);
    }
    $list = array_slice($list, 0, max(0, $max - 1));

    return [
        'hero' => $hero,
        'list' => $list,
        'stats' => [
            'catalog' => count($all),
            'due_soon' => count($dueSoon),
            'catalog_version' => trim((string)(kev_fetch_catalog()['catalogVersion'] ?? '')),
        ],
        'has_data' => $all !== [],
    ];
}
