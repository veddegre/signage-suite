<?php
/**
 * CALENDAR BOARD — 1920×1080 signage
 * Today + the week ahead from one or more ICS calendar feeds, trash/recycle
 * day, and countdowns to dates that matter.
 *
 * Setup:
 *   ICS_FEEDS — iCal subscription URLs and/or WebDAV/CalDAV calendars (Nextcloud,
 *     Radicale, etc.). Each row: name, source (ical|webdav), URL, optional user/
 *     password, color.
 *   TRASH_WEEKDAY — pickup day (leave unset to hide — e.g. apartment). RECYCLE_ANCHOR —
 *     any date recycling was collected, for every-other-week cadence ('' to disable).
 *   COUNTDOWNS — [label => YYYY-MM-DD].
 *
 * Recurring events: DAILY, WEEKLY (BYDAY, INTERVAL, WKST), MONTHLY (BYMONTHDAY), and YEARLY
 * rules are expanded, with INTERVAL/UNTIL/EXDATE honored. Outlook biweekly patterns use
 * INTERVAL=2 with WKST=SU; weekly+EXDATE skips are matched by local calendar day.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/security_lib.php';
require_once dirname(__DIR__, 2) . '/lib/calendar_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';

$icsFeeds = cfg('calendar.ICS_FEEDS', []);
if (!is_array($icsFeeds)) {
    $icsFeeds = [];
}
define('ICS_FEEDS', admin_filter_list_for_display($icsFeeds));
define('TRASH_WEEKDAY', cfg('calendar.TRASH_WEEKDAY', ''));
define('RECYCLE_ANCHOR', cfg('calendar.RECYCLE_ANCHOR', ''));
$countdowns = cfg('calendar.COUNTDOWNS', []);
if (!is_array($countdowns)) {
    $countdowns = [];
}
define('COUNTDOWNS', admin_filter_scalar_map_for_display($countdowns));
define('TIMEZONE', cfg('calendar.TIMEZONE', 'America/Detroit'));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('calendar.CACHE_TTL', 600));

date_default_timezone_set(TIMEZONE);
$frameH = signage_frame_height();
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function cached_get(string $url, string $key, ?array $auth = null): ?string
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        $GLOBALS['diag'][$key] = $policy['error'] ?? 'blocked URL';
        return null;
    }
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) return (string)file_get_contents($f);
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'HomeSignage/1.0'];
    if ($auth !== null && ($auth[0] ?? '') !== '') {
        $opts[CURLOPT_USERPWD] = $auth[0] . ':' . ($auth[1] ?? '');
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;
}

function calendar_feed_cache_key(int $i, array $feed, int $winStart): string
{
    $blob = ($feed['url'] ?? '') . '|' . ($feed['user'] ?? '') . '|' . ($feed['source'] ?? 'ical')
          . '|' . date('Ymd', $winStart);
    return 'ics_' . $i . '_' . substr(sha1($blob), 0, 12);
}

function calendar_feed_auth(array $feed): ?array
{
    $user = trim((string)($feed['user'] ?? ''));
    if ($user === '') {
        return null;
    }
    return [$user, (string)($feed['password'] ?? '')];
}

function caldav_normalize_url(string $url): string
{
    $url = trim($url);
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return rtrim($url, '/') . '/';
    }
    $path = $parts['path'] ?? '/';
    $path = preg_replace('#/+#', '/', $path);
    if ($path === '') {
        $path = '/';
    }
    if (!preg_match('/\.ics(\?|$)/i', $path) && !str_ends_with($path, '/')) {
        $path .= '/';
    }
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . $path . $query;
}

function caldav_auth_error(int $code, string $host): string
{
    if ($code === 401 || $code === 403) {
        if (str_contains(strtolower($host), 'fastmail.com')) {
            return "HTTP $code — Fastmail needs your full email as user and an app-specific password "
                 . '(Settings → Privacy & Security → App Passwords; not your login password)';
        }
        return "HTTP $code — check CalDAV user and password";
    }
    return "HTTP $code";
}

/** CalDAV calendar-query — returns merged ICS text for the event window. */
function caldav_fetch(string $url, ?array $auth, int $winStart, int $winEnd, string $key): ?string
{
    $url = caldav_normalize_url($url);
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        $GLOBALS['diag'][$key] = $policy['error'] ?? 'blocked URL';
        return null;
    }
    if ($auth === null || trim((string)($auth[0] ?? '')) === '') {
        $GLOBALS['diag'][$key] = 'CalDAV feed requires user and password';
        return null;
    }
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        return (string)file_get_contents($f);
    }

    $start = gmdate('Ymd\THis\Z', $winStart);
    $end = gmdate('Ymd\THis\Z', $winEnd + 86400);
    $xmlBody = '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:prop><D:getetag/><C:calendar-data/></D:prop>'
        . '<C:filter><C:comp-filter name="VCALENDAR">'
        . '<C:comp-filter name="VEVENT">'
        . '<C:time-range start="' . $start . '" end="' . $end . '"/>'
        . '</C:comp-filter></C:comp-filter></C:filter>'
        . '</C:calendar-query>';

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'REPORT',
        CURLOPT_POSTFIELDS => $xmlBody,
        CURLOPT_HTTPHEADER => [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'Accept: text/calendar, application/xml',
        ],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $auth[0] . ':' . ($auth[1] ?? ''),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HomeSignage/1.0',
    ];
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && in_array($code, [200, 207], true)) {
        $ics = caldav_response_to_ics((string)$body);
        if ($ics !== '') {
            @file_put_contents($f, $ics, LOCK_EX);
            return $ics;
        }
        $GLOBALS['diag'][$key] = 'empty CalDAV response (no events in range)';
    } else {
        $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : caldav_auth_error($code, $host);
    }
    return is_file($f) ? (string)file_get_contents($f) : null;
}

