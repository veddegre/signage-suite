<?php
/**
 * POWER BI BOARD — 1920×1080 signage
 * Wraps Power BI reports in the rotation with the weather-alert ticker overlay.
 *
 * Two embed paths:
 *   1. Publish to web (app.powerbi.com/view?r=…) — public iframe, no auth.
 *   2. Private reports — Azure AD service principal + embed tokens (like Yodeck):
 *      configure tenant/client/secret in admin, then workspace + report IDs (or paste
 *      the Share → Embed reportEmbed URL). Tokens refresh automatically on the player.
 *
 * Azure setup (one time):
 *   - Entra app registration + client secret
 *   - API permissions (application): Power BI Service — Report.Read.All, Dataset.Read.All,
 *     Dashboard.Read.All, Workspace.Read.All; grant admin consent
 *   - Power BI admin portal → Tenant settings → Developer settings →
 *     "Allow service principals to use Power BI APIs" (+ embed if separate toggle)
 *   - Add the service principal to the workspace as Member or Admin
 *
 * Playlist: powerbi.php?d=<key> (no ?d= = first entry).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/powerbi_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';

define('DASHBOARDS', powerbi_dashboards_for_display());
define('DEFAULT_RELOAD', cfg('powerbi.DEFAULT_RELOAD', 300));
define('TIMEZONE', cfg('powerbi.TIMEZONE', 'America/Detroit'));

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
<title>Power BI — Not available</title>
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
    echo json_encode(powerbi_embed_payload((string)$key, $dash), JSON_UNESCAPED_SLASHES);
    exit;
}

$reload = max(0, (int)($dash['reload'] ?? DEFAULT_RELOAD));
$embed = powerbi_embed_payload((string)$key, $dash);
$mode = (string)($embed['mode'] ?? 'unknown');
$boardH = signage_frame_height();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($dash['title'] ?? $key) ?></title>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:var(--lake-night);
              font-family:system-ui,sans-serif; }
  iframe, #embed-container { width:1920px; height:<?= (int)$boardH ?>px; border:0; display:block;
                              background:var(--lake-night); pointer-events:none; }
  .empty { width:1920px; height:<?= (int)$boardH ?>px; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); padding:0 80px; }
  .empty h2 { font-size:54px; color:var(--snow); font-weight:700; text-align:center; }
  .empty p { font-size:27px; max-width:1150px; text-align:center; line-height:1.65; }
  .empty code { color:var(--beacon); background:var(--harbor); padding:2px 12px; border-radius:6px; }
</style>
<?php if ($mode === 'token' && !empty($embed['ok'])): ?>
<script src="https://cdn.jsdelivr.net/npm/powerbi-client@2.23.1/dist/powerbi.min.js"></script>
<?php endif; ?>
</head>
<body>
<?php if (empty($embed['ok'])): ?>
  <div class="empty">
    <h2>Power BI &ldquo;<?= h($dash['title'] ?? $key) ?>&rdquo; not ready</h2>
    <p><?= h((string)($embed['error'] ?? 'Configure this dashboard in admin.')) ?></p>
  </div>
<?php elseif ($mode === 'publish'): ?>
  <iframe id="dash" src="<?= h((string)$embed['embedUrl']) ?>" allow="fullscreen"></iframe>
  <script>
    <?php if ($reload > 0): ?>
    setInterval(() => {
      const f = document.getElementById('dash');
      f.src = f.src.split('#')[0];
    }, <?= $reload ?> * 1000);
    <?php endif; ?>
    setTimeout(() => location.reload(), 60 * 60 * 1000);
  </script>
<?php else: ?>
  <div id="embed-container"></div>
  <script>
  (function () {
    const API = 'powerbi.php?api=1&d=' + encodeURIComponent(<?= json_encode((string)$key) ?>);
    const container = document.getElementById('embed-container');
    const models = window['powerbi-client'].models;
    let embedded = null;
    let refreshTimer = null;

    function scheduleRefresh(expiresIn) {
      if (refreshTimer) clearTimeout(refreshTimer);
      const sec = Math.max(120, (expiresIn || 3600) - 300);
      refreshTimer = setTimeout(refreshEmbed, sec * 1000);
    }

    function embedConfig(data) {
      const type = data.type === 'dashboard' ? 'dashboard' : 'report';
      return {
        type,
        tokenType: models.TokenType.Embed,
        accessToken: data.accessToken,
        embedUrl: data.embedUrl,
        id: data.id,
        settings: {
          panes: {
            filters: { visible: false, expanded: false },
            pageNavigation: { visible: false },
          },
          background: models.BackgroundType.Transparent,
        },
      };
    }

    async function refreshEmbed() {
      try {
        const r = await fetch(API, { cache: 'no-store' });
        const data = await r.json();
        if (!data.ok) return;
        if (embedded && typeof embedded.setAccessToken === 'function') {
          await embedded.setAccessToken(data.accessToken);
        } else {
          embedded = powerbi.embed(container, embedConfig(data));
        }
        scheduleRefresh(data.expiresIn);
      } catch (e) {}
    }

    const initial = <?= json_encode([
        'ok' => true,
        'type' => $embed['type'] ?? 'report',
        'id' => $embed['id'] ?? '',
        'embedUrl' => $embed['embedUrl'] ?? '',
        'accessToken' => $embed['accessToken'] ?? '',
        'expiresIn' => (int)($embed['expiresIn'] ?? 3300),
    ], JSON_UNESCAPED_SLASHES) ?>;
    embedded = powerbi.embed(container, embedConfig(initial));
    scheduleRefresh(initial.expiresIn);

    setTimeout(() => location.reload(), 60 * 60 * 1000);
  })();
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
