#!/usr/bin/env php
<?php
/**
 * CLI: list Power BI dashboard rows, Azure auth, and embed mode.
 *
 * Usage:
 *   php scripts/diagnose-powerbi.php [--root=/path/to/install] [--test] [--key=report1]
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
require_once $root . '/lib/powerbi_lib.php';

$runTest = in_array('--test', $argv, true);
$testKey = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $testKey = substr($arg, 6);
    }
}

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n";
echo 'Default reload: ' . (int)cfg('powerbi.DEFAULT_RELOAD', 300) . "s\n";
echo 'Timezone: ' . (string)cfg('powerbi.TIMEZONE', 'America/Detroit') . "\n";
echo 'Azure AD: ' . (powerbi_azure_configured() ? 'configured' : 'NOT configured') . "\n\n";

if ($runTest) {
    echo "Testing Azure AD + Power BI API…\n";
    $azure = powerbi_test_azure_connection();
    echo ($azure['ok'] ? '  OK: ' : '  FAIL: ') . ($azure['detail'] ?? $azure['error'] ?? '') . "\n";
    if ($testKey !== '') {
        echo "\nTesting dashboard \"{$testKey}\"…\n";
        $dashTest = powerbi_test_dashboard($testKey);
        echo ($dashTest['ok'] ? '  OK: ' : '  FAIL: ') . ($dashTest['detail'] ?? $dashTest['error'] ?? '') . "\n";
    }
    echo "\n";
}

$registry = powerbi_dashboard_registry();
if ($registry === []) {
    echo "DASHBOARDS: none configured (admin → Power BI)\n";
    exit(0);
}

echo 'DASHBOARDS (' . count($registry) . "):\n";
foreach ($registry as $key => $dash) {
    if (!is_array($dash)) {
        continue;
    }
    $title = trim((string)($dash['title'] ?? $key));
    $url = trim((string)($dash['url'] ?? ''));
    $reload = (int)($dash['reload'] ?? cfg('powerbi.DEFAULT_RELOAD', 300));
    $mode = powerbi_dashboard_mode($dash);
    $kind = powerbi_embed_kind($url);
    $resource = powerbi_dashboard_resource($dash);

    echo "\n  {$key}: {$title}\n";
    echo '    rotation: ' . powerbi_page_url((string)$key) . "\n";
    echo '    effective mode: ' . $mode . "\n";
    echo '    configured: ' . (powerbi_dashboard_is_configured($dash) ? 'yes' : 'no') . "\n";
    echo '    reload: ' . $reload . "s\n";
    echo '    url kind: ' . $kind . "\n";
    echo '    note: ' . powerbi_embed_kind_note($kind, $mode) . "\n";
    if ($resource !== null) {
        echo '    resource: ' . $resource['type'];
        if ($resource['workspace_id'] !== '') {
            echo ' · workspace ' . $resource['workspace_id'];
        }
        if ($resource['report_id'] !== '') {
            echo ' · report ' . $resource['report_id'];
        }
        if ($resource['dashboard_id'] !== '') {
            echo ' · dashboard ' . $resource['dashboard_id'];
        }
        echo "\n";
    }
    if (trim((string)($dash['rls_username'] ?? '')) !== '') {
        echo '    RLS user: ' . (string)$dash['rls_username'] . "\n";
    }
}

echo "\nPrivate reports: configure Azure credentials and use mode token/auto.\n";
echo "Run with --test to verify Azure AD; add --key=<row-key> to test one embed token.\n";
