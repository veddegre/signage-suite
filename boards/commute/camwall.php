<?php
/**
 * CAMERA WALL — 1920×1080 signage
 * Dense grid of MDOT Mi Drive still cameras on the Allendale ↔ Grand Rapids corridor
 * (I-96, I-196, US-131). Images are proxied via camwall_img.php.
 *
 * Setup: admin → Camera wall — override the built-in corridor set or tune the grid.
 * Add camwall.php to rotation (90–120s dwell pairs well with traffic.php).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/emergency_lib.php';
require_once dirname(__DIR__, 2) . '/lib/camwall_lib.php';

define('TITLE', cfg('camwall.TITLE', 'Commute Cameras'));
define('SUBTITLE', cfg('camwall.SUBTITLE', 'Allendale ↔ Grand Rapids · I-96 · I-196 · US-131'));
define('ATTRIBUTION', cfg('camwall.ATTRIBUTION', 'MDOT Mi Drive'));
define('SHOW_OVERLAY', cfg('camwall.SHOW_OVERLAY', true));
define('REFRESH_SEC', max(15, (int)cfg('camwall.REFRESH_SEC', 45)));
define('TIMEZONE', cfg('camwall.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$tickerOnPage = !$embedded && (signage_ticker_enabled() || emergency_ticker_forces_display());
$grid = camwall_grid_size();
$cameras = camwall_active_cameras();
$slots = $grid['slots'];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$tiles = [];
foreach ($cameras as $cam) {
    $tiles[] = [
        'key' => $cam['key'],
        'name' => $cam['name'],
        'route' => $cam['route'],
        'src' => camwall_image_proxy_url($cam['key']),
        'focus' => (string)($cam['focus'] ?? 'center center'),
    ];
}
while (count($tiles) < $slots) {
    $tiles[] = ['key' => '', 'name' => '', 'route' => '', 'src' => '', 'focus' => 'center center'];
}

$compact = $boardH < 1080;
$headH = $compact ? 68 : 80;
$gap = $compact ? 6 : 8;
$pad = $compact ? 12 : 16;
$heightPx = $boardH . 'px';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
    --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
    <?php if ($tickerOnPage): ?>--signage-ticker-inset: <?= (int)SIGNAGE_TICKER_H ?>px;<?php endif; ?>
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= h($heightPx) ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; cursor:none; }
  .board { width:1920px; height:100%; padding:<?= $pad ?>px <?= $pad + 4 ?>px <?= $pad ?>px;
           display:grid; gap:<?= $gap ?>px;
           grid-template-rows: <?= SHOW_OVERLAY ? $headH . 'px' : '0px' ?> minmax(0, 1fr) auto; min-height:0; }
  .head { display:flex; align-items:flex-start; justify-content:space-between; gap:32px; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:700;
             font-size:<?= $compact ? 38 : 46 ?>px; letter-spacing:.4px; line-height:1.05; }
  .head .sub { display:block; margin-top:4px; font-size:<?= $compact ? 14 : 16 ?>px; font-weight:500;
               letter-spacing:1.2px; text-transform:uppercase; color:var(--mist); }
  .head-right { display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex:0 0 auto;
                min-width:220px; padding-top:2px; }
  #clock { font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:600;
           font-size:<?= $compact ? 34 : 40 ?>px; color:var(--snow); font-variant-numeric:tabular-nums;
           line-height:1; white-space:nowrap; }
  .meta { text-align:right; font-size:<?= $compact ? 13 : 14 ?>px; color:var(--mist); white-space:nowrap; }
  .grid { min-height:0; display:grid; gap:<?= $gap ?>px;
          grid-template-columns:repeat(<?= (int)$grid['cols'] ?>, minmax(0, 1fr));
          grid-template-rows:repeat(<?= (int)$grid['rows'] ?>, minmax(0, 1fr)); }
  .tile { position:relative; min-height:0; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:<?= $compact ? 8 : 10 ?>px; overflow:hidden; }
  .tile.empty { opacity:.35; }
  .tile img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center;
              background:#0a1018; display:block; }
  .tile img.err { opacity:.15; }
  .cap { position:absolute; left:0; right:0; top:0; z-index:2; display:flex; align-items:center; gap:8px;
         padding:<?= $compact ? '6px 10px' : '8px 12px' ?>;
         background:linear-gradient(180deg, rgba(12,20,34,.92) 0%, rgba(12,20,34,.72) 70%, transparent 100%);
         pointer-events:none; }
  .route { font-size:<?= $compact ? 10 : 11 ?>px; font-weight:600; letter-spacing:.7px; text-transform:uppercase;
           color:var(--beacon); background:rgba(255,179,71,.14); border:1px solid rgba(255,179,71,.35);
           border-radius:999px; padding:2px 7px; white-space:nowrap; flex:0 0 auto; }
  .name { font-size:<?= $compact ? 14 : 15 ?>px; font-weight:500; color:var(--snow);
          white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .stamp { font-size:13px; color:var(--mist); opacity:.85; text-align:right; line-height:1.2; }
  .empty-msg { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
               color:var(--mist); font-size:15px; padding:12px; text-align:center; }
</style>
</head>
<body>
<div class="board">
  <?php if (SHOW_OVERLAY): ?>
  <header class="head">
    <div>
      <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?></span></h1>
    </div>
    <div class="head-right">
      <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
      <div class="meta"><?= count($cameras) ?> live feeds · refresh <?= (int)REFRESH_SEC ?>s</div>
    </div>
  </header>
  <?php endif; ?>
  <div class="grid" id="grid">
    <?php foreach ($tiles as $i => $tile): ?>
    <div class="tile<?= $tile['src'] === '' ? ' empty' : '' ?>" data-key="<?= h($tile['key']) ?>">
      <?php if ($tile['src'] !== ''): ?>
      <img id="cam-<?= (int)$i ?>" alt="<?= h($tile['name']) ?>" src="<?= h($tile['src']) ?>"
           data-base="<?= h($tile['src']) ?>" loading="eager"
           style="object-position:<?= h($tile['focus']) ?>">
      <div class="cap">
        <?php if ($tile['route'] !== ''): ?><span class="route"><?= h($tile['route']) ?></span><?php endif; ?>
        <span class="name"><?= h($tile['name']) ?></span>
      </div>
      <?php else: ?>
      <div class="empty-msg">—</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (ATTRIBUTION !== ''): ?>
  <div class="stamp"><?= h(ATTRIBUTION) ?></div>
  <?php endif; ?>
</div>
<script>
(function(){
  const refreshMs = <?= (int)REFRESH_SEC ?> * 1000;

  function bust(base) {
    const sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 't=' + Date.now();
  }

  function refreshAll() {
    document.querySelectorAll('.tile img[data-base]').forEach(function(img) {
      const next = bust(img.getAttribute('data-base'));
      img.onload = function() { img.classList.remove('err'); };
      img.onerror = function() { img.classList.add('err'); };
      img.src = next;
    });
  }

  setInterval(refreshAll, refreshMs);
})();
<?php if ($showClock && SHOW_OVERLAY): ?>
(function(){
  const tz = <?= json_encode(TIMEZONE) ?>;
  function tick(){
    const el = document.getElementById('clock');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('en-US', {
      hour: 'numeric', minute: '2-digit', hour12: true, timeZone: tz
    });
  }
  tick(); setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
<?php
if (!$embedded) {
    include dirname(__DIR__, 2) . '/ticker.php';
}
?>
</body>
</html>
