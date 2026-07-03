<?php
/**
 * TAILSCALE BOARD — 1920×1080 signage
 * Peer online/offline grid from the Tailscale admin API.
 */

require_once dirname(__DIR__, 2) . '/lib/tailscale_lib.php';

define('BOARD_TITLE', (string)cfg('tailscale.BOARD_TITLE', 'Tailscale'));
define('BOARD_SUB', (string)cfg('tailscale.BOARD_SUB', 'Mesh network'));
define('TIMEZONE', cfg('tailscale.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$configured = tailscale_configured();
$data = tailscale_fetch_devices();
$devices = is_array($data['devices'] ?? null) ? $data['devices'] : [];
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : ['total' => 0, 'online' => 0, 'offline' => 0];
$cacheTtl = tailscale_cache_ttl();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(BOARD_TITLE) ?> — Signage</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --ok:#59db8f; --bad:#e45959; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:flex; flex-direction:column; gap:22px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }
  .summary { display:flex; gap:14px; flex-wrap:wrap; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px; border-radius:999px;
          border:1px solid var(--hairline); background:var(--harbor); font-size:22px; color:var(--mist); }
  .pill strong { color:var(--snow); font-variant-numeric:tabular-nums; }
  .pill .dot { width:12px; height:12px; border-radius:50%; }
  .pill .dot.ok { background:var(--ok); }
  .pill .dot.bad { background:var(--bad); }
  .grid { flex:1; min-height:0; display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; align-content:start; overflow:hidden; }
  .dev { padding:14px 16px; border-radius:10px; background:var(--harbor); border:1px solid rgba(38,52,77,.55);
         display:grid; grid-template-columns:14px 1fr; gap:12px; align-items:start; min-width:0; }
  .dev .dot { width:14px; height:14px; border-radius:50%; background:var(--ok); margin-top:6px; }
  .dev.off .dot { background:var(--bad); }
  .dev .name { font-size:22px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .dev .meta { font-size:16px; color:var(--mist); margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .setupmsg,.err { font-size:24px; color:var(--mist); line-height:1.6; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?> <span>&middot; <?= h(BOARD_SUB) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if (!$configured): ?>
    <div class="setupmsg">Set <code>TAILNET</code> and <code>TAILSCALE_API_KEY</code> in admin → Tailscale.</div>
  <?php elseif (($data['error'] ?? '') !== '' && $devices === []): ?>
    <div class="err"><?= h((string)$data['error']) ?></div>
  <?php else: ?>
    <div class="summary">
      <div class="pill"><span class="dot ok"></span> Online <strong><?= (int)($counts['online'] ?? 0) ?></strong></div>
      <div class="pill"><span class="dot bad"></span> Offline <strong><?= (int)($counts['offline'] ?? 0) ?></strong></div>
      <div class="pill">Peers <strong><?= (int)($counts['total'] ?? 0) ?></strong></div>
    </div>
    <div class="grid">
      <?php foreach ($devices as $dev): ?>
      <div class="dev<?= empty($dev['online']) ? ' off' : '' ?>">
        <span class="dot"></span>
        <div>
          <div class="name"><?= h((string)($dev['name'] ?? '')) ?></div>
          <div class="meta"><?= h((string)($dev['os'] ?? '')) ?><?php if (!empty($dev['tags'])): ?> · <?= h(implode(', ', $dev['tags'])) ?><?php endif; ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="stamp">Tailscale · cache <?= (int)$cacheTtl ?>s<?php if (!empty($data['stale'])): ?> · stale<?php endif; ?></div>
</div>
<?php if ($showClock): ?>
<script>
(function () {
  const tz = <?= json_encode(TIMEZONE, JSON_UNESCAPED_SLASHES) ?>;
  const el = document.getElementById('clock');
  function tick() {
    el.textContent = new Date().toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', timeZone: tz });
  }
  tick(); setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
<?php if (!isset($_GET['noticker'])): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
