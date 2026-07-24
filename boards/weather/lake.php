<?php
/**
 * LAKE MICHIGAN CONDITIONS — 1920×1080 signage
 * NDBC buoy observations + NWS active alerts + sun times for the Grand Haven shoreline.
 * Default buoy 45029 (Holland nearshore) — reports wave height; Muskegon 45161 often lacks WVHT.
 * No API keys required.
 *
 * Note: nearshore buoys are seasonal (roughly Apr–Oct). When the buoy is out of
 * the water the board says so and leans on NWS alerts + sun data. Rotation auto-
 * skips lake.php after 24h with no fresh buoy data and restores it when live again.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/lake_lib.php';

define('NDBC_STATION', lake_ndbc_station());
define('STATION_NAME', cfg('lake.STATION_NAME', 'Holland Buoy 45029'));
define('BEACH_NAME', cfg('lake.BEACH_NAME', 'Grand Haven'));
define('LAT', cfg('lake.LAT', 43.0631));
define('LON', cfg('lake.LON', -86.2470));
define('TIMEZONE', cfg('lake.TIMEZONE', 'America/Detroit'));
define('NWS_UA', lake_nws_ua());

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$frameH = signage_frame_height();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function compass(float $deg): string {
    $d = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $d[(int)round($deg / 22.5) % 16];
}

// ── NDBC buoy ────────────────────────────────────────────────────────────────
$obs = lake_fetch_obs(NDBC_STATION);
$obsAgeMin = $obs ? (int)round((time() - (int)$obs['time']) / 60) : null;
$buoyOnline = $obs && $obsAgeMin !== null && $obsAgeMin < LAKE_BUOY_ONLINE_MAX_AGE_MIN;

// ── NWS active alerts for the beach point ───────────────────────────────────
$alerts = [];
$aRaw = lake_cached_get(
    sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', LAT, LON),
    'nws_alerts_' . sprintf('%.4F_%.4F', LAT, LON),
    ['Accept: application/geo+json']
);
if ($aRaw !== null) {
    $aj = json_decode($aRaw, true);
    foreach (($aj['features'] ?? []) as $f) {
        $p = $f['properties'] ?? [];
        $alerts[] = [
            'event'    => $p['event'] ?? 'Alert',
            'severity' => $p['severity'] ?? '',
            'ends'     => $p['ends'] ?? $p['expires'] ?? null,
        ];
    }
}

// ── Swim risk heuristic from wave height (NWS beach forecast is authoritative)
$risk = null;
if ($buoyOnline && $obs['wvht'] !== null) {
    if     ($obs['wvht'] < 2)  $risk = ['LOW', '#39c46d', 'Calm to light chop'];
    elseif ($obs['wvht'] < 4)  $risk = ['MODERATE', '#ffb347', 'Watch for currents near structures'];
    else                       $risk = ['HIGH', '#ff5d5d', 'Dangerous currents likely — stay off the pier'];
}
foreach ($alerts as $a) {        // NWS beach hazard overrides the heuristic upward
    if (stripos($a['event'], 'Beach Hazard') !== false || stripos($a['event'], 'Rip Current') !== false) {
        $risk = ['HIGH', '#ff5d5d', $a['event'] . ' in effect'];
    }
}

$sun = date_sun_info(time(), LAT, LON);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lake Michigan — <?= h(BEACH_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:grid;
           grid-template-columns: 760px 1fr; grid-template-rows: 96px minmax(0,1fr) 130px auto;
           grid-template-areas: "head head" "wave side" "foot foot" "meta meta"; gap:24px; }

  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; letter-spacing:1px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .wave { grid-area:wave; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:14px; padding:40px 44px; display:flex; flex-direction:column; }
  .wave .label { font-size:22px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .wave .big { font-family:'Big Shoulders Display'; font-weight:700; font-size:230px; line-height:.95;
               color:var(--beacon); }
  .wave .big small { font-size:80px; font-weight:600; }
  .wave .period { font-size:30px; color:var(--mist); margin-top:6px; }
  .risk { margin-top:auto; border-top:1px solid var(--hairline); padding-top:26px; }
  .risk .pill { display:inline-block; font-family:'Big Shoulders Display'; font-weight:700;
                font-size:44px; letter-spacing:2px; padding:6px 24px; border-radius:10px;
                color:var(--lake-night); }
  .risk .why { font-size:24px; color:var(--mist); margin-top:10px; }
  .offline { font-size:30px; line-height:1.5; color:var(--mist); margin-top:24px; }

  .side { grid-area:side; display:grid; grid-template-rows:auto 1fr; gap:24px; }
  .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; }
  .stat { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:22px 26px; }
  .stat .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .stat .v { font-family:'Big Shoulders Display'; font-weight:600; font-size:64px; margin-top:4px;
             font-variant-numeric:tabular-nums; }
  .stat .v small { font-size:30px; color:var(--mist); }

  .alerts { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:24px 28px; overflow:hidden; }
  .alerts .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .alert { display:flex; justify-content:space-between; align-items:baseline;
           border-bottom:1px solid var(--hairline); padding:14px 2px; }
  .alert:last-child { border-bottom:none; }
  .alert .e { font-size:30px; font-weight:600; }
  .alert .t { font-size:22px; color:var(--mist); }
  .sev-Severe .e, .sev-Extreme .e { color:#ff5d5d; }
  .sev-Moderate .e { color:var(--beacon); }
  .none { font-size:28px; color:var(--mist); padding:20px 2px; }

  .foot { grid-area:foot; display:flex; gap:24px; }
  .chip { flex:1; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:18px 26px; display:flex; justify-content:space-between; align-items:center; }
  .chip .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .chip .v { font-family:'Big Shoulders Display'; font-weight:600; font-size:44px;
             font-variant-numeric:tabular-nums; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Lake Michigan <span>&middot; <?= h(BEACH_NAME) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <section class="wave">
    <div class="label">Wave Height &middot; <?= h(STATION_NAME) ?></div>
    <?php if ($buoyOnline): ?>
      <div class="big"><?= $obs['wvht'] !== null ? number_format($obs['wvht'], 1) : '—' ?><small> ft</small></div>
      <div class="period"><?php
        $bits = [];
        if ($obs['dpd'] !== null) $bits[] = 'Dominant period ' . number_format($obs['dpd'], 0) . ' s';
        if ($obs['wvht'] !== null && $obs['wvht_time'] !== null && ($obs['time'] - $obs['wvht_time']) > 1800) {
            $bits[] = 'wave obs ' . date('g:i A', $obs['wvht_time']);
        }
        echo h(implode(' · ', $bits));
      ?></div>
      <?php if ($risk): ?>
        <div class="risk">
          <span class="pill" style="background:<?= $risk[1] ?>"><?= $risk[0] ?> RISK</span>
          <div class="why"><?= h($risk[2]) ?> &middot; heuristic — check the NWS beach forecast</div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="big">—</div>
      <div class="offline">No recent buoy observations. Nearshore buoys are typically
        recovered for the winter (Nov&ndash;Apr); NWS alerts below remain live.</div>
    <?php endif; ?>
  </section>

  <section class="side">
    <div class="stats">
      <div class="stat"><div class="k">Water Temp</div>
        <div class="v"><?= $buoyOnline && $obs['wtmp'] !== null ? (int)round($obs['wtmp']) . '<small>°F</small>' : '—' ?></div></div>
      <div class="stat"><div class="k">Wind</div>
        <div class="v"><?= $buoyOnline && $obs['wspd'] !== null
            ? h($obs['wdir'] !== null ? compass($obs['wdir']) : '') . ' ' . (int)round($obs['wspd']) . '<small> mph</small>'
            : '—' ?></div></div>
      <div class="stat"><div class="k">Air Temp</div>
        <div class="v"><?= $buoyOnline && $obs['atmp'] !== null ? (int)round($obs['atmp']) . '<small>°F</small>' : '—' ?></div></div>
    </div>
    <div class="alerts">
      <div class="k">NWS Active Alerts &middot; <?= h(BEACH_NAME) ?> shoreline</div>
      <?php if ($alerts): foreach (array_slice($alerts, 0, 5) as $a): ?>
        <div class="alert sev-<?= h($a['severity']) ?>">
          <span class="e"><?= h($a['event']) ?></span>
          <span class="t"><?= $a['ends'] ? 'until ' . date('D g:i A', strtotime($a['ends'])) : '' ?></span>
        </div>
      <?php endforeach; else: ?>
        <div class="none">No active alerts — all clear.</div>
      <?php endif; ?>
    </div>
  </section>

  <div class="foot">
    <div class="chip"><span class="k">Sunset over the lake</span>
      <span class="v"><?= date('g:i A', $sun['sunset']) ?></span></div>
    <div class="chip"><span class="k">Gusts</span>
      <span class="v"><?= $buoyOnline && $obs['gst'] !== null ? (int)round($obs['gst']) . ' mph' : '—' ?></span></div>
    <div class="chip"><span class="k">Pressure</span>
      <span class="v"><?= $buoyOnline && $obs['pres'] !== null ? number_format($obs['pres'], 2) . ' inHg' : '—' ?></span></div>
    <div class="chip"><span class="k">Observed</span>
      <span class="v"><?= $buoyOnline ? $obsAgeMin . ' min ago' : 'offline' ?></span></div>
  </div>
  <div class="stamp">NDBC <?= h(NDBC_STATION) ?> &middot; NWS API<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
