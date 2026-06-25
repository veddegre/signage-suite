<?php
/**
 * Attack map — Cloudflare Radar L7 origin→target attack pairs.
 */

require_once __DIR__ . '/radar_lib.php';

/** @return array<string, array{lat:float,lng:float}> */
function attackmap_country_centroids(): array
{
    static $centroids = [
        'AD' => ['lat' => 42.55, 'lng' => 1.60], 'AE' => ['lat' => 24.00, 'lng' => 54.00],
        'AF' => ['lat' => 33.00, 'lng' => 65.00], 'AG' => ['lat' => 17.05, 'lng' => -61.80],
        'AL' => ['lat' => 41.15, 'lng' => 20.17], 'AM' => ['lat' => 40.07, 'lng' => 45.04],
        'AO' => ['lat' => -11.20, 'lng' => 17.87], 'AR' => ['lat' => -38.42, 'lng' => -63.62],
        'AT' => ['lat' => 47.52, 'lng' => 14.55], 'AU' => ['lat' => -25.27, 'lng' => 133.78],
        'AZ' => ['lat' => 40.14, 'lng' => 47.58], 'BA' => ['lat' => 43.92, 'lng' => 17.68],
        'BD' => ['lat' => 23.68, 'lng' => 90.36], 'BE' => ['lat' => 50.50, 'lng' => 4.47],
        'BF' => ['lat' => 12.24, 'lng' => -1.56], 'BG' => ['lat' => 42.73, 'lng' => 25.49],
        'BH' => ['lat' => 26.07, 'lng' => 50.56], 'BI' => ['lat' => -3.37, 'lng' => 29.92],
        'BJ' => ['lat' => 9.31, 'lng' => 2.32], 'BN' => ['lat' => 4.54, 'lng' => 114.73],
        'BO' => ['lat' => -16.29, 'lng' => -63.59], 'BR' => ['lat' => -14.24, 'lng' => -51.93],
        'BS' => ['lat' => 25.03, 'lng' => -77.40], 'BT' => ['lat' => 27.51, 'lng' => 90.43],
        'BW' => ['lat' => -22.33, 'lng' => 24.68], 'BY' => ['lat' => 53.71, 'lng' => 27.95],
        'BZ' => ['lat' => 17.19, 'lng' => -88.50], 'CA' => ['lat' => 56.13, 'lng' => -106.35],
        'CD' => ['lat' => -4.04, 'lng' => 21.76], 'CF' => ['lat' => 6.61, 'lng' => 20.94],
        'CG' => ['lat' => -0.23, 'lng' => 15.83], 'CH' => ['lat' => 46.82, 'lng' => 8.23],
        'CI' => ['lat' => 7.54, 'lng' => -5.55], 'CL' => ['lat' => -35.68, 'lng' => -71.54],
        'CM' => ['lat' => 7.37, 'lng' => 12.35], 'CN' => ['lat' => 35.86, 'lng' => 104.20],
        'CO' => ['lat' => 4.57, 'lng' => -74.30], 'CR' => ['lat' => 9.75, 'lng' => -83.75],
        'CU' => ['lat' => 21.52, 'lng' => -77.78], 'CY' => ['lat' => 35.13, 'lng' => 33.43],
        'CZ' => ['lat' => 49.82, 'lng' => 15.47], 'DE' => ['lat' => 51.17, 'lng' => 10.45],
        'DK' => ['lat' => 56.26, 'lng' => 9.50], 'DO' => ['lat' => 18.74, 'lng' => -70.16],
        'DZ' => ['lat' => 28.03, 'lng' => 1.66], 'EC' => ['lat' => -1.83, 'lng' => -78.18],
        'EE' => ['lat' => 58.60, 'lng' => 25.01], 'EG' => ['lat' => 26.82, 'lng' => 30.80],
        'ES' => ['lat' => 40.46, 'lng' => -3.75], 'ET' => ['lat' => 9.15, 'lng' => 40.49],
        'FI' => ['lat' => 61.92, 'lng' => 25.75], 'FR' => ['lat' => 46.23, 'lng' => 2.21],
        'GA' => ['lat' => -0.80, 'lng' => 11.61], 'GB' => ['lat' => 55.38, 'lng' => -3.44],
        'GE' => ['lat' => 42.32, 'lng' => 43.36], 'GH' => ['lat' => 7.95, 'lng' => -1.02],
        'GR' => ['lat' => 39.07, 'lng' => 21.82], 'GT' => ['lat' => 15.78, 'lng' => -90.23],
        'HK' => ['lat' => 22.40, 'lng' => 114.11], 'HN' => ['lat' => 15.20, 'lng' => -86.24],
        'HR' => ['lat' => 45.10, 'lng' => 15.20], 'HT' => ['lat' => 18.97, 'lng' => -72.29],
        'HU' => ['lat' => 47.16, 'lng' => 19.50], 'ID' => ['lat' => -0.79, 'lng' => 113.92],
        'IE' => ['lat' => 53.41, 'lng' => -8.24], 'IL' => ['lat' => 31.05, 'lng' => 34.85],
        'IN' => ['lat' => 20.59, 'lng' => 78.96], 'IQ' => ['lat' => 33.22, 'lng' => 43.68],
        'IR' => ['lat' => 32.43, 'lng' => 53.69], 'IS' => ['lat' => 64.96, 'lng' => -19.02],
        'IT' => ['lat' => 41.87, 'lng' => 12.57], 'JM' => ['lat' => 18.11, 'lng' => -77.30],
        'JO' => ['lat' => 30.59, 'lng' => 36.24], 'JP' => ['lat' => 36.20, 'lng' => 138.25],
        'KE' => ['lat' => -0.02, 'lng' => 37.91], 'KG' => ['lat' => 41.20, 'lng' => 74.77],
        'KH' => ['lat' => 12.57, 'lng' => 104.99], 'KR' => ['lat' => 35.91, 'lng' => 127.77],
        'KW' => ['lat' => 29.31, 'lng' => 47.48], 'KZ' => ['lat' => 48.02, 'lng' => 66.92],
        'LA' => ['lat' => 19.86, 'lng' => 102.50], 'LB' => ['lat' => 33.85, 'lng' => 35.86],
        'LK' => ['lat' => 7.87, 'lng' => 80.77], 'LT' => ['lat' => 55.17, 'lng' => 23.88],
        'LU' => ['lat' => 49.82, 'lng' => 6.13], 'LV' => ['lat' => 56.88, 'lng' => 24.60],
        'LY' => ['lat' => 26.34, 'lng' => 17.23], 'MA' => ['lat' => 31.79, 'lng' => -7.09],
        'MD' => ['lat' => 47.41, 'lng' => 28.37], 'ME' => ['lat' => 42.71, 'lng' => 19.37],
        'MG' => ['lat' => -18.77, 'lng' => 46.87], 'MK' => ['lat' => 41.51, 'lng' => 21.75],
        'ML' => ['lat' => 17.57, 'lng' => -4.00], 'MM' => ['lat' => 21.91, 'lng' => 95.96],
        'MN' => ['lat' => 46.86, 'lng' => 103.85], 'MO' => ['lat' => 22.20, 'lng' => 113.54],
        'MR' => ['lat' => 21.01, 'lng' => -10.94], 'MT' => ['lat' => 35.94, 'lng' => 14.38],
        'MU' => ['lat' => -20.35, 'lng' => 57.55], 'MV' => ['lat' => 3.20, 'lng' => 73.22],
        'MW' => ['lat' => -13.25, 'lng' => 34.30], 'MX' => ['lat' => 23.63, 'lng' => -102.55],
        'MY' => ['lat' => 4.21, 'lng' => 101.98], 'MZ' => ['lat' => -18.67, 'lng' => 35.53],
        'NA' => ['lat' => -22.96, 'lng' => 18.49], 'NE' => ['lat' => 17.61, 'lng' => 8.08],
        'NG' => ['lat' => 9.08, 'lng' => 8.68], 'NI' => ['lat' => 12.87, 'lng' => -85.21],
        'NL' => ['lat' => 52.13, 'lng' => 5.29], 'NO' => ['lat' => 60.47, 'lng' => 8.47],
        'NP' => ['lat' => 28.39, 'lng' => 84.12], 'NZ' => ['lat' => -40.90, 'lng' => 174.89],
        'OM' => ['lat' => 21.47, 'lng' => 55.98], 'PA' => ['lat' => 8.54, 'lng' => -80.78],
        'PE' => ['lat' => -9.19, 'lng' => -75.02], 'PH' => ['lat' => 12.88, 'lng' => 121.77],
        'PK' => ['lat' => 30.38, 'lng' => 69.35], 'PL' => ['lat' => 51.92, 'lng' => 19.15],
        'PR' => ['lat' => 18.22, 'lng' => -66.59], 'PS' => ['lat' => 31.95, 'lng' => 35.23],
        'PT' => ['lat' => 39.40, 'lng' => -8.22], 'PY' => ['lat' => -23.44, 'lng' => -58.44],
        'QA' => ['lat' => 25.35, 'lng' => 51.18], 'RO' => ['lat' => 45.94, 'lng' => 24.97],
        'RS' => ['lat' => 44.02, 'lng' => 21.01], 'RU' => ['lat' => 61.52, 'lng' => 105.32],
        'RW' => ['lat' => -1.94, 'lng' => 29.87], 'SA' => ['lat' => 23.89, 'lng' => 45.08],
        'SD' => ['lat' => 12.86, 'lng' => 30.22], 'SE' => ['lat' => 60.13, 'lng' => 18.64],
        'SG' => ['lat' => 1.35, 'lng' => 103.82], 'SI' => ['lat' => 46.15, 'lng' => 14.99],
        'SK' => ['lat' => 48.67, 'lng' => 19.70], 'SN' => ['lat' => 14.50, 'lng' => -14.45],
        'SO' => ['lat' => 5.15, 'lng' => 46.20], 'SR' => ['lat' => 3.92, 'lng' => -56.03],
        'SV' => ['lat' => 13.79, 'lng' => -88.90], 'SY' => ['lat' => 34.80, 'lng' => 38.99],
        'TH' => ['lat' => 15.87, 'lng' => 100.99], 'TJ' => ['lat' => 38.86, 'lng' => 71.28],
        'TM' => ['lat' => 38.97, 'lng' => 59.56], 'TN' => ['lat' => 33.89, 'lng' => 9.54],
        'TR' => ['lat' => 38.96, 'lng' => 35.24], 'TW' => ['lat' => 23.70, 'lng' => 120.96],
        'TZ' => ['lat' => -6.37, 'lng' => 34.89], 'UA' => ['lat' => 48.38, 'lng' => 31.17],
        'UG' => ['lat' => 1.37, 'lng' => 32.29], 'US' => ['lat' => 39.83, 'lng' => -98.58],
        'UY' => ['lat' => -32.52, 'lng' => -55.77], 'UZ' => ['lat' => 41.38, 'lng' => 64.59],
        'VE' => ['lat' => 6.42, 'lng' => -66.59], 'VN' => ['lat' => 14.06, 'lng' => 108.28],
        'YE' => ['lat' => 15.55, 'lng' => 48.52], 'ZA' => ['lat' => -30.56, 'lng' => 22.94],
        'ZM' => ['lat' => -13.13, 'lng' => 27.85], 'ZW' => ['lat' => -19.02, 'lng' => 29.15],
    ];
    return $centroids;
}

