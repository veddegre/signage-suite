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
 * INTERVAL=2 with WKST=SU, weekly+EXDATE skips, or a weekly RRULE with cadence in the title.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/security_lib.php';
require_once dirname(__DIR__, 2) . '/lib/calendar_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';

define('ICS_FEEDS', calendar_feeds_for_signage(signage_request_screen()));
define('TRASH_WEEKDAY', cfg('calendar.TRASH_WEEKDAY', ''));
define('RECYCLE_ANCHOR', cfg('calendar.RECYCLE_ANCHOR', ''));
define('COUNTDOWNS', calendar_countdowns_for_signage(signage_request_screen()));
if (!defined('TIMEZONE')) {
    define('TIMEZONE', cfg('calendar.TIMEZONE', 'America/Detroit'));
}
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('calendar.CACHE_TTL', 600));

date_default_timezone_set(calendar_display_timezone_name());
$SCREEN = signage_request_screen();
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

function calendar_feed_label(array $feed, int $i): string
{
    $label = trim((string)($feed['key'] ?? $feed['name'] ?? ''));
    return $label !== '' ? $label : 'Feed ' . ($i + 1);
}

function calendar_diag_set(string $label, string $cacheKey, string $message): void
{
    unset($GLOBALS['diag'][$cacheKey]);
    $GLOBALS['diag'][$label !== '' ? $label : $cacheKey] = $message;
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
    $url = calendar_normalize_feed_url((string)($feed['url'] ?? ''));
    if ($url === '') {
        return null;
    }
    $source = calendar_feed_source($feed);
    $auth = calendar_feed_auth($feed);
    $key = calendar_feed_cache_key($i, $feed, $winStart);
    $label = calendar_feed_label($feed, $i);
    $hasAuth = $auth !== null && trim((string)($auth[0] ?? '')) !== '';

    if ($source === 'webdav' && !preg_match('/\.ics(\?|$)/i', $url)) {
        // Public subscription URLs (iCloud /published/, etc.) are often mislabeled webdav.
        if (!$hasAuth) {
            $raw = cached_get($url, $key, null);
            if ($raw !== null && stripos($raw, 'BEGIN:VCALENDAR') !== false) {
                unset($GLOBALS['diag'][$key]);
                return $raw;
            }
            calendar_diag_set(
                $label,
                $key,
                'CalDAV requires user and password — set Source to ical for subscription URLs (e.g. iCloud public calendar)'
            );
            return is_file(CACHE_DIR . "/$key.dat") ? (string)file_get_contents(CACHE_DIR . "/$key.dat") : null;
        }
        $raw = caldav_fetch(caldav_normalize_url($url), $auth, $winStart, $winEnd, $key);
        if ($raw === null && isset($GLOBALS['diag'][$key])) {
            calendar_diag_set($label, $key, (string)$GLOBALS['diag'][$key]);
        }
        return $raw;
    }
    $raw = cached_get($url, $key, $auth);
    if ($raw === null && !isset($GLOBALS['diag'][$label])) {
        calendar_diag_set($label, $key, 'fetch failed');
    } elseif ($raw !== null) {
        unset($GLOBALS['diag'][$key]);
    }

    return $raw;
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ICS parsing helpers live in lib/calendar_lib.php (ics_time, ics_timezone, …).

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
    if (ics_local_iso_weekday($day) !== $rule['dow']) {
        return false;
    }
    $ord = $rule['ord'];
    if ($ord === null) {
        return true;
    }
    [$year, $month, $dom, $daysInMonth] = ics_local_ymd($day);
    $tz = calendar_display_timezone();
    if ($ord > 0) {
        $count = 0;
        for ($d = 1; $d <= $dom; $d++) {
            $probe = (new DateTime())->setTimezone($tz)->setDate($year, $month, $d)->setTime(12, 0, 0);
            if ((int)$probe->format('N') === $rule['dow']) {
                $count++;
            }
        }
        return $count === $ord;
    }
    $count = 0;
    for ($d = $daysInMonth; $d >= $dom; $d--) {
        $probe = (new DateTime())->setTimezone($tz)->setDate($year, $month, $d)->setTime(12, 0, 0);
        if ((int)$probe->format('N') === $rule['dow']) {
            $count++;
        }
    }
    return $count === abs($ord);
}

function ics_months_between(int $day, int $start): int
{
    [$y1, $m1] = ics_local_ymd($day);
    [$y2, $m2] = ics_local_ymd($start);

    return ($y1 * 12 + $m1) - ($y2 * 12 + $m2);
}

