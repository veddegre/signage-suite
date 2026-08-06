<?php
/**
 * GRAFANA BOARD — 1920×1080 signage
 * Wraps a Grafana dashboard in kiosk mode for rotation + ticker overlay.
 *
 * Auth options:
 *   1. JWT — HS256 (self-hosted) or RS256 + grafana-jwks.php (Cloud) — docs/grafana.md / grafana-cloud.md
 *   2. Public dashboard URL — no JWT; data is public
 *   3. Anonymous Viewer (LAN homelab) — grafana.ini auth.anonymous
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/grafana_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
require_once dirname(__DIR__, 2) . '/lib/rotation_lib.php';

define('TIMEZONE', cfg('grafana.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$dashResolved = grafana_resolve_dashboard((string)($_GET['d'] ?? ''));
if ($dashResolved === null) {
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'Dashboard not found'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $previewHint = admin_preview_session_ready()
        ? 'Save the page in admin first, then preview again — or check Access sharing.'
        : 'Pick a dashboard from the list in admin, or add one you own.';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Grafana — Not available</title>
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
    <p><?= h($previewHint) ?></p>
  </div>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}

$key = (string)$dashResolved['key'];
$dash = $dashResolved;
unset($dash['key']);

if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(grafana_embed_api_payload((string)$key, $dash), JSON_UNESCAPED_SLASHES);
    exit;
}

$embed = grafana_dashboard_iframe_src((string)$key, $dash);
$useJwt = ($embed['auth'] ?? '') === 'jwt';
$boardTitle = trim((string)($dash['title'] ?? $key));
$boardSub = trim((string)($dash['sub'] ?? ''));
$showClock = signage_show_clock();
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
  .empty { width:1920px; max-width:100%; height:100%; margin:0 auto; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); padding:0 80px; text-align:center; }
  .empty h2 { font-size:54px; color:var(--snow); font-weight:700; }
  .empty p { font-size:27px; max-width:1100px; line-height:1.65; }
</style>
</head>
<body>
<?php if (empty($embed['ok'])): ?>
  <div class="empty">
    <h2>Grafana &ldquo;<?= h($boardTitle) ?>&rdquo; not ready</h2>
    <p><?= h((string)($embed['error'] ?? 'Configure dashboard URL and JWT settings in admin.')) ?></p>
  </div>
<?php else: ?>
  <div class="wall">
    <div class="head">
      <h1><?= h($boardTitle) ?><?php if ($boardSub !== ''): ?> <span>&middot; <?= h($boardSub) ?></span><?php endif; ?></h1>
      <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
    </div>
    <div class="signage-embed-frame">
      <iframe id="dash" src="<?= h((string)$embed['src']) ?>" allow="fullscreen" scrolling="no"
              credentialless referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
  </div>
  <script>
  (function () {
    const frame = document.getElementById('dash');
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
    <?php if ($useJwt): ?>
    const API = 'grafana.php?api=1&d=' + encodeURIComponent(<?= json_encode((string)$key) ?>)
      + <?= json_encode(isset($_GET['screen']) && (string)$_GET['screen'] !== '' ? '&screen=' . rawurlencode(rotation_normalize_screen_key((string)$_GET['screen'])) : '') ?>;
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
