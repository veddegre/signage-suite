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
 *      instance. If you'd rather not change it, put the published URL
 *      directly into Anthias as the web asset — you just lose the ticker.)
 *
 * Notes:
 *   - Published dashboards refresh on their *scheduled search* cadence;
 *     searches don't run on demand. Set the publish refresh schedule to match
 *     how live you want the wall to be.
 *   - Anthias usage: each asset is  splunkdash.php?d=<key>  (no ?d= = first).
 *   - 'reload' (seconds, optional) hard-reloads the iframe as a backstop in
 *     case the published page's own auto-refresh ever stalls in a long
 *     kiosk session. Default 300, 0 disables.
 */

require_once __DIR__ . '/config.php';

define('DASHBOARDS', cfg('splunkdash.DASHBOARDS', [
    'soc' => [
        'title'  => 'SOC Overview',
        'url'    => 'https://splunk.example.com:8000/published/REPLACE-WITH-PUBLISHED-URL',

    ],
    'network' => [
        'title'  => 'Network Activity',
        'url'    => 'https://splunk.example.com:8000/published/REPLACE-ME-TOO',
    ],
]));

define('DEFAULT_RELOAD', cfg('splunkdash.DEFAULT_RELOAD', 300));
define('TIMEZONE', cfg('splunkdash.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['d'] ?? ''));
if ($key === '' || !isset(DASHBOARDS[$key])) $key = array_key_first(DASHBOARDS);
$dash       = DASHBOARDS[$key];
$reload     = max(0, (int)($dash['reload'] ?? DEFAULT_RELOAD));
$configured = !str_contains($dash['url'], 'REPLACE');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($dash['title']) ?></title>
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:var(--lake-night); cursor:none;
              font-family:system-ui,sans-serif; }
  iframe { width:1920px; height:100%; border:0; display:block; background:var(--lake-night); }
  .empty { width:1920px; height:100%; display:flex; flex-direction:column; gap:18px;
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
  <iframe id="dash" src="<?= h($dash['url']) ?>" allow="fullscreen"></iframe>
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
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