function ics_is_excluded(int $ts, array $ev): bool
{
    $dayKey = ics_local_date_key($ts);
    foreach ($ev['exdate_ts'] ?? [] as $ex) {
        if ($ex === $ts) {
            return true;
        }
        // Outlook EXDATE often differs by TZ/UTC from expanded instances — match local day.
        if (ics_local_date_key($ex) === $dayKey) {
            return true;
        }
    }
    return in_array(str_replace('-', '', $dayKey), $ev['exdates'] ?? [], true);
}

/** Each local-midnight timestamp for an all-day span (end is iCal-exclusive). */
function ics_all_day_instances(array $ev, int $winStart, int $winEnd): array
{
    $tz = calendar_display_timezone();
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
        $startDay = ics_local_midnight($cur['start']);
        $endDay = ics_local_midnight($cur['end']);
        if ($endDay > $startDay || ($cur['end'] - $cur['start']) >= 82800) {
            $cur['all_day'] = true;
            $cur['start'] = $startDay;
            $cur['end'] = $cur['end'] > $endDay ? ics_add_local_days($endDay, 1) : $endDay;
            if ($cur['end'] <= $cur['start']) {
                $cur['end'] = ics_add_local_days($cur['start'], 1);
            }
        }
    }
    // Outlook work calendar: OOF blocks (holidays/vacation) export as timed 8–5, not VALUE=DATE.
    if (!$cur['all_day'] && ($cur['busy'] ?? '') === 'OOF' && ($cur['end'] ?? null) !== null) {
        $dur = $cur['end'] - $cur['start'];
        if ($dur >= 4 * 3600) {
            $first = ics_local_midnight($cur['start']);
            $last = ics_local_midnight($cur['end']);
            $cur['all_day'] = true;
            $cur['start'] = $first;
            $cur['end'] = ics_add_local_days($last, 1);
        }
    }
    if ($cur['all_day'] && ($cur['end'] ?? null) === null) {
        $cur['end'] = ics_add_local_days(ics_local_midnight($cur['start']), 1);
    }
    return $cur;
}

/** Last local-midnight day that is still part of a finite RRULE series (COUNT or UNTIL). */
function ics_rrule_last_instance_day(array $ev): ?int
{
    $r = $ev['rrule'] ?? null;
    if (!is_array($r)) {
        return null;
    }
    if (isset($r['UNTIL'])) {
        $until = ics_time('', $r['UNTIL']);
        if ($until) {
            return ics_local_midnight($until[0]);
        }
    }
    if (!isset($r['COUNT'])) {
        return null;
    }

    $count = max(1, (int)$r['COUNT']);
    $interval = ics_rrule_interval($ev);
    $startDay = ics_local_midnight($ev['start']);
    $freq = $r['FREQ'] ?? '';

    switch ($freq) {
        case 'DAILY':
            return ics_add_local_days($startDay, ($count - 1) * $interval);
        case 'WEEKLY':
            $bydayRules = ics_parse_byday_rules($r['BYDAY'] ?? '');
            if ($bydayRules === []) {
                return ics_add_local_days($startDay, ($count - 1) * 7 * $interval);
            }
            $wkstIso = ics_wkst_to_iso($r['WKST'] ?? ($interval > 1 ? 'SU' : 'MO'));
            $found = 0;
            $cap = ics_add_local_days($startDay, max($count * 7 * $interval * 4, 366));
            $cur = (new DateTime('@' . $startDay))->setTimezone(calendar_display_timezone());
            $capDt = (new DateTime('@' . $cap))->setTimezone(calendar_display_timezone());
            while ($cur <= $capDt) {
                $day = $cur->getTimestamp();
                $weeks = ics_weeks_since_start($day, $startDay, $wkstIso);
                if ($weeks < 0 || $weeks % $interval !== 0) {
                    $cur->modify('+1 day');
                    continue;
                }
                $hit = false;
                foreach ($bydayRules as $rule) {
                    if ($rule['ord'] !== null) {
                        if (ics_day_matches_byday($day, $rule)) {
                            $hit = true;
                            break;
                        }
                    } elseif (ics_local_iso_weekday($day) === $rule['dow']) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $found++;
                    if ($found >= $count) {
                        return $day;
                    }
                }
                $cur->modify('+1 day');
            }
            return null;
        case 'MONTHLY':
            $bydayRules = ics_parse_byday_rules($r['BYDAY'] ?? '');
            $found = 0;
            $cap = ics_add_local_days($startDay, max($count * 32 * $interval, 366));
            $cur = (new DateTime('@' . $startDay))->setTimezone(calendar_display_timezone());
            $capDt = (new DateTime('@' . $cap))->setTimezone(calendar_display_timezone());
            while ($cur <= $capDt) {
                $day = $cur->getTimestamp();
                $months = ics_months_between($day, $startDay);
                if ($months < 0 || $months % $interval !== 0) {
                    $cur->modify('+1 day');
                    continue;
                }
                $hit = false;
                if ($bydayRules !== []) {
                    foreach ($bydayRules as $rule) {
                        if (ics_day_matches_byday($day, $rule)) {
                            $hit = true;
                            break;
                        }
                    }
                } else {
                    [, , $domStart] = ics_local_ymd($startDay);
                    $dom = (int)($r['BYMONTHDAY'] ?? $domStart);
                    [, , $domDay] = ics_local_ymd($day);
                    $hit = $domDay === $dom;
                }
                if ($hit) {
                    $found++;
                    if ($found >= $count) {
                        return $day;
                    }
                }
                $cur->modify('+1 day');
            }
            return null;
        case 'YEARLY':
            return (new DateTime('@' . $startDay))->setTimezone(calendar_display_timezone())
                ->modify('+' . ($count - 1) * $interval . ' years')
                ->setTime(0, 0, 0)
                ->getTimestamp();
        default:
            return null;
    }
}

