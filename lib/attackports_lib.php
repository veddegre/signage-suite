<?php
/**
 * Top targeted ports — DShield treemap data.
 */

require_once __DIR__ . '/attacks_lib.php';

function attackports_enabled(): bool
{
    if (cfg('attackports.ENABLE_DSHIELD', null) !== null) {
        return (bool)cfg('attackports.ENABLE_DSHIELD', true);
    }
    return attacks_dshield_enabled();
}

function attackports_max_ports(): int
{
    return max(6, min(24, (int)cfg('attackports.MAX_PORTS', 16)));
}

/** @return list<array<string,mixed>> */
function attackports_fetch(): array
{
    if (!attackports_enabled()) {
        return [];
    }

    $limit = attackports_max_ports();
    $raw = attacks_fetch_top_ports();
    if ($raw === []) {
        return [];
    }

    $maxRecords = 0;
    $out = [];
    foreach (array_slice($raw, 0, $limit) as $i => $row) {
        $records = (int)($row['records'] ?? 0);
        if ($records <= 0) {
            continue;
        }
        $port = (int)($row['port'] ?? 0);
        $maxRecords = max($maxRecords, $records);
        $label = attacks_port_label($port);
        $out[] = [
            'rank' => (int)($row['rank'] ?? $i + 1),
            'port' => $port,
            'label' => $label,
            'name' => $label !== '' ? 'Port ' . $port . ' · ' . $label : 'Port ' . $port,
            'records' => $records,
            'targets' => (int)($row['targets'] ?? 0),
            'sources' => (int)($row['sources'] ?? 0),
        ];
    }

    if ($out === []) {
        return [];
    }

    $logMax = log10($maxRecords + 1);
    foreach ($out as &$row) {
        $row['intensity'] = $logMax > 0 ? log10($row['records'] + 1) / $logMax : 0;
    }
    unset($row);

    return $out;
}

function attackports_port_hue(int $port): int
{
    return match ($port) {
        22, 23 => 0,
        3389, 445, 139 => 25,
        25, 110, 143 => 45,
        53 => 195,
        80, 8080 => 210,
        443 => 175,
        default => 15,
    };
}
