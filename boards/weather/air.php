<?php
/**
 * AIR & POLLEN — 1920×1080 signage
 * US AQI, PM2.5/PM10, ozone, and pollen levels for your location.
 *
 * Data: EPA AirNow observations when an API key is set (ground monitors, per-pollutant AQI).
 *   Fallback: Open-Meteo per-pollutant US AQI (CAMS model — can lag smoke events).
 * Pollen: Google Pollen API for the US (optional key in admin); Open-Meteo pollen is Europe-only.
 *   Local nudge: NWS hourly weather can nudge Google UPI (rain/humidity/wind) like echoweather.
 *   https://open-meteo.com/en/docs/air-quality-api
 *
 * Configure lat/lon and place name in admin.php → Air & Pollen.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';
require_once dirname(__DIR__, 2) . '/lib/air_aqi_lib.php';
require_once dirname(__DIR__, 2) . '/lib/air_pollen_lib.php';

$SCREEN = signage_request_screen();
$LOC = rotation_screen_location($SCREEN);

define('TITLE', cfg('air.TITLE', 'Air & Pollen'));
define('PLACE', $LOC['place']);
define('LAT', $LOC['lat']);
define('LON', $LOC['lon']);
define('TIMEZONE', cfg('air.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('air.RELOAD_SEC', 3600));
define('GOOGLE_POLLEN_API_KEY', cfg('air.GOOGLE_POLLEN_API_KEY', ''));
define('AIRNOW_API_KEY', cfg('air.AIRNOW_API_KEY', ''));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('air.CACHE_TTL', 3600));
define('NWS_CACHE_TTL', max(60, (int)cfg('air.NWS_CACHE_TTL', 300)));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function air_cache_is_fresh(string $cacheFile, int $ttl): bool
{
    return is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl;
}

function air_read_cache_file(string $cacheFile): ?array
{
    if (!is_file($cacheFile)) {
        return null;
    }
    $d = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($d) || isset($d['WebServiceError'])) {
        return null;
    }

    return $d;
}

function air_fetch_and_cache_json(string $url, string $cacheFile, string $diagKey): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'HomeSignage/AirBoard/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json, application/geo+json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (!is_array($d)) {
            $GLOBALS['diag'][$diagKey] = 'invalid JSON';
        } elseif (isset($d['WebServiceError'])) {
            $msg = '';
            foreach ((array)($d['WebServiceError'] ?? []) as $row) {
                if (is_array($row) && ($row['Message'] ?? '') !== '') {
                    $msg = (string)$row['Message'];
                    break;
                }
            }
            $GLOBALS['diag'][$diagKey] = $msg !== '' ? $msg : 'AirNow API error';
            @unlink($cacheFile);
        } else {
            @file_put_contents($cacheFile, $body, LOCK_EX);

            return $d;
        }
    } else {
        $GLOBALS['diag'][$diagKey] = $err !== '' ? "curl: $err" : "HTTP $code";
        if ($code === 401 || $code === 403) {
            @unlink($cacheFile);
        }
    }

    return air_read_cache_file($cacheFile);
}

/**
 * Cached JSON fetch with single-flight lock — one upstream request per cache key when TTL expires.
 */
function air_cached_json(string $url, string $key, string $diagKey = 'openmeteo', ?int $ttl = null): ?array
{
    $ttl = $ttl ?? CACHE_TTL;
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $cacheFile = CACHE_DIR . '/' . $key . '.json';
    if (air_cache_is_fresh($cacheFile, $ttl)) {
        $d = air_read_cache_file($cacheFile);
        if ($d !== null) {
            return $d;
        }
    }

    $lockFile = $cacheFile . '.lock';
    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp === false) {
        return air_fetch_and_cache_json($url, $cacheFile, $diagKey);
    }

    $gotLock = flock($lockFp, LOCK_EX | LOCK_NB);
    if (!$gotLock) {
        flock($lockFp, LOCK_EX);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        if (air_cache_is_fresh($cacheFile, $ttl)) {
            $d = air_read_cache_file($cacheFile);
            if ($d !== null) {
                return $d;
            }
        }

        return air_read_cache_file($cacheFile);
    }

    try {
        if (air_cache_is_fresh($cacheFile, $ttl)) {
            $d = air_read_cache_file($cacheFile);
            if ($d !== null) {
                return $d;
            }
        }

        return air_fetch_and_cache_json($url, $cacheFile, $diagKey);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/** EPA US AQI band → label, accent color, short advice. */
function air_aqi_band(?int $aqi): array
{
    if ($aqi === null) {
        return ['—', 'var(--mist)', 'Air quality data unavailable'];
    }
    if ($aqi <= 50) {
        return ['Good', '#39c46d', 'Air is clean — open windows freely'];
    }
    if ($aqi <= 100) {
        return ['Moderate', 'var(--beacon)', 'Acceptable for most — unusually sensitive people take it easy'];
    }
    if ($aqi <= 150) {
        return ['Sensitive', '#ff9d4d', 'Unhealthy for sensitive groups — limit prolonged outdoor exertion'];
    }
    if ($aqi <= 200) {
        return ['Unhealthy', '#ff5d5d', 'Everyone may feel effects — keep windows closed'];
    }
    if ($aqi <= 300) {
        return ['Very unhealthy', '#c850ff', 'Health alert — minimize outdoor time'];
    }

    return ['Hazardous', '#7a001a', 'Emergency conditions — stay indoors'];
}

/**
 * Per-pollutant US AQI from an Open-Meteo current payload.
 *
 * @return array{pm25:?int,pm10:?int,ozone:?int,no2:?int}
 */
function air_openmeteo_pollutant_aqis(array $current): array
{
    $map = [
        'pm25' => 'us_aqi_pm2_5',
        'pm10' => 'us_aqi_pm10',
        'ozone' => 'us_aqi_ozone',
        'no2' => 'us_aqi_nitrogen_dioxide',
    ];
    $out = [];
    foreach ($map as $key => $field) {
        $out[$key] = isset($current[$field]) && $current[$field] !== null && $current[$field] !== ''
            ? (int)round((float)$current[$field])
            : null;
    }

    return $out;
}

/** @param array<int|string,mixed>|null $rows */
function air_parse_airnow(?array $rows): ?array
{
    if ($rows === null || $rows === []) {
        return null;
    }
    // AirNow returns a JSON array; error payloads are associative objects handled upstream.
    if (!array_is_list($rows)) {
        return null;
    }
    $paramKeys = [
        'PM2.5' => 'pm25',
        'PM10' => 'pm10',
        'O3' => 'ozone',
        'NO2' => 'no2',
    ];
    $pollutants = [];
    $reportingArea = '';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $param = (string)($row['ParameterName'] ?? '');
        if ($param === '' || !isset($row['AQI'])) {
            continue;
        }
        $key = $paramKeys[$param] ?? null;
        if ($key === null) {
            continue;
        }
        $category = $row['Category'] ?? [];
        $pollutants[$key] = [
            'aqi' => (int)$row['AQI'],
            'category' => is_array($category) ? (string)($category['Name'] ?? '') : '',
            'param' => $param,
        ];
        if ($reportingArea === '') {
            $reportingArea = (string)($row['ReportingArea'] ?? '');
        }
    }
    if ($pollutants === []) {
        return null;
    }
    $overall = max(array_map(static fn(array $p): int => $p['aqi'], $pollutants));

    return [
        'pollutants' => $pollutants,
        'overall' => $overall,
        'reporting_area' => $reportingArea,
    ];
}

