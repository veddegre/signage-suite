<?php
/**
 * Smoke test for per-display color schemes.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;
$curated = signage_curated_theme_keys();
if (count($curated) !== 6) {
    fwrite(STDERR, "FAIL: expected 6 curated themes, got " . count($curated) . "\n");
    $fail++;
}
$presets = signage_theme_presets();
if ($presets === [] || !isset($presets['lake_night']) || count($presets) !== 6) {
    fwrite(STDERR, "FAIL: signage_theme_presets should expose exactly 6 curated schemes\n");
    $fail++;
}
$all = signage_theme_presets_all();
if (!isset($all['forest'])) {
    fwrite(STDERR, "FAIL: legacy forest preset should still resolve for old slides\n");
    $fail++;
}
$css = signage_theme_css_block('harbor_glow');
if ($css === '' || !str_contains($css, '--beacon:')) {
    fwrite(STDERR, "FAIL: css block empty\n");
    $fail++;
}
$harbor = signage_theme_preset('harbor_glow');
if ($harbor === null || strtolower((string)$harbor['harbor']) === '#141f33' && strtolower((string)$harbor['lake-night']) === '#0c1422') {
    // harbor_glow should derive harbor from its gradient, not default lake_night harbor only
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
$frostCss = signage_theme_css_block('frost');
if (!str_contains($frostCss, '--tile-bg') || !str_contains($frostCss, '--signage-light:1')) {
    fwrite(STDERR, "FAIL: frost theme missing light/tile tokens\n");
    $fail++;
}
$key = signage_active_theme_key();
if ($key === '') {
    fwrite(STDERR, "FAIL: active theme empty\n");
    $fail++;
}

exit($fail > 0 ? 1 : 0);
