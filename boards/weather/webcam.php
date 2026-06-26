<?php
/**
 * WEBCAM BOARD — 1920×1080 signage
 * Full-screen embed of a public share URL (EarthCam, YouTube live, etc.).
 *
 * Default: Grand Haven beach cam from Surf Grand Haven / MACkite via EarthCam:
 *   https://surfgrandhaven.com
 *
 * Paste the iframe src URL in admin — not the surrounding page. If the stream
 * stops loading, check the source site for an updated embed link.
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('webcam.TITLE', 'Grand Haven Beach'));
define('EMBED_URL', cfg('webcam.EMBED_URL', 'https://share.earthcam.net/tJ90CoLmq7TzrY396Yd88KTssi7iV3ZNicDEymFXa2k!'));
define('ATTRIBUTION', cfg('webcam.ATTRIBUTION', 'EarthCam · MACkite · Surf Grand Haven'));
define('SHOW_OVERLAY', cfg('webcam.SHOW_OVERLAY', true));
define('RELOAD_SEC', cfg('webcam.RELOAD_SEC', 3600));
define('TIMEZONE', cfg('webcam.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function webcam_embed_url(): ?string
{
    $url = trim((string)EMBED_URL);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    if (trim((string)($parts['host'] ?? '')) === '') {
        return null;
    }
    return $url;
}

$embed = webcam_embed_url();
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$reloadSec = max(0, (int)RELOAD_SEC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; cursor:none; }
  .board { position:relative; width:1920px; height:<?= $heightCss ?>; }
  iframe { position:absolute; inset:0; width:100%; height:100%; border:0; display:block;
            background:var(--lake-night); }
  .overlay { position:absolute; top:<?= $boardH < 1080 ? 18 : 24 ?>px; left:<?= $boardH < 1080 ? 24 : 32 ?>px;
             z-index:2; pointer-events:none;
             padding:12px 18px; border-radius:12px; background:rgba(12,20,34,.72);
             border:1px solid var(--hairline); backdrop-filter:blur(6px); }
  .overlay h1 { font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:700;
                font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; letter-spacing:.5px; }
  #clock { position:fixed; top:36px; right:48px; z-index:9000; pointer-events:none;
           font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:600; font-size:48px;
           color:var(--snow); font-variant-numeric:tabular-nums;
           padding:6px 18px; border-radius:10px; background:rgba(12,20,34,.78);
           box-shadow:0 2px 24px rgba(0,0,0,.55); }
  .stamp { position:absolute; right:<?= $boardH < 1080 ? 20 : 28 ?>px; bottom:<?= $boardH < 1080 ? 10 : 14 ?>px;
           z-index:2; text-align:right; font-size:15px; color:var(--mist); opacity:.85;
           pointer-events:none; max-width:70%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .empty { width:100%; height:100%; display:flex; flex-direction:column; gap:16px; align-items:center;
           justify-content:center; color:var(--mist); padding:40px; text-align:center; }
  .empty h2 { font-family:'Big Shoulders Display',system-ui,sans-serif; font-size:48px; color:var(--snow); }
  .empty p { font-size:22px; line-height:1.55; max-width:980px; }
  .empty code { color:var(--beacon); background:var(--harbor); padding:2px 8px; border-radius:6px; }
</style>
</head>
<body>
<div class="board">
  <?php if ($embed): ?>
  <iframe id="cam" src="<?= h($embed) ?>" allow="autoplay; fullscreen" loading="eager"></iframe>
  <?php if (SHOW_OVERLAY): ?>
  <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  <div class="overlay">
    <h1><?= h(TITLE) ?></h1>
  </div>
  <?php endif; ?>
  <?php if (ATTRIBUTION !== ''): ?>
  <div class="stamp"><?= h(ATTRIBUTION) ?></div>
  <?php endif; ?>
  <?php else: ?>
  <div class="empty">
    <h2>Webcam not configured</h2>
    <p>Set a valid <code>https://</code> embed URL in admin → <strong>Webcam</strong>.
       For Surf Grand Haven, use the EarthCam iframe <code>src</code> from
       <a href="https://surfgrandhaven.com" style="color:var(--beacon)">surfgrandhaven.com</a> — not the page URL itself.</p>
  </div>
  <?php endif; ?>
</div>
<?php if ($embed && SHOW_OVERLAY): ?>
<script>
<?php if ($showClock): ?>
(function(){
  const tz = <?= json_encode(TIMEZONE) ?>;
  function tick(){
    const el = document.getElementById('clock');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('en-US', {
      hour: 'numeric', minute: '2-digit', hour12: true, timeZone: tz
    });
  }
  tick(); setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
<?php endif; ?>
<?php if ($embed && $reloadSec > 0): ?>
<script>
(function(){
  const ms = <?= (int)$reloadSec ?> * 1000;
  setInterval(function(){
    const f = document.getElementById('cam');
    if (!f) return;
    f.src = f.src.split('#')[0];
  }, ms);
})();
</script>
<?php endif; ?>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
