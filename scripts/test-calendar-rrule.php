<?php
/**
 * Quick RRULE expansion checks (RFC 5545 + Outlook-style patterns).
 * Run: php scripts/test-calendar-rrule.php
 */
define('SIGNAGE_CALENDAR_LIB_ONLY', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/calendar_lib.php';
require_once __DIR__ . '/../boards/media/calendar.php';

date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'America/Detroit');

function test_expand(array $ev, int $winStart, int $winEnd): array
{
    $out = [];
    foreach (expand_event($ev, $winStart, $winEnd) as $inst) {
        $out[] = date('Y-m-d D H:i', $inst['ts']);
    }
    return $out;
}

$fail = 0;

// RFC 5545 — every other week, WKST=SU
$start = strtotime('1997-09-02 09:00:00');
$ev = [
    'start' => $start,
    'end' => $start + 3600,
    'all_day' => false,
    'summary' => 'RFC biweekly WKST=SU',
    'rrule' => parse_rrule('FREQ=WEEKLY;INTERVAL=2;WKST=SU'),
    'exdates' => [],
    'exdate_ts' => [],
    'uid' => 'rfc1',
    'cal' => '', 'color' => '', 'hex' => '',
];
$win = [strtotime('1997-09-01'), strtotime('1997-12-31')];
$got = test_expand($ev, $win[0], $win[1]);
$expect = ['1997-09-02', '1997-09-16', '1997-09-30', '1997-10-14'];
foreach ($expect as $ymd) {
    $ok = false;
    foreach ($got as $line) {
        if (str_starts_with($line, $ymd)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        echo "FAIL RFC WKST=SU: missing $ymd in " . implode(', ', $got) . PHP_EOL;
        $fail++;
    }
}

// RFC — INTERVAL=2 BYDAY=TU,SU WKST=MO (first 4)
$start = strtotime('1997-08-05 09:00:00');
$ev['start'] = $start;
$ev['rrule'] = parse_rrule('FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO');
$win = [strtotime('1997-08-01'), strtotime('1997-08-31')];
$got = test_expand($ev, $win[0], $win[1]);
$expectDays = ['1997-08-05', '1997-08-10', '1997-08-19', '1997-08-24'];
foreach ($expectDays as $ymd) {
    $ok = false;
    foreach ($got as $line) {
        if (str_starts_with($line, $ymd)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        echo "FAIL RFC BYDAY TU,SU WKST=MO: missing $ymd in " . implode(', ', $got) . PHP_EOL;
        $fail++;
    }
}
// Off-week Tuesday should not appear
foreach ($got as $line) {
    if (str_starts_with($line, '1997-08-12')) {
        echo "FAIL RFC BYDAY: should not include 1997-08-12 in " . implode(', ', $got) . PHP_EOL;
        $fail++;
    }
}

// Outlook-style: weekly RRULE + EXDATE on skipped weeks (alternate pattern when INTERVAL omitted)
$start = strtotime('2025-01-08 10:00:00'); // Wed
$ev['start'] = $start;
$ev['summary'] = 'Wednesday standup';
$ev['rrule'] = parse_rrule('FREQ=WEEKLY;BYDAY=WE');
$ev['exdate_ts'] = [
    strtotime('2025-01-22 10:00:00'),
    strtotime('2025-02-05 10:00:00'),
];
$win = [strtotime('2025-01-01'), strtotime('2025-02-15')];
$got = test_expand($ev, $win[0], $win[1]);
$expectWe = ['2025-01-08', '2025-01-15', '2025-01-29', '2025-02-12'];
foreach ($expectWe as $ymd) {
    if (!preg_grep("/^$ymd/", $got)) {
        echo "FAIL EXDATE biweekly: missing $ymd in " . implode(', ', $got) . PHP_EOL;
        $fail++;
    }
}
foreach (['2025-01-22', '2025-02-05'] as $ymd) {
    if (preg_grep("/^$ymd/", $got)) {
        echo "FAIL EXDATE: $ymd should be excluded" . PHP_EOL;
        $fail++;
    }
}

// Simple INTERVAL=2 BYDAY=WE WKST=SU
$start = strtotime('2025-01-15 10:00:00');
$ev['start'] = $start;
$ev['summary'] = 'Biweekly WE';
$ev['rrule'] = parse_rrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;WKST=SU');
$ev['exdate_ts'] = [];
$win = [strtotime('2025-01-01'), strtotime('2025-03-01')];
$got = test_expand($ev, $win[0], $win[1]);
$expectWe = ['2025-01-15', '2025-01-29', '2025-02-12', '2025-02-26'];
foreach ($expectWe as $ymd) {
    if (!preg_grep("/^$ymd/", $got)) {
        echo "FAIL biweekly WE: missing $ymd in " . implode(', ', $got) . PHP_EOL;
        $fail++;
    }
}
if (preg_grep('/2025-01-22/', $got)) {
    echo 'FAIL biweekly WE: 2025-01-22 should not appear' . PHP_EOL;
    $fail++;
}

// Outlook-style: weekly RRULE without INTERVAL, cadence only in summary
$start = strtotime('2025-01-15 11:00:00'); // Wed — on week
$ev['start'] = $start;
$ev['rrule'] = parse_rrule('FREQ=WEEKLY;BYDAY=WE');
$ev['exdate_ts'] = [];
$ev['summary'] = 'TeamDynamix Review Team Meeting (Every 2 Weeks)';
$got = test_expand($ev, strtotime('2025-01-15'), strtotime('2025-01-15') + 86399);
if ($got === []) {
    echo 'FAIL Outlook summary biweekly: missing 2025-01-15' . PHP_EOL;
    $fail++;
}
$gotOff = test_expand($ev, strtotime('2025-01-22'), strtotime('2025-01-22') + 86399);
if ($gotOff !== []) {
    echo 'FAIL Outlook summary biweekly: 2025-01-22 should not appear in ' . implode(', ', $gotOff) . PHP_EOL;
    $fail++;
}

if ($fail === 0) {
    echo "OK — all RRULE checks passed\n";
    exit(0);
}
echo "$fail check(s) failed\n";
exit(1);
