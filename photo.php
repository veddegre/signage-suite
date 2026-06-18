<?php
/**
 * PHOTO CONDITIONS — 1920×1080 signage
 * "Should I grab a camera tonight?" — golden/blue hour windows, cloud cover at
 * sunset, moon phase + illumination, and aurora Kp index for West Michigan.
 *
 * Setup: set OWM_API_KEY (same key as the weather board). NOAA SWPC needs no key.
 */

require_once __DIR__ . '/config.php';

define('OWM_API_KEY', cfg('photo.OWM_API_KEY', 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE'));
define('LAT', cfg('photo.LAT', 42.9720));
define('LON', cfg('photo.LON', -85.9536));
define('PLACE', cfg('photo.PLACE', 'West Michigan'));
define('TIMEZONE', cfg('photo.TIMEZONE', 'America/Detroit'));
const CACHE_DIR   = __DIR__ . '/cache';
define('CACHE_TTL', cfg('photo.CACHE_TTL', 900));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function cached_get(string $url, string $key): ?string
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) return (string)file_get_contents($f);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>10, CURLOPT_USERAGENT=>'HomeSignage/1.0']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Sun geometry ─────────────────────────────────────────────────────────────
$sun = date_sun_info(time(), LAT, LON);
$goldenAm = [$sun['sunrise'], $sun['sunrise'] + 3600];
$goldenPm = [$sun['sunset'] - 3600, $sun['sunset']];
$bluePm   = [$sun['sunset'], $sun['civil_twilight_end']];
$blueAm   = [$sun['civil_twilight_begin'], $sun['sunrise']];

// ── Moon phase (synodic approximation, good to ~hours) ──────────────────────
$synodic = 29.530588853;
$daysSinceNew = fmod((time() - 947182440) / 86400, $synodic);   // 2000-01-06 18:14 UTC new moon
if ($daysSinceNew < 0) $daysSinceNew += $synodic;
$phaseFrac = $daysSinceNew / $synodic;                          // 0=new .5=full
$illum = (1 - cos(2 * M_PI * $phaseFrac)) / 2;
$phaseNames = [
    [0.0325,'New Moon'], [0.2175,'Waxing Crescent'], [0.2825,'First Quarter'],
    [0.4675,'Waxing Gibbous'], [0.5325,'Full Moon'], [0.7175,'Waning Gibbous'],
    [0.7825,'Last Quarter'], [0.9675,'Waning Crescent'], [1.01,'New Moon'],
];
$phaseName = 'Moon';
foreach ($phaseNames as [$lim, $name]) { if ($phaseFrac <= $lim) { $phaseName = $name; break; } }

// ── Cloud cover at sunset tonight + next evenings (OWM 3-hourly forecast) ───
$evenings = [];      // [label, clouds%, desc]
$configured = OWM_API_KEY !== 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE' && OWM_API_KEY !== '';
if ($configured) {
    $raw = cached_get(sprintf(
        'https://api.openweathermap.org/data/2.5/forecast?lat=%F&lon=%F&units=imperial&appid=%s',
        LAT, LON, OWM_API_KEY), 'owm_forecast_photo_' . sprintf('%F_%F', LAT, LON));
    $fj = $raw ? json_decode($raw, true) : null;
    if ($fj && isset($fj['list'])) {
        for ($d = 0; $d < 4; $d++) {
            $dayTs  = strtotime("+$d day");
            $sunD   = date_sun_info($dayTs, LAT, LON);
            $target = $sunD['sunset'];
            $best = null; $bestGap = PHP_INT_MAX;
            foreach ($fj['list'] as $slot) {
                $gap = abs($slot['dt'] - $target);
                if ($gap < $bestGap) { $bestGap = $gap; $best = $slot; }
            }
            if ($best && $bestGap < 5400) {
                $evenings[] = [
                    'label'  => $d === 0 ? 'Tonight' : date('D', $dayTs),
                    'clouds' => (int)($best['clouds']['all'] ?? 0),
                    'desc'   => $best['weather'][0]['description'] ?? '',
                    'sunset' => $sunD['sunset'],
                ];
            }
        }
    }
}

