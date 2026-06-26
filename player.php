<?php
/**
 * SIGNAGE PLAYER (PWA) — board.php for everything that isn't a 1080p kiosk.
 * Wraps a screen's rotation in a letterboxed stage that scales to fit ANY
 * viewport — phone, tablet, TV browser, or a resized desktop window — while
 * the boards keep rendering at their designed 1920×1080.
 *
 * Usage:  player.php                → main screen
 *         player.php?screen=garage  → that screen's rotation
 *
 * As a PWA: open it in a mobile browser and "Add to Home Screen" / install.
 * It launches fullscreen in landscape, requests a wake lock so the display
 * stays on, and a tap toggles fullscreen in a normal browser tab.
 *
 * For Channels DVR capture tools (e.g. chrome-capture-for-channels) you can
 * point the capture at board.php directly (it is exactly 1920×1080), or at
 * this player if your capture resolution differs — see the README.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/rotation_lib.php';

$SCREEN = rotation_normalize_screen_key((string)($_GET['screen'] ?? 'main'));
$blankActive = rotation_screen_blank_active($SCREEN);
$showTicker = rotation_screen_ticker_enabled($SCREEN);
// noticker=1 suppresses the ticker inside the iframe; player.php renders it here
// at the viewport bottom so polling works in the top-level PWA document.
$src = $SCREEN !== 'main'
    ? 'board.php?screen=' . rawurlencode($SCREEN) . '&noticker=1'
    : 'board.php?noticker=1';
if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') {
    $src .= '&debug=1';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c1422">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon-192.png">
<link rel="apple-touch-icon" href="icon-192.png">
<title>Signage Player<?= $SCREEN !== 'main' ? ' — ' . htmlspecialchars($SCREEN) : '' ?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:100%; height:100%; background:#000; overflow:hidden; }
  #stage { position:absolute; top:50%; left:50%; width:1920px; height:1080px;
           transform-origin:center center; }
  #stage iframe { width:1920px; height:1080px; border:0; display:block;
                  pointer-events:none; }
  #hint { position:fixed; bottom:14px; left:50%; transform:translateX(-50%);
          font:14px system-ui,sans-serif; color:#8aa0c0; background:rgba(12,20,34,.85);
          padding:7px 16px; border-radius:18px; opacity:0; transition:opacity .4s;
          pointer-events:none; }
  #hint.show { opacity:1; }
  body.signage-blank #signage-ticker-root,
  body.signage-blank #signage-ticker { display:none !important; }
</style>
</head>
<body<?= $blankActive ? ' class="signage-blank"' : '' ?>>
<div id="stage"><iframe src="<?= htmlspecialchars($src) ?>" allow="autoplay; fullscreen"></iframe></div>
<?php signage_kiosk_pointer_shield_html(); ?>
<div id="hint">Tap to toggle fullscreen</div>

<script>
  // ── Scale the 1920×1080 stage to fit whatever this device gives us ──
  const stage = document.getElementById('stage');
  function fit() {
    const s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
  }
  fit();
  addEventListener('resize', fit);
  if (screen.orientation && screen.orientation.addEventListener) {
    screen.orientation.addEventListener('change', fit);
  }

  // ── Tap toggles fullscreen; first tap also unlocks media autoplay in nested iframes ──
  const boardFrame = document.querySelector('#stage iframe');
  let gestureSent = false;
  function sendGesture() {
    if (gestureSent || !boardFrame || !boardFrame.contentWindow) return;
    gestureSent = true;
    boardFrame.contentWindow.postMessage({ type: 'signage-gesture' }, '*');
  }
  const hint = document.getElementById('hint');
  document.body.addEventListener('click', () => {
    sendGesture();
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen().catch(() => {});
  });
  if (!matchMedia('(display-mode: fullscreen)').matches
      && !matchMedia('(display-mode: standalone)').matches) {
    hint.classList.add('show');
    setTimeout(() => hint.classList.remove('show'), 4000);
  }

  // ── Keep the display awake while playing (best effort) ──
  async function wakeLock() {
    try {
      if ('wakeLock' in navigator) {
        const lock = await navigator.wakeLock.request('screen');
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') wakeLock();
        }, { once: true });
      }
    } catch (e) { /* not critical */ }
  }
  wakeLock();

  // Forward arrow keys to board.php (iframe has pointer-events:none so it never receives focus).
  document.addEventListener('keydown', function (e) {
    if (!boardFrame || !boardFrame.contentWindow) return;
    let dir = null;
    if (e.key === 'ArrowRight' || e.key === 'PageDown') dir = 'next';
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') dir = 'prev';
    if (!dir) return;
    e.preventDefault();
    boardFrame.contentWindow.postMessage({ type: 'signage-nav', dir: dir }, '*');
  });

  // ── PWA service worker (installability + tiny offline fallback) ──
  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }

  // Keep player chrome in sync when a scheduled blank window starts/ends
  (function () {
    const screen = <?= json_encode($SCREEN) ?>;
    const q = screen === 'main' ? 'board.php?api=1' : ('board.php?api=1&screen=' + encodeURIComponent(screen));
    function applyBlankState(on) {
      var wasBlank = document.body.classList.contains('signage-blank');
      document.body.classList.toggle('signage-blank', on);
      if (wasBlank !== on) {
        document.dispatchEvent(new CustomEvent('signage-blank', { detail: { on: on } }));
      }
    }
    window.addEventListener('message', function (ev) {
      if (!ev.data || ev.data.type !== 'signage-blank') return;
      if (typeof ev.data.on !== 'boolean') return;
      applyBlankState(ev.data.on);
    });
    function syncBlank() {
      fetch(q, { cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data || typeof data.blank !== 'boolean') return;
          applyBlankState(data.blank);
        })
        .catch(function () {});
    }
    syncBlank();
    setInterval(syncBlank, 30000);
  })();
</script>
<?php signage_kiosk_hide_pointer_script(); ?>
<?php if ($showTicker): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
