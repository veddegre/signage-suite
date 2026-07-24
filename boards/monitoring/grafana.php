<?php
/**
 * GRAFANA BOARD — 1920×1080 signage
 * Wraps a Grafana dashboard in kiosk mode for rotation + ticker overlay.
 *
 * Auth options (pick one on the Grafana side):
 *   1. JWT (recommended for work Grafana behind SSO) — enable JWT in admin;
 *      signage signs short-lived tokens and appends auth_token=… (Grafana auth.jwt url_login).
 *   2. Public dashboard share URL — no login.
 *   3. Anonymous Viewer (LAN-only homelab) — grafana.ini auth.anonymous.
 *
 * Grafana.ini (JWT path):
 *   [security] allow_embedding = true
 *   [auth.jwt] enabled = true, url_login = true, jwk_set_file = …, email_claim = email
 *
 * See docs/grafana.md for full self-hosted SSO setup.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/grafana_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';

define('DASHBOARDS', grafana_dashboards_for_display());
define('TIMEZONE', cfg('grafana.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = admin_resolve_display_registry_key(DASHBOARDS, (string)($_GET['d'] ?? ''));
if ($key === null || !isset(DASHBOARDS[$key])) {
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'Dashboard not found'], JSON_UNESCAPED_SLASHES);
        exit;
    }
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
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}

$dash = DASHBOARDS[$key];

if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(grafana_embed_api_payload((string)$key, $dash), JSON_UNESCAPED_SLASHES);
    exit;
}

$embed = grafana_dashboard_iframe_src((string)$key, $dash);
$useJwt = grafana_jwt_configured() && ($embed['auth'] ?? '') === 'jwt';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($dash['title'] ?? $key) ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:#0c1422; }
  iframe { width:1920px; height:100%; border:0; display:block; pointer-events:none; background:#0c1422; }
  .empty { width:1920px; height:100%; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:#8aa0c0; padding:0 80px; text-align:center; }
  .empty h2 { font-size:54px; color:#edf2fb; font-weight:700; }
  .empty p { font-size:27px; max-width:1100px; line-height:1.65; }
</style>
</head>
<body>
<?php if (empty($embed['ok'])): ?>
  <div class="empty">
    <h2>Grafana &ldquo;<?= h($dash['title'] ?? $key) ?>&rdquo; not ready</h2>
    <p><?= h((string)($embed['error'] ?? 'Configure dashboard URL and JWT settings in admin.')) ?></p>
  </div>
<?php else: ?>
  <iframe id="dash" src="<?= h((string)$embed['src']) ?>" allow="fullscreen"></iframe>
  <script>
  (function () {
    const frame = document.getElementById('dash');
    <?php if ($useJwt): ?>
    const API = 'grafana.php?api=1&d=' + encodeURIComponent(<?= json_encode((string)$key) ?>);
    let refreshTimer = null;

    function scheduleRefresh(expiresIn) {
      if (refreshTimer) clearTimeout(refreshTimer);
      const sec = Math.max(120, (expiresIn || 3600) - 300);
      refreshTimer = setTimeout(refreshSrc, sec * 1000);
    }

    async function refreshSrc() {
      try {
        const r = await fetch(API, { cache: 'no-store' });
        const data = await r.json();
        if (data.ok && data.src) {
          frame.src = data.src;
          scheduleRefresh(data.expiresIn);
        }
      } catch (e) {}
    }

    scheduleRefresh(<?= (int)($embed['expiresIn'] ?? 3600) ?>);
    <?php endif; ?>
    setTimeout(() => location.reload(), 60 * 60 * 1000);
  })();
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
