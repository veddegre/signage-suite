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
$available = webcam_board_is_available($cam);
$attribution = $boardAttribution !== '' ? $boardAttribution : (string)$cam['attribution'];
$camJson = $cam;
$camJson['imageSrc'] = $imageSrc;
$camJson['streamPlaylist'] = $streamPlaylist;
$camJson['streamApi'] = 'webcam.php?cam=' . rawurlencode((string)$cam['key']) . '&api=1';
$camJson['streamIframe'] = (string)$cam['url'];
$camJson['preferIframe'] = webcam_stream_prefers_iframe_embed($cam);
$earthcamIframeWarmup = webcam_earthcam_iframe_warmup($cam);
$iframeEmbed = $available && !$usesImage && !$usesStream;
$camJson['earthcamWarmup'] = $earthcamIframeWarmup;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($available ? (string)$cam['name'] : TITLE) ?></title>
<?= signage_theme_fonts_head_html() ?>
<?php if ($usesStream && is_file(dirname(__DIR__, 2) . '/' . webcam_hls_js_url())): ?>
<script src="<?= h(webcam_hls_js_url()) ?>"></script>
<?php endif; ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= h($heightCss) ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',system-ui,sans-serif; cursor:none; }
  .board { position:relative; width:1920px; height:<?= h($heightCss) ?>; overflow:hidden; }
  .frame { position:absolute; inset:0; overflow:hidden; background:var(--lake-night); }
  .frame iframe, .frame img, .frame video { width:100%; height:100%; border:0; display:block;
                               object-fit:cover; object-position:center; background:var(--tile-bg);
                               overflow:hidden; }
  /* Third-party iframe embeds (EarthCam, WMTA/wetmet, …) occasionally paint scrollbars */
  .frame.iframe-embed iframe {
    width:102%; height:102%; max-width:none; max-height:none;
    transform:translate(-1%, -1%); transform-origin:center center;
  }
  .overlay { position:absolute; top:<?= $boardH < 1080 ? 18 : 24 ?>px; left:<?= $boardH < 1080 ? 24 : 32 ?>px;
             z-index:2; pointer-events:none;
             padding:12px 18px; border-radius:12px; <?= signage_glass_panel_css() ?> }
  .overlay h1 { font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:700;
                font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; letter-spacing:.5px; }
  .overlay .sub { display:block; margin-top:4px; font-size:<?= $boardH < 1080 ? 17 : 19 ?>px;
                   letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); font-weight:500; }
  #clock { position:fixed; top:36px; right:48px; z-index:9000; pointer-events:none;
           font-family:'Big Shoulders Display',system-ui,sans-serif; font-weight:600; font-size:48px;
           color:var(--snow); font-variant-numeric:tabular-nums;
           padding:6px 18px; border-radius:10px; <?= signage_glass_panel_css() ?>
           box-shadow:0 2px 24px color-mix(in srgb, var(--lake-night) 45%, transparent); }
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
  <div class="frame<?= $iframeEmbed ? ' iframe-embed' : '' ?>" id="frame">
    <?php if ($usesStream): ?>
    <video id="cam-video" autoplay muted playsinline></video>
    <?php elseif ($usesImage): ?>
    <img id="cam-img" alt="<?= h((string)$cam['name']) ?>" src="">
    <?php else: ?>
    <iframe id="cam-frame" scrolling="no" allow="autoplay; fullscreen; encrypted-media" loading="eager"<?php
      if (!$earthcamIframeWarmup): ?> src="<?= h((string)$cam['url']) ?>"<?php endif; ?>></iframe>
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
    <?php if ($usesStream && trim((string)$cam['url']) !== ''): ?>
    <p>This camera&rsquo;s live stream is offline or stale at the source
       (<?= h((string)$cam['name']) ?>).
       Add a working feed under admin → <strong>Webcam</strong>, or pick another quick-add entry in <strong>Rotation</strong>.</p>
    <?php else: ?>
    <p>Add cameras in admin → <strong>Webcam</strong>, then add each feed to rotation separately —
       e.g. <code>webcam.php?cam=grpm</code>, <code>webcam.php?cam=grandhaven</code>, <code>webcam.php?cam=muskegon</code>.</p>
    <?php endif; ?>
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
    frame.classList.add('iframe-embed');
    frame.innerHTML = '<iframe id="cam-frame" scrolling="no" allow="autoplay; fullscreen; encrypted-media" loading="eager" src="' + url + '"></iframe>';
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

  if (cam.preferIframe && cam.streamIframe) {
    showStreamIframe(cam.streamIframe);
    return;
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
    function frameOk(el) {
      return el && el.complete && el.naturalWidth > 0 && el.naturalHeight > 0;
    }
    function showLoaded(el) {
      if (!frameOk(el)) return;
      img.src = el.src;
    }
    function refresh() {
      preload.onload = function () { showLoaded(preload); };
      preload.onerror = function () {};
      preload.src = refreshImageSrc(cam.imageSrc);
      if (frameOk(preload)) showLoaded(preload);
    }
    img.onerror = function () {};
    refresh();
    setInterval(refresh, imageRefreshMs);
    return;
  }

  const frame = document.getElementById('cam-frame');
  if (!frame) return;

  function iframeBaseUrl() {
    return (cam.url || '').split('#')[0];
  }

  function iframeBustUrl() {
    const base = iframeBaseUrl();
    if (!base) return base;
    const sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 'ec=' + Date.now();
  }

  function startIframeHourlyReload() {
    if (reloadMs <= 0) return;
    setInterval(function () {
      frame.src = iframeBaseUrl();
    }, reloadMs);
  }

  if (cam.earthcamWarmup) {
    frame.style.opacity = '0';
    frame.style.transition = 'opacity 0.4s ease';
    let loadPass = 0;
    let shown = false;
    function revealIframe() {
      if (shown) return;
      shown = true;
      frame.style.opacity = '1';
      startIframeHourlyReload();
    }
    frame.onload = function () {
      loadPass++;
      if (loadPass === 1) {
        setTimeout(function () {
          frame.src = iframeBustUrl();
        }, 700);
        return;
      }
      frame.onload = null;
      revealIframe();
    };
    frame.src = iframeBustUrl();
    setTimeout(revealIframe, 14000);
    return;
  }

  startIframeHourlyReload();
})();
<?php if ($showClock && SHOW_OVERLAY): ?>
<?= signage_clock_tick_script('clock', TIMEZONE) ?>
<?php endif; ?>
</script>
<?php endif; ?>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
