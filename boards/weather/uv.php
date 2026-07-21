<?php
/**
 * UV INDEX — 1920×1080 signage
 * Current UV, today's hourly curve, and multi-day peak forecast.
 *
 * Data: Open-Meteo Forecast API (free, no key).
 * https://open-meteo.com/en/docs
 *
 * Configure lat/lon and place name in admin.php → UV Index.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';

$SCREEN = signage_request_screen();
$LOC = rotation_screen_location($SCREEN);

define('TITLE', cfg('uv.TITLE', 'UV Index'));
define('PLACE', $LOC['place']);
define('LAT', $LOC['lat']);
define('LON', $LOC['lon']);
define('TIMEZONE', cfg('uv.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('uv.RELOAD_SEC', 900));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('uv.CACHE_TTL', 900));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function uv_cached_json(string $url, string $key): ?array
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
        CURLOPT_USERAGENT => 'HomeSignage/UVBoard/1.0',
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

/** WHO UV index band → label, color, short advice. */
function uv_band(?float $uv): array
{
    if ($uv === null) return ['—', 'var(--mist)', 'UV data unavailable'];
    $uv = max(0.0, $uv);
    if ($uv < 3)  return ['Low', '#39c46d', 'No protection needed for most people'];
    if ($uv < 6)  return ['Moderate', 'var(--beacon)', 'Wear SPF 30+ if outside more than 30 minutes'];
    if ($uv < 8)  return ['High', '#ff9d4d', 'Seek shade 10am–4pm — hat, sunglasses, sunscreen'];
    if ($uv < 11) return ['Very high', '#ff5d5d', 'Minimize midday sun — reapply sunscreen every 2 hours'];
    return ['Extreme', '#c850ff', 'Avoid midday sun — skin burns in minutes'];
}

function uv_day_key(string $iso): string
{
    return substr($iso, 0, 10);
}

/** @return list<array{time:string,hour:int,label:string,uv:?float}> */
function uv_hours_for_day(array $hourly, string $dayKey): array
{
    $out = [];
    $times = $hourly['time'] ?? [];
    $vals = $hourly['uv_index'] ?? [];
    foreach ($times as $i => $t) {
        $iso = (string)$t;
        if (uv_day_key($iso) !== $dayKey) continue;
        $uv = isset($vals[$i]) && $vals[$i] !== null ? (float)$vals[$i] : null;
        $ts = strtotime($iso);
        $out[] = [
            'time' => $iso,
            'hour' => (int)date('G', $ts),
            'label' => date('ga', $ts),
            'uv' => $uv,
        ];
    }
    return $out;
}

/** @return array{uv:?float,time:?string,label:string} */
function uv_peak(array $hours): array
{
    $best = null;
    $bestUv = null;
    foreach ($hours as $slot) {
        if ($slot['uv'] === null) continue;
        if ($bestUv === null || $slot['uv'] > $bestUv) {
            $bestUv = $slot['uv'];
            $best = $slot;
        }
    }
    if ($best === null) {
        return ['uv' => null, 'time' => null, 'label' => '—'];
    }
    return ['uv' => $bestUv, 'time' => $best['time'], 'label' => $best['label']];
}

function uv_format_clock(?string $iso): string
{
    if ($iso === null || $iso === '') return '—';
    return date('g:i A', strtotime($iso));
}

function uv_verdict(?float $uvNow, bool $isDay, ?float $maxToday, ?float $maxTomorrow): array
{
    if (!$isDay && ($uvNow === null || $uvNow < 0.5)) {
        if ($maxTomorrow !== null && $maxTomorrow >= 6) {
            return ['Night now', 'High UV expected tomorrow — plan shade and sunscreen', 'var(--beacon)'];
        }
        return ['Night now', 'Low UV after dark — tomorrow\'s peak shown in outlook', 'var(--mist)'];
    }
    [, $color, $hint] = uv_band($uvNow);
    if ($uvNow !== null && $uvNow >= 8) {
        return ['Strong sun', $hint, $color];
    }
    if ($maxToday !== null && $maxToday >= 8) {
        return ['Peak UV high today', $hint, $color];
    }
    if ($uvNow !== null && $uvNow >= 3) {
        return ['Use sunscreen', $hint, $color];
    }
    return ['Low UV', $hint, $color];
}

