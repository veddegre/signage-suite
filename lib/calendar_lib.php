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
    $dow = ics_local_iso_weekday($dayMidnight);
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
        $tz = calendar_display_timezone();
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

/** Display timezone for calendar wall + ICS expansion (admin → Calendar → Timezone). */
function calendar_display_timezone_name(): string
{
    if (defined('TIMEZONE')) {
        $name = trim((string)TIMEZONE);
        if ($name !== '') {
            return $name;
        }
    }
    $name = trim((string)cfg('calendar.TIMEZONE', 'America/Detroit'));

    return $name !== '' ? $name : 'America/Detroit';
}

function calendar_display_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }
    try {
        $tz = new DateTimeZone(calendar_display_timezone_name());
    } catch (Throwable $e) {
        $tz = new DateTimeZone('America/Detroit');
    }

    return $tz;
}

function calendar_ensure_display_timezone(): void
{
    @date_default_timezone_set(calendar_display_timezone_name());
}

/** @return array<string, string> Outlook / Windows TZID → IANA */
function ics_windows_tzid_map(): array
{
    return [
        'Eastern Standard Time' => 'America/New_York',
        'US Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'US Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'US Mountain Standard Time' => 'America/Phoenix',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'US Pacific Standard Time' => 'America/Los_Angeles',
        'Alaskan Standard Time' => 'America/Anchorage',
        'Hawaiian Standard Time' => 'Pacific/Honolulu',
        'Atlantic Standard Time' => 'America/Halifax',
        'Newfoundland Standard Time' => 'America/St_Johns',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'UTC' => 'UTC',
        'GMT' => 'UTC',
    ];
}

