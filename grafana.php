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

define('DASHBOARDS', cfg('grafana.DASHBOARDS', [
    'signaltrace' => [
        'title'   => 'SignalTrace Overview',
        'url'     => 'http://192.168.86.20:3000/d/signaltrace/signaltrace-overview',
        'refresh' => '1m',
    ],
    'homelab' => [
        'title'   => 'Homelab Metrics',
        'url'     => 'http://192.168.86.20:3000/d/node/node-exporter',
        'refresh' => '30s',

    ],
]));

define('GRAFANA_THEME', cfg('grafana.GRAFANA_THEME', 'dark'));
define('TIMEZONE', cfg('grafana.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['d'] ?? ''));
if ($key === '' || !isset(DASHBOARDS[$key])) $key = array_key_first(DASHBOARDS);
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
