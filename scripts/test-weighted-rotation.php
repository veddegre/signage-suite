#!/usr/bin/env php
<?php
/**
 * Sanity checks for board.php rotation logic (hour windows, weighted deck, shuffle).
 * Usage: php scripts/test-weighted-rotation.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/rotation_lib.php';

/** @param array<string,mixed> $page */
function test_page_weight(array $page): int
{
    $w = (int)($page['weight'] ?? 0);

    return ($w > 0) ? min(20, $w) : 1;
}

// ── Hour windows (rotation_page_in_window) ────────────────────────────────────
$windowPages = [
    ['url' => 'day', 'from' => 7, 'to' => 22],
    ['url' => 'night', 'from' => 22, 'to' => 7],
    ['url' => 'always'],
];
$windowExpect = [
    6 => ['night', 'always'],
    7 => ['day', 'always'],
    12 => ['day', 'always'],
    22 => ['night', 'always'],
    23 => ['night', 'always'],
];

foreach ($windowExpect as $hour => $expect) {
    $got = [];
    foreach ($windowPages as $page) {
        if (rotation_page_in_window($page, (int)$hour)) {
            $got[] = (string)$page['url'];
        }
    }
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL hour window at h={$hour}: got " . implode(',', $got)
            . ' expected ' . implode(',', $expect) . PHP_EOL);
        exit(1);
    }
}
echo "Hour window checks: OK\n";

$commutePages = [
    ['url' => 'traffic', 'windows' => [['from' => 7, 'to' => 9], ['from' => 16, 'to' => 18]]],
    ['url' => 'always'],
];
$commuteExpect = [
    6 => ['always'],
    8 => ['traffic', 'always'],
    12 => ['always'],
    17 => ['traffic', 'always'],
    20 => ['always'],
];
foreach ($commuteExpect as $hour => $expect) {
    $got = [];
    foreach ($commutePages as $page) {
        if (rotation_page_in_window($page, (int)$hour)) {
            $got[] = (string)$page['url'];
        }
    }
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL multi-window at h={$hour}: got " . implode(',', $got)
            . ' expected ' . implode(',', $expect) . PHP_EOL);
        exit(1);
    }
}
echo "Multi-window checks: OK\n";

$minutePage = ['url' => 'traffic', 'from' => '7:30', 'to' => '9:00'];
$minuteExpect = [
    449 => false, // 7:29
    450 => true,  // 7:30
    480 => true,  // 8:00
    539 => true,  // 8:59
    540 => false, // 9:00 end exclusive
];
foreach ($minuteExpect as $min => $expect) {
    $now = rotation_now()->setTime(intdiv($min, 60), $min % 60);
    $got = rotation_page_in_window($minutePage, $now);
    if ($got !== $expect) {
        fwrite(STDERR, "FAIL minute window at {$min}m: got " . ($got ? 'in' : 'out') . PHP_EOL);
        exit(1);
    }
}
echo "Minute window checks: OK\n";

$weekdayPage = [
    'url' => 'traffic',
    'from' => 7,
    'to' => 9,
    'weekdays' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
];
$dow = (int)rotation_now()->format('N');
$isWeekday = $dow >= 1 && $dow <= 5;
$inCommuteHour = rotation_page_in_window($weekdayPage, rotation_now()->setTime(8, 0));
if ($inCommuteHour !== $isWeekday) {
    fwrite(STDERR, "FAIL weekday filter: expected " . ($isWeekday ? 'in' : 'out') . PHP_EOL);
    exit(1);
}
echo "Weekday window checks: OK\n";

/**
 * Mirrors board.php weighted deck (weight copies per cycle, shuffled).
 * @param list<array<string,mixed>> $pages
 * @param list<int> $eligible
 * @return list<int>
 */
function test_build_weighted_deck(array $pages, array $eligible): array
{
    $deck = [];
    foreach ($eligible as $i) {
        $w = test_page_weight($pages[$i]);
        for ($c = 0; $c < $w; $c++) {
            $deck[] = $i;
        }
    }
    $n = count($deck);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
    }

    return $deck;
}

/**
 * @param list<array<string,mixed>> $pages
 */
