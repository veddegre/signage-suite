<?php
/**
 * GRAFANA BOARD — 1920×1080 signage
 * Wraps a Grafana dashboard in kiosk mode so it slots into the rotation and
 * gets the weather-alert ticker overlay like every other board.
 *
 * One file, many dashboards: define them in DASHBOARDS, then each Anthias
 * web asset is  grafana.php?d=<key>  (no ?d= = first entry).
 *
 * Grafana-side setup (one time, grafana.ini):
 *   [security]        allow_embedding = true
 *   [auth.anonymous]  enabled = true   org_role = Viewer     ← LAN-only advice
 * …or use Grafana's "Public dashboards" share link as the URL instead, which
 * needs neither setting. Restart Grafana after editing the config.
 *
 * 'url' is the normal dashboard URL from your browser. Kiosk mode, theme, and
 * auto-refresh params are appended automatically; override per dashboard with
 * 'params' (e.g. 'params' => 'var-host=pve1&from=now-6h&to=now').
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/users_lib.php';

define('DASHBOARDS', grafana_dashboards_for_display());

define('GRAFANA_THEME', cfg('grafana.GRAFANA_THEME', 'dark'));
define('TIMEZONE', cfg('grafana.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = admin_resolve_display_registry_key(DASHBOARDS, (string)($_GET['d'] ?? ''));
if ($key === null || !isset(DASHBOARDS[$key])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Grafana — Not available</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:#0c1422;
              color:#8aa0c0; font-family:system-ui,sans-serif; cursor:none;
              display:flex; align-items:center; justify-content:center; text-align:center; }
  h1 { font-size:58px; color:#edf2fb; margin-bottom:16px; }
  p { font-size:28px; max-width:900px; line-height:1.5; }
</style>
</head>
<body>
  <div>
    <h1>No dashboard to preview</h1>
    <p>Pick a dashboard from the list in admin, or add one you own.</p>
  </div>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}
$dash = DASHBOARDS[$key];

$qs = 'kiosk&theme=' . GRAFANA_THEME;
if (!empty($dash['refresh'])) $qs .= '&refresh=' . rawurlencode($dash['refresh']);
if (!empty($dash['params']))  $qs .= '&' . $dash['params'];
$src = $dash['url'] . (str_contains($dash['url'], '?') ? '&' : '?') . $qs;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($dash['title']) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:#0c1422; cursor:none; }
  iframe { width:1920px; height:100%; border:0; display:block; }
</style>
</head>
<body>
<iframe src="<?= h($src) ?>" allow="fullscreen"></iframe>
<script>
  // Grafana's own refresh param keeps panels live; reload the wrapper hourly
  // as a backstop against memory creep in long-running kiosk sessions.
  setTimeout(() => location.reload(), 60 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
