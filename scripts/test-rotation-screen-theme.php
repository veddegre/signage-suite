<?php
/**
 * Regression: saving Rotation must not wipe per-display theme (and tied font stacks).
 *
 * Usage: php scripts/test-rotation-screen-theme.php
 */

require_once dirname(__DIR__) . '/lib/rotation_lib.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;

// Display settings table save must preserve theme when the row has no theme field.
$existing = [
    'name' => 'Lobby TV',
    'theme' => 'gvsu_lakers',
    'hero_strip' => true,
    'location_place' => 'Allendale',
];
$screensRow = [
    '_key' => 'lobby',
    'name' => 'Lobby TV',
    'show_ticker' => '1',
    'show_clock' => '1',
    'fade_ms' => '',
];
$merged = rotation_apply_screens_table_post_row($existing, $screensRow, 'lobby');
if (($merged['theme'] ?? '') !== 'gvsu_lakers') {
    fwrite(STDERR, "FAIL: SCREENS table merge should preserve theme, got " . ($merged['theme'] ?? '(missing)') . "\n");
    $fail++;
}
if (empty($merged['hero_strip']) || ($merged['location_place'] ?? '') !== 'Allendale') {
    fwrite(STDERR, "FAIL: SCREENS table merge should preserve kiosk extras\n");
    $fail++;
}

// Kiosk settings save must update theme when explicitly posted.
$kioskRow = [
    '_screen_opts_form' => '1',
    'theme' => 'ember',
    'show_ticker' => '1',
];
$updated = rotation_apply_screen_post_row($existing, $kioskRow, false, false, 'lobby', true);
if (($updated['theme'] ?? '') !== 'ember') {
    fwrite(STDERR, "FAIL: kiosk POST should update theme to ember, got " . ($updated['theme'] ?? '(missing)') . "\n");
    $fail++;
}

// Missing theme in kiosk POST must not clear a saved theme.
$noThemeRow = [
    '_screen_opts_form' => '1',
    'show_ticker' => '1',
];
$preserved = rotation_apply_screen_post_row($existing, $noThemeRow, false, false, 'lobby', true);
if (($preserved['theme'] ?? '') !== 'gvsu_lakers') {
    fwrite(STDERR, "FAIL: kiosk POST without theme should preserve existing theme\n");
    $fail++;
}

// Font stacks follow the active theme key (GVSU vs default).
$gvsuFonts = signage_theme_font_stacks('gvsu_lakers');
$lakeFonts = signage_theme_font_stacks('lake_night');
if (!str_contains($gvsuFonts['sans'], 'Open Sans')) {
    fwrite(STDERR, "FAIL: GVSU theme should use Open Sans\n");
    $fail++;
}
if (!str_contains($lakeFonts['display'], 'Big Shoulders')) {
    fwrite(STDERR, "FAIL: lake_night theme should use Big Shoulders Display\n");
    $fail++;
}
if ($gvsuFonts['sans'] === $lakeFonts['sans']) {
    fwrite(STDERR, "FAIL: GVSU and lake_night should use different sans stacks\n");
    $fail++;
}

$gvsuHead = signage_theme_fonts_head_html('gvsu_lakers');
$lakeHead = signage_theme_fonts_head_html('lake_night');
if (!str_contains($gvsuHead, 'Open+Sans') || str_contains($gvsuHead, 'IBM+Plex+Sans')) {
    fwrite(STDERR, "FAIL: GVSU font head should load Open Sans, not IBM Plex\n");
    $fail++;
}
if (!str_contains($lakeHead, 'IBM+Plex+Sans') || str_contains($lakeHead, 'Open+Sans')) {
    fwrite(STDERR, "FAIL: lake_night font head should load IBM Plex, not Open Sans\n");
    $fail++;
}

if ($fail > 0) {
    exit(1);
}

echo "OK: rotation screen theme + font stack preservation\n";
