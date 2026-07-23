<?php
/**
 * Calendar-driven playlist overrides — swap a display’s playlist during matching ICS events.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/rotation_lib.php';

/** @return list<array<string,mixed>> */
function rotation_calendar_overrides(string $screen = 'main'): array
{
    $all = cfg('rotation.CALENDAR_OVERRIDES', []);
    if (!is_array($all)) {
        return [];
    }
    $screen = rotation_normalize_screen_key($screen);
    $rows = $all[$screen] ?? [];

    return is_array($rows) ? rotation_normalize_calendar_overrides($rows) : [];
}

/** @param list<mixed> $rows @return list<array<string,mixed>> */
function rotation_normalize_calendar_overrides(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = rotation_normalize_calendar_override_row($row);
        if ($norm !== null) {
            $out[] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function rotation_normalize_calendar_override_row(array $row): ?array
{
    $feed = trim((string)($row['feed'] ?? ''));
    $match = trim((string)($row['match'] ?? ''));
    if ($feed === '' || $match === '') {
        return null;
    }
    $pagesRaw = $row['pages'] ?? [];
    if (!is_array($pagesRaw)) {
        return null;
    }
    $pages = rotation_parse_pages_rows($pagesRaw);
    if ($pages === []) {
        return null;
    }

    return [
        'feed' => $feed,
        'match' => $match,
        'pages' => $pages,
        'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']),
        'label' => trim((string)($row['label'] ?? '')),
    ];
}

/** @return list<string> ICS feed keys from calendar board config. */
function rotation_calendar_feed_keys(): array
{
    $feeds = cfg('calendar.ICS_FEEDS', []);
    if (!is_array($feeds)) {
        return [];
    }
    $keys = [];
    foreach ($feeds as $i => $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, (int)$i);
        $key = trim((string)($meta['key'] ?? ''));
        if ($key !== '') {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Active override playlist for a display, or null when the normal playlist should play.
 *
 * @return list<array<string,mixed>>|null
 */
function rotation_calendar_override_pages(string $screen = 'main', ?DateTimeInterface $now = null): ?array
{
    $overrides = rotation_calendar_overrides($screen);
    if ($overrides === []) {
        return null;
    }
    $now = $now ?? rotation_now();
    $ts = $now->getTimestamp();
    $winStart = strtotime('today', $ts);
    $winEnd = $winStart + (2 * 86400) - 1;

    require_once __DIR__ . '/calendar_lib.php';
    if (!defined('SIGNAGE_CALENDAR_LIB_ONLY')) {
        define('SIGNAGE_CALENDAR_LIB_ONLY', true);
    }
    require_once __DIR__ . '/../boards/media/calendar.php';

    $events = calendar_collect_events($winStart, $winEnd);
    foreach ($overrides as $override) {
        if (empty($override['enabled'])) {
            continue;
        }
        $feed = (string)($override['feed'] ?? '');
        $needle = strtolower((string)($override['match'] ?? ''));
        if ($feed === '' || $needle === '') {
            continue;
        }
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            if ((string)($ev['cal'] ?? '') !== $feed) {
                continue;
            }
            $summary = strtolower((string)($ev['summary'] ?? ''));
            if ($summary === '' || !str_contains($summary, $needle)) {
                continue;
            }
            $start = (int)($ev['ts'] ?? 0);
            $end = (int)($ev['end_ts'] ?? ($start + 3600));
            if ($start <= 0 || $ts < $start || $ts >= $end) {
                continue;
            }

            return $override['pages'];
        }
    }

    return null;
}

/** Metadata for runtime / diagnose when an override is active. @return array<string,mixed>|null */
function rotation_calendar_override_status(string $screen = 'main', ?DateTimeInterface $now = null): ?array
{
    $overrides = rotation_calendar_overrides($screen);
    if ($overrides === []) {
        return null;
    }
    $now = $now ?? rotation_now();
    $ts = $now->getTimestamp();
    $winStart = strtotime('today', $ts);
    $winEnd = $winStart + (2 * 86400) - 1;

    require_once __DIR__ . '/calendar_lib.php';
    if (!defined('SIGNAGE_CALENDAR_LIB_ONLY')) {
        define('SIGNAGE_CALENDAR_LIB_ONLY', true);
    }
    require_once __DIR__ . '/../boards/media/calendar.php';

    $events = calendar_collect_events($winStart, $winEnd);
    foreach ($overrides as $override) {
        if (empty($override['enabled'])) {
            continue;
        }
        $feed = (string)($override['feed'] ?? '');
        $needle = strtolower((string)($override['match'] ?? ''));
        foreach ($events as $ev) {
            if (!is_array($ev) || (string)($ev['cal'] ?? '') !== $feed) {
                continue;
            }
            $summary = (string)($ev['summary'] ?? '');
            if ($summary === '' || !str_contains(strtolower($summary), $needle)) {
                continue;
            }
            $start = (int)($ev['ts'] ?? 0);
            $end = (int)($ev['end_ts'] ?? ($start + 3600));
            if ($start <= 0 || $ts < $start || $ts >= $end) {
                continue;
            }
            $label = trim((string)($override['label'] ?? ''));
            if ($label === '') {
                $label = $summary;
            }

            return [
                'feed' => $feed,
                'match' => (string)($override['match'] ?? ''),
                'label' => $label,
                'summary' => $summary,
                'start' => $start,
                'end' => $end,
                'page_count' => count($override['pages']),
            ];
        }
    }

    return null;
}

/**
 * Parse admin POST / JSON for calendar overrides.
 *
 * @return array<string,list<array<string,mixed>>>
 */
function rotation_calendar_overrides_from_post(array $raw): array
{
    $out = [];
    foreach ($raw as $screen => $rows) {
        $screen = rotation_normalize_screen_key((string)$screen);
        if ($screen === '' || !is_array($rows)) {
            continue;
        }
        $norm = rotation_normalize_calendar_overrides($rows);
        if ($norm !== []) {
            $out[$screen] = $norm;
        }
    }

    return $out;
}

/** @return array<string,list<array<string,mixed>>>|null */
function rotation_calendar_overrides_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return rotation_calendar_overrides_from_post($decoded);
}
