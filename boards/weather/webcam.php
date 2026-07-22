<?php
/**
 * WEBCAM BOARD — 1920×1080 signage
 * Full-screen iframe or still-image feeds from the webcam registry.
 *
 * Built-in cameras: GVSU campus, WetMet station, Grand Haven beach (EarthCam).
 * Add more under admin → Webcam → Cameras. Pick one with ?cam=KEY or rotate all.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/webcam_lib.php';

define('TITLE', cfg('webcam.TITLE', 'Live Webcam'));
define('SHOW_OVERLAY', cfg('webcam.SHOW_OVERLAY', true));
define('RELOAD_SEC', cfg('webcam.RELOAD_SEC', 3600));
define('IMAGE_REFRESH_SEC', max(15, (int)cfg('webcam.IMAGE_REFRESH_SEC', 60)));
define('ROTATE_SEC', max(15, (int)cfg('webcam.ROTATE_SEC', 90)));
define('TIMEZONE', cfg('webcam.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$cameras = webcam_active_cameras();
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$reloadSec = max(0, (int)RELOAD_SEC);
$imageRefreshSec = IMAGE_REFRESH_SEC;
$rotateSec = ROTATE_SEC;
$rotate = count($cameras) > 1;
$primary = $cameras[0] ?? null;
$boardAttribution = trim((string)cfg('webcam.ATTRIBUTION', ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($primary ? (string)$primary['name'] : TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= h($heightCss) ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; cursor:none; }
  .board { position:relative; width:1920px; height:<?= h($heightCss) ?>; }
  .frame { position:absolute; inset:0; overflow:hidden; background:var(--lake-night); }
  .layer { position:absolute; inset:0; width:100%; height:100%; border:0; display:block;
           background:var(--lake-night); opacity:0; transition:opacity 1.2s ease; }
  .layer.on { opacity:1; }
  .layer img { width:100%; height:100%; object-fit:cover; object-position:center; }
  .overlay { position:absolute; top:<?= $boardH < 1080 ? 18 : 24 ?>px; left:<?= $boardH < 1080 ? 24 : 32 ?>px;
             z-index:2; pointer-events:none;
             padding:12px 18px; border-radius:12px; background:rgba(12,20,34,.72);
             border:1px solid var(--hairline); backdrop-filter:blur(6px); }
  .overlay h1 { font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:700;
                font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; letter-spacing:.5px; }
  .overlay .sub { display:block; margin-top:4px; font-size:<?= $boardH < 1080 ? 17 : 19 ?>px;
                   letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); font-weight:500; }
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
  <?php if ($primary): ?>
  <div class="frame" id="frame">
    <iframe id="layerA" class="layer" allow="autoplay; fullscreen" loading="eager"></iframe>
    <iframe id="layerB" class="layer" allow="autoplay; fullscreen" loading="lazy"></iframe>
    <img id="imgA" class="layer" alt="">
    <img id="imgB" class="layer" alt="">
  </div>
  <?php if (SHOW_OVERLAY): ?>
  <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  <div class="overlay">
    <h1><?= h(TITLE !== '' ? TITLE : (string)$primary['name']) ?><span class="sub" id="cam-label"><?= h((string)$primary['name']) ?></span></h1>
  </div>
  <?php endif; ?>
  <div class="stamp" id="stamp"><?= h($boardAttribution !== '' ? $boardAttribution : (string)$primary['attribution']) ?></div>
  <?php else: ?>
  <div class="empty">
    <h2>No webcam configured</h2>
    <p>Add cameras in admin → <strong>Webcam</strong>, or enable the built-in GVSU, WetMet, and Grand Haven feeds.
       Use <code>webcam.php?cam=gvsu</code> in rotation for a specific camera.</p>
  </div>
  <?php endif; ?>
</div>
<?php if ($primary): ?>
<script>
(function(){
  const cameras = <?= json_encode(array_values($cameras), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const boardAttribution = <?= json_encode($boardAttribution) ?>;
  const reloadMs = <?= (int)$reloadSec ?> * 1000;
  const imageRefreshMs = <?= (int)$imageRefreshSec ?> * 1000;
  const rotateMs = <?= (int)$rotateSec ?> * 1000;
  const rotate = <?= $rotate ? 'true' : 'false' ?>;
  const ifA = document.getElementById('layerA');
  const ifB = document.getElementById('layerB');
  const imgA = document.getElementById('imgA');
  const imgB = document.getElementById('imgB');
  const labelEl = document.getElementById('cam-label');
  const stampEl = document.getElementById('stamp');
  let idx = 0;
  let frontKind = '';
  let frontEl = null;
  let imageTimer = null;

  function bust(url) {
    try {
      const u = new URL(url, location.href);
      u.searchParams.set('t', String(Date.now()));
      return u.toString();
    } catch (e) {
      return url + (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
    }
  }

  function setMeta(cam) {
    if (labelEl) labelEl.textContent = cam.name || '';
    if (stampEl) {
      const attr = (cam.attribution || boardAttribution || '').trim();
      stampEl.textContent = attr;
      stampEl.style.display = attr ? '' : 'none';
    }
  }

  function hideAll() {
    [ifA, ifB, imgA, imgB].forEach(function (el) {
      if (!el) return;
      el.classList.remove('on');
      if (el.tagName === 'IFRAME') el.removeAttribute('src');
    });
  }

  function showCam(cam, el) {
    hideAll();
    frontEl = el;
    frontKind = cam.kind;
    setMeta(cam);
    if (cam.kind === 'image') {
      el.src = bust(cam.url);
      el.classList.add('on');
      if (imageTimer) clearInterval(imageTimer);
      imageTimer = setInterval(function () {
        if (frontEl === el) el.src = bust(cam.url);
      }, imageRefreshMs);
      return;
    }
    if (imageTimer) { clearInterval(imageTimer); imageTimer = null; }
    el.src = cam.url;
    el.classList.add('on');
    if (reloadMs > 0 && !rotate) {
      setInterval(function () {
        if (frontEl !== el) return;
        el.src = cam.url.split('#')[0];
      }, reloadMs);
    }
  }

  function crossfadeTo(i) {
    idx = ((i % cameras.length) + cameras.length) % cameras.length;
    const cam = cameras[idx];
    const useIframe = cam.kind !== 'image';
    const next = useIframe
      ? (frontEl === ifA ? ifB : ifA)
      : (frontEl === imgA ? imgB : imgA);
    if (cam.kind === 'image') {
      let done = false;
      function finish() {
        if (done) return;
        done = true;
        next.onload = null;
        showCam(cam, next);
      }
      next.onload = finish;
      next.src = bust(cam.url);
      if (next.complete) finish();
      return;
    }
    showCam(cam, next);
  }

  const first = cameras[0];
  const startEl = first.kind === 'image' ? imgA : ifA;
  showCam(first, startEl);
  if (rotate) {
    setInterval(function () { crossfadeTo(idx + 1); }, rotateMs);
  }
})();
<?php if ($showClock && SHOW_OVERLAY): ?>
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
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
