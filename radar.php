<?php
/**
 * CLOUDFLARE RADAR — 1920×1080 signage
 *
 * L3/L7 DDoS attack geography via Cloudflare Radar API.
 * Requires a free Cloudflare API token with Account → Radar permission.
 */

require_once __DIR__ . '/radar_lib.php';

define('TITLE', cfg('radar.TITLE', 'DDoS Radar'));
define('SUBTITLE', cfg('radar.SUBTITLE', 'Cloudflare Radar'));
define('TIMEZONE', cfg('radar.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('radar.RELOAD_SEC', 0));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$data = radar_fetch_all();
$configured = !empty($data['configured']);
$hero = $data['hero'] ?? null;
$l3Targets = $data['l3_targets'] ?? [];
$l3Origins = $data['l3_origins'] ?? [];
$l7Targets = $data['l7_targets'] ?? [];
$range = radar_date_range();

$hasData = $configured && ($hero !== null || $l3Targets !== [] || $l3Origins !== [] || $l7Targets !== []);

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function radar_panel_rows(array $rows, ?array $heroRow, string $unit): array
{
    if ($heroRow === null) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn($r) => ($r['code'] ?? '') !== ($heroRow['code'] ?? '')));
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
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --ok:#39c46d; --warn:#ffb347; --crit:#ff5d5d; }
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
  .pill .dot { width:12px; height:12px; border-radius:50%; }
  .pill.ok .dot { background:var(--ok); }
  .pill.warn .dot { background:var(--warn); }

  .main { grid-area:main; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
          grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel.hero-panel { grid-column: 1 / -1; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 32 : 36 ?>px; font-weight:600; }
  .panel h2 em { font-style:normal; font-size:0.55em; color:var(--mist); font-weight:500; margin-left:8px; }

  .hero { display:grid; grid-template-columns:auto 1fr; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; align-items:center; min-width:0; }
  .hero.us { }
  .hero .tag { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px; color:var(--beacon); }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 48 : 56 ?>px; line-height:1.05; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
  .hero-meta .chip { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; padding:6px 14px; border-radius:999px;
                     border:1px solid var(--hairline); color:var(--mist); }
  .hero-meta .chip strong { color:var(--snow); }
  .hero-pct { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 72 : 84 ?>px;
              color:var(--beacon); text-align:right; line-height:1; font-variant-numeric:tabular-nums; }

  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.us { border-color:rgba(255,179,71,.45); }
  .row .title { font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; font-weight:500; }
  .row .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); margin-right:8px; }
  .bar { height:6px; border-radius:999px; background:var(--hairline); margin-top:8px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--beacon); border-radius:999px; }
  .row .side { text-align:right; white-space:nowrap; font-family:'Big Shoulders Display';
               font-size:<?= $boardH < 1080 ? 28 : 32 ?>px; color:var(--snow); }
  .row .side span { display:block; font-family:'IBM Plex Sans',sans-serif; font-size:<?= $boardH < 1080 ? 13 : 14 ?>px; color:var(--mist); }

  .empty, .notcfg { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.55; }
  .notcfg { grid-area:main; padding:20px 0; }
  .notcfg ol { margin:16px 0 0 24px; }
  .notcfg li { margin:8px 0; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?> · <?= h($range) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($configured && $hasData): ?>
  <div class="summary">
    <?php if ($hero): ?>
    <div class="pill warn">
      <span class="dot"></span>
      Top <?= h((string)$hero['layer']) ?> <?= h((string)$hero['role']) ?> <strong><?= h((string)$hero['name']) ?></strong>
    </div>
    <?php endif; ?>
    <div class="pill ok">
      <span class="dot"></span>
      Window <strong><?= h($range) ?></strong>
    </div>
  </div>

  <div class="main">
    <?php if ($hero): ?>
    <section class="panel hero-panel">
      <div class="hero <?= ($hero['code'] ?? '') === 'US' ? 'us' : '' ?>">
        <div class="tag"><?= h((string)$hero['code']) ?></div>
        <div>
          <div class="hero-title"><?= h((string)$hero['name']) ?></div>
          <div class="hero-meta">
            <span class="chip"><strong><?= h((string)$hero['layer']) ?></strong> layer</span>
            <span class="chip">Attack <strong><?= h((string)$hero['role']) ?></strong></span>
            <span class="chip">Share of traffic seen by Cloudflare</span>
          </div>
        </div>
        <div class="hero-pct"><?= h(number_format((float)$hero['percent'], 1)) ?>%</div>
      </div>
    </section>
    <?php endif; ?>

    <?php if (radar_show_l3_targets()): ?>
    <section class="panel">
      <h2>L3 DDoS targets<em>under attack</em></h2>
      <div class="list">
        <?php
        $rows = radar_panel_rows($l3Targets, ($hero['layer'] ?? '') === 'L3' && ($hero['role'] ?? '') === 'target' ? $hero : null, '%');
        if ($rows === []): ?>
        <div class="empty">No L3 target data for <?= h($range) ?>.</div>
        <?php else: foreach ($rows as $c): ?>
        <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
          <div>
            <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
            <div class="bar"><i style="width:<?= min(100, (float)$c['percent']) ?>%"></i></div>
          </div>
          <div class="side"><?= h(number_format((float)$c['percent'], 1)) ?><span>%</span></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (radar_show_l3_origins()): ?>
    <section class="panel">
      <h2>L3 attack origins<em>source countries</em></h2>
      <div class="list">
        <?php
        $rows = radar_panel_rows($l3Origins, ($hero['layer'] ?? '') === 'L3' && ($hero['role'] ?? '') === 'origin' ? $hero : null, '%');
        if ($rows === []): ?>
        <div class="empty">No L3 origin data for <?= h($range) ?>.</div>
        <?php else: foreach ($rows as $c): ?>
        <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
          <div>
            <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
            <div class="bar"><i style="width:<?= min(100, (float)$c['percent']) ?>%"></i></div>
          </div>
          <div class="side"><?= h(number_format((float)$c['percent'], 1)) ?><span>%</span></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if (radar_show_l7_targets()): ?>
    <section class="panel" style="<?= (!radar_show_l3_targets() && !radar_show_l3_origins()) ? 'grid-column:1/-1' : '' ?>">
      <h2>L7 attack targets<em>application layer</em></h2>
      <div class="list">
        <?php
        $rows = radar_panel_rows($l7Targets, ($hero['layer'] ?? '') === 'L7' ? $hero : null, '%');
        if ($rows === []): ?>
        <div class="empty">No L7 target data for <?= h($range) ?>.</div>
        <?php else: foreach ($rows as $c): ?>
        <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
          <div>
            <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
            <div class="bar"><i style="width:<?= min(100, (float)$c['percent']) ?>%"></i></div>
          </div>
          <div class="side"><?= h(number_format((float)$c['percent'], 1)) ?><span>%</span></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <?php elseif (!$configured): ?>
  <div class="notcfg">
    Cloudflare Radar needs an API token before this board can load attack geography.
    <ol>
      <li>Sign in at <strong>dash.cloudflare.com</strong> (free account is fine).</li>
      <li><strong>My Profile → API Tokens → Create Token</strong></li>
      <li>Use the <strong>“Read all Radar data”</strong> template, or a custom token with <strong>Account → Radar</strong> permission.</li>
      <li>Admin → <strong>Cloudflare Radar</strong> → paste into <strong>Cloudflare API token</strong>.</li>
    </ol>
  </div>
  <?php else: ?>
  <div class="notcfg">Cloudflare Radar feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?>.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Cloudflare Radar',
    $range,
    count($l3Targets) . ' L3 targets',
    count($l3Origins) . ' L3 origins',
    count($l7Targets) . ' L7 targets',
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
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
<?php if (!$embedded): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
