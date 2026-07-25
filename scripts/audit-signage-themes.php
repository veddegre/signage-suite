<?php
/**
 * Scan boards for theme/clipping risk patterns. Exit 1 if any FAIL lines.
 *
 * Usage: php scripts/audit-signage-themes.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/signage_theme_lib.php';

$fail = 0;
$warn = 0;

$presets = signage_theme_presets();
if (count($presets) !== 6) {
    fwrite(STDERR, 'FAIL: expected 6 curated signage themes, got ' . count($presets) . "\n");
    $fail++;
}
$requiredTokens = ['--tile-bg', '--data-accent', '--inset-muted', '--map-accent'];
foreach (['lake_night', 'frost', 'gvsu_lakers'] as $key) {
    $css = signage_theme_css_block($key);
    foreach ($requiredTokens as $tok) {
        if (!str_contains($css, $tok . ':')) {
            fwrite(STDERR, "FAIL: {$key} css block missing {$tok}\n");
            $fail++;
        }
    }
}
$gvsuCss = signage_theme_css_block('gvsu_lakers');
if (!str_contains($gvsuCss, '--data-accent:#DEC197') && !str_contains($gvsuCss, '--data-accent:#dec197')) {
    fwrite(STDERR, "FAIL: gvsu_lakers should set --data-accent to GVSU gold\n");
    $fail++;
}

$boardsDir = dirname(__DIR__) . '/boards';
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($boardsDir));
$boardFiles = [];
foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $boardFiles[] = $file->getPathname();
}
sort($boardFiles);

$noTheme = [];
$hardcodedNavy = [];
$tileLakeNight = [];
foreach ($boardFiles as $path) {
    $rel = str_replace(dirname(__DIR__) . '/', '', $path);
    $src = (string)file_get_contents($path);
    if (!str_contains($src, 'signage_theme_css()') && !str_contains($rel, 'traffic_tiles.php')) {
        $noTheme[] = $rel;
    }
    foreach (explode("\n", $src) as $i => $line) {
        $n = $i + 1;
        if (preg_match('/#0c1422|#8aa0c0|#edf2fb/', $line) && !str_contains($rel, 'rss.php')
            && !str_contains($line, '--map-') && !str_contains($line, '--l3-') && !str_contains($line, '--dshield')
            && !str_contains($line, '--ioda') && !str_contains($line, '--ports') && !str_contains($line, '--src-')
            && !str_contains($line, '--l7-')) {
            $hardcodedNavy[] = "{$rel}:{$n}";
        }
        if (str_contains($line, 'background:var(--lake-night)') && !preg_match('/html,body|iframe|\.frame|\.board \{|\.setup \{|justify-center/', $line)) {
            $tileLakeNight[] = "{$rel}:{$n}";
        }
    }
}

if ($noTheme !== []) {
    fwrite(STDERR, "WARN: boards without signage_theme_css(): " . implode(', ', $noTheme) . "\n");
    $warn += count($noTheme);
}
if ($hardcodedNavy !== []) {
    fwrite(STDERR, "WARN: hardcoded legacy palette (sample): " . implode(', ', array_slice($hardcodedNavy, 0, 12)) . "\n");
    if (count($hardcodedNavy) > 12) {
        fwrite(STDERR, 'WARN: ... and ' . (count($hardcodedNavy) - 12) . " more\n");
    }
    $warn += count($hardcodedNavy);
}
if ($tileLakeNight !== []) {
    fwrite(STDERR, "FAIL: nested tiles still use --lake-night (use --tile-bg): " . implode(', ', array_slice($tileLakeNight, 0, 20)) . "\n");
    if (count($tileLakeNight) > 20) {
        fwrite(STDERR, 'FAIL: ... and ' . (count($tileLakeNight) - 20) . " more\n");
    }
    $fail += count($tileLakeNight);
}

echo 'Themes: ' . count($presets) . ' curated (' . count(signage_theme_presets_all()) . ' legacy-capable); boards scanned: ' . count($boardFiles) . "\n";
echo "Warnings: {$warn}; failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
