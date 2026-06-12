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

$SCREEN = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['screen'] ?? ''));
if ($SCREEN === '') $SCREEN = 'main';
$src = 'board.php' . ($SCREEN !== 'main' ? '?screen=' . rawurlencode($SCREEN) : '');
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
</style>
</head>
<body>
<div id="stage"><iframe src="<?= htmlspecialchars($src) ?>"></iframe></div>
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

  // ── Tap toggles fullscreen (needs a user gesture in normal tabs) ──
  const hint = document.getElementById('hint');
  document.body.addEventListener('click', () => {
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

  // ── PWA service worker (installability + tiny offline fallback) ──
  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
</script>
</body>
</html>