function attackmap_cache_ttl(): int
{
    return max(60, (int)cfg('attackmap.CACHE_TTL', 300));
}

function attackmap_date_range(): string
{
    $range = trim((string)cfg('attackmap.DATE_RANGE', ''));
    if ($range === '') {
        return radar_date_range();
    }
    return in_array($range, ['1d', '7d', '14d', '28d'], true) ? $range : '1d';
}

function attackmap_max_flows(): int
{
    return max(6, min(30, (int)cfg('attackmap.MAX_FLOWS', 18)));
}

function attackmap_user_agent(): string
{
    $ua = trim((string)cfg('attackmap.USER_AGENT', ''));
    return $ua !== '' ? $ua : radar_user_agent();
}

function attackmap_cf_token(): string
{
    $token = trim((string)cfg('attackmap.CF_API_TOKEN', ''));
    if ($token !== '') {
        return $token;
    }
    return radar_cf_token();
}

function attackmap_configured(): bool
{
    return attackmap_cf_token() !== '';
}

/** @return array{lat:float,lng:float}|null */
function attackmap_country_point(string $code): ?array
{
    $code = strtoupper(trim($code));
    $centroids = attackmap_country_centroids();
    if (!isset($centroids[$code])) {
        return null;
    }
    return $centroids[$code];
}