function caldav_unescape_ics(string $block): string
{
    $block = html_entity_decode($block, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return str_replace(["\\n", "\\N", "\\r"], ["\n", "\n", ''], $block);
}

function caldav_response_to_ics(string $xml): string
{
    $chunks = [];

    if (class_exists('SimpleXMLElement')) {
        libxml_use_internal_errors(true);
        $sx = @simplexml_load_string($xml);
        if ($sx !== false) {
            $nodes = $sx->xpath('//*[local-name()="calendar-data"]');
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $block = caldav_unescape_ics(trim((string)$node));
                    if ($block !== '' && stripos($block, 'BEGIN:VEVENT') !== false) {
                        $chunks[] = $block;
                    }
                }
            }
        }
    }

    if ($chunks === [] && preg_match_all(
        '/<(?:[\w-]+:)?calendar-data[^>]*>\s*(.*?)\s*<\/(?:[\w-]+:)?calendar-data>/is',
        $xml,
        $m
    )) {
        foreach ($m[1] as $block) {
            $block = caldav_unescape_ics(trim($block));
            if ($block !== '' && stripos($block, 'BEGIN:VEVENT') !== false) {
                $chunks[] = $block;
            }
        }
    }

    if ($chunks === []) {
        return '';
    }
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//HomeSignage//CalDAV//EN\r\n"
        . implode("\r\n", $chunks) . "\r\nEND:VCALENDAR\r\n";
}

