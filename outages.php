<?php
/**
 * CLOUD OUTAGES — 1920×1080 signage
 * Public status for AWS, Azure, GitHub, Cloudflare, Microsoft 365, Google Workspace.
 *
 * Microsoft 365 uses Microsoft Graph (ServiceHealth.Read.All application permission).
 */

require_once __DIR__ . '/outages_lib.php';

define('TITLE', cfg('outages.TITLE', 'Cloud Outages'));
define('SUBTITLE', cfg('outages.SUBTITLE', 'Public status feeds'));
define('TIMEZONE', cfg('outages.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('outages.RELOAD_SEC', 300));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$providers = outages_fetch_all();
$overall = outages_overall_status($providers);
$activeCount = count(array_filter($providers, static fn($p) => ($p['status'] ?? '') !== 'operational' && ($p['status'] ?? '') !== 'unconfigured'));
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function outages_status_class(string $status): string
{
    return match ($status) {
        'operational' => 'ok',
        'degraded' => 'warn',
        'outage' => 'bad',
        'maintenance' => 'maint',
        'unconfigured' => 'off',
        default => 'unk',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
          --ok:#39c46d; --warn:#ffb347; --bad:#ff5d5d; --maint:#6eb6ff; --off:#5a7090; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-rows: <?= $rowHead ?>px auto minmax(0, 1fr) auto;
           grid-template-areas: "head" "summary" "grid" "meta"; min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; gap:24px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }

  .summary { grid-area:summary; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px; border-radius:999px;
          border:1px solid var(--hairline); background:var(--harbor); font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; }
  .pill .dot { width:12px; height:12px; border-radius:50%; }

  .grid { grid-area:grid; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
          grid-template-columns:repeat(3, minmax(0, 1fr)); grid-template-rows:repeat(2, minmax(0, 1fr)); }
  .card { background:var(--harbor); border:1px solid var(--hairline); border-radius:16px;
          padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
          display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 10 : 12 ?>px; }
  .card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; min-width:0; }
  .card-head h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600;
                   white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .badge { flex-shrink:0; font-size:<?= $boardH < 1080 ? 15 : 17 ?>px; letter-spacing:1.5px; text-transform:uppercase;
           padding:6px 12px; border-radius:999px; border:1px solid var(--hairline); color:var(--mist); }
  .badge.ok { color:var(--ok); border-color:rgba(57,196,109,.35); }
  .badge.warn { color:var(--warn); border-color:rgba(255,179,71,.35); }
  .badge.bad { color:var(--bad); border-color:rgba(255,93,93,.35); }
  .badge.maint { color:var(--maint); border-color:rgba(110,182,255,.35); }
  .badge.off { color:var(--off); }
  .card .dot { width:14px; height:14px; border-radius:50%; flex-shrink:0; }
  .dot.ok { background:var(--ok); } .dot.warn { background:var(--warn); }
  .dot.bad { background:var(--bad); } .dot.maint { background:var(--maint); } .dot.off, .dot.unk { background:var(--off); }

  .card-summary { font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--mist); line-height:1.35;
                   display:flex; align-items:center; gap:10px; min-width:0; }
  .issues { flex:1; min-height:0; overflow:hidden; display:flex; flex-direction:column; gap:8px; }
  .issue { border-top:1px solid rgba(38,52,77,.65); padding-top:8px; min-width:0; }
  .issue:first-child { border-top:none; padding-top:0; }
  .issue .title { font-size:<?= $boardH < 1080 ? 21 : 24 ?>px; line-height:1.25; font-weight:500; }
  .issue .detail { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; color:var(--mist); margin-top:4px;
                    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .empty { font-size:<?= $boardH < 1080 ? 19 : 21 ?>px; color:var(--mist); font-style:italic; }
  .errline { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--bad); }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"> · <?= h(SUBTITLE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <div class="summary">
    <div class="pill"><span class="dot <?= h(outages_status_class($overall)) ?>"></span>
      Overall <strong><?= h(outages_status_label($overall)) ?></strong></div>
    <div class="pill">Providers <strong><?= count($providers) ?></strong></div>
    <div class="pill">Active issues <strong><?= (int)$activeCount ?></strong></div>
  </div>

  <section class="grid">
    <?php if ($providers === []): ?>
    <div class="card" style="grid-column:1/-1">
      <div class="card-summary">No providers enabled — turn on feeds in admin → Cloud Outages.</div>
    </div>
    <?php else: foreach ($providers as $p):
      $st = (string)($p['status'] ?? 'unknown');
      $cls = outages_status_class($st);
      $issues = is_array($p['issues'] ?? null) ? $p['issues'] : [];
    ?>
    <article class="card">
      <div class="card-head">
        <h2><?= h((string)($p['name'] ?? '')) ?></h2>
        <span class="badge <?= h($cls) ?>"><?= h(outages_status_label($st)) ?></span>
      </div>
      <div class="card-summary">
        <span class="dot <?= h($cls) ?>"></span>
        <span><?= h((string)($p['summary'] ?? '')) ?></span>
      </div>
      <?php if (!empty($p['error']) && $st !== 'operational'): ?>
      <div class="errline"><?= h((string)$p['error']) ?></div>
      <?php endif; ?>
      <div class="issues">
        <?php if ($issues !== []): foreach ($issues as $issue): ?>
        <div class="issue">
          <div class="title"><?= h((string)($issue['title'] ?? '')) ?></div>
          <?php if (!empty($issue['detail'])): ?>
          <div class="detail"><?= h((string)$issue['detail']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; elseif ($st === 'operational'): ?>
        <div class="empty">No active incidents</div>
        <?php elseif ($st === 'unconfigured'): ?>
        <div class="empty">Add Graph credentials in admin for M365 health</div>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; endif; ?>
  </section>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Public status feeds',
    count($providers) . ' providers',
    'cache ' . outages_cache_ttl() . 's',
  ]))) ?></div>
</div>
<script>
<?php if ($showClock): ?>
(function(){
  function tick(){
    const n = new Date();
    let h = n.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const el = document.getElementById('clock');
    if (el) el.textContent = h + ':' + String(n.getMinutes()).padStart(2, '0') + ' ' + ap;
  }
  tick(); setInterval(tick, 1000);
})();
<?php endif; ?>
<?php if (!$embedded && RELOAD_SEC > 0): ?>
setTimeout(function(){ location.reload(); }, <?= (int)RELOAD_SEC * 1000 ?>);
<?php endif; ?>
</script>
<?php if (!$embedded): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