/**
 * @param array{pm25:?int,pm10:?int,ozone:?int,no2:?int} $pollutantAqis
 * @return list<array{lab:string,aqi:?int,color:string,sub:string}>
 */
function air_pollutant_stat_rows(
    string $aqSource,
    array $pollutantAqis,
    ?array $airnow,
    ?float $pm25,
    ?float $pm10,
    ?float $ozoneUg,
    ?float $no2Ug
): array {
    $defs = [
        ['key' => 'pm25', 'lab' => 'PM2.5', 'ug' => $pm25],
        ['key' => 'pm10', 'lab' => 'PM10', 'ug' => $pm10],
        ['key' => 'ozone', 'lab' => 'Ozone', 'ug' => $ozoneUg],
        ['key' => 'no2', 'lab' => 'NO₂', 'ug' => $no2Ug],
    ];
    $rows = [];
    foreach ($defs as $d) {
        $key = (string)$d['key'];
        $aqi = $pollutantAqis[$key] ?? null;
        if ($aqSource === 'airnow' && $aqi === null) {
            continue;
        }
        [, $color] = air_aqi_band($aqi);
        $sub = '';
        if ($aqSource === 'airnow' && is_array($airnow)) {
            $sub = trim((string)($airnow['pollutants'][$key]['category'] ?? ''));
        } elseif ($d['ug'] !== null) {
            $sub = ($key === 'ozone' ? (string)(int)$d['ug'] : (string)$d['ug']) . ' µg/m³';
        }
        $rows[] = [
            'lab' => (string)$d['lab'],
            'aqi' => $aqi,
            'color' => $color,
            'sub' => $sub,
        ];
    }

    return $rows;
}

/** @param list<array{event:string,headline:string,severity:string,description?:string}> $alerts */
function air_nws_alert_labels(array $alerts): array
{
    $out = [];
    $seen = [];
    foreach ($alerts as $a) {
        $label = trim((string)($a['event'] ?? 'Alert'));
        if ($label === '' || isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;
        $out[] = $label;
    }

    return $out;
}

function air_fetch_airnow(): ?array
{
    $key = trim((string)AIRNOW_API_KEY);
    if ($key === '') {
        return null;
    }
    $cacheKey = 'airnow_v2_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $url = 'https://www.airnowapi.org/aq/observation/latLong/current/?' . http_build_query([
        'format' => 'application/json',
        'latitude' => LAT,
        'longitude' => LON,
        'distance' => 100,
        'API_KEY' => $key,
    ]);
    $raw = air_cached_json($url, $cacheKey, 'airnow');
    $parsed = air_parse_airnow($raw);
    if ($parsed === null && $raw !== null && $raw !== []) {
        $GLOBALS['diag']['airnow'] = 'Unexpected AirNow payload';
    } elseif ($parsed === null && ($raw === null || $raw === []) && !isset($GLOBALS['diag']['airnow'])) {
        $GLOBALS['diag']['airnow'] = 'No EPA monitor readings within 100 mi';
    }

    return $parsed;
}

/** Max hourly US AQI across pollutant indices for one calendar day. */
function air_day_max_combined_aqi(array $hourly, string $dayKey): ?int
{
    $fields = ['us_aqi', 'us_aqi_pm2_5', 'us_aqi_pm10', 'us_aqi_ozone', 'us_aqi_nitrogen_dioxide'];
    $max = null;
    foreach ($fields as $field) {
        $v = air_day_max($hourly, $field, $dayKey);
        if ($v !== null) {
            $max = max($max ?? 0, (int)round($v));
        }
    }

    return $max;
}

/** @return list<array{event:string,headline:string,severity:string}> */
function air_fetch_nws_alerts(): array
{
    $cacheKey = 'nws_air_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $raw = air_cached_json(
        sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', LAT, LON),
        $cacheKey,
        'nws',
        NWS_CACHE_TTL
    );
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach (($raw['features'] ?? []) as $feat) {
        if (!is_array($feat)) {
            continue;
        }
        $p = $feat['properties'] ?? [];
        if (!is_array($p)) {
            continue;
        }
        $out[] = [
            'event' => (string)($p['event'] ?? ''),
            'headline' => (string)($p['headline'] ?? ''),
            'severity' => (string)($p['severity'] ?? 'Moderate'),
            'description' => (string)($p['description'] ?? ''),
        ];
    }

    return $out;
}

