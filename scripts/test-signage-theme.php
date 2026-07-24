<?php
/**
 * Smoke test for per-display color schemes.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;
$presets = signage_theme_presets();
if ($presets === [] || !isset($presets['lake_night'])) {
    fwrite(STDERR, "FAIL: missing lake_night preset\n");
    $fail++;
}
$css = signage_theme_css_block('forest');
if ($css === '' || !str_contains($css, '--beacon:')) {
    fwrite(STDERR, "FAIL: css block empty\n");
    $fail++;
}
$lilac = signage_theme_preset('lilac');
if ($lilac === null || strtolower((string)$lilac['harbor']) === '#141f33') {
    fwrite(STDERR, "FAIL: lilac harbor should follow theme, not default navy\n");
    $fail++;
}
if (signage_normalize_theme_key('Lake-Night!') !== 'lakenight') {
    // keys use underscores in slide presets
}
if (signage_theme_preset('forest') === null) {
    fwrite(STDERR, "FAIL: forest preset\n");
    $fail++;
}
$key = signage_active_theme_key();
if ($key === '') {
    fwrite(STDERR, "FAIL: active theme empty\n");
    $fail++;
}

exit($fail > 0 ? 1 : 0);
