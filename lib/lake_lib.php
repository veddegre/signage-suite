<?php
/**
 * Lake Michigan board — NDBC buoy fetch/parse + rotation seasonal skip.
 */

require_once __DIR__ . '/../config.php';

const LAKE_BUOY_ONLINE_MAX_AGE_MIN = 240;   // match lake.php display threshold (>4h = offline)
const LAKE_BUOY_SKIP_MIN_AGE_MIN = 1440;  // 24h offline → drop from rotation until back

function lake_ndbc_station(): string
{
    return (string)cfg('lake.NDBC_STATION', '45029');
}

function lake_nws_ua(): string
{
    return (string)cfg('lake.NWS_UA', 'HomeSignage/1.0 (contact: you@example.com)');
}

function lake_cache_dir(): string
{
    return SIGNAGE_ROOT . '/cache';
}

function lake_cache_ttl(): int
{
    return max(60, (int)cfg('lake.CACHE_TTL', 600));
}

function lake_cached_get(string $url, string $key, array $headers = []): ?string
{
    $dir = lake_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $f = $dir . '/' . $key . '.dat';
    if (is_file($f) && (time() - filemtime($f)) < lake_cache_ttl()) {
        return (string)file_get_contents($f);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => lake_nws_ua(),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($body !== false && $code === 200) {
        @file_put_contents($f, $body, LOCK_EX);

        return $body;
    }
    if (!isset($GLOBALS['diag']) || !is_array($GLOBALS['diag'])) {
        $GLOBALS['diag'] = [];
    }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";

    return is_file($f) ? (string)file_get_contents($f) : null;
}

/** @return array<string,mixed>|null Newest merged NDBC observation row */
function lake_parse_ndbc_obs(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $lines = preg_split('/\R/', trim($raw));
    if (count($lines) < 3) {
        return null;
    }

    $cols = preg_split('/\s+/', ltrim($lines[0], '# '));
    $want = ['WVHT', 'DPD', 'WTMP', 'ATMP', 'WSPD', 'GST', 'WDIR', 'PRES'];
    $vals = [];
    $vtimes = [];
    $newest = null;
    for ($i = 2; $i < min(count($lines), 40); $i++) {
        $parts = preg_split('/\s+/', trim($lines[$i]));
        if (count($parts) < count($cols)) {
            continue;
        }
        $row = array_combine($cols, array_slice($parts, 0, count($cols)));
        if (!is_array($row)) {
            continue;
        }
        $ts = gmmktime((int)$row['hh'], (int)$row['mm'], 0, (int)$row['MM'], (int)$row['DD'], (int)$row['YY']);
        if ($newest === null) {
            $newest = $ts;
        }
        if ($newest - $ts > 4 * 3600) {
            break;
        }
        foreach ($want as $f) {
            if (!isset($vals[$f]) && isset($row[$f]) && $row[$f] !== 'MM') {
                $vals[$f] = (float)$row[$f];
                $vtimes[$f] = $ts;
            }
        }
        if (count($vals) === count($want)) {
            break;
        }
    }
    if ($newest === null) {
        return null;
    }

    return [
        'time' => $newest,
        'wvht' => isset($vals['WVHT']) ? lake_m_to_ft($vals['WVHT']) : null,
        'wvht_time' => $vtimes['WVHT'] ?? null,
        'dpd' => $vals['DPD'] ?? null,
        'wtmp' => isset($vals['WTMP']) ? lake_c_to_f($vals['WTMP']) : null,
        'atmp' => isset($vals['ATMP']) ? lake_c_to_f($vals['ATMP']) : null,
        'wspd' => isset($vals['WSPD']) ? lake_ms_to_mph($vals['WSPD']) : null,
        'gst' => isset($vals['GST']) ? lake_ms_to_mph($vals['GST']) : null,
        'wdir' => $vals['WDIR'] ?? null,
        'pres' => isset($vals['PRES']) ? $vals['PRES'] * 0.02953 : null,
    ];
}

function lake_m_to_ft(float $m): float
{
    return $m * 3.28084;
}

function lake_ms_to_mph(float $m): float
{
    return $m * 2.23694;
}

function lake_c_to_f(float $c): float
{
    return $c * 9 / 5 + 32;
}

function lake_fetch_obs(?string $station = null): ?array
{
    $station = $station ?? lake_ndbc_station();
    $raw = lake_cached_get(
        'https://www.ndbc.noaa.gov/data/realtime2/' . $station . '.txt',
        'ndbc_' . $station
    );

    return lake_parse_ndbc_obs($raw);
}

/**
 * @return array{online:bool,obs_age_min:?int,skip_rotation:bool}
 */
function lake_buoy_status(?string $station = null): array
{
    static $cache = [];
    $station = $station ?? lake_ndbc_station();
    if (isset($cache[$station])) {
        return $cache[$station];
    }

    $obs = lake_fetch_obs($station);
    $obsAgeMin = $obs ? (int)round((time() - (int)$obs['time']) / 60) : null;
    $online = $obs && $obsAgeMin !== null && $obsAgeMin < LAKE_BUOY_ONLINE_MAX_AGE_MIN;
    $skipRotation = !$online
        && $obsAgeMin !== null
        && $obsAgeMin >= LAKE_BUOY_SKIP_MIN_AGE_MIN;

    return $cache[$station] = [
        'online' => $online,
        'obs_age_min' => $obsAgeMin,
        'skip_rotation' => $skipRotation,
    ];
}

function lake_buoy_skip_rotation(?string $station = null): bool
{
    return lake_buoy_status($station)['skip_rotation'];
}

/** Whether a rotation playlist URL targets lake.php. */
function rotation_page_url_is_lake(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strcasecmp($url, 'lake.php') === 0) {
        return true;
    }
    if (preg_match('~^lake\.php(?:[?#]|$)~i', $url) === 1) {
        return true;
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

    return preg_match('~(?:^|/)lake\.php$~i', $path) === 1;
}