/** Pollen grains/m³ → label and color. */
function air_pollen_band(?float $grains): array
{
    if ($grains === null) return ['—', 'var(--mist)'];
    if ($grains < 10)  return ['Low', '#39c46d'];
    if ($grains < 50)  return ['Moderate', 'var(--beacon)'];
    if ($grains < 200) return ['High', '#ff9d4d'];
    return ['Very high', '#ff5d5d'];
}

/** @return array{0:?int,1:string,2:bool} UPI value, category label, whether index data is present */
function air_google_pollen_index(?array $indexInfo): array
{
    $upi = null;
    if (isset($indexInfo['value']) && $indexInfo['value'] !== null && $indexInfo['value'] !== '') {
        $upi = (int)$indexInfo['value'];
    }
    $category = trim((string)($indexInfo['category'] ?? ''));
    $hasIndex = $upi !== null
        || ($category !== '' && !preg_match('/off\s*season/i', $category));
    return [$upi, $category, $hasIndex];
}

/** Match Google dailyInfo[] to a local calendar day (Y-m-d). */
function air_google_day_index(?array $data, string $dayKey): int
{
    if (!is_array($data)) {
        return -1;
    }
    foreach ($data['dailyInfo'] ?? [] as $i => $day) {
        if (!is_array($day)) {
            continue;
        }
        $date = $day['date'] ?? null;
        if (!is_array($date)) {
            continue;
        }
        $y = (int)($date['year'] ?? 0);
        $m = (int)($date['month'] ?? 0);
        $d = (int)($date['day'] ?? 0);
        if ($y > 0 && $m > 0 && $d > 0 && sprintf('%04d-%02d-%02d', $y, $m, $d) === $dayKey) {
            return (int)$i;
        }
    }

    return -1;
}

function air_google_pollen_response_valid(?array $data): bool
{
    return is_array($data)
        && isset($data['dailyInfo'])
        && is_array($data['dailyInfo'])
        && $data['dailyInfo'] !== [];
}

/** Best UPI for a pollen type from plantInfo (GRASS / TREE / WEED). */
function air_google_plant_type_index(?array $day, string $pollenTypeCode): array
{
    $bestUpi = null;
    $bestCategory = '';
    foreach ($day['plantInfo'] ?? [] as $plant) {
        if (!is_array($plant)) {
            continue;
        }
        $desc = is_array($plant['plantDescription'] ?? null) ? $plant['plantDescription'] : [];
        $plantType = strtoupper((string)($desc['type'] ?? ''));
        if ($plantType !== $pollenTypeCode) {
            continue;
        }
        [$upi, $category, $hasIndex] = air_google_pollen_index($plant['indexInfo'] ?? null);
        if (!$hasIndex) {
            continue;
        }
        $score = $upi ?? 0;
        if ($bestUpi === null || $score > $bestUpi) {
            $bestUpi = $upi ?? 0;
            $bestCategory = $category;
        }
    }

    return [$bestUpi, $bestCategory, $bestUpi !== null];
}

function air_pollen_rows_sort(array $rows): array
{
    usort($rows, function ($a, $b) {
        $av = $a['val'];
        $bv = $b['val'];
        if ($av === null && $bv === null) return 0;
        if ($av === null) return 1;
        if ($bv === null) return -1;
        return $bv <=> $av;
    });
    return $rows;
}

/** Highest active pollen row for forecast summaries (skips off-season nulls). */
function air_pollen_top_row(array $rows): array
{
    foreach ($rows as $row) {
        if ($row['val'] !== null) {
            return $row;
        }
    }
    return $rows[0] ?? ['name' => '—', 'label' => '—'];
}

function air_openmeteo_has_pollen(array $hourly): bool
{
    foreach (['grass_pollen', 'ragweed_pollen', 'birch_pollen', 'alder_pollen'] as $field) {
        foreach ($hourly[$field] ?? [] as $v) {
            if ($v !== null) return true;
        }
    }
    return false;
}

function air_day_key(string $isoTime): string
{
    return substr($isoTime, 0, 10);
}

/** Max value for one hourly series on a given calendar day. */
function air_day_max(array $hourly, string $field, string $dayKey): ?float
{
    $times = $hourly['time'] ?? [];
    $vals = $hourly[$field] ?? [];
    $max = null;
    foreach ($times as $i => $t) {
        if (air_day_key((string)$t) !== $dayKey) continue;
        if (!isset($vals[$i]) || $vals[$i] === null) continue;
        $max = max($max ?? (float)$vals[$i], (float)$vals[$i]);
    }
    return $max;
}

/** Distinct forecast days present in hourly data (sorted). */
function air_forecast_days(array $hourly, int $limit = 3): array
{
    $days = [];
    foreach ($hourly['time'] ?? [] as $t) {
        $d = air_day_key((string)$t);
        $days[$d] = true;
    }
    $keys = array_keys($days);
    sort($keys);
    return array_slice($keys, 0, $limit);
}

function air_pollen_rows_openmeteo(array $hourly, string $dayKey): array
{
    $tree = max(
        air_day_max($hourly, 'birch_pollen', $dayKey) ?? 0,
        air_day_max($hourly, 'alder_pollen', $dayKey) ?? 0
    );
    $types = [
        ['name' => 'Grass', 'val' => air_day_max($hourly, 'grass_pollen', $dayKey), 'unit' => 'grains'],
        ['name' => 'Ragweed', 'val' => air_day_max($hourly, 'ragweed_pollen', $dayKey), 'unit' => 'grains'],
        ['name' => 'Tree', 'val' => $tree > 0 ? $tree : null, 'unit' => 'grains'],
    ];
    return air_pollen_rows_sort($types);
}

