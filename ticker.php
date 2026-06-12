<?php
/**
 * WEATHER ALERT TICKER — shared include
 * Add  <?php include __DIR__ . '/ticker.php'; ?>  just before </body> on any
 * board. Renders NOTHING when there are no active NWS alerts; when alerts are
 * active it overlays a scrolling ticker along the bottom of the screen.
 *
 * Seamless across Anthias slides: the scroll position is computed from the
 * wall clock, so every board renders the ticker at the same phase — when
 * Anthias cuts from one slide to the next, the ticker appears continuous.
 *
 * All boards share one cache file, so however many boards are in rotation,
 * the NWS API is hit at most once per TICKER_TTL.
 *
 * TICKER_MODE 'scroll' = marquee; 'static' = fixed bar cycling one alert at a
 * time (also clock-phased, also seamless).
 * Set TICKER_DEMO = true to preview with a fake alert, then turn it back off.
 */

// Inside the rotation shell (board.php) the shell renders the one true
// ticker; framed boards get ?noticker=1 appended and skip theirs.
if (isset($_GET['noticker'])) return;

require_once __DIR__ . '/config.php';

if (!defined('TICKER_LAT')) {
    define('TICKER_LAT', cfg('ticker.TICKER_LAT', 42.9720));              // Allendale / home point
    define('TICKER_LON', cfg('ticker.TICKER_LON', -85.9536));
    define('TICKER_UA', 'HomeSignage/1.0 (contact: you@example.com)');
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
    $f = $dir . '/ticker_alerts.dat';
    $raw = null;
    if (is_file($f) && (time() - filemtime($f)) < TICKER_TTL) {
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
        elseif (is_file($f)) { $raw = (string)file_get_contents($f); touch($f); } // stale fallback, retry later
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

$signage_alerts = signage_ticker_alerts();
if ($signage_alerts):
    $tk_h = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $isSevere = (bool)array_filter($signage_alerts,
        fn($a) => in_array($a['severity'], ['Severe', 'Extreme'], true));
?>
<style>
  #signage-ticker { position:fixed; left:0; right:0; bottom:0; height:72px; z-index:9999;
    display:flex; align-items:stretch; font-family:'IBM Plex Sans',sans-serif;
    background:<?= $isSevere ? '#3a1016' : '#33260e' ?>;
    border-top:2px solid <?= $isSevere ? '#ff5d5d' : '#ffb347' ?>;
    box-shadow:0 -8px 30px rgba(0,0,0,.45); }
  #signage-ticker .tk-tag { flex:0 0 auto; display:flex; align-items:center; gap:14px;
    padding:0 28px; font-weight:700; font-size:26px; letter-spacing:2px;
    color:#0c1422; background:<?= $isSevere ? '#ff5d5d' : '#ffb347' ?>;
    text-transform:uppercase; white-space:nowrap; }
  #signage-ticker .tk-dot { width:14px; height:14px; border-radius:50%; background:#0c1422;
    animation:tk-blink 1.2s steps(2,start) infinite; }
  @keyframes tk-blink { to { visibility:hidden; } }
  #signage-ticker .tk-scroll { flex:1; overflow:hidden; display:flex; align-items:center; }
  #signage-ticker .tk-track { display:flex; white-space:nowrap; will-change:transform; }
  #signage-ticker .tk-item { font-size:27px; color:#edf2fb; padding-right:90px; }
  #signage-ticker .tk-item b { color:<?= $isSevere ? '#ff9d9d' : '#ffd089' ?>; font-weight:600;
    letter-spacing:1px; text-transform:uppercase; }
  #signage-ticker .tk-item .tk-sep { color:<?= $isSevere ? '#ff5d5d' : '#ffb347' ?>; padding:0 18px; }
  #signage-ticker.tk-static .tk-item { padding-right:0; width:100%;
    overflow:hidden; text-overflow:ellipsis; padding-left:26px; }
  @media (prefers-reduced-motion: reduce) {
    #signage-ticker .tk-track { animation:none !important; transform:none !important; } }
</style>
<div id="signage-ticker<?= TICKER_MODE === 'static' ? '" class="tk-static' : '' ?>">
  <div class="tk-tag"><span class="tk-dot"></span><?= $isSevere ? 'Warning' : 'Advisory' ?></div>
  <div class="tk-scroll"><div class="tk-track" id="tk-track">
    <?php if (TICKER_MODE === 'static'): ?>
      <?php foreach ($signage_alerts as $i => $a): ?>
        <span class="tk-item" data-i="<?= $i ?>" style="display:none">
          <b><?= $tk_h($a['event']) ?></b><span class="tk-sep">&bull;</span><?= $tk_h($a['headline']) ?>
        </span>
      <?php endforeach; ?>
    <?php else: ?>
      <?php for ($copy = 0; $copy < 2; $copy++): foreach ($signage_alerts as $a): ?>
        <span class="tk-item">
          <b><?= $tk_h($a['event']) ?></b><span class="tk-sep">&bull;</span><?= $tk_h($a['headline']) ?>
        </span>
      <?php endforeach; endfor; ?>
    <?php endif; ?>
  </div></div>
</div>
<script>
(function () {
  var MODE = <?= json_encode(TICKER_MODE) ?>;
  var track = document.getElementById('tk-track');
  if (MODE === 'static') {
    // One alert at a time, 9s each, phase locked to the wall clock so every
    // board shows the same alert at the same moment.
    var items = track.querySelectorAll('.tk-item');
    function flip() {
      var slot = Math.floor(Date.now() / 9000) % items.length;
      items.forEach(function (el, i) { el.style.display = i === slot ? 'inline-block' : 'none'; });
    }
    flip(); setInterval(flip, 500);
    return;
  }
  // Scrolling marquee: content is duplicated once; sliding by half the track
  // width loops perfectly. Position derives from epoch time, so every board
  // renders the identical phase and slide changes look continuous.
  var SPEED = 110;                                   // px per second
  var half = 0;
  function step() {
    if (!half) {
      half = track.scrollWidth / 2;
      if (!half) { requestAnimationFrame(step); return; }
    }
    var x = (Date.now() / 1000 * SPEED) % half;
    track.style.transform = 'translateX(' + (-x) + 'px)';
    requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
})();
</script>
<?php endif; ?>
