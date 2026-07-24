#!/usr/bin/env php
<?php
/**
 * CLI: Grafana dashboard rows and JWT embed configuration.
 *
 * Usage:
 *   php scripts/diagnose-grafana.php [--root=/path/to/install] [--test] [--key=ops]
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
require_once $root . '/lib/grafana_lib.php';

$runTest = in_array('--test', $argv, true);
$testKey = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $testKey = substr($arg, 6);
    }
}

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n";
echo 'JWT enabled: ' . (grafana_jwt_enabled() ? 'yes' : 'no') . "\n";
echo 'JWT configured: ' . (grafana_jwt_configured() ? 'yes' : 'no') . "\n";
if (grafana_jwt_configured()) {
    echo 'Login email: ' . grafana_jwt_login_email() . "\n";
    echo 'Key ID (kid): ' . grafana_jwt_kid() . "\n";
    echo 'TTL: ' . grafana_jwt_ttl() . "s\n\n";
} else {
    echo "\n";
}

if ($runTest) {
    echo "Testing JWT signing…\n";
    $jwt = grafana_test_jwt();
    echo ($jwt['ok'] ? '  OK: ' : '  FAIL: ') . ($jwt['detail'] ?? $jwt['error'] ?? '') . "\n";
    if ($testKey !== '') {
        echo "\nTesting dashboard \"{$testKey}\"…\n";
        $registry = grafana_dashboard_registry();
        $dash = $registry[grafana_normalize_key($testKey)] ?? null;
        if (!is_array($dash)) {
            echo "  FAIL: key not found\n";
        } else {
            $probe = grafana_test_dashboard_embed($testKey, $dash);
            echo ($probe['ok'] ? '  OK: ' : '  FAIL: ') . ($probe['detail'] ?? $probe['error'] ?? '') . "\n";
        }
    }
    echo "\n";
}

$registry = grafana_dashboard_registry();
if ($registry === []) {
    echo "DASHBOARDS: none configured (admin → Grafana)\n";
    exit(0);
}

echo 'DASHBOARDS (' . count($registry) . "):\n";
foreach ($registry as $key => $dash) {
    if (!is_array($dash)) {
        continue;
    }
    $title = trim((string)($dash['title'] ?? $key));
    $built = grafana_dashboard_iframe_src((string)$key, $dash);
    echo "\n  {$key}: {$title}\n";
    echo '    rotation: ' . grafana_page_url((string)$key) . "\n";
    echo '    embed: ' . (empty($built['ok']) ? 'NOT READY — ' . ($built['error'] ?? '') : (string)($built['auth'] ?? 'url'));
    if (!empty($built['cloud'])) {
        echo ' (Grafana Cloud)';
    }
    if (!empty($built['public'])) {
        echo ' (public URL)';
    }
    echo "\n";
    $email = grafana_jwt_login_email($dash);
    if ($email !== '') {
        echo '    jwt user: ' . $email . "\n";
    }
}

echo "\nSelf-hosted JWT: docs/grafana.md\n";
echo "Grafana Cloud: docs/grafana-cloud.md\n";
