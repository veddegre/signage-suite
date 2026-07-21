<?php
/**
 * PHOTO CONDITIONS — 1920×1080 signage
 * "Should I grab a camera tonight?" — golden/blue hour windows, sunset cloud
 * structure, smoke/haze tint (Open-Meteo AQ + OWM weather), NWS photo-relevant
 * advisories, moon phase, and aurora Kp for West Michigan.
 *
 * Setup: set OWM_API_KEY (same key as the weather board). Open-Meteo AQ and
 * NOAA SWPC / NWS need no key.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';

$SCREEN = signage_request_screen();
$LOC = rotation_screen_location($SCREEN);

define('OWM_API_KEY', cfg('photo.OWM_API_KEY', 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE'));
define('LAT', $LOC['lat']);
define('LON', $LOC['lon']);
define('PLACE', $LOC['place']);
define('TIMEZONE', cfg('photo.TIMEZONE', 'America/Detroit'));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('photo.CACHE_TTL', 900));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function photo_cached_get(string $url, string $key, int $timeout = 10): ?string
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        return (string)file_get_contents($f);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'HomeSignage/PhotoBoard/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json, application/geo+json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        @file_put_contents($f, $body, LOCK_EX);

        return $body;
    }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";

    return is_file($f) ? (string)file_get_contents($f) : null;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** OWM weather ids that tint the sky without needing cloud structure. */
function photo_is_smoke_weather_id(int $id): bool
{
    // 711 smoke, 721 haze, 731 sand/dust whirls, 751 sand, 761 dust, 762 ash
    return in_array($id, [711, 721, 731, 751, 761, 762], true);
}

function photo_text_mentions_smoke(string $text): bool
{
    return (bool)preg_match('/\b(smoke|haze|dust|ash|wildfire|blowing\s+dust)\b/i', $text);
}

/**
 * Smoke/haze tint for photography (often dramatic color with a clear deck).
 * PM2.5 alone can stay low while smoke aloft still tints sunset — also weight
 * OWM smoke/haze weather, AOD, visibility, and NWS advisories.
 *
 * @return array{level:string, label:string, score:int, pm25:?float, aod:?float, reasons:list<string>}
 */
