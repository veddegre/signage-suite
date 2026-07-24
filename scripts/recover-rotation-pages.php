#!/usr/bin/env php
<?php
/**
 * Restore per-display playlists from legacy rotation.PAGES_* keys in settings.json
 * into config/rotation/pages/<screen>.json (when migration did not run or files were wiped).
 *
 * Usage: php scripts/recover-rotation-pages.php [--root=/path] [--force]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cli_lib.php';

$opts = signage_cli_parse_argv($argv);
$root = signage_cli_resolve_root($opts['root']);
if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', $root);
}
if (!defined('SIGNAGE_CLI')) {
    define('SIGNAGE_CLI', true);
}
require_once $root . '/config.php';
require_once $root . '/lib/rotation_pages_store_lib.php';

$force = in_array('--force', $argv, true);

cfg('_', null);
$conf = cfg_all();
$found = 0;
$written = 0;

foreach ($conf as $key => $val) {
    if (!is_string($key) || !preg_match('/^rotation\.PAGES_(.+)$/', $key, $m)) {
        continue;
    }
    if (!is_array($val) || $val === []) {
        continue;
    }
    $screen = rotation_pages_store_normalize_screen($m[1]);
    $path = rotation_pages_store_path($screen);
    if ($path === null) {
        continue;
    }
    $found++;
    if (is_file($path) && !$force) {
        echo "Skip {$screen}: file exists (use --force to overwrite)\n";
        continue;
    }
    if (rotation_pages_store_write_file($screen, $val)) {
        $written++;
        echo "Wrote {$path} (" . count($val) . " rows)\n";
    } else {
        echo "FAILED {$screen}\n";
    }
}

if ($written > 0) {
    cfg_update(static function (array $c) use ($conf): array {
        foreach (array_keys($conf) as $key) {
            if (is_string($key) && str_starts_with($key, 'rotation.PAGES_')) {
                unset($c[$key]);
            }
        }

        return $c;
    });
    echo "Removed legacy rotation.PAGES_* keys from settings.json\n";
}

if ($found === 0) {
    echo "No rotation.PAGES_* arrays found in settings.json\n";
    exit($written > 0 ? 0 : 1);
}

echo "Done ({$written}/{$found} restored)\n";
