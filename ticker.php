<?php
/**
 * WEATHER ALERT TICKER — shared include
 * Add  <?php include __DIR__ . '/ticker.php'; ?>  just before </body> on any
 * board. Overlays a scrolling ticker when NWS alerts are active; hidden otherwise.
 *
 * Polls ticker.php?api=1 on a short interval so alerts appear/disappear (and demo
 * mode toggles) without reloading the page — board.php / player.php can run for days.
 *
 * player.php loads board.php?noticker=1 in an iframe and includes this file in the
 * outer document so polling runs in the top-level PWA context (not a nested iframe).
 *
 * Seamless across slide changes: scroll/static phase is computed from the wall
 * clock so every board shows the same position at the same moment.
 *
 * All boards share one cache file per alert point, so the NWS API is hit at most
 * once per TICKER_TTL for that location (demo mode bypasses the cache).
 *
 * TICKER_MODE 'scroll' = marquee; 'static' = fixed bar cycling one alert at a
 * time (also clock-phased, also seamless).
 * Set TICKER_DEMO = true to preview with a fake alert, then turn it back off.
 */

// Inside the rotation shell (board.php) the shell renders the one true
// ticker; framed boards get ?noticker=1 appended and skip theirs.
if (isset($_GET['noticker'])) return;

require_once __DIR__ . '/config.php';

if (!signage_ticker_enabled()) {
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'alerts' => [],
            'mode'   => 'scroll',
            'demo'   => false,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    return;
}

if (!defined('TICKER_LAT')) {
    define('TICKER_LAT', cfg('ticker.TICKER_LAT', 42.9720));              // Allendale / home point
    define('TICKER_LON', cfg('ticker.TICKER_LON', -85.9536));
    define('TICKER_UA', cfg('ticker.TICKER_UA', 'HomeSignage/1.0 (contact: you@example.com)'));
    define('TICKER_TTL', cfg('ticker.TICKER_TTL', 300));                  // seconds between NWS fetches
    define('TICKER_MODE', cfg('ticker.TICKER_MODE', 'scroll'));            // 'scroll' or 'static'
    define('TICKER_MIN_SEVERITY', cfg('ticker.TICKER_MIN_SEVERITY', 'Minor'));     // Minor | Moderate | Severe — hide anything below
    define('TICKER_DEMO', cfg('ticker.TICKER_DEMO', false));               // true = render a sample alert for layout testing
}

if (!function_exists('signage_ticker_alerts')) {
function signage_ticker_alerts(): array
{
    if (TICKER_DEMO) {
        return [[
            'event'    => 'Beach Hazards Statement',
            'severity' => 'Moderate',
            'headline' => 'Beach Hazards Statement issued until 10 PM EDT this evening — '
                        . 'dangerous swimming conditions and structural currents expected '
                        . 'along Lake Michigan beaches near Grand Haven and Holland.',
        ], [
            'event'    => 'Severe Thunderstorm Warning',
            'severity' => 'Severe',
            'headline' => 'Severe Thunderstorm Warning for Ottawa County until 8:45 PM — '
                        . '60 mph wind gusts and quarter size hail possible.',
        ]];
    }

    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = $dir . '/ticker_alerts_' . sprintf('%.4F_%.4F', TICKER_LAT, TICKER_LON) . '.dat';
    $maxAge = min(max((int)TICKER_TTL, 30), 90);   // cap so new alerts show within ~90s
    $raw = null;
    if (is_file($f) && (time() - filemtime($f)) < $maxAge) {
        $raw = (string)file_get_contents($f);
    } else {
        $ch = curl_init(sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F',
            TICKER_LAT, TICKER_LON));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>8, CURLOPT_USERAGENT=>TICKER_UA,
            CURLOPT_HTTPHEADER=>['Accept: application/geo+json']]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); $raw = $body; }
        elseif (is_file($f)) { $raw = (string)file_get_contents($f); } // stale fallback, retry later
    }
    if ($raw === null) return [];

    $j = json_decode($raw, true);
    $rank = ['Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];
    $min  = $rank[TICKER_MIN_SEVERITY] ?? 1;
    $out = [];
    foreach (($j['features'] ?? []) as $feat) {
        $p = $feat['properties'] ?? [];
        $sev = $p['severity'] ?? 'Minor';
        if (($rank[$sev] ?? 1) < $min) continue;
        $out[] = [
            'event'    => (string)($p['event'] ?? 'Weather Alert'),
            'severity' => $sev,
            'headline' => (string)($p['headline'] ?? $p['event'] ?? ''),
        ];
    }
    // Most severe first
    usort($out, fn($a, $b) => ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0));
    return $out;
}
}

// JSON feed for client-side polling (board.php shell, direct board views).
if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'alerts' => signage_ticker_alerts(),
        'mode'   => TICKER_MODE,
        'demo'   => (bool)TICKER_DEMO,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$tickerPollMs = TICKER_DEMO ? 15000 : 30000;
