#!/usr/bin/env php
<?php
/**
 * CLI: why slide deploy might skip displays (run on the signage server).
 * Usage: php scripts/diagnose-slides-deploy.php [screen_key ...]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/slides_lib.php';
require_once $root . '/rotation_lib.php';
require_once $root . '/users_lib.php';

$deck = cfg('slides.SLIDES', []);
if (!is_array($deck)) {
    $deck = [];
}
$repaired = slides_repair_deck($deck);
$onDisk = slides_rotation_pages($repaired, null);
$screens = rotation_screens();
$args = array_slice($argv, 1);
$check = $args !== [] ? array_map('rotation_normalize_screen_key', $args) : array_keys($screens);

echo "Slides in deck (config): " . count($deck) . "\n";
echo "Enabled on disk (after repair): " . count($onDisk) . "\n";
echo "Slides dir: " . slides_dir() . "\n";
echo "Targeting broken (no slide hits any display): "
    . (slides_deck_targets_no_configured_screens($repaired) ? 'yes' : 'no') . "\n\n";

foreach ($check as $sk) {
    if ($sk === '') {
        continue;
    }
    $name = rotation_screen_display_name($sk, $screens);
    $n = count(slides_rotation_pages($repaired, $sk));
    $deploy = count(slides_deploy_expected_pages($repaired, $sk, null));
    echo sprintf("%s (%s): %d targeted, %d deploy expected (super)\n", $name, $sk, $n, $deploy);
}

$hidden = 0;
$stale = 0;
$all = 0;
$known = array_flip(array_keys($screens));
foreach ($deck as $slide) {
    if (!is_array($slide)) {
        continue;
    }
    if (!array_key_exists('screens', $slide)) {
        $all++;
        continue;
    }
    $t = slide_target_screens($slide);
    if ($t === []) {
        $hidden++;
        continue;
    }
    $ok = false;
    foreach ($t as $k) {
        if (isset($known[$k])) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $stale++;
    }
}
echo "\nDeck targeting: {$all} all-displays, {$hidden} hidden (screens:[]), {$stale} stale/unknown keys\n";
