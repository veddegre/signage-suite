#!/usr/bin/env php
<?php
/**
 * Inspect what a display's rotation shell will play (playlist source, file, active pages).
 *
 * Usage:
 *   php scripts/diagnose-rotation-screen.php --screen=edingco
 *   php scripts/diagnose-rotation-screen.php --screen=edingco --root=/var/www/html/boards
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
require_once $root . '/lib/users_lib.php';
require_once $root . '/lib/rotation_lib.php';
require_once $root . '/lib/rotation_pages_store_lib.php';

$screen = 'main';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--screen=')) {
        $screen = rotation_normalize_screen_key(substr($arg, 9));
    }
}
if ($screen === '') {
    $screen = 'main';
}

$path = rotation_pages_store_path($screen);
$fileExists = $path !== null && is_file($path);
$fileRows = $fileExists ? rotation_pages_store_read_file($screen) : [];
$legacyKey = 'rotation.PAGES_' . $screen;
cfg('_', null);
$conf = $GLOBALS['__cfg_cache'] ?? [];
$legacyRows = (is_array($conf) && is_array($conf[$legacyKey] ?? null)) ? $conf[$legacyKey] : [];
$ownRows = rotation_screen_own_pages($screen);
$effective = rotation_screen_effective_pages($screen);
$active = rotation_screen_active_pages($screen);
$runtime = rotation_screen_runtime($screen);
$assigned = users_screen_assignments()[$screen] ?? null;

$source = function_exists('rotation_screen_playlist_source')
    ? rotation_screen_playlist_source($screen)
    : ($ownRows !== [] ? 'own' : ($screen !== 'main' ? 'mirror_main' : 'starter'));

echo "Display: {$screen}\n";
echo 'Playlist source: ' . $source . "\n";
if ($screen !== 'main' && $source === 'mirror_main') {
    echo "  → No saved playlist for this display; kiosk plays main's rotation.\n";
    echo "  → Fix: admin → Rotation → Playlist — {$screen} → add pages → Save.\n";
    echo "  → Or: php scripts/recover-rotation-pages.php --copy-main={$screen}\n";
}
echo "\n";

echo "File: " . ($path ?? '(none)') . "\n";
echo '  exists: ' . ($fileExists ? 'yes' : 'no') . "\n";
if ($fileExists) {
    echo '  mtime: ' . date('c', (int)filemtime($path)) . "\n";
    echo '  rows in file: ' . count($fileRows) . "\n";
}
echo '  legacy settings rows: ' . (is_array($legacyRows) ? count($legacyRows) : 0) . "\n";
echo '  own rows (file + legacy): ' . count($ownRows) . "\n";
if ($assigned !== null && $assigned !== '') {
    echo '  assigned operator id: ' . $assigned . "\n";
}
echo "\n";

echo 'Effective playlist rows: ' . count($effective) . "\n";
echo 'Active on wall now: ' . count($active) . "\n";
echo "\n";

if ($active === []) {
    echo "No active pages — check dwell > 0, Skip toggles, hour windows, and slide deploy.\n\n";
} else {
    foreach ($active as $i => $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        $label = rotation_page_label($url);
        $dwell = rotation_page_dwell($page);
        echo sprintf("  [%d] %ds  %s  (%s)\n", $i, $dwell, $label, $url);
    }
    echo "\n";
}

echo "Runtime revision: " . (string)($runtime['revision'] ?? '') . "\n";
echo "Kiosk URL: " . rotation_screen_kiosk_url($screen) . "\n";
echo "Player URL: " . rotation_screen_preview_url($screen) . "\n";
