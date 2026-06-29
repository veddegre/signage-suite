#!/usr/bin/env php
<?php
/**
 * CLI: test Zabbix page host group resolution and API connectivity.
 * Usage: php scripts/diagnose-zabbix.php [page_key] [--needle=substring]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/zabbix_lib.php';

$pageKey = zabbix_normalize_page_key('main');
$needle = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--needle=')) {
        $needle = substr($arg, 9);
        continue;
    }
    $pageKey = zabbix_normalize_page_key($arg);
}

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

$minSeverity = max(0, min(5, (int)($page['min_severity'] ?? 2)));
$hideAck = !empty($page['hide_acknowledged']);
$problemParams = [
    'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged', 'r_eventid', 'objectid', 'source'],
    'groupids' => $ids,
    'severities' => zabbix_severities_from_min($minSeverity),
    'recent' => false,
    'symptom' => false,
    'limit' => 50,
    'suppressed' => false,
];
if ($hideAck) {
    $problemParams['acknowledged'] = false;
}

$rawProblems = zabbix_api_call('problem.get', $problemParams, $error);
if (!is_array($rawProblems)) {
    echo 'problem.get failed: ' . ($error ?: 'unknown') . "\n";
    exit(1);
}

$unresolved = zabbix_filter_unresolved_problems($rawProblems);
$visible = zabbix_filter_visible_problems($unresolved, $error);

echo 'API problems (unresolved): ' . count($unresolved) . "\n";
echo 'After UI visibility filter: ' . count($visible) . "\n";
$hidden = count($unresolved) - count($visible);
if ($hidden > 0) {
    echo "Hidden by disabled trigger/host/item: {$hidden}\n";
}
echo "\n";

$triggerIds = [];
foreach ($unresolved as $problem) {
    if (!is_array($problem)) {
        continue;
    }
    if ((int)($problem['source'] ?? 0) !== 0) {
        continue;
    }
    $oid = trim((string)($problem['objectid'] ?? ''));
    if ($oid !== '' && $oid !== '0') {
        $triggerIds[$oid] = true;
    }
}

$triggerMeta = [];
if ($triggerIds !== []) {
    $triggers = zabbix_api_call('trigger.get', [
        'output' => ['triggerid', 'description', 'status'],
        'triggerids' => array_keys($triggerIds),
        'selectHosts' => ['name', 'status'],
    ], $error);
    if (is_array($triggers)) {
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $tid = (string)($trigger['triggerid'] ?? '');
            if ($tid === '') {
                continue;
            }
            $triggerMeta[$tid] = $trigger;
        }
    }

    $monitored = zabbix_api_call('trigger.get', [
        'output' => ['triggerid'],
        'triggerids' => array_keys($triggerIds),
        'monitored' => true,
        'skipDependent' => true,
        'filter' => ['status' => 0],
        'selectHosts' => ['status'],
    ], $error);
    $monitoredIds = [];
    if (is_array($monitored)) {
        foreach ($monitored as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $tid = (string)($trigger['triggerid'] ?? '');
            if ($tid === '') {
                continue;
            }
            $hosts = is_array($trigger['hosts'] ?? null) ? $trigger['hosts'] : [];
            $hostsOk = true;
            foreach ($hosts as $host) {
                if (is_array($host) && (string)($host['status'] ?? '0') === '1') {
                    $hostsOk = false;
                    break;
                }
            }
            if ($hostsOk) {
                $monitoredIds[$tid] = true;
            }
        }
    }
}

foreach ($unresolved as $problem) {
    if (!is_array($problem)) {
        continue;
    }
    $name = (string)($problem['name'] ?? '');
    if ($needle !== '' && stripos($name, $needle) === false) {
        continue;
    }
    $eid = (string)($problem['eventid'] ?? '');
    $oid = (string)($problem['objectid'] ?? '');
    $shown = false;
    foreach ($visible as $row) {
        if (is_array($row) && (string)($row['eventid'] ?? '') === $eid) {
            $shown = true;
            break;
        }
    }
    $status = $shown ? 'WALL' : 'HIDDEN';
    $reason = '';
    if (!$shown && $oid !== '' && isset($triggerMeta[$oid])) {
        $tr = $triggerMeta[$oid];
        $parts = [];
        if ((string)($tr['status'] ?? '0') === '1') {
            $parts[] = 'trigger disabled';
        }
        if (!isset($monitoredIds[$oid])) {
            $parts[] = 'not monitored (disabled item/host or dependent)';
        }
        $reason = $parts !== [] ? ' [' . implode(', ', $parts) . ']' : ' [filtered]';
    } elseif (!$shown) {
        $reason = ' [filtered]';
    }
    echo "{$status} event={$eid} trigger={$oid} sev=" . (int)($problem['severity'] ?? 0) . "{$reason}\n";
    echo "  {$name}\n";
}

echo "\n";

$data = zabbix_fetch_wall_data($page);
if (empty($data['ok'])) {
    echo 'Wall fetch failed: ' . (string)($data['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo 'Cached wall problems: ' . count($data['problems'] ?? []) . "\n";
echo 'Hosts: ' . count($data['hosts'] ?? []) . "\n";
echo "OK\n";
