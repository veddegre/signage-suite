<?php
/**
 * Regression: saving Rotation must not wipe per-display theme/font (and tied stacks).
 *
 * Usage: php scripts/test-rotation-screen-theme.php
 */

require_once dirname(__DIR__) . '/lib/rotation_lib.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;

// Display settings table save must preserve theme + font when the row has neither field.
$existing = [
    'name' => 'Lobby TV',
    'theme' => 'gvsu_lakers',
    'font_pack' => 'gvsu',
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
if (($merged['font_pack'] ?? '') !== 'gvsu') {
    fwrite(STDERR, "FAIL: SCREENS table merge should preserve font_pack, got " . ($merged['font_pack'] ?? '(missing)') . "\n");
    $fail++;
}
if (empty($merged['hero_strip']) || ($merged['location_place'] ?? '') !== 'Allendale') {
    fwrite(STDERR, "FAIL: SCREENS table merge should preserve kiosk extras\n");
    $fail++;
}

// Kiosk settings save must update theme and font when explicitly posted.
$kioskRow = [
    '_screen_opts_form' => '1',
    'theme' => 'ember',
    'font_pack' => 'inter',
    'show_ticker' => '1',
];
$updated = rotation_apply_screen_post_row($existing, $kioskRow, false, false, 'lobby', true);
if (($updated['theme'] ?? '') !== 'ember') {
    fwrite(STDERR, "FAIL: kiosk POST should update theme to ember, got " . ($updated['theme'] ?? '(missing)') . "\n");
    $fail++;
}
if (($updated['font_pack'] ?? '') !== 'inter') {
    fwrite(STDERR, "FAIL: kiosk POST should update font_pack to inter, got " . ($updated['font_pack'] ?? '(missing)') . "\n");
    $fail++;
}

// Missing theme/font in kiosk POST must not clear saved values.
$noThemeRow = [
    '_screen_opts_form' => '1',
    'show_ticker' => '1',
];
$preserved = rotation_apply_screen_post_row($existing, $noThemeRow, false, false, 'lobby', true);
if (($preserved['theme'] ?? '') !== 'gvsu_lakers' || ($preserved['font_pack'] ?? '') !== 'gvsu') {
    fwrite(STDERR, "FAIL: kiosk POST without theme/font should preserve existing values\n");
    $fail++;
}

// Font stacks follow font pack keys (independent of palette).
$gvsuFonts = signage_font_stacks('gvsu');
$lakeFonts = signage_font_stacks('signage');
if (!str_contains($gvsuFonts['sans'], 'Open Sans')) {
    fwrite(STDERR, "FAIL: gvsu font pack should use Open Sans\n");
    $fail++;
}
if (!str_contains($lakeFonts['display'], 'Big Shoulders')) {
    fwrite(STDERR, "FAIL: signage font pack should use Big Shoulders Display\n");
    $fail++;
}
if ($gvsuFonts['sans'] === $lakeFonts['sans']) {
    fwrite(STDERR, "FAIL: gvsu and signage should use different sans stacks\n");
    $fail++;
}

$gvsuHead = signage_theme_fonts_head_html('gvsu');
$lakeHead = signage_theme_fonts_head_html('signage');
if (!str_contains($gvsuHead, 'Open+Sans') || str_contains($gvsuHead, 'IBM+Plex+Sans')) {
    fwrite(STDERR, "FAIL: gvsu font head should load Open Sans, not IBM Plex\n");
    $fail++;
}
if (!str_contains($lakeHead, 'IBM+Plex+Sans') || str_contains($lakeHead, 'Open+Sans')) {
    fwrite(STDERR, "FAIL: signage font head should load IBM Plex, not Open Sans\n");
    $fail++;
}

$qs = signage_board_rotation_query('main', 'lake_night', false, 'gvsu');
if (!str_contains($qs, 'font=gvsu') || !str_contains($qs, 'theme=lake_night')) {
    fwrite(STDERR, "FAIL: rotation query should carry independent theme and font params\n");
    $fail++;
}

if (count(signage_font_packs()) !== 8) {
    fwrite(STDERR, "FAIL: expected 8 curated font packs\n");
    $fail++;
}

if ($fail > 0) {
    exit(1);
}

echo "OK: rotation screen theme + font stack preservation\n";