// ── Aurora: current Kp + max forecast Kp ────────────────────────────────────
$kpNow = null; $kpMax = null;
$kRaw = cached_get('https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json', 'kp_now');
if ($kRaw) { $kj = json_decode($kRaw, true); if (is_array($kj) && count($kj) > 1) $kpNow = (float)end($kj)[1]; }
$fRaw = cached_get('https://services.swpc.noaa.gov/products/noaa-planetary-k-index-forecast.json', 'kp_fc');
if ($fRaw) {
    $fj = json_decode($fRaw, true);
    if (is_array($fj)) {
        foreach (array_slice($fj, 1) as $row) {
            $t = strtotime($row[0] . ' UTC');
            if ($t !== false && $t > time() && $t < time() + 86400) $kpMax = max($kpMax ?? 0, (float)$row[1]);
        }
    }
}

// ── Verdict ──────────────────────────────────────────────────────────────────
$verdict = ['—', 'Cloud forecast unavailable', 'var(--mist)'];
if ($evenings) {
    $c = $evenings[0]['clouds'];
    if     ($c <= 20)              $verdict = ['CLEAN LIGHT', 'Clear horizon — crisp golden hour, minimal drama', '#39c46d'];
    elseif ($c <= 70)              $verdict = ['DRAMATIC SKY', 'Broken clouds — best odds for a painted sunset', 'var(--beacon)'];
    elseif ($c <= 85)              $verdict = ['MARGINAL', 'Mostly cloudy — maybe a break at the horizon', 'var(--mist)'];
    else                           $verdict = ['FLAT GRAY', 'Overcast — good night for editing instead', '#ff5d5d'];
}
$aurora = ($kpMax !== null && $kpMax >= 6) || ($kpNow !== null && $kpNow >= 6);

function tspan(array $w): string { return date('g:i', $w[0]) . '–' . date('g:i A', $w[1]); }

