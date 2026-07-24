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

/** Only surface entries newly added to the KEV catalog within N days (0 = no limit). */
function kev_added_days(): int
{
    return max(0, min(365, (int)cfg('kev.ADDED_DAYS', 90)));
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

function kev_row_added_within(array $row, int $addedDays): bool
{
    if ($addedDays <= 0) {
        return true;
    }
    $addedTs = (int)($row['added_ts'] ?? 0);
    if ($addedTs <= 0) {
        return false;
    }

    return $addedTs >= strtotime('-' . $addedDays . ' days midnight');
}

function kev_days_since_added(array $row): ?int
{
    $addedTs = (int)($row['added_ts'] ?? 0);
    if ($addedTs <= 0) {
        return null;
    }

    return (int)floor((strtotime('today') - $addedTs) / 86400);
}

function kev_format_added_relative(array $row): string
{
    $days = kev_days_since_added($row);
    if ($days === null) {
        return '';
    }
    if ($days === 0) {
        return 'added today';
    }
    if ($days === 1) {
        return 'added yesterday';
    }
    if ($days <= 14) {
        return 'added ' . $days . 'd ago';
    }

    return 'added ' . date('M j', (int)$row['added_ts']);
}

function kev_row_passes_filters(array $row, bool $showRansom, array $watch): bool
{
    if (!$showRansom && !empty($row['ransomware'])) {
        return false;
    }

    return kev_row_matches_watch($row, $watch);
}

/** @return list<array<string,mixed>> */
function kev_merge_unique_rows(array ...$groups): array
{
    $out = [];
    $seen = [];
    foreach ($groups as $group) {
        foreach ($group as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $row;
        }
    }

    return $out;
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
    $addedDays = kev_added_days();
    $watch = kev_watch_vendors();
    $showRansom = kev_show_ransomware();
    $all = kev_all_entries();

    $newToKev = [];
    $dueSoon = [];
    $watchRows = [];
    $newCount = 0;
    $dueSoonCount = 0;

    foreach ($all as $row) {
        if (!kev_row_passes_filters($row, $showRansom, $watch)) {
            continue;
        }

        $daysUntilDue = $row['due_days'];
        if ($daysUntilDue !== null && $daysUntilDue >= 0 && $daysUntilDue <= $warnDays) {
            $dueSoon[] = $row;
            $dueSoonCount++;
        }

        if (kev_row_added_within($row, $addedDays)) {
            $newToKev[] = $row;
            $newCount++;
        }

        if ($watch !== []) {
            $watchRows[] = $row;
        }
    }

    usort($dueSoon, static fn(array $a, array $b): int => ((int)($a['due_days'] ?? 999)) <=> ((int)($b['due_days'] ?? 999)));
    $dueSoon = array_slice($dueSoon, 0, $max);
    $newToKev = array_slice($newToKev, 0, $max);
    $watchRows = array_slice($watchRows, 0, $max);

    $hero = $newToKev[0] ?? $dueSoon[0] ?? ($watchRows[0] ?? null);
    $merged = kev_merge_unique_rows($newToKev, $dueSoon, $watchRows);
    $list = [];
    foreach ($merged as $row) {
        if ($hero !== null && ($row['id'] ?? '') === ($hero['id'] ?? '')) {
            continue;
        }
        $list[] = $row;
        if (count($list) >= max(0, $max - 1)) {
            break;
        }
    }

    return [
        'hero' => $hero,
        'list' => $list,
        'stats' => [
            'catalog' => count($all),
            'new_to_kev' => $newCount,
            'due_soon' => $dueSoonCount,
            'added_days' => $addedDays,
            'watch_vendors' => count($watch),
            'catalog_version' => trim((string)(kev_fetch_catalog()['catalogVersion'] ?? '')),
        ],
        'has_data' => $all !== [],
        'has_actionable' => $hero !== null,
    ];
}
