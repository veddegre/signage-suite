#!/usr/bin/env php
<?php
/**
 * Restore rotation playlists from settings.json (and backups / tmp files).
 *
 * Usage:
 *   php scripts/recover-rotation-pages.php [--root=/path]
 *   php scripts/recover-rotation-pages.php --screen=veddersg [--force]
 *   php scripts/recover-rotation-pages.php --copy-main=veddersg
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
require_once $root . '/lib/rotation_lib.php';
require_once $root . '/lib/rotation_pages_store_lib.php';

$force = in_array('--force', $argv, true);
$onlyScreen = null;
$copyMain = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--screen=')) {
        $onlyScreen = rotation_pages_store_normalize_screen(substr($arg, 9));
    }
    if (str_starts_with($arg, '--copy-main=')) {
        $copyMain = rotation_pages_store_normalize_screen(substr($arg, 12));
    }
}

if ($copyMain !== null && $copyMain !== '') {
    $mainPages = rotation_screen_own_pages('main');
    if ($mainPages === []) {
        $mainPages = rotation_resolved_playlist_pages('main');
    }
    if ($mainPages === []) {
        fwrite(STDERR, "Main playlist is empty — cannot copy.\n");
        exit(1);
    }
    if (!rotation_pages_store_write_file($copyMain, $mainPages)) {
        fwrite(STDERR, "Could not write {$copyMain} playlist file.\n");
        exit(1);
    }
    cfg_update(static function (array $c) use ($copyMain): array {
        unset($c['rotation.PAGES_' . $copyMain]);

        return $c;
    });
    echo 'Copied ' . count($mainPages) . " row(s) from main to {$copyMain}\n";
    echo rotation_pages_store_path($copyMain) . "\n";
    exit(0);
}

/** @return list<array<string,mixed>> */
function recover_rotation_pages_from_bak_file(string $bakPath): array
{
    $decoded = json_decode((string)file_get_contents($bakPath), true);
    if (!is_array($decoded)) {
        return [];
    }
    $pages = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $pages[] = $row;
        }
    }

    return $pages;
}

/** @return array<string,list<array<string,mixed>>> */
function recover_rotation_scan_playlist_baks(?string $onlyScreen): array
{
    $dir = rotation_pages_store_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (glob($dir . '/*.json.bak') ?: [] as $bak) {
        $base = basename($bak, '.json.bak');
        $screen = rotation_pages_store_normalize_screen($base);
        if ($onlyScreen !== null && $onlyScreen !== '' && $screen !== $onlyScreen) {
            continue;
        }
        $pages = recover_rotation_pages_from_bak_file($bak);
        if ($pages === []) {
            continue;
        }
        if (!isset($out[$screen]) || count($pages) > count($out[$screen])) {
            $out[$screen] = $pages;
        }
    }

    return $out;
}

/** @return list<string> */
function recover_rotation_settings_candidate_paths(): array
{
    $dir = SIGNAGE_ROOT . '/config';
    $paths = [];
    foreach (['settings.json.bak', 'settings.json~', 'settings.json.old'] as $name) {
        $p = $dir . '/' . $name;
        if (is_file($p)) {
            $paths[] = $p;
        }
    }
    $primary = cfg_path();
    if (is_file($primary)) {
        $paths[] = $primary;
    }
    foreach (glob($dir . '/settings.json.tmp.*') ?: [] as $p) {
        $paths[] = $p;
    }

    return array_values(array_unique($paths));
}

/**
 * @return array<string,list<array<string,mixed>>> screen => pages (best seen)
 */
function recover_rotation_extract_pages_from_file(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $key => $val) {
        if (!is_string($key) || !preg_match('/^rotation\.PAGES_(.+)$/', $key, $m)) {
            continue;
        }
        if (!is_array($val) || $val === []) {
            continue;
        }
        $screen = rotation_pages_store_normalize_screen($m[1]);
        $pages = [];
        foreach ($val as $row) {
            if (is_array($row)) {
                $pages[] = $row;
            }
        }
        if ($pages === []) {
            continue;
        }
        if (!isset($out[$screen]) || count($pages) > count($out[$screen])) {
            $out[$screen] = $pages;
        }
    }

    return $out;
}

/** @param array<string,list<array<string,mixed>>> $merged */
function recover_rotation_merge_candidates(array &$merged, array $found): void
{
    foreach ($found as $screen => $pages) {
        if (!isset($merged[$screen]) || count($pages) > count($merged[$screen])) {
            $merged[$screen] = $pages;
        }
    }
}

$merged = recover_rotation_scan_playlist_baks($onlyScreen);
if ($merged !== []) {
    echo 'Found playlist .bak file(s): ' . implode(', ', array_keys($merged)) . "\n";
}
foreach (recover_rotation_settings_candidate_paths() as $path) {
    $found = recover_rotation_extract_pages_from_file($path);
    if ($found !== []) {
        echo 'Found legacy keys in ' . $path . ': ' . implode(', ', array_keys($found)) . "\n";
    }
    recover_rotation_merge_candidates($merged, $found);
}

if ($onlyScreen !== null && $onlyScreen !== '') {
    $merged = array_intersect_key($merged, [$onlyScreen => true]);
}

if ($merged === []) {
    echo "No playlist data in .json.bak files or rotation.PAGES_* in settings / settings.json.bak.\n";
    echo "Try: php scripts/recover-rotation-pages.php --copy-main=veddersg\n";
    echo "Or rebuild in admin → Rotation (Copy from main / Templates / starter playlist).\n";
    exit(1);
}

$written = 0;
foreach ($merged as $screen => $pages) {
    $path = rotation_pages_store_path($screen);
    if ($path === null) {
        continue;
    }
    $onDisk = is_file($path) ? rotation_pages_store_read_file($screen) : [];
    if ($onDisk !== [] && !$force) {
        echo "Skip {$screen}: file already has " . count($onDisk) . " row(s) (use --force)\n";
        continue;
    }
    if (!rotation_pages_store_write_file($screen, $pages)) {
        echo "FAILED {$screen}\n";
        continue;
    }
    $written++;
    echo 'Wrote ' . $path . ' (' . count($pages) . " rows)\n";
}

if ($written > 0) {
    cfg_update(static function (array $c) use ($merged): array {
        foreach (array_keys($merged) as $screen) {
            unset($c['rotation.PAGES_' . $screen]);
        }

        return $c;
    });
    echo "Cleaned legacy rotation.PAGES_* keys from settings.json\n";
}

echo $written > 0 ? "Done.\n" : "Nothing written.\n";
exit($written > 0 ? 0 : 1);
