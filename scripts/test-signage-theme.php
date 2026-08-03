<?php
/**
 * Smoke test for per-display color schemes.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;
$curated = signage_curated_theme_keys();
if (count($curated) !== 5) {
    fwrite(STDERR, "FAIL: expected 5 curated themes, got " . count($curated) . "\n");
    $fail++;
}
if ($curated !== ['ember', 'forest', 'frost', 'gvsu_lakers', 'lake_night']) {
    fwrite(STDERR, "FAIL: curated themes should be alphabetical by label\n");
    $fail++;
}
$presets = signage_theme_presets();
if ($presets === [] || !isset($presets['lake_night']) || count($presets) !== 5) {
    fwrite(STDERR, "FAIL: signage_theme_presets should expose exactly 5 curated schemes\n");
    $fail++;
}
if (!isset($presets['frost'], $presets['ember'], $presets['forest'])) {
    fwrite(STDERR, "FAIL: curated presets should include ember, forest, frost\n");
    $fail++;
}
$all = signage_theme_presets_all();
foreach (['beacon_bar', 'harbor_glow', 'slate'] as $legacy) {
    if (!isset($all[$legacy])) {
        fwrite(STDERR, "FAIL: retired curated theme {$legacy} should remain in legacy catalog\n");
        $fail++;
    }
}
$css = signage_theme_css_block('ember');
if ($css === '' || !str_contains($css, '--beacon:')) {
    fwrite(STDERR, "FAIL: ember css block empty\n");
    $fail++;
}
$emberPage = signage_theme_preset('ember')['lake-night'] ?? '';
if (stripos($emberPage, '1a1210') === false) {
    fwrite(STDERR, "FAIL: ember should use warm page background, not lake navy\n");
    $fail++;
}
$forestPage = signage_theme_preset('forest')['lake-night'] ?? '';
if (stripos($forestPage, '0f1a14') === false) {
    fwrite(STDERR, "FAIL: forest should use green page background\n");
    $fail++;
}
$frost = signage_theme_preset('frost');
if ($frost === null || ($frost['light'] ?? '0') !== '1') {
    fwrite(STDERR, "FAIL: frost should be a light theme\n");
    $fail++;
}
$gvsu = signage_theme_preset('gvsu_lakers');
if ($gvsu === null
    || stripos((string)($gvsu['lake-night'] ?? ''), '0032a0') === false
    || stripos((string)($gvsu['harbor'] ?? ''), '002878') === false
    || stripos((string)($gvsu['beacon'] ?? ''), '0ecbf0') === false) {
    fwrite(STDERR, "FAIL: gvsu_lakers should use GVSU Blue background with secondary panel/accent colors\n");
    $fail++;
}
$gvsuCss = signage_theme_css_block('gvsu_lakers');
if (!preg_match('/--data-accent:#dec197/i', $gvsuCss)) {
    fwrite(STDERR, "FAIL: gvsu --data-accent should be GVSU gold (#DEC197)\n");
    $fail++;
}
$lakeCss = signage_theme_css_block('lake_night');
if (!str_contains($lakeCss, '--tile-bg')) {
    fwrite(STDERR, "FAIL: lake_night missing tile tokens\n");
    $fail++;
}
$key = signage_active_theme_key();
if ($key === '') {
    fwrite(STDERR, "FAIL: active theme empty\n");
    $fail++;
}

exit($fail > 0 ? 1 : 0);
