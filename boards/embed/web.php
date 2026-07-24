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

require_once dirname(__DIR__, 2) . '/lib/web_lib.php';

define('TIMEZONE', cfg('web.TIMEZONE', 'America/Detroit'));
define('SHOW_CLOCK', signage_show_clock((bool)cfg('web.SHOW_CLOCK', true)));

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              font-family:system-ui,sans-serif; <?= signage_viewport_css() ?> }
  iframe { width:1920px; height:100%; border:0; display:block; background:var(--lake-night);
            pointer-events:none; }
  #clock { position:fixed; top:36px; right:48px; z-index:9000; pointer-events:none;
           font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:600; font-size:48px;
           color:var(--snow); font-variant-numeric:tabular-nums;
           padding:6px 18px; border-radius:10px; background:rgba(12,20,34,.78);
           box-shadow:0 2px 24px rgba(0,0,0,.55); }
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
  <?php if (SHOW_CLOCK): ?><div id="clock">--:--</div><?php endif; ?>
  <iframe id="site" src="<?= h($site['url']) ?>" allow="autoplay; fullscreen"></iframe>
  <script>
    <?php if (SHOW_CLOCK): ?>
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
      const f = document.getElementById('site');
      if (!f) return;
      f.src = f.src.split('#')[0];
    }, <?= (int)$reload ?> * 1000);
    <?php endif; ?>
    setTimeout(function () { location.reload(); }, 60 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
