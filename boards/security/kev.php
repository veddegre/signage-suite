<?php
/**
 * CISA KEV — 1920×1080 signage
 *
 * Data: CISA Known Exploited Vulnerabilities catalog (JSON feed).
 */

require_once dirname(__DIR__, 2) . '/lib/kev_lib.php';

define('TITLE', cfg('kev.TITLE', 'CISA KEV'));
define('SUBTITLE', cfg('kev.SUBTITLE', 'Known Exploited Vulnerabilities'));
define('TIMEZONE', cfg('kev.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('kev.RELOAD_SEC', 0));
define('WARN_DAYS', kev_warn_days());
define('ADDED_DAYS', kev_added_days());

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$data = kev_board_data();
$hero = $data['hero'];
$list = $data['list'];
$stats = $data['stats'];
$hasData = $data['has_data'];
$hasActionable = $data['has_actionable'] ?? ($hero !== null);

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kev_row_class(array $row): string
{
    if (ADDED_DAYS > 0 && kev_row_added_within($row, ADDED_DAYS)) {
        return 'new';
    }
    $days = $row['due_days'] ?? null;
    if ($days !== null && $days < 0) {
        return 'overdue';
    }
    if ($days !== null && $days <= WARN_DAYS) {
        return 'due';
    }
    if (!empty($row['ransomware'])) {
        return 'ransom';
    }

    return '';
}

function kev_row_when_label(array $row): string
{
    if (ADDED_DAYS > 0 && kev_row_added_within($row, ADDED_DAYS)) {
        $added = kev_format_added_relative($row);
        if ($added !== '') {
            return $added;
        }
    }

    return kev_format_relative_date((string)($row['due'] ?? '')) ?: '—';
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
  <?= signage_theme_css() ?>

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
  .main { grid-area:main; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; grid-template-columns: 1.2fr 0.8fr; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }
  .hero { border:1px solid var(--hairline); border-radius:12px; padding:<?= $boardH < 1080 ? '14px 16px' : '16px 18px' ?>;
          background:var(--lake-night); min-width:0; }
  .hero.due { border-color:rgba(255,179,71,.45); }
  .hero.overdue { border-color:rgba(255,107,107,.55); }
  .hero.new { border-color:rgba(57,196,109,.55); }
  .hero.ransom { border-color:rgba(255,179,71,.35); }
  .hero-id { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; color:var(--beacon); }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 36 : 42 ?>px; line-height:1.08; margin-top:8px; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
  .chip { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; padding:6px 12px; border-radius:999px;
          border:1px solid var(--hairline); color:var(--mist); }
  .chip strong { color:var(--snow); }
  .hero-desc { margin-top:12px; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.45;
               max-height:<?= $boardH < 1080 ? 120 : 140 ?>px; overflow:hidden; }
  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.due { border-color:rgba(255,179,71,.45); }
  .row.overdue { border-color:rgba(255,107,107,.55); }
  .row.new { border-color:rgba(57,196,109,.45); }
  .row.new .when { color:var(--ok); }
  .row .title { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; font-weight:500; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:3px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .when { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 26 : 30 ?>px; color:var(--beacon);
               text-align:right; white-space:nowrap; }
  .row.overdue .when { color:var(--alert); }
  .empty { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--mist); line-height:1.5; }
  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-area:main; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData && $hasActionable && $hero): ?>
  <div class="summary">
    <?php if (ADDED_DAYS > 0): ?>
    <div class="pill ok"><strong><?= (int)$stats['new_to_kev'] ?></strong> new in <?= (int)ADDED_DAYS ?>d</div>
    <?php endif; ?>
    <?php if ((int)$stats['due_soon'] > 0): ?>
    <div class="pill warn"><strong><?= (int)$stats['due_soon'] ?></strong> due within <?= (int)WARN_DAYS ?>d</div>
    <?php endif; ?>
    <div class="pill"><strong><?= (int)$stats['catalog'] ?></strong> in catalog</div>
    <?php if (($stats['catalog_version'] ?? '') !== ''): ?>
    <div class="pill">Catalog <strong><?= h((string)$stats['catalog_version']) ?></strong></div>
    <?php endif; ?>
  </div>

  <div class="main">
    <section class="panel">
      <h2>Priority remediation</h2>
      <div class="hero <?= h(kev_row_class($hero)) ?>">
        <div class="hero-id"><?= h((string)$hero['id']) ?></div>
        <div class="hero-title"><?= h((string)$hero['name']) ?></div>
        <div class="hero-meta">
          <?php if (($hero['vendor'] ?? '') !== '' || ($hero['product'] ?? '') !== ''): ?>
          <span class="chip"><strong><?= h(trim((string)$hero['vendor'] . ' ' . (string)$hero['product'])) ?></strong></span>
          <?php endif; ?>
          <?php if (($hero['due'] ?? '') !== ''): ?>
          <span class="chip"><?= h(kev_format_relative_date((string)$hero['due'])) ?></span>
          <?php endif; ?>
          <?php if (ADDED_DAYS > 0 && kev_row_added_within($hero, ADDED_DAYS)): ?>
          <span class="chip"><?= h(kev_format_added_relative($hero)) ?></span>
          <?php endif; ?>
          <?php if (!empty($hero['ransomware'])): ?>
          <span class="chip warn">Ransomware use</span>
          <?php endif; ?>
          <?php if (($hero['added'] ?? '') !== '' && !(ADDED_DAYS > 0 && kev_row_added_within($hero, ADDED_DAYS))): ?>
          <span class="chip">Added <?= h((string)$hero['added']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (($hero['summary'] ?? '') !== ''): ?>
        <div class="hero-desc"><?= h((string)$hero['summary']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($list !== []): ?>
      <div class="k">More KEV entries</div>
      <div class="list">
        <?php foreach ($list as $row): ?>
        <div class="row <?= h(kev_row_class($row)) ?>">
          <div>
            <div class="title"><?= h((string)$row['id']) ?></div>
            <div class="sub"><?= h((string)$row['vendor']) ?><?= ($row['product'] ?? '') !== '' ? ' · ' . h((string)$row['product']) : '' ?></div>
          </div>
          <div class="when"><?= h(kev_row_when_label($row)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Required action</h2>
      <?php if (($hero['action'] ?? '') !== ''): ?>
      <div class="empty" style="color:var(--snow);font-size:<?= $boardH < 1080 ? 19 : 21 ?>px"><?= h((string)$hero['action']) ?></div>
      <?php else: ?>
      <div class="empty">No required action text for the featured entry.</div>
      <?php endif; ?>
      <div class="k" style="margin-top:auto">About this feed</div>
      <div class="empty">CISA mandates remediation for federal agencies; use as a prioritization signal for patching and vendor outreach.</div>
    </section>
  </div>
  <?php elseif ($hasData): ?>
  <div class="summary">
    <?php if (ADDED_DAYS > 0): ?>
    <div class="pill ok"><strong>0</strong> new in <?= (int)ADDED_DAYS ?>d</div>
    <?php endif; ?>
    <?php if ((int)$stats['due_soon'] > 0): ?>
    <div class="pill warn"><strong><?= (int)$stats['due_soon'] ?></strong> due within <?= (int)WARN_DAYS ?>d</div>
    <?php endif; ?>
    <div class="pill"><strong><?= (int)$stats['catalog'] ?></strong> in catalog</div>
  </div>
  <div class="notcfg">No actionable KEV entries for current filters.
    <?php if (ADDED_DAYS > 0): ?>Nothing newly added in the last <?= (int)ADDED_DAYS ?> days<?php endif; ?>
    <?php if ((int)($stats['watch_vendors'] ?? 0) > 0): ?> matching your watch list<?php endif; ?>.
    Set <strong>Watch vendors</strong> in admin for your stack, or widen the new-entry window.</div>
  <?php else: ?>
  <div class="notcfg">CISA KEV catalog unavailable<?= !empty($GLOBALS['diag']['kev']) ? ' — ' . h((string)$GLOBALS['diag']['kev']) : '' ?>.
    Check network access to <code>cisa.gov</code>.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'cisa.gov/kev',
    ADDED_DAYS > 0 ? ADDED_DAYS . 'd new window' : 'all additions',
    WARN_DAYS . 'd due window',
    !empty($GLOBALS['diag']['kev']) ? (string)$GLOBALS['diag']['kev'] : '',
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