// ── Fetch Open-Meteo forecast ────────────────────────────────────────────────
$cacheKey = 'openmeteo_uv_' . md5(sprintf('%.4F_%.4F_%s', LAT, LON, TIMEZONE));
$query = http_build_query([
    'latitude' => LAT,
    'longitude' => LON,
    'timezone' => TIMEZONE,
    'forecast_days' => 7,
    'current' => 'uv_index,is_day',
    'hourly' => 'uv_index,uv_index_clear_sky',
    'daily' => 'uv_index_max,uv_index_clear_sky_max,sunrise,sunset',
]);
$data = uv_cached_json('https://api.open-meteo.com/v1/forecast?' . $query, $cacheKey);

$current = is_array($data['current'] ?? null) ? $data['current'] : [];
$hourly  = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
$daily   = is_array($data['daily'] ?? null) ? $data['daily'] : [];
$hasData = $current !== [] || $hourly !== [];

$uvNow = isset($current['uv_index']) ? round((float)$current['uv_index'], 1) : null;
$isDay = (bool)($current['is_day'] ?? true);
[$uvLabel, $uvColor, $uvHint] = uv_band($uvNow);

$todayKey = date('Y-m-d');
$todayHours = uv_hours_for_day($hourly, $todayKey);
$peakToday = uv_peak($todayHours);

$sunrise = $daily['sunrise'][0] ?? null;
$sunset = $daily['sunset'][0] ?? null;
$maxToday = isset($daily['uv_index_max'][0]) ? round((float)$daily['uv_index_max'][0], 1) : null;
$clearToday = isset($daily['uv_index_clear_sky_max'][0]) ? round((float)$daily['uv_index_clear_sky_max'][0], 1) : null;

$chartHours = [];
foreach ($todayHours as $slot) {
    if ($sunrise !== null && strtotime($slot['time']) < strtotime((string)$sunrise)) continue;
    if ($sunset !== null && strtotime($slot['time']) > strtotime((string)$sunset)) continue;
    $chartHours[] = $slot;
}

$forecast = [];
$dayTimes = $daily['time'] ?? [];
foreach ($dayTimes as $i => $dayKey) {
    $maxUv = isset($daily['uv_index_max'][$i]) ? round((float)$daily['uv_index_max'][$i], 1) : null;
    $clearMax = isset($daily['uv_index_clear_sky_max'][$i]) ? round((float)$daily['uv_index_clear_sky_max'][$i], 1) : null;
    [$bandLabel, $bandColor] = uv_band($maxUv);
    $label = $dayKey === $todayKey ? 'Today'
        : ($dayKey === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('D', strtotime($dayKey . ' 12:00:00')));
    $forecast[] = [
        'label' => $label,
        'max' => $maxUv,
        'clear' => $clearMax,
        'band' => $bandLabel,
        'color' => $bandColor,
        'sunrise' => uv_format_clock($daily['sunrise'][$i] ?? null),
        'sunset' => uv_format_clock($daily['sunset'][$i] ?? null),
    ];
}
$forecast = array_slice($forecast, 0, 5);

