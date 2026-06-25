<?php
/**
 * DSHIELD HEATMAP — 1920×1080 world map
 *
 * SANS ISC DShield — countries shaded by distinct attack targets.
 * Free, no API key (same feed as Internet Attacks board).
 */

require_once __DIR__ . '/dshieldmap_lib.php';

define('TITLE', cfg('dshieldmap.TITLE', 'Attack Heatmap'));
define('SUBTITLE', cfg('dshieldmap.SUBTITLE', 'SANS DShield · targets by country'));
define('TIMEZONE', cfg('dshieldmap.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('dshieldmap.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$enabled = dshieldmap_enabled();
$countries = $enabled ? dshieldmap_fetch_heatmap() : [];
$infocon = $enabled ? attacks_fetch_infocon() : null;
$hero = $countries[0] ?? null;
$hasData = $enabled && $countries !== [];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$topBar = max(96, (int)round(104 * $boardH / 1080));
$sidebarLimit = dshieldmap_max_sidebar();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<?php if ($enabled): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --heat:#ff6b6b;
          --ok:#39c46d; --warn:#ffb347; --crit:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; display:flex; flex-direction:column;
           min-height:0; overflow:hidden; background:var(--lake-night); }
  .topbar { flex-shrink:0; height:<?= $topBar ?>px; display:flex; align-items:center;
            justify-content:space-between; gap:24px; padding:0 <?= $boardH < 1080 ? 28 : 32 ?>px;
            background:var(--harbor); border-bottom:1px solid var(--hairline); z-index:700; }
  .topbar .title-block { min-width:0; }
  .topbar h1 { font-family:'Big Shoulders Display'; font-weight:700;
               font-size:<?= $boardH < 1080 ? 48 : 54 ?>px; line-height:1; white-space:nowrap; }
  .topbar .sub { display:block; font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--beacon);
                 margin-top:6px; white-space:nowrap; }
  .topbar .sub .infocon { color:var(--mist); }
  .topbar .sub .infocon.ok { color:var(--ok); }
  .topbar .sub .infocon.warn { color:var(--warn); }
  .topbar .sub .infocon.crit { color:var(--crit); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }
  .map-area { flex:1; min-height:0; position:relative; background:#080e18; }
  .mapwrap { position:absolute; inset:0; }
  #heatMap { width:100%; height:100%; }
  #heatMap.leaflet-container,
  #heatMap .leaflet-container { width:100% !important; height:100% !important; background:#080e18; }
  #heatMap .leaflet-control-attribution { font-size:11px; background:rgba(8,14,24,.85); color:var(--mist); }
  #heatMap .leaflet-control-attribution a { color:var(--mist); }
  .heat-canvas { pointer-events:none; z-index:450; }

  .side { position:absolute; top:<?= $boardH < 1080 ? 16 : 20 ?>px; right:<?= $boardH < 1080 ? 20 : 28 ?>px;
          width:<?= $boardH < 1080 ? 360 : 400 ?>px; max-height:calc(100% - <?= $boardH < 1080 ? 88 : 96 ?>px);
          z-index:600; display:flex; flex-direction:column; gap:10px; overflow:hidden; pointer-events:none; }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist);
             background:rgba(12,20,34,.72); padding:8px 12px; border-radius:8px; border:1px solid var(--hairline); }
  .row { background:rgba(12,20,34,.78); border:1px solid var(--hairline); border-radius:10px;
         padding:10px 12px; backdrop-filter:blur(4px); }
  .row.hero { border-color:rgba(255,179,71,.45); box-shadow:0 0 18px rgba(255,107,107,.12); }
  .row .name { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; line-height:1.35; }
  .row .name .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); }
  .row .val { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
              color:var(--snow); margin-top:4px; }
  .row .meta { font-size:13px; color:var(--mist); margin-top:2px; }

  .map-tag { position:absolute; left:<?= $boardH < 1080 ? 28 : 32 ?>px; bottom:<?= $boardH < 1080 ? 16 : 20 ?>px; z-index:600;
             pointer-events:none; font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; letter-spacing:1.5px;
             text-transform:uppercase; color:var(--mist); background:rgba(12,20,34,.78); padding:10px 16px;
             border-radius:8px; border:1px solid var(--hairline); }
  .map-tag b { color:var(--beacon); }
  .legend-bar { display:flex; align-items:center; gap:10px; margin-top:8px; font-size:13px;
                text-transform:none; letter-spacing:0; }
  .legend-bar .grad { width:120px; height:10px; border-radius:5px;
                      background:linear-gradient(90deg, rgba(255,107,107,.15), rgba(255,179,71,.55), rgba(255,107,107,.95)); }

  .setup, .empty { position:absolute; inset:0; z-index:700; display:flex; align-items:center; justify-content:center;
                    flex-direction:column; gap:18px; text-align:center; padding:40px; background:var(--lake-night); }
  .setup h2, .empty h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p, .empty p { font-size:22px; color:var(--mist); line-height:1.55; max-width:920px; }
  <?= signage_stamp_css() ?>
  .stamp { position:absolute; right:<?= $boardH < 1080 ? 20 : 28 ?>px; bottom:<?= $boardH < 1080 ? 10 : 12 ?>px;
           left:auto; z-index:600; pointer-events:none; }