function test_weighted_cycle_unique_before_end(array $pages, array $eligible): bool
{
    if ($eligible === []) {
        return true;
    }
    $deck = test_build_weighted_deck($pages, $eligible);
    $seen = [];
    foreach ($deck as $pick) {
        $seen[$pick] = true;
    }

    return count($seen) === count($eligible);
}

// ── Weighted deck (each page once per cycle minimum, weight sets frequency) ───
$pages = [
    ['url' => 'heavy', 'weight' => 10],
    ['url' => 'light-a'],
    ['url' => 'light-b'],
];
$eligible = [0, 1, 2];
if (!test_weighted_cycle_unique_before_end($pages, $eligible)) {
    fwrite(STDERR, "FAIL: weighted deck missing a page in cycle\n");
    exit(1);
}
$deck = test_build_weighted_deck($pages, $eligible);
if (count($deck) !== 12) {
    fwrite(STDERR, 'FAIL: weighted deck size expected 12, got ' . count($deck) . PHP_EOL);
    exit(1);
}
$counts = [0, 0, 0];
foreach ($deck as $pick) {
    $counts[$pick]++;
}
if ($counts !== [10, 1, 1]) {
    fwrite(STDERR, 'FAIL: weighted deck counts ' . json_encode($counts) . PHP_EOL);
    exit(1);
}
echo "Weighted deck checks: OK (10+1+1 slots, all boards in every cycle)\n";

// ── Shuffle deck (in-window pages only, no repeat before cycle completes) ─────
function test_shuffle_next(array &$deck, int &$pos, string &$fp, array $eligible, int $lastShown): int
{
    $newFp = implode(',', $eligible);
    if ($fp !== $newFp || $pos >= count($deck)) {
        $deck = $eligible;
        $n = count($deck);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
        }
        if ($n > 1 && $deck[0] === $lastShown) {
            [$deck[0], $deck[$n - 1]] = [$deck[$n - 1], $deck[0]];
        }
        $pos = 0;
        $fp = $newFp;
    }
    return $deck[$pos++];
}

$pages = 10;
$inWindow = static fn(int $i): bool => $i % 2 === 0;
$failShuffle = 0;
for ($trial = 0; $trial < 3000; $trial++) {
    $eligible = array_values(array_filter(range(0, $pages - 1), $inWindow));
    $deck = [];
    $pos = 0;
    $fp = '';
    $last = -1;
    $seen = [];
    foreach ($eligible as $_) {
        $last = test_shuffle_next($deck, $pos, $fp, $eligible, $last);
        if (isset($seen[$last])) {
            $failShuffle++;
            break 2;
        }
        $seen[$last] = true;
    }
}
if ($failShuffle > 0) {
    fwrite(STDERR, "FAIL: shuffle deck repeated before full cycle\n");
    exit(1);
}
echo "Shuffle deck checks: OK\n";

// ── Deploy sync must preserve weight/from/to/off ──────────────────────────────
$prev = ['url' => 'slides.php?slide=a.png', 'dwell' => 30, 'weight' => 20, 'from' => 8, 'to' => 18, 'off' => true];
$merged = rotation_merge_page_meta(['url' => 'slides.php?slide=a.png', 'dwell' => 45], $prev);
if (($merged['weight'] ?? 0) !== 20 || ($merged['from'] ?? null) !== 8 || ($merged['to'] ?? null) !== 18 || empty($merged['off'])) {
    fwrite(STDERR, "FAIL: rotation_merge_page_meta dropped single-window metadata\n");
    exit(1);
}
$prevMulti = [
    'url' => 'traffic.php',
    'dwell' => 90,
    'windows' => [['from' => 7, 'to' => 9], ['from' => 16, 'to' => 18]],
];
$mergedMulti = rotation_merge_page_meta(['url' => 'traffic.php', 'dwell' => 60], $prevMulti);
$mergedWindows = $mergedMulti['windows'] ?? [];
if (count($mergedWindows) !== 2
    || (int)($mergedWindows[0]['from'] ?? 0) !== 7
    || (int)($mergedWindows[1]['to'] ?? 0) !== 18) {
    fwrite(STDERR, "FAIL: rotation_merge_page_meta dropped multi-window metadata\n");
    exit(1);
}
echo "Sync metadata preservation: OK\n";
