#!/usr/bin/env php
<?php
/**
 * Export signage configuration to a zip (settings, users, rotation playlists, .bak sidecars).
 *
 * Usage:
 *   php scripts/backup-config.php [--root=/path] [--out=backup.zip]
 *   php scripts/backup-config.php --store [--keep=10]
 *   php scripts/backup-config.php --store --cookies
 *
 * Cron example (nightly on-server copy, keep 14):
 *   0 3 * * * cd /var/www/html/boards && php scripts/backup-config.php --store --keep=14
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
require_once $root . '/lib/backup_lib.php';

$store = in_array('--store', $argv, true);
$includeCookies = in_array('--cookies', $argv, true);
$keep = SIGNAGE_CONFIG_BACKUP_KEEP_DEFAULT;
$outPath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
        continue;
    }
    if (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int)substr($arg, 7));
    }
}

if ($store) {
    $result = signage_config_backup_store_on_disk($keep, true, $includeCookies);
    if (!$result['ok']) {
        fwrite(STDERR, 'Backup failed: ' . ($result['error'] ?? 'unknown') . "\n");
        exit(1);
    }
    echo 'Stored ' . ($result['relative'] ?? '') . ' — '
        . (int)($result['file_count'] ?? 0) . ' entries, '
        . signage_config_backup_format_bytes((int)($result['bytes'] ?? 0));
    if (($result['pruned'] ?? 0) > 0) {
        echo ', pruned ' . (int)$result['pruned'] . ' old zip(s)';
    }
    echo "\n";
    exit(0);
}

if ($outPath === null || $outPath === '') {
    $outPath = 'signage-config-' . gmdate('Y-m-d-His') . 'Z.zip';
}

$absOut = $outPath;
if ($outPath[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $outPath)) {
    $absOut = getcwd() . '/' . $outPath;
}

$result = signage_config_backup_write_zip($absOut, true, $includeCookies);
if (!$result['ok']) {
    fwrite(STDERR, 'Backup failed: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

echo 'Wrote ' . $absOut . ' — '
    . (int)($result['file_count'] ?? 0) . ' entries, '
    . signage_config_backup_format_bytes((int)($result['bytes'] ?? 0)) . "\n";
