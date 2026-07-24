#!/usr/bin/env php
<?php
/**
 * CLI: probe configured TLS hosts for certexp.php.
 *
 * Usage: php scripts/diagnose-certexp.php [--root=/path/to/install]
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
require_once $root . '/lib/certexp_lib.php';

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n\n";

echo 'Warn window: ' . certexp_warn_days() . " days\n";
echo 'Max items: ' . certexp_max_items() . "\n";
echo 'Cache TTL: ' . certexp_cache_ttl() . "s\n\n";

$hosts = certexp_host_rows();
if ($hosts === []) {
    echo "No hosts configured — add certexp.HOSTS rows in admin.\n";
    exit(1);
}

echo "Configured hosts:\n";
foreach ($hosts as $row) {
    echo '  ' . (string)($row['key'] ?? '') . ': ' . (string)($row['label'] ?? '')
        . ' — ' . (string)($row['host'] ?? '') . ':' . (int)($row['port'] ?? 443) . "\n";
}
echo "\nProbing…\n\n";

$fail = 0;
foreach ($hosts as $row) {
    $host = (string)($row['host'] ?? '');
    $port = (int)($row['port'] ?? 443);
    $probe = certexp_probe($host, $port);
    $ok = !empty($probe['ok']);
    $days = $probe['days_left'];
    $status = $ok
        ? ($days !== null && $days <= certexp_warn_days() ? 'WARN' : 'OK')
        : 'ERR';
    if (!$ok) {
        $fail++;
    }
    echo "[{$status}] " . (string)($row['label'] ?? $host) . " ({$host}:{$port})\n";
    if (!$ok) {
        echo '  ' . (string)($probe['error'] ?? 'probe failed') . "\n";
    } else {
        echo '  subject=' . (string)($probe['subject'] ?? '') . ' expires=' . (string)($probe['expires'] ?? '')
            . ' days_left=' . ($days !== null ? (string)$days : '?') . "\n";
    }
}

echo "\n";
$data = certexp_board_data();
echo 'Board expiring count: ' . (int)($data['stats']['expiring'] ?? 0) . "\n";

if ($fail > 0) {
    echo "{$fail} host(s) failed probe — check LAN routing and PHP outbound TLS.\n";
    exit(1);
}

echo "OK\n";
