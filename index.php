<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  ALLENDALE WEATHER BOARD — 1920×1080 digital signage
 *  Single-file PHP. Current conditions + 5-day forecast (OpenWeatherMap)
 *  and live KGRR radar loop (NWS RIDGE).
 *
 *  Setup:
 *    1. Set OWM_API_KEY below (free tier is plenty — page caches for 10 min).
 *    2. Drop this file on any PHP host (PHP 7.4+ with curl) and point the
 *       signage browser at it. Page reloads itself every 15 minutes; the
 *       radar image refreshes every 5 minutes without a reload.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Config ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

define('OWM_API_KEY', cfg('index.OWM_API_KEY', 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE'));
define('LAT', cfg('index.LAT', 42.9720));
define('LON', cfg('index.LON', -85.9536));
define('LOCATION', cfg('index.LOCATION', 'Allendale, Michigan'));
define('UNITS', cfg('index.UNITS', 'imperial'));
define('TIMEZONE', cfg('index.TIMEZONE', 'America/Detroit'));
define('RADAR_URL', cfg('index.RADAR_URL', 'https://radar.weather.gov/ridge/standard/KGRR_loop.gif'));
const CACHE_DIR   = __DIR__ . '/cache';  // must be writable by the web server
define('CACHE_TTL', cfg('index.CACHE_TTL', 600));

date_default_timezone_set(TIMEZONE);

// ── Data layer ───────────────────────────────────────────────────────────────

/**
 * Fetch a URL as decoded JSON, cached on disk. On API failure, serve stale
 * cache forever rather than blanking the board.
 */
$GLOBALS['api_diag'] = [];

function fetch_json_cached(string $url, string $cacheKey): ?array
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $cacheFile = CACHE_DIR . '/' . $cacheKey . '.json';

    $fresh = is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_TTL;
    if ($fresh) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $data;
        }
    }

    if (!function_exists('curl_init')) {
        $GLOBALS['api_diag'][$cacheKey] = 'PHP curl extension is not installed (apt install php-curl, then restart the web server)';
        // fall through to stale cache below
    } else {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'AllendaleWeatherBoard/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Record what happened for the diagnostics panel (API key never included)
    if ($body === false || $code !== 200) {
        $detail = $err !== '' ? 'curl: ' . $err : 'HTTP ' . $code;
        if (is_string($body) && $body !== '' && $code !== 200) {
            $api = json_decode($body, true);
            if (isset($api['message'])) {
                $detail .= ' — API says: "' . $api['message'] . '"';
            }
        }
        $GLOBALS['api_diag'][$cacheKey] = $detail;
    }

    if ($body !== false && $code === 200) {
        $data = json_decode($body, true);
        if (is_array($data)) {
            @file_put_contents($cacheFile, $body, LOCK_EX);
            return $data;
        }
        $GLOBALS['api_diag'][$cacheKey] = 'HTTP 200 but the response was not valid JSON';
    }
    }

    // Fall back to stale cache if the API is down.
    if (is_file($cacheFile)) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($data) ? $data : null;
    }
    return null;
}

function owm_url(string $endpoint): string
{
    return sprintf(
        'https://api.openweathermap.org/data/2.5/%s?lat=%F&lon=%F&units=%s&appid=%s',
        $endpoint, LAT, LON, UNITS, OWM_API_KEY
    );
}

function compass(float $deg): string
{
    $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $dirs[(int)round($deg / 22.5) % 16];
}

