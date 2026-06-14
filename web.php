<?php
/**
 * WEBSITE BOARD — 1920×1080 signage
 * Wraps external https pages in a full-screen iframe for rotation. Configure
 * sites in admin.php → Websites; each rotation entry is web.php?d=<key>.
 *
 * Notes:
 *   - The remote site must allow embedding (no X-Frame-Options / CSP block).
 *   - board.php loads this wrapper in an iframe — the inner frame is your URL
 *     as configured, with no extra query params appended to it.
 *   - Optional per-site reload (seconds) hard-refreshes the inner iframe as a
 *     backstop during long kiosk sessions. Default reload is 0 (disabled).
 */

require_once __DIR__ . '/web_lib.php';

define('TIMEZONE', cfg('web.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$site = web_resolve_site((string)($_GET['d'] ?? ''));
$configured = $site['url'] !== '' && web_allowed_url($site['url']);
$reload = $site['reload'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($site['title']) ?> — Signage</title>
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night); cursor:none;
              font-family:system-ui,sans-serif; <?= signage_viewport_css() ?> }
  iframe { width:1920px; height:100%; border:0; display:block; background:var(--lake-night); }
  .empty { width:1920px; height:100%; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); padding:40px; }
  .empty h2 { font-size:54px; color:var(--snow); font-weight:700; text-align:center; }
  .empty p { font-size:27px; max-width:1150px; text-align:center; line-height:1.65; }
  .empty code { color:var(--beacon); background:var(--harbor); padding:2px 12px; border-radius:6px; }
</style>
</head>
<body>
<?php if (!$configured): ?>
  <div class="empty">
    <h2>No website configured for &ldquo;<?= h($site['key']) ?>&rdquo;</h2>
    <p>Add sites in <strong>admin.php → Websites</strong> with a valid
       <code>https://</code> URL. Use <code>web.php?d=<?= h($site['key']) ?></code> in rotation.
       If the frame stays blank after that, the remote site may block iframe embedding.</p>
  </div>
<?php else: ?>
  <iframe id="site" src="<?= h($site['url']) ?>" allow="autoplay; fullscreen"></iframe>
  <script>
    <?php if ($reload > 0): ?>
    setInterval(function () {
      const f = document.getElementById('site');
      if (!f) return;
      f.src = f.src.split('#')[0];
    }, <?= (int)$reload ?> * 1000);
    <?php endif; ?>
    setTimeout(function () { location.reload(); }, 60 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