function fetch_calendar_feed(array $feed, int $i, int $winStart, int $winEnd): ?string
{
    $url = trim((string)($feed['url'] ?? ''));
    if ($url === '') {
        return null;
    }
    $source = strtolower(trim((string)($feed['source'] ?? 'ical')));
    if ($source !== 'webdav') {
        $source = 'ical';
    }
    $auth = calendar_feed_auth($feed);
    $key = calendar_feed_cache_key($i, $feed, $winStart);

    if ($source === 'webdav' && !preg_match('/\.ics(\?|$)/i', $url)) {
        return caldav_fetch(caldav_normalize_url($url), $auth, $winStart, $winEnd, $key);
    }
    return cached_get($url, $key, $auth);
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Minimal ICS parsing ──────────────────────────────────────────────────────
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

/** Map Outlook/Windows TZID names to IANA zones PHP understands. */
function ics_timezone(string $tzid): DateTimeZone
{
    static $windows = [
        'Eastern Standard Time' => 'America/Detroit',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'US Eastern Standard Time' => 'America/Detroit',
        'UTC' => 'UTC',
        'GMT Standard Time' => 'Europe/London',
    ];
    $tzid = trim($tzid);
    if (isset($windows[$tzid])) {
        return new DateTimeZone($windows[$tzid]);
    }
    try {
        return new DateTimeZone($tzid);
    } catch (Throwable $e) {
        return new DateTimeZone(TIMEZONE);
    }
}

/** Parse a DTSTART/DTEND value (+params) into [unix_ts, all_day]. */
function ics_time(string $params, string $value): ?array
{
    if (stripos($params, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $value)) {
        $t = DateTime::createFromFormat('Ymd', substr($value, 0, 8), new DateTimeZone(TIMEZONE));
        return $t ? [$t->setTime(0, 0)->getTimestamp(), true] : null;
    }
    $tz = new DateTimeZone(TIMEZONE);
    if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
        $tz = ics_timezone($m[1]);
    }
    if (str_ends_with($value, 'Z')) {
        $t = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
    } else {
        $t = DateTime::createFromFormat('Ymd\THis', $value, $tz);
    }
    return $t ? [$t->getTimestamp(), false] : null;
}

function parse_rrule(string $r): array
{
    $out = [];
    foreach (explode(';', $r) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) {
            continue;
        }
        $key = strtoupper(trim($kv[0]));
        $val = trim($kv[1]);
        // BYDAY/WKST are day tokens; INTERVAL/COUNT stay numeric.
        $out[$key] = in_array($key, ['BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'BYWEEKNO'], true)
            ? strtoupper($val) : $val;
    }
    return $out;
}

/** @return list<array{ord:?int,dow:int}> */
function ics_parse_byday_rules(string $byday): array
{
    static $dayMap = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
    $rules = [];
    foreach (explode(',', $byday) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $part, $m)) {
            $ord = ($m[1] ?? '') !== '' ? (int)$m[1] : null;
            $rules[] = ['ord' => $ord, 'dow' => $dayMap[$m[2]]];
        }
    }
    return $rules;
}

/** Does $day fall on the requested weekday position (e.g. 2nd Friday)? */
function ics_day_matches_byday(int $day, array $rule): bool
{
    if ((int)date('N', $day) !== $rule['dow']) {
        return false;
    }
    $ord = $rule['ord'];
    if ($ord === null) {
        return true;
    }
    $year = (int)date('Y', $day);
    $month = (int)date('n', $day);
    $dom = (int)date('j', $day);
    if ($ord > 0) {
        $count = 0;
        for ($d = 1; $d <= $dom; $d++) {
            if ((int)date('N', mktime(12, 0, 0, $month, $d, $year)) === $rule['dow']) {
                $count++;
            }
        }
        return $count === $ord;
    }
    $daysInMonth = (int)date('t', $day);
    $count = 0;
    for ($d = $daysInMonth; $d >= $dom; $d--) {
        if ((int)date('N', mktime(12, 0, 0, $month, $d, $year)) === $rule['dow']) {
            $count++;
        }
    }
    return $count === abs($ord);
}

function ics_months_between(int $day, int $start): int
{
    return ((int)date('Y', $day) * 12 + (int)date('n', $day))
         - ((int)date('Y', $start) * 12 + (int)date('n', $start));
}

