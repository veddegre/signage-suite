<?php
/**
 * AIR & POLLEN — 1920×1080 signage
 * US AQI, PM2.5/PM10, ozone, and pollen levels for your location.
 *
 * Data: Open-Meteo Air Quality API (free, no API key).
 *   https://open-meteo.com/en/docs/air-quality-api
 *
 * Configure lat/lon and place name in admin.php → Air & Pollen.
 */

require_once __DIR__ . '/config.php';

define('TITLE', cfg('air.TITLE', 'Air & Pollen'));
define('PLACE', cfg('air.PLACE', 'West Michigan'));
define('LAT', cfg('air.LAT', 42.9720));
define('LON', cfg('air.LON', -85.9536));
define('TIMEZONE', cfg('air.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('air.RELOAD_SEC', 900));
const CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('air.CACHE_TTL', 900));

date_default_timezone_set(TIMEZONE);
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function air_cached_json(string $url, string $key): ?array
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
    $GLOBALS['diag']['openmeteo'] = $err !== '' ? "curl: $err" : "HTTP $code";
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/** EPA US AQI band → label, accent color, short advice. */
function air_aqi_band(?int $aqi): array
{
    if ($aqi === null) return ['—', 'var(--mist)', 'Air quality data unavailable'];
    if ($aqi <= 50)  return ['Good', '#39c46d', 'Air is clean — open windows freely'];
    if ($aqi <= 100) return ['Moderate', 'var(--beacon)', 'Acceptable for most — unusually sensitive people take it easy'];
    if ($aqi <= 150) return ['Sensitive', '#ff9d4d', 'Unhealthy for sensitive groups — limit prolonged outdoor exertion'];
    if ($aqi <= 200) return ['Unhealthy', '#ff5d5d', 'Everyone may feel effects — keep windows closed'];
    if ($aqi <= 300) return ['Very unhealthy', '#c850ff', 'Health alert — minimize outdoor time'];
    return ['Hazardous', '#7a001a', 'Emergency conditions — stay indoors'];
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

function air_pollen_rows(array $hourly, string $dayKey): array
{
    $tree = max(
        air_day_max($hourly, 'birch_pollen', $dayKey) ?? 0,
        air_day_max($hourly, 'alder_pollen', $dayKey) ?? 0
    );
    $types = [
        ['name' => 'Grass', 'val' => air_day_max($hourly, 'grass_pollen', $dayKey)],
        ['name' => 'Ragweed', 'val' => air_day_max($hourly, 'ragweed_pollen', $dayKey)],
        ['name' => 'Tree', 'val' => $tree > 0 ? $tree : null],
    ];
    usort($types, fn($a, $b) => ($b['val'] ?? 0) <=> ($a['val'] ?? 0));
    return $types;
}

function air_verdict(?int $aqi, array $pollenRows): array
{
    $maxPollen = 0.0;
    foreach ($pollenRows as $p) {
        $maxPollen = max($maxPollen, (float)($p['val'] ?? 0));
    }
    $aqiBad = $aqi !== null && $aqi > 100;
    $aqiWarn = $aqi !== null && $aqi > 50;
    $pollenBad = $maxPollen >= 50;
    $pollenWarn = $maxPollen >= 10;

    if ($aqi !== null && $aqi > 150) {
        return ['Keep windows closed', 'Poor air quality — limit time outside', '#ff5d5d'];
    }
    if ($maxPollen >= 200) {
        return ['High pollen', 'Close windows — allergy sufferers stay indoors', '#ff5d5d'];
    }
    if ($aqiBad || $pollenBad) {
        return ['Take it easy outdoors', 'Elevated air or pollen — sensitive groups use caution', 'var(--beacon)'];
    }
    if ($aqiWarn || $pollenWarn) {
        return ['Mostly fine', 'OK for most people — watch symptoms if you are sensitive', 'var(--beacon)'];
    }
    if ($aqi !== null) {
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
    'current' => 'us_aqi,pm2_5,pm10,ozone,nitrogen_dioxide',
    'hourly' => 'us_aqi,pm2_5,ragweed_pollen,grass_pollen,birch_pollen,alder_pollen',
]);
$data = air_cached_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $query, $cacheKey);

$current = is_array($data['current'] ?? null) ? $data['current'] : [];
$hourly  = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
$hasData = $current !== [] || $hourly !== [];

$aqiNow = isset($current['us_aqi']) ? (int)round((float)$current['us_aqi']) : null;
[$aqiLabel, $aqiColor, $aqiHint] = air_aqi_band($aqiNow);
$pm25 = isset($current['pm2_5']) ? round((float)$current['pm2_5'], 1) : null;
$pm10 = isset($current['pm10']) ? round((float)$current['pm10'], 1) : null;
$ozone = isset($current['ozone']) ? round((float)$current['ozone'], 0) : null;
$no2 = isset($current['nitrogen_dioxide']) ? round((float)$current['nitrogen_dioxide'], 1) : null;

$todayKey = date('Y-m-d');
$pollenToday = air_pollen_rows($hourly, $todayKey);
$pollenMax = 0.0;
foreach ($pollenToday as $p) {
    $pollenMax = max($pollenMax, (float)($p['val'] ?? 0));
}
$pollenBarPct = min(100, (int)round($pollenMax / 2)); // 200 grains ≈ full bar

$forecastDays = air_forecast_days($hourly, 3);
$forecast = [];
foreach ($forecastDays as $i => $dayKey) {
    $dayAqi = air_day_max($hourly, 'us_aqi', $dayKey);
    $dayPm = air_day_max($hourly, 'pm2_5', $dayKey);
    $dayPollen = air_pollen_rows($hourly, $dayKey);
    $topPollen = $dayPollen[0]['name'] ?? '—';
    $topVal = $dayPollen[0]['val'] ?? null;
    [$pLabel] = air_pollen_band($topVal);
    $label = $dayKey === $todayKey ? 'Today'
        : ($dayKey === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D', strtotime($dayKey . ' 12:00:00')));
    $forecast[] = [
        'label' => $label,
        'aqi' => $dayAqi !== null ? (int)round($dayAqi) : null,
        'pm25' => $dayPm !== null ? round($dayPm, 1) : null,
        'pollen' => $topPollen,
        'pollen_level' => $pLabel,
    ];
}

[$verdictTitle, $verdictSub, $verdictColor] = air_verdict($aqiNow, $pollenToday);

$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = $embedded
    ? $boardH . 'px'
    : 'calc(1080px - var(--signage-ticker-inset, 0px))';
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowAqi  = max(300, (int)round(360 * $boardH / 1080));
$rowMid  = max(240, (int)round(300 * $boardH / 1080));
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

  .forecast { grid-area:forecast; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 16 ?>px; }
  .fday { flex:1; background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $boardH < 1080 ? '14px 16px' : '18px 20px' ?>; min-height:0; }
  .fday .d { font-size:17px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:10px; }
  .fday .aqi-num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 42 : 50 ?>px;
               color:var(--beacon); line-height:1; }
  .fday .line { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); margin-top:8px; }

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
    <div id="clock">--:--</div>
  </div>

  <?php if ($hasData): ?>
  <section class="panel aqi-panel">
    <div class="k">US Air Quality Index</div>
    <div>
      <div class="num"><?= $aqiNow ?? '—' ?></div>
      <div class="band"><?= h($aqiLabel) ?></div>
      <div class="hint"><?= h($aqiHint) ?></div>
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
    <div class="k">Pollen today · grains/m³</div>
    <?php foreach ($pollenToday as $p):
      $val = $p['val'];
      [$pLabel, $pColor] = air_pollen_band($val);
      $pct = $val !== null ? min(100, (int)round((float)$val / 2)) : 0;
      $hot = $val !== null && (float)$val >= 50;
    ?>
    <div class="prow">
      <span class="n"><?= h($p['name']) ?></span>
      <div class="track"><div class="fill<?= $hot ? ' hot' : '' ?>" style="width:<?= $pct ?>%;background:<?= h($pColor) ?>"></div></div>
      <span class="c"><?= $val !== null ? h((string)round((float)$val, 1)) : '—' ?></span>
      <span class="lvl" style="color:<?= h($pColor) ?>"><?= h($pLabel) ?></span>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="panel forecast">
    <div class="k">Outlook</div>
    <?php foreach ($forecast as $fd):
      [, $fdColor] = air_aqi_band($fd['aqi']);
    ?>
    <div class="fday">
      <div class="d"><?= h($fd['label']) ?></div>
      <div class="aqi-num" style="color:<?= h($fdColor) ?>">AQI <?= $fd['aqi'] ?? '—' ?></div>
      <div class="line">PM2.5 <?= $fd['pm25'] ?? '—' ?> · <?= h($fd['pollen']) ?> <?= h($fd['pollen_level']) ?></div>
    </div>
    <?php endforeach; ?>
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

  <div class="stamp">Open-Meteo Air Quality<?= $GLOBALS['diag'] ? ' · ' . h($GLOBALS['diag']['openmeteo']) : '' ?></div>
</div>
<script>
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
  <?php if (!$embedded): ?>
  setTimeout(() => location.reload(), <?= max(60, (int)RELOAD_SEC) ?> * 1000);
  <?php endif; ?>
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