$maxTomorrow = $forecast[1]['max'] ?? null;
[$verdictTitle, $verdictSub, $verdictColor] = uv_verdict($uvNow, $isDay, $maxToday, $maxTomorrow);

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowMain = max(300, (int)round(360 * $boardH / 1080));
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
           grid-template-columns: 1.1fr 0.9fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowMain ?>px <?= $rowMid ?>px auto auto;
           grid-template-areas:
             "head head head"
             "current sun forecast"
             "hourly hourly forecast"
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

  .current { grid-area:current; display:flex; flex-direction:column; justify-content:space-between; }
  .current .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 148 : 180 ?>px;
                  line-height:1; font-variant-numeric:tabular-nums; color:<?= h($uvColor) ?>; }
  .current .band { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 38 : 46 ?>px;
                   letter-spacing:2px; text-transform:uppercase; color:<?= h($uvColor) ?>; margin-top:8px; }
  .current .hint { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); margin-top:10px; line-height:1.4; max-width:720px; }
  .current .night { font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--mist); margin-top:8px; }

  .sun { grid-area:sun; display:grid; grid-template-columns:1fr 1fr; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; align-content:start; }
  .stat { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $boardH < 1080 ? '16px 18px' : '20px 22px' ?>; min-height:0; }
  .stat .lab { font-size:16px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:8px; }
  .stat .val { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
               line-height:1.1; color:var(--snow); font-variant-numeric:tabular-nums; }
  .stat .sub { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; color:var(--mist); margin-top:6px; }

  .hourly { grid-area:hourly; display:flex; flex-direction:column; min-height:0; }
  .chart { flex:1; min-height:0; display:flex; align-items:flex-end; gap:<?= $chartHours !== [] && count($chartHours) > 14 ? 6 : 10 ?>px;
           padding-top:8px; border-top:1px solid var(--hairline); margin-top:6px; }
  .bar-wrap { flex:1; min-width:0; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:8px; height:100%; }
  .bar { width:100%; max-width:56px; min-height:4px; border-radius:8px 8px 4px 4px; transition:height .2s; }
  .bar-wrap .hr { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; color:var(--mist); font-family:'IBM Plex Mono',monospace; }
  .bar-wrap .uv { font-size:<?= $boardH < 1080 ? 13 : 14 ?>px; color:var(--snow); font-weight:600; font-variant-numeric:tabular-nums; }
  .bar-wrap.now .hr { color:var(--beacon); font-weight:600; }
  .chart-empty { font-size:20px; color:var(--mist); padding:24px 0; }

  .forecast { grid-area:forecast; display:flex; flex-direction:column; min-height:0; }
  .forecast .days { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; }
  .fday { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
          padding:<?= $boardH < 1080 ? '12px 14px' : '14px 16px' ?>; display:grid;
          grid-template-columns:minmax(<?= $boardH < 1080 ? 96 : 108 ?>px, max-content) minmax(0, 1fr) auto;
          gap:<?= $boardH < 1080 ? 10 : 12 ?>px 14px; align-items:center; }
  .fday .d { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; letter-spacing:<?= $boardH < 1080 ? 1.5 : 2 ?>px;
             text-transform:uppercase; color:var(--mist); white-space:nowrap; }
  .fday .max { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 34 : 40 ?>px; line-height:1; }
  .fday .line { font-size:<?= $boardH < 1080 ? 14 : 16 ?>px; color:var(--mist); margin-top:4px; white-space:nowrap; }
  .fday .band { font-size:<?= $boardH < 1080 ? 14 : 16 ?>px; font-weight:600; text-transform:uppercase; letter-spacing:1px;
                text-align:right; white-space:nowrap; padding-left:8px; }

  .verdict { grid-area:verdict; border-radius:14px; border:1px solid var(--hairline);
             padding:<?= $boardH < 1080 ? '18px 24px' : '22px 32px' ?>; display:flex;
             align-items:baseline; justify-content:space-between; gap:24px;
             background:linear-gradient(90deg, rgba(20,31,51,.95), rgba(12,20,34,.95)); }
  .verdict .t { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 40 : 48 ?>px;
                color:<?= h($verdictColor) ?>; letter-spacing:1px; }
  .verdict .s { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); text-align:right; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
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
  <section class="panel current">
    <div class="k">Right now<?= $isDay ? '' : ' · night' ?></div>
    <div>
      <div class="num"><?= $uvNow !== null ? h((string)$uvNow) : '—' ?></div>
      <div class="band"><?= h($uvLabel) ?></div>
      <div class="hint"><?= h($uvHint) ?></div>
      <?php if (!$isDay && $peakToday['uv'] !== null): ?>
      <div class="night">Today's peak was <?= h((string)round($peakToday['uv'], 1)) ?> around <?= h($peakToday['label']) ?></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel sun">
    <div class="stat">
      <div class="lab">Peak today</div>
      <div class="val" style="color:<?= h(uv_band($maxToday)[1]) ?>"><?= $maxToday ?? '—' ?></div>
      <div class="sub"><?= $peakToday['uv'] !== null ? 'Around ' . h($peakToday['label']) : '—' ?></div>
    </div>
    <div class="stat">
      <div class="lab">Clear-sky max</div>
      <div class="val"><?= $clearToday ?? '—' ?></div>
      <div class="sub">Without cloud cover</div>
    </div>
    <div class="stat">
      <div class="lab">Sunrise</div>
      <div class="val" style="font-size:<?= $boardH < 1080 ? 36 : 42 ?>px"><?= h(uv_format_clock(is_string($sunrise) ? $sunrise : null)) ?></div>
    </div>
    <div class="stat">
      <div class="lab">Sunset</div>
      <div class="val" style="font-size:<?= $boardH < 1080 ? 36 : 42 ?>px"><?= h(uv_format_clock(is_string($sunset) ? $sunset : null)) ?></div>
    </div>
  </section>

  <section class="panel forecast">
    <div class="k">Daily peak · 5 days</div>
    <div class="days">
    <?php foreach ($forecast as $fd): ?>
    <div class="fday">
      <div class="d"><?= h($fd['label']) ?></div>
      <div>
        <div class="max" style="color:<?= h($fd['color']) ?>"><?= $fd['max'] ?? '—' ?></div>
        <div class="line"><?= h($fd['sunrise']) ?> – <?= h($fd['sunset']) ?></div>
      </div>
      <div class="band" style="color:<?= h($fd['color']) ?>"><?= h($fd['band']) ?></div>
    </div>
    <?php endforeach; ?>
    </div>
  </section>

  <section class="panel hourly">
    <div class="k">Today · sunrise to sunset</div>
    <?php if ($chartHours !== []): ?>
    <div class="chart">
      <?php
      $nowHour = (int)date('G');
      foreach ($chartHours as $slot):
          $uv = $slot['uv'];
          $pct = $uv !== null ? max(4, (int)round($uv / 11 * 100)) : 4;
          [, $barColor] = uv_band($uv);
          $isNow = $slot['hour'] === $nowHour;
      ?>
      <div class="bar-wrap<?= $isNow ? ' now' : '' ?>">
        <div class="uv"><?= $uv !== null ? h((string)round($uv, 1)) : '' ?></div>
        <div class="bar" style="height:<?= $pct ?>%;background:<?= h($barColor) ?>"></div>
        <div class="hr"><?= h($slot['label']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="chart-empty">Hourly UV curve unavailable</div>
    <?php endif; ?>
  </section>

  <div class="verdict">
    <span class="t"><?= h($verdictTitle) ?></span>
    <span class="s"><?= h($verdictSub) ?></span>
  </div>
  <?php else: ?>
  <section class="panel current" style="grid-column:1/-1">
    <div class="notcfg">UV forecast unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['openmeteo'] ?? '') : '' ?>.
      Check network access to <code>api.open-meteo.com</code> or try again shortly.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Open-Meteo Forecast',
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
    const m = String(n.getMinutes()).padStart(2, '0');
    const el = document.getElementById('clock');
    if (el) el.textContent = h + ':' + m + ' ' + ap;
  }
  tick();
  setInterval(tick, 1000);
  <?php endif; ?>
  <?php if (!$embedded && RELOAD_SEC > 0): ?>
  setTimeout(() => location.reload(), <?= (int)RELOAD_SEC * 1000 ?>);
  <?php endif; ?>
</script>
</body>
</html>