function ics_unfold(string $ics): array
{
    $lines = preg_split('/\R/', $ics);
    $out = [];
    foreach ($lines as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $out) {
            $out[count($out) - 1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }

    return $out;
}

function ics_parse_tzid(string $params): ?string
{
    if (!preg_match('/(?:^|;)TZID=([^:;]+)/i', $params, $m)) {
        return null;
    }
    $tzid = trim(str_replace('\\,', ',', $m[1]));
    if ($tzid !== '' && $tzid[0] === '"' && str_ends_with($tzid, '"')) {
        $tzid = substr($tzid, 1, -1);
    }

    return $tzid !== '' ? $tzid : null;
}

/** Map Outlook/Windows TZID names (and IANA ids) to a PHP zone. */
function ics_timezone(string $tzid, ?DateTimeZone $fallback = null): DateTimeZone
{
    $fallback ??= calendar_display_timezone();
    $tzid = trim(str_replace('\\,', ',', $tzid));
    $windows = ics_windows_tzid_map();
    if (isset($windows[$tzid])) {
        try {
            return new DateTimeZone($windows[$tzid]);
        } catch (Throwable $e) {
        }
    }
    try {
        return new DateTimeZone($tzid);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/** Local midnight for a unix timestamp in the display timezone. */
function ics_local_midnight(int $ts): int
{
    $dt = (new DateTime('@' . $ts))->setTimezone(calendar_display_timezone());
    $dt->setTime(0, 0, 0);

    return $dt->getTimestamp();
}

function ics_local_date_key(int $ts): string
{
    return (new DateTime('@' . $ts))->setTimezone(calendar_display_timezone())->format('Y-m-d');
}

/** ISO weekday 1=Mon … 7=Sun for a local-midnight timestamp. */
function ics_local_iso_weekday(int $dayMidnight): int
{
    return (int)(new DateTime('@' . $dayMidnight))
        ->setTimezone(calendar_display_timezone())
        ->format('N');
}

/** Apply the wall-clock time from $referenceTs onto a local calendar day. DST-safe. */
function ics_wall_time_on_day(int $dayMidnight, int $referenceTs): int
{
    $tz = calendar_display_timezone();
    $ref = (new DateTime('@' . $referenceTs))->setTimezone($tz);
    $day = (new DateTime('@' . $dayMidnight))->setTimezone($tz);
    $day->setTime((int)$ref->format('G'), (int)$ref->format('i'), (int)$ref->format('s'));

    return $day->getTimestamp();
}

function ics_format_local_time(int $ts, string $format = 'g:i A'): string
{
    return (new DateTime('@' . $ts))->setTimezone(calendar_display_timezone())->format($format);
}

/** Parse a DTSTART/DTEND/RECURRENCE-ID/EXDATE value (+params) into [unix_ts, all_day]. */
function ics_time(string $params, string $value, ?DateTimeZone $fallbackTz = null): ?array
{
    $fallbackTz ??= calendar_display_timezone();
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (stripos($params, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $value)) {
        $t = DateTime::createFromFormat('Ymd', substr($value, 0, 8), $fallbackTz);

        return $t ? [$t->setTime(0, 0)->getTimestamp(), true] : null;
    }

    $tz = $fallbackTz;
    $tzid = ics_parse_tzid($params);
    if ($tzid !== null) {
        $tz = ics_timezone($tzid, $fallbackTz);
    }

    if (str_ends_with($value, 'Z')) {
        $t = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        if ($t) {
            return [$t->getTimestamp(), false];
        }
    }

    $t = DateTime::createFromFormat('Ymd\THis', $value, $tz);
    if (!$t) {
        $t = DateTime::createFromFormat('Ymd\THi', $value, $tz);
    }

    return $t ? [$t->getTimestamp(), false] : null;
}

function ics_add_local_days(int $dayMidnight, int $days): int
{
    $dt = (new DateTime('@' . $dayMidnight))->setTimezone(calendar_display_timezone());
    $dt->modify(($days >= 0 ? '+' : '') . $days . ' days');
    $dt->setTime(0, 0, 0);

    return $dt->getTimestamp();
}

/** @return array{0:int,1:int,2:int,3:int} year, month, day-of-month, days-in-month */
function ics_local_ymd(int $dayMidnight): array
{
    $dt = (new DateTime('@' . $dayMidnight))->setTimezone(calendar_display_timezone());

    return [
        (int)$dt->format('Y'),
        (int)$dt->format('n'),
        (int)$dt->format('j'),
        (int)$dt->format('t'),
    ];
}

/**
 * Feed keys allowed on unauthenticated kiosks (plain board.php / main). Empty when unset.
 *
 * @return list<string>
 */
function calendar_public_feed_keys(): array
{
    $conf = cfg_all();
    $cfgKey = 'calendar.PUBLIC_FEED_KEYS';
    if (!array_key_exists($cfgKey, $conf)) {
        return [];
    }
    $raw = $conf[$cfgKey];
    if (!is_array($raw)) {
        return [];
    }

    return calendar_normalize_feed_key_list($raw);
}

/**
 * Feed keys an operator may pick for their display kiosk settings (owned/shared feeds only).
 *
 * @return list<string>
 */
function calendar_admin_selectable_feed_keys(): array
{
    require_once __DIR__ . '/users_lib.php';
    require_once __DIR__ . '/rotation_calendar_lib.php';

    $raw = cfg('calendar.ICS_FEEDS', []);
    if (!is_array($raw)) {
        return [];
    }
    if (admin_is_super()) {
        return rotation_calendar_feed_keys();
    }
    $feeds = admin_filter_list_for_display($raw);
    $keys = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, $i++);
        $keys[] = $meta['key'];
    }

    return calendar_normalize_feed_key_list($keys);
}

/** Whether kiosk settings may change calendar_feeds (operators never on main). */
function calendar_admin_may_edit_display_feeds(string $screen): bool
{
    require_once __DIR__ . '/users_lib.php';
    require_once __DIR__ . '/rotation_lib.php';

    $screen = rotation_normalize_screen_key($screen);
    if ($screen === '') {
        return false;
    }
    if ($screen === 'main') {
        return admin_is_super();
    }

    return admin_is_super() || admin_has_full_screen_edit($screen);
}

/** @param list<mixed> $keys @return list<string> */
function calendar_normalize_feed_key_list(array $keys): array
{
    $out = [];
    $seen = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '') {
            continue;
        }
        $id = strtolower($key);
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $key;
    }

    return $out;
}

/**
 * @param list<mixed> $posted
 * @param list<array<string,mixed>> $feeds
 * @return list<string>
 */
function calendar_normalize_public_feed_keys_from_post(array $posted, array $feeds): array
{
    $catalog = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, $i++);
        $catalog[strtolower($meta['key'])] = $meta['key'];
    }
    $out = [];
    foreach ($posted as $key) {
        $id = strtolower(trim((string)$key));
        if ($id === '' || !isset($catalog[$id])) {
            continue;
        }
        $out[] = $catalog[$id];
    }

    return calendar_normalize_feed_key_list($out);
}

/** @param list<array<string,mixed>> $feeds @param list<string> $allowedKeys @return list<array<string,mixed>> */
function calendar_filter_feeds_by_keys(array $feeds, array $allowedKeys): array
{
    $allowedKeys = calendar_normalize_feed_key_list($allowedKeys);
    if ($allowedKeys === []) {
        return [];
    }
    $want = [];
    foreach ($allowedKeys as $key) {
        $want[strtolower($key)] = true;
    }
    $out = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, $i);
        if (isset($want[strtolower($meta['key'])])) {
            $out[] = $feed;
        }
        $i++;
    }

    return $out;
}

/**
 * ICS feeds for calendar.php / glance.php — respects operator scope, public allowlist, and per-display picks.
 *
 * @return list<array<string,mixed>>
 */
