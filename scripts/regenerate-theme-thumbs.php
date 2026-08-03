<?php
/**
 * Regenerate slide_backgrounds/*.png for curated wall themes.
 *
 * Usage:
 *   php scripts/regenerate-theme-thumbs.php              # ember, forest, frost
 *   php scripts/regenerate-theme-thumbs.php ember frost  # specific keys
 *   php scripts/regenerate-theme-thumbs.php --all        # all five curated
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/slides_lib.php';

if (!function_exists('imagepng')) {
    fwrite(STDERR, "php-gd required (imagepng missing)\n");
    exit(1);
}

$keys = array_slice($argv, 1);
if ($keys === [] || (count($keys) === 1 && $keys[0] === '--all')) {
    $keys = $keys === [] ? ['ember', 'forest', 'frost'] : null;
}

$written = slide_background_regenerate_themes($keys);
echo 'Wrote ' . $written . " theme PNG(s) to " . slide_backgrounds_dir() . "\n";
exit($written > 0 ? 0 : 1);
