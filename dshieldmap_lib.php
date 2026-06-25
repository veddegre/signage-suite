<?php
/**
 * DShield attack heatmap — country targets on a world map.
 */

require_once __DIR__ . '/attacks_lib.php';
require_once __DIR__ . '/attackmap_lib.php';

/** @return array<string, array{lat:float,lng:float}> */
function dshieldmap_extra_centroids(): array
{
    static $extra = [
        'AI' => ['lat' => 18.22, 'lng' => -63.07], 'AS' => ['lat' => -14.27, 'lng' => -170.13],
        'AW' => ['lat' => 12.52, 'lng' => -69.97], 'AX' => ['lat' => 60.18, 'lng' => 19.92],
        'BQ' => ['lat' => 12.18, 'lng' => -68.25], 'CK' => ['lat' => -21.23, 'lng' => -159.78],
        'CW' => ['lat' => 12.17, 'lng' => -68.99], 'FK' => ['lat' => -51.80, 'lng' => -59.52],
        'FO' => ['lat' => 61.89, 'lng' => -6.91], 'GF' => ['lat' => 3.93, 'lng' => -53.13],
        'GG' => ['lat' => 49.46, 'lng' => -2.58], 'GI' => ['lat' => 36.14, 'lng' => -5.35],
        'GL' => ['lat' => 71.71, 'lng' => -42.60], 'GP' => ['lat' => 16.27, 'lng' => -61.55],
        'GU' => ['lat' => 13.44, 'lng' => 144.79], 'IM' => ['lat' => 54.24, 'lng' => -4.55],
        'JE' => ['lat' => 49.21, 'lng' => -2.13], 'KY' => ['lat' => 19.31, 'lng' => -81.25],
        'MC' => ['lat' => 43.75, 'lng' => 7.41], 'MF' => ['lat' => 18.07, 'lng' => -63.05],
        'MQ' => ['lat' => 14.64, 'lng' => -61.02], 'NC' => ['lat' => -20.90, 'lng' => 165.62],
        'PF' => ['lat' => -17.68, 'lng' => -149.41], 'PM' => ['lat' => 46.94, 'lng' => -56.27],
        'PR' => ['lat' => 18.22, 'lng' => -66.59], 'RE' => ['lat' => -21.11, 'lng' => 55.54],
        'SC' => ['lat' => -4.68, 'lng' => 55.49], 'SX' => ['lat' => 18.03, 'lng' => -63.05],
        'TC' => ['lat' => 21.69, 'lng' => -71.80], 'VG' => ['lat' => 18.42, 'lng' => -64.64],
        'VI' => ['lat' => 18.34, 'lng' => -64.90], 'XK' => ['lat' => 42.60, 'lng' => 20.90],
    ];
    return $extra;
}

/** @return array{lat:float,lng:float}|null */
function dshieldmap_country_point(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '' || $code === 'XX') {
        return null;
    }
    $point = attackmap_country_point($code);
    if ($point !== null) {
        return $point;
    }
    $extra = dshieldmap_extra_centroids();
    return $extra[$code] ?? null;
}

function dshieldmap_enabled(): bool
{
    if (cfg('dshieldmap.ENABLE_DSHIELD', null) !== null) {
        return (bool)cfg('dshieldmap.ENABLE_DSHIELD', true);
    }
    return attacks_dshield_enabled();
}

function dshieldmap_min_targets(): int
{
    return max(0, (int)cfg('dshieldmap.MIN_TARGETS', 100));
}

function dshieldmap_max_sidebar(): int
{
    return max(4, min(12, (int)cfg('dshieldmap.MAX_SIDEBAR', 8)));
}

/** @return list<array<string,mixed>> */
function dshieldmap_fetch_heatmap(): array
{
    if (!dshieldmap_enabled()) {
        return [];
    }

    $countries = attacks_fetch_countries();
    if ($countries === []) {
        return [];
    }

    $minTargets = dshieldmap_min_targets();
    $maxTargets = 0;
    $rows = [];

    foreach ($countries as $row) {
        $targets = (int)($row['targets'] ?? 0);
        if ($targets < $minTargets) {
            continue;
        }
        $code = (string)($row['code'] ?? '');
        $point = dshieldmap_country_point($code);
        if ($point === null) {
            continue;
        }
        $maxTargets = max($maxTargets, $targets);
        $rows[] = [
            'code' => $code,
            'name' => (string)($row['name'] ?? attacks_country_name($code)),
            'targets' => $targets,
            'reports' => (int)($row['reports'] ?? 0),
            'sources' => (int)($row['sources'] ?? 0),
            'lat' => $point['lat'],
            'lng' => $point['lng'],
        ];
    }

    if ($rows === []) {
        return [];
    }

    $logMax = log10($maxTargets + 1);
    foreach ($rows as &$row) {
        $row['intensity'] = $logMax > 0 ? log10($row['targets'] + 1) / $logMax : 0;
    }
    unset($row);

    usort($rows, static fn($a, $b) => $b['targets'] <=> $a['targets']);
    return $rows;
}
