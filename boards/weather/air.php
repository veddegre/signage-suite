<?php
/**
 * AIR & POLLEN — 1920×1080 signage
 * US AQI, PM2.5/PM10, ozone, and pollen levels for your location.
 *
 * Data: EPA AirNow observations when an API key is set (ground monitors, per-pollutant AQI).
 *   Fallback: Open-Meteo per-pollutant US AQI (CAMS model — can lag smoke events).
 * Pollen: Google Pollen API for the US (optional key in admin); Open-Meteo pollen is Europe-only.
 *   https://open-meteo.com/en/docs/air-quality-api
 *
 * Configure lat/lon and place name in admin.php → Air & Pollen.
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('air.TITLE', 'Air & Pollen'));
define('PLACE', cfg('air.PLACE', 'West Michigan'));
define('LAT', cfg('air.LAT', 42.9720));
define('LON', cfg('air.LON', -85.9536));
define('TIMEZONE', cfg('air.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('air.RELOAD_SEC', 900));
define('GOOGLE_POLLEN_API_KEY', cfg('air.GOOGLE_POLLEN_API_KEY', ''));
define('AIRNOW_API_KEY', cfg('air.AIRNOW_API_KEY', ''));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('air.CACHE_TTL', 900));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function air_cached_json(string $url, string $key, string $diagKey = 'openmeteo'): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . '/' . $key . '.json';
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
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
        if (is_array($d)) {
            @file_put_contents($f, $body, LOCK_EX);
            return $d;
        }
    }
    $GLOBALS['diag'][$diagKey] = $err !== '' ? "curl: $err" : "HTTP $code";
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
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

/** Convert PM2.5 (µg/m³) to EPA US AQI using standard breakpoints. */
function air_pm25_to_aqi(?float $pm25): ?int
{
    if ($pm25 === null) {
        return null;
    }
    $bps = [
        [0.0, 12.0, 0, 50],
        [12.1, 35.4, 51, 100],
        [35.5, 55.4, 101, 150],
        [55.5, 150.4, 151, 200],
        [150.5, 250.4, 201, 300],
        [250.5, 350.4, 301, 400],
        [350.5, 500.4, 401, 500],
    ];
    foreach ($bps as [$cLow, $cHigh, $iLow, $iHigh]) {
        if ($pm25 < $cLow) {
            continue;
        }
        if ($pm25 <= $cHigh) {
            return (int)round(($iHigh - $iLow) / ($cHigh - $cLow) * ($pm25 - $cLow) + $iLow);
        }
    }

    return 500;
}

/**
 * @param list<array{event:string,headline:string,severity:string}> $alerts
 * @return list<array{event:string,headline:string,severity:string}>
 */
function air_nws_aq_alerts(array $alerts): array
{
    $out = [];
    foreach ($alerts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $event = trim((string)($a['event'] ?? ''));
        $headline = trim((string)($a['headline'] ?? ''));
        if ($event === '') {
            continue;
        }
        $blob = $event . ' ' . $headline;
        if (
            preg_match('/air\s+quality|smoke|haze|ozone\s+action|dense\s+fog|particulate|pm2\.?5/i', $blob)
            || preg_match('/\b(fire\s+weather|red\s+flag)\b/i', $blob)
        ) {
            $out[] = [
                'event' => $event,
                'headline' => $headline,
                'severity' => trim((string)($a['severity'] ?? 'Moderate')),
                'description' => trim((string)($a['description'] ?? '')),
            ];
        }
    }

    return $out;
}

/** Map EPA category language in NWS alert text to a minimum AQI floor. */
function air_nws_text_aqi_floor(string $text): ?int
{
    $t = strtolower($text);
    if (preg_match('/\bhazardous\b/', $t)) {
        return 301;
    }
    if (preg_match('/very\s+unhealthy/', $t)) {
        return 201;
    }
    if (preg_match('/unhealthy\s+for\s+sensitive|sensitive\s+groups/', $t)) {
        return 101;
    }
    if (preg_match('/\bunhealthy\b/', $t)) {
        return 151;
    }

    return null;
}

