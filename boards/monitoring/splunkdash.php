<?php
/**
 * SPLUNK PUBLISHED DASHBOARD BOARD — 1920×1080 signage
 * Wraps Splunk Dashboard Studio published URLs with the same title overlay,
 * themed border, and iframe crop used for a polished kiosk wall (like Grafana).
 *
 * Splunk-side: Dashboard Studio → Actions → Publish dashboard. Set
 * x_frame_options_sameorigin = false in web.conf if the frame stays blank.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/splunkdash_lib.php';

define('DASHBOARDS', splunkdash_dashboards_for_display());
define('DEFAULT_RELOAD', cfg('splunkdash.DEFAULT_RELOAD', 300));
define('TIMEZONE', cfg('splunkdash.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = admin_resolve_display_registry_key(DASHBOARDS, (string)($_GET['d'] ?? ''));
if ($key === null || !isset(DASHBOARDS[$key])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Splunk — Not available</title>
<style>
  <?= signage_theme_css() ?>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:var(--lake-night);
              color:var(--mist); font-family:system-ui,sans-serif; cursor:none;
              display:flex; align-items:center; justify-content:center; text-align:center; }
  h1 { font-size:58px; color:var(--snow); margin-bottom:16px; }
  p { font-size:28px; max-width:900px; line-height:1.5; }
</style>
</head>
<body>
  <div>
    <h1>No dashboard to preview</h1>
    <p>Pick a dashboard from the list in admin, or add one you own under Dashboards → Splunk.</p>
  </div>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}

$dash = DASHBOARDS[$key];
$reload = max(0, (int)($dash['reload'] ?? DEFAULT_RELOAD));
$configured = !str_contains((string)($dash['url'] ?? ''), 'REPLACE');
$embedUrl = splunkdash_embed_url((string)($dash['url'] ?? ''), $dash);
$cropTop = splunkdash_crop_top_px($dash);
$hideScrollbars = splunkdash_hide_scrollbars($dash);
$boardTitle = trim((string)($dash['title'] ?? $key));
$boardSub = trim((string)($dash['sub'] ?? ''));
$showClock = signage_show_clock();
$embedH = max(720, signage_frame_height() - 16);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($boardTitle) ?></title>
<?= signage_theme_fonts_head_html() ?>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_theme_css() ?>
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:100%; <?= signage_viewport_css() ?> overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; }
  .wall { width:1920px; max-width:100%; height:100%; margin:0 auto; position:relative;
          padding:0 16px 16px; box-sizing:border-box; }
  .head { position:absolute; top:0; left:16px; right:16px; z-index:10; display:flex; align-items:baseline;
          justify-content:space-between; padding:14px 32px 18px; pointer-events:none;
          background:linear-gradient(180deg, rgba(12,20,34,.98) 0%, rgba(12,20,34,.94) 65%, rgba(12,20,34,0) 100%); }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:48px; line-height:1.05;
             text-shadow:0 2px 18px rgba(0,0,0,.65); }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:44px; color:var(--mist);
           font-variant-numeric:tabular-nums; text-shadow:0 2px 18px rgba(0,0,0,.65); }
  <?= signage_embed_frame_css() ?>
  .signage-embed-frame .dash-wrap { width:100%; height:100%; }
  <?= signage_iframe_crop_css($embedH, $cropTop, 'dash-wrap', $hideScrollbars, true) ?>
  .empty { width:1920px; max-width:100%; height:100%; margin:0 auto; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); padding:0 80px; text-align:center; }
  .empty h2 { font-size:54px; color:var(--snow); font-weight:700; }
  .empty p { font-size:27px; max-width:1100px; line-height:1.65; }
  .empty code { color:var(--beacon); background:var(--harbor); padding:2px 12px; border-radius:6px; }
</style>
</head>
<body>
<?php if (!$configured): ?>
  <div class="empty">
    <h2>No published dashboard configured for &ldquo;<?= h($key) ?>&rdquo;</h2>
    <p>In Splunk: open the Dashboard Studio dashboard → <code>Actions</code> →
       <code>Publish dashboard</code>, copy the published URL into admin under
       <strong>Dashboards → Splunk</strong>. If the frame stays blank, set
       <code>x_frame_options_sameorigin&nbsp;=&nbsp;false</code> in <code>web.conf</code>
       and restart Splunk.</p>
  </div>
<?php else: ?>
  <div class="wall">
    <div class="head">
      <h1><?= h($boardTitle) ?><?php if ($boardSub !== ''): ?> <span>&middot; <?= h($boardSub) ?></span><?php endif; ?></h1>
      <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
    </div>
    <div class="signage-embed-frame">
      <div class="dash-wrap">
        <iframe id="dash" src="<?= h($embedUrl) ?>" allow="fullscreen" scrolling="no"></iframe>
      </div>
    </div>
  </div>
  <script>
  (function () {
    <?php if ($showClock): ?>
    (function () {
      const tz = <?= json_encode(TIMEZONE) ?>;
      function tick() {
        const el = document.getElementById('clock');
        if (!el) return;
        el.textContent = new Date().toLocaleTimeString('en-US', {
          hour: 'numeric', minute: '2-digit', hour12: true, timeZone: tz
        });
      }
      tick();
      setInterval(tick, 1000);
    })();
    <?php endif; ?>
    <?php if ($reload > 0): ?>
    setInterval(function () {
      const f = document.getElementById('dash');
      if (!f) return;
      f.src = f.src.split('#')[0];
    }, <?= $reload ?> * 1000);
    <?php endif; ?>
    setTimeout(function () { location.reload(); }, 60 * 60 * 1000);
  })();
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