/** @return list<array{name:string,val:?float,unit:string,label:string,color:string}> */
function air_pollen_rows_google(?array $data, int $dayIndex, string $dayKey = ''): array
{
    if (!$data || !air_google_pollen_response_valid($data)) {
        return [];
    }
    if ($dayKey !== '') {
        $matched = air_google_day_index($data, $dayKey);
        if ($matched >= 0) {
            $dayIndex = $matched;
        }
    }
    $day = $data['dailyInfo'][$dayIndex] ?? null;
    if (!$day || !is_array($day)) {
        return [];
    }
    $labels = ['GRASS' => 'Grass', 'WEED' => 'Weed', 'TREE' => 'Tree'];
    $byCode = [];
    foreach ($day['pollenTypeInfo'] ?? [] as $pt) {
        if (!is_array($pt)) {
            continue;
        }
        $code = strtoupper((string)($pt['code'] ?? ''));
        if ($code !== '') {
            $byCode[$code] = $pt;
        }
    }
    $rows = [];
    foreach ($labels as $code => $displayName) {
        $pt = $byCode[$code] ?? null;
        $upi = null;
        $category = '';
        $hasIndex = false;
        $typeInSeason = is_array($pt) && array_key_exists('inSeason', $pt)
            ? !empty($pt['inSeason'])
            : null;
        if (is_array($pt)) {
            [$upi, $category, $hasIndex] = air_google_pollen_index($pt['indexInfo'] ?? null);
        }
        if (!$hasIndex) {
            [$plantUpi, $plantCat, $plantHas] = air_google_plant_type_index($day, $code);
            if ($plantHas) {
                $upi = $plantUpi;
                $category = $plantCat;
                $hasIndex = true;
            }
        }
        // Google often omits indexInfo at the type level while still listing the
        // type as in season (or a plant still has a Very Low UPI). That is "None",
        // not off-season — Midwest grass routinely continues into August.
        if (!$hasIndex && $typeInSeason === true) {
            $upi = 0;
            $category = 'None';
            $hasIndex = true;
        }
        // Google's grass inSeason flag often drops in midsummer for the Great
        // Lakes while residual grass pollen is still a thing. Don't call that
        // off-season during May–September.
        if (!$hasIndex && $code === 'GRASS') {
            $month = 0;
            if ($dayKey !== '' && preg_match('/^\d{4}-(\d{2})-/', $dayKey, $dm)) {
                $month = (int)$dm[1];
            } elseif (is_array($day['date'] ?? null)) {
                $month = (int)($day['date']['month'] ?? 0);
            }
            if ($month >= 5 && $month <= 9) {
                $upi = 0;
                $category = 'None';
                $hasIndex = true;
            }
        }
        if (!$hasIndex) {
            $rows[] = [
                'name' => $displayName,
                'val' => null,
                'unit' => 'upi',
                'label' => 'Off season',
                'color' => 'var(--mist)',
            ];
            continue;
        }
        [$label, $color] = air_google_pollen_row_label($upi, $category);
        $rows[] = [
            'name' => $displayName,
            'val' => $upi !== null ? (float)$upi : null,
            'unit' => 'upi',
            'label' => $label,
            'color' => $color,
        ];
    }

    return air_pollen_rows_sort($rows);
}

function air_fetch_google_pollen(): ?array
{
    $key = trim((string)GOOGLE_POLLEN_API_KEY);
    if ($key === '') return null;
    $cacheKey = 'google_pollen_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $url = 'https://pollen.googleapis.com/v1/forecast:lookup?' . http_build_query([
        'key' => $key,
        'location.latitude' => LAT,
        'location.longitude' => LON,
        'days' => 3,
    ]);
    return air_cached_json($url, $cacheKey, 'google_pollen');
}

/** @return list<array<string,mixed>> NWS hourly forecast periods for pollen weather nudge. */
function air_fetch_nws_hourly_for_pollen(): array
{
    $cacheKey = 'nws_hourly_pollen_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $points = air_cached_json(
        sprintf('https://api.weather.gov/points/%.4F,%.4F', LAT, LON),
        $cacheKey . '_pts',
        'nws_hourly',
        max(NWS_CACHE_TTL, 3600)
    );
    $hourlyUrl = is_array($points) ? ($points['properties']['forecastHourly'] ?? null) : null;
    if (!is_string($hourlyUrl) || $hourlyUrl === '') {
        return [];
    }
    $hourly = air_cached_json($hourlyUrl, $cacheKey, 'nws_hourly', max(NWS_CACHE_TTL, 3600));
    $periods = is_array($hourly) ? ($hourly['properties']['periods'] ?? null) : null;

    return is_array($periods) ? $periods : [];
}

function air_pollen_rows_for_day(string $source, array $hourly, ?array $google, int $dayIndex, string $dayKey, float $weatherDelta = 0.0): array
{
    if ($source === 'google') {
        $rows = air_pollen_rows_google($google, $dayIndex, $dayKey);

        return air_pollen_apply_weather_delta($rows, $weatherDelta);
    }
    if ($source === 'openmeteo') {
        return air_pollen_rows_openmeteo($hourly, $dayKey);
    }
    return [
        ['name' => 'Grass', 'val' => null, 'unit' => 'none', 'label' => '—', 'color' => 'var(--mist)'],
        ['name' => 'Weed', 'val' => null, 'unit' => 'none', 'label' => '—', 'color' => 'var(--mist)'],
        ['name' => 'Tree', 'val' => null, 'unit' => 'none', 'label' => '—', 'color' => 'var(--mist)'],
    ];
}

function air_pollen_max_score(array $rows, string $source): float
{
    $max = 0.0;
    foreach ($rows as $p) {
        if ($p['val'] === null) continue;
        if ($source === 'google') {
            $max = max($max, (float)$p['val']); // UPI 1–5
        } else {
            $max = max($max, (float)$p['val']); // grains/m³
        }
    }
    return $max;
}

