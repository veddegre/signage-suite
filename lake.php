<?php
/**
 * LAKE MICHIGAN CONDITIONS — 1920×1080 signage
 * NDBC buoy observations + NWS active alerts + sun times for the Grand Haven shoreline.
 * No API keys required.
 *
 * Note: nearshore buoys are seasonal (roughly Apr–Oct). When the buoy is out of
 * the water the board says so and leans on NWS alerts + sun data.
 */

require_once __DIR__ . '/config.php';

define('NDBC_STATION', cfg('lake.NDBC_STATION', '45161'));
define('STATION_NAME', cfg('lake.STATION_NAME', 'Muskegon Buoy 45161'));
define('BEACH_NAME', cfg('lake.BEACH_NAME', 'Grand Haven'));
define('LAT', cfg('lake.LAT', 43.0631));
define('LON', cfg('lake.LON', -86.2470));
define('TIMEZONE', cfg('lake.TIMEZONE', 'America/Detroit'));
define('NWS_UA', cfg('lake.NWS_UA', 'HomeSignage/1.0 (contact: you@example.com)'));
const CACHE_DIR    = __DIR__ . '/cache';
define('CACHE_TTL', cfg('lake.CACHE_TTL', 600));

date_default_timezone_set(TIMEZONE);
$GLOBALS['diag'] = [];

function cached_get(string $url, string $key, array $headers = []): ?string
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        return (string)file_get_contents($f);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => NWS_UA, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;   // stale fallback
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m_to_ft(float $m): float   { return $m * 3.28084; }
function ms_to_mph(float $m): float { return $m * 2.23694; }
function c_to_f(float $c): float    { return $c * 9 / 5 + 32; }
function compass(float $deg): string {
    $d = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $d[(int)round($deg / 22.5) % 16];
}

// ── NDBC buoy: realtime2 text format ────────────────────────────────────────
$obs = null;
$raw = cached_get('https://www.ndbc.noaa.gov/data/realtime2/' . NDBC_STATION . '.txt', 'ndbc_' . NDBC_STATION);
if ($raw !== null) {
    $lines = preg_split('/\R/', trim($raw));
    // line0 = column names, line1 = units, line2+ = newest-first observations.
    // Buoys report met data (wind/temps) more often than wave spectra, so the
    // newest row frequently has MM in WVHT/DPD while a slightly older row has
    // them. Take each field's freshest non-MM value within a 4-hour window.
    if (count($lines) >= 3) {
        $cols   = preg_split('/\s+/', ltrim($lines[0], "# "));
        $want   = ['WVHT','DPD','WTMP','ATMP','WSPD','GST','WDIR','PRES'];
        $vals   = [];           // field => freshest numeric value
        $vtimes = [];           // field => timestamp of that value
        $newest = null;
        for ($i = 2; $i < min(count($lines), 40); $i++) {
            $parts = preg_split('/\s+/', trim($lines[$i]));
            if (count($parts) < count($cols)) continue;
            $row = array_combine($cols, array_slice($parts, 0, count($cols)));
            $ts  = gmmktime((int)$row['hh'], (int)$row['mm'], 0, (int)$row['MM'], (int)$row['DD'], (int)$row['YY']);
            if ($newest === null) $newest = $ts;
            if ($newest - $ts > 4 * 3600) break;                    // too old to substitute
            foreach ($want as $f) {
                if (!isset($vals[$f]) && isset($row[$f]) && $row[$f] !== 'MM') {
                    $vals[$f] = (float)$row[$f];
                    $vtimes[$f] = $ts;
                }
            }
            if (count($vals) === count($want)) break;
        }
        if ($newest !== null) {
            $obs = [
                'time'      => $newest,
                'wvht'      => isset($vals['WVHT']) ? m_to_ft($vals['WVHT']) : null,
                'wvht_time' => $vtimes['WVHT'] ?? null,
                'dpd'       => $vals['DPD'] ?? null,
                'wtmp'      => isset($vals['WTMP']) ? c_to_f($vals['WTMP']) : null,
                'atmp'      => isset($vals['ATMP']) ? c_to_f($vals['ATMP']) : null,
                'wspd'      => isset($vals['WSPD']) ? ms_to_mph($vals['WSPD']) : null,
                'gst'       => isset($vals['GST'])  ? ms_to_mph($vals['GST'])  : null,
                'wdir'      => $vals['WDIR'] ?? null,
                'pres'      => isset($vals['PRES']) ? $vals['PRES'] * 0.02953 : null,
            ];
        }
    }
}
$obsAgeMin  = $obs ? (int)round((time() - $obs['time']) / 60) : null;
$buoyOnline = $obs && $obsAgeMin !== null && $obsAgeMin < 240;   // >4h old = likely offline/seasonal

// ── NWS active alerts for the beach point ───────────────────────────────────
$alerts = [];
$aRaw = cached_get(sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', LAT, LON),
                   'nws_alerts_' . sprintf('%.4F_%.4F', LAT, LON), ['Accept: application/geo+json']);
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
  :root {
    --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
    --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:1080px; padding:28px 32px; display:grid;
           grid-template-columns: 760px 1fr; grid-template-rows: 96px 1fr 130px;
           grid-template-areas: "head head" "wave side" "foot foot"; gap:24px; }

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
  .stamp { position:absolute; bottom:6px; right:36px; font-size:15px; color:var(--mist); opacity:.7; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Lake Michigan <span>&middot; <?= h(BEACH_NAME) ?></span></h1>
    <div id="clock">--:--</div>
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
</div>
<div class="stamp">NDBC <?= h(NDBC_STATION) ?> &middot; NWS API<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
<script>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
