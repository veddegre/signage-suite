<?php
/**
 * L3 ATTACK MAP — 1920×1080 pew-pew map
 *
 * Cloudflare Radar L3 origin→target volumetric attack pairs.
 */

require_once dirname(__DIR__, 2) . '/lib/l3map_lib.php';

define('TITLE', cfg('l3map.TITLE', 'L3 Attack Map'));
define('SUBTITLE', cfg('l3map.SUBTITLE', 'Cloudflare Radar · L3 DDoS flows'));
define('TIMEZONE', cfg('l3map.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('l3map.RELOAD_SEC', 300));
define('ANIM_SEC', max(2.0, min(12.0, (float)cfg('l3map.ANIM_SEC', 5.0))));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$configured = l3map_configured();
$flows = $configured ? l3map_fetch_flows() : [];
$range = l3map_date_range();
$hasData = $configured && $flows !== [];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$topBar = max(96, (int)round(104 * $boardH / 1080));

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
<?php if ($configured): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<style>
  <?= signage_theme_css() ?>

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
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }
  .map-area { flex:1; min-height:0; position:relative; background:#080e18; }
  .mapwrap { position:absolute; inset:0; }
  #attackMap { width:100%; height:100%; }
  #attackMap.leaflet-container,
  #attackMap .leaflet-container { width:100% !important; height:100% !important; background:#080e18; }
  #attackMap .leaflet-control-attribution { font-size:11px; background:rgba(8,14,24,.85); color:var(--mist); }
  #attackMap .leaflet-control-attribution a { color:var(--mist); }
  .attack-canvas { pointer-events:none; z-index:450; }

  .side { position:absolute; top:<?= $boardH < 1080 ? 16 : 20 ?>px; right:<?= $boardH < 1080 ? 20 : 28 ?>px;
          width:<?= $boardH < 1080 ? 360 : 400 ?>px; max-height:calc(100% - <?= $boardH < 1080 ? 88 : 96 ?>px);
          z-index:600; display:flex; flex-direction:column; gap:10px; overflow:hidden; pointer-events:none; }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist);
             background:rgba(12,20,34,.72); padding:8px 12px; border-radius:8px; border:1px solid var(--hairline); }
  .flow { background:rgba(12,20,34,.78); border:1px solid var(--hairline); border-radius:10px;
          padding:10px 12px; backdrop-filter:blur(4px); }
  .flow .route { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; line-height:1.35; }
  .flow .route .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); }
  .flow .pct { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
               color:var(--snow); margin-top:4px; }

  .map-tag { position:absolute; left:<?= $boardH < 1080 ? 28 : 32 ?>px; bottom:<?= $boardH < 1080 ? 16 : 20 ?>px; z-index:600;
             pointer-events:none; font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; letter-spacing:1.5px;
             text-transform:uppercase; color:var(--mist); background:rgba(12,20,34,.78); padding:10px 16px;
             border-radius:8px; border:1px solid var(--hairline); }
  .map-tag b { color:var(--beacon); }
  .legend-dots { display:flex; gap:16px; margin-top:6px; font-size:14px; text-transform:none; letter-spacing:0; }
  .legend-dots span { display:inline-flex; align-items:center; gap:6px; }
  .legend-dots i { width:10px; height:10px; border-radius:50%; display:inline-block; }
  .legend-dots .o { background:var(--origin); box-shadow:0 0 8px var(--origin); }
  .legend-dots .t { background:var(--target); box-shadow:0 0 8px var(--target); }

  .setup { position:absolute; inset:0; z-index:700; display:flex; align-items:center; justify-content:center;
           flex-direction:column; gap:18px; text-align:center; padding:40px; background:var(--lake-night); }
  .setup h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p, .setup ol { font-size:22px; color:var(--mist); line-height:1.55; max-width:920px; text-align:left; }
  .setup ol { margin-top:8px; padding-left:24px; }
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
      <div class="sub"><?= h(SUBTITLE) ?> · <?= h($range) ?></div>
    </div>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </header>

  <?php if ($configured): ?>
  <div class="map-area">
  <div class="mapwrap"><div id="attackMap"></div></div>

  <?php if ($hasData): ?>
  <div class="side">
    <div class="k">Top L3 DDoS flows</div>
    <?php foreach (array_slice($flows, 0, 6) as $f): ?>
    <div class="flow">
      <div class="route">
        <span class="code"><?= h((string)$f['origin']['code']) ?></span> <?= h((string)$f['origin']['name']) ?>
        → <span class="code"><?= h((string)$f['target']['code']) ?></span> <?= h((string)$f['target']['name']) ?>
      </div>
      <div class="pct"><?= h(number_format((float)$f['percent'], 1)) ?>%</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="map-tag">
    <b><?= count($flows) ?></b> flows · aggregated L3 attacks
    <div class="legend-dots">
      <span><i class="o"></i> origin</span>
      <span><i class="t"></i> target</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Cloudflare Radar L3',
    $range,
    count($flows) . ' arcs',
    $GLOBALS['diag']['l3map'] ?? '',
  ]))) ?></div>
  </div>

  <?php else: ?>
  <div class="map-area">
  <div class="setup">
    <h2>L3 attack map needs a Cloudflare token</h2>
    <p>Paste your Radar API token in admin — it can be shared with the <strong>Cloudflare Radar</strong> board.</p>
    <ol>
      <li>Sign in at <strong>dash.cloudflare.com</strong></li>
      <li><strong>My Profile → API Tokens → Create Token</strong></li>
      <li>Use <strong>Read all Radar data</strong> or <strong>Account → Radar</strong> permission</li>
      <li>Admin → <strong>L3 Attack Map</strong> or <strong>Cloudflare Radar</strong> → paste token</li>
    </ol>
  </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($configured): ?>
