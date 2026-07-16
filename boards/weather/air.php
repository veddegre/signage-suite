<?php
/**
 * AIR & POLLEN — 1920×1080 signage
 * US AQI, PM2.5/PM10, ozone, and pollen levels for your location.
 *
 * Data: Open-Meteo Air Quality API for US AQI + pollutants (free, no key).
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
            ];
        }
    }

    return $out;
}

/** @param list<array{event:string,headline:string,severity:string}> $alerts */
function air_nws_alert_floor(array $alerts): ?int
{
    if ($alerts === []) {
        return null;
    }
    $floor = null;
    foreach ($alerts as $a) {
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
 * Reconcile model AQI with PM2.5, smoke/haze, and active NWS alerts.
 *
 * @param list<array{event:string,headline:string,severity:string}> $nwsAlerts
 * @return array{effective:?int,model:?int,pm25_aqi:?int,floor:?int,note:string}
 */
function air_effective_aqi(?int $modelAqi, ?float $pm25, ?float $aod, array $nwsAlerts): array
{
    $pm25Aqi = air_pm25_to_aqi($pm25);
    $nwsFloor = air_nws_alert_floor($nwsAlerts);
    $smokeFloor = air_smoke_floor($aod, $pm25);
    $candidates = array_filter([$modelAqi, $pm25Aqi, $nwsFloor, $smokeFloor], static fn($v) => $v !== null);
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
        if ($modelAqi !== null && $effective !== null && $effective > $modelAqi) {
            $note .= ' — model AQI may lag official alerts';
        }
    } elseif ($pm25Aqi !== null && $modelAqi !== null && $pm25Aqi > $modelAqi + 15) {
        $note = 'PM2.5 suggests worse air than the model AQI';
    } elseif ($smokeFloor !== null && $modelAqi !== null && $smokeFloor > $modelAqi) {
        $note = 'Smoke / haze layer in the forecast';
    }

    return [
        'effective' => $effective,
        'model' => $modelAqi,
        'pm25_aqi' => $pm25Aqi,
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

// ── Fetch Open-Meteo air quality + pollen ────────────────────────────────────
$cacheKey = 'openmeteo_air_' . md5(sprintf('%.4F_%.4F_%s', LAT, LON, TIMEZONE));
$query = http_build_query([
    'latitude' => LAT,
    'longitude' => LON,
    'timezone' => TIMEZONE,
    'forecast_days' => 3,
    'current' => 'us_aqi,pm2_5,pm10,ozone,nitrogen_dioxide,aerosol_optical_depth',
    'hourly' => 'us_aqi,pm2_5,aerosol_optical_depth,ragweed_pollen,grass_pollen,birch_pollen,alder_pollen',
]);
$data = air_cached_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $query, $cacheKey);

$nwsAlerts = air_nws_aq_alerts(air_fetch_nws_alerts());

$current = is_array($data['current'] ?? null) ? $data['current'] : [];
$hourly  = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
$hasData = $current !== [] || $hourly !== [];

$aqiModel = isset($current['us_aqi']) ? (int)round((float)$current['us_aqi']) : null;
$pm25 = isset($current['pm2_5']) ? round((float)$current['pm2_5'], 1) : null;
$aod = isset($current['aerosol_optical_depth']) ? round((float)$current['aerosol_optical_depth'], 2) : null;
if ($aod === null && $hourly !== []) {
    foreach (array_reverse($hourly['aerosol_optical_depth'] ?? []) as $v) {
        if ($v !== null && $v !== '') {
            $aod = round((float)$v, 2);
            break;
        }
    }
}
$aqiInfo = air_effective_aqi($aqiModel, $pm25, $aod, $nwsAlerts);
$aqiNow = $aqiInfo['effective'];
[$aqiLabel, $aqiColor, $aqiHint] = air_aqi_band($aqiNow);
if (($aqiInfo['note'] ?? '') !== '') {
    $aqiHint = $aqiInfo['note'];
}
$pm10 = isset($current['pm10']) ? round((float)$current['pm10'], 1) : null;
$ozone = isset($current['ozone']) ? round((float)$current['ozone'], 0) : null;
$no2 = isset($current['nitrogen_dioxide']) ? round((float)$current['nitrogen_dioxide'], 1) : null;

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
    $dayAqi = air_day_max($hourly, 'us_aqi', $dayKey);
    $dayPm = air_day_max($hourly, 'pm2_5', $dayKey);
    $dayPollen = air_pollen_rows_for_day($pollenSource, $hourly, $googlePollen, $i, $dayKey);
    $topRow = air_pollen_top_row($dayPollen);
    $topPollen = $topRow['name'] ?? '—';
    $topLabel = $topRow['label'] ?? '—';
    $label = $dayKey === $todayKey ? 'Today'
        : ($dayKey === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D', strtotime($dayKey . ' 12:00:00')));
    $forecast[] = [
        'label' => $label,
        'aqi' => $dayAqi !== null ? (int)round($dayAqi) : null,
        'pm25' => $dayPm !== null ? round($dayPm, 1) : null,
        'pollen' => $topPollen,
        'pollen_level' => $topLabel,
    ];
}

[$verdictTitle, $verdictSub, $verdictColor] = air_verdict($aqiNow, $aqiModel, $pollenToday, $pollenSource, $nwsAlerts);

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowAqi  = max(300, (int)round(360 * $boardH / 1080));
$rowMid  = max(260, (int)round(320 * $boardH / 1080));
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
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-columns: 1fr 1fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowAqi ?>px <?= $rowMid ?>px auto auto;
           grid-template-areas:
             "head head head"
             "aqi aqi parts"
             "pollen pollen forecast"
             "verdict verdict verdict"
             "meta meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '20px 24px' : '26px 32px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:10px; }

  .aqi-panel { grid-area:aqi; display:flex; flex-direction:column; justify-content:space-between; }
  .aqi-panel .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 148 : 180 ?>px;
              line-height:1; font-variant-numeric:tabular-nums; color:<?= h($aqiColor) ?>; }
  .aqi-panel .band { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 38 : 46 ?>px;
               letter-spacing:2px; text-transform:uppercase; color:<?= h($aqiColor) ?>; margin-top:8px; }
  .aqi-panel .hint { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); margin-top:10px; line-height:1.4; max-width:920px; }
  .aqi-panel .model { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); margin-top:8px; }
  .advisories { margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; }
  .adv { font-size:15px; letter-spacing:1px; text-transform:uppercase; color:var(--beacon);
         border:1px solid rgba(255,179,71,.45); padding:4px 10px; border-radius:8px; }

  .parts { grid-area:parts; display:grid; grid-template-columns:1fr 1fr; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; }
  .stat { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $boardH < 1080 ? '16px 18px' : '20px 22px' ?>; min-height:0; }
  .stat .lab { font-size:16px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:8px; }
  .stat .val { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 52 : 64 ?>px;
               line-height:1; color:var(--snow); font-variant-numeric:tabular-nums; }
  .stat .unit { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); font-weight:500; margin-left:6px; }

  .pollen { grid-area:pollen; }
  .prow { display:grid; grid-template-columns:130px 1fr 110px 90px; align-items:center; gap:14px;
          padding:<?= $boardH < 1080 ? '10px 0' : '13px 0' ?>; border-bottom:1px solid var(--hairline); }
  .prow:last-child { border-bottom:none; }
  .prow .n { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; }
  .prow .track { height:18px; background:var(--lake-night); border-radius:9px; overflow:hidden; }
  .prow .fill { height:100%; border-radius:9px; background:var(--beacon); }
  .prow .fill.hot { background:var(--down); }
  .prow .c { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 18 : 21 ?>px; color:var(--mist); text-align:right; }
  .prow .lvl { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; font-weight:600; text-align:right; text-transform:uppercase; letter-spacing:1px; }

  .forecast { grid-area:forecast; display:flex; flex-direction:column; min-height:0; }
  .forecast .days { flex:1; min-height:0; display:grid; grid-template-columns:repeat(3,1fr);
                   gap:<?= $boardH < 1080 ? 10 : 12 ?>px; align-items:stretch; }
  .fday { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $boardH < 1080 ? '12px 14px' : '16px 18px' ?>; min-height:0;
          display:flex; flex-direction:column; justify-content:flex-start; }
  .fday .d { font-size:15px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:8px; }
  .fday .aqi-num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 36 : 44 ?>px;
               line-height:1.15; margin:0; }
  .fday .line { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--mist); margin-top:8px; line-height:1.35; }

  .pollen-note { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; color:var(--mist); margin-top:12px; line-height:1.45; }
  .pollen-note code { background:var(--lake-night); padding:2px 6px; border-radius:6px; }

  .verdict { grid-area:verdict; border-radius:14px; border:1px solid var(--hairline);
             padding:<?= $boardH < 1080 ? '18px 24px' : '22px 32px' ?>; display:flex;
             align-items:baseline; justify-content:space-between; gap:24px;
             background:linear-gradient(90deg, rgba(20,31,51,.95), rgba(12,20,34,.95)); }
  .verdict .t { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 40 : 48 ?>px;
                color:<?= h($verdictColor) ?>; letter-spacing:1px; }
  .verdict .s { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); text-align:right; }

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
      <?php if ($aqiModel !== null && $aqiNow !== null && $aqiNow > $aqiModel): ?>
        <div class="model">Open-Meteo model AQI <?= (int)$aqiModel ?><?= $pm25 !== null ? ' · PM2.5 ' . h((string)$pm25) : '' ?><?= $aod !== null ? ' · AOD ' . h((string)$aod) : '' ?></div>
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
      <div><span class="val"><?= $pm25 ?? '—' ?></span><span class="unit">µg/m³</span></div>
    </div>
    <div class="stat">
      <div class="lab">PM10</div>
      <div><span class="val"><?= $pm10 ?? '—' ?></span><span class="unit">µg/m³</span></div>
    </div>
    <div class="stat">
      <div class="lab">Ozone</div>
      <div><span class="val"><?= $ozone ?? '—' ?></span><span class="unit">µg/m³</span></div>
    </div>
    <div class="stat">
      <div class="lab">NO₂</div>
      <div><span class="val"><?= $no2 ?? '—' ?></span><span class="unit">µg/m³</span></div>
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
      <div class="line">PM2.5 <?= $fd['pm25'] ?? '—' ?> · <?= h($fd['pollen']) ?> <?= h($fd['pollen_level']) ?></div>
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
    'Open-Meteo Air Quality',
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
