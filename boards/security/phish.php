<?php
/**
 * PHISHING & BRAND THREATS — 1920×1080 signage
 *
 * URLhaus recent malware/phishing URLs (abuse.ch) + optional brand CT watch (crt.sh).
 * Malicious hosts are defanged — never rendered as clickable links.
 */

require_once dirname(__DIR__, 2) . '/lib/phish_lib.php';

define('TITLE', cfg('phish.TITLE', 'Phishing & brand threats'));
define('SUBTITLE', cfg('phish.SUBTITLE', 'abuse.ch · crt.sh'));
define('TIMEZONE', cfg('phish.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('phish.RELOAD_SEC', 0));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$data = phish_board_data();
$urls = $data['urls'];
$brands = $data['brands'];
$hasData = $data['has_data'];
$needsAuth = $data['needs_auth'];

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
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 50 : 58 ?>px; }
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
          grid-template-columns: 0.95fr 1.05fr; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }

  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.warn { border-color:rgba(255,107,107,.45); }
  .row.ok { border-color:rgba(57,196,109,.35); }
  .row .title { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--beacon);
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:4px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .meta { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
  .chip { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; padding:4px 10px; border-radius:999px;
          border:1px solid var(--hairline); color:var(--mist); }
  .chip.online { border-color:rgba(255,107,107,.45); color:var(--alert); }
  .chip.offline { opacity:.75; }

  .tags { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
  .tag { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; padding:4px 10px; border-radius:999px;
         background:var(--lake-night); border:1px solid var(--hairline); color:var(--mist); }

  .empty { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--mist); line-height:1.5; }
  .setup { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--mist); line-height:1.55; }
  .setup code { background:var(--lake-night); padding:2px 8px; border-radius:6px; color:var(--snow); }
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

  <?php if ($hasData): ?>
  <div class="summary">
    <?php if ($urls !== []): ?>
    <div class="pill warn"><strong><?= count($urls) ?></strong> URLhaus hits</div>
    <div class="pill"><strong><?= (int)$data['online_count'] ?></strong> online</div>
    <?php endif; ?>
    <?php if ($brands !== []): ?>
    <div class="pill <?= (int)$data['brand_hits'] > 0 ? 'alert' : 'ok' ?>">
      <strong><?= (int)$data['brand_hits'] ?></strong> brand lookalike<?= (int)$data['brand_hits'] === 1 ? '' : 's' ?>
    </div>
    <?php endif; ?>
    <?php if ($data['top_tags'] !== []):
      $topTag = array_key_first($data['top_tags']);
    ?>
    <div class="pill">Top tag <strong><?= h((string)$topTag) ?></strong></div>
    <?php endif; ?>
  </div>

  <div class="main">
    <section class="panel">
      <h2>Brand watch</h2>
      <?php if ($brands === []): ?>
      <div class="setup">Add rows under admin → <strong>Phishing & brand threats → Brand watch</strong> with a root domain and keywords (e.g. <code>gvsu.edu</code>, <code>vdrs.fyi</code>).</div>
      <?php else: ?>
      <div class="list">
        <?php foreach ($brands as $brand): ?>
        <div class="row <?= ($brand['hit_count'] ?? 0) > 0 ? 'warn' : 'ok' ?>">
          <div class="title"><?= h((string)$brand['label']) ?></div>
          <div class="sub"><?= h((string)$brand['root_domain']) ?><?= !empty($brand['keywords']) ? ' · watch ' . h(implode(', ', (array)$brand['keywords'])) : '' ?></div>
          <?php if (!empty($brand['hits'])): ?>
          <?php foreach ($brand['hits'] as $hit): ?>
          <div class="sub" style="margin-top:6px"><?= h(phish_defang_host((string)$hit)) ?></div>
          <?php endforeach; ?>
          <?php else: ?>
          <div class="sub" style="margin-top:6px">No suspicious CT names in lookback window</div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>URLhaus recent</h2>
      <?php if ($urls === []): ?>
      <div class="setup">
        <?php if ($needsAuth): ?>
        Paste an <strong>abuse.ch Auth-Key</strong> in admin (free at auth.abuse.ch) to load recent malware/phishing URLs.
        <?php else: ?>
        No URLs matched your tag filters. Adjust <strong>Tags include</strong> or turn off <strong>Online only</strong>.
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="list">
        <?php foreach ($urls as $row): ?>
        <div class="row">
          <div class="title"><?= h((string)$row['host_defang']) ?></div>
          <div class="sub"><?= h((string)$row['threat']) ?><?= $row['tags_label'] !== '' ? ' · ' . h((string)$row['tags_label']) : '' ?></div>
          <div class="meta">
            <span class="chip <?= !empty($row['online']) ? 'online' : 'offline' ?>"><?= !empty($row['online']) ? 'online' : h((string)$row['status']) ?></span>
            <?php if ($row['added'] !== ''): ?>
            <span class="chip"><?= h(phish_format_added((string)$row['added'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($data['top_tags'] !== []): ?>
      <div class="k" style="margin-top:8px">Tag mix</div>
      <div class="tags">
        <?php foreach ($data['top_tags'] as $tag => $count): ?>
        <span class="tag"><?= h((string)$tag) ?> · <?= (int)$count ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
  <?php else: ?>
  <div class="notcfg">Threat feeds unavailable.
    <?php if ($needsAuth): ?>Add a URLhaus Auth-Key in admin.<?php endif; ?>
    <?php if (!empty($GLOBALS['diag'])): ?> — <?= h(implode('; ', $GLOBALS['diag'])) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Hosts defanged — not clickable',
    'urlhaus.abuse.ch',
    count($urls) . ' URLs',
    count($brands) . ' brand rows',
    !empty($GLOBALS['diag']) ? implode('; ', $GLOBALS['diag']) : '',
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
