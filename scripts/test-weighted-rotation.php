#!/usr/bin/env php
<?php
/**
 * Sanity checks for board.php rotation logic (hour windows + weighted pick).
 * Usage: php scripts/test-weighted-rotation.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/rotation_lib.php';

/** @param array<string,mixed> $page */
function test_page_weight(array $page): int
{
    $w = (int)($page['weight'] ?? 0);

    return ($w > 0) ? min(20, $w) : 1;
}

/**
 * Mirrors board.php pickWeightedPage() for Monte Carlo checks.
 * @param list<array<string,mixed>> $pages
 */
function test_pick_weighted_page(array $pages, int $excludeIdx): int
{
    $eligible = [];
    foreach ($pages as $i => $page) {
        if (!is_array($page) || !rotation_page_in_window($page)) {
            continue;
        }
        $eligible[] = $i;
    }
    if ($eligible === []) {
        return $excludeIdx >= 0 ? $excludeIdx : 0;
    }

    $total = 0;
    $pool = [];
    foreach ($eligible as $i) {
        $w = test_page_weight($pages[$i]);
        $pool[] = ['i' => $i, 'w' => $w];
        $total += $w;
    }

    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($pool as $item) {
        $r -= $item['w'];
        if ($r <= 0) {
            return $item['i'];
        }
    }

    return $pool[count($pool) - 1]['i'];
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

// ── Weighted distribution (weights 10:1:1) ───────────────────────────────────
$pages = [
    ['url' => 'heavy', 'weight' => 10],
    ['url' => 'light-a'],
    ['url' => 'light-b'],
];
$trials = 50000;
$counts = [0, 0, 0];
$idx = -1;
for ($t = 0; $t < $trials; $t++) {
    $idx = test_pick_weighted_page($pages, $idx);
    $counts[$idx]++;
}

$heavyPct = round($counts[0] / $trials * 100, 1);
$lightPct = round(($counts[1] + $counts[2]) / $trials * 100, 1);
echo "Weighted rotation simulation ({$trials} picks, weights 10:1:1):\n";
echo "  heavy:   {$counts[0]} ({$heavyPct}%, expected ~83%)\n";
echo "  light-a: {$counts[1]}\n";
echo "  light-b: {$counts[2]}\n";
echo "  light total: {$lightPct}%\n";

if ($counts[0] / $trials < 0.75) {
    fwrite(STDERR, "FAIL: heavy page under-represented\n");
    exit(1);
}
echo "Weighted checks: OK\n";

// ── Deploy sync must preserve weight/from/to/off ──────────────────────────────
$prev = ['url' => 'slides.php?slide=a.png', 'dwell' => 30, 'weight' => 20, 'from' => 8, 'to' => 18, 'off' => true];
$merged = rotation_merge_page_meta(['url' => 'slides.php?slide=a.png', 'dwell' => 45], $prev);
if (($merged['weight'] ?? 0) !== 20 || ($merged['from'] ?? null) !== 8 || ($merged['to'] ?? null) !== 18 || empty($merged['off'])) {
    fwrite(STDERR, "FAIL: rotation_merge_page_meta dropped playlist metadata\n");
    exit(1);
}
echo "Sync metadata preservation: OK\n";
