<?php
/**
 * DSHIELD SOURCES — attack origin heatmap (1920×1080)
 */

require_once dirname(__DIR__, 2) . '/lib/dshieldmap_lib.php';

define('TITLE', cfg('dshieldsrc.TITLE', 'Attack Origins'));
define('SUBTITLE', cfg('dshieldsrc.SUBTITLE', 'SANS DShield · sources by country'));
define('TIMEZONE', cfg('dshieldsrc.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('dshieldsrc.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$enabled = dshield_heatmap_enabled('dshieldsrc');
$countries = $enabled ? dshield_heatmap_fetch('dshieldsrc') : [];
$infocon = $enabled ? attacks_fetch_infocon() : null;
$hero = $countries[0] ?? null;
$hasData = $enabled && $countries !== [];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$topBar = max(96, (int)round(104 * $boardH / 1080));
$sidebarLimit = dshield_heatmap_max_sidebar('dshieldsrc');

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<?= signage_theme_fonts_head_html() ?>
<?php if ($enabled): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= h(signage_map_canvas_js_url()) ?>"></script>
<?php endif; ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; display:flex; flex-direction:column;
           min-height:0; overflow:hidden; background:var(--tile-bg); }
  .topbar { flex-shrink:0; height:<?= $topBar ?>px; display:flex; align-items:center;
            justify-content:space-between; gap:24px; padding:0 <?= $boardH < 1080 ? 28 : 32 ?>px;
            background:var(--harbor); border-bottom:1px solid var(--hairline); z-index:700; }
  .topbar h1 { font-family:'Big Shoulders Display'; font-weight:700;
               font-size:<?= $boardH < 1080 ? 48 : 54 ?>px; line-height:1; }
  .topbar .sub { display:block; font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--beacon); margin-top:6px; }
  .topbar .sub .infocon.ok { color:var(--ok); }
  .topbar .sub .infocon.warn { color:var(--warn); }
  .topbar .sub .infocon.crit { color:var(--crit); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }
  .map-area { flex:1; min-height:0; position:relative; }
  .mapwrap { position:absolute; inset:0; }
  #heatMap { width:100%; height:100%; }
  #heatMap .leaflet-container { width:100% !important; height:100% !important; }
  #heatMap .leaflet-control-attribution { font-size:11px; }
  #heatMap .leaflet-control-attribution a { color:var(--src-muted); }
  .heat-canvas { position:absolute; left:0; top:0; pointer-events:none; z-index:450; }
  .side { position:absolute; top:<?= $boardH < 1080 ? 16 : 20 ?>px; right:<?= $boardH < 1080 ? 20 : 28 ?>px;
          width:<?= $boardH < 1080 ? 360 : 400 ?>px; max-height:calc(100% - 88px); z-index:600;
          display:flex; flex-direction:column; gap:10px; overflow:hidden; pointer-events:none;
          color:var(--src-text); }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--src-muted);
             background:var(--src-panel); padding:8px 12px; border-radius:8px; border:1px solid var(--src-border); }
  .row { background:var(--src-panel); border:1px solid var(--src-border); border-radius:10px; padding:10px 12px;
         color:var(--src-text); }
  .row.hero { border-color:rgba(126,200,255,.55); box-shadow:0 0 16px rgba(93,173,226,.12); }
  .row .code { font-family:'IBM Plex Mono',monospace; color:var(--src-accent); font-weight:600; }
  .row .val { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px; margin-top:4px;
              color:var(--src-text); line-height:1.05; }
  .row .meta { font-size:13px; color:var(--src-muted); margin-top:2px; }
  .map-tag { position:absolute; left:<?= $boardH < 1080 ? 28 : 32 ?>px; bottom:16px; z-index:600; pointer-events:none;
             font-size:16px; text-transform:uppercase; color:var(--src-muted); background:var(--src-panel);
             padding:10px 16px; border-radius:8px; border:1px solid var(--src-border); }
  .map-tag b { color:var(--src-accent); }
  .legend-bar { display:flex; align-items:center; gap:10px; margin-top:8px; font-size:13px; text-transform:none;
                color:var(--src-muted); }
  .legend-bar .grad { width:120px; height:10px; border-radius:5px;
                      background:linear-gradient(90deg, rgba(93,173,226,.2), rgba(126,200,255,.65), rgba(93,173,226,.95)); }
  .setup, .empty { position:absolute; inset:0; z-index:700; display:flex; align-items:center; justify-content:center;
                    flex-direction:column; gap:18px; text-align:center; padding:40px; background:var(--tile-bg); }
  .setup h2, .empty h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p, .empty p { font-size:22px; color:var(--mist); max-width:920px; }
  <?= signage_stamp_css() ?>
  .stamp { position:absolute; right:28px; bottom:10px; left:auto; z-index:600; pointer-events:none;
           color:var(--src-muted); opacity:.95; }