function ics_is_excluded(int $ts, array $ev): bool
{
    $dayKey = date('Ymd', $ts);
    foreach ($ev['exdate_ts'] ?? [] as $ex) {
        if ($ex === $ts) {
            return true;
        }
        // Outlook EXDATE often differs by TZ/UTC from expanded instances — match local day.
        if (date('Ymd', $ex) === $dayKey) {
            return true;
        }
    }
    return in_array($dayKey, $ev['exdates'] ?? [], true);
}

/** Each local-midnight timestamp for an all-day span (end is iCal-exclusive). */
function ics_all_day_instances(array $ev, int $winStart, int $winEnd): array
{
    $tz = new DateTimeZone(TIMEZONE);
    $start = (new DateTime('@' . $ev['start']))->setTimezone($tz)->setTime(0, 0, 0);
    $endEx = (int)($ev['end'] ?? ($ev['start'] + 86400));
    $end = (new DateTime('@' . $endEx))->setTimezone($tz)->setTime(0, 0, 0);
    if ($end <= $start) {
        $end = (clone $start)->modify('+1 day');
    }
    $out = [];
    for ($d = clone $start; $d < $end; $d->modify('+1 day')) {
        $ts = $d->getTimestamp();
        if ($ts >= $winStart && $ts <= $winEnd) {
            $out[] = $ts;
        }
    }
    return $out;
}

/** Normalize Outlook all-day quirks after DTSTART/DTEND are parsed. */
function ics_finalize_vevent(array $cur): array
{
    if ($cur['start'] === null) {
        return $cur;
    }
    if (!empty($cur['ms_all_day']) && !$cur['all_day'] && ($cur['end'] ?? null) !== null) {
        $startDay = strtotime('today', $cur['start']);
        $endDay = strtotime('today', $cur['end']);
        if ($endDay > $startDay || ($cur['end'] - $cur['start']) >= 82800) {
            $cur['all_day'] = true;
            $cur['start'] = $startDay;
            $cur['end'] = $cur['end'] > $endDay ? $endDay + 86400 : $endDay;
            if ($cur['end'] <= $cur['start']) {
                $cur['end'] = $cur['start'] + 86400;
            }
        }
    }
    // Outlook work calendar: OOF blocks (holidays/vacation) export as timed 8–5, not VALUE=DATE.
    if (!$cur['all_day'] && ($cur['busy'] ?? '') === 'OOF' && ($cur['end'] ?? null) !== null) {
        $dur = $cur['end'] - $cur['start'];
        if ($dur >= 4 * 3600) {
            $first = strtotime('today', $cur['start']);
            $last = strtotime('today', $cur['end']);
            $cur['all_day'] = true;
            $cur['start'] = $first;
            $cur['end'] = strtotime('+1 day', $last);
        }
    }
    if ($cur['all_day'] && ($cur['end'] ?? null) === null) {
        $cur['end'] = $cur['start'] + 86400;
    }
    return $cur;
}

