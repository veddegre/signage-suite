<?php
/**
 * ATTACK MAP — 1920×1080 pew-pew map
 *
 * Cloudflare Radar L7 origin→target attack pairs.
 * Requires Cloudflare API token (shared with Cloudflare Radar board).
 */

require_once __DIR__ . '/attackmap_lib.php';

define('TITLE', cfg('attackmap.TITLE', 'Attack Map'));
define('SUBTITLE', cfg('attackmap.SUBTITLE', 'Cloudflare Radar · L7 flows'));
define('TIMEZONE', cfg('attackmap.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('attackmap.RELOAD_SEC', 300));
define('ANIM_SEC', max(2.0, min(12.0, (float)cfg('attackmap.ANIM_SEC', 4.5))));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$configured = attackmap_configured();
$flows = $configured ? attackmap_fetch_flows() : [];
$range = attackmap_date_range();
$hasData = $configured && $flows !== [];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

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
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --origin:#ff6b6b; --target:#39c46d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; position:relative; min-height:0; overflow:hidden; }
  .mapwrap { position:absolute; inset:0; background:#080e18; }
  #attackMap { width:100%; height:100%; }
  #attackMap.leaflet-container,
  #attackMap .leaflet-container { width:100% !important; height:100% !important; background:#080e18; }
  #attackMap .leaflet-control-attribution { font-size:11px; background:rgba(8,14,24,.85); color:var(--mist); }
  #attackMap .leaflet-control-attribution a { color:var(--mist); }
  .attack-canvas { pointer-events:none; z-index:450; }

  .overlay-head { position:absolute; top:<?= $boardH < 1080 ? 18 : 24 ?>px; left:<?= $boardH < 1080 ? 28 : 32 ?>px;
                    right:<?= $boardH < 1080 ? 28 : 32 ?>px; z-index:600; display:flex;
                    align-items:flex-start; justify-content:space-between; gap:24px; pointer-events:none; }
  .overlay-head .title-block { min-width:0; }
  .overlay-head h1 { font-family:'Big Shoulders Display'; font-weight:700;
                     font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; line-height:1;
                     text-shadow:0 2px 18px rgba(0,0,0,.65); white-space:nowrap; }
  .overlay-head .sub { display:block; font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--beacon);
                       margin-top:8px; text-shadow:0 2px 18px rgba(0,0,0,.65); white-space:nowrap; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; text-shadow:0 2px 18px rgba(0,0,0,.65);
           flex-shrink:0; }

  .side { position:absolute; top:<?= $rowHead + ($boardH < 1080 ? 36 : 44) ?>px; right:<?= $boardH < 1080 ? 20 : 28 ?>px;
          width:<?= $boardH < 1080 ? 360 : 400 ?>px; max-height:calc(100% - <?= $rowHead + ($boardH < 1080 ? 120 : 140) ?>px);
          z-index:600; display:flex; flex-direction:column; gap:10px; overflow:hidden; pointer-events:none; }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist);
             background:rgba(12,20,34,.72); padding:8px 12px; border-radius:8px; border:1px solid var(--hairline); }
  .flow { background:rgba(12,20,34,.78); border:1px solid var(--hairline); border-radius:10px;
          padding:10px 12px; backdrop-filter:blur(4px); }
  .flow .route { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; line-height:1.35; }
  .flow .route .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); }
  .flow .pct { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
               color:var(--snow); margin-top:4px; }

  .map-tag { position:absolute; left:<?= $boardH < 1080 ? 28 : 32 ?>px; bottom:<?= $embedded ? 20 : 72 ?>px; z-index:600;
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
  .stamp { position:absolute; left:<?= $boardH < 1080 ? 28 : 32 ?>px; right:<?= $boardH < 1080 ? 28 : 32 ?>px;
           bottom:<?= $embedded ? 8 : 52 ?>px; z-index:600; pointer-events:none; }
</style>
</head>
<body>
<div class="board">
  <?php if ($configured): ?>
  <div class="mapwrap"><div id="attackMap"></div></div>

  <div class="overlay-head">
    <div class="title-block">
      <h1><?= h(TITLE) ?></h1>
      <div class="sub"><?= h(SUBTITLE) ?> · <?= h($range) ?></div>
    </div>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <div class="side">
    <div class="k">Top L7 attack flows</div>
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
    <b><?= count($flows) ?></b> flows · aggregated L7 attacks
    <div class="legend-dots">
      <span><i class="o"></i> origin</span>
      <span><i class="t"></i> target</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Cloudflare Radar L7',
    $range,
    count($flows) . ' arcs',
    $GLOBALS['diag']['attackmap'] ?? '',
  ]))) ?></div>

  <?php else: ?>
  <div class="setup">
    <h2>Attack map needs a Cloudflare token</h2>
    <p>Paste your Radar API token in admin — it can be shared with the <strong>Cloudflare Radar</strong> board.</p>
    <ol>
      <li>Sign in at <strong>dash.cloudflare.com</strong></li>
      <li><strong>My Profile → API Tokens → Create Token</strong></li>
      <li>Use <strong>Read all Radar data</strong> or <strong>Account → Radar</strong> permission</li>
      <li>Admin → <strong>Attack Map</strong> or <strong>Cloudflare Radar</strong> → paste token</li>
    </ol>
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
    attributionControl: true, worldCopyJump: false, maxBoundsViscosity: 1.0,
  });

  const worldBounds = L.latLngBounds(L.latLng(-58, -180), L.latLng(78, 180));
  map.setMaxBounds(worldBounds);

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd', maxZoom: 4, minZoom: 2, noWrap: true,
    attribution: '&copy; OpenStreetMap &copy; CARTO &middot; attacks &copy; Cloudflare Radar'
  }).addTo(map);

  map.fitBounds(worldBounds, { padding: [12, 12], animate: false });

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
      let tLng = t.lng;
      const dLng = tLng - o.lng;
      if (dLng > 180) tLng -= 360;
      else if (dLng < -180) tLng += 360;
      const p0 = this._map.latLngToContainerPoint([o.lat, o.lng]);
      const p1 = this._map.latLngToContainerPoint([t.lat, tLng]);
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
        ctx.strokeStyle = 'rgba(255,179,71,0.14)';
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
        grad.addColorStop(0, 'rgba(255,107,107,0.05)');
        grad.addColorStop(0.5, 'rgba(255,179,71,0.85)');
        grad.addColorStop(1, 'rgba(255,255,255,0.95)');
        ctx.strokeStyle = grad;
        ctx.lineWidth = weight + 1.2;
        ctx.shadowColor = 'rgba(255,179,71,0.9)';
        ctx.shadowBlur = 10;
        ctx.stroke();
        ctx.shadowBlur = 0;

        const hp = arc.pts[headIdx];
        ctx.beginPath();
        ctx.arc(hp[0], hp[1], 4 + weight * 0.5, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,0.95)';
        ctx.fill();

        const pulse = 0.55 + 0.45 * Math.sin(now / 280 + flow.phase * 6);
        for (const [pt, color] of [[arc.p0, '255,107,107'], [arc.p1, '57,196,109']]) {
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

  setTimeout(() => map.invalidateSize(), 50);
  setTimeout(() => {
    map.fitBounds(worldBounds, { padding: [12, 12], animate: false });
    map.invalidateSize();
  }, 250);
  window.addEventListener('load', () => map.invalidateSize());
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
