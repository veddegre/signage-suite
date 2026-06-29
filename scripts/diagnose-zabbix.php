#!/usr/bin/env php
<?php
/**
 * CLI: test Zabbix page host group resolution and API connectivity.
 * Usage: php scripts/diagnose-zabbix.php [page_key]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/zabbix_lib.php';

$pageKey = zabbix_normalize_page_key($argv[1] ?? 'main');
$page = zabbix_resolve_page($pageKey);
$rawGroups = (string)($page['host_groups'] ?? '');
$parsed = zabbix_parse_host_groups($rawGroups);

echo "Page key: {$pageKey}\n";
echo 'Title: ' . (string)($page['title'] ?? '') . "\n";
echo 'Host groups (stored): ' . ($rawGroups !== '' ? $rawGroups : '(empty)') . "\n";
echo 'Parsed: ' . ($parsed !== [] ? implode(' | ', $parsed) : '(none)') . "\n\n";

if (!zabbix_configured()) {
    echo "Zabbix URL/token not configured.\n";
    exit(1);
}

$error = null;
$ids = zabbix_resolve_group_ids($parsed, $error);
if ($ids === []) {
    echo "Resolve failed: " . ($error ?: 'unknown error') . "\n";
    exit(1);
}

echo 'Resolved group IDs: ' . implode(', ', $ids) . "\n";

$data = zabbix_fetch_wall_data($page);
if (empty($data['ok'])) {
    echo 'Wall fetch failed: ' . (string)($data['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo 'Problems: ' . count($data['problems'] ?? []) . "\n";
echo 'Hosts: ' . count($data['hosts'] ?? []) . "\n";
echo "OK\n";