/** @param list<array{event:string,headline:string,severity:string,description?:string}> $alerts */
function air_nws_alert_floor(array $alerts): ?int
{
    if ($alerts === []) {
        return null;
    }
    $floor = null;
    foreach ($alerts as $a) {
        $blob = implode(' ', array_filter([
            (string)($a['event'] ?? ''),
            (string)($a['headline'] ?? ''),
            (string)($a['description'] ?? ''),
        ]));
        $textFloor = air_nws_text_aqi_floor($blob);
        if ($textFloor !== null) {
            $floor = max($floor ?? 0, $textFloor);
            continue;
        }
        $event = strtolower((string)($a['event'] ?? ''));
        $sev = strtolower((string)($a['severity'] ?? ''));
        if (str_contains($event, 'warning') || $sev === 'extreme') {
            $floor = max($floor ?? 0, 201);
        } elseif ($sev === 'severe') {
            $floor = max($floor ?? 0, 151);
        } elseif (preg_match('/air\s+quality|ozone\s+action|smoke|haze|particulate/', $event)) {
            $floor = max($floor ?? 0, 101);
        } else {
            $floor = max($floor ?? 0, 101);
        }
    }

    return $floor;
}

/** Smoke/haze layer can warrant a higher effective AQI than a single PM2.5 snapshot. */
function air_smoke_floor(?float $aod, ?float $pm25): ?int
{
    if ($aod !== null) {
        if ($aod >= 0.35) {
            return 151;
        }
        if ($aod >= 0.18) {
            return 101;
        }
    }
    if ($pm25 !== null && $pm25 >= 35.0) {
        return air_pm25_to_aqi($pm25);
    }

    return null;
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

/** @param list<array<string,mixed>>|null $rows */
function air_parse_airnow(?array $rows): ?array
{
    if ($rows === null || $rows === []) {
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

function air_fetch_airnow(): ?array
{
    $key = trim((string)AIRNOW_API_KEY);
    if ($key === '') {
        return null;
    }
    $cacheKey = 'airnow_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $url = 'https://www.airnowapi.org/aq/observation/latLong/current/?' . http_build_query([
        'format' => 'application/json',
        'latitude' => LAT,
        'longitude' => LON,
        'distance' => 50,
        'API_KEY' => $key,
    ]);
    $raw = air_cached_json($url, $cacheKey, 'airnow');

    return air_parse_airnow($raw);
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

/**
 * EPA overall AQI = max pollutant sub-index (+ NWS / smoke floors when higher).
 *
 * @param array{pm25:?int,pm10:?int,ozone:?int,no2:?int} $pollutantAqis
 * @param list<array{event:string,headline:string,severity:string}> $nwsAlerts
 * @return array{effective:?int,pollutant_max:?int,model:?int,floor:?int,note:string}
 */
function air_effective_aqi(array $pollutantAqis, ?int $modelAqi, ?float $pm25, ?float $aod, array $nwsAlerts): array
{
    $filtered = array_filter($pollutantAqis, static fn($v) => $v !== null);
    $pollutantMax = $filtered !== [] ? max($filtered) : null;
    if ($pollutantMax === null) {
        $pollutantMax = $modelAqi ?? air_pm25_to_aqi($pm25);
    }
    $nwsFloor = air_nws_alert_floor($nwsAlerts);
    $smokeFloor = air_smoke_floor($aod, $pm25);
    $candidates = array_filter([$pollutantMax, $nwsFloor, $smokeFloor], static fn($v) => $v !== null);
    $effective = $candidates !== [] ? max($candidates) : null;
    $floor = null;
    foreach ([$nwsFloor, $smokeFloor] as $f) {
        if ($f !== null) {
            $floor = max($floor ?? 0, $f);
        }
    }

    $note = '';
    if ($nwsAlerts !== []) {
        $note = (string)($nwsAlerts[0]['event'] ?? 'Air quality alert') . ' active';
        if ($pollutantMax !== null && $effective !== null && $effective > $pollutantMax) {
            $note .= ' — elevated by alert / smoke signals';
        } elseif ($modelAqi !== null && $pollutantMax !== null && $pollutantMax > $modelAqi + 20) {
            $note .= ' — ground monitors above model';
        }
    } elseif ($modelAqi !== null && $pollutantMax !== null && $pollutantMax > $modelAqi + 20) {
        $note = 'Per-pollutant AQI above consolidated model reading';
    } elseif ($smokeFloor !== null && $pollutantMax !== null && $smokeFloor > $pollutantMax) {
        $note = 'Smoke / haze layer in the forecast';
    }

    return [
        'effective' => $effective,
        'pollutant_max' => $pollutantMax,
        'model' => $modelAqi,
        'floor' => $floor,
        'note' => $note,
    ];
}

/** @return list<array{event:string,headline:string,severity:string}> */
function air_fetch_nws_alerts(): array
{
    $cacheKey = 'nws_air_' . md5(sprintf('%.4F_%.4F', LAT, LON));
    $raw = air_cached_json(
        sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', LAT, LON),
        $cacheKey,
        'nws'
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

/** Google Universal Pollen Index (1–5) → label and color. */
function air_upi_band(?int $upi): array
{
    if ($upi === null) return ['—', 'var(--mist)'];
    return match (max(1, min(5, $upi))) {
        1       => ['Very low', '#39c46d'],
        2       => ['Low', '#39c46d'],
        3       => ['Moderate', 'var(--beacon)'],
        4       => ['High', '#ff9d4d'],
        default => ['Very high', '#ff5d5d'],
    };
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

function air_google_pollen_row_label(?int $upi, string $category): array
{
    [$label, $color] = air_upi_band($upi);
    if ($category !== '' && !preg_match('/off\s*season/i', $category)) {
        $label = $category;
    }
    return [$label, $color];
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
function air_pollen_rows_google(?array $data, int $dayIndex): array
{
    if (!$data) return [];
    $day = $data['dailyInfo'][$dayIndex] ?? null;
    if (!$day) return [];
    $labels = ['GRASS' => 'Grass', 'WEED' => 'Weed', 'TREE' => 'Tree'];
    $rows = [];
    foreach ($day['pollenTypeInfo'] ?? [] as $pt) {
        $code = (string)($pt['code'] ?? '');
        if (!isset($labels[$code])) continue;
        $inSeason = (bool)($pt['inSeason'] ?? false);
        [$upi, $category, $hasIndex] = air_google_pollen_index($pt['indexInfo'] ?? null);
        if (!$inSeason && !$hasIndex) {
            $rows[] = [
                'name' => $labels[$code],
                'val' => null,
                'unit' => 'upi',
                'label' => 'Off season',
                'color' => 'var(--mist)',
            ];
            continue;
        }
        [$label, $color] = air_google_pollen_row_label($upi, $category);
        $rows[] = [
            'name' => $labels[$code],
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

function air_pollen_rows_for_day(string $source, array $hourly, ?array $google, int $dayIndex, string $dayKey): array
{
    if ($source === 'google') {
        return air_pollen_rows_google($google, $dayIndex);
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

$aqiInfo = air_effective_aqi($pollutantAqis, $aqiModel, $pm25, $aod, $nwsAlerts);
$aqiNow = $aqiInfo['effective'];
[$aqiLabel, $aqiColor, $aqiHint] = air_aqi_band($aqiNow);
if (($aqiInfo['note'] ?? '') !== '') {
    $aqiHint = $aqiInfo['note'];
}
if ($aqSource === 'openmeteo' && trim((string)AIRNOW_API_KEY) === '' && $nwsAlerts !== []) {
    $aqiHint = ($aqiHint !== '' ? $aqiHint . ' · ' : '') . 'Add EPA AirNow API key in admin for ground-monitor AQI';
}

$pm25Aqi = $pollutantAqis['pm25'];
$pm10Aqi = $pollutantAqis['pm10'];
$ozoneAqi = $pollutantAqis['ozone'];
$no2Aqi = $pollutantAqis['no2'];
[, $pm25Color] = air_aqi_band($pm25Aqi);
[, $pm10Color] = air_aqi_band($pm10Aqi);
[, $ozoneColor] = air_aqi_band($ozoneAqi);
[, $no2Color] = air_aqi_band($no2Aqi);

$todayKey = date('Y-m-d');
$googlePollen = air_fetch_google_pollen();
$pollenSource = 'none';
if ($googlePollen) {
    $pollenSource = 'google';
} elseif (air_openmeteo_has_pollen($hourly)) {
    $pollenSource = 'openmeteo';
}
$pollenUnitLabel = $pollenSource === 'google' ? 'UPI index' : ($pollenSource === 'openmeteo' ? 'grains/m³' : 'unavailable');
$pollenToday = air_pollen_rows_for_day($pollenSource, $hourly, $googlePollen, 0, $todayKey);
$pollenNeedsKey = $pollenSource === 'none';

$forecastDays = air_forecast_days($hourly, 3);
$forecast = [];
foreach ($forecastDays as $i => $dayKey) {
    $dayAqi = air_day_max_combined_aqi($hourly, $dayKey);
    $dayPmAqi = air_day_max($hourly, 'us_aqi_pm2_5', $dayKey);
    $dayPollen = air_pollen_rows_for_day($pollenSource, $hourly, $googlePollen, $i, $dayKey);
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

[$verdictTitle, $verdictSub, $verdictColor] = air_verdict($aqiNow, $aqiModel, $pollenToday, $pollenSource, $nwsAlerts);

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
          --up:#39c46d; --down:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; min-height:0; padding:<?= $padY ?>px 28px;
           display:grid; gap:<?= $gap ?>px;
           grid-template-columns: 1fr 1fr 1fr;
           grid-template-rows: auto minmax(0,1.15fr) minmax(0,1fr) auto minmax(0,auto);
           grid-template-areas:
             "head head head"
             "aqi aqi parts"
             "pollen pollen forecast"
             "verdict verdict verdict"
             "meta meta meta"; }
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
  .aqi-panel .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 112 : 140 ?>px;
              line-height:1; font-variant-numeric:tabular-nums; color:<?= h($aqiColor) ?>; }
  .aqi-panel .band { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 30 : 38 ?>px;
               letter-spacing:2px; text-transform:uppercase; color:<?= h($aqiColor) ?>; margin-top:4px; }
  .aqi-panel .hint { font-size:<?= $compact ? 17 : 20 ?>px; color:var(--mist); margin-top:4px; line-height:1.35; max-width:920px;
                     display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .aqi-panel .model { font-size:<?= $compact ? 16 : 18 ?>px; color:var(--mist); margin-top:2px; }
  .advisories { margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
  .adv { font-size:14px; letter-spacing:1px; text-transform:uppercase; color:var(--beacon);
         border:1px solid rgba(255,179,71,.45); padding:3px 8px; border-radius:8px; }

  .parts { grid-area:parts; display:grid; grid-template-columns:1fr 1fr; gap:<?= $compact ? 10 : 12 ?>px; }
  .stat { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $compact ? '12px 14px' : '16px 18px' ?>; min-height:0; }
  .stat .lab { font-size:14px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:6px; }
  .stat .val { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 40 : 52 ?>px;
               line-height:1; font-variant-numeric:tabular-nums; }
  .stat .unit { font-size:<?= $compact ? 18 : 22 ?>px; color:var(--mist); font-weight:500; margin-left:6px; }
  .stat .conc { font-size:<?= $compact ? 14 : 16 ?>px; color:var(--mist); margin-top:4px; }

  .pollen { grid-area:pollen; }
  .prow { display:grid; grid-template-columns:110px 1fr 90px 80px; align-items:center; gap:10px;
          padding:<?= $compact ? '8px 0' : '10px 0' ?>; border-bottom:1px solid var(--hairline); }
  .prow:last-child { border-bottom:none; }
  .prow .n { font-size:<?= $compact ? 20 : 24 ?>px; }
  .prow .track { height:16px; background:var(--lake-night); border-radius:9px; overflow:hidden; }
  .prow .fill { height:100%; border-radius:9px; background:var(--beacon); }
  .prow .fill.hot { background:var(--down); }
  .prow .c { font-family:'IBM Plex Mono',monospace; font-size:<?= $compact ? 16 : 19 ?>px; color:var(--mist); text-align:right; }
  .prow .lvl { font-size:<?= $compact ? 15 : 17 ?>px; font-weight:600; text-align:right; text-transform:uppercase; letter-spacing:1px; }

  .forecast { grid-area:forecast; display:flex; flex-direction:column; min-height:0; }
  .forecast .days { flex:1; min-height:0; display:grid; grid-template-columns:repeat(3,1fr);
                   gap:<?= $compact ? 8 : 10 ?>px; align-items:stretch; }
  .fday { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $compact ? '10px 12px' : '14px 16px' ?>; min-height:0;
          display:flex; flex-direction:column; justify-content:flex-start; }
  .fday .d { font-size:14px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:6px; }
  .fday .aqi-num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 30 : 38 ?>px;
               line-height:1.15; margin:0; }
  .fday .line { font-size:<?= $compact ? 14 : 16 ?>px; color:var(--mist); margin-top:6px; line-height:1.3; }

  .pollen-note { font-size:<?= $compact ? 15 : 17 ?>px; color:var(--mist); margin-top:8px; line-height:1.4; }
  .pollen-note code { background:var(--lake-night); padding:2px 6px; border-radius:6px; }

  .verdict { grid-area:verdict; border-radius:14px; border:1px solid var(--hairline);
             padding:<?= $compact ? '14px 20px' : '18px 24px' ?>; display:flex;
             align-items:baseline; justify-content:space-between; gap:20px;
             background:linear-gradient(90deg, rgba(20,31,51,.95), rgba(12,20,34,.95)); min-height:0; }
  .verdict .t { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 32 : 40 ?>px;
                color:<?= h($verdictColor) ?>; letter-spacing:1px; }
  .verdict .s { font-size:<?= $compact ? 18 : 22 ?>px; color:var(--mist); text-align:right;
                display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
  .notcfg code { background:var(--lake-night); padding:2px 8px; border-radius:6px; }
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
    <div class="k">US Air Quality Index</div>
    <div>
      <div class="num"><?= $aqiNow ?? '—' ?></div>
      <div class="band"><?= h($aqiLabel) ?></div>
      <div class="model"><?= h($aqSourceLabel) ?><?= $reportingArea !== '' ? ' · ' . h($reportingArea) : '' ?><?= $aqiModel !== null && $aqSource === 'airnow' ? ' · model ' . (int)$aqiModel : '' ?></div>
      <?php if ($aqiModel !== null && $aqiNow !== null && $aqSource === 'openmeteo' && $aqiNow > $aqiModel): ?>
        <div class="model">Model consolidated AQI <?= (int)$aqiModel ?><?= $aod !== null ? ' · AOD ' . h((string)$aod) : '' ?></div>
      <?php endif; ?>
      <div class="hint"><?= h($aqiHint) ?></div>
      <?php if ($nwsAlerts !== []): ?>
        <div class="advisories">
          <?php foreach ($nwsAlerts as $alert): ?>
            <span class="adv"><?= h((string)($alert['event'] ?? 'Alert')) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel parts">
    <div class="stat">
      <div class="lab">PM2.5</div>
      <div><span class="val" style="color:<?= h($pm25Color) ?>"><?= $pm25Aqi ?? '—' ?></span><span class="unit">AQI</span></div>
      <?php if ($pm25 !== null): ?><div class="conc"><?= h((string)$pm25) ?> µg/m³</div><?php endif; ?>
    </div>
    <div class="stat">
      <div class="lab">PM10</div>
      <div><span class="val" style="color:<?= h($pm10Color) ?>"><?= $pm10Aqi ?? '—' ?></span><span class="unit">AQI</span></div>
      <?php if ($pm10 !== null): ?><div class="conc"><?= h((string)$pm10) ?> µg/m³</div><?php endif; ?>
    </div>
    <div class="stat">
      <div class="lab">Ozone</div>
      <div><span class="val" style="color:<?= h($ozoneColor) ?>"><?= $ozoneAqi ?? '—' ?></span><span class="unit">AQI</span></div>
      <?php if ($ozoneUg !== null): ?><div class="conc"><?= h((string)$ozoneUg) ?> µg/m³</div><?php endif; ?>
    </div>
    <div class="stat">
      <div class="lab">NO₂</div>
      <div><span class="val" style="color:<?= h($no2Color) ?>"><?= $no2Aqi ?? '—' ?></span><span class="unit">AQI</span></div>
      <?php if ($no2Ug !== null): ?><div class="conc"><?= h((string)$no2Ug) ?> µg/m³</div><?php endif; ?>
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
    <?php endif; ?>
  </section>

  <section class="panel forecast">
    <div class="k">Outlook</div>
    <div class="days">
    <?php foreach ($forecast as $fd):
      [, $fdColor] = air_aqi_band($fd['aqi']);
    ?>
    <div class="fday">
      <div class="d"><?= h($fd['label']) ?></div>
      <div class="aqi-num" style="color:<?= h($fdColor) ?>">AQI <?= $fd['aqi'] ?? '—' ?></div>
      <div class="line">PM2.5 AQI <?= $fd['pm25_aqi'] ?? '—' ?> · <?= h($fd['pollen']) ?> <?= h($fd['pollen_level']) ?></div>
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
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
  ]))) ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick() {
    const n = new Date();
    let h = n.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('clock').textContent =
      h + ':' + String(n.getMinutes()).padStart(2, '0') + ' ' + ap;
  }
  tick();
  setInterval(tick, 1000);
  <?php endif; ?>
  <?php if (!$embedded): ?>
  setTimeout(() => location.reload(), <?= max(60, (int)RELOAD_SEC) ?> * 1000);
  <?php endif; ?>
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