</style>
</head>
<body>
<div class="board">
  <header class="topbar">
    <div class="title-block">
      <h1><?= h(TITLE) ?></h1>
      <div class="sub">
        <?= h(SUBTITLE) ?>
        <?php if ($infocon): ?>
        · Infocon <span class="infocon <?= h((string)$infocon['class']) ?>"><?= h((string)$infocon['label']) ?></span>
        <?php endif; ?>
        <?php if ($hasData): ?> · <?= count($countries) ?> countries<?php endif; ?>
      </div>
    </div>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </header>

  <div class="map-area">
  <?php if ($enabled): ?>
  <div class="mapwrap"><div id="heatMap"></div></div>

  <?php if ($hasData): ?>
  <div class="side">
    <div class="k">Most targeted countries</div>
    <?php foreach (array_slice($countries, 0, $sidebarLimit) as $i => $c): ?>
    <div class="row<?= $i === 0 ? ' hero' : '' ?>">
      <div class="name">
        <span class="code"><?= h((string)$c['code']) ?></span> <?= h((string)$c['name']) ?>
      </div>
      <div class="val"><?= h(attacks_format_count((int)($c['value'] ?? $c['targets']))) ?> targets</div>
      <div class="meta"><?= h(attacks_format_count((int)$c['reports'])) ?> reports · <?= h(attacks_format_count((int)$c['sources'])) ?> sources</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="map-tag">
    <b><?= h(attacks_format_count((int)($hero['value'] ?? $hero['targets'] ?? 0))) ?></b> peak · <?= h((string)($hero['name'] ?? '')) ?>
    <div class="legend-bar">
      <span>low</span><div class="grad"></div><span>high</span>
    </div>
  </div>
  <?php else: ?>
  <div class="empty">
    <h2>DShield feed unavailable</h2>
    <p>Could not load country targets from isc.sans.edu<?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?>.</p>
  </div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'isc.sans.edu',
    count($countries) . ' countries mapped',
    $hero ? attacks_format_count((int)($hero['value'] ?? $hero['targets'] ?? 0)) . ' peak targets' : '',
    $GLOBALS['diag']['attacks_countries'] ?? '',
  ]))) ?></div>

  <?php else: ?>
  <div class="setup">
    <h2>DShield heatmap is disabled</h2>
    <p>Enable DShield in admin under <strong>DShield Heatmap</strong> or <strong>Internet Attacks</strong>.</p>
  </div>
  <?php endif; ?>
  </div>
</div>

