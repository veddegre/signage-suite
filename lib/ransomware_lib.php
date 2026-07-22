<?php
/**
 * Ransomware victim tracking — Ransomware.live API v2.
 * https://api.ransomware.live/apidocs
 *
 * Victim posts are unverified extortion-site claims — not confirmed breaches.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/attacks_lib.php';

const RANSOMWARE_CACHE_DIR = SIGNAGE_ROOT . '/cache';
const RANSOMWARE_API = 'https://api.ransomware.live/v2/recentvictims';

function ransomware_cache_ttl(): int
{
    return max(900, (int)cfg('ransomware.CACHE_TTL', 1800));
}

function ransomware_user_agent(): string
{
    return trim((string)cfg('ransomware.USER_AGENT', 'HomeSignage/RansomwareBoard/1.0 (signage-suite)'));
}

function ransomware_lookback_days(): int
{
    return max(1, min(30, (int)cfg('ransomware.LOOKBACK_DAYS', 7)));
}

function ransomware_max_items(): int
{
    return max(4, min(12, (int)cfg('ransomware.MAX_ITEMS', 8)));
}

function ransomware_highlight_country(): string
{
    return strtoupper(trim((string)cfg('ransomware.HIGHLIGHT_COUNTRY', 'US')));
}

function ransomware_show_infostealer(): bool
{
    return (bool)cfg('ransomware.SHOW_INFOSTEALER', true);
}

/** @return list<string> */
function ransomware_csv_tokens(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

/** @return list<string> */
function ransomware_watch_sectors(): array
{
    return ransomware_csv_tokens((string)cfg('ransomware.WATCH_SECTORS', ''));
}

/** @return list<string> */
function ransomware_watch_groups(): array
{
    return ransomware_csv_tokens((string)cfg('ransomware.WATCH_GROUPS', ''));
}

function ransomware_plain(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (strlen($text) > 320) {
        $text = rtrim(substr($text, 0, 317)) . '…';
    }

    return $text;
}

function ransomware_parse_time(string $iso): int
{
    if ($iso === '') {
        return 0;
    }
    $t = strtotime($iso);

    return $t !== false ? $t : 0;
}

function ransomware_format_relative(string $iso): string
{
    $t = ransomware_parse_time($iso);
    if ($t <= 0) {
        return '';
    }
    $delta = time() - $t;
    if ($delta < 60) {
        return 'just now';
    }
    if ($delta < 3600) {
        return (int)floor($delta / 60) . 'm ago';
    }
    if ($delta < 86400) {
        return (int)floor($delta / 3600) . 'h ago';
    }
    if ($delta < 86400 * 14) {
        return (int)floor($delta / 86400) . 'd ago';
    }

    return date('M j', $t);
}

function ransomware_public_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https://(www\.)?ransomware\.live/#i', $url)) {
        return null;
    }

    return $url;
}

function ransomware_screenshot_url(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '' || !str_starts_with($url, 'https://images.ransomware.live/')) {
        return null;
    }

    return $url;
}

/** @return array<string,mixed>|null */
function ransomware_normalize(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $victim = trim((string)($row['victim'] ?? ''));
    if ($victim === '') {
        return null;
    }
    $group = strtolower(trim((string)($row['group'] ?? '')));
    $country = strtoupper(trim((string)($row['country'] ?? '')));
    $activity = trim((string)($row['activity'] ?? ''));
    $discovered = trim((string)($row['discovered'] ?? ''));
    $domain = trim((string)($row['domain'] ?? ''));
    $press = is_array($row['press'] ?? null) ? $row['press'] : null;
    $infostealer = is_array($row['infostealer'] ?? null) ? $row['infostealer'] : null;
    $users = 0;
    if (is_array($infostealer)) {
        $users = max(0, (int)($infostealer['users'] ?? 0));
    }

    return [
        'victim' => $victim,
        'group' => $group,
        'group_label' => $group !== '' ? ucfirst($group) : 'Unknown',
        'country' => $country,
        'country_name' => $country !== '' ? attacks_country_name($country) : '',
        'activity' => $activity !== '' && strcasecmp($activity, 'Not Found') !== 0 ? $activity : '',
        'discovered' => $discovered,
        'domain' => $domain,
        'description' => ransomware_plain((string)($row['description'] ?? '')),
        'url' => ransomware_public_url($row['url'] ?? null),
        'screenshot' => ransomware_screenshot_url($row['screenshot'] ?? null),
        'corroborated' => is_array($press) && trim((string)($press['link'] ?? '')) !== '',
        'infostealer_users' => $users,
        'discovered_ts' => ransomware_parse_time($discovered),
    ];
}