/** Expand one VEVENT into instances inside [winStart, winEnd]. */
function expand_event(array $ev, int $winStart, int $winEnd, array $overrides = []): array
{
    $out = [];
    $start = $ev['start'];
    $allDay = $ev['all_day'];
    $uid = $ev['uid'] ?? '';
    $push = function (int $ts) use (&$out, $ev, $allDay, $uid, $overrides) {
        if (ics_is_excluded($ts, $ev)) {
            return;
        }
        if ($uid !== '' && isset($overrides[$uid][$ts])) {
            return;
        }
        $out[] = ['ts' => $ts, 'all_day' => $allDay, 'summary' => $ev['summary'],
                  'cal' => $ev['cal'], 'color' => $ev['color'], 'hex' => $ev['hex']];
    };

    if (!$ev['rrule']) {
        if ($allDay) {
            foreach (ics_all_day_instances($ev, $winStart, $winEnd) as $ts) {
                $push($ts);
            }
        } elseif ($start >= $winStart && $start <= $winEnd) {
            $push($start);
        }
        return $out;
    }

    $r = $ev['rrule'];
    $freq = $r['FREQ'] ?? '';
    $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $until = isset($r['UNTIL']) ? (ics_time('', $r['UNTIL'])[0] ?? PHP_INT_MAX) : PHP_INT_MAX;
    $bydayRules = ics_parse_byday_rules($r['BYDAY'] ?? '');
    $weeklyDays = array_values(array_filter(
        array_map(fn($rule) => $rule['ord'] === null ? $rule['dow'] : null, $bydayRules),
        fn($d) => $d !== null
    ));

    $tod = $allDay ? 0 : ($start - strtotime('today', $start));
    for ($day = strtotime('today', $winStart); $day <= $winEnd; $day += 86400) {
        $ts = $day + $tod;
        if ($ts < $start || $ts > $until || $ts < $winStart || $ts > $winEnd) {
            continue;
        }
        $match = false;
        switch ($freq) {
            case 'DAILY':
                $match = ((int)floor(($day - strtotime('today', $start)) / 86400)) % $interval === 0;
                break;
            case 'WEEKLY':
                $wkstIso = ics_wkst_to_iso($r['WKST'] ?? 'MO');
                $weeks = ics_weeks_since_start($day, strtotime('today', $start), $wkstIso);
                $dow = (int)date('N', $day);
                $days = $weeklyDays !== [] ? $weeklyDays : [(int)date('N', $start)];
                $match = $weeks >= 0 && $weeks % $interval === 0 && in_array($dow, $days, true);
                break;
            case 'MONTHLY':
                $months = ics_months_between($day, $start);
                if ($months % $interval !== 0) {
                    break;
                }
                if ($bydayRules !== []) {
                    foreach ($bydayRules as $rule) {
                        if (ics_day_matches_byday($day, $rule)) {
                            $match = true;
                            break;
                        }
                    }
                } else {
                    $dom = (int)($r['BYMONTHDAY'] ?? date('j', $start));
                    $match = (int)date('j', $day) === $dom;
                }
                break;
            case 'YEARLY':
                $years = (int)date('Y', $day) - (int)date('Y', $start);
                if ($years % $interval !== 0) {
                    break;
                }
                if (isset($r['BYMONTH']) && (int)date('n', $day) !== (int)$r['BYMONTH']) {
                    break;
                }
                if ($bydayRules !== []) {
                    foreach ($bydayRules as $rule) {
                        if (ics_day_matches_byday($day, $rule)) {
                            $match = true;
                            break;
                        }
                    }
                } else {
                    $match = date('md', $day) === date('md', $start);
                }
                break;
        }
        if ($match) {
            $push($ts);
        }
    }
    return $out;
}

