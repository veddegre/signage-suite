#!/usr/bin/env php
<?php
/**
 * CLI: test URLhaus auth, brand watch rows, and cache status for phish.php.
 *
 * Usage:
 *   php scripts/diagnose-phish.php [--root=/path/to/install]
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
require_once $root . '/lib/phish_lib.php';

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n\n";

$authKey = phish_urlhaus_auth_key();
if ($authKey === '') {
    echo "URLhaus Auth-Key: NOT SET (admin → Phishing & brand threats)\n";
} else {
    echo 'URLhaus Auth-Key: set (' . strlen($authKey) . " chars)\n";
}
echo 'URLhaus cache TTL: ' . phish_urlhaus_cache_ttl() . "s\n";
echo 'CT cache TTL: ' . phish_ct_cache_ttl() . "s\n";
echo 'CT lookback: ' . phish_ct_lookback_days() . " days\n";
echo 'Online only: ' . (phish_online_only() ? 'yes' : 'no') . "\n";
$tags = phish_tags_include();
echo 'Tag filters: ' . ($tags !== [] ? implode(', ', $tags) : '(none)') . "\n\n";

$brands = phish_brand_watch_rows();
if ($brands === []) {
    echo "Brand watch rows: none configured\n\n";
} else {
    echo "Brand watch rows:\n";
    foreach ($brands as $row) {
        $key = (string)($row['key'] ?? '');
        $label = (string)($row['label'] ?? $key);
        $rootDomain = (string)($row['root_domain'] ?? '');
        $keywords = is_array($row['keywords'] ?? null) ? implode(', ', $row['keywords']) : (string)($row['keywords'] ?? '');
        echo "  {$key}: {$label} — {$rootDomain}";
        if ($keywords !== '') {
            echo " · keywords: {$keywords}";
        }
        echo "\n";
    }
    echo "\n";
}

$cacheDir = SIGNAGE_ROOT . '/cache';
$urlhausCache = $cacheDir . '/phish_urlhaus_recent.json';
echo 'URLhaus cache file: ' . $urlhausCache . "\n";
if (is_file($urlhausCache)) {
    $age = time() - (int)filemtime($urlhausCache);
    echo '  age: ' . $age . 's (TTL ' . phish_urlhaus_cache_ttl() . "s)\n";
} else {
    echo "  (missing)\n";
}

echo "\nFetching board data…\n\n";
$data = phish_board_data();

if (!empty($data['needs_auth'])) {
    echo "URLhaus: needs Auth-Key — no recent URLs loaded\n";
} else {
    echo 'URLhaus recent URLs: ' . count($data['urls'] ?? []) . "\n";
    echo 'Online URLs in list: ' . (int)($data['online_count'] ?? 0) . "\n";
    if (!empty($data['top_tags'])) {
        echo 'Top tags: ';
        $parts = [];
        foreach ($data['top_tags'] as $tag => $count) {
            $parts[] = $tag . ' (' . $count . ')';
        }
        echo implode(', ', $parts) . "\n";
    }
}

echo 'Brand panels: ' . count($data['brands'] ?? []) . "\n";
echo 'Brand CT hits (total): ' . (int)($data['brand_hits'] ?? 0) . "\n\n";

if (!empty($data['urls'])) {
    echo "Sample URLhaus rows:\n";
    foreach (array_slice($data['urls'], 0, 5) as $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '  ' . (string)($row['url'] ?? '') . ' · ' . phish_format_added((string)($row['date_added'] ?? '')) . "\n";
    }
    echo "\n";
}

if (!empty($data['brands'])) {
    echo "Brand watch results:\n";
    foreach ($data['brands'] as $brand) {
        if (!is_array($brand)) {
            continue;
        }
        $label = (string)($brand['label'] ?? '');
        $hits = (int)($brand['hit_count'] ?? 0);
        echo "  {$label}: {$hits} hit" . ($hits === 1 ? '' : 's') . "\n";
        foreach (array_slice($brand['hits'] ?? [], 0, 3) as $hit) {
            echo '    ' . (is_string($hit) ? $hit : (string)($hit['name'] ?? '')) . "\n";
        }
    }
    echo "\n";
}

if (empty($data['has_data'])) {
    echo "No data to display — configure URLhaus key and/or brand watch rows.\n";
    exit($authKey === '' && $brands === [] ? 1 : 0);
}

echo "OK\n";
