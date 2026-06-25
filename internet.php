<?php
/**
 * INTERNET INFRASTRUCTURE — 1920×1080 signage
 *
 * BGP/ASN outages via IODA (Georgia Tech) — free, no API key.
 * DNS root reachability via CHAOS TXT probes (requires dig).
 */

require_once __DIR__ . '/internet_lib.php';

define('TITLE', cfg('internet.TITLE', 'Internet Infrastructure'));
define('SUBTITLE', cfg('internet.SUBTITLE', 'BGP · DNS roots'));
define('TIMEZONE', cfg('internet.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('internet.RELOAD_SEC', 0));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$bgpItems = internet_bgp_fetch_items();
$dnsRoots = internet_dns_roots_fetch();
$bgpSummary = internet_bgp_summary($bgpItems);
$dnsSummary = internet_dns_summary($dnsRoots);

$bgpHero = $bgpItems[0] ?? null;
$bgpList = array_slice($bgpItems, 1);
$dnsDown = $dnsSummary['down'];

$showBgp = internet_bgp_enabled();
$showDns = internet_dns_enabled();
$hasData = ($showBgp && $bgpHero !== null) || ($showDns && $dnsRoots !== []);

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function internet_format_time(int $ts): string
{
    if ($ts <= 0) {
        return '';
    }
    return date('M j, g:i A', $ts);
}

function internet_level_class(string $level): string
{
    return match ($level) {
        'critical' => 'crit',
        'warn' => 'warn',
        default => 'ok',
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
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --alert:#ff6b6b;
          --ok:#39c46d; --warn:#ffb347; --crit:#ff5d5d; }
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
  .pill.crit .dot { background:var(--crit); }

  .main { grid-area:main; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
          grid-template-columns: <?= ($showBgp && $showDns) ? '1.1fr 0.9fr' : '1fr' ?>; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 36 : 40 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }

  .hero { display:grid; grid-template-columns:auto 1fr; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; align-items:start;
          padding:<?= $boardH < 1080 ? '14px 16px' : '16px 18px' ?>; background:var(--lake-night);
          border:1px solid var(--hairline); border-radius:12px; min-width:0; }
  .hero .tag { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 22 : 24 ?>px; color:var(--beacon);
               writing-mode:vertical-rl; transform:rotate(180deg); letter-spacing:2px; }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 38 : 44 ?>px; line-height:1.05; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
  .hero-meta .chip { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; padding:6px 12px; border-radius:999px;
                     border:1px solid var(--hairline); color:var(--mist); }
  .hero-meta .chip strong { color:var(--snow); }
  .hero-meta .chip.crit { border-color:var(--crit); color:var(--crit); }
  .hero-meta .chip.warn { border-color:var(--warn); color:var(--warn); }
  .hero-detail { margin-top:12px; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.45; }

  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row .title { font-size:<?= $boardH < 1080 ? 18 : 19 ?>px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:3px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .side { text-align:right; white-space:nowrap; font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); }
  .row .side strong { display:block; font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
                      color:var(--snow); line-height:1; }

  .root-grid { flex:1; min-height:0; display:grid; gap:<?= $boardH < 1080 ? 8 : 10 ?>px;
               grid-template-columns: repeat(4, minmax(0, 1fr)); align-content:start; }
  .root { padding:<?= $boardH < 1080 ? '10px 8px' : '12px 10px' ?>; background:var(--lake-night);
          border:1px solid var(--hairline); border-radius:10px; text-align:center; min-width:0; }
  .root.down { border-color:var(--crit); background:rgba(255,93,93,.08); }
  .root .letter { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; line-height:1; }
  .root .op { font-size:<?= $boardH < 1080 ? 12 : 13 ?>px; color:var(--mist); margin-top:4px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .root .stat { margin-top:8px; font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; color:var(--mist); }
  .root .stat strong { color:var(--ok); }
  .root.down .stat strong { color:var(--crit); }

  .dns-alert { padding:<?= $boardH < 1080 ? '12px 14px' : '14px 16px' ?>; border-radius:10px;
               border:1px solid var(--crit); background:rgba(255,93,93,.1); color:var(--snow);
               font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; line-height:1.45; }
  .dns-alert strong { color:var(--crit); }

  .empty { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.5; padding:8px 0; }
  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-area:main; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?><?php if ($showBgp): ?> · IODA <?= (int)internet_lookback_days() ?>d<?php endif; ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <div class="summary">
    <?php if ($showBgp): ?>
    <div class="pill <?= h($bgpSummary['has_issues'] ? 'warn' : 'ok') ?>">
      <span class="dot"></span>
      BGP/ASN <strong><?= $bgpSummary['has_issues'] ? $bgpSummary['critical'] . ' critical' : 'no critical alerts' ?></strong>
    </div>
    <?php endif; ?>
    <?php if ($showDns): ?>
    <div class="pill <?= h($dnsSummary['all_ok'] ? 'ok' : 'crit') ?>">
      <span class="dot"></span>
      DNS roots <strong><?= (int)$dnsSummary['up'] ?>/<?= (int)$dnsSummary['total'] ?> responding</strong>
    </div>
    <?php endif; ?>
    <?php if (internet_us_only()): ?>
    <div class="pill">Scope <strong>United States</strong></div>
    <?php endif; ?>
  </div>

  <div class="main">
    <?php if ($showBgp): ?>
    <section class="panel">
      <h2>Routing &amp; ASN outages</h2>
      <?php if ($bgpHero): ?>
      <div class="hero">
        <div class="tag"><?= h(strtoupper((string)($bgpHero['datasource'] ?? 'IODA'))) ?></div>
        <div>
          <div class="hero-title"><?= h((string)$bgpHero['title']) ?></div>
          <div class="hero-meta">
            <span class="chip <?= h(internet_level_class((string)($bgpHero['level'] ?? ''))) ?>">
              <strong><?= h(ucfirst((string)($bgpHero['level'] ?? 'normal'))) ?></strong>
            </span>
            <span class="chip"><strong><?= h((string)$bgpHero['subtitle']) ?></strong></span>
            <?php if (!empty($bgpHero['time'])): ?>
            <span class="chip">Started <strong><?= h(internet_format_time((int)$bgpHero['time'])) ?></strong></span>
            <?php endif; ?>
            <?php if (!empty($bgpHero['ongoing'])): ?>
            <span class="chip warn"><strong>Ongoing</strong></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($bgpHero['detail']) || $bgpHero['score'] !== null): ?>
          <div class="hero-detail">
            <?php if ($bgpHero['score'] !== null): ?>Severity score <?= h(number_format((float)$bgpHero['score'], 0)) ?><?php endif; ?>
            <?php if (!empty($bgpHero['detail'])): ?><?= $bgpHero['score'] !== null ? ' · ' : '' ?><?= h((string)$bgpHero['detail']) ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($bgpList !== []): ?>
      <div class="k">More events</div>
      <div class="list">
        <?php foreach ($bgpList as $item): ?>
        <div class="row">
          <div>
            <div class="title"><?= h((string)$item['title']) ?></div>
            <div class="sub"><?= h((string)$item['subtitle']) ?><?php if (!empty($item['time'])): ?> · <?= h(internet_format_time((int)$item['time'])) ?><?php endif; ?></div>
          </div>
          <div class="side">
            <?php if ($item['score'] !== null): ?>
            <strong><?= h(number_format((float)$item['score'], 0)) ?></strong>
            score
            <?php elseif (!empty($item['detail'])): ?>
            <?= h((string)$item['detail']) ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="empty">No BGP or ASN outage events in the lookback window.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($showDns): ?>
    <section class="panel">
      <h2>DNS root servers</h2>
      <?php if ($dnsDown !== []): ?>
      <div class="dns-alert">
        <strong><?= count($dnsDown) ?> root<?= count($dnsDown) === 1 ? '' : 's' ?> not responding</strong>
        — <?= h(implode(', ', array_map(static fn($r) => (string)($r['letter'] ?? '?'), $dnsDown))) ?>
      </div>
      <?php endif; ?>
      <div class="root-grid">
        <?php foreach ($dnsRoots as $root): ?>
        <div class="root <?= !empty($root['ok']) ? '' : 'down' ?>">
          <div class="letter"><?= h((string)$root['letter']) ?></div>
          <div class="op"><?= h((string)$root['operator']) ?></div>
          <div class="stat">
            <?php if (!empty($root['ok'])): ?>
            <strong><?= $root['latency_ms'] !== null ? h((string)$root['latency_ms']) . ' ms' : 'OK' ?></strong>
            <?php else: ?>
            <strong><?= h((string)($root['error'] !== '' ? $root['error'] : 'down')) ?></strong>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="notcfg">Internet infrastructure feeds unavailable.
    <?php if ($showDns && internet_dig_path() === null): ?>Install <code>dnsutils</code> (<code>dig</code>) for DNS root probes.<?php endif; ?>
    <?php if ($GLOBALS['diag']): ?> — <?= h($GLOBALS['diag']['ioda'] ?? '') ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'IODA',
    $showDns ? 'CHAOS TXT probes' : '',
    $showBgp ? count($bgpItems) . ' routing events' : '',
    $showDns ? $dnsSummary['up'] . '/' . $dnsSummary['total'] . ' roots up' : '',
    internet_us_only() ? 'US scope' : 'global',
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
