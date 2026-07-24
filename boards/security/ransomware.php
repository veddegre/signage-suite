<?php
/**
 * RANSOMWARE TRACKER — 1920×1080 signage
 *
 * Data: Ransomware.live API v2 (free, rate-limited).
 * Victim posts are unverified extortion-site claims.
 */

require_once dirname(__DIR__, 2) . '/lib/ransomware_lib.php';

define('TITLE', cfg('ransomware.TITLE', 'Ransomware tracker'));
define('SUBTITLE', cfg('ransomware.SUBTITLE', 'Ransomware.live'));
define('TIMEZONE', cfg('ransomware.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('ransomware.RELOAD_SEC', 0));
define('LOOKBACK_DAYS', ransomware_lookback_days());
define('HIGHLIGHT_COUNTRY', ransomware_highlight_country());
define('SHOW_INFOSTEALER', ransomware_show_infostealer());

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$data = ransomware_board_data();
$hero = $data['hero'];
$list = $data['list'];
$stats = $data['stats'];
$hasData = $data['has_data'];

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ransomware_row_class(array $row): string
{
    if (HIGHLIGHT_COUNTRY !== '' && ($row['country'] ?? '') === HIGHLIGHT_COUNTRY) {
        return 'us';
    }

    return '';
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

  .main { grid-area:main; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
          grid-template-columns: 1.15fr 0.85fr; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }

  .hero { display:grid; grid-template-columns: <?= $hero && !empty($hero['screenshot']) ? '220px 1fr' : 'auto 1fr' ?>;
          gap:<?= $boardH < 1080 ? 16 : 20 ?>px; align-items:start; min-width:0; }
  .hero.us { border:1px solid rgba(255,179,71,.45); border-radius:12px; padding:<?= $boardH < 1080 ? '12px 14px' : '14px 16px' ?>; background:var(--lake-night); }
  .hero-shot { width:<?= $boardH < 1080 ? 200 : 220 ?>px; height:<?= $boardH < 1080 ? 120 : 132 ?>px; border-radius:10px;
               object-fit:cover; border:1px solid var(--hairline); background:var(--lake-night); }
  .hero-tag { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; color:var(--beacon);
              writing-mode:vertical-rl; transform:rotate(180deg); letter-spacing:2px; }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 40 : 46 ?>px; line-height:1.05; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
  .chip { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; padding:6px 12px; border-radius:999px;
          border:1px solid var(--hairline); color:var(--mist); }
  .chip strong { color:var(--snow); }
  .chip.ok { border-color:rgba(57,196,109,.45); }
  .hero-desc { margin-top:12px; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.45;
               max-height:<?= $boardH < 1080 ? 120 : 140 ?>px; overflow:hidden; }

  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.us { border-color:rgba(255,179,71,.45); }
  .row .title { font-size:<?= $boardH < 1080 ? 18 : 19 ?>px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:3px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .when { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 26 : 30 ?>px; color:var(--beacon);
               text-align:right; white-space:nowrap; }

  .sector { display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center;
            padding:8px 0; border-bottom:1px solid var(--hairline); font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; }
  .sector:last-child { border-bottom:0; }
  .sector strong { color:var(--snow); font-weight:600; }
  .bar { height:6px; border-radius:999px; background:var(--hairline); margin-top:6px; overflow:hidden; grid-column:1/-1; }
  .bar > i { display:block; height:100%; background:var(--beacon); border-radius:999px; }

  .right { min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; grid-template-rows: repeat(2, minmax(0, 1fr)); }
  .empty { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--mist); line-height:1.5; }
  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-area:main; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?> · last <?= (int)LOOKBACK_DAYS ?> days</span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData && $hero): ?>
  <div class="summary">
    <div class="pill warn"><strong><?= (int)$stats['window_count'] ?></strong> claims in window</div>
    <?php if ((int)$stats['month_count'] > 0): ?>
    <div class="pill"><strong><?= (int)$stats['month_count'] ?></strong> this month</div>
    <?php endif; ?>
    <?php if (HIGHLIGHT_COUNTRY !== '' && (int)$stats['highlight_count'] > 0): ?>
    <div class="pill alert"><strong><?= (int)$stats['highlight_count'] ?></strong> <?= h(HIGHLIGHT_COUNTRY) ?></div>
    <?php endif; ?>
    <?php if ($stats['top_groups'] !== []):
      $topGroup = array_key_first($stats['top_groups']);
    ?>
    <div class="pill">Top group <strong><?= h((string)$topGroup) ?></strong></div>
    <?php endif; ?>
    <?php if ($hero['corroborated']): ?>
    <div class="pill ok">Press corroborated</div>
    <?php endif; ?>
  </div>

  <div class="main">
    <section class="panel">
      <h2>Recent victim claims</h2>
      <div class="hero <?= h(ransomware_row_class($hero)) ?>">
        <?php if (!empty($hero['screenshot'])): ?>
        <img class="hero-shot" src="<?= h((string)$hero['screenshot']) ?>" alt="">
        <?php else: ?>
        <div class="hero-tag"><?= h($hero['group_label']) ?></div>
        <?php endif; ?>
        <div>
          <div class="hero-title"><?= h((string)$hero['victim']) ?></div>
          <div class="hero-meta">
            <span class="chip"><strong><?= h((string)$hero['group_label']) ?></strong></span>
            <?php if ($hero['country'] !== ''): ?>
            <span class="chip"><strong><?= h((string)($hero['country_name'] ?: $hero['country'])) ?></strong></span>
            <?php endif; ?>
            <?php if ($hero['activity'] !== ''): ?>
            <span class="chip"><?= h((string)$hero['activity']) ?></span>
            <?php endif; ?>
            <?php if ($hero['discovered'] !== ''): ?>
            <span class="chip"><?= h(ransomware_format_relative((string)$hero['discovered'])) ?></span>
            <?php endif; ?>
            <?php if (SHOW_INFOSTEALER && (int)$hero['infostealer_users'] > 0): ?>
            <span class="chip">Infostealer <strong><?= (int)$hero['infostealer_users'] ?></strong> users</span>
            <?php endif; ?>
          </div>
          <?php if ($hero['description'] !== ''): ?>
          <div class="hero-desc"><?= h((string)$hero['description']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($list !== []): ?>
      <div class="k">More claims</div>
      <div class="list">
        <?php foreach ($list as $row): ?>
        <div class="row <?= h(ransomware_row_class($row)) ?>">
          <div>
            <div class="title"><?= h((string)$row['victim']) ?></div>
            <div class="sub"><?= h((string)$row['group_label']) ?><?= $row['activity'] !== '' ? ' · ' . h((string)$row['activity']) : '' ?><?= $row['country'] !== '' ? ' · ' . h((string)($row['country_name'] ?: $row['country'])) : '' ?></div>
          </div>
          <div class="when"><?= h(ransomware_format_relative((string)$row['discovered'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <div class="right">
      <section class="panel">
        <h2>Top sectors</h2>
        <div class="list">
          <?php if ($stats['top_sectors'] === []): ?>
          <div class="empty">No sector tags in this window.</div>
          <?php else:
            $maxSector = max(1, (int)max($stats['top_sectors']));
            foreach ($stats['top_sectors'] as $name => $count):
              $pct = min(100, (int)round(((int)$count / $maxSector) * 100));
          ?>
          <div class="sector">
            <span><?= h((string)$name) ?></span>
            <strong><?= (int)$count ?></strong>
            <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="panel">
        <h2>Active groups</h2>
        <div class="list">
          <?php if ($stats['top_groups'] === []): ?>
          <div class="empty">No group data in this window.</div>
          <?php else: foreach ($stats['top_groups'] as $name => $count): ?>
          <div class="row">
            <div>
              <div class="title"><?= h((string)$name) ?></div>
              <div class="sub"><?= (int)$count ?> claim<?= (int)$count === 1 ? '' : 's' ?> in window</div>
            </div>
            <div class="when"><?= (int)$count ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
    </div>
  </div>
  <?php else: ?>
  <div class="notcfg">Ransomware feed unavailable<?= !empty($GLOBALS['diag']) ? ' — ' . h((string)($GLOBALS['diag']['ransomware'] ?? '')) : '' ?>.
    Check network access to <code>api.ransomware.live</code> (free API, 1 request/min).</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'api.ransomware.live',
    'Unverified extortion claims — not confirmed breaches',
    count($data['rows']) . ' shown',
    LOOKBACK_DAYS . 'd window',
    !empty($GLOBALS['diag']) ? (string)($GLOBALS['diag']['ransomware'] ?? '') : '',
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
