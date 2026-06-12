<?php
/**
 * TRAFFIC BOARD — 1920×1080 signage
 * Live congestion on I-96 between Allendale and Grand Rapids (TomTom Traffic Flow
 * tiles over a dark basemap — same Leaflet stack as the weather radar panel).
 *
 * Setup: free TomTom Developer key → admin.php → Traffic → paste API key.
 *   https://developer.tomtom.com/  (Traffic API / Maps API on the free tier)
 *
 * Tiles load in the browser; the key is visible to the kiosk — fine on a LAN wall.
 */

require_once __DIR__ . '/config.php';

define('TOMTOM_API_KEY', cfg('traffic.TOMTOM_API_KEY', 'PUT-YOUR-TOMTOM-KEY-HERE'));
define('TITLE', cfg('traffic.TITLE', 'Commute Traffic'));
define('SUBTITLE', cfg('traffic.SUBTITLE', 'I-96 · Allendale ↔ Grand Rapids'));
define('LAT', cfg('traffic.LAT', 42.935));
define('LON', cfg('traffic.LON', -85.82));
define('ZOOM', cfg('traffic.ZOOM', 11));
define('FLOW_STYLE', cfg('traffic.FLOW_STYLE', 'relative0-dark'));
define('TIMEZONE', cfg('traffic.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('traffic.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);

$configured = TOMTOM_API_KEY !== '' && TOMTOM_API_KEY !== 'PUT-YOUR-TOMTOM-KEY-HERE';
$frameH = signage_frame_height();
$flowStyles = ['relative0-dark', 'relative0', 'relative', 'absolute'];
$flowStyle = in_array(FLOW_STYLE, $flowStyles, true) ? FLOW_STYLE : 'relative0-dark';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$markers = [
    ['name' => 'Allendale', 'lat' => 42.9720, 'lon' => -85.9536],
    ['name' => 'Grand Rapids', 'lat' => 42.9634, 'lon' => -85.6681],
];
$i96 = [[42.963, -86.05], [42.963, -85.55]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<?php if ($configured): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              height:calc(<?= $frameH ?>px - var(--signage-ticker-inset, 0px)); }
  .board { width:1920px; height:100%; padding:24px 32px 20px; display:grid; gap:18px;
           grid-template-rows: 88px 1fr 64px; grid-template-areas: "head" "map" "legend"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:58px; letter-spacing:.5px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:26px; color:var(--mist); margin-left:18px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:52px; color:var(--mist);
           font-variant-numeric:tabular-nums; }

  .mapwrap { grid-area:map; position:relative; border-radius:14px; overflow:hidden;
             border:1px solid var(--hairline); background:var(--harbor); min-height:0; }
  #trafficMap { width:100%; height:100%; background:#0a1018; }
  #trafficMap .leaflet-control-attribution { font-size:11px; background:rgba(12,20,34,.85); color:var(--mist); }
  #trafficMap .leaflet-control-attribution a { color:var(--mist); }
  .map-tag { position:absolute; left:20px; bottom:16px; z-index:500; pointer-events:none;
             font-size:18px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
             background:rgba(12,20,34,.78); padding:8px 16px; border-radius:8px;
             border:1px solid var(--hairline); }
  .map-tag b { color:var(--beacon); font-weight:600; }

  .legend { grid-area:legend; display:flex; align-items:center; gap:28px; padding:0 8px; }
  .legend .lab { font-size:18px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .legend .items { display:flex; gap:22px; flex:1; }
  .legend .item { display:flex; align-items:center; gap:10px; font-size:21px; color:var(--snow); }
  .legend .swatch { width:36px; height:8px; border-radius:4px; }
  .sw-free { background:#39c46d; } .sw-slow { background:#ffb347; }
  .sw-bad { background:#ff5d5d; } .sw-worse { background:#a02040; }

  .setup { grid-area:map; display:flex; align-items:center; justify-content:center; flex-direction:column;
           gap:18px; text-align:center; padding:40px; }
  .setup h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p { font-size:26px; color:var(--mist); line-height:1.55; max-width:900px; }
  .setup code { background:var(--lake-night); padding:3px 10px; border-radius:6px; color:var(--snow); }
  .setup a { color:var(--beacon); }
  .stamp { position:absolute; top:28px; right:36px; font-size:15px; color:var(--mist); opacity:.7; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <div>
      <h1><?= h(TITLE) ?> <span>&middot; Live</span></h1>
      <span class="sub"><?= h(SUBTITLE) ?></span>
    </div>
    <div id="clock">--:--</div>
  </div>

  <?php if ($configured): ?>
  <div class="mapwrap">
    <div id="trafficMap"></div>
    <div class="map-tag">Flow <b>TomTom</b> &middot; refreshes every <?= (int)RELOAD_SEC ?>s</div>
  </div>
  <div class="legend">
    <span class="lab">Congestion</span>
    <div class="items">
      <span class="item"><span class="swatch sw-free"></span> Free flow</span>
      <span class="item"><span class="swatch sw-slow"></span> Slower</span>
      <span class="item"><span class="swatch sw-bad"></span> Congested</span>
      <span class="item"><span class="swatch sw-worse"></span> Heavy / closed</span>
    </div>
  </div>
  <?php else: ?>
  <div class="setup">
    <h2>TomTom API key needed</h2>
    <p>Add a free key under <strong>admin.php → Traffic</strong>, or set
       <code>traffic.TOMTOM_API_KEY</code> in config. Register at
       <a href="https://developer.tomtom.com/" target="_blank" rel="noopener">developer.tomtom.com</a>
       and enable the Traffic / Maps APIs.</p>
  </div>
  <div class="legend">
    <span class="lab">Congestion</span>
    <div class="items" style="color:var(--mist)">—</div>
  </div>
  <?php endif; ?>
</div>
<div class="stamp">TomTom Traffic Flow<?= $configured ? '' : ' · not configured' ?></div>

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

  <?php if ($configured): ?>
  (function () {
    const CENTER = [<?= LAT ?>, <?= LON ?>];
    const ZOOM = <?= (int)ZOOM ?>;
    const API_KEY = <?= json_encode(TOMTOM_API_KEY) ?>;
    const FLOW = <?= json_encode($flowStyle) ?>;
    const MARKERS = <?= json_encode($markers) ?>;
    const I96 = <?= json_encode($i96) ?>;
    const RELOAD = <?= max(60, (int)RELOAD_SEC) ?> * 1000;

    const map = L.map('trafficMap', {
      zoomControl: false, dragging: false, scrollWheelZoom: false,
      doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false,
      attributionControl: true
    }).setView(CENTER, ZOOM);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      subdomains: 'abcd', maxZoom: 19,
      attribution: '&copy; OpenStreetMap &copy; CARTO'
    }).addTo(map);

    let trafficLayer = L.tileLayer(
      'https://api.tomtom.com/traffic/map/4/tile/flow/' + FLOW + '/{z}/{x}/{y}.png?key=' + encodeURIComponent(API_KEY) + '&thickness=5',
      { opacity: 0.92, maxZoom: 19, attribution: '&copy; TomTom' }
    ).addTo(map);

    L.polyline(I96, {
      color: '#ffb347', weight: 2, opacity: 0.45, dashArray: '10 14'
    }).addTo(map);

    MARKERS.forEach(function (m) {
      L.circleMarker([m.lat, m.lon], {
        radius: 7, color: '#ffb347', weight: 2, fillColor: '#ffb347', fillOpacity: 0.85
      }).bindTooltip(m.name, {
        permanent: true, direction: 'top', offset: [0, -10],
        className: 'tt-label'
      }).addTo(map);
    });

    // Tooltip styling injected once
    if (!document.getElementById('tt-style')) {
      const s = document.createElement('style');
      s.id = 'tt-style';
      s.textContent = '.tt-label{background:rgba(12,20,34,.9)!important;border:1px solid #26344d!important;'
        + 'color:#edf2fb!important;font:600 15px "IBM Plex Sans",sans-serif!important;'
        + 'padding:4px 10px!important;border-radius:6px!important;box-shadow:none!important;}';
      document.head.appendChild(s);
    }

    setTimeout(function () { map.invalidateSize(); }, 200);

    // Bust traffic tile cache periodically so flow colors stay current
    setInterval(function () {
      map.removeLayer(trafficLayer);
      trafficLayer = L.tileLayer(
        'https://api.tomtom.com/traffic/map/4/tile/flow/' + FLOW + '/{z}/{x}/{y}.png?key='
          + encodeURIComponent(API_KEY) + '&thickness=5&t=' + Date.now(),
        { opacity: 0.92, maxZoom: 19, attribution: '&copy; TomTom' }
      ).addTo(map);
    }, RELOAD);

    setTimeout(function () { location.reload(); }, RELOAD);
  })();
  <?php endif; ?>
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