?>
<style>
  #signage-ticker-root { position:fixed; left:0; right:0; bottom:0; z-index:9999; pointer-events:none; }
  #signage-ticker { display:flex; align-items:stretch; height:72px;
    font-family:'IBM Plex Sans',sans-serif;
    background:#33260e; border-top:2px solid #ffb347;
    box-shadow:0 -8px 30px rgba(0,0,0,.45); }
  #signage-ticker.tk-severe { background:#3a1016; border-top-color:#ff5d5d; }
  #signage-ticker .tk-tag { flex:0 0 auto; display:flex; align-items:center; gap:14px;
    padding:0 28px; font-weight:700; font-size:26px; letter-spacing:2px;
    color:#0c1422; background:#ffb347; text-transform:uppercase; white-space:nowrap; }
  #signage-ticker.tk-severe .tk-tag { background:#ff5d5d; }
  #signage-ticker .tk-dot { width:14px; height:14px; border-radius:50%; background:#0c1422;
    animation:tk-blink 1.2s steps(2,start) infinite; }
  @keyframes tk-blink { to { visibility:hidden; } }
  #signage-ticker .tk-scroll { flex:1; overflow:hidden; display:flex; align-items:center; }
  #signage-ticker .tk-track { display:flex; white-space:nowrap; will-change:transform; }
  #signage-ticker .tk-item { font-size:27px; color:#edf2fb; padding-right:90px; }
  #signage-ticker .tk-item b { color:#ffd089; font-weight:600; letter-spacing:1px; text-transform:uppercase; }
  #signage-ticker.tk-severe .tk-item b { color:#ff9d9d; }
  #signage-ticker .tk-item .tk-sep { color:#ffb347; padding:0 18px; }
  #signage-ticker.tk-severe .tk-item .tk-sep { color:#ff5d5d; }
  #signage-ticker.tk-static .tk-item { padding-right:0; width:100%;
    overflow:hidden; text-overflow:ellipsis; padding-left:26px; }
  @media (prefers-reduced-motion: reduce) {
    #signage-ticker .tk-track { animation:none !important; transform:none !important; } }
</style>
<div id="signage-ticker-root"></div>
<script>
(function () {
  var API = 'ticker.php?api=1';
  var POLL = <?= (int)$tickerPollMs ?>;
  var scrollRAF = null;
  var staticTimer = null;
  var lastKey = '';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function isSevere(alerts) {
    return alerts.some(function (a) {
      return a.severity === 'Severe' || a.severity === 'Extreme';
    });
  }

  function itemHtml(a) {
    return '<span class="tk-item"><b>' + esc(a.event) + '</b><span class="tk-sep">&bull;</span>'
         + esc(a.headline) + '</span>';
  }

  function stopAnim() {
    if (scrollRAF) { cancelAnimationFrame(scrollRAF); scrollRAF = null; }
    if (staticTimer) { clearInterval(staticTimer); staticTimer = null; }
  }

  function startScroll(track) {
    var SPEED = 110;
    var half = 0;
    function step() {
      if (!track.isConnected) return;
      if (!half) {
        half = track.scrollWidth / 2;
        if (!half) { scrollRAF = requestAnimationFrame(step); return; }
      }
      var x = (Date.now() / 1000 * SPEED) % half;
      track.style.transform = 'translateX(' + (-x) + 'px)';
      scrollRAF = requestAnimationFrame(step);
    }
    scrollRAF = requestAnimationFrame(step);
  }

  function startStatic(track) {
    var items = track.querySelectorAll('.tk-item');
    function flip() {
      if (!items.length) return;
      var slot = Math.floor(Date.now() / 9000) % items.length;
      items.forEach(function (el, i) { el.style.display = i === slot ? 'inline-block' : 'none'; });
    }
    flip();
    staticTimer = setInterval(flip, 500);
  }

  function apply(data) {
    if (document.body.classList.contains('signage-blank')) {
      var blankRoot = document.getElementById('signage-ticker-root');
      stopAnim();
      if (blankRoot) blankRoot.innerHTML = '';
      document.documentElement.style.setProperty('--signage-ticker-inset', '0px');
      lastKey = '';
      return;
    }

    var key = JSON.stringify(data.alerts || []) + '|' + (data.mode || 'scroll');
    if (key === lastKey) return;
    lastKey = key;

    var root = document.getElementById('signage-ticker-root');
    stopAnim();

    if (!data.alerts || !data.alerts.length) {
      root.innerHTML = '';
      document.documentElement.style.setProperty('--signage-ticker-inset', '0px');
      return;
    }

    var severe = isSevere(data.alerts);
    var mode = data.mode === 'static' ? 'static' : 'scroll';
    var items = '';
    if (mode === 'static') {
      data.alerts.forEach(function (a) {
        items += '<span class="tk-item" style="display:none"><b>' + esc(a.event)
               + '</b><span class="tk-sep">&bull;</span>' + esc(a.headline) + '</span>';
      });
    } else {
      data.alerts.forEach(function (a) { items += itemHtml(a); });
      items += items;   // duplicate for seamless loop
    }

    root.innerHTML =
      '<div id="signage-ticker" class="' + (severe ? 'tk-severe ' : '') + (mode === 'static' ? 'tk-static' : '') + '">'
      + '<div class="tk-tag"><span class="tk-dot"></span>' + (severe ? 'Warning' : 'Advisory') + '</div>'
      + '<div class="tk-scroll"><div class="tk-track" id="tk-track">' + items + '</div></div></div>';

    document.documentElement.style.setProperty('--signage-ticker-inset', '<?= SIGNAGE_TICKER_H ?>px');

    var track = document.getElementById('tk-track');
    if (mode === 'static') startStatic(track);
    else startScroll(track);
  }

  function refresh() {
    fetch(API, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data) apply(data); })
      .catch(function () {});
  }

  refresh();
  setInterval(refresh, POLL);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') refresh();
  });
  document.addEventListener('signage-blank', function (ev) {
    if (ev.detail && ev.detail.on) apply({ alerts: [] });
    else refresh();
  });
  if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(function () {
      if (document.body.classList.contains('signage-blank')) apply({ alerts: [] });
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  }
})();
</script>
