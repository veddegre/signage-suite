#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/zabbix_lib.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL  $msg\n";
}

function problem(string $name, string $opdata = ''): array
{
    $row = ['name' => $name];
    if ($opdata !== '') {
        $row['opdata'] = $opdata;
    }

    return $row;
}

$noise = [
    problem('Linux: New updates are available'),
    problem('Security updates are available'),
    problem('Software updates available on {HOST.NAME}'),
    problem('Windows updates are available'),
    problem('Package updates pending'),
    problem('yum: updates available'),
    problem('apt updates outstanding'),
    problem('Patches are pending'),
];

foreach ($noise as $row) {
    expect(zabbix_problem_is_update_noise($row), 'noise: ' . $row['name']);
}

$real = [
    problem('Docker: Container /signaltrace: Container has been stopped with error code'),
    problem('High CPU utilization'),
    problem('Service update failed on host db01'),
    problem('Zabbix agent is not available'),
    problem('Disk space is low'),
];

foreach ($real as $row) {
    expect(!zabbix_problem_is_update_noise($row), 'real alert: ' . $row['name']);
}

$mixed = [
    problem('Security updates are available'),
    problem('High CPU utilization'),
];
$filtered = zabbix_filter_update_problems($mixed, true);
expect(count($filtered) === 1, 'filter removes only update noise');
expect((string)($filtered[0]['name'] ?? '') === 'High CPU utilization', 'filter keeps real alert');

exit($fail > 0 ? 1 : 0);