function air_verdict(
    ?int $effectiveAqi,
    ?int $modelAqi,
    array $pollenRows,
    string $pollenSource,
    array $nwsAlerts
): array {
    if ($nwsAlerts !== []) {
        $eventLabel = (string)($nwsAlerts[0]['event'] ?? 'Air quality alert');
        if ($effectiveAqi !== null && $effectiveAqi > 150) {
            return ['Keep windows closed', $eventLabel . ' — poor air quality', '#ff5d5d'];
        }

        return [
            'Air quality alert',
            $eventLabel . ($modelAqi !== null && $modelAqi <= 100
                ? ' — NWS alert active; do not trust a moderate model reading'
                : ' — limit outdoor time'),
            '#ff9d4d',
        ];
    }

    $maxScore = air_pollen_max_score($pollenRows, $pollenSource);
    $aqiBad = $effectiveAqi !== null && $effectiveAqi > 100;
    $aqiWarn = $effectiveAqi !== null && $effectiveAqi > 50;
    if ($pollenSource === 'google') {
        $pollenBad = $maxScore >= 4;
        $pollenWarn = $maxScore >= 3;
    } elseif ($pollenSource === 'openmeteo') {
        $pollenBad = $maxScore >= 50;
        $pollenWarn = $maxScore >= 10;
    } else {
        $pollenBad = $pollenWarn = false;
    }

    if ($effectiveAqi !== null && $effectiveAqi > 150) {
        return ['Keep windows closed', 'Poor air quality — limit time outside', '#ff5d5d'];
    }
    if ($pollenSource === 'google' && $maxScore >= 5) {
        return ['High pollen', 'Close windows — allergy sufferers stay indoors', '#ff5d5d'];
    }
    if ($pollenSource === 'openmeteo' && $maxScore >= 200) {
        return ['High pollen', 'Close windows — allergy sufferers stay indoors', '#ff5d5d'];
    }
    if ($aqiBad || $pollenBad) {
        return ['Take it easy outdoors', 'Elevated air or pollen — sensitive groups use caution', 'var(--beacon)'];
    }
    if ($aqiWarn || $pollenWarn) {
        return ['Mostly fine', 'OK for most people — watch symptoms if you are sensitive', 'var(--beacon)'];
    }
    if ($effectiveAqi !== null) {
        if ($pollenSource === 'none') {
            return ['Fresh air day', 'Good air quality — add Google Pollen key for allergy outlook', '#39c46d'];
        }

        return ['Fresh air day', 'Good air and low pollen — open windows, enjoy outside', '#39c46d'];
    }

    return ['—', 'Forecast unavailable', 'var(--mist)'];
}

// ── Fetch air quality + pollen ─────────────────────────────────────────────────
$cacheKey = 'openmeteo_air_v2_' . md5(sprintf('%.4F_%.4F_%s', LAT, LON, TIMEZONE));
$query = http_build_query([
    'latitude' => LAT,
    'longitude' => LON,
    'timezone' => TIMEZONE,
    'forecast_days' => 3,
    'current' => 'us_aqi,us_aqi_pm2_5,us_aqi_pm10,us_aqi_ozone,us_aqi_nitrogen_dioxide,pm2_5,pm10,ozone,nitrogen_dioxide,aerosol_optical_depth',
    'hourly' => 'us_aqi,us_aqi_pm2_5,us_aqi_pm10,us_aqi_ozone,us_aqi_nitrogen_dioxide,pm2_5,aerosol_optical_depth,ragweed_pollen,grass_pollen,birch_pollen,alder_pollen',
]);
$data = air_cached_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $query, $cacheKey);

$airnow = air_fetch_airnow();
$airnowKeySet = trim((string)AIRNOW_API_KEY) !== '';
$airnowError = $airnowKeySet && $airnow === null ? (string)($GLOBALS['diag']['airnow'] ?? 'unavailable') : '';
$nwsAlerts = air_nws_aq_alerts(air_fetch_nws_alerts());

$current = is_array($data['current'] ?? null) ? $data['current'] : [];
$hourly  = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
$hasData = $airnow !== null || $current !== [] || $hourly !== [];

$aqiModel = isset($current['us_aqi']) ? (int)round((float)$current['us_aqi']) : null;
$pm25 = isset($current['pm2_5']) ? round((float)$current['pm2_5'], 1) : null;
$pm10 = isset($current['pm10']) ? round((float)$current['pm10'], 1) : null;
$ozoneUg = isset($current['ozone']) ? round((float)$current['ozone'], 0) : null;
$no2Ug = isset($current['nitrogen_dioxide']) ? round((float)$current['nitrogen_dioxide'], 1) : null;
$aod = isset($current['aerosol_optical_depth']) ? round((float)$current['aerosol_optical_depth'], 2) : null;
if ($aod === null && $hourly !== []) {
    foreach (array_reverse($hourly['aerosol_optical_depth'] ?? []) as $v) {
        if ($v !== null && $v !== '') {
            $aod = round((float)$v, 2);
            break;
        }
    }
}

$omPollutants = air_openmeteo_pollutant_aqis($current);
if ($airnow !== null) {
    $aqSource = 'airnow';
    $aqSourceLabel = 'EPA AirNow';
    $reportingArea = trim((string)($airnow['reporting_area'] ?? ''));
    $pollutantAqis = [
        'pm25' => $airnow['pollutants']['pm25']['aqi'] ?? null,
        'pm10' => $airnow['pollutants']['pm10']['aqi'] ?? null,
        'ozone' => $airnow['pollutants']['ozone']['aqi'] ?? null,
        'no2' => $airnow['pollutants']['no2']['aqi'] ?? null,
    ];
} else {
    $aqSource = 'openmeteo';
    $aqSourceLabel = 'Open-Meteo';
    $reportingArea = '';
    $pollutantAqis = $omPollutants;
}

$aqiInfo = air_effective_aqi($pollutantAqis, $aqiModel, $pm25, $aod, $nwsAlerts, $aqSource === 'airnow');
$aqiDriver = (string)($aqiInfo['driver'] ?? 'pollutants');
$nwsCategory = $aqiInfo['nws_category'] ?? null;
$pollutantMax = $aqiInfo['pollutant_max'] ?? null;

