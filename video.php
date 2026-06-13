<?php
/**
 * VIDEO BOARD — 1920×1080 signage
 * Plays locally stored videos fullscreen — the same approach Anthias uses for
 * its native video assets, done by hand so it works as a web asset alongside
 * the other boards (and gets the alert ticker).
 *
 * One file is both the player and the downloader:
 *
 *   PLAYER   video.php?v=<key>      → plays that video (no ?v= = first entry)
 *   FETCHER  php video.php fetch    → run from the CLI; downloads/updates every
 *                                     YouTube entry in VIDEOS via yt-dlp and
 *                                     prints each video's duration so you can
 *                                     set the matching Anthias asset length
 *
 * Admin can also download/update from Video Board in admin.php.
 *
 * Requirements on the server: yt-dlp in PATH or bin/yt-dlp for fetching,
 * and optionally ffprobe (apt install ffmpeg) for duration readout.
 * Videos land in ./videos/ next to this file so the web server itself serves
 * the media with proper range support — easy on a Pi's CPU.
 *
 * Anthias setup: add  video.php?v=drone  as a web asset and set its duration
 * to the length printed by the fetcher. The video loops as a safety net, so a
 * slightly long asset duration just wraps to the start rather than going black.
 *
 * Keep videos muted for signage (MUTED=true). Chromium's autoplay policy
 * blocks un-muted autoplay unless the kiosk is launched with
 * --autoplay-policy=no-user-gesture-required.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/video_lib.php';

define('VIDEOS', video_registry());
define('VIDEO_DIR', video_dir());
define('MUTED', cfg('video.MUTED', true));
define('FIT', cfg('video.FIT', 'cover'));
define('SHOW_CLOCK', cfg('video.SHOW_CLOCK', true));
define('MAX_HEIGHT', video_max_height());
define('TIMEZONE', cfg('video.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

// ── CLI fetcher: php video.php fetch ─────────────────────────────────────────
// (bare `php video.php` with no argument falls through and renders the player)
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    if ($argv[1] !== 'fetch') {
        fwrite(STDERR, "Usage: php video.php fetch [key]\n");
        exit(1);
    }
    $only = isset($argv[2]) ? preg_replace('/[^a-z0-9_\-]/i', '', (string)$argv[2]) : '';
    $result = $only !== ''
        ? video_fetch_one($only, fn($line) => print($line . "\n"))
        : video_fetch_all(fn($line) => print($line . "\n"));
    exit($result['ok'] ? 0 : 1);
}

// ── Player ───────────────────────────────────────────────────────────────────
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['v'] ?? ''));
if ($key === '' || !isset(VIDEOS[$key])) $key = array_key_first(VIDEOS);
$video = VIDEOS[$key];
$path  = video_path($key, $video);
$src   = $path ? 'videos/' . rawurlencode(basename($path)) : null;
$title = $video['title'] ?? '';
$embedded = isset($_GET['noticker']);
$settleMs = max(0, (int)($_GET['settle'] ?? 0));
$autoplayMuted = $embedded || MUTED;
$autoplayAttr = ($settleMs <= 0 && !$embedded) ? 'autoplay' : '';
$loopAttr = $embedded ? '' : 'loop';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($title !== '' ? $title : 'Video') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --mist:#8aa0c0; --beacon:#ffb347; --snow:#edf2fb; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:#000;
              font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  video { width:1920px; height:1080px; object-fit:<?= FIT ?>; background:#000; }
  .chrome { position:absolute; top:36px; left:48px; right:48px; z-index:5;
            display:flex; justify-content:space-between; align-items:baseline;
            text-shadow:0 1px 14px rgba(0,0,0,.8); }
  .title { font-family:'Big Shoulders Display'; font-weight:700; font-size:48px;
           color:var(--snow); letter-spacing:1px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:44px;
           color:var(--mist); font-variant-numeric:tabular-nums; }
  .empty { position:absolute; inset:0; display:flex; flex-direction:column; gap:16px;
           align-items:center; justify-content:center; background:var(--lake-night);
           color:var(--mist); }
  .empty h2 { font-family:'Big Shoulders Display'; font-size:58px; color:var(--snow); }
  .empty p { font-size:27px; max-width:1100px; text-align:center; line-height:1.6; }
  .empty code { color:var(--beacon); background:#141f33; padding:2px 12px; border-radius:6px; }
</style>
</head>
<body>
<?php if ($src === null): ?>
  <div class="empty">
    <h2>No video downloaded for &ldquo;<?= h($key) ?>&rdquo;</h2>
    <p>Use <strong>Download YouTube videos</strong> in admin, run
       <code>php video.php fetch</code> on the server, or drop a file at
       <code>videos/<?= h($key) ?>.mp4</code>.</p>
  </div>
<?php else: ?>
  <video id="player" src="<?= h($src) ?>" <?= $autoplayAttr ?> <?= $autoplayMuted ? 'muted' : '' ?> <?= $loopAttr ?> playsinline></video>
  <div class="chrome">
    <div class="title"><?= h($title) ?></div>
    <?php if (SHOW_CLOCK): ?><div id="clock">--:--</div><?php endif; ?>
  </div>
  <script>
    const EMBEDDED = <?= json_encode($embedded) ?>;
    const SETTLE = <?= (int)$settleMs ?>;
    const WANT_SOUND = <?= json_encode(!MUTED) ?>;
    <?php if (SHOW_CLOCK): ?>
    function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
      document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
    tick(); setInterval(tick, 1000);
    <?php endif; ?>
    const v = document.getElementById('player');
    let armed = false;

    function notifyReady() {
      if (!EMBEDDED || window.parent === window) return;
      try { window.parent.postMessage({ type: 'signage-ready' }, '*'); } catch (e) {}
    }

    function startVideo() {
      if (!armed) return;
      v.muted = !(WANT_SOUND && gestureSeen);
      if (v.muted) v.setAttribute('muted', '');
      else v.removeAttribute('muted');
      if (v.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
        v.addEventListener('canplay', startVideo, { once: true });
        return;
      }
      const p = v.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () {
          if (!v.muted) {
            v.muted = true;
            v.setAttribute('muted', '');
            v.play().catch(function () {});
          }
        });
      }
    }

    let gestureSeen = false;

    if (EMBEDDED) {
      window.addEventListener('message', function (ev) {
        if (!ev.data) return;
        if (ev.data.type === 'signage-stop') {
          armed = false;
          v.pause();
          return;
        }
        if (ev.data.type === 'signage-gesture') {
          gestureSeen = true;
          armed = true;
          startVideo();
          return;
        }
        if (ev.data.type === 'signage-show') {
          armed = true;
          v.muted = true;
          v.setAttribute('muted', '');
          startVideo();
        }
      });
      notifyReady();
      if (document.readyState === 'complete') notifyReady();
      else window.addEventListener('load', notifyReady, { once: true });
      // noticker=1 outside the rotation shell (direct preview).
      if (window.parent === window) {
        armed = true;
        if (SETTLE > 0) setTimeout(startVideo, SETTLE);
        else startVideo();
      }
      v.addEventListener('ended', function () { v.pause(); });
      setInterval(function () {
        if (armed && !v.ended && v.paused) startVideo();
      }, 2000);
    } else {
      armed = true;
      if (SETTLE > 0) setTimeout(startVideo, SETTLE);
      else startVideo();
      // Belt and suspenders: some Chromium builds pause looped video on decode
      // hiccups; nudge it back.
      setInterval(function () { if (v.paused) v.play().catch(function () {}); }, 5000);
    }
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
