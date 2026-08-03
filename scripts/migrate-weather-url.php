#!/usr/bin/env php
<?php
/**
 * Rewrite saved rotation playlists: index.php → weather.php
 *
 * Usage:
 *   php scripts/migrate-weather-url.php [--root=/path] [--dry-run]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cli_lib.php';

$opts = signage_cli_parse_argv($argv);
$root = signage_cli_resolve_root($opts['root']);
$dryRun = in_array('--dry-run', $argv, true);

if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', $root);
}
if (!defined('SIGNAGE_CLI')) {
    define('SIGNAGE_CLI', true);
}

require_once $root . '/lib/rotation_pages_store_lib.php';

$dir = rotation_pages_store_dir();
if (!is_dir($dir)) {
    fwrite(STDERR, "No playlist directory: {$dir}\n");
    exit(1);
}

$changed = 0;
$files = glob($dir . '/*.json') ?: [];
sort($files);

foreach ($files as $path) {
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        continue;
    }
    $before = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    $fixed = rotation_pages_store_apply_url_fixes($decoded);
    $after = json_encode($fixed, JSON_UNESCAPED_SLASHES);
    if ($before === $after) {
        continue;
    }
    $changed++;
    $screen = basename($path, '.json');
    echo ($dryRun ? '[dry-run] ' : '') . "{$screen}: index.php → weather.php\n";
    if (!$dryRun) {
        rotation_pages_store_write_file($screen, $fixed);
    }
}

echo $changed === 0
    ? "No playlists needed updating.\n"
    : ($dryRun
        ? "{$changed} playlist(s) would be updated (re-run without --dry-run).\n"
        : "Updated {$changed} playlist(s).\n");