function icon_url(string $code, int $scale = 4): string
{
    return sprintf('https://openweathermap.org/img/wn/%s@%dx.png', $code, $scale);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── Pull data ────────────────────────────────────────────────────────────────
$configured = OWM_API_KEY !== 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE' && OWM_API_KEY !== '';
$owmCacheId = sprintf('%F_%F_%s', LAT, LON, UNITS);
$current    = $configured ? fetch_json_cached(owm_url('weather'),  'owm_current_' . $owmCacheId)  : null;
$forecast   = $configured ? fetch_json_cached(owm_url('forecast'), 'owm_forecast_' . $owmCacheId) : null;

// Current conditions
$cw = null;
if ($current && isset($current['main'])) {
    $cw = [
        'temp'       => (int)round($current['main']['temp']),
        'feels'      => (int)round($current['main']['feels_like']),
        'humidity'   => (int)$current['main']['humidity'],
        'pressure'   => round(($current['main']['pressure'] ?? 0) * 0.02953, 2), // hPa → inHg
        'desc'       => $current['weather'][0]['description'] ?? '—',
        'icon'       => $current['weather'][0]['icon'] ?? '01d',
        'wind_mph'   => (int)round($current['wind']['speed'] ?? 0),
        'wind_gust'  => isset($current['wind']['gust']) ? (int)round($current['wind']['gust']) : null,
        'wind_dir'   => compass((float)($current['wind']['deg'] ?? 0)),
        'clouds'     => (int)($current['clouds']['all'] ?? 0),
        'visibility' => round(($current['visibility'] ?? 0) / 1609, 1),          // m → mi
        'sunrise'    => (int)($current['sys']['sunrise'] ?? 0),
        'sunset'     => (int)($current['sys']['sunset'] ?? 0),
        'updated'    => (int)($current['dt'] ?? time()),
    ];
}

// 5-day outlook: collapse the 3-hour list into per-day min/max + midday icon
$days = [];
if ($forecast && isset($forecast['list'])) {
    foreach ($forecast['list'] as $slot) {
        $ts  = (int)$slot['dt'];
        $key = date('Y-m-d', $ts);
        if (!isset($days[$key])) {
            $days[$key] = [
                'label'    => date('D', $ts),
                'date'     => date('M j', $ts),
                'min'      => PHP_FLOAT_MAX,
                'max'      => -PHP_FLOAT_MAX,
                'icon'     => $slot['weather'][0]['icon'] ?? '01d',
                'desc'     => $slot['weather'][0]['description'] ?? '',
                'icon_gap' => PHP_INT_MAX,
                'pop'      => 0.0,
            ];
        }
        $d = &$days[$key];
        $d['min'] = min($d['min'], (float)$slot['main']['temp_min']);
        $d['max'] = max($d['max'], (float)$slot['main']['temp_max']);
        $d['pop'] = max($d['pop'], (float)($slot['pop'] ?? 0));

        // Use the slot closest to 1 PM as the day's representative icon
        $gap = abs((int)date('G', $ts) - 13);
        if ($gap < $d['icon_gap']) {
            $d['icon_gap'] = $gap;
            $d['icon']     = $slot['weather'][0]['icon'] ?? '01d';
            $d['desc']     = $slot['weather'][0]['description'] ?? '';
        }
        unset($d);
    }
    $days = array_slice($days, 0, 5);
}

$todayKey = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Allendale Weather Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  :root {
    --lake-night: #0c1422;   /* page background          */
    --harbor:     #141f33;   /* panels                   */
    --hairline:   #26344d;   /* rules and borders        */
    --snow:       #edf2fb;   /* primary text             */
    --mist:       #8aa0c0;   /* secondary text           */
    --beacon:     #ffb347;   /* the one accent: amber    */
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    width: 1920px;
    height: 1080px;
    overflow: hidden;
    background: var(--lake-night);
    color: var(--snow);
    font-family: 'IBM Plex Sans', sans-serif;
    cursor: none;                       /* signage: hide any stray pointer */
  }

  .board {
    width: 1920px;
    height: 1080px;
    display: grid;
    grid-template-columns: 700px 1fr;
    grid-template-rows: 1fr 210px;
    grid-template-areas:
      "now   radar"
      "week  week";
    gap: 24px;
    padding: 28px 32px;
  }

  /* ── Left column: now ─────────────────────────────────────────────────── */
  .now {
    grid-area: now;
    display: flex;
    flex-direction: column;
    min-height: 0;
  }

  .clock-line { display: flex; align-items: baseline; gap: 20px; }
  #clock {
    font-family: 'Big Shoulders Display', sans-serif;
    font-weight: 700;
    font-size: 104px;
    line-height: 1;
    letter-spacing: 1px;
  }
  #clock .ampm { font-size: 40px; font-weight: 600; color: var(--mist); margin-left: 8px; }
  #dateline {
    margin-top: 4px;
    font-size: 26px;
    color: var(--mist);
    letter-spacing: 0.5px;
  }
  .location {
    margin-top: 2px;
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--beacon);
  }

  .temp-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
  }
  .temp-row img { width: 150px; height: 150px; margin: -20px 0 -20px -20px; }
  .big-temp {
    font-family: 'Big Shoulders Display', sans-serif;
    font-weight: 700;
    font-size: 190px;
    line-height: 0.9;
    color: var(--beacon);
  }
  .big-temp sup { font-size: 76px; font-weight: 600; vertical-align: 52px; }
  .condition {
    font-size: 32px;
    font-weight: 500;
    text-transform: capitalize;
    margin-top: 10px;
  }
  .feels { font-size: 23px; color: var(--mist); margin-top: 4px; }

  .stats {
    margin-top: 22px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px 32px;
    border-top: 1px solid var(--hairline);
    padding-top: 20px;
  }
  .stat { display: flex; justify-content: space-between; align-items: baseline; }
  .stat .k { font-size: 20px; color: var(--mist); letter-spacing: 1px; text-transform: uppercase; }
  .stat .v { font-size: 27px; font-weight: 600; font-variant-numeric: tabular-nums; }

  /* Sun arc — sunrise → sunset with live position */
  .sun {
    margin-top: auto;
    padding-top: 16px;
  }
  .sun svg { width: 100%; height: 118px; display: block; }
  .sun-times {
    display: flex;
    justify-content: space-between;
    font-size: 22px;
    color: var(--mist);
    margin-top: -4px;
    font-variant-numeric: tabular-nums;
  }
  .sun-times b { color: var(--snow); font-weight: 600; }

  /* ── Right column: radar ──────────────────────────────────────────────── */
  .radar {
    grid-area: radar;
    background: var(--harbor);
    border: 1px solid var(--hairline);
    border-radius: 14px;
    overflow: hidden;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  #radarMap {
    position: absolute;
    inset: 0;
    background: var(--harbor);
  }
  #radarMap .leaflet-control-attribution {
    background: rgba(12, 20, 34, 0.7);
    color: var(--mist);
    font-size: 11px;
  }
  #radarMap .leaflet-control-attribution a { color: var(--mist); }

  /* Fallback: NWS RIDGE GIF with header/scale chrome cropped away */
  .radar.fallback-active #radarMap { display: none; }
  #radarFallback {
    display: none;
    position: absolute;
    inset: 0;
    overflow: hidden;
    background: #fdfdfd;
  }
  .radar.fallback-active #radarFallback { display: block; }
  #radarFallback img {
    /* source 600x550; usable map approx y 24-520. Fill panel height. */
    position: absolute;
    top: 50%;
    left: 50%;
    height: 124%;            /* 550/496 * 112% margin */
    transform: translate(-50%, -50%);
  }
  .radar .tag {
    position: absolute;
    top: 18px;
    left: 22px;
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--snow);
    background: rgba(12, 20, 34, 0.82);
    border: 1px solid var(--hairline);
    border-radius: 8px;
    padding: 8px 14px;
  }
  .radar .tag span { color: var(--beacon); }

  /* ── Bottom strip: 5-day outlook ──────────────────────────────────────── */
  .week {
    grid-area: week;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 24px;
  }
  .day {
    background: var(--harbor);
    border: 1px solid var(--hairline);
    border-radius: 14px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .day.today { border-color: var(--beacon); }
  .day img { width: 80px; height: 80px; margin: -6px; flex: none; }
  .day .meta { flex: 1; min-width: 0; overflow: hidden; }
  .day .name {
    font-family: 'Big Shoulders Display', sans-serif;
    font-size: 32px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .day.today .name { color: var(--beacon); }
  .day .sub { font-size: 17px; color: var(--mist); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-transform: capitalize; }
  .day .range {
    text-align: right;
    font-variant-numeric: tabular-nums;
    flex: none;
    white-space: nowrap;
  }
  .day .hi { font-size: 36px; font-weight: 600; }
  .day .lo { font-size: 22px; color: var(--mist); }
  .day .pop { font-size: 17px; color: var(--beacon); margin-top: 2px; }

  /* ── Footer / error states ─────────────────────────────────────────────── */
  .stamp {
    position: absolute;
    bottom: 8px;
    right: 36px;
    font-size: 16px;
    color: var(--mist);
    opacity: 0.7;
  }
  .setup {
    width: 1920px; height: 1080px;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 24px; text-align: center;
  }
  .setup h1 { font-family: 'Big Shoulders Display', sans-serif; font-size: 84px; color: var(--beacon); }
  .setup p { font-size: 32px; color: var(--mist); max-width: 1100px; line-height: 1.5; }
  .setup code { color: var(--snow); background: var(--harbor); padding: 4px 14px; border-radius: 8px; }
</style>
</head>
<body>

<?php if (!$configured): ?>
  <div class="setup">
    <h1>Almost there</h1>
    <p>Open <code><?= h(basename(__FILE__)) ?></code> and set <code>OWM_API_KEY</code> to your
       OpenWeatherMap API key. The free tier covers this board easily &mdash;
       it calls the API at most once every <?= (int)(CACHE_TTL / 60) ?> minutes.</p>
  </div>
<?php elseif (!$cw): ?>
  <div class="setup">
    <h1>No weather data</h1>
    <p>The OpenWeatherMap API call failed and no cached data exists yet.
       The board retries automatically. Here's what happened:</p>
    <?php foreach (($GLOBALS['api_diag'] ?? []) as $key => $detail): ?>
      <p style="font-size:26px"><code><?= h($key) ?></code> &mdash; <?= h($detail) ?></p>
    <?php endforeach; ?>
    <?php
      $diagText = implode(' ', $GLOBALS['api_diag'] ?? []);
      if (strpos($diagText, '401') !== false || stripos($diagText, 'invalid api key') !== false): ?>
      <p style="font-size:24px">A 401 usually means the key is wrong <em>or brand new</em> &mdash;
         OpenWeatherMap takes up to a couple of hours to activate new keys.
         Confirm the key works:<br>
         <code style="font-size:20px">curl "https://api.openweathermap.org/data/2.5/weather?lat=<?= LAT ?>&amp;lon=<?= LON ?>&amp;units=imperial&amp;appid=YOUR_KEY"</code></p>
    <?php elseif (stripos($diagText, 'curl:') !== false): ?>
      <p style="font-size:24px">A curl-level error means the request never reached OpenWeatherMap &mdash;
         check DNS, outbound HTTPS/firewall rules, and CA certificates on this server.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="board">

  <!-- NOW -->
  <section class="now">
    <div class="clock-line"><div id="clock">--:--<span class="ampm">--</span></div></div>
    <div id="dateline">&nbsp;</div>
    <div class="location"><?= h(LOCATION) ?></div>

    <div class="temp-row">
      <img src="<?= h(icon_url($cw['icon'])) ?>" alt="">
      <div>
        <div class="big-temp"><?= $cw['temp'] ?><sup>&deg;F</sup></div>
      </div>
    </div>
    <div class="condition"><?= h($cw['desc']) ?></div>
    <div class="feels">Feels like <?= $cw['feels'] ?>&deg; &middot; <?= $cw['clouds'] ?>% cloud cover</div>

    <div class="stats">
      <div class="stat"><span class="k">Wind</span>
        <span class="v"><?= h($cw['wind_dir']) ?> <?= $cw['wind_mph'] ?> mph<?= $cw['wind_gust'] !== null ? ' <span style="color:var(--mist);font-size:20px">G ' . $cw['wind_gust'] . '</span>' : '' ?></span></div>
      <div class="stat"><span class="k">Humidity</span><span class="v"><?= $cw['humidity'] ?>%</span></div>
      <div class="stat"><span class="k">Pressure</span><span class="v"><?= number_format($cw['pressure'], 2) ?> inHg</span></div>
      <div class="stat"><span class="k">Visibility</span><span class="v"><?= $cw['visibility'] ?> mi</span></div>
    </div>

    <div class="sun">
      <svg viewBox="0 0 640 170" aria-hidden="true">
        <!-- horizon -->
        <line x1="20" y1="150" x2="620" y2="150" stroke="var(--hairline)" stroke-width="2"/>
        <!-- arc: half-ellipse from sunrise (60,150) to sunset (580,150) -->
        <path d="M 60 150 A 260 130 0 0 1 580 150"
              fill="none" stroke="var(--hairline)" stroke-width="3" stroke-dasharray="2 8"/>
        <path id="sunTrail" d="" fill="none" stroke="var(--beacon)" stroke-width="3"/>
        <circle id="sunDot" cx="60" cy="150" r="11" fill="var(--beacon)"/>
      </svg>
      <div class="sun-times">
        <span>Sunrise <b><?= date('g:i A', $cw['sunrise']) ?></b></span>
        <span>Sunset <b><?= date('g:i A', $cw['sunset']) ?></b></span>
      </div>
    </div>
  </section>

  <!-- RADAR -->
  <section class="radar" id="radarPanel">
    <div id="radarMap"></div>
    <div id="radarFallback"><img src="<?= h(RADAR_URL) ?>" alt="KGRR radar loop"></div>
    <div class="tag">Radar <span>West Michigan</span> &middot; <span id="radarTime">&hellip;</span></div>
  </section>

  <!-- 5-DAY -->
  <section class="week">
    <?php foreach ($days as $key => $d): ?>
      <div class="day<?= $key === $todayKey ? ' today' : '' ?>">
        <img src="<?= h(icon_url($d['icon'], 2)) ?>" alt="">
        <div class="meta">
          <div class="name"><?= $key === $todayKey ? 'Today' : h($d['label']) ?></div>
          <div class="sub"><?= h($d['desc']) ?></div>
        </div>
        <div class="range">
          <span class="hi"><?= (int)round($d['max']) ?>&deg;</span>
          <span class="lo">/ <?= (int)round($d['min']) ?>&deg;</span>
          <?php if ($d['pop'] >= 0.15): ?>
            <div class="pop"><?= (int)round($d['pop'] * 100) ?>% precip</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

</div>

<div class="stamp">Conditions updated <?= date('g:i A', $cw['updated']) ?> &middot; OpenWeatherMap &middot; NWS RIDGE</div>

<script>
  // ── Live clock ─────────────────────────────────────────────────────────
  function tick() {
    const now = new Date();
    let h = now.getHours();
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const m = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('clock').innerHTML =
      h + ':' + m + '<span class="ampm">' + ampm + '</span>';
    document.getElementById('dateline').textContent =
      now.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
  }
  tick();
  setInterval(tick, 1000);

  // ── Sun position on the arc ────────────────────────────────────────────
  const SUNRISE = <?= (int)$cw['sunrise'] ?> * 1000;
  const SUNSET  = <?= (int)$cw['sunset'] ?>  * 1000;

  function placeSun() {
    const t = Math.min(1, Math.max(0, (Date.now() - SUNRISE) / (SUNSET - SUNRISE)));
    // Half-ellipse: center (320,150), rx 260, ry 130
    const a = Math.PI * (1 - t);
    const x = 320 + 260 * Math.cos(a);
    const y = 150 - 130 * Math.sin(a);
    const dot = document.getElementById('sunDot');
    dot.setAttribute('cx', x.toFixed(1));
    dot.setAttribute('cy', y.toFixed(1));
    // Trail from sunrise to current position
    const trail = document.getElementById('sunTrail');
    if (t > 0.01) {
      trail.setAttribute('d', 'M 60 150 A 260 130 0 0 1 ' + x.toFixed(1) + ' ' + y.toFixed(1));
    }
    // Dim the dot at night
    const night = Date.now() < SUNRISE || Date.now() > SUNSET;
    dot.setAttribute('opacity', night ? '0.25' : '1');
  }
  placeSun();
  setInterval(placeSun, 60 * 1000);

  // ── Radar: dark animated composite (RainViewer over Carto dark) ────────
  // Falls back to the NWS RIDGE KGRR loop if tiles or the API are unavailable.
  const HOME = [<?= LAT ?>, <?= LON ?>];
  const RADAR_BASE = <?= json_encode(RADAR_URL) ?>;
  let radarLayers = [], radarFrames = [], radarIdx = 0, radarTimer = null;

  function radarFallback() {
    document.getElementById('radarPanel').classList.add('fallback-active');
    document.querySelector('#radarPanel .tag').innerHTML =
      'Radar <span>KGRR</span> &middot; Grand Rapids';
    // refresh the GIF every 5 minutes
    setInterval(() => {
      document.querySelector('#radarFallback img').src = RADAR_BASE + '?t=' + Date.now();
    }, 5 * 60 * 1000);
  }

  function fmtFrameTime(unix) {
    return new Date(unix * 1000)
      .toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }

  if (typeof L === 'undefined') {
    radarFallback();
  } else {
    const map = L.map('radarMap', {
      zoomControl: false, dragging: false, scrollWheelZoom: false,
      doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false
    }).setView(HOME, 7);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      subdomains: 'abcd', maxZoom: 10,
      attribution: '&copy; OpenStreetMap &copy; CARTO &middot; radar &copy; RainViewer'
    }).addTo(map);

    // Home marker: Allendale
    L.circleMarker(HOME, {
      radius: 6, color: '#ffb347', weight: 2, fillColor: '#ffb347', fillOpacity: 0.9
    }).addTo(map);

    function showFrame(i) {
      radarLayers.forEach((l, j) => l.setOpacity(j === i ? 0.8 : 0));
      if (radarFrames[i]) {
        document.getElementById('radarTime').textContent = fmtFrameTime(radarFrames[i].time);
      }
    }

    function stepFrame() {
      radarIdx = (radarIdx + 1) % radarLayers.length;
      showFrame(radarIdx);
      // brief hold on the most recent frame
      radarTimer = setTimeout(stepFrame, radarIdx === radarLayers.length - 1 ? 2200 : 550);
    }

    async function loadRadar() {
      const res = await fetch('https://api.rainviewer.com/public/weather-maps.json');
      if (!res.ok) throw new Error('rainviewer http ' + res.status);
      const data = await res.json();
      const frames = (data.radar && data.radar.past || []).slice(-8);
      if (!frames.length) throw new Error('no radar frames');

      clearTimeout(radarTimer);
      radarLayers.forEach(l => map.removeLayer(l));
      radarLayers = frames.map(f => L.tileLayer(
        data.host + f.path + '/256/{z}/{x}/{y}/8/1_1.png',
        { opacity: 0, maxZoom: 10 }
      ).addTo(map));
      radarFrames = frames;
      radarIdx = radarLayers.length - 1;
      showFrame(radarIdx);
      radarTimer = setTimeout(stepFrame, 2200);
    }

    loadRadar().catch(radarFallback);
    setInterval(() => loadRadar().catch(() => {}), 5 * 60 * 1000);
  }

  // ── Full reload every 15 min picks up fresh conditions/forecast ───────
  setTimeout(() => location.reload(), 15 * 60 * 1000);
</script>

<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
