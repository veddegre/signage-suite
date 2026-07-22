<?php
/**
 * WEBCAM BOARD — 1920×1080 signage
 * One camera per rotation slot — same pattern as zabbix.php?d= / splunk.php?d=.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/webcam_lib.php';

$cam = webcam_resolve_camera((string)($_GET['cam'] ?? ''));
if (isset($_GET['api']) && (string)$_GET['api'] === '1') {
    webcam_stream_api_response($cam);
}

define('TITLE', cfg('webcam.TITLE', 'Live Webcam'));
define('SHOW_OVERLAY', cfg('webcam.SHOW_OVERLAY', true));
define('RELOAD_SEC', cfg('webcam.RELOAD_SEC', 3600));
define('IMAGE_REFRESH_SEC', max(10, (int)cfg('webcam.IMAGE_REFRESH_SEC', 15)));
define('STREAM_REFRESH_SEC', max(300, (int)cfg('webcam.STREAM_REFRESH_SEC', 1500)));
define('TIMEZONE', cfg('webcam.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$reloadSec = max(0, (int)RELOAD_SEC);
$imageRefreshSec = ($cam['kind'] ?? '') === 'widget'
    ? max(10, min(IMAGE_REFRESH_SEC, 20))
    : IMAGE_REFRESH_SEC;
$streamRefreshSec = STREAM_REFRESH_SEC;
$boardAttribution = trim((string)cfg('webcam.ATTRIBUTION', ''));
$usesImage = webcam_uses_image_tag($cam);
$usesStream = webcam_uses_stream_tag($cam);
$imageSrc = $usesImage ? webcam_board_image_src($cam) : '';
$streamPlaylist = $usesStream ? webcam_hls_proxy_url((string)$cam['key']) : null;
$streamReady = true;
if ($usesStream) {
    $streamReady = webcam_hls_proxied_playlist($cam) !== null;
}
$available = !$cam['off'] && trim($cam['url']) !== '' && (!$usesStream || $streamReady);
$attribution = $boardAttribution !== '' ? $boardAttribution : (string)$cam['attribution'];
$camJson = $cam;
$camJson['imageSrc'] = $imageSrc;
$camJson['streamPlaylist'] = $streamPlaylist;
$camJson['streamApi'] = 'webcam.php?cam=' . rawurlencode((string)$cam['key']) . '&api=1';
$camJson['streamIframe'] = (string)$cam['url'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($available ? (string)$cam['name'] : TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<?php if ($usesStream && is_file(dirname(__DIR__, 2) . '/' . webcam_hls_js_url())): ?>
<script src="<?= h(webcam_hls_js_url()) ?>"></script>
<?php endif; ?>
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= h($heightCss) ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; cursor:none; }
  .board { position:relative; width:1920px; height:<?= h($heightCss) ?>; }
  .frame { position:absolute; inset:0; overflow:hidden; background:var(--lake-night); }
  .frame iframe, .frame img, .frame video { width:100%; height:100%; border:0; display:block;
                               object-fit:cover; object-position:center; background:var(--lake-night); }
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
  <?php if ($available): ?>
  <div class="frame" id="frame">
    <?php if ($usesStream): ?>
    <video id="cam-video" autoplay muted playsinline></video>
    <?php elseif ($usesImage): ?>
    <img id="cam-img" alt="<?= h((string)$cam['name']) ?>" src="">
    <?php else: ?>
    <iframe id="cam-frame" allow="autoplay; fullscreen" loading="eager"
            src="<?= h((string)$cam['url']) ?>"></iframe>
    <?php endif; ?>
  </div>
  <?php if (SHOW_OVERLAY): ?>
  <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  <div class="overlay">
    <h1><?= h(TITLE !== '' ? TITLE : (string)$cam['name']) ?><span class="sub"><?= h((string)$cam['name']) ?></span></h1>
  </div>
  <?php endif; ?>
  <?php if ($attribution !== ''): ?>
  <div class="stamp"><?= h($attribution) ?></div>
  <?php endif; ?>
  <?php else: ?>
  <div class="empty">
    <h2>Webcam not available</h2>
    <p>Add cameras in admin → <strong>Webcam</strong>, then add each feed to rotation separately —
       e.g. <code>webcam.php?cam=grpm</code>, <code>webcam.php?cam=gvsu</code>.</p>
  </div>
  <?php endif; ?>
</div>
<?php if ($available): ?>
<script>
(function(){
  const cam = <?= json_encode($camJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const reloadMs = <?= (int)$reloadSec ?> * 1000;
  const imageRefreshMs = <?= (int)$imageRefreshSec ?> * 1000;
  const streamRefreshMs = <?= (int)$streamRefreshSec ?> * 1000;
  let hlsPlayer = null;

  function refreshImageSrc(base) {
    const sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 't=' + Date.now();
  }

  function showStreamIframe(url) {
    const frame = document.getElementById('frame');
    if (!frame) return;
    frame.innerHTML = '<iframe id="cam-frame" allow="autoplay; fullscreen; encrypted-media" loading="eager" src="' + url + '"></iframe>';
  }

  function loadStream(playlistUrl) {
    const video = document.getElementById('cam-video');
    if (!video || !playlistUrl) {
      if (cam.streamIframe) showStreamIframe(cam.streamIframe);
      return;
    }
    if (window.Hls && window.Hls.isSupported()) {
      if (hlsPlayer) {
        hlsPlayer.destroy();
        hlsPlayer = null;
      }
      hlsPlayer = new window.Hls({ enableWorker: true, lowLatencyMode: true });
      hlsPlayer.on(window.Hls.Events.ERROR, function (_event, data) {
        if (data && data.fatal && cam.streamIframe) {
          showStreamIframe(cam.streamIframe);
        }
      });
      hlsPlayer.loadSource(refreshImageSrc(playlistUrl));
      hlsPlayer.attachMedia(video);
      hlsPlayer.on(window.Hls.Events.MANIFEST_PARSED, function () {
        video.play().catch(function () {
          if (cam.streamIframe) showStreamIframe(cam.streamIframe);
        });
      });
      setTimeout(function () {
        if (video.readyState < 2 && cam.streamIframe) {
          showStreamIframe(cam.streamIframe);
        }
      }, 12000);
      return;
    }
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = refreshImageSrc(playlistUrl);
      video.play().catch(function () {
        if (cam.streamIframe) showStreamIframe(cam.streamIframe);
      });
      return;
    }
    if (cam.streamIframe) showStreamIframe(cam.streamIframe);
  }

  async function refreshStreamPlaylist() {
    try {
      const res = await fetch(cam.streamApi, { cache: 'no-store' });
      const data = await res.json();
      if (data && data.ok && data.playlist) {
        loadStream(data.playlist);
      }
    } catch (e) {}
  }

  if (cam.streamPlaylist) {
    loadStream(cam.streamPlaylist);
    setInterval(refreshStreamPlaylist, streamRefreshMs);
    return;
  }

  if (cam.imageSrc) {
    const img = document.getElementById('cam-img');
    if (!img) return;
    const preload = new Image();
    function showLoaded(el) {
      img.src = el.src;
    }
    function refresh() {
      preload.onload = function () { showLoaded(preload); };
      preload.src = refreshImageSrc(cam.imageSrc);
      if (preload.complete) showLoaded(preload);
    }
    refresh();
    setInterval(refresh, imageRefreshMs);
    return;
  }

  const frame = document.getElementById('cam-frame');
  if (!frame || reloadMs <= 0) return;
  setInterval(function () {
    frame.src = cam.url.split('#')[0];
  }, reloadMs);
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