// When NWS alert drives the headline, show the official category — not a synthetic floor beside model tiles.
if ($aqiDriver === 'nws' && is_array($nwsCategory)) {
    $aqiNow = null;
    $aqiHeadline = $nwsCategory['label'];
    $aqiHeadlineIsText = true;
    $aqiBandText = $nwsCategory['floor'] . '+ AQI range · NWS alert';
    [, $aqiColor] = air_aqi_band($nwsCategory['floor']);
    $aqiHint = 'Open-Meteo model max ' . ($pollutantMax !== null ? (int)$pollutantMax : '—')
        . ' — not current ground conditions during smoke';
    if ($aqSource === 'openmeteo' && !$airnowKeySet) {
        $aqiHint .= ' · add AirNow key for EPA monitor readings';
    } elseif ($airnowKeySet && $airnow === null && $airnowError !== '') {
        $aqiHint .= ' · AirNow: ' . $airnowError;
    }
} else {
    $aqiNow = $aqiInfo['effective'];
    $aqiHeadline = $aqiNow !== null ? (string)$aqiNow : '—';
    $aqiHeadlineIsText = false;
    [$aqiBandText, $aqiColor, $aqiHint] = air_aqi_band($aqiNow);
    if (($aqiInfo['note'] ?? '') !== '') {
        $aqiHint = $aqiInfo['note'];
    }
    if ($aqSource === 'openmeteo' && !$airnowKeySet && $nwsAlerts !== []) {
        $aqiHint = ($aqiHint !== '' ? $aqiHint . ' · ' : '') . 'Add EPA AirNow API key for ground-monitor AQI';
    } elseif ($airnowKeySet && $airnow === null && $airnowError !== '') {
        $aqiHint = ($aqiHint !== '' ? $aqiHint . ' · ' : '') . 'AirNow: ' . $airnowError;
    }
}
$pollutantsAreModel = $aqSource === 'openmeteo';
$pollutantsStale = $pollutantsAreModel && $aqiDriver === 'nws';

$pm25Aqi = $pollutantAqis['pm25'];
$pm10Aqi = $pollutantAqis['pm10'];
$ozoneAqi = $pollutantAqis['ozone'];
$no2Aqi = $pollutantAqis['no2'];
$pollutantStats = air_pollutant_stat_rows($aqSource, $pollutantAqis, $airnow, $pm25, $pm10, $ozoneUg, $no2Ug);
$statGridCols = count($pollutantStats) >= 4 ? 2 : max(1, count($pollutantStats));
$nwsAlertLabels = air_nws_alert_labels($nwsAlerts);
$aqiNumClass = '';
if (empty($aqiHeadlineIsText) && $aqiNow !== null) {
    if ($aqiNow >= 500) {
        $aqiNumClass = ' tight huge';
    } elseif ($aqiNow >= 200) {
        $aqiNumClass = ' tight';
    }
}
if ($aqSource === 'airnow' && $nwsAlerts !== [] && ($aqiInfo['note'] ?? '') === (string)($nwsAlerts[0]['event'] ?? 'Air quality alert') . ' active') {
    $aqiHint = '';
}

$todayKey = date('Y-m-d');
$googlePollen = air_fetch_google_pollen();
$pollenSource = 'none';
if ($googlePollen !== null && air_google_pollen_response_valid($googlePollen)) {
    $pollenSource = 'google';
} elseif (air_openmeteo_has_pollen($hourly)) {
    $pollenSource = 'openmeteo';
}
$pollenUnitLabel = $pollenSource === 'google' ? 'UPI index' : ($pollenSource === 'openmeteo' ? 'grains/m³' : 'unavailable');
$nwsHourlyPeriods = $pollenSource === 'google' ? air_fetch_nws_hourly_for_pollen() : [];
$pollenWeatherDeltaToday = 0.0;
if ($nwsHourlyPeriods !== []) {
    $wxToday = air_pollen_nws_day_weather($nwsHourlyPeriods, $todayKey);
    $pollenWeatherDeltaToday = air_pollen_weather_upi_delta($wxToday);
}
$pollenToday = air_pollen_rows_for_day($pollenSource, $hourly, $googlePollen, 0, $todayKey, $pollenWeatherDeltaToday);
$pollenWeatherAdjusted = $pollenSource === 'google' && abs($pollenWeatherDeltaToday) >= 0.05;
$pollenNeedsKey = $pollenSource === 'none';

