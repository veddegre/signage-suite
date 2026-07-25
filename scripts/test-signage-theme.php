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
if ($curated !== ['beacon_bar', 'gvsu_lakers', 'harbor_glow', 'lake_night', 'slate']) {
    fwrite(STDERR, "FAIL: curated themes should be alphabetical by label\n");
    $fail++;
}
$presets = signage_theme_presets();
if ($presets === [] || !isset($presets['lake_night']) || count($presets) !== 5) {
    fwrite(STDERR, "FAIL: signage_theme_presets should expose exactly 5 curated schemes\n");
    $fail++;
}
if (isset($presets['frost'])) {
    fwrite(STDERR, "FAIL: frost should not be in curated presets\n");
    $fail++;
}
$all = signage_theme_presets_all();
if (!isset($all['forest'])) {
    fwrite(STDERR, "FAIL: legacy forest preset should still resolve for old slides\n");
    $fail++;
}
if (!isset($all['frost'])) {
    fwrite(STDERR, "FAIL: frost should remain in legacy catalog for old slides/displays\n");
    $fail++;
}
$css = signage_theme_css_block('harbor_glow');
if ($css === '' || !str_contains($css, '--beacon:')) {
    fwrite(STDERR, "FAIL: css block empty\n");
    $fail++;
}
if (signage_theme_preset('forest') === null) {
    fwrite(STDERR, "FAIL: forest legacy preset\n");
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