/** 1-based RRULE occurrence index for $dayMidnight, or null if that day is not an instance. */
function ics_rrule_occurrence_index(array $ev, int $dayMidnight): ?int
{
    $r = $ev['rrule'] ?? null;
    if (!is_array($r)) {
        return null;
    }
    $startDay = ics_local_midnight($ev['start']);
    if ($dayMidnight < $startDay) {
        return null;
    }
    $interval = ics_rrule_interval($ev);
    $freq = $r['FREQ'] ?? '';
    $bydayRules = ics_parse_byday_rules($r['BYDAY'] ?? '');

    switch ($freq) {
        case 'DAILY':
            $daysSince = ics_calendar_days_between($startDay, $dayMidnight);
            if ($daysSince < 0 || $daysSince % $interval !== 0) {
                return null;
            }

            return (int)($daysSince / $interval) + 1;
        case 'WEEKLY':
            $wkstIso = ics_wkst_to_iso($r['WKST'] ?? ($interval > 1 ? 'SU' : 'MO'));
            $weeks = ics_weeks_since_start($dayMidnight, $startDay, $wkstIso);
            if ($weeks < 0 || $weeks % $interval !== 0) {
                return null;
            }
            if ($bydayRules === []) {
                if (ics_local_iso_weekday($dayMidnight) !== ics_local_iso_weekday($startDay)) {
                    return null;
                }

                return (int)($weeks / $interval) + 1;
            }
            $found = 0;
            $cur = (new DateTime('@' . $startDay))->setTimezone(calendar_display_timezone());
            $endDt = (new DateTime('@' . $dayMidnight))->setTimezone(calendar_display_timezone());
            while ($cur <= $endDt) {
                $day = $cur->getTimestamp();
                $w = ics_weeks_since_start($day, $startDay, $wkstIso);
                if ($w < 0 || $w % $interval !== 0) {
                    $cur->modify('+1 day');
                    continue;
                }
                $hit = false;
                foreach ($bydayRules as $rule) {
                    if ($rule['ord'] !== null) {
                        if (ics_day_matches_byday($day, $rule)) {
                            $hit = true;
                            break;
                        }
                    } elseif (ics_local_iso_weekday($day) === $rule['dow']) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $found++;
                    if ($day === $dayMidnight) {
                        return $found;
                    }
                }
                $cur->modify('+1 day');
            }
            return null;
        case 'MONTHLY':
            $months = ics_months_between($dayMidnight, $startDay);
            if ($months < 0 || $months % $interval !== 0) {
                return null;
            }
            $hit = false;
            if ($bydayRules !== []) {
                foreach ($bydayRules as $rule) {
                    if (ics_day_matches_byday($dayMidnight, $rule)) {
                        $hit = true;
                        break;
                    }
                }
            } else {
                [, , $domStart] = ics_local_ymd($startDay);
                $dom = (int)($r['BYMONTHDAY'] ?? $domStart);
                [, , $domDay] = ics_local_ymd($dayMidnight);
                $hit = $domDay === $dom;
            }
            if (!$hit) {
                return null;
            }

            return (int)($months / $interval) + 1;
        case 'YEARLY':
            [$y1] = ics_local_ymd($dayMidnight);
            [$y2] = ics_local_ymd($startDay);
            $years = $y1 - $y2;
            if ($years < 0 || $years % $interval !== 0) {
                return null;
            }
            [, $m1] = ics_local_ymd($dayMidnight);
            if (isset($r['BYMONTH']) && $m1 !== (int)$r['BYMONTH']) {
                return null;
            }
            if ($bydayRules !== []) {
                $hit = false;
                foreach ($bydayRules as $rule) {
                    if (ics_day_matches_byday($dayMidnight, $rule)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    return null;
                }
            } else {
                [, $mStart, $dStart] = ics_local_ymd($startDay);
                [, $mDay, $dDay] = ics_local_ymd($dayMidnight);
                if ($mDay !== $mStart || $dDay !== $dStart) {
                    return null;
                }
            }

            return (int)($years / $interval) + 1;
        default:
            return null;
    }
}

/** End timestamp for one expanded instance (end exclusive for timed events). */
function ics_instance_end_ts(array $ev, int $startTs): int
{
    if (!empty($ev['all_day'])) {
        return ics_add_local_days(ics_local_midnight($startTs), 1);
    }
    $masterStart = (int)($ev['start'] ?? $startTs);
    $masterEnd = (int)($ev['end'] ?? 0);
    if ($masterEnd > $masterStart) {
        return $startTs + ($masterEnd - $masterStart);
    }

    return $startTs + 3600;
}

/** @return array<string,mixed> */
function calendar_event_instance(array $ev, int $ts, bool $allDay): array
{
    return [
        'ts' => $ts,
        'end_ts' => ics_instance_end_ts($ev, $ts),
        'all_day' => $allDay,
        'summary' => $ev['summary'],
        'cal' => $ev['cal'],
        'feed_id' => (string)($ev['feed_id'] ?? ''),
        'color' => $ev['color'],
        'hex' => $ev['hex'],
    ];
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
        $out[] = calendar_event_instance($ev, $ts, $allDay);
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
    $interval = ics_rrule_interval($ev);
    $until = isset($r['UNTIL']) ? (ics_time('', $r['UNTIL'])[0] ?? PHP_INT_MAX) : PHP_INT_MAX;
    $bydayRules = ics_parse_byday_rules($r['BYDAY'] ?? '');
    $rruleCount = isset($r['COUNT']) ? max(1, (int)$r['COUNT']) : null;
    $lastInstanceDay = ics_rrule_last_instance_day($ev);
    $startDay = ics_local_midnight($start);
    $winDayStart = ics_local_midnight($winStart);
    $winDayEnd = ics_local_midnight($winEnd);

    $cur = (new DateTime('@' . $winDayStart))->setTimezone(calendar_display_timezone());
    $endDt = (new DateTime('@' . $winDayEnd))->setTimezone(calendar_display_timezone());
    while ($cur <= $endDt) {
        $day = $cur->getTimestamp();
        if ($lastInstanceDay !== null && $day > $lastInstanceDay) {
            $cur->modify('+1 day');
            continue;
        }
        $ts = $allDay ? $day : ics_wall_time_on_day($day, $start);
        if ($ts < $start || $ts > $until || $ts < $winStart || $ts > $winEnd) {
            $cur->modify('+1 day');
            continue;
        }
        $match = false;
        switch ($freq) {
            case 'DAILY':
                $daysSince = ics_calendar_days_between($startDay, $day);
                $match = $daysSince >= 0 && $daysSince % $interval === 0;
                break;
            case 'WEEKLY':
                $wkstIso = ics_wkst_to_iso($r['WKST'] ?? ($interval > 1 ? 'SU' : 'MO'));
                $weeks = ics_weeks_since_start($day, $startDay, $wkstIso);
                if ($weeks >= 0 && $weeks % $interval === 0) {
                    if ($bydayRules !== []) {
                        foreach ($bydayRules as $rule) {
                            if ($rule['ord'] !== null) {
                                if (ics_day_matches_byday($day, $rule)) {
                                    $match = true;
                                    break;
                                }
                            } elseif (ics_local_iso_weekday($day) === $rule['dow']) {
                                $match = true;
                                break;
                            }
                        }
                    } else {
                        $match = ics_local_iso_weekday($day) === ics_local_iso_weekday($startDay);
                    }
                }
                break;
            case 'MONTHLY':
                $months = ics_months_between($day, $start);
                if ($months % $interval === 0) {
                    if ($bydayRules !== []) {
                        foreach ($bydayRules as $rule) {
                            if (ics_day_matches_byday($day, $rule)) {
                                $match = true;
                                break;
                            }
                        }
                    } else {
                        [, , $domStart] = ics_local_ymd($startDay);
                        $dom = (int)($r['BYMONTHDAY'] ?? $domStart);
                        [, , $domDay] = ics_local_ymd($day);
                        $match = $domDay === $dom;
                    }
                }
                break;
            case 'YEARLY':
                [$yDay] = ics_local_ymd($day);
                [$yStart] = ics_local_ymd($startDay);
                $years = $yDay - $yStart;
                if ($years % $interval === 0) {
                    [, $mDay] = ics_local_ymd($day);
                    if (isset($r['BYMONTH']) && $mDay !== (int)$r['BYMONTH']) {
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
                        [, $mStart, $dStart] = ics_local_ymd($startDay);
                        [, $mCur, $dCur] = ics_local_ymd($day);
                        $match = $mCur === $mStart && $dCur === $dStart;
                    }
                }
                break;
        }
        if ($match && $rruleCount !== null) {
            $occ = ics_rrule_occurrence_index($ev, $day);
            $match = ($occ !== null && $occ <= $rruleCount);
        }
        if ($match) {
            $push($ts);
        }
        $cur->modify('+1 day');
    }
    return $out;
}

/**
 * Collect expanded calendar instances from all feeds for a time window.
 * Shared by calendar.php and glance.php.
 *
 * @return list<array<string,mixed>>
 */
function calendar_collect_events(int $winStart, int $winEnd, ?array $feeds = null): array
{
    if ($feeds === null) {
        if (defined('ICS_FEEDS')) {
            $feeds = ICS_FEEDS;
        } else {
            $rawFeeds = cfg('calendar.ICS_FEEDS', []);
            $feeds = is_array($rawFeeds) ? $rawFeeds : [];
            if (function_exists('admin_filter_list_for_display')) {
                require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
                $feeds = admin_filter_list_for_display($feeds);
            }
        }
    }
    $events = [];
    foreach ($feeds as $i => $feed) {
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
        $mastersByUid = [];
        foreach ($vevents as $ev) {
            if ($ev['recurrence_id'] !== null && ($ev['uid'] ?? '') !== '') {
                continue;
            }
            $masters[] = $ev;
            if (($ev['uid'] ?? '') !== '') {
                $mastersByUid[$ev['uid']] = $ev;
            }
        }
        foreach ($vevents as $ev) {
            if ($ev['recurrence_id'] === null || ($ev['uid'] ?? '') === '') {
                continue;
            }
            $master = $mastersByUid[$ev['uid']] ?? null;
            if ($master !== null && !empty($master['rrule']) && ($master['rrule']['FREQ'] ?? '') === 'WEEKLY') {
                $interval = ics_rrule_interval($master);
                if ($interval > 1) {
                    $ridTs = (int)$ev['recurrence_id'];
                    $dayStart = ics_local_midnight($ridTs);
                    $dayEnd = $dayStart + 86399;
                    if (expand_event($master, $dayStart, $dayEnd, []) === []) {
                        continue;
                    }
                }
            }
            $overrides[$ev['uid']][$ev['recurrence_id']] = true;
            if ($ev['all_day']) {
                foreach (ics_all_day_instances($ev, $winStart, $winEnd) as $ts) {
                    $events[] = calendar_event_instance($ev, $ts, true);
                }
            } elseif ($ev['start'] >= $winStart && $ev['start'] <= $winEnd) {
                $events[] = calendar_event_instance($ev, (int)$ev['start'], false);
            }
        }
        foreach ($masters as $ev) {
            foreach (expand_event($ev, $winStart, $winEnd, $overrides) as $inst) {
                $events[] = $inst;
            }
        }
    }
    usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);

    return $events;
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
                'feed_id' => (string)($feedMeta['id'] ?? ''),
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
$winStart = ics_local_midnight(time());
$winEnd   = ics_add_local_days($winStart, 7) - 1;
$events   = calendar_collect_events($winStart, $winEnd);

// Bucket by day
$days = [];
for ($d = 0; $d < 7; $d++) {
    $key = ics_local_date_key(ics_add_local_days($winStart, $d));
    $days[$key] = [];
}
foreach ($events as $e) {
    $key = ics_local_date_key($e['ts']);
    if (isset($days[$key])) {
        $days[$key][] = $e;
    }
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
    if ($date === '') {
        continue;
    }
    $ts = strtotime($date);
    if ($ts === false) {
        continue;
    }
    $d = (int)ceil(($ts - time()) / 86400);
    if ($d >= 0) {
        $counts[] = [$label, $d];
    }
}
usort($counts, fn($a, $b) => $a[1] <=> $b[1]);
$showCountdownStrip = $counts !== [] || $showTrash;

$calLegend = calendar_legend(is_array(ICS_FEEDS) ? ICS_FEEDS : []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Calendar</title>
<?= signage_theme_fonts_head_html() ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:grid; gap:24px;
           grid-template-columns: 600px 1fr;
           grid-template-rows: <?= $showCountdownStrip ? 'minmax(0,1fr) 150px auto' : 'minmax(0,1fr) auto' ?>;
           grid-template-areas: <?= $showCountdownStrip
               ? '"today week" "strip strip" "meta meta"'
               : '"today week" "meta meta"' ?>; }

  .today { grid-area:today; background:var(--harbor); border:1px solid var(--hairline);
           border-radius:14px; padding:38px 42px; display:flex; flex-direction:column; min-height:0; }
  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= signage_font_scaled_bignum_px(110) ?>px; line-height:1; }
  #clock span { font-size:44px; color:var(--mist); }
  .dateline { font-size:30px; color:var(--mist); margin-top:6px; }
  .today .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist);
              margin:30px 0 8px; border-top:1px solid var(--hairline); padding-top:24px; flex-shrink:0; }
  .today-events { flex:1; min-height:0; overflow:hidden; }
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

  .strip { grid-area:strip; display:flex; gap:24px; min-height:0; overflow:hidden; align-items:stretch; }
  .chip { flex:1; min-width:0; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:20px 28px; display:flex; align-items:center; justify-content:space-between; }
  .chip .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .chip .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:50px; }
  .chip .v small { font-size:26px; color:var(--mist); font-weight:600; }
  .chip.trash .v { color:var(--beacon); }
  .setup { font-size:24px; color:var(--mist); line-height:1.6; }
  .setup code { background:var(--inset-surface,var(--panel-dim)); padding:2px 8px; border-radius:6px; color:var(--snow); }
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
    <div class="today-events">
    <?php $todayKey = ics_local_date_key(time());
    if (ICS_FEEDS === []) : ?>
      <div class="setup">Add calendar feeds in admin — iCal subscription URLs or WebDAV/CalDAV
        (Nextcloud, Radicale, …) with user/password when required.</div>
    <?php elseif ($days[$todayKey]): foreach (array_slice($days[$todayKey], 0, 7) as $e): ?>
      <div class="tev">
        <span class="who" style="color:<?= h($e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''))) ?>"><?= h($e['cal']) ?></span>
        <span class="t" style="color:<?= h($e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''))) ?>"><?= $e['all_day'] ? 'All day' : h(ics_format_local_time($e['ts'], null, $SCREEN)) ?></span>
        <span class="s"><?= h($e['summary']) ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="free">Nothing on the calendar — enjoy it.</div>
    <?php endif; ?>
    </div>
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
            <span class="et"><?= $e['all_day'] ? 'All day' : h(ics_format_local_time($e['ts'], null, $SCREEN)) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (count($list) > 4): ?><div class="more">+<?= count($list) - 4 ?> more</div><?php endif; ?>
        <?php if (!$list): ?><div class="nothing">—</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <?php if ($showCountdownStrip): ?>
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
  </section>
  <?php endif; ?>
  <div class="stamp">ICS feeds refresh every 10 min<?php if ($GLOBALS['diag']): ?> · <?= h(implode('; ', array_map(
      static fn($label, $msg) => $label . ': ' . $msg,
      array_keys($GLOBALS['diag']),
      array_values($GLOBALS['diag'])
  ))) ?><?php endif; ?></div>
</div>
<script>
  function tick(){
    const n = new Date();
    document.getElementById('dateline').textContent =
      n.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric' });
  }
  tick(); setInterval(tick, 1000);
  <?php if ($showClock): ?>
  <?= signage_clock_tick_script('clock', calendar_display_timezone_name(), $SCREEN) ?>
  <?php endif; ?>
  setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