$frameH = signage_frame_height();
$compact = $frameH < 1080;
$rowHead = $compact ? 88 : 96;
$rowFoot = $compact ? 248 : 280;
$padY = $compact ? 24 : 28;
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
  .board { width:1920px; height:100%; padding:<?= $padY ?>px 32px; display:grid; gap:<?= $compact ? 20 : 24 ?>px;
           grid-template-columns: 1.2fr 1fr; grid-template-rows: <?= $rowHead ?>px minmax(0,1fr) <?= $rowFoot ?>px auto;
           grid-template-areas: "head head" "verdict sky" "windows windows" "meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .verdict { grid-area:verdict; background:var(--harbor); border:1px solid var(--hairline);
             border-radius:14px; padding:<?= $compact ? '32px 36px' : '40px 44px' ?>; display:flex;
             flex-direction:column; min-height:0; overflow:hidden; }
  .verdict .k { font-size:22px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .verdict .big { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 108 : 120 ?>px; line-height:1.05; }
  .verdict .why { font-size:<?= $compact ? 26 : 30 ?>px; color:var(--mist); margin-top:10px; }
  .cloudbar { margin-top:<?= $compact ? 24 : 34 ?>px; }
  .cloudbar .lab { display:flex; justify-content:space-between; font-size:22px; color:var(--mist); margin-bottom:10px; }
  .cloudbar .track { height:22px; background:var(--lake-night); border:1px solid var(--hairline); border-radius:11px; overflow:hidden; }
  .cloudbar .fill { height:100%; background:var(--beacon); border-radius:11px; }
  .nights { margin-top:auto; display:flex; gap:18px; border-top:1px solid var(--hairline); padding-top:<?= $compact ? 18 : 24 ?>px; }
  .night { flex:1; min-width:0; }
  .night .d { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 28 : 32 ?>px; letter-spacing:1px; text-transform:uppercase; }
  .night .c { font-size:<?= $compact ? 21 : 24 ?>px; color:var(--mist); text-transform:capitalize; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

  .sky { grid-area:sky; display:flex; flex-direction:column; gap:<?= $compact ? 18 : 24 ?>px; min-height:0; }
  .moon { flex:1; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:14px; padding:<?= $compact ? '28px 32px' : '36px 40px' ?>; display:flex;
          flex-direction:column; align-items:center; justify-content:center; min-height:0; overflow:hidden; }
  .moon .k { align-self:flex-start; font-size:22px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .moon svg { width:<?= $compact ? 240 : 280 ?>px; height:<?= $compact ? 240 : 280 ?>px; margin:<?= $compact ? '12px 0 6px' : '18px 0 8px' ?>; }
  .moon .name { font-family:'Big Shoulders Display'; font-weight:700; font-size:54px; }
  .moon .pct { font-size:28px; color:var(--mist); margin-top:4px; }

  .aurora-panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
                  padding:<?= $compact ? '22px 28px' : '28px 32px' ?>; }
  .aurora-panel.watch { border-color:#3d7a52; }
  .aurora-panel .k { font-size:22px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .aurora-panel .note { font-size:<?= $compact ? 20 : 22 ?>px; color:var(--mist); margin-top:8px; }
  .aurora-panel.watch .note { color:#7ee787; font-weight:600; }
  .aurora-stats { margin-top:<?= $compact ? 16 : 20 ?>px; display:flex; justify-content:space-between; gap:16px;
                  border-top:1px solid var(--hairline); padding-top:<?= $compact ? 16 : 20 ?>px; }
  .aurora-stats div { flex:1; text-align:center; min-width:0; }
  .aurora-stats .kk { font-size:18px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .aurora-stats .kv { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 48 : 54 ?>px;
                       margin-top:4px; font-variant-numeric:tabular-nums; }
  .aurora-stats .kv.hot { color:#7ee787; }

  .windows { grid-area:windows; display:grid; grid-template-columns:repeat(4,1fr); gap:24px; }
  .win { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px; padding:<?= $compact ? '20px 24px' : '26px 30px' ?>; }
  .win.prime { border-color:var(--beacon); }
  .win .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .win .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 50 : 56 ?>px; margin-top:8px;
            font-variant-numeric:tabular-nums; }
  .win.prime .v { color:var(--beacon); }
  .win .s { font-size:<?= $compact ? 19 : 21 ?>px; color:var(--mist); margin-top:6px; }
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
        <div class="lab"><span>Cloud cover at sunset</span><span><?= $evenings[0]['clouds'] ?>%</span></div>
        <div class="track"><div class="fill" style="width:<?= $evenings[0]['clouds'] ?>%"></div></div>
      </div>
    <?php endif; ?>
    <div class="nights">
      <?php foreach (array_slice($evenings, 1) as $e): ?>
        <div class="night">
          <div class="d"><?= h($e['label']) ?> &middot; <?= $e['clouds'] ?>%</div>
          <div class="c"><?= h($e['desc']) ?></div>
        </div>
      <?php endforeach; if (count($evenings) <= 1): ?>
        <div class="night"><div class="c">Forecast outlook unavailable<?= $configured ? '' : ' — set OWM_API_KEY' ?></div></div>
      <?php endif; ?>
    </div>
  </section>

  <div class="sky">
  <section class="moon">
    <div class="k">Moon</div>
    <svg viewBox="0 0 100 100">
      <?php
        // Shaded-disc moon: terminator drawn as an ellipse half
        $r = 46;
        $k = cos(2 * M_PI * $phaseFrac);          // semi-axis of the terminator
        $waxing = $phaseFrac < 0.5;
        $lit = 'var(--snow)'; $dark = '#1b2840';
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
  <div class="stamp">OpenWeatherMap &middot; NOAA SWPC<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(() => location.reload(), 15 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