/** @return list<array<string,mixed>>|null */
function attackmap_fetch_raw_pairs(): ?array
{
    if (!attackmap_configured()) {
        return null;
    }

    $cacheKey = 'attackmap_l7_pairs_' . attackmap_date_range() . '_' . attackmap_max_flows();
    $cacheFile = RADAR_CACHE_DIR . '/' . $cacheKey . '.json';
    if (attackmap_cache_ttl() > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < attackmap_cache_ttl()) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $params = http_build_query([
        'limit' => attackmap_max_flows(),
        'dateRange' => attackmap_date_range(),
        'format' => 'json',
    ]);
    $url = RADAR_CF_BASE . '/radar/attacks/layer7/top/attacks?' . $params;
    $res = radar_http_get($url, [
        'Authorization: Bearer ' . attackmap_cf_token(),
        'User-Agent: ' . attackmap_user_agent(),
    ], 25);

    if ($res['body'] !== false && $res['code'] === 200) {
        $decoded = json_decode($res['body'], true);
        $top = $decoded['result']['top_0'] ?? null;
        if (is_array($top)) {
            @file_put_contents($cacheFile, json_encode($top, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $top;
        }
    }
    $GLOBALS['diag']['attackmap'] = $res['err'] !== '' ? 'curl: ' . $res['err'] : 'HTTP ' . $res['code'];

    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : null;
    }
    return null;
}

/** @return list<array<string,mixed>> */
function attackmap_fetch_flows(): array
{
    $raw = attackmap_fetch_raw_pairs();
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $oCode = strtoupper(trim((string)($row['originCountryAlpha2'] ?? '')));
        $tCode = strtoupper(trim((string)($row['targetCountryAlpha2'] ?? '')));
        $oPoint = attackmap_country_point($oCode);
        $tPoint = attackmap_country_point($tCode);
        if ($oPoint === null || $tPoint === null) {
            continue;
        }
        $percent = (float)($row['value'] ?? 0);
        $out[] = [
            'rank' => (int)($row['rank'] ?? $i + 1),
            'percent' => $percent,
            'origin' => [
                'code' => $oCode,
                'name' => trim((string)($row['originCountryName'] ?? '')) ?: radar_country_name($oCode),
                'lat' => $oPoint['lat'],
                'lng' => $oPoint['lng'],
            ],
            'target' => [
                'code' => $tCode,
                'name' => trim((string)($row['targetCountryName'] ?? '')) ?: radar_country_name($tCode),
                'lat' => $tPoint['lat'],
                'lng' => $tPoint['lng'],
            ],
        ];
    }
    return $out;
}
