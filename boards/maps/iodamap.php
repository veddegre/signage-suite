<?php
/**
 * IODA OUTAGE MAP — country connectivity disruptions (1920×1080)
 */

require_once dirname(__DIR__, 2) . '/lib/iodamap_lib.php';

define('TITLE', cfg('iodamap.TITLE', 'Outage Map'));
define('SUBTITLE', cfg('iodamap.SUBTITLE', 'IODA · connectivity disruptions'));
define('TIMEZONE', cfg('iodamap.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('iodamap.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$enabled = iodamap_enabled();
$countries = $enabled ? iodamap_fetch_map() : [];
$lookback = iodamap_lookback_days();
$hero = $countries[0] ?? null;
$ongoing = count(array_filter($countries, static fn($c) => !empty($c['ongoing'])));
$hasData = $enabled && $countries !== [];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$topBar = max(96, (int)round(104 * $boardH / 1080));
$sidebarLimit = iodamap_max_sidebar();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function iodamap_format_score(float $score): string
{
    if ($score >= 100000) {
        return round($score / 1000) . 'K';
    }
    if ($score >= 10000) {
        return number_format((int)round($score));
    }
    return number_format((int)round($score));
}
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
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; display:flex; flex-direction:column; min-height:0; overflow:hidden; }
  .topbar { flex-shrink:0; height:<?= $topBar ?>px; display:flex; align-items:center; justify-content:space-between;
            gap:24px; padding:0 32px; background:var(--harbor); border-bottom:1px solid var(--hairline); }
  .topbar h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:54px; line-height:1; }
  .topbar .sub { display:block; font-size:22px; color:var(--beacon); margin-top:6px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:52px; color:var(--mist); font-variant-numeric:tabular-nums; }
  .map-area { flex:1; min-height:0; position:relative; background:#080e18; }
  .mapwrap { position:absolute; inset:0; }
  #heatMap { width:100%; height:100%; }
  #heatMap.leaflet-container { width:100% !important; height:100% !important; background:#080e18; }
  #heatMap .leaflet-control-attribution { font-size:11px; background:rgba(8,14,24,.85); color:var(--mist); }
  .heat-canvas { pointer-events:none; z-index:450; }
  .side { position:absolute; top:20px; right:28px; width:400px; max-height:calc(100% - 88px); z-index:600;
          display:flex; flex-direction:column; gap:10px; overflow:hidden; pointer-events:none; }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist);
             background:rgba(12,20,34,.72); padding:8px 12px; border-radius:8px; border:1px solid var(--hairline); }
  .row { background:rgba(12,20,34,.78); border:1px solid var(--hairline); border-radius:10px; padding:10px 12px; }
  .row.hero { border-color:rgba(217,167,255,.45); }
  .row.ongoing { box-shadow:0 0 16px rgba(217,167,255,.15); }
  .row .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); }
  .row .val { font-family:'Big Shoulders Display'; font-size:32px; margin-top:4px; }
  .row .meta { font-size:13px; color:var(--mist); margin-top:2px; }
  .pill-live { display:inline-block; font-size:11px; letter-spacing:1px; text-transform:uppercase;
               color:#ffb347; border:1px solid rgba(255,179,71,.45); border-radius:999px; padding:2px 8px; margin-left:8px; }
  .map-tag { position:absolute; left:32px; bottom:16px; z-index:600; pointer-events:none; font-size:16px;
             text-transform:uppercase; color:var(--mist); background:rgba(12,20,34,.78); padding:10px 16px;
             border-radius:8px; border:1px solid var(--hairline); }
  .map-tag b { color:var(--beacon); }
  .legend-bar { display:flex; align-items:center; gap:10px; margin-top:8px; font-size:13px; text-transform:none; }
  .legend-bar .grad { width:120px; height:10px; border-radius:5px;
                      background:linear-gradient(90deg, rgba(150,90,220,.15), rgba(217,167,255,.55), rgba(255,179,71,.9)); }
  .setup, .empty { position:absolute; inset:0; z-index:700; display:flex; align-items:center; justify-content:center;
                    flex-direction:column; gap:18px; text-align:center; padding:40px; background:var(--lake-night); }
  .setup h2, .empty h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p, .empty p { font-size:22px; color:var(--mist); max-width:920px; }
  <?= signage_stamp_css() ?>
  .stamp { position:absolute; right:28px; bottom:10px; left:auto; z-index:600; pointer-events:none; }
</style>
</head>
<body>
<div class="board">
  <header class="topbar">
    <div>
      <h1><?= h(TITLE) ?></h1>
      <div class="sub">
        <?= h(SUBTITLE) ?> · <?= (int)$lookback ?>d lookback
        <?php if ($hasData): ?> · <?= count($countries) ?> countries<?php if ($ongoing): ?> · <b><?= $ongoing ?> ongoing</b><?php endif; ?><?php endif; ?>
      </div>
    </div>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </header>
  <div class="map-area">
  <?php if ($enabled): ?>
  <div class="mapwrap"><div id="heatMap"></div></div>
  <?php if ($hasData): ?>
  <div class="side">
    <div class="k">Worst outage signals</div>
    <?php foreach (array_slice($countries, 0, $sidebarLimit) as $i => $c): ?>
    <div class="row<?= $i === 0 ? ' hero' : '' ?><?= !empty($c['ongoing']) ? ' ongoing' : '' ?>">
      <div>
        <span class="code"><?= h((string)$c['code']) ?></span> <?= h((string)$c['name']) ?>
        <?php if (!empty($c['ongoing'])): ?><span class="pill-live">live</span><?php endif; ?>
      </div>
      <div class="val"><?= h(iodamap_format_score((float)$c['score'])) ?> score</div>
      <div class="meta">
        <?= (int)$c['events'] ?> events
        <?php if ($c['datasource']): ?> · <?= h((string)$c['datasource']) ?><?php endif; ?>
        <?php if ((int)$c['duration'] > 0): ?> · <?= h(internet_format_duration((int)$c['duration'])) ?><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="map-tag">
    <b><?= h((string)($hero['name'] ?? '')) ?></b> peak outage
    <div class="legend-bar"><span>low</span><div class="grad"></div><span>critical</span></div>
  </div>
  <?php else: ?>
  <div class="empty">
    <h2>No significant outages</h2>
    <p>IODA shows no country-level disruption above the threshold in the last <?= (int)$lookback ?> days.</p>
  </div>
  <?php endif; ?>
  <div class="stamp"><?= h(implode(' · ', array_filter(['IODA Georgia Tech', count($countries) . ' countries', $GLOBALS['diag']['ioda'] ?? '']))) ?></div>
  <?php else: ?>
  <div class="setup"><h2>Outage map is disabled</h2><p>Enable IODA in admin under <strong>Outage Map</strong> or <strong>Internet Infrastructure</strong>.</p></div>
  <?php endif; ?>
  </div>
</div>
<?php if ($enabled && $hasData): ?>
<script>
(function () {
  const COUNTRIES = <?= json_encode($countries, JSON_UNESCAPED_UNICODE) ?>;
  const RELOAD = <?= max(0, (int)RELOAD_SEC) ?> * 1000;
  const map = L.map('heatMap', { zoomControl:false, dragging:false, scrollWheelZoom:false, doubleClickZoom:false,
    boxZoom:false, keyboard:false, touchZoom:false, attributionControl:true, worldCopyJump:false, zoomSnap:0 });
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains:'abcd', maxZoom:6, minZoom:0, noWrap:true,
    attribution:'&copy; OpenStreetMap &copy; CARTO &middot; outages &copy; IODA'
  }).addTo(map);
  function fitWorld() {
    const s = map.getSize(); if (!s.x) return;
    map.setView([18, 0], Math.log(Math.max(256, s.x - 12) / 256) / Math.LN2, { animate:false });
  }
  const HeatCanvas = L.Layer.extend({
    onAdd(m) { this._map=m; this._canvas=L.DomUtil.create('canvas','heat-canvas');
      m.getPanes().overlayPane.appendChild(this._canvas);
      m.on('move resize viewreset zoomend', this._resize, this); this._resize();
      this._tick=this._tick.bind(this); requestAnimationFrame(this._tick); },
    onRemove(m) { cancelAnimationFrame(this._raf); m.off('move resize viewreset zoomend', this._resize, this);
      L.DomUtil.remove(this._canvas); },
    _resize() { const sz=this._map.getSize(); L.DomUtil.setPosition(this._canvas, this._map.containerPointToLayerPoint([0,0]));
      const dpr=window.devicePixelRatio||1; this._canvas.width=Math.round(sz.x*dpr); this._canvas.height=Math.round(sz.y*dpr);
      this._canvas.style.width=sz.x+'px'; this._canvas.style.height=sz.y+'px'; this._dpr=dpr; },
    _rgb(t, a, ongoing) {
      const r = Math.round(90 + t * 130 + (ongoing ? 35 : 0));
      const g = Math.round(35 + t * 60);
      const b = Math.round(120 + t * 100);
      return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    },
    _tick(now) { this._draw(now||performance.now()); this._raf=requestAnimationFrame(this._tick); },
    _draw(now) {
      const ctx=this._canvas.getContext('2d'), dpr=this._dpr||1;
      ctx.setTransform(dpr,0,0,dpr,0,0); ctx.clearRect(0,0,this._canvas.width/dpr,this._canvas.height/dpr);
      for (const c of COUNTRIES) {
        const pt=this._map.latLngToContainerPoint([c.lat,c.lng]), t=c.intensity;
        const pulse = c.ongoing ? (0.85 + 0.15 * Math.sin(now / 420)) : 1;
        const radius = (12 + t * 54) * (c.rank === 1 ? pulse : (c.ongoing ? pulse : 1));
        const g=ctx.createRadialGradient(pt.x,pt.y,0,pt.x,pt.y,radius);
        g.addColorStop(0, this._rgb(t, 0.5 + t * 0.35, c.ongoing));
        g.addColorStop(0.45, this._rgb(t, 0.2 + t * 0.18, c.ongoing));
        g.addColorStop(1, this._rgb(t, 0, c.ongoing));
        ctx.fillStyle=g; ctx.beginPath(); ctx.arc(pt.x,pt.y,radius,0,Math.PI*2); ctx.fill();
        if (c.ongoing) {
          ctx.beginPath(); ctx.arc(pt.x, pt.y, 4 + t * 2, 0, Math.PI * 2);
          ctx.strokeStyle = 'rgba(255,179,71,0.85)'; ctx.lineWidth = 2; ctx.stroke();
        }
      }
    }
  });
  COUNTRIES.forEach((c,i)=>{c.rank=i+1;});
  map.addLayer(new HeatCanvas()); fitWorld();
  setTimeout(()=>{map.invalidateSize();fitWorld();},50);
  setTimeout(()=>{map.invalidateSize();fitWorld();},250);
  if (RELOAD>0) setTimeout(()=>location.reload(),RELOAD);
})();
</script>
<?php endif; ?>
<script><?php if ($showClock): ?>
function tick(){const n=new Date();let h=n.getHours();const ap=h>=12?'PM':'AM';h=h%12||12;
const el=document.getElementById('clock');if(el)el.textContent=h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);<?php endif; ?></script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
