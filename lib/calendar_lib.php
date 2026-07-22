<?php
/**
 * Calendar board — shared feed palette and settings migration from legacy family.* keys.
 */

/** Theme-complementary palette for calendar feeds on the dark navy wall. */
function calendar_palette(): array
{
    return [
        ['key' => 'beacon',  'label' => 'Amber',   'hex' => '#ffb347'],
        ['key' => 'sky',     'label' => 'Sky',     'hex' => '#7ec8ff'],
        ['key' => 'seafoam', 'label' => 'Seafoam', 'hex' => '#6ee7c8'],
        ['key' => 'sage',    'label' => 'Sage',    'hex' => '#9dffb0'],
        ['key' => 'coral',   'label' => 'Coral',   'hex' => '#ff9d9d'],
        ['key' => 'lilac',   'label' => 'Lilac',   'hex' => '#c4a8ff'],
        ['key' => 'gold',    'label' => 'Gold',    'hex' => '#ffd089'],
        ['key' => 'rose',    'label' => 'Rose',    'hex' => '#ff8fc7'],
    ];
}

function calendar_color_hex(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '#ffb347';
    }
    if ($stored[0] === '#') {
        return $stored;
    }
    foreach (calendar_palette() as $p) {
        if ($p['key'] === $stored) {
            return $p['hex'];
        }
    }
    return '#ffb347';
}

/** @param array<string,mixed> $feed */
function calendar_feed_meta(array $feed, int $index = 0): array
{
    $palette = calendar_palette();
    $key = trim((string)($feed['key'] ?? $feed['name'] ?? ''));
    if ($key === '') {
        $key = 'Cal ' . ($index + 1);
    }
    $colorKey = trim((string)($feed['color'] ?? ''));
    if ($colorKey === '' || ($colorKey[0] !== '#' && !calendar_palette_has_key($colorKey))) {
        $colorKey = $palette[$index % count($palette)]['key'];
    }
    return [
        'key' => $key,
        'color' => $colorKey,
        'hex' => calendar_color_hex($colorKey),
    ];
}

function calendar_palette_has_key(string $key): bool
{
    foreach (calendar_palette() as $p) {
        if ($p['key'] === $key) {
            return true;
        }
    }
    return false;
}

/** iCal subscription URLs often use webcal:// — normalize to https:// for curl and policy checks. */
function calendar_normalize_feed_url(string $url): string
{
    $url = trim($url);
    if (preg_match('#^webcals?://#i', $url)) {
        return 'https://' . preg_replace('#^webcals?://#i', '', $url);
    }

    return $url;
}

/** True for vendor-published ICS subscription links (iCloud, etc.) — not CalDAV. */
function calendar_is_published_ical_url(string $url): bool
{
    $url = calendar_normalize_feed_url($url);
    if ($url === '') {
        return false;
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

    return str_contains($host, 'caldav.icloud.com') && str_contains($path, '/published/');
}

/** Resolve feed transport: public ICS subscriptions must not use CalDAV. */
function calendar_feed_source(array $feed): string
{
    $url = calendar_normalize_feed_url((string)($feed['url'] ?? ''));
    if (calendar_is_published_ical_url($url)) {
        return 'ical';
    }
    $source = strtolower(trim((string)($feed['source'] ?? 'ical')));

    return $source === 'webdav' ? 'webdav' : 'ical';
}

/** ISO weekday 1=Mon … 7=Sun for an RRULE WKST token (RFC 5545 default: MO). */
function ics_wkst_to_iso(string $wkst): int
{
    static $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    return $map[strtoupper(trim($wkst))] ?? 1;
}

/** Local-midnight timestamp for the WKST-aligned week that contains $dayMidnight. */
function ics_week_period_start(int $dayMidnight, int $wkstIso): int
{
    $dow = (int)date('N', $dayMidnight);
    $back = ($dow - $wkstIso + 7) % 7;
    return strtotime("-{$back} days", $dayMidnight);
}

/** Calendar-day difference (DST-safe) between two local midnights. */
function ics_calendar_days_between(int $fromMidnight, int $toMidnight): int
{
    if ($toMidnight === $fromMidnight) {
        return 0;
    }
    try {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $from = (new DateTime('@' . $fromMidnight))->setTimezone($tz);
    $to = (new DateTime('@' . $toMidnight))->setTimezone($tz);
    if ($to < $from) {
        return -(int)$to->diff($from)->format('%a');
    }

    return (int)$from->diff($to)->format('%a');
}

/** Whole weeks from the anchor week (contains DTSTART) to the week that contains $dayMidnight. */
function ics_weeks_since_start(int $dayMidnight, int $startMidnight, int $wkstIso): int
{
    $anchor = ics_week_period_start($startMidnight, $wkstIso);
    $here = ics_week_period_start($dayMidnight, $wkstIso);

    return (int)floor(ics_calendar_days_between($anchor, $here) / 7);
}

/**
 * Effective WEEKLY INTERVAL — Outlook often omits INTERVAL=2 and uses EXDATE skips or
 * puts cadence in the summary (e.g. "Meeting (Every 2 Weeks)").
 */
function ics_rrule_interval(array $ev): int
{
    $r = $ev['rrule'] ?? null;
    if (!is_array($r)) {
        return 1;
    }
    $explicit = max(1, (int)($r['INTERVAL'] ?? 1));
    if (($r['FREQ'] ?? '') !== 'WEEKLY' || $explicit > 1) {
        return $explicit;
    }

    $summary = (string)($ev['summary'] ?? '');
    if (preg_match('/(?:every\s*2[\-\s]*weeks|every\s*other\s*week|\(\s*every\s*2[\-\s]*weeks\s*\))/i', $summary)) {
        return 2;
    }

    return 1;
}

/** @return list<array{key:string,hex:string}> */
function calendar_legend(array $feeds): array
{
    $out = [];
    $seen = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, $i++);
        $id = strtolower($meta['key']);
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = ['key' => $meta['key'], 'hex' => $meta['hex']];
    }
    return $out;
}

/**
 * Migrate family.php board + family.* settings to calendar.php / calendar.*.
 * @return array{conf:array<string,mixed>,changed:bool}
 */
function calendar_migrate_from_family(array $conf): array
{
    $changed = false;
    foreach ($conf as $key => $val) {
        if (!is_string($key) || !str_starts_with($key, 'family.')) {
            continue;
        }
        $newKey = 'calendar.' . substr($key, 7);
        if (!array_key_exists($newKey, $conf)) {
            $conf[$newKey] = $val;
            $changed = true;
        }
        unset($conf[$key]);
        $changed = true;
    }
    foreach ($conf as $key => $val) {
        if (!is_string($key) || !str_starts_with($key, 'rotation.PAGES')) {
            continue;
        }
        if (!is_array($val)) {
            continue;
        }
        foreach ($val as $i => $page) {
            if (!is_array($page)) {
                continue;
            }
            $url = trim((string)($page['url'] ?? ''));
            if ($url === 'family.php' || str_starts_with($url, 'family.php?')) {
                $conf[$key][$i]['url'] = preg_replace('/^family\.php/', 'calendar.php', $url) ?? 'calendar.php';
                $changed = true;
            }
        }
    }
    return ['conf' => $conf, 'changed' => $changed];
}