<?php if ($enabled && $hasData): ?>
<script>
(function () {
  const COUNTRIES = <?= json_encode($countries, JSON_UNESCAPED_UNICODE) ?>;
  const RELOAD = <?= max(0, (int)RELOAD_SEC) ?> * 1000;

  const map = L.map('heatMap', {
    zoomControl: false, dragging: false, scrollWheelZoom: false,
    doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false,
    attributionControl: true, worldCopyJump: false, zoomSnap: 0,
  });

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd', maxZoom: 6, minZoom: 0, noWrap: true,
    attribution: '&copy; OpenStreetMap &copy; CARTO &middot; targets &copy; SANS DShield'
  }).addTo(map);

  function fitWorldFullWidth() {
    const size = map.getSize();
    if (!size.x || !size.y) return;
    const padX = 6;
    const w = Math.max(256, size.x - padX * 2);
    const zoom = Math.log(w / 256) / Math.LN2;
    map.setView([18, 0], zoom, { animate: false });
  }

  const HeatCanvas = L.Layer.extend({
    onAdd(m) {
      this._map = m;
      this._canvas = L.DomUtil.create('canvas', 'heat-canvas');
      m.getPanes().overlayPane.appendChild(this._canvas);
      m.on('move resize viewreset zoomend', this._resize, this);
      this._resize();
      this._tick = this._tick.bind(this);
      requestAnimationFrame(this._tick);
    },
    onRemove(m) {
      cancelAnimationFrame(this._raf);
      m.off('move resize viewreset zoomend', this._resize, this);
      L.DomUtil.remove(this._canvas);
    },
    _resize() {
      const size = this._map.getSize();
      const topLeft = this._map.containerPointToLayerPoint([0, 0]);
      L.DomUtil.setPosition(this._canvas, topLeft);
      const dpr = window.devicePixelRatio || 1;
      this._canvas.width = Math.round(size.x * dpr);
      this._canvas.height = Math.round(size.y * dpr);
      this._canvas.style.width = size.x + 'px';
      this._canvas.style.height = size.y + 'px';
      this._dpr = dpr;
    },
    _heatRgb(t, alpha) {
      const r = Math.round(35 + t * 220);
      const g = Math.round(18 + t * 100);
      const b = Math.round(55 + (1 - t) * 30);
      return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    },
    _tick(now) {
      this._draw(now || performance.now());
      this._raf = requestAnimationFrame(this._tick);
    },
    _draw(now) {
      const ctx = this._canvas.getContext('2d');
      const dpr = this._dpr || 1;
      const w = this._canvas.width;
      const h = this._canvas.height;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w / dpr, h / dpr);

      const pulse = 0.92 + 0.08 * Math.sin(now / 900);
      for (const c of COUNTRIES) {
        const pt = this._map.latLngToContainerPoint([c.lat, c.lng]);
        const t = c.intensity;
        const radius = (14 + t * 58) * (c.rank === 1 ? pulse : 1);
        const grad = ctx.createRadialGradient(pt.x, pt.y, 0, pt.x, pt.y, radius);
        grad.addColorStop(0, this._heatRgb(t, 0.55 + t * 0.35));
        grad.addColorStop(0.42, this._heatRgb(t, 0.18 + t * 0.22));
        grad.addColorStop(1, this._heatRgb(t, 0));
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, radius, 0, Math.PI * 2);
        ctx.fill();

        if (t > 0.55) {
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, 3 + t * 2.5, 0, Math.PI * 2);
          ctx.fillStyle = this._heatRgb(t, 0.75);
          ctx.fill();
        }
      }
    },
  });

  COUNTRIES.forEach((c, i) => { c.rank = i + 1; });
  map.addLayer(new HeatCanvas());
  fitWorldFullWidth();

  setTimeout(() => { map.invalidateSize(); fitWorldFullWidth(); }, 50);
  setTimeout(() => { map.invalidateSize(); fitWorldFullWidth(); }, 250);
  window.addEventListener('load', () => { map.invalidateSize(); fitWorldFullWidth(); });
  window.addEventListener('resize', () => { map.invalidateSize(); fitWorldFullWidth(); });
  if (RELOAD > 0) setTimeout(() => location.reload(), RELOAD);
})();
</script>
<?php endif; ?>

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
</script>
<?php if (!$embedded): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