</style>
</head>
<body>
<div class="board">
  <header class="topbar">
    <div class="title-block">
      <h1><?= h(TITLE) ?></h1>
      <div class="sub">
        <?= h(SUBTITLE) ?>
        <?php if ($infocon): ?> · Infocon <span class="infocon <?= h((string)$infocon['class']) ?>"><?= h((string)$infocon['label']) ?></span><?php endif; ?>
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
    <div class="k">Top attack sources</div>
    <?php foreach (array_slice($countries, 0, $sidebarLimit) as $i => $c): ?>
    <div class="row<?= $i === 0 ? ' hero' : '' ?>">
      <div><span class="code"><?= h((string)$c['code']) ?></span> <?= h((string)$c['name']) ?></div>
      <div class="val"><?= h(attacks_format_count((int)$c['value'])) ?> sources</div>
      <div class="meta"><?= h(attacks_format_count((int)$c['reports'])) ?> reports · <?= h(attacks_format_count((int)$c['targets'])) ?> targets</div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="map-tag">
    <b><?= h(attacks_format_count((int)($hero['value'] ?? 0))) ?></b> peak · <?= h((string)($hero['name'] ?? '')) ?>
    <div class="legend-bar"><span>low</span><div class="grad"></div><span>high</span></div>
  </div>
  <?php else: ?>
  <div class="empty"><h2>DShield feed unavailable</h2><p>Could not load country sources.</p></div>
  <?php endif; ?>
  <div class="stamp"><?= h(implode(' · ', array_filter(['isc.sans.edu', count($countries) . ' countries']))) ?></div>
  <?php else: ?>
  <div class="setup"><h2>DShield sources map is disabled</h2><p>Enable in admin under <strong>Attack Origins</strong>.</p></div>
  <?php endif; ?>
  </div>
</div>
<?php if ($enabled && $hasData): ?>
<script>
(function () {
  const COUNTRIES = <?= json_encode($countries, JSON_UNESCAPED_UNICODE) ?>;
  const RELOAD = <?= max(0, (int)RELOAD_SEC) ?> * 1000;
  function heatMapPoint(map, lat, lng) {
    const p = map.latLngToContainerPoint([lat, lng]);
    return { x: p.x, y: p.y };
  }
  function heatDrawCode(ctx, code, x, y) {
    ctx.font = '600 10px "IBM Plex Mono", monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.lineWidth = 3;
    ctx.lineJoin = 'round';
    ctx.strokeStyle = 'rgba(8,14,24,0.9)';
    ctx.strokeText(code, x, y);
    ctx.fillStyle = 'rgba(237,242,251,0.95)';
    ctx.fillText(code, x, y);
  }
  const map = L.map('heatMap', { zoomControl:false, dragging:false, scrollWheelZoom:false, doubleClickZoom:false,
    boxZoom:false, keyboard:false, touchZoom:false, attributionControl:true, worldCopyJump:false, zoomSnap:0 });
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains:'abcd', maxZoom:6, minZoom:0, noWrap:true,
    attribution:'&copy; OpenStreetMap &copy; CARTO &middot; sources &copy; SANS DShield'
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
      m.getContainer().appendChild(this._canvas);
      m.on('move resize viewreset zoomend', this._resize, this);
      this._resize();
      this._drawBound = (now) => this._draw(now || performance.now());
      SignageMapCanvas.bindAnimLoop(this, this._drawBound);
    },
    onRemove(m) {
      SignageMapCanvas.unbindAnimLoop(this);
      m.off('move resize viewreset zoomend', this._resize, this);
      L.DomUtil.remove(this._canvas);
    },
    _resize() {
      const sz = this._map.getSize();
      this._dpr = SignageMapCanvas.resizeCanvas(this._canvas, sz.x, sz.y);
      this._rebuildHeatPoints();
    },
    _rebuildHeatPoints() {
      this._heatPts = COUNTRIES.map((c) => ({
        c,
        pt: heatMapPoint(this._map, c.lat, c.lng),
      }));
    },
    _heatRgb(t, alpha) {
      const r = Math.round(25 + t * 65);
      const g = Math.round(55 + t * 145);
      const b = Math.round(100 + t * 155);
      return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    },
    _draw(now) {
      const ctx = this._canvas.getContext('2d');
      const dpr = this._dpr || 1;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, this._canvas.width / dpr, this._canvas.height / dpr);
      const pulse = 0.92 + 0.08 * Math.sin(now / 900);
      for (const row of this._heatPts || []) {
        const c = row.c;
        const pt = row.pt;
        const t = c.intensity;
        const r = (10 + t * 46) * (c.rank === 1 ? pulse : 1);
        const g = ctx.createRadialGradient(pt.x, pt.y, 0, pt.x, pt.y, r);
        g.addColorStop(0, this._heatRgb(t, 0.34 + t * 0.26));
        g.addColorStop(0.42, this._heatRgb(t, 0.12 + t * 0.14));
        g.addColorStop(1, this._heatRgb(t, 0));
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, r, 0, Math.PI * 2);
        ctx.fill();
        if (SignageMapCanvas.shouldDrawHeatLabel(c.rank, t)) {
          ctx.beginPath();
          ctx.arc(pt.x, pt.y, 3.5 + t * 2.5, 0, Math.PI * 2);
          ctx.fillStyle = this._heatRgb(t, 0.88);
          ctx.fill();
          heatDrawCode(ctx, c.code, pt.x, pt.y);
        }
      }
    }
  });
  COUNTRIES.forEach((c, i) => { c.rank = i + 1; });
  fitWorldFullWidth();
  map.addLayer(new HeatCanvas());
  setTimeout(() => { map.invalidateSize(); fitWorldFullWidth(); }, 50);
  setTimeout(() => { map.invalidateSize(); fitWorldFullWidth(); }, 250);
  window.addEventListener('load', () => { map.invalidateSize(); fitWorldFullWidth(); });
  window.addEventListener('resize', () => { map.invalidateSize(); fitWorldFullWidth(); });
  if (RELOAD>0) setTimeout(()=>location.reload(),RELOAD);
})();
</script>
<?php endif; ?>
<script><?php if ($showClock): ?><?= signage_clock_tick_script('clock', TIMEZONE) ?><?php endif; ?></script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
