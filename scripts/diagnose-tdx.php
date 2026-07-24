#!/usr/bin/env php
<?php
/**
 * CLI: test TeamDynamix auth, metadata, and ticket search for a page.
 *
 * Usage:
 *   php scripts/diagnose-tdx.php [page_key] [--root=/path/to/install] [--timing]
 *
 * Reads config/settings.json from the install root.
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
require_once $root . '/lib/tdx_lib.php';

$pageKey = tdx_normalize_page_key($opts['positional'][0] ?? 'main');

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n";
if (!is_file(cfg_path())) {
    echo "settings.json not found — set TeamDynamix in admin or use --root=/var/www/html/boards\n\n";
} else {
    echo "\n";
}

$allPages = tdx_admin_pages();
if ($allPages !== []) {
    echo "TeamDynamix pages in config:\n";
    foreach ($allPages as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        echo '  ' . $key . ': ' . (string)($row['title'] ?? '') . ' — app '
            . (int)($row['app_id'] ?? 0) . "\n";
    }
    echo "\n";
}

$page = tdx_resolve_page($pageKey);
echo "Page key: {$pageKey}\n";
echo 'Title: ' . (string)($page['title'] ?? '') . "\n";
echo 'App ID: ' . (int)($page['app_id'] ?? 0) . "\n";
echo 'Type IDs: ' . tdx_ids_string($page['type_ids'] ?? '') . "\n";
echo 'Status IDs: ' . (tdx_ids_string($page['status_ids'] ?? '') ?: '(default open)') . "\n";
echo 'Group IDs: ' . (tdx_ids_string($page['group_ids'] ?? '') ?: '(any)') . "\n";
echo 'Responsible users: ' . (tdx_users_string($page['responsible_users'] ?? '') ?: '(any)') . "\n";
echo 'Responsible UIDs: ' . (tdx_uids_string($page['responsible_uids'] ?? '') ?: '(any)') . "\n";
echo 'Priority IDs: ' . (tdx_ids_string($page['priority_ids'] ?? '') ?: '(any)') . "\n\n";

if (!tdx_configured()) {
    echo "TeamDynamix not configured in " . cfg_path() . ".\n";
    exit(1);
}

echo 'Base URL: ' . tdx_base_url() . "\n";
echo 'Auth mode: ' . tdx_auth_mode() . "\n\n";

$timing = in_array('--timing', $argv, true);
if ($timing) {
    $test = tdx_test_connection((int)($page['app_id'] ?? 0) > 0 ? (int)$page['app_id'] : null);
    echo 'Connection test: ' . ($test['ok'] ? 'OK' : 'FAILED') . "\n";
    if (!empty($test['detail'])) {
        echo 'Detail: ' . $test['detail'] . "\n";
    }
    if (!empty($test['ms'])) {
        echo "Latency: {$test['ms']} ms\n";
    }
    echo "\n";
}

$appId = (int)($page['app_id'] ?? 0);
if ($appId <= 0) {
    echo "No app ID on this page — set app_id in admin.\n";
    exit(1);
}

$error = null;
$meta = tdx_fetch_metadata($appId, $error, true);
echo 'Applications: ' . count($meta['applications'] ?? []) . "\n";
echo 'Types (app ' . $appId . '): ' . count($meta['types'] ?? []) . "\n";
echo 'Statuses: ' . count($meta['statuses'] ?? []) . "\n";
echo 'Groups: ' . count($meta['groups'] ?? []) . "\n";
echo 'Priorities: ' . count($meta['priorities'] ?? []) . "\n\n";

$tickets = tdx_search_tickets($page, $error);
if ($error !== null && $tickets === []) {
    echo 'Search failed: ' . $error . "\n";
    exit(1);
}

echo 'Tickets returned: ' . count($tickets) . "\n";
foreach (array_slice($tickets, 0, 15) as $row) {
    if (!is_array($row)) {
        continue;
    }
    echo '#' . (int)($row['id'] ?? 0) . ' [' . (string)($row['priority'] ?? '') . '] '
        . (string)($row['status'] ?? '') . ' — ' . (string)($row['title'] ?? '') . "\n";
}
if (count($tickets) > 15) {
    echo '… ' . (count($tickets) - 15) . " more\n";
}
echo "\n";

$data = tdx_fetch_wall_data($page);
if (empty($data['ok'])) {
    echo 'Wall fetch failed: ' . (string)($data['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo 'Cached wall tickets: ' . count($data['tickets'] ?? []) . "\n";
echo "OK\n";