$forecastDays = air_forecast_days($hourly, 3);
$forecast = [];
foreach ($forecastDays as $i => $dayKey) {
    $dayAqi = air_day_max_combined_aqi($hourly, $dayKey);
    $dayPmAqi = air_day_max($hourly, 'us_aqi_pm2_5', $dayKey);
    $dayWeatherDelta = 0.0;
    if ($nwsHourlyPeriods !== []) {
        $wxDay = air_pollen_nws_day_weather($nwsHourlyPeriods, $dayKey);
        $dayWeatherDelta = air_pollen_weather_upi_delta($wxDay);
    }
    $dayPollen = air_pollen_rows_for_day($pollenSource, $hourly, $googlePollen, $i, $dayKey, $dayWeatherDelta);
    $topRow = air_pollen_top_row($dayPollen);
    $topPollen = $topRow['name'] ?? '—';
    $topLabel = $topRow['label'] ?? '—';
    $label = $dayKey === $todayKey ? 'Today'
        : ($dayKey === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D', strtotime($dayKey . ' 12:00:00')));
    $forecast[] = [
        'label' => $label,
        'aqi' => $dayAqi,
        'pm25_aqi' => $dayPmAqi !== null ? (int)round($dayPmAqi) : null,
        'pollen' => $topPollen,
        'pollen_level' => $topLabel,
    ];
}

[$verdictTitle, $verdictSub, $verdictColor] = air_verdict(
    $aqiDriver === 'nws' && is_array($nwsCategory) ? $nwsCategory['floor'] : $aqiInfo['effective'],
    $aqiModel,
    $pollenToday,
    $pollenSource,
    $nwsAlerts
);

$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$compact = $boardH < 1008;
$padY = $compact ? 16 : 20;
$gap = $compact ? 12 : 16;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<?= signage_theme_fonts_head_html() ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; min-height:0; padding:<?= $padY ?>px 28px;
           display:grid; gap:<?= $gap ?>px;
           grid-template-columns: 1.05fr 0.95fr;
           grid-template-rows: auto minmax(0,1fr) minmax(0,0.95fr) auto auto;
           grid-template-areas:
             "head head"
             "aqi parts"
             "pollen forecast"
             "verdict verdict"
             "meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 48 : 56 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $compact ? 20 : 24 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 40 : 48 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $compact ? '16px 18px' : '20px 24px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:16px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:8px; }

  .aqi-panel { grid-area:aqi; display:flex; flex-direction:column; justify-content:flex-start; gap:6px; }
  .aqi-panel .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 100 : 128 ?>px;
              line-height:0.95; font-variant-numeric:tabular-nums; color:<?= h($aqiColor) ?>; }
  .aqi-panel .num.tight { font-size:<?= $compact ? 84 : 104 ?>px; }
  .aqi-panel .num.tight.huge { font-size:<?= $compact ? 68 : 84 ?>px; }
  .aqi-panel .band { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 28 : 34 ?>px;
               letter-spacing:2px; text-transform:uppercase; color:<?= h($aqiColor) ?>; margin-top:2px; }
  .aqi-panel .hint { font-size:<?= $compact ? 16 : 18 ?>px; color:var(--mist); margin-top:4px; line-height:1.35; max-width:100%;
                     display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .aqi-panel .model { font-size:<?= $compact ? 16 : 18 ?>px; color:var(--mist); margin-top:2px; }
  .aqi-panel .num.text { font-size:<?= $compact ? 72 : 88 ?>px; letter-spacing:1px; }
  .parts-stale .k { color:var(--beacon); }
  .parts-stale .stat { opacity:.82; }
  .parts-stale .stat .val { color:var(--mist) !important; }
  .parts-note { font-size:<?= $compact ? 14 : 16 ?>px; color:var(--beacon); margin:-4px 0 8px; line-height:1.35; }
  .advisories { margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
  .adv { font-size:14px; letter-spacing:1px; text-transform:uppercase; color:var(--beacon);
         border:1px solid rgba(255,179,71,.45); padding:3px 8px; border-radius:8px; }

  .parts { grid-area:parts; display:flex; flex-direction:column; min-height:0; gap:<?= $compact ? 8 : 10 ?>px; }
  .parts .stats-grid { flex:1; min-height:0; display:grid; grid-template-columns:repeat(<?= $statGridCols ?>, 1fr);
          gap:<?= $compact ? 8 : 10 ?>px; align-content:start; }
  .stat { background:var(--tile-bg); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $compact ? '10px 12px' : '14px 16px' ?>; min-height:0; display:flex; flex-direction:column; justify-content:center; }
  .stat .lab { font-size:<?= $compact ? 12 : 13 ?>px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:4px; }
  .stat .val { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 34 : 44 ?>px;
               line-height:1; font-variant-numeric:tabular-nums; }
  .stat .unit { font-size:<?= $compact ? 15 : 18 ?>px; color:var(--mist); font-weight:500; margin-left:4px; }
  .stat .conc { font-size:<?= $compact ? 12 : 14 ?>px; color:var(--mist); margin-top:4px; line-height:1.2;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .pollen { grid-area:pollen; }
  .prow { display:grid; grid-template-columns:72px 1fr 36px minmax(68px,auto); align-items:center; gap:8px;
          padding:<?= $compact ? '7px 0' : '9px 0' ?>; border-bottom:1px solid var(--hairline); }
  .prow:last-child { border-bottom:none; }
  .prow .n { font-size:<?= $compact ? 20 : 24 ?>px; }
  .prow .track { height:16px; background:var(--tile-bg); border-radius:9px; overflow:hidden; }
  .prow .fill { height:100%; border-radius:9px; background:var(--beacon); }
  .prow .fill.hot { background:var(--down); }
  .prow .c { font-family:'IBM Plex Mono',monospace; font-size:<?= $compact ? 16 : 19 ?>px; color:var(--mist); text-align:right; }
  .prow .lvl { font-size:<?= $compact ? 15 : 17 ?>px; font-weight:600; text-align:right; text-transform:uppercase; letter-spacing:1px; }

  .forecast { grid-area:forecast; display:flex; flex-direction:column; min-height:0; }
  .forecast .days { flex:1; min-height:0; display:grid; grid-template-columns:repeat(3,1fr);
                   gap:<?= $compact ? 8 : 10 ?>px; align-items:stretch; }
  .fday { background:var(--tile-bg); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $compact ? '10px 12px' : '14px 16px' ?>; min-height:0;
          display:flex; flex-direction:column; justify-content:flex-start; }
  .fday .d { font-size:14px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:6px; }
  .fday .aqi-num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 30 : 38 ?>px;
               line-height:1.15; margin:0; }
  .fday .line { font-size:<?= $compact ? 12 : 14 ?>px; color:var(--mist); margin-top:4px; line-height:1.25;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .fday .line.sub { margin-top:2px; opacity:.9; }

  .pollen-note { font-size:<?= $compact ? 15 : 17 ?>px; color:var(--mist); margin-top:8px; line-height:1.4; }
  .pollen-note code { background:var(--tile-bg); padding:2px 6px; border-radius:6px; }

  .verdict { grid-area:verdict; border-radius:14px; border:1px solid var(--hairline);
             padding:<?= $compact ? '14px 20px' : '18px 24px' ?>; display:flex;
             align-items:baseline; justify-content:space-between; gap:20px;
             background:color-mix(in srgb,var(--harbor) 94%, var(--lake-night)); min-height:0; }
  .verdict .t { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 32 : 40 ?>px;
                color:<?= h($verdictColor) ?>; letter-spacing:1px; }
  .verdict .s { font-size:<?= $compact ? 18 : 22 ?>px; color:var(--mist); text-align:right;
                display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
  .notcfg code { background:var(--tile-bg); padding:2px 8px; border-radius:6px; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(PLACE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <section class="panel aqi-panel">
    <div class="k"><?= $aqiDriver === 'nws' ? 'NWS Air Quality Alert' : 'US Air Quality Index' ?></div>
    <div>
      <div class="num<?= !empty($aqiHeadlineIsText) ? ' text' : $aqiNumClass ?>"><?= h($aqiHeadline) ?></div>
      <div class="band"><?= h($aqiBandText) ?></div>
      <?php if ($aqSource === 'airnow'): ?>
        <div class="model"><?= h($aqSourceLabel) ?><?= $reportingArea !== '' ? ' · ' . h($reportingArea) : '' ?></div>
      <?php elseif ($aqiDriver !== 'nws' && $pollutantMax !== null): ?>
        <div class="model"><?= h($aqSourceLabel) ?> · max pollutant <?= (int)$pollutantMax ?><?= $aqiModel !== null ? ' · consolidated ' . (int)$aqiModel : '' ?></div>
      <?php endif; ?>
      <?php if ($aqiHint !== ''): ?><div class="hint"><?= h($aqiHint) ?></div><?php endif; ?>
      <?php if ($nwsAlertLabels !== []): ?>
        <div class="advisories">
          <?php foreach ($nwsAlertLabels as $label): ?>
            <span class="adv"><?= h($label) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel parts<?= $pollutantsStale ? ' parts-stale' : '' ?>">
    <div class="k"><?= $pollutantsAreModel ? 'Model estimates · Open-Meteo' : 'Pollutant AQI · EPA AirNow' ?></div>
    <?php if ($pollutantsStale): ?>
      <div class="parts-note">CAMS forecast model — lags wildfire smoke; not current monitor readings</div>
    <?php endif; ?>
    <div class="stats-grid">
    <?php foreach ($pollutantStats as $stat): ?>
    <div class="stat">
      <div class="lab"><?= h($stat['lab']) ?></div>
      <div><span class="val" style="color:<?= h($stat['color']) ?>"><?= $stat['aqi'] ?? '—' ?></span><span class="unit">AQI</span></div>
      <?php if ($stat['sub'] !== ''): ?><div class="conc"><?= h($stat['sub']) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </section>

  <section class="panel pollen">
    <div class="k">Pollen today · <?= h($pollenUnitLabel) ?></div>
    <?php foreach ($pollenToday as $p):
      $val = $p['val'];
      if ($pollenSource === 'google') {
          $pLabel = $p['label'];
          $pColor = $p['color'];
          $pct = $val !== null ? min(100, (int)round((float)$val / 5 * 100)) : 0;
          $display = $val !== null ? (string)(int)$val : '—';
          $hot = $val !== null && (float)$val >= 4;
      } elseif ($pollenSource === 'openmeteo') {
          [$pLabel, $pColor] = air_pollen_band($val);
          $pct = $val !== null ? min(100, (int)round((float)$val / 2)) : 0;
          $display = $val !== null ? (string)round((float)$val, 1) : '—';
          $hot = $val !== null && (float)$val >= 50;
      } else {
          $pLabel = $p['label'];
          $pColor = $p['color'];
          $pct = 0;
          $display = '—';
          $hot = false;
      }
    ?>
    <div class="prow">
      <span class="n"><?= h($p['name']) ?></span>
      <div class="track"><div class="fill<?= $hot ? ' hot' : '' ?>" style="width:<?= $pct ?>%;background:<?= h($pColor) ?>"></div></div>
      <span class="c"><?= h($display) ?></span>
      <span class="lvl" style="color:<?= h($pColor) ?>"><?= h($pLabel) ?></span>
    </div>
    <?php endforeach; ?>
    <?php if ($pollenNeedsKey): ?>
    <div class="pollen-note">Open-Meteo pollen is Europe-only. Add a <strong>Google Pollen API key</strong> in admin → Air &amp; Pollen for US forecasts (free tier: 5,000 calls/mo).</div>
    <?php elseif ($pollenWeatherAdjusted): ?>
    <div class="pollen-note">Adjusted for today’s weather (NWS rain / humidity / wind).</div>
    <?php endif; ?>
  </section>

  <section class="panel forecast">
    <div class="k">Outlook · Open-Meteo model</div>
    <div class="days">
    <?php foreach ($forecast as $fd):
      [, $fdColor] = air_aqi_band($fd['aqi']);
    ?>
    <div class="fday">
      <div class="d"><?= h($fd['label']) ?></div>
      <div class="aqi-num" style="color:<?= h($fdColor) ?>">AQI <?= $fd['aqi'] ?? '—' ?></div>
      <div class="line">PM2.5 <?= $fd['pm25_aqi'] ?? '—' ?></div>
      <div class="line sub"><?= h($fd['pollen']) ?> · <?= h($fd['pollen_level']) ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </section>

  <div class="verdict">
    <span class="t"><?= h($verdictTitle) ?></span>
    <span class="s"><?= h($verdictSub) ?></span>
  </div>
  <?php else: ?>
  <section class="panel aqi-panel" style="grid-column:1/-1">
    <div class="notcfg">Air quality data unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['openmeteo'] ?? '') : '' ?>.
      Check network access to <code>air-quality-api.open-meteo.com</code> or try again shortly.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    $aqSource === 'airnow' ? 'EPA AirNow' : 'Open-Meteo Air Quality',
    $nwsAlerts !== [] ? 'NWS alerts' : '',
    $pollenSource === 'google' ? 'Google Pollen' : '',
    $pollenWeatherAdjusted ? 'NWS weather nudge' : '',
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
  ]))) ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  <?= signage_clock_tick_script('clock', TIMEZONE) ?>
  <?php endif; ?>
  <?php if (!$embedded): ?>
  setTimeout(() => location.reload(), <?= max(60, (int)RELOAD_SEC) ?> * 1000);
  <?php endif; ?>
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
