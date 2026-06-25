<?php
/**
 * TOP PORTS — DShield targeted ports treemap (1920×1080)
 */

require_once __DIR__ . '/attackports_lib.php';

define('TITLE', cfg('attackports.TITLE', 'Top Attack Ports'));
define('SUBTITLE', cfg('attackports.SUBTITLE', 'SANS DShield · records by port'));
define('TIMEZONE', cfg('attackports.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('attackports.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$enabled = attackports_enabled();
$ports = $enabled ? attackports_fetch() : [];
$infocon = $enabled ? attacks_fetch_infocon() : null;
$hero = $ports[0] ?? null;
$hasData = $enabled && $ports !== [];

foreach ($ports as &$p) {
    $p['hue'] = attackports_port_hue((int)$p['port']);
}
unset($p);

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
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d; --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
          --ok:#39c46d; --warn:#ffb347; --crit:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; display:flex; flex-direction:column; min-height:0; overflow:hidden; }
  .topbar { flex-shrink:0; height:<?= $topBar ?>px; display:flex; align-items:center; justify-content:space-between;
            gap:24px; padding:0 32px; background:var(--harbor); border-bottom:1px solid var(--hairline); }
  .topbar h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:54px; line-height:1; }
  .topbar .sub { display:block; font-size:22px; color:var(--beacon); margin-top:6px; }
  .topbar .sub .infocon.ok { color:var(--ok); }
  .topbar .sub .infocon.warn { color:var(--warn); }
  .topbar .sub .infocon.crit { color:var(--crit); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:52px; color:var(--mist); font-variant-numeric:tabular-nums; }
  .main { flex:1; min-height:0; display:grid; grid-template-columns:1fr <?= $boardH < 1080 ? 360 : 400 ?>px; gap:0; }
  .viz { position:relative; background:#0a101a; padding:<?= $boardH < 1080 ? 20 : 28 ?>px; min-height:0; }
  #treemap { width:100%; height:100%; display:block; border-radius:12px; }
  .side { background:rgba(12,20,34,.55); border-left:1px solid var(--hairline); padding:20px 20px 16px;
          display:flex; flex-direction:column; gap:10px; overflow:hidden; }
  .side .k { font-size:14px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }
  .row { background:rgba(12,20,34,.78); border:1px solid var(--hairline); border-radius:10px; padding:10px 12px; }
  .row.hero { border-color:rgba(255,179,71,.45); }
  .row .port { font-family:'IBM Plex Mono',monospace; font-size:22px; color:var(--beacon); }
  .row .lbl { font-size:15px; color:var(--mist); margin-top:2px; }
  .row .val { font-family:'Big Shoulders Display'; font-size:30px; margin-top:4px; }
  .row .meta { font-size:13px; color:var(--mist); margin-top:2px; }
  .setup, .empty { grid-column:1/-1; display:flex; align-items:center; justify-content:center; flex-direction:column;
                   gap:18px; padding:40px; background:var(--lake-night); }
  .setup h2, .empty h2 { font-family:'Big Shoulders Display'; font-size:52px; color:var(--beacon); }
  .setup p, .empty p { font-size:22px; color:var(--mist); }
  <?= signage_stamp_css() ?>
  .stamp { padding:8px 32px 12px; text-align:right; flex-shrink:0; }
</style>
</head>
<body>
<div class="board">
  <header class="topbar">
    <div>
      <h1><?= h(TITLE) ?></h1>
      <div class="sub">
        <?= h(SUBTITLE) ?>
        <?php if ($infocon): ?> · Infocon <span class="infocon <?= h((string)$infocon['class']) ?>"><?= h((string)$infocon['label']) ?></span><?php endif; ?>
        <?php if ($hasData): ?> · <?= count($ports) ?> ports<?php endif; ?>
      </div>
    </div>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </header>

  <?php if ($enabled && $hasData): ?>
  <div class="main">
    <div class="viz"><canvas id="treemap"></canvas></div>
    <div class="side">
      <div class="k">Most targeted ports</div>
      <?php foreach (array_slice($ports, 0, min(8, count($ports))) as $i => $p): ?>
      <div class="row<?= $i === 0 ? ' hero' : '' ?>">
        <div class="port"><?= h((string)$p['port']) ?><?php if ($p['label']): ?> <span class="lbl"><?= h((string)$p['label']) ?></span><?php endif; ?></div>
        <div class="val"><?= h(attacks_format_count((int)$p['records'])) ?></div>
        <div class="meta"><?= h(attacks_format_count((int)$p['targets'])) ?> targets · <?= h(attacks_format_count((int)$p['sources'])) ?> sources</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stamp"><?= h(implode(' · ', array_filter(['isc.sans.edu', $hero ? 'Port ' . $hero['port'] . ' leads' : '']))) ?></div>
  <?php elseif ($enabled): ?>
  <div class="main"><div class="empty"><h2>No port data</h2><p>DShield topports feed is empty right now.</p></div></div>
  <?php else: ?>
  <div class="main"><div class="setup"><h2>Top ports board is disabled</h2><p>Enable DShield in admin under <strong>Top Attack Ports</strong>.</p></div></div>
  <?php endif; ?>
</div>

<?php if ($enabled && $hasData): ?>
<script>
(function () {
  const PORTS = <?= json_encode($ports, JSON_UNESCAPED_UNICODE) ?>;
  const RELOAD = <?= max(0, (int)RELOAD_SEC) ?> * 1000;
  const canvas = document.getElementById('treemap');
  const ctx = canvas.getContext('2d');

  function resize() {
    const r = canvas.parentElement.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(r.width * dpr);
    canvas.height = Math.round(r.height * dpr);
    canvas.style.width = r.width + 'px';
    canvas.style.height = r.height + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw(r.width, r.height);
  }

  function layoutRow(items, x, y, w, h, horizontal) {
    if (!items.length) return;
    const total = items.reduce((s, i) => s + i.records, 0);
    if (items.length === 1) {
      items[0].rect = { x, y, w, h };
      return;
    }
    const mid = Math.ceil(items.length / 2);
    const left = items.slice(0, mid);
    const right = items.slice(mid);
    const leftSum = left.reduce((s, i) => s + i.records, 0);
    const ratio = leftSum / total;
    if (horizontal) {
      const lw = w * ratio;
      layoutRow(left, x, y, lw, h, !horizontal);
      layoutRow(right, x + lw, y, w - lw, h, !horizontal);
    } else {
      const lh = h * ratio;
      layoutRow(left, x, y, w, lh, !horizontal);
      layoutRow(right, x, y + lh, w, h - lh, !horizontal);
    }
  }

  function draw(w, h) {
    ctx.clearRect(0, 0, w, h);
    const pad = 6;
    const items = PORTS.map(p => ({ ...p }));
    layoutRow(items, pad, pad, w - pad * 2, h - pad * 2, w >= h);
    for (const p of items) {
      const r = p.rect;
      if (!r || r.w < 4 || r.h < 4) continue;
      const t = p.intensity;
      const hue = p.hue;
      ctx.fillStyle = 'hsla(' + hue + ',72%,' + Math.round(38 + t * 18) + '%,0.92)';
      ctx.strokeStyle = 'hsla(' + hue + ',60%,55%,0.35)';
      ctx.lineWidth = 2;
      const rr = 10;
      ctx.beginPath();
      ctx.moveTo(r.x + rr, r.y);
      ctx.arcTo(r.x + r.w, r.y, r.x + r.w, r.y + r.h, rr);
      ctx.arcTo(r.x + r.w, r.y + r.h, r.x, r.y + r.h, rr);
      ctx.arcTo(r.x, r.y + r.h, r.x, r.y, rr);
      ctx.arcTo(r.x, r.y, r.x + r.w, r.y, rr);
      ctx.closePath();
      ctx.fill();
      ctx.stroke();
      if (r.w > 90 && r.h > 50) {
        ctx.fillStyle = '#edf2fb';
        ctx.font = '700 ' + Math.min(42, r.h * 0.38) + 'px "Big Shoulders Display",sans-serif';
        ctx.fillText(String(p.port), r.x + 14, r.y + r.h * 0.42);
        if (p.label && r.h > 70) {
          ctx.font = '500 18px "IBM Plex Sans",sans-serif';
          ctx.fillStyle = '#8aa0c0';
          ctx.fillText(p.label, r.x + 14, r.y + r.h * 0.58);
        }
        ctx.font = '600 ' + Math.min(28, r.h * 0.22) + 'px "IBM Plex Mono",monospace';
        ctx.fillStyle = '#ffb347';
        const txt = formatN(p.records);
        ctx.fillText(txt, r.x + 14, r.y + r.h - 14);
      }
    }
  }

  function formatN(n) {
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e4) return Math.round(n / 1e3) + 'K';
    return String(n);
  }

  resize();
  window.addEventListener('resize', resize);
  if (RELOAD > 0) setTimeout(() => location.reload(), RELOAD);
})();
</script>
<?php endif; ?>
<script><?php if ($showClock): ?>
function tick(){const n=new Date();let h=n.getHours();const ap=h>=12?'PM':'AM';h=h%12||12;
const el=document.getElementById('clock');if(el)el.textContent=h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap;}
tick();setInterval(tick,1000);<?php endif; ?></script>
<?php if (!$embedded): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