function calendar_feeds_for_signage(?string $screen = null): array
{
    require_once __DIR__ . '/users_lib.php';
    require_once __DIR__ . '/screen_scope_lib.php';

    if ($screen === null || $screen === '') {
        $screen = signage_request_screen();
    } else {
        $screen = rotation_normalize_screen_key($screen);
    }

    $raw = cfg('calendar.ICS_FEEDS', []);
    if (!is_array($raw)) {
        $raw = [];
    }

    $feeds = admin_filter_list_for_display($raw);

    if (admin_display_scope_user_id() === null) {
        $feeds = calendar_filter_feeds_by_keys($feeds, calendar_public_feed_keys());
    }

    $screenKeys = rotation_screen_calendar_feed_keys($screen);
    if ($screenKeys !== []) {
        $feeds = calendar_filter_feeds_by_keys($feeds, $screenKeys);
    }

    return array_values($feeds);
}

/** @return list<string> Countdown labels defined in calendar config. */
function calendar_configured_countdown_keys(): array
{
    $raw = cfg('calendar.COUNTDOWNS', []);
    if (!is_array($raw)) {
        return [];
    }
    $keys = [];
    foreach ($raw as $label => $v) {
        $label = trim((string)$label);
        if ($label !== '') {
            $keys[] = $label;
        }
    }

    return calendar_normalize_feed_key_list($keys);
}

/**
 * Countdown labels allowed on unauthenticated kiosks (plain board.php / main). Empty when unset.
 *
 * @return list<string>
 */
function calendar_public_countdown_keys(): array
{
    $conf = cfg_all();
    $cfgKey = 'calendar.PUBLIC_COUNTDOWN_KEYS';
    if (!array_key_exists($cfgKey, $conf)) {
        return [];
    }
    $raw = $conf[$cfgKey];
    if (!is_array($raw)) {
        return [];
    }

    return calendar_normalize_feed_key_list($raw);
}

/**
 * Countdown labels an operator may pick for their display (owned/shared rows only).
 *
 * @return list<string>
 */
function calendar_admin_selectable_countdown_keys(): array
{
    require_once __DIR__ . '/users_lib.php';

    $raw = cfg('calendar.COUNTDOWNS', []);
    if (!is_array($raw)) {
        return [];
    }
    if (admin_is_super()) {
        return calendar_configured_countdown_keys();
    }
    $filtered = admin_filter_scalar_map_for_display($raw);
    $keys = [];
    foreach ($filtered as $label => $v) {
        $label = trim((string)$label);
        if ($label !== '') {
            $keys[] = $label;
        }
    }

    return calendar_normalize_feed_key_list($keys);
}

/**
 * @param list<mixed> $posted
 * @param array<string,mixed> $countdowns
 * @return list<string>
 */
function calendar_normalize_public_countdown_keys_from_post(array $posted, array $countdowns): array
{
    $catalog = [];
    foreach ($countdowns as $label => $v) {
        $label = trim((string)$label);
        if ($label === '') {
            continue;
        }
        $catalog[strtolower($label)] = $label;
    }
    $out = [];
    foreach ($posted as $key) {
        $id = strtolower(trim((string)$key));
        if ($id === '' || !isset($catalog[$id])) {
            continue;
        }
        $out[] = $catalog[$id];
    }

    return calendar_normalize_feed_key_list($out);
}

/** @param array<string,mixed> $map @param list<string> $allowedKeys @return array<string,mixed> */
function calendar_filter_countdowns_by_keys(array $map, array $allowedKeys): array
{
    $allowedKeys = calendar_normalize_feed_key_list($allowedKeys);
    if ($allowedKeys === []) {
        return [];
    }
    $want = [];
    foreach ($allowedKeys as $key) {
        $want[strtolower($key)] = true;
    }
    $out = [];
    foreach ($map as $label => $v) {
        $id = strtolower(trim((string)$label));
        if ($id === '' || !isset($want[$id])) {
            continue;
        }
        $out[(string)$label] = $v;
    }

    return $out;
}

/**
 * Countdown rows for calendar.php — operator scope, public allowlist, and per-display picks.
 *
 * @return array<string,mixed>
 */
function calendar_countdowns_for_signage(?string $screen = null): array
{
    require_once __DIR__ . '/users_lib.php';
    require_once __DIR__ . '/screen_scope_lib.php';

    if ($screen === null || $screen === '') {
        $screen = signage_request_screen();
    } else {
        $screen = rotation_normalize_screen_key($screen);
    }

    $raw = cfg('calendar.COUNTDOWNS', []);
    if (!is_array($raw)) {
        $raw = [];
    }

    $map = admin_filter_scalar_map_for_display($raw);

    if (admin_display_scope_user_id() === null) {
        $map = calendar_filter_countdowns_by_keys($map, calendar_public_countdown_keys());
    }

    $screenKeys = rotation_screen_calendar_countdown_keys($screen);
    if ($screenKeys !== []) {
        $map = calendar_filter_countdowns_by_keys($map, $screenKeys);
    }

    return $map;
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
