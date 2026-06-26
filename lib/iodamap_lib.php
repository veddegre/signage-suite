<?php
/**
 * IODA outage map — country connectivity disruptions.
 */

require_once __DIR__ . '/internet_lib.php';
require_once __DIR__ . '/attacks_lib.php';
require_once __DIR__ . '/dshieldmap_lib.php';

function iodamap_enabled(): bool
{
    if (cfg('iodamap.ENABLE_IODA', null) !== null) {
        return (bool)cfg('iodamap.ENABLE_IODA', true);
    }
    return internet_bgp_enabled();
}

function iodamap_lookback_days(): int
{
    $days = (int)cfg('iodamap.LOOKBACK_DAYS', 0);
    if ($days > 0) {
        return max(1, min(30, $days));
    }
    return internet_lookback_days();
}

function iodamap_max_sidebar(): int
{
    return max(4, min(12, (int)cfg('iodamap.MAX_SIDEBAR', 8)));
}

function iodamap_min_score(): float
{
    return max(0.0, (float)cfg('iodamap.MIN_SCORE', 3000));
}

/** @return array{from:int,until:int} */
function iodamap_time_window(): array
{
    $until = time();
    return ['from' => $until - iodamap_lookback_days() * 86400, 'until' => $until];
}

/** @param array<string,mixed> $bucket @param array<string,mixed> $event */
function iodamap_merge_event(array &$bucket, array $event, string $code, string $name): void
{
    $score = (float)($event['score'] ?? 0);
    $ongoing = !empty($event['overlaps_window']);
    $duration = max(0, (int)($event['duration'] ?? 0));
    $ds = trim((string)($event['datasource'] ?? ''));

    if ($score > ($bucket['score'] ?? 0)) {
        $bucket['score'] = $score;
        $bucket['datasource'] = internet_datasource_label($ds);
        $bucket['duration'] = $duration;
    }
    if ($ongoing) {
        $bucket['ongoing'] = true;
    }
    $bucket['events'] = (int)($bucket['events'] ?? 0) + 1;
    $bucket['code'] = $code;
    $bucket['name'] = $name;
}

/** @return list<array<string,mixed>> */
function iodamap_fetch_map(): array
{
    if (!iodamap_enabled()) {
        return [];
    }

    $window = iodamap_time_window();
    $base = [
        'from' => $window['from'],
        'until' => $window['until'],
        'entityType' => 'country',
        'limit' => 250,
    ];
    if (internet_us_only()) {
        $base['relatedTo'] = 'country/US';
    }

    /** @var array<string,array<string,mixed>> $byCode */
    $byCode = [];

    foreach (internet_ioda_data_list(internet_ioda_fetch('/outages/events', $base)) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $loc = (string)($row['location'] ?? '');
        if (!preg_match('#country/([A-Z]{2})#', $loc, $m)) {
            continue;
        }
        $code = $m[1];
        $name = trim((string)($row['location_name'] ?? '')) ?: attacks_country_name($code);
        if (!isset($byCode[$code])) {
            $byCode[$code] = ['score' => 0, 'events' => 0, 'ongoing' => false];
        }
        iodamap_merge_event($byCode[$code], $row, $code, $name);
    }

    foreach (internet_ioda_data_list(internet_ioda_fetch('/outages/alerts', $base)) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entity = $row['entity'] ?? null;
        if (!is_array($entity) || ($entity['type'] ?? '') !== 'country') {
            continue;
        }
        $code = strtoupper(trim((string)($entity['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $level = strtolower(trim((string)($row['level'] ?? '')));
        if ($level !== 'critical') {
            continue;
        }
        $name = trim((string)($entity['name'] ?? '')) ?: attacks_country_name($code);
        if (!isset($byCode[$code])) {
            $byCode[$code] = ['score' => 0, 'events' => 0, 'ongoing' => false];
        }
        $byCode[$code]['critical'] = true;
        $byCode[$code]['ongoing'] = true;
        $byCode[$code]['code'] = $code;
        $byCode[$code]['name'] = $name;
        $ds = trim((string)($row['datasource'] ?? ''));
        if ($ds !== '') {
            $byCode[$code]['datasource'] = internet_datasource_label($ds);
        }
    }

    $minScore = iodamap_min_score();
    $maxScore = 0.0;
    $rows = [];
    foreach ($byCode as $code => $bucket) {
        $score = (float)($bucket['score'] ?? 0);
        if ($score < $minScore && empty($bucket['critical'])) {
            continue;
        }
        $point = dshieldmap_country_point($code);
        if ($point === null) {
            continue;
        }
        $maxScore = max($maxScore, $score);
        $rows[] = [
            'code' => $code,
            'name' => (string)($bucket['name'] ?? attacks_country_name($code)),
            'score' => $score,
            'events' => (int)($bucket['events'] ?? 0),
            'ongoing' => !empty($bucket['ongoing']),
            'critical' => !empty($bucket['critical']),
            'datasource' => (string)($bucket['datasource'] ?? ''),
            'duration' => (int)($bucket['duration'] ?? 0),
            'lat' => $point['lat'],
            'lng' => $point['lng'],
        ];
    }

    if ($rows === []) {
        return [];
    }

    $logMax = log10($maxScore + 1);
    foreach ($rows as &$row) {
        $boost = !empty($row['ongoing']) ? 0.12 : 0;
        $row['intensity'] = min(1.0, ($logMax > 0 ? log10($row['score'] + 1) / $logMax : 0) + $boost);
    }
    unset($row);

    usort($rows, static function (array $a, array $b): int {
        if (($a['ongoing'] ?? false) !== ($b['ongoing'] ?? false)) {
            return ($b['ongoing'] ?? false) <=> ($a['ongoing'] ?? false);
        }
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    return $rows;
}