function photo_smoke_assessment(
    ?array $slot,
    ?float $pm25,
    ?float $aod,
    ?float $visibilityKm,
    array $nwsEvents
): array {
    $score = 0;
    $reasons = [];

    $desc = strtolower((string)($slot['weather'][0]['description'] ?? ''));
    $wid = (int)($slot['weather'][0]['id'] ?? 0);
    if (photo_is_smoke_weather_id($wid) || photo_text_mentions_smoke($desc)) {
        $score += 2;
        $reasons[] = $desc !== '' ? ucfirst($desc) . ' in forecast' : 'Smoke / haze weather type';
    }

    if ($pm25 !== null) {
        if ($pm25 >= 35.0) {
            $score += 3;
            $reasons[] = 'Elevated PM2.5 ' . round($pm25);
        } elseif ($pm25 >= 12.0) {
            $score += 2;
            $reasons[] = 'PM2.5 ' . round($pm25, 1);
        } elseif ($pm25 >= 8.0) {
            $score += 1;
            $reasons[] = 'PM2.5 ' . round($pm25, 1);
        }
    }

    if ($aod !== null) {
        if ($aod >= 0.35) {
            $score += 3;
            $reasons[] = 'Heavy aerosol / haze (AOD ' . number_format($aod, 2) . ')';
        } elseif ($aod >= 0.18) {
            $score += 2;
            $reasons[] = 'Haze layer (AOD ' . number_format($aod, 2) . ')';
        } elseif ($aod >= 0.10) {
            $score += 1;
            $reasons[] = 'Light aerosol (AOD ' . number_format($aod, 2) . ')';
        }
    }

    if ($visibilityKm !== null && $visibilityKm > 0 && $visibilityKm < 8.0) {
        $score += 1;
        $reasons[] = 'Reduced visibility ' . round($visibilityKm, 1) . ' km';
    }

    foreach ($nwsEvents as $event) {
        if (photo_text_mentions_smoke($event) || preg_match('/air\s+quality/i', $event)) {
            $score += 2;
            $reasons[] = $event;
            break;
        }
    }

    if ($score >= 4) {
        $level = 'heavy';
        $label = 'HEAVY';
    } elseif ($score >= 2) {
        $level = 'broken';
        $label = 'BROKEN';
    } elseif ($score >= 1) {
        $level = 'clear';
        $label = 'LIGHT';
    } else {
        $level = 'none';
        $label = 'CLEAR';
    }

    return [
        'level' => $level,
        'label' => $label,
        'score' => $score,
        'pm25' => $pm25,
        'aod' => $aod,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

/**
 * @param list<array{event:string,headline:string}> $alerts
 * @return list<string>
 */
function photo_nws_photo_events(array $alerts): array
{
    $out = [];
    foreach ($alerts as $a) {
        $event = trim((string)($a['event'] ?? ''));
        $headline = trim((string)($a['headline'] ?? ''));
        $blob = $event . ' ' . $headline;
        if ($event === '') {
            continue;
        }
        if (
            preg_match('/air\s+quality|smoke|haze|dust|dense\s+fog|blowing\s+dust|volcanic/i', $blob)
            || preg_match('/\b(fire\s+weather|red\s+flag)\b/i', $blob)
        ) {
            $out[] = $event;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return array{0:string,1:string,2:string,3:int,4:array}
 */
function photo_verdict(int $clouds, array $smoke, string $wxDesc): array
{
    $smokeLevel = (string)($smoke['level'] ?? 'none');
    $hasSmoke = $smokeLevel !== 'none';
    $desc = $wxDesc !== '' ? $wxDesc : 'no detail';

    // Clear / mostly clear deck + smoke tint → dramatic color without cloud structure
    if ($clouds <= 25 && $hasSmoke) {
        $tone = $smokeLevel === 'heavy' ? 'deep orange and red' : 'warm orange and red';
        $why = 'Wildfire smoke / haze with a mostly clear deck — strong chance of '
            . $tone . ', even without cloud structure.';

        return ['DRAMATIC SKY', $why, 'var(--beacon)', $clouds, $smoke];
    }

    if ($clouds <= 20) {
        return [
            'CLEAN LIGHT',
            'Clear horizon — crisp golden hour, minimal drama (' . $desc . ')',
            '#39c46d',
            $clouds,
            $smoke,
        ];
    }

    if ($clouds <= 70) {
        $extra = $hasSmoke
            ? ' Smoke / haze may deepen the color.'
            : ' Broken clouds — best odds for a painted sunset.';

        return [
            'DRAMATIC SKY',
            trim('Broken / textured cloud deck.' . $extra),
            'var(--beacon)',
            $clouds,
            $smoke,
        ];
    }

    if ($clouds <= 85) {
        return [
            'MARGINAL',
            'Mostly cloudy — maybe a break at the horizon' . ($hasSmoke ? '; smoke still may tint gaps' : ''),
            'var(--mist)',
            $clouds,
            $smoke,
        ];
    }

    return [
        'FLAT GRAY',
        'Overcast — good night for editing instead' . ($hasSmoke ? ' (smoke capped under thick cloud)' : ''),
        '#ff5d5d',
        $clouds,
        $smoke,
    ];
}

function photo_nearest_hourly(array $times, array $values, int $targetTs): ?float
{
    $best = null;
    $bestGap = PHP_INT_MAX;
    foreach ($times as $i => $t) {
        $ts = strtotime((string)$t);
        if ($ts === false || !isset($values[$i]) || $values[$i] === null || $values[$i] === '') {
            continue;
        }
        $gap = abs($ts - $targetTs);
        if ($gap < $bestGap) {
            $bestGap = $gap;
            $best = (float)$values[$i];
        }
    }

    return $bestGap <= 5400 ? $best : null;
}

function tspan(array $w): string
{
    return date('g:i', $w[0]) . '–' . date('g:i A', $w[1]);
}

// ── Sun geometry ─────────────────────────────────────────────────────────────
$sun = date_sun_info(time(), LAT, LON);
$goldenAm = [$sun['sunrise'], $sun['sunrise'] + 3600];
$goldenPm = [$sun['sunset'] - 3600, $sun['sunset']];
$bluePm = [$sun['sunset'], $sun['civil_twilight_end']];
$blueAm = [$sun['civil_twilight_begin'], $sun['sunrise']];

// ── Moon phase (synodic approximation, good to ~hours) ──────────────────────
$synodic = 29.530588853;
$daysSinceNew = fmod((time() - 947182440) / 86400, $synodic); // 2000-01-06 18:14 UTC new moon
if ($daysSinceNew < 0) {
    $daysSinceNew += $synodic;
}
$phaseFrac = $daysSinceNew / $synodic; // 0=new .5=full
$illum = (1 - cos(2 * M_PI * $phaseFrac)) / 2;
$phaseNames = [
    [0.0325, 'New Moon'], [0.2175, 'Waxing Crescent'], [0.2825, 'First Quarter'],
    [0.4675, 'Waxing Gibbous'], [0.5325, 'Full Moon'], [0.7175, 'Waning Gibbous'],
    [0.7825, 'Last Quarter'], [0.9675, 'Waning Crescent'], [1.01, 'New Moon'],
];
$phaseName = 'Moon';
foreach ($phaseNames as [$lim, $name]) {
    if ($phaseFrac <= $lim) {
        $phaseName = $name;
        break;
    }
}

// ── NWS advisories that matter for photo (smoke / AQ / dust / fog) ───────────
$nwsPhotoEvents = [];
$nwsRaw = photo_cached_get(
    sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', LAT, LON),
    'nws_photo_' . sprintf('%.4F_%.4F', LAT, LON),
    8
);
if ($nwsRaw) {
    $nj = json_decode($nwsRaw, true);
    $alerts = [];
    foreach (($nj['features'] ?? []) as $feat) {
        $p = $feat['properties'] ?? [];
        if (!is_array($p)) {
            continue;
        }
        $alerts[] = [
            'event' => (string)($p['event'] ?? ''),
            'headline' => (string)($p['headline'] ?? ''),
        ];
    }
    $nwsPhotoEvents = photo_nws_photo_events($alerts);
}

// ── Open-Meteo air quality around sunset (PM2.5 + haze AOD) ─────────────────
$aqPm25 = null;
$aqAod = null;
$aqQuery = http_build_query([
    'latitude' => LAT,
    'longitude' => LON,
    'hourly' => 'pm2_5,aerosol_optical_depth',
    'timezone' => TIMEZONE,
    'forecast_days' => 4,
]);
$aqRaw = photo_cached_get(
    'https://air-quality-api.open-meteo.com/v1/air-quality?' . $aqQuery,
    'photo_aq_' . sprintf('%F_%F', LAT, LON),
    12
);
$aqJson = $aqRaw ? json_decode($aqRaw, true) : null;
$aqTimes = is_array($aqJson['hourly']['time'] ?? null) ? $aqJson['hourly']['time'] : [];
$aqPmSeries = is_array($aqJson['hourly']['pm2_5'] ?? null) ? $aqJson['hourly']['pm2_5'] : [];
$aqAodSeries = is_array($aqJson['hourly']['aerosol_optical_depth'] ?? null)
    ? $aqJson['hourly']['aerosol_optical_depth'] : [];

// ── Cloud cover at sunset tonight + next evenings (OWM 3-hourly forecast) ───
$evenings = [];
$tonightSlot = null;
$configured = OWM_API_KEY !== 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE' && OWM_API_KEY !== '';
if ($configured) {
    $raw = photo_cached_get(sprintf(
        'https://api.openweathermap.org/data/2.5/forecast?lat=%F&lon=%F&units=imperial&appid=%s',
        LAT,
        LON,
        OWM_API_KEY
    ), 'owm_forecast_photo_' . sprintf('%F_%F', LAT, LON));
    $fj = $raw ? json_decode($raw, true) : null;
    if ($fj && isset($fj['list']) && is_array($fj['list'])) {
        for ($d = 0; $d < 4; $d++) {
            $dayTs = strtotime("+$d day");
            $sunD = date_sun_info($dayTs, LAT, LON);
            $target = $sunD['sunset'];

            // Prefer the slot nearest sunset; also blend nearby slots so a single
            // 3-hour bucket does not invent phantom cloud structure.
            $near = [];
            foreach ($fj['list'] as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                $gap = abs((int)($slot['dt'] ?? 0) - $target);
                if ($gap <= 10800) {
                    $near[] = ['gap' => $gap, 'slot' => $slot];
                }
            }
            usort($near, static fn($a, $b) => $a['gap'] <=> $b['gap']);
            if ($near === [] || $near[0]['gap'] >= 5400) {
                continue;
            }

            $best = $near[0]['slot'];
            $cloudSamples = [];
            foreach (array_slice($near, 0, 3) as $row) {
                $cloudSamples[] = (int)($row['slot']['clouds']['all'] ?? 0);
            }
            $clouds = (int)round(array_sum($cloudSamples) / max(1, count($cloudSamples)));
            $desc = (string)($best['weather'][0]['description'] ?? '');
            $visM = isset($best['visibility']) ? (float)$best['visibility'] : null;

            $pmAtSunset = photo_nearest_hourly($aqTimes, $aqPmSeries, $target);
            $aodAtSunset = photo_nearest_hourly($aqTimes, $aqAodSeries, $target);
            // Tonight: fall back to current-ish first hourly if sunset lookup missed
            if ($d === 0) {
                if ($pmAtSunset === null && $aqPmSeries !== []) {
                    foreach ($aqPmSeries as $v) {
                        if ($v !== null && $v !== '') {
                            $pmAtSunset = (float)$v;
                            break;
                        }
                    }
                }
                if ($aodAtSunset === null && $aqAodSeries !== []) {
                    foreach ($aqAodSeries as $v) {
                        if ($v !== null && $v !== '') {
                            $aodAtSunset = (float)$v;
                            break;
                        }
                    }
                }
            }

            $smoke = photo_smoke_assessment(
                $best,
                $pmAtSunset,
                $aodAtSunset,
                $visM !== null ? $visM / 1000.0 : null,
                $d === 0 ? $nwsPhotoEvents : []
            );

            $row = [
                'label' => $d === 0 ? 'Tonight' : date('D', $dayTs),
                'clouds' => $clouds,
                'desc' => $desc,
                'sunset' => $sunD['sunset'],
                'smoke' => $smoke,
                'visibility_km' => $visM !== null ? round($visM / 1000.0, 1) : null,
            ];
            $evenings[] = $row;
            if ($d === 0) {
                $tonightSlot = $best;
                $aqPm25 = $pmAtSunset;
                $aqAod = $aodAtSunset;
            }
        }
    }
} else {
    // Without OWM we can still assess smoke from Open-Meteo + NWS for tonight.
    $pmAtSunset = photo_nearest_hourly($aqTimes, $aqPmSeries, $sun['sunset']);
    $aodAtSunset = photo_nearest_hourly($aqTimes, $aqAodSeries, $sun['sunset']);
    $aqPm25 = $pmAtSunset;
    $aqAod = $aodAtSunset;
    $smoke = photo_smoke_assessment(null, $pmAtSunset, $aodAtSunset, null, $nwsPhotoEvents);
    if ($smoke['level'] !== 'none') {
        $evenings[] = [
            'label' => 'Tonight',
            'clouds' => 0,
            'desc' => 'cloud cover unavailable',
            'sunset' => $sun['sunset'],
            'smoke' => $smoke,
            'visibility_km' => null,
        ];
    }
}

// ── Aurora: current Kp + max forecast Kp ────────────────────────────────────
$kpNow = null;
$kpMax = null;
$kRaw = photo_cached_get('https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json', 'kp_now');
if ($kRaw) {
    $kj = json_decode($kRaw, true);
    if (is_array($kj) && count($kj) > 1) {
        $kpNow = (float)end($kj)[1];
    }
}
$fRaw = photo_cached_get('https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json', 'kp_fc');
if ($fRaw) {
    $fjKp = json_decode($fRaw, true);
    if (is_array($fjKp)) {
        foreach (array_slice($fjKp, 1) as $row) {
            $t = strtotime($row[0] . ' UTC');
            if ($t !== false && $t > time() && $t < time() + 86400) {
                $kpMax = max($kpMax ?? 0, (float)$row[1]);
            }
        }
    }
}

// ── Verdict ──────────────────────────────────────────────────────────────────
$smokeTonight = $evenings[0]['smoke'] ?? photo_smoke_assessment(
    $tonightSlot,
    $aqPm25,
    $aqAod,
    isset($evenings[0]['visibility_km']) ? (float)$evenings[0]['visibility_km'] : null,
    $nwsPhotoEvents
);
if ($evenings) {
    [$vTitle, $vWhy, $vColor] = array_values(photo_verdict(
        (int)$evenings[0]['clouds'],
        $smokeTonight,
        (string)$evenings[0]['desc']
    ));
    $verdict = [$vTitle, $vWhy, $vColor];
} else {
    $verdict = ['—', 'Cloud forecast unavailable' . ($configured ? '' : ' — set OWM_API_KEY'), 'var(--mist)'];
}
$aurora = ($kpMax !== null && $kpMax >= 6) || ($kpNow !== null && $kpNow >= 6);

$smokeFillPct = match ((string)($smokeTonight['level'] ?? 'none')) {
    'heavy' => 100,
    'broken' => 55,
    'clear' => 22,
    default => 0,
};
$smokeReasons = $smokeTonight['reasons'] ?? [];
$conditionBits = [];
foreach (array_slice($smokeReasons, 0, 3) as $reason) {
    $conditionBits[] = $reason;
}
$pmLabel = null;
if ($aqPm25 !== null) {
    $pmLabel = 'PM2.5 ' . (abs($aqPm25 - round($aqPm25)) < 0.05
        ? (string)(int)round($aqPm25)
        : (string)round($aqPm25, 1));
    $alreadyHasPm = false;
    foreach ($conditionBits as $bit) {
        if (stripos($bit, 'PM2.5') !== false) {
            $alreadyHasPm = true;
            break;
        }
    }
    if (!$alreadyHasPm) {
        $conditionBits[] = $pmLabel;
    }
}
if (($smokeTonight['level'] ?? 'none') === 'broken') {
    $conditionBits[] = 'Broken / textured tint — often strong photo odds';
} elseif (($smokeTonight['level'] ?? 'none') === 'heavy') {
    $conditionBits[] = 'Heavy smoke tint — deep color likely if the sun can punch through';
} elseif (($smokeTonight['level'] ?? 'none') === 'none' && $evenings) {
    $conditionBits[] = ucfirst((string)$evenings[0]['desc']);
}
$conditionBits = array_values(array_unique($conditionBits));
$outlookNights = array_slice($evenings, 1);

$boardH = signage_frame_height();
$compact = $boardH < 1008;
$rowHead = $compact ? 72 : 84;
$rowOutlook = $compact ? 76 : 92;
$rowFoot = $compact ? 188 : 216;
$padY = $compact ? 16 : 20;
$gap = $compact ? 12 : 16;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Photo Conditions — <?= h(PLACE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; min-height:0; padding:<?= $padY ?>px 28px; display:grid; gap:<?= $gap ?>px;
           grid-template-columns: 1.2fr 1fr;
           grid-template-rows: <?= $rowHead ?>px minmax(0,1fr) <?= $rowOutlook ?>px <?= $rowFoot ?>px minmax(0,auto);
           grid-template-areas: "head head" "verdict sky" "nights nights" "windows windows" "meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 56 : 64 ?>px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 48 : 56 ?>px; color:var(--mist); }

  .verdict { grid-area:verdict; background:var(--harbor); border:1px solid var(--hairline);
             border-radius:14px; padding:<?= $compact ? '24px 28px' : '30px 36px' ?>; display:flex;
             flex-direction:column; min-height:0; overflow:hidden; gap:0; }
  .verdict .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); flex:0 0 auto; }
  .verdict .big { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 72 : 88 ?>px; line-height:1.02; flex:0 0 auto; }
  .verdict .why { font-size:<?= $compact ? 20 : 24 ?>px; color:var(--mist); margin-top:6px; line-height:1.25;
                  flex:0 1 auto; min-height:0; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
  .cloudbar { margin-top:<?= $compact ? 10 : 14 ?>px; flex:0 0 auto; }
  .cloudbar .lab { display:flex; justify-content:space-between; font-size:18px; color:var(--mist); margin-bottom:6px; }
  .cloudbar .track { height:16px; background:var(--lake-night); border:1px solid var(--hairline); border-radius:11px; overflow:hidden; position:relative; }
  .cloudbar .fill { height:100%; background:var(--beacon); border-radius:11px; }
  .cloudbar.smoke .fill { background:linear-gradient(90deg, #ffb347, #e07040); }
  .cloudbar .marks { display:flex; justify-content:space-between; margin-top:4px; font-size:14px; letter-spacing:1px; text-transform:uppercase; color:var(--mist); }
  .conds { margin-top:8px; font-size:<?= $compact ? 16 : 18 ?>px; color:var(--mist); line-height:1.25;
           flex:0 1 auto; min-height:0; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
  .conds strong { color:var(--snow); font-weight:600; }
  .advisories { margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; flex:0 0 auto; }
  .adv { font-size:15px; letter-spacing:1px; text-transform:uppercase; color:var(--beacon);
         border:1px solid rgba(255,179,71,.45); padding:4px 10px; border-radius:8px; }

  .nights { grid-area:nights; display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:18px;
            background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $compact ? '16px 22px' : '18px 26px' ?>; align-content:center; min-height:0; overflow:hidden; }
  .nights.empty { display:flex; align-items:center; }
  .night { min-width:0; }
  .night .d { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 24 : 28 ?>px; letter-spacing:1px; text-transform:uppercase; }
  .night .c { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); text-transform:capitalize; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px; }
  .nights .fallback { font-size:22px; color:var(--mist); }

  .sky { grid-area:sky; display:flex; flex-direction:column; gap:<?= $compact ? 16 : 18 ?>px; min-height:0; }
  .moon { flex:1; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:14px; padding:<?= $compact ? '22px 26px' : '28px 32px' ?>; display:flex;
          flex-direction:column; align-items:center; justify-content:center; min-height:0; overflow:hidden; }
  .moon .k { align-self:flex-start; font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .moon svg { width:<?= $compact ? 180 : 220 ?>px; height:<?= $compact ? 180 : 220 ?>px; margin:<?= $compact ? '8px 0 4px' : '12px 0 6px' ?>; }
  .moon .name { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 44 : 50 ?>px; }
  .moon .pct { font-size:24px; color:var(--mist); margin-top:2px; }

  .aurora-panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
                  padding:<?= $compact ? '18px 22px' : '22px 26px' ?>; flex:0 0 auto; }
  .aurora-panel.watch { border-color:#3d7a52; }
  .aurora-panel .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .aurora-panel .note { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); margin-top:6px; }
  .aurora-panel.watch .note { color:#7ee787; font-weight:600; }
  .aurora-stats { margin-top:<?= $compact ? 12 : 14 ?>px; display:flex; justify-content:space-between; gap:16px;
                  border-top:1px solid var(--hairline); padding-top:<?= $compact ? 12 : 14 ?>px; }
  .aurora-stats div { flex:1; text-align:center; min-width:0; }
  .aurora-stats .kk { font-size:16px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .aurora-stats .kv { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 40 : 46 ?>px;
                       margin-top:2px; font-variant-numeric:tabular-nums; }
  .aurora-stats .kv.hot { color:#7ee787; }

  .windows { grid-area:windows; display:grid; grid-template-columns:repeat(4,1fr); gap:18px; }
  .win { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px; padding:<?= $compact ? '16px 20px' : '20px 24px' ?>; }
  .win.prime { border-color:var(--beacon); }
  .win .k { font-size:18px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .win .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 38 : 44 ?>px; margin-top:4px;
            font-variant-numeric:tabular-nums; }
  .win.prime .v { color:var(--beacon); }
  .win .s { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); margin-top:4px; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Photo Conditions <span>&middot; <?= h(PLACE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <section class="verdict">
    <div class="k">Tonight's Golden Hour</div>
    <div class="big" style="color:<?= $verdict[2] ?>"><?= h($verdict[0]) ?></div>
    <div class="why"><?= h($verdict[1]) ?></div>
    <?php if ($evenings): ?>
      <div class="cloudbar">
        <div class="lab"><span>Cloud cover at sunset</span><span><?= (int)$evenings[0]['clouds'] ?>%</span></div>
        <div class="track"><div class="fill" style="width:<?= (int)$evenings[0]['clouds'] ?>%"></div></div>
      </div>
      <div class="cloudbar smoke">
        <div class="lab"><span>Color potential · smoke tint</span><span><?= h((string)($smokeTonight['label'] ?? 'CLEAR')) ?></span></div>
        <div class="track"><div class="fill" style="width:<?= (int)$smokeFillPct ?>%"></div></div>
        <div class="marks"><span>Clear</span><span>Broken</span><span>Heavy</span></div>
      </div>
      <?php if ($conditionBits !== []): ?>
        <div class="conds"><?= h(implode(' · ', $conditionBits)) ?></div>
      <?php endif; ?>
      <?php if ($nwsPhotoEvents !== []): ?>
        <div class="advisories">
          <?php foreach ($nwsPhotoEvents as $ev): ?>
            <span class="adv"><?= h($ev) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="nights<?= $outlookNights === [] ? ' empty' : '' ?>">
    <?php if ($outlookNights === []): ?>
      <div class="fallback">Forecast outlook unavailable<?= $configured ? '' : ' — set OWM_API_KEY' ?></div>
    <?php else: ?>
      <?php foreach ($outlookNights as $e): ?>
        <div class="night">
          <div class="d"><?= h($e['label']) ?> &middot; <?= (int)$e['clouds'] ?>%</div>
          <div class="c"><?= h($e['desc']) ?><?php
            $sl = (string)($e['smoke']['level'] ?? 'none');
            if ($sl !== 'none') {
                echo ' · smoke ' . h((string)$e['smoke']['label']);
            }
          ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <div class="sky">
  <section class="moon">
    <div class="k">Moon</div>
    <svg viewBox="0 0 100 100">
      <?php
        $r = 46;
        $k = cos(2 * M_PI * $phaseFrac);
        $waxing = $phaseFrac < 0.5;
        $lit = 'var(--snow)';
        $dark = '#1b2840';
      ?>
      <circle cx="50" cy="50" r="<?= $r ?>" fill="<?= $dark ?>"/>
      <path d="M 50 4
               A <?= $r ?> <?= $r ?> 0 0 <?= $waxing ? 1 : 0 ?> 50 96
               A <?= abs($k) * $r ?> <?= $r ?> 0 0 <?= ($k < 0 ? ($waxing?1:0) : ($waxing?0:1)) ?> 50 4 Z"
            fill="<?= $lit ?>"/>
      <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="var(--hairline)" stroke-width="1.5"/>
    </svg>
    <div class="name"><?= h($phaseName) ?></div>
    <div class="pct"><?= (int)round($illum * 100) ?>% illuminated</div>
  </section>

  <section class="aurora-panel<?= $aurora ? ' watch' : '' ?>">
    <div class="k">Aurora</div>
    <div class="note"><?= $aurora
        ? 'Watch — Kp ' . number_format(max($kpMax ?? 0, $kpNow ?? 0), 1) . '. Northern lights possible on the north horizon after dark.'
        : 'Geomagnetic activity (planetary K-index). Michigan may see aurora at Kp 6+.' ?></div>
    <div class="aurora-stats">
      <div>
        <div class="kk">Kp now</div>
        <div class="kv<?= $aurora && $kpNow !== null && $kpNow >= 6 ? ' hot' : '' ?>"><?= $kpNow !== null ? number_format($kpNow, 1) : '—' ?></div>
      </div>
      <div>
        <div class="kk">Kp next 24h</div>
        <div class="kv<?= $aurora ? ' hot' : '' ?>"><?= $kpMax !== null ? number_format($kpMax, 1) : '—' ?></div>
      </div>
      <div>
        <div class="kk">MI threshold</div>
        <div class="kv">6+</div>
      </div>
    </div>
  </section>
  </div>

  <section class="windows">
    <div class="win"><div class="k">Blue Hour AM</div><div class="v"><?= tspan($blueAm) ?></div>
      <div class="s">Civil twilight to sunrise</div></div>
    <div class="win"><div class="k">Golden Hour AM</div><div class="v"><?= tspan($goldenAm) ?></div>
      <div class="s">Sunrise <?= date('g:i A', $sun['sunrise']) ?></div></div>
    <div class="win prime"><div class="k">Golden Hour PM</div><div class="v"><?= tspan($goldenPm) ?></div>
      <div class="s">Sunset <?= date('g:i A', $sun['sunset']) ?></div></div>
    <div class="win"><div class="k">Blue Hour PM</div><div class="v"><?= tspan($bluePm) ?></div>
      <div class="s">Sunset to end of civil twilight</div></div>
  </section>
  <div class="stamp">OpenWeatherMap · Open-Meteo AQ · NWS · NOAA SWPC<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(() => location.reload(), 15 * 60 * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
