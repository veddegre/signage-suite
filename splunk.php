<?php
/**
 * SPLUNK BOARD — 1920×1080 signage
 * Renders a grid of panels from Splunk searches via the REST API — no Splunk
 * Web, no iframe, no login wall on the kiosk. Each panel is a oneshot search
 * executed server-side with an auth token; the token never reaches the
 * display browser.
 *
 * Splunk-side setup (one time):
 *   1. Create a low-privilege user for signage (can run the searches it needs,
 *      nothing else).
 *   2. Settings → Tokens → New Token for that user; paste it in admin.
 *   3. SPLUNK_BASE is the management port (8089), not Splunk Web.
 *
 * Panel types:
 *   'single'  big number — first result row, field named by 'field'
 *   'list'    label + bar + count rows — fields named by 'label'/'value'
 *   'trend'   area chart from a timechart — y field named by 'value'
 * Optional per panel: 'earliest'/'latest' (Splunk time modifiers, default
 * -24h@h/now), 'unit' (suffix on singles), 'wide' => true (span 2 columns).
 *
 * Configure panels in admin.php → Splunk Panels (drag-and-drop cards).
 */

require_once __DIR__ . '/splunk_lib.php';

define('BOARD_TITLE', cfg('splunk.BOARD_TITLE', 'Splunk'));
define('BOARD_SUB', cfg('splunk.BOARD_SUB', 'Home SOC'));
define('TIMEZONE', cfg('splunk.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$configured = splunk_configured();
$panels = [];
foreach (splunk_wall_panels() as $p) {
    $diag = null;
    $rows = $configured
        ? splunk_oneshot(
            (string)$p['spl'],
            (string)($p['earliest'] ?? '-24h@h'),
            (string)($p['latest'] ?? 'now'),
            $diag
        )
        : null;
    if ($diag !== null) {
        $GLOBALS['diag'][substr(md5((string)($p['spl'] ?? '')), 0, 6)] = $diag;
    }
    $panels[] = $p + ['rows' => $rows];
}

$cacheTtl = splunk_cache_ttl();
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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:flex;
           flex-direction:column; gap:24px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .grid { flex:1; min-height:0; display:grid; grid-template-columns:repeat(3, 1fr);
          grid-auto-rows:1fr; gap:24px; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:26px 32px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel.wide { grid-column:span 2; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:12px; }
  .single { flex:1; display:flex; align-items:center; }
  .single .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:128px;
               line-height:1; color:var(--beacon); font-variant-numeric:tabular-nums; }
  .single .v small { font-size:48px; color:var(--mist); font-weight:600; }
  .lrow { display:grid; grid-template-columns:minmax(110px,auto) 1fr 90px; align-items:center;
          gap:14px; padding:8px 0; }
  .lrow .n { font-size:24px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .lrow .track { height:14px; background:var(--lake-night); border-radius:7px; overflow:hidden; }
  .lrow .fill { height:100%; background:var(--beacon); border-radius:7px; }
  .lrow .c { font-size:26px; font-weight:600; text-align:right; font-variant-numeric:tabular-nums; }
  .trend { flex:1; min-height:0; }
  .trend svg { width:100%; height:100%; }
  .nodata, .err { font-size:24px; color:var(--mist); }
  .setupmsg { font-size:28px; color:var(--mist); line-height:1.7; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?> <span>&middot; <?= h(BOARD_SUB) ?></span></h1>
    <div id="clock">--:--</div>
  </div>
  <div class="grid">
    <?php if (!$configured): ?>
      <div class="panel wide"><div class="k">Setup</div>
        <div class="setupmsg">Set <code>SPLUNK_BASE</code> (management port 8089) and
        <code>SPLUNK_TOKEN</code> in <strong>admin.php → Splunk Panels</strong>. Create the token under
        Settings &rarr; Tokens for a low-privilege signage user.</div></div>
    <?php endif; ?>
    <?php foreach ($panels as $p): ?>
      <div class="panel<?= !empty($p['wide']) ? ' wide' : '' ?>">
        <div class="k"><?= h($p['title']) ?></div>
        <?php $rows = $p['rows'];
        if ($rows === null): ?>
          <div class="err"><?= $configured ? 'no data — see diagnostics' : '—' ?></div>
        <?php elseif ($p['type'] === 'single'):
            $v = $rows[0][$p['field'] ?? 'count'] ?? null; ?>
          <div class="single"><div class="v"><?= $v !== null ? splunk_fmt_num($v) : '—'
            ?><?= !empty($p['unit']) ? '<small> ' . h($p['unit']) . '</small>' : '' ?></div></div>
        <?php elseif ($p['type'] === 'list'):
            $vals = array_map(fn($r) => (float)($r[$p['value'] ?? 'count'] ?? 0), $rows);
            $maxV = max(1, $vals ? max($vals) : 1);
            foreach ($rows as $r): ?>
          <div class="lrow">
            <span class="n"><?= h((string)($r[$p['label'] ?? 'label'] ?? '?')) ?></span>
            <div class="track"><div class="fill" style="width:<?= (int)round((float)($r[$p['value'] ?? 'count'] ?? 0) / $maxV * 100) ?>%"></div></div>
            <span class="c"><?= splunk_fmt_num($r[$p['value'] ?? 'count'] ?? 0) ?></span>
          </div>
        <?php endforeach;
              if (!$rows): ?><div class="nodata">no results</div><?php endif;
        elseif ($p['type'] === 'trend'): ?>
          <div class="trend"><?= splunk_trend_svg($rows, (string)($p['value'] ?? 'count')) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="stamp">Splunk REST &middot; refresh <?= $cacheTtl ?>s<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), <?= $cacheTtl ?> * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
