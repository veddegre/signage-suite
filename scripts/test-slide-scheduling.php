#!/usr/bin/env php
<?php
/**
 * Sanity checks for slide time scheduling (windows, time_weekdays, legacy hours).
 * Usage: php scripts/test-slide-scheduling.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/slides_lib.php';
require_once $root . '/lib/rotation_lib.php';

$tz = new DateTimeZone(slides_timezone());

// Legacy hour_from / hour_to (inclusive end hour)
$legacy = ['hour_from' => 7, 'hour_to' => 9];
$legacyExpect = [
    6 => false,
    7 => true,
    9 => true,
    10 => false,
];
foreach ($legacyExpect as $hour => $expect) {
    $now = (new DateTime('now', $tz))->setTime((int)$hour, 0);
    $got = slide_time_window_active($legacy, $now);
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL legacy hour window at h={$hour}\n");
        exit(1);
    }
}
echo "Legacy hour window checks: OK\n";

// Multi-window via rotation semantics (end-exclusive)
$windowsSlide = [
    'windows' => [
        ['from' => 7, 'to' => 9],
        ['from' => 16, 'to' => 18],
    ],
];
$winExpect = [
    6 => false,
    8 => true,
    12 => false,
    17 => true,
    20 => false,
];
foreach ($winExpect as $hour => $expect) {
    $now = (new DateTime('now', $tz))->setTime((int)$hour, 0);
    $got = slide_time_window_active($windowsSlide, $now);
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL slide windows at h={$hour}\n");
        exit(1);
    }
}
echo "Slide multi-window checks: OK\n";

// Minute precision
$minuteSlide = ['windows' => [['from' => '7:30', 'to' => '9:00']]];
foreach ([449 => false, 450 => true, 539 => true, 540 => false] as $min => $expect) {
    $now = (new DateTime('now', $tz))->setTime(intdiv($min, 60), $min % 60);
    $got = slide_time_window_active($minuteSlide, $now);
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL slide minute window at {$min}m\n");
        exit(1);
    }
}
echo "Slide minute window checks: OK\n";

// time_weekdays
$weekdaySlide = [
    'windows' => [['from' => 7, 'to' => 9]],
    'time_weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
];
$dow = (int)(new DateTime('now', $tz))->format('N');
$isWeekday = $dow >= 1 && $dow <= 5;
$now8 = (new DateTime('now', $tz))->setTime(8, 0);
$got = slide_time_window_active($weekdaySlide, $now8);
if ($got !== $isWeekday) {
    fwrite(STDERR, "FAIL slide time_weekdays\n");
    exit(1);
}
echo "Slide time_weekdays checks: OK\n";

// slide_schedule_summary includes window label
$summary = slide_schedule_summary([
    'schedule' => 'always',
    'windows' => [['from' => '7:30', 'to' => '9']],
    'time_weekdays' => ['Monday', 'Friday'],
]);
if (!str_contains($summary, '7:30') || !str_contains($summary, 'Mon') || !str_contains($summary, 'Fri')) {
    fwrite(STDERR, "FAIL slide_schedule_summary: {$summary}\n");
    exit(1);
}
echo "Slide schedule summary checks: OK\n";

// slide_time_schedule_page maps to rotation_page_in_window
$page = slide_time_schedule_page([
    'windows' => [['from' => 8, 'to' => 10]],
    'time_weekdays' => ['Saturday'],
]);
$sat = (new DateTime('next Saturday', $tz))->setTime(9, 0);
$sun = (new DateTime('next Sunday', $tz))->setTime(9, 0);
if (!rotation_page_in_window($page, $sat) || rotation_page_in_window($page, $sun)) {
    fwrite(STDERR, "FAIL slide_time_schedule_page mapping\n");
    exit(1);
}
echo "Slide schedule page mapping checks: OK\n";

echo "All slide scheduling checks passed.\n";
