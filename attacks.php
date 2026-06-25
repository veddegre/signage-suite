<?php
/**
 * INTERNET ATTACKS — 1920×1080 signage
 *
 * DShield (SANS ISC) — free, no API key.
 * Cloudflare Radar — optional API token for L3/L7 DDoS geography.
 */

require_once __DIR__ . '/attacks_lib.php';

define('TITLE', cfg('attacks.TITLE', 'Internet Attacks'));
define('SUBTITLE', cfg('attacks.SUBTITLE', 'DShield · Cloudflare Radar'));
define('TIMEZONE', cfg('attacks.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('attacks.RELOAD_SEC', 0));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$data = attacks_fetch_all();
$dshield = $data['dshield'];
$cf = $data['cloudflare'];

$infocon = $dshield['infocon'] ?? null;
$hero = $dshield['hero'] ?? null;
$countries = $dshield['countries'] ?? [];
$countryList = $hero ? array_slice($countries, 1) : $countries;
$topPorts = $dshield['top_ports'] ?? [];
$topIps = $dshield['top_ips'] ?? [];
$cfL3 = $cf['l3_targets'] ?? [];
$cfL7 = $cf['l7_targets'] ?? [];

$showDshield = attacks_dshield_enabled();
$showCf = attacks_cloudflare_enabled();
$cfReady = !empty($cf['configured']);
$rightQuad = ($showDshield && $showCf && $cfReady);
$hasData = ($showDshield && ($hero !== null || $topPorts !== [] || $topIps !== []))
    || ($showCf && $cfReady && ($cfL3 !== [] || $cfL7 !== []));

$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$cfRange = attacks_cf_date_range();

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
          grid-template-columns: <?= $showDshield ? '1.15fr 0.85fr' : '1fr' ?>; min-width:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 20px' : '22px 24px' ?>; min-height:0; overflow:hidden;
           display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; }
  .panel h2 { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px; font-weight:600; }
  .k { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; letter-spacing:1.6px; text-transform:uppercase; color:var(--mist); }

  .hero { display:grid; grid-template-columns:auto 1fr; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; align-items:start;
          padding:<?= $boardH < 1080 ? '14px 16px' : '16px 18px' ?>; background:var(--lake-night);
          border:1px solid var(--hairline); border-radius:12px; min-width:0; }
  .hero.us { border-color:var(--beacon); }
  .hero .tag { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 22 : 24 ?>px; color:var(--beacon);
               writing-mode:vertical-rl; transform:rotate(180deg); letter-spacing:2px; }
  .hero-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 40 : 46 ?>px; line-height:1.05; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }
  .hero-meta .chip { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; padding:6px 12px; border-radius:999px;
                     border:1px solid var(--hairline); color:var(--mist); }
  .hero-meta .chip strong { color:var(--snow); }
  .hero-detail { margin-top:12px; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); line-height:1.45; }

  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row.us { border-color:rgba(255,179,71,.45); }
  .row .title { font-size:<?= $boardH < 1080 ? 18 : 19 ?>px; font-weight:500; }
  .row .code { font-family:'IBM Plex Mono',monospace; color:var(--beacon); margin-right:8px; }
  .row .sub { font-size:<?= $boardH < 1080 ? 15 : 16 ?>px; color:var(--mist); margin-top:3px; }
  .row .side { text-align:right; white-space:nowrap; }
  .row .side strong { display:block; font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
                      color:var(--snow); line-height:1; }
  .row .side span { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; color:var(--mist); }

  .right { min-height:0; display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-columns: <?= $rightQuad ? '1fr 1fr' : '1fr' ?>;
           grid-template-rows: <?= $rightQuad ? '1fr 1fr' : 'repeat(2, minmax(0, 1fr))' ?>; }
  .right.placeholder-only { grid-template-rows: 1fr; }
  .mini { min-height:0; }
  .bar { height:6px; border-radius:999px; background:var(--hairline); margin-top:8px; overflow:hidden; }
  .bar > i { display:block; height:100%; background:var(--beacon); border-radius:999px; }

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

  <?php if ($hasData || $showDshield || $showCf): ?>
  <div class="summary">
    <?php if ($infocon): ?>
    <div class="pill <?= h((string)$infocon['class']) ?>">
      <span class="dot"></span>
      SANS Infocon <strong><?= h((string)$infocon['label']) ?></strong>
    </div>
    <?php endif; ?>
    <?php if ($hero): ?>
    <div class="pill warn">
      <span class="dot"></span>
      Top target <strong><?= h((string)$hero['name']) ?></strong>
    </div>
    <?php endif; ?>
    <?php if ($showCf): ?>
    <div class="pill <?= h($cfReady && ($cfL3 !== [] || $cfL7 !== []) ? 'warn' : 'ok') ?>">
      <span class="dot"></span>
      Cloudflare Radar <strong><?= $cfReady ? h($cfRange) . ' window' : 'token needed' ?></strong>
    </div>
    <?php endif; ?>
  </div>

  <div class="main">
    <?php if ($showDshield): ?>
    <section class="panel">
      <h2>Areas under attack <span style="font-size:0.55em;color:var(--mist);font-weight:500">DShield targets</span></h2>
      <?php if ($hero): ?>
      <div class="hero <?= ($hero['code'] ?? '') === 'US' ? 'us' : '' ?>">
        <div class="tag"><?= h((string)$hero['code']) ?></div>
        <div>
          <div class="hero-title"><?= h((string)$hero['name']) ?></div>
          <div class="hero-meta">
            <span class="chip"><strong><?= h(attacks_format_count((int)$hero['targets'])) ?></strong> targets</span>
            <span class="chip"><strong><?= h(attacks_format_count((int)$hero['reports'])) ?></strong> reports</span>
            <span class="chip"><strong><?= h(attacks_format_count((int)$hero['sources'])) ?></strong> sources</span>
          </div>
          <div class="hero-detail">Countries ranked by distinct hosts reported as attack targets in the DShield sensor network.</div>
        </div>
      </div>
      <?php if ($countryList !== []): ?>
      <div class="k">More targeted regions</div>
      <div class="list">
        <?php foreach ($countryList as $c):
          $maxTargets = max(1, (int)($hero['targets'] ?? $c['targets'] ?? 1));
          $pct = min(100, round(((int)$c['targets'] / $maxTargets) * 100));
        ?>
        <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
          <div>
            <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
            <div class="sub"><?= h(attacks_format_count((int)$c['reports'])) ?> reports · <?= h(attacks_format_count((int)$c['sources'])) ?> sources</div>
            <div class="bar"><i style="width:<?= (int)$pct ?>%"></i></div>
          </div>
          <div class="side">
            <strong><?= h(attacks_format_count((int)$c['targets'])) ?></strong>
            <span>targets</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="empty">DShield country feed unavailable.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="right<?= ($showCf && !$cfReady && !$showDshield) ? ' placeholder-only' : '' ?>">
      <?php if ($showDshield): ?>
      <section class="panel mini">
        <h2>Top targeted ports</h2>
        <div class="list">
          <?php if ($topPorts === []): ?>
          <div class="empty">No port data today.</div>
          <?php else: foreach ($topPorts as $p):
            $label = attacks_port_label((int)$p['port']);
          ?>
          <div class="row">
            <div>
              <div class="title"><span class="code"><?= (int)$p['port'] ?></span><?= $label !== '' ? h($label) : 'TCP service' ?></div>
              <div class="sub"><?= h(attacks_format_count((int)$p['sources'])) ?> sources · <?= h(attacks_format_count((int)$p['targets'])) ?> targets</div>
            </div>
            <div class="side">
              <strong><?= h(attacks_format_count((int)$p['records'])) ?></strong>
              <span>records</span>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="panel mini">
        <h2>Top attacking IPs</h2>
        <div class="list">
          <?php if ($topIps === []): ?>
          <div class="empty">No IP data today.</div>
          <?php else: foreach ($topIps as $ip): ?>
          <div class="row">
            <div>
              <div class="title"><span class="code">#<?= (int)$ip['rank'] ?></span><?= h((string)$ip['ip']) ?></div>
              <div class="sub"><?= h(attacks_format_count((int)$ip['targets'])) ?> targets hit</div>
            </div>
            <div class="side">
              <strong><?= h(attacks_format_count((int)$ip['reports'])) ?></strong>
              <span>reports</span>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if ($showCf && $cfReady): ?>
      <section class="panel mini">
        <h2>L3 DDoS targets <span style="font-size:0.55em;color:var(--mist);font-weight:500">Cloudflare</span></h2>
        <div class="list">
          <?php if ($cfL3 === []): ?>
          <div class="empty">No L3 target data for <?= h($cfRange) ?>.</div>
          <?php else: foreach ($cfL3 as $c): ?>
          <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
            <div>
              <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
              <div class="bar"><i style="width:<?= min(100, (float)$c['percent']) ?>%"></i></div>
            </div>
            <div class="side">
              <strong><?= h(number_format((float)$c['percent'], 1)) ?>%</strong>
              <span>of L3 attacks</span>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="panel mini">
        <h2>L7 attack targets <span style="font-size:0.55em;color:var(--mist);font-weight:500">Cloudflare</span></h2>
        <div class="list">
          <?php if ($cfL7 === []): ?>
          <div class="empty">No L7 target data for <?= h($cfRange) ?>.</div>
          <?php else: foreach ($cfL7 as $c): ?>
          <div class="row <?= ($c['code'] ?? '') === 'US' ? 'us' : '' ?>">
            <div>
              <div class="title"><span class="code"><?= h((string)$c['code']) ?></span><?= h((string)$c['name']) ?></div>
              <div class="bar"><i style="width:<?= min(100, (float)$c['percent']) ?>%"></i></div>
            </div>
            <div class="side">
              <strong><?= h(number_format((float)$c['percent'], 1)) ?>%</strong>
              <span>of L7 attacks</span>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php elseif ($showCf && !$cfReady): ?>
      <section class="panel mini" style="grid-row: span 2">
        <h2>Cloudflare Radar</h2>
        <div class="empty">Add a Cloudflare API token with <strong>Account → Radar</strong> permission in admin to show L3/L7 DDoS geography.</div>
      </section>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="notcfg">Attack feeds unavailable<?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?>.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'isc.sans.edu',
    $cfReady ? 'Cloudflare Radar' : '',
    count($countries) . ' countries',
    count($topPorts) . ' ports',
    count($topIps) . ' IPs',
    $infocon ? 'Infocon ' . ($infocon['label'] ?? '') : '',
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