function parse_ics_vevents(string $raw, array $feedMeta): array
{
    $lines = ics_unfold($raw);
    $vevents = [];
    $cur = null;
    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $cur = [
                'start' => null, 'end' => null, 'all_day' => false, 'summary' => '', 'rrule' => null,
                'exdates' => [], 'exdate_ts' => [], 'uid' => '', 'recurrence_id' => null,
                'status' => '', 'busy' => '', 'ms_all_day' => false,
                'cal' => (string)($feedMeta['key'] ?? ''),
                'color' => (string)($feedMeta['color'] ?? 'beacon'),
                'hex' => (string)($feedMeta['hex'] ?? '#ffb347'),
            ];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($cur && $cur['start'] !== null && strtoupper($cur['status']) !== 'CANCELLED') {
                $vevents[] = ics_finalize_vevent($cur);
            }
            $cur = null;
            continue;
        }
        if ($cur === null) {
            continue;
        }
        $sep = strpos($line, ':');
        if ($sep === false) {
            continue;
        }
        $left = substr($line, 0, $sep);
        $value = substr($line, $sep + 1);
        $parts = explode(';', $left, 2);
        $prop = strtoupper($parts[0]);
        $params = $parts[1] ?? '';
        switch ($prop) {
            case 'DTSTART':
                $t = ics_time($params, $value);
                if ($t) {
                    $cur['start'] = $t[0];
                    $cur['all_day'] = $t[1];
                }
                break;
            case 'DTEND':
                $t = ics_time($params, $value);
                if ($t) {
                    $cur['end'] = $t[0];
                    if ($t[1]) {
                        $cur['all_day'] = true;
                    }
                }
                break;
            case 'RECURRENCE-ID':
                $t = ics_time($params, $value);
                if ($t) {
                    $cur['recurrence_id'] = $t[0];
                }
                break;
            case 'UID':
                $cur['uid'] = trim($value);
                break;
            case 'STATUS':
                $cur['status'] = strtoupper(trim($value));
                break;
            case 'SUMMARY':
                $cur['summary'] = str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $value);
                break;
            case 'RRULE':
                $cur['rrule'] = parse_rrule($value);
                break;
            case 'EXDATE':
                foreach (explode(',', $value) as $x) {
                    $x = trim($x);
                    $t = ics_time($params, $x);
                    if ($t) {
                        $cur['exdate_ts'][] = $t[0];
                    } else {
                        $cur['exdates'][] = substr($x, 0, 8);
                    }
                }
                break;
            case 'X-MICROSOFT-CDO-BUSYSTATUS':
                $cur['busy'] = strtoupper(trim($value));
                break;
            case 'X-MICROSOFT-CDO-ALLDAYEVENT':
                $cur['ms_all_day'] = strtoupper(trim($value)) === 'TRUE';
                break;
        }
    }
    return $vevents;
}

if (defined('SIGNAGE_CALENDAR_LIB_ONLY') && SIGNAGE_CALENDAR_LIB_ONLY) {
    return;
}

// ── Gather events for today + 6 days ────────────────────────────────────────
$winStart = strtotime('today');
$winEnd   = strtotime('today +7 days') - 1;
$events   = [];

foreach (ICS_FEEDS as $i => $feed) {
    if (!is_array($feed)) {
        continue;
    }
    $raw = fetch_calendar_feed($feed, $i, $winStart, $winEnd);
    if ($raw === null) {
        continue;
    }
    $meta = calendar_feed_meta($feed, $i);
    $vevents = parse_ics_vevents($raw, $meta);
    $overrides = [];
    $masters = [];
    foreach ($vevents as $ev) {
        if ($ev['recurrence_id'] !== null && ($ev['uid'] ?? '') !== '') {
            $overrides[$ev['uid']][$ev['recurrence_id']] = true;
            if ($ev['all_day']) {
                foreach (ics_all_day_instances($ev, $winStart, $winEnd) as $ts) {
                    $events[] = [
                        'ts' => $ts,
                        'all_day' => true,
                        'summary' => $ev['summary'],
                        'cal' => $ev['cal'],
                        'color' => $ev['color'],
                        'hex' => $ev['hex'],
                    ];
                }
            } elseif ($ev['start'] >= $winStart && $ev['start'] <= $winEnd) {
                $events[] = [
                    'ts' => $ev['start'],
                    'all_day' => $ev['all_day'],
                    'summary' => $ev['summary'],
                    'cal' => $ev['cal'],
                    'color' => $ev['color'],
                    'hex' => $ev['hex'],
                ];
            }
            continue;
        }
        $masters[] = $ev;
    }
    foreach ($masters as $ev) {
        foreach (expand_event($ev, $winStart, $winEnd, $overrides) as $inst) {
            $events[] = $inst;
        }
    }
}
usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);

// Bucket by day
$days = [];
for ($d = 0; $d < 7; $d++) {
    $key = date('Y-m-d', strtotime("+$d day", $winStart));
    $days[$key] = [];
}
foreach ($events as $e) {
    $key = date('Y-m-d', $e['ts']);
    if (isset($days[$key])) $days[$key][] = $e;
}

