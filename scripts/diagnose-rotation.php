#!/usr/bin/env php
<?php
/**
 * CLI: explain why weighted or other playlist rows may not play on a display.
 * Usage: php scripts/diagnose-rotation.php [screen_key]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/rotation_lib.php';
require_once $root . '/lib/slides_lib.php';

$screen = rotation_normalize_screen_key($argv[1] ?? 'main');
$settings = rotation_screen_settings($screen);
$hour = rotation_current_hour();
$tz = rotation_timezone();
$effective = rotation_screen_effective_pages($screen);
$active = rotation_screen_active_pages($screen);
$activeUrls = [];
foreach ($active as $p) {
    if (!is_array($p)) {
        continue;
    }
    $activeUrls[trim((string)($p['url'] ?? ''))] = true;
}

echo "Screen: {$screen}\n";
echo 'Timezone: ' . $tz . " (hour {$hour})\n";
echo 'Weighted mode: ' . (!empty($settings['weighted']) ? 'ON' : 'OFF — weights are ignored') . "\n";
echo 'Shuffle: ' . (!empty($settings['shuffle']) ? 'on' : 'off') . "\n";
echo 'On wall now: ' . count($active) . ' active / ' . count($effective) . " in saved playlist\n\n";

if ($effective === []) {
    echo "No playlist saved for this screen (mirrors main if unset).\n";
    exit(0);
}

$inWindowCount = 0;
$weightedRows = [];
foreach ($effective as $i => $page) {
    if (!is_array($page)) {
        continue;
    }
    $url = trim((string)($page['url'] ?? ''));
    if ($url === '') {
        continue;
    }
    $weight = (int)($page['weight'] ?? 1);
    $skipped = !empty($page['off']);
    $inWindow = rotation_page_in_window($page, $hour);
    $onWall = isset($activeUrls[$url]);
    if ($inWindow && $onWall && !$skipped) {
        $inWindowCount++;
    }
    if ($weight > 1) {
        $reasons = [];
        if ($skipped) {
            $reasons[] = 'Skip checked';
        }
        if (!$onWall) {
            require_once $root . '/lib/lake_lib.php';
            require_once $root . '/lib/sports_lib.php';
            require_once $root . '/lib/webcam_lib.php';
            if (rotation_page_url_is_lake($url) && lake_buoy_skip_rotation()) {
                $reasons[] = 'lake buoy offline 24h+ (seasonal auto-skip)';
            } elseif (rotation_page_url_is_sports($url) && sports_skip_rotation($screen)) {
                $reasons[] = 'all sports teams off-season (auto-skip)';
            } elseif (rotation_page_url_is_webcam($url) && webcam_skip_rotation()) {
                $reasons[] = 'webcam embed unreachable 24h+ (auto-skip)';
            } else {
                $reasons[] = 'not on wall (slide off/schedule, missing file, or filtered)';
            }
        }
        if (!$inWindow) {
            $from = $page['from'] ?? '—';
            $to = $page['to'] ?? '—';
            $reasons[] = "outside hour window (from {$from}, to {$to})";
        }
        if (!empty($settings['weighted'])) {
            $reasons[] = 'eligible for weighted pick';
        } else {
            $reasons[] = 'weight ignored until Weighted is on';
        }
        $weightedRows[] = [
            'label' => rotation_page_label($url),
            'url' => $url,
            'weight' => $weight,
            'ok' => $onWall && $inWindow && !$skipped && !empty($settings['weighted']),
            'note' => $reasons === [] ? 'ok' : implode('; ', $reasons),
        ];
    }
}

echo "Eligible for rotation right now (on wall + in hour window + not skipped): {$inWindowCount}\n\n";

if ($weightedRows === []) {
    echo "No saved rows with weight > 1 on this screen.\n";
    echo "Set Weight (2–20) on playlist cards and Save. Enable Weighted on the display.\n";
    exit(0);
}

echo "Rows with weight > 1:\n";
foreach ($weightedRows as $row) {
    $flag = $row['ok'] ? 'OK' : 'BLOCKED';
    echo sprintf(
        "  [%s] weight %d — %s\n      %s\n      %s\n",
        $flag,
        $row['weight'],
        $row['label'],
        $row['url'],
        $row['note']
    );
}

if (!empty($settings['weighted']) && $inWindowCount > 0) {
    $totalW = 0;
    foreach ($active as $p) {
        if (!is_array($p) || !rotation_page_in_window($p, $hour)) {
            continue;
        }
        $w = (int)($p['weight'] ?? 1);
        $totalW += max(1, min(20, $w > 0 ? $w : 1));
    }
    echo "\nTotal pick weight in pool: {$totalW}\n";
    foreach ($weightedRows as $row) {
        if (!$row['ok']) {
            continue;
        }
        $pct = round($row['weight'] / $totalW * 100, 1);
        echo "  {$row['label']}: ~{$pct}% of picks\n";
    }
}
