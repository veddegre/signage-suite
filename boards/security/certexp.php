<?php
/**
 * TLS CERT EXPIRY — 1920×1080 signage
 *
 * Probes configured HTTPS hosts and surfaces expiring certificates.
 */

require_once dirname(__DIR__, 2) . '/lib/certexp_lib.php';

define('TITLE', cfg('certexp.TITLE', 'TLS certificate expiry'));
define('SUBTITLE', cfg('certexp.SUBTITLE', 'Configured hosts'));
define('TIMEZONE', cfg('certexp.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('certexp.RELOAD_SEC', 0));
define('WARN_DAYS', certexp_warn_days());

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();

$data = certexp_board_data();
$hero = $data['hero'];
$list = $data['list'];
$stats = $data['stats'];
$hasData = $data['has_data'];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function certexp_days_label(?int $days): string
{
    if ($days === null) {
        return '—';
    }
    if ($days < 0) {
        return abs($days) . 'd expired';
    }
    if ($days === 0) {
        return 'today';
    }
    if ($days === 1) {
        return '1 day';
    }
    if ($days <= WARN_DAYS) {
        return $days . ' days';
    }

    return $days . 'd left';
}

function certexp_row_class(array $row): string
{
    return (string)($row['status'] ?? 'ok');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Mono:wght@500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --alert:#ff6b6b; --ok:#39c46d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-rows: <?= $rowHead ?>px auto minmax(0, 1fr) auto;
           grid-template-areas: "head" "summary" "main" "meta"; min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; gap:24px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }
  .summary { grid-area:summary; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px; border-radius:999px;
          border:1px solid var(--hairline); background:var(--harbor); font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; }
  .pill.warn strong { color:var(--beacon); }
  .pill.alert strong { color:var(--alert); }
  .pill.ok strong { color:var(--ok); }
  .main { grid-area:main; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; grid-template-columns: 1.15fr 0.85fr; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }
  .hero { border:1px solid var(--hairline); border-radius:12px; padding:<?= $boardH < 1080 ? '14px 16px' : '16px 18px' ?>;
          background:var(--lake-night); min-width:0; }
  .hero.warn { border-color:rgba(255,179,71,.45); }
  .hero.expired, .hero.error { border-color:rgba(255,107,107,.55); }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 40 : 46 ?>px; line-height:1.05; }
  .hero-host { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--beacon); margin-top:8px; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
  .chip { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; padding:6px 12px; border-radius:999px;
          border:1px solid var(--hairline); color:var(--mist); }
  .chip strong { color:var(--snow); }
  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.warn { border-color:rgba(255,179,71,.45); }
  .row.expired, .row.error { border-color:rgba(255,107,107,.55); }
  .row .title { font-size:<?= $boardH < 1080 ? 18 : 19 ?>px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:3px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .when { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 26 : 30 ?>px; color:var(--beacon);
               text-align:right; white-space:nowrap; }
  .row.expired .when, .row.error .when { color:var(--alert); }
  .empty { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--mist); line-height:1.5; }
  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-area:main; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?> · warn <?= (int)WARN_DAYS ?>d</span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData && $hero): ?>
  <div class="summary">
    <div class="pill ok"><strong><?= (int)$stats['hosts'] ?></strong> host<?= (int)$stats['hosts'] === 1 ? '' : 's' ?></div>
    <?php if ((int)$stats['expiring'] > 0): ?>
    <div class="pill warn"><strong><?= (int)$stats['expiring'] ?></strong> need attention</div>
    <?php else: ?>
    <div class="pill ok">All clear</div>
    <?php endif; ?>
  </div>

  <div class="main">
    <section class="panel">
      <h2>Attention needed</h2>
      <div class="hero <?= h(certexp_row_class($hero)) ?>">
        <div class="hero-title"><?= h((string)$hero['label']) ?></div>
        <div class="hero-host"><?= h((string)$hero['host']) ?><?= (int)($hero['port'] ?? 443) !== 443 ? ':' . (int)$hero['port'] : '' ?></div>
        <div class="hero-meta">
          <?php if (!$hero['ok']): ?>
          <span class="chip alert"><strong><?= h((string)($hero['error'] ?: 'probe failed')) ?></strong></span>
          <?php else: ?>
          <?php if (($hero['subject'] ?? '') !== ''): ?>
          <span class="chip"><strong><?= h((string)$hero['subject']) ?></strong></span>
          <?php endif; ?>
          <?php if (($hero['expires'] ?? '') !== ''): ?>
          <span class="chip">Expires <?= h((string)$hero['expires']) ?></span>
          <?php endif; ?>
          <span class="chip"><?= h(certexp_days_label($hero['days_left'])) ?></span>
          <?php if (($hero['issuer'] ?? '') !== ''): ?>
          <span class="chip"><?= h((string)$hero['issuer']) ?></span>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($list !== []): ?>
      <div class="k">All monitored hosts</div>
      <div class="list">
        <?php foreach ($list as $row): ?>
        <div class="row <?= h(certexp_row_class($row)) ?>">
          <div>
            <div class="title"><?= h((string)$row['label']) ?></div>
            <div class="sub"><?= h((string)$row['host']) ?><?= !$row['ok'] ? ' · ' . h((string)$row['error']) : (($row['expires'] ?? '') !== '' ? ' · ' . h((string)$row['expires']) : '') ?></div>
          </div>
          <div class="when"><?= h($row['ok'] ? certexp_days_label($row['days_left']) : 'ERR') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Renewal window</h2>
      <div class="empty" style="color:var(--snow);font-size:<?= $boardH < 1080 ? 19 : 21 ?>px">
        Certificates within <strong><?= (int)WARN_DAYS ?> days</strong> of expiry are highlighted. Probes run from the signage server — LAN hosts need outbound TLS from PHP.
      </div>
      <div class="k" style="margin-top:auto">Setup</div>
      <div class="empty">Admin → <strong>TLS cert expiry</strong> — add <code>HOSTS</code> rows (<code>host</code>, optional <code>label</code>, <code>port</code>).</div>
    </section>
  </div>
  <?php else: ?>
  <div class="notcfg">No TLS hosts configured — add rows under admin → <strong>TLS cert expiry</strong> (<code>certexp.HOSTS</code>).</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Direct TLS probe',
    WARN_DAYS . 'd warn window',
    (int)$stats['hosts'] . ' hosts',
  ]))) ?></div>
</div>
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
  <?php if (!$embedded && RELOAD_SEC > 0): ?>
  setTimeout(() => location.reload(), <?= (int)RELOAD_SEC * 1000 ?>);
  <?php endif; ?>
</script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