<script>
(function () {
  const FLOWS = <?= json_encode($flows, JSON_UNESCAPED_UNICODE) ?>;
  const ANIM_SEC = <?= json_encode(ANIM_SEC) ?>;
  const RELOAD = <?= max(0, (int)RELOAD_SEC) ?> * 1000;

  const map = L.map('attackMap', {
    zoomControl: false, dragging: false, scrollWheelZoom: false,
    doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false,
    attributionControl: true, worldCopyJump: false, zoomSnap: 0,
  });

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd', maxZoom: 6, minZoom: 0, noWrap: true,
    attribution: '&copy; OpenStreetMap &copy; CARTO &middot; L3 attacks &copy; Cloudflare Radar'
  }).addTo(map);

  /** Fit the full 360° world to the map width (fitBounds crops longitude on wide screens). */
  function fitWorldFullWidth() {
    const size = map.getSize();
    if (!size.x || !size.y) return;
    const padX = 6;
    const w = Math.max(256, size.x - padX * 2);
    const zoom = Math.log(w / 256) / Math.LN2;
    map.setView([18, 0], zoom, { animate: false });
  }

  fitWorldFullWidth();

  if (FLOWS.length) {
  FLOWS.forEach((f, i) => { f.phase = i * 0.41; });

  const AttackCanvas = L.Layer.extend({
    onAdd(m) {
      this._map = m;
      this._canvas = L.DomUtil.create('canvas', 'attack-canvas');
      const pane = m.getPanes().overlayPane;
      pane.appendChild(this._canvas);
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
    _arcPoints(o, t, steps) {
      const mapW = this._map.getSize().x;
      const p0 = this._map.latLngToContainerPoint([o.lat, o.lng]);
      let p1 = this._map.latLngToContainerPoint([t.lat, t.lng]);
      let dLng = t.lng - o.lng;
      if (dLng > 180) dLng -= 360;
      else if (dLng < -180) dLng += 360;
      if (dLng > 0 && p1.x < p0.x) p1.x += mapW;
      else if (dLng < 0 && p1.x > p0.x) p1.x -= mapW;
      const mx = (p0.x + p1.x) / 2;
      const my = (p0.y + p1.y) / 2;
      const dx = p1.x - p0.x;
      const dy = p1.y - p0.y;
      const len = Math.hypot(dx, dy) || 1;
      const bulge = Math.min(160, len * 0.32);
      const cx = mx - (dy / len) * bulge;
      const cy = my + (dx / len) * bulge;
      const pts = [];
      for (let i = 0; i <= steps; i++) {
        const u = i / steps;
        const x = (1 - u) * (1 - u) * p0.x + 2 * (1 - u) * u * cx + u * u * p1.x;
        const y = (1 - u) * (1 - u) * p0.y + 2 * (1 - u) * u * cy + u * u * p1.y;
        pts.push([x, y]);
      }
      return { pts, p0, p1 };
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

      for (const flow of FLOWS) {
        const arc = this._arcPoints(flow.origin, flow.target, 56);
        const weight = Math.max(1.2, Math.min(4.5, flow.percent / 2.5));

        ctx.beginPath();
        arc.pts.forEach((p, i) => (i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1])));
        ctx.strokeStyle = 'rgba(179,136,255,0.14)';
        ctx.lineWidth = weight;
        ctx.stroke();

        const t = (((now / 1000) + flow.phase) % ANIM_SEC) / ANIM_SEC;
        const headIdx = Math.max(1, Math.floor(t * (arc.pts.length - 1)));
        const tailIdx = Math.max(0, headIdx - Math.floor(arc.pts.length * 0.22));

        ctx.beginPath();
        for (let i = tailIdx; i <= headIdx; i++) {
          const p = arc.pts[i];
          if (i === tailIdx) ctx.moveTo(p[0], p[1]);
          else ctx.lineTo(p[0], p[1]);
        }
        const grad = ctx.createLinearGradient(
          arc.pts[tailIdx][0], arc.pts[tailIdx][1],
          arc.pts[headIdx][0], arc.pts[headIdx][1]
        );
        grad.addColorStop(0, 'rgba(179,136,255,0.05)');
        grad.addColorStop(0.5, 'rgba(201,160,255,0.85)');
        grad.addColorStop(1, 'rgba(255,255,255,0.95)');
        ctx.strokeStyle = grad;
        ctx.lineWidth = weight + 1.2;
        ctx.shadowColor = 'rgba(201,160,255,0.9)';
        ctx.shadowBlur = 10;
        ctx.stroke();
        ctx.shadowBlur = 0;

        const hp = arc.pts[headIdx];
        ctx.beginPath();
        ctx.arc(hp[0], hp[1], 4 + weight * 0.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)';
        ctx.fill();

        const pulse = 0.55 + 0.45 * Math.sin(now / 280 + flow.phase * 6);
        for (const [pt, color] of [[arc.p0, '179,136,255'], [arc.p1, '77,208,225']]) {
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, 5 + pulse * 2, 0, Math.PI * 2);
          ctx.fillStyle = 'rgba(' + color + ',' + (0.35 + pulse * 0.25) + ')';
          ctx.fill();
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, 2.5, 0, Math.PI * 2);
          ctx.fillStyle = 'rgb(' + color + ')';
          ctx.fill();
        }
      }
    },
  });

  map.addLayer(new AttackCanvas());
  }

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
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