/** @return list<array<string,mixed>>|null */
function ransomware_fetch_recent(): ?array
{
    if (!is_dir(RANSOMWARE_CACHE_DIR)) {
        @mkdir(RANSOMWARE_CACHE_DIR, 0775, true);
    }
    $cacheFile = RANSOMWARE_CACHE_DIR . '/ransomware_recent.json';
    $ttl = ransomware_cache_ttl();
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $ch = curl_init(RANSOMWARE_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . ransomware_user_agent()],
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

    $GLOBALS['diag']['ransomware'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : null;
    }

    return null;
}

/** @param array<string,mixed> $row */
function ransomware_in_lookback(array $row, int $days): bool
{
    $ts = (int)($row['discovered_ts'] ?? 0);
    if ($ts <= 0) {
        return true;
    }

    return $ts >= (time() - ($days * 86400));
}

/** @param list<array<string,mixed>> $raw */
function ransomware_build_list(array $raw): array
{
    $lookback = ransomware_lookback_days();
    $watchSectors = ransomware_watch_sectors();
    $watchGroups = ransomware_watch_groups();
    $highlight = ransomware_highlight_country();
    $max = ransomware_max_items();

    $rows = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $norm = ransomware_normalize($item);
        if ($norm === null || !ransomware_in_lookback($norm, $lookback)) {
            continue;
        }
        if ($watchGroups !== [] && !in_array((string)$norm['group'], $watchGroups, true)) {
            continue;
        }
        if ($watchSectors !== []) {
            $activity = strtolower((string)$norm['activity']);
            $hit = false;
            foreach ($watchSectors as $sector) {
                if ($activity !== '' && str_contains($activity, $sector)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
        }
        $rows[] = $norm;
    }

    usort($rows, static function (array $a, array $b) use ($highlight, $watchSectors, $watchGroups): int {
        $score = static function (array $row) use ($highlight, $watchSectors, $watchGroups): int {
            $s = 0;
            if ($highlight !== '' && ($row['country'] ?? '') === $highlight) {
                $s += 4;
            }
            if (($row['corroborated'] ?? false) === true) {
                $s += 3;
            }
            if ($watchGroups !== [] && in_array((string)($row['group'] ?? ''), $watchGroups, true)) {
                $s += 2;
            }
            if ($watchSectors !== []) {
                $activity = strtolower((string)($row['activity'] ?? ''));
                foreach ($watchSectors as $sector) {
                    if ($activity !== '' && str_contains($activity, $sector)) {
                        $s += 1;
                        break;
                    }
                }
            }

            return $s;
        };
        $diff = $score($b) <=> $score($a);
        if ($diff !== 0) {
            return $diff;
        }

        return ((int)($b['discovered_ts'] ?? 0)) <=> ((int)($a['discovered_ts'] ?? 0));
    });

    return array_slice($rows, 0, $max);
}

/** @param list<array<string,mixed>> $rows */
function ransomware_aggregate(array $rows): array
{
    $sectors = [];
    $groups = [];
    $countries = [];
    $monthCount = 0;
    $windowCount = count($rows);
    $usCount = 0;
    $highlight = ransomware_highlight_country();
    $monthStart = strtotime(date('Y-m-01 00:00:00'));

    foreach ($rows as $row) {
        $activity = trim((string)($row['activity'] ?? ''));
        if ($activity !== '') {
            $sectors[$activity] = ($sectors[$activity] ?? 0) + 1;
        }
        $group = trim((string)($row['group_label'] ?? ''));
        if ($group !== '') {
            $groups[$group] = ($groups[$group] ?? 0) + 1;
        }
        $country = trim((string)($row['country'] ?? ''));
        if ($country !== '') {
            $countries[$country] = ($countries[$country] ?? 0) + 1;
            if ($highlight !== '' && $country === $highlight) {
                $usCount++;
            }
        }
        if ((int)($row['discovered_ts'] ?? 0) >= $monthStart) {
            $monthCount++;
        }
    }

    arsort($sectors);
    arsort($groups);
    arsort($countries);

    return [
        'window_count' => $windowCount,
        'month_count' => $monthCount,
        'highlight_count' => $usCount,
        'top_sectors' => array_slice($sectors, 0, 5, true),
        'top_groups' => array_slice($groups, 0, 5, true),
        'top_countries' => array_slice($countries, 0, 5, true),
    ];
}

/** @param list<array<string,mixed>> $rows */
function ransomware_board_data(): array
{
    $raw = ransomware_fetch_recent();
    $rows = $raw ? ransomware_build_list($raw) : [];
    $hero = $rows[0] ?? null;
    $list = array_slice($rows, 1);
    $stats = ransomware_aggregate($rows);

    return [
        'rows' => $rows,
        'hero' => $hero,
        'list' => $list,
        'stats' => $stats,
        'has_data' => $hero !== null,
    ];
}
