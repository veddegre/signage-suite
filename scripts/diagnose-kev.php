#!/usr/bin/env php
<?php
/**
 * CLI: test CISA KEV feed fetch and board filtering.
 *
 * Usage: php scripts/diagnose-kev.php [--root=/path/to/install]
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
require_once $root . '/lib/kev_lib.php';

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n\n";

echo 'Cache TTL: ' . kev_cache_ttl() . "s\n";
echo 'Warn window: ' . kev_warn_days() . " days\n";
echo 'New-entry window: ' . kev_added_days() . " days\n";
echo 'Max items: ' . kev_max_items() . "\n";
echo 'Show ransomware-linked: ' . (kev_show_ransomware() ? 'yes' : 'no') . "\n";
$watch = kev_watch_vendors();
echo 'Watch vendors: ' . ($watch !== [] ? implode(', ', $watch) : '(all)') . "\n\n";

$cacheFile = KEV_CACHE_DIR . '/kev_catalog.json';
echo 'Cache file: ' . $cacheFile . "\n";
if (is_file($cacheFile)) {
    echo '  age: ' . (time() - (int)filemtime($cacheFile)) . 's' . "\n";
} else {
    echo "  (missing)\n";
}
echo "\nFetching board data…\n\n";

$data = kev_board_data();
$stats = $data['stats'] ?? [];
$hero = $data['hero'];

if (empty($data['has_data'])) {
    echo "KEV catalog unavailable";
    if (!empty($GLOBALS['diag']['kev'])) {
        echo ' — ' . (string)$GLOBALS['diag']['kev'];
    }
    echo "\n";
    exit(1);
}

echo 'Catalog entries: ' . (int)($stats['catalog'] ?? 0) . "\n";
echo 'New within window: ' . (int)($stats['new_to_kev'] ?? 0) . "\n";
echo 'Due within warn window: ' . (int)($stats['due_soon'] ?? 0) . "\n";
if (($stats['catalog_version'] ?? '') !== '') {
    echo 'Catalog version: ' . (string)$stats['catalog_version'] . "\n";
}
echo "\n";

if (is_array($hero)) {
    echo "Hero: " . (string)($hero['id'] ?? '') . ' — ' . (string)($hero['name'] ?? '') . "\n";
    echo '  Added: ' . kev_format_added_relative($hero) . "\n";
    echo '  Due: ' . kev_format_relative_date((string)($hero['due'] ?? '')) . "\n";
    if (($hero['vendor'] ?? '') !== '') {
        echo '  Vendor: ' . (string)$hero['vendor'] . "\n";
    }
    echo "\n";
} else {
    echo "Hero: (none — no entries match current filters)\n\n";
}

$list = $data['list'] ?? [];
if ($list !== []) {
    echo "List sample:\n";
    foreach (array_slice($list, 0, 5) as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '  ' . (string)($row['id'] ?? '') . ' · ' . kev_format_added_relative($row) . ' · ' . kev_format_relative_date((string)($row['due'] ?? '')) . "\n";
    }
    echo "\n";
}

echo "OK\n";