// ── Trash & recycling (optional — leave TRASH_WEEKDAY unset to hide) ─────────
$showTrash = TRASH_WEEKDAY !== '';
$trashLabel = '';
$recycleWeek = false;
if ($showTrash) {
    $trashNext = strtotime('this ' . TRASH_WEEKDAY, $winStart);
    if (date('l') === TRASH_WEEKDAY) $trashNext = $winStart;
    $daysToTrash = (int)floor(($trashNext - $winStart) / 86400);
    if (RECYCLE_ANCHOR !== '') {
        $weeks = (int)floor(($trashNext - strtotime(RECYCLE_ANCHOR)) / 604800);
        $recycleWeek = $weeks % 2 === 0;
    }
    $trashLabel = $daysToTrash === 0 ? 'TODAY' : ($daysToTrash === 1 ? 'TOMORROW' : date('l', $trashNext));
}

// Countdowns
$counts = [];
foreach (COUNTDOWNS as $label => $date) {
    if (is_array($date)) {
        $date = trim((string)($date['value'] ?? ''));
    }
    $date = trim((string)$date);
    $d = (int)ceil((strtotime($date) - time()) / 86400);
    if ($d >= 0) $counts[] = [$label, $d];
}
usort($counts, fn($a, $b) => $a[1] <=> $b[1]);

