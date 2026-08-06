<?php
/**
 * SPLUNK PUBLISHED DASHBOARD BOARD — 1920×1080 signage
 * Wraps Splunk's published Dashboard Studio dashboards (Splunk 10.x /
 * Cloud 9.3.2411+) so they join the rotation and get the alert ticker.
 * Published dashboards need no login, which makes them perfect kiosk material —
 * this is the "whole dashboard, pixel for pixel" companion to splunk.php's
 * REST-API panel grid.
 *
 * Splunk-side setup:
 *   1. Open the Dashboard Studio dashboard → Actions → Publish dashboard.
 *      Pick a data refresh schedule and (optionally) a link expiration, then
 *      copy the published URL into DASHBOARDS below.
 *   2. Published pages are served by Splunk Web, which by default refuses to
 *      be iframed from another origin. On the Splunk server set, in
 *      $SPLUNK_HOME/etc/system/local/web.conf:
 *
 *          [settings]
 *          x_frame_options_sameorigin = false
 *
 *      and restart Splunk. (LAN-appropriate; it removes clickjacking
 *      protection for Splunk Web, so don't do this on an internet-exposed
 *      instance. If you'd rather not change it, load the published URL
 *      directly in your rotator — you just lose the ticker overlay.)
 *
 * Notes:
 *   - Published dashboards refresh on their *scheduled search* cadence;
 *     searches don't run on demand. Set the publish refresh schedule to match
 *     how live you want the wall to be.
 *   - Playlist usage: each entry is splunkdash.php?d=<key> (no ?d= = first).
 *   - 'reload' (seconds, optional) hard-reloads the iframe as a backstop in
 *     case the published page's own auto-refresh ever stalls in a long
 *     kiosk session. Default 300, 0 disables.
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
    <p>Pick a dashboard from the list in admin, or add one you own.</p>
  </div>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}
$dash       = DASHBOARDS[$key];
$reload     = max(0, (int)($dash['reload'] ?? DEFAULT_RELOAD));
$configured = !str_contains($dash['url'], 'REPLACE');
$embedUrl   = splunkdash_embed_url((string)$dash['url'], $dash);
$cropTop    = splunkdash_crop_top_px($dash);
$boardH     = signage_frame_height();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($dash['title']) ?></title>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:var(--lake-night);
              font-family:system-ui,sans-serif; }
  <?= signage_iframe_crop_css($boardH, $cropTop) ?>
  .empty { width:1920px; height:<?= (int)$boardH ?>px; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); }
  .empty h2 { font-size:54px; color:var(--snow); font-weight:700; }
  .empty p { font-size:27px; max-width:1150px; text-align:center; line-height:1.65; }
  .empty code { color:var(--beacon); background:var(--harbor); padding:2px 12px; border-radius:6px; }
</style>
</head>
<body>
<?php if (!$configured): ?>
  <div class="empty">
    <h2>No published dashboard configured for &ldquo;<?= h($key) ?>&rdquo;</h2>
    <p>In Splunk: open the Dashboard Studio dashboard &rarr; <code>Actions</code> &rarr;
       <code>Publish dashboard</code>, copy the published URL into
       <code>DASHBOARDS</code> in this file. If the frame stays blank after that,
       set <code>x_frame_options_sameorigin&nbsp;=&nbsp;false</code> in web.conf
       and restart Splunk — see the comments at the top of this file.</p>
  </div>
<?php else: ?>
  <div class="dash-wrap">
    <iframe id="dash" src="<?= h($embedUrl) ?>" allow="fullscreen"></iframe>
  </div>
  <script>
    <?php if ($reload > 0): ?>
    // Backstop: re-pull the published page in case its own auto-refresh
    // stalls during a long kiosk session.
    setInterval(() => {
      const f = document.getElementById('dash');
      f.src = f.src.split('#')[0];
    }, <?= $reload ?> * 1000);
    <?php endif; ?>
    setTimeout(() => location.reload(), 60 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
