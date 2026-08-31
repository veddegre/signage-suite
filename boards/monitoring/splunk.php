<?php
/**
 * SPLUNK REST PANELS (RETIRED) — use splunkdash.php for Dashboard Studio embeds.
 * Legacy splunk.php?d= URLs are auto-skipped in rotation.
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TIMEZONE', cfg('splunkdash.TIMEZONE', 'America/Detroit'));
date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$showClock = signage_show_clock();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Splunk panels retired — Signage</title>
<?= signage_theme_fonts_head_html() ?>
<style>
  <?= signage_theme_css() ?>
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
  .msg { flex:1; display:flex; align-items:center; justify-content:center; text-align:center; padding:0 120px; }
  .msg p { font-size:34px; line-height:1.55; color:var(--mist); max-width:1400px; }
  .msg code { color:var(--snow); background:var(--code-bg); padding:4px 12px; border-radius:8px; font-size:30px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Splunk panels <span>retired</span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>
  <div class="msg">
    <p>SPL REST panel walls are no longer supported. Use <strong>Dashboards → Splunk Published</strong> in admin
    and add <code>splunkdash.php?d=KEY</code> to rotation instead. Remove any legacy <code>splunk.php</code> slots from your playlist.</p>
  </div>
  <div class="stamp">Splunk REST retired · use splunkdash.php</div>
</div>
<script>
  <?php if ($showClock): ?>
  <?= signage_clock_tick_script('clock', TIMEZONE) ?>
  <?php endif; ?>
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