$calLegend = calendar_legend(is_array(ICS_FEEDS) ? ICS_FEEDS : []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Calendar</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:grid; gap:24px;
           grid-template-columns: 600px 1fr; grid-template-rows: minmax(0,1fr) 150px auto;
           grid-template-areas: "today week" "strip strip" "meta meta"; }

  .today { grid-area:today; background:var(--harbor); border:1px solid var(--hairline);
           border-radius:14px; padding:38px 42px; display:flex; flex-direction:column; min-height:0; }
  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:110px; line-height:1; }
  #clock span { font-size:44px; color:var(--mist); }
  .dateline { font-size:30px; color:var(--mist); margin-top:6px; }
  .today .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist);
              margin:30px 0 8px; border-top:1px solid var(--hairline); padding-top:24px; }
  .cal-legend { display:flex; flex-wrap:wrap; gap:18px 28px; margin-top:22px; }
  .cal-legend .leg { display:flex; align-items:center; gap:10px; font-size:20px; color:var(--snow); }
  .cal-legend .dot { width:14px; height:14px; border-radius:50%; flex-shrink:0;
                     box-shadow:0 0 0 2px rgba(255,255,255,.12); }
  .tev { display:flex; gap:14px; align-items:baseline; padding:11px 0; }
  .tev .who { font-size:18px; font-weight:600; letter-spacing:1px; text-transform:uppercase;
              min-width:52px; flex-shrink:0; opacity:.95; }
  .tev .t { font-family:'Big Shoulders Display'; font-weight:600; font-size:30px; min-width:120px;
            color:var(--beacon); font-variant-numeric:tabular-nums; }
  .tev .s { font-size:28px; }
  .free { font-size:28px; color:var(--mist); padding:14px 0; }

  .week { grid-area:week; display:grid; grid-template-columns:repeat(6,1fr); gap:16px; min-height:0; }
  .day { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
         padding:18px; overflow:hidden; display:flex; flex-direction:column; }
  .day .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:34px;
            letter-spacing:1px; text-transform:uppercase; }
  .day .d { font-size:19px; color:var(--mist); margin-bottom:12px; }
  .ev { font-size:20px; line-height:1.3; padding:7px 0 7px 14px; border-left:4px solid var(--beacon);
        margin-bottom:8px; overflow:hidden; }
  .ev .ewho { font-size:15px; font-weight:600; letter-spacing:.8px; text-transform:uppercase;
              display:block; margin-bottom:3px; }
  .ev .et { color:var(--mist); font-size:17px; display:block; }
  .more { font-size:18px; color:var(--mist); margin-top:auto; }
  .nothing { font-size:19px; color:var(--mist); opacity:.6; }

  .strip { grid-area:strip; display:flex; gap:24px; }
  .chip { flex:1; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:20px 28px; display:flex; align-items:center; justify-content:space-between; }
  .chip .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .chip .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:50px; }
  .chip .v small { font-size:26px; color:var(--mist); font-weight:600; }
  .chip.trash .v { color:var(--beacon); }
  .setup { font-size:24px; color:var(--mist); line-height:1.6; }
  .setup code { background:var(--lake-night); padding:2px 8px; border-radius:6px; color:var(--snow); }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <section class="today">
    <?php if ($showClock): ?><div id="clock">--:--<span> --</span></div><?php endif; ?>
    <div class="dateline" id="dateline">&nbsp;</div>
    <?php if ($calLegend !== []): ?>
    <div class="cal-legend" aria-label="Calendar key">
      <?php foreach ($calLegend as $leg): ?>
      <span class="leg"><span class="dot" style="background:<?= h($leg['hex']) ?>"></span><?= h($leg['key']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="k">Today</div>
    <?php $todayKey = date('Y-m-d');
    if (ICS_FEEDS === []) : ?>
      <div class="setup">Add calendar feeds in admin — iCal subscription URLs or WebDAV/CalDAV
        (Nextcloud, Radicale, …) with user/password when required.</div>
    <?php elseif ($days[$todayKey]): foreach (array_slice($days[$todayKey], 0, 7) as $e): ?>
      <div class="tev">
        <span class="who" style="color:<?= h($e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''))) ?>"><?= h($e['cal']) ?></span>
        <span class="t" style="color:<?= h($e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''))) ?>"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
        <span class="s"><?= h($e['summary']) ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="free">Nothing on the calendar — enjoy it.</div>
    <?php endif; ?>
  </section>

  <section class="week">
    <?php $keys = array_keys($days);
    foreach (array_slice($keys, 1) as $key): $list = $days[$key]; $ts = strtotime($key); ?>
      <div class="day">
        <div class="n"><?= date('D', $ts) ?></div>
        <div class="d"><?= date('M j', $ts) ?></div>
        <?php foreach (array_slice($list, 0, 4) as $e):
          $hex = $e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''));
        ?>
          <div class="ev" style="border-color:<?= h($hex) ?>">
            <span class="ewho" style="color:<?= h($hex) ?>"><?= h($e['cal']) ?></span>
            <?= h($e['summary']) ?>
            <span class="et"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (count($list) > 4): ?><div class="more">+<?= count($list) - 4 ?> more</div><?php endif; ?>
        <?php if (!$list): ?><div class="nothing">—</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="strip">
    <?php if ($showTrash): ?>
    <div class="chip trash">
      <span class="k"><?= $recycleWeek ? 'Trash + Recycling' : 'Trash' ?></span>
      <span class="v"><?= h($trashLabel) ?></span>
    </div>
    <?php endif; ?>
    <?php foreach (array_slice($counts, 0, 3) as $c): ?>
      <div class="chip">
        <span class="k"><?= h($c[0]) ?></span>
        <span class="v"><?= $c[1] ?><small> <?= $c[1] === 1 ? 'day' : 'days' ?></small></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$counts): ?>
      <div class="chip"><span class="k">Countdowns</span>
        <span class="v" style="font-size:26px;color:var(--mist)">add dates to COUNTDOWNS</span></div>
    <?php endif; ?>
  </section>
  <div class="stamp">ICS feeds refresh every 10 min<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  function tick(){
    const n = new Date();
    <?php if ($showClock): ?>
    let h = n.getHours(); const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    document.getElementById('clock').innerHTML =
      h + ':' + String(n.getMinutes()).padStart(2,'0') + '<span> ' + ap + '</span>';
    <?php endif; ?>
    document.getElementById('dateline').textContent =
      n.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric' });
  }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
