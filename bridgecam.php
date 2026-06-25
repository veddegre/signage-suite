<?php
/**
 * MACKINAC BRIDGE CAM — 1920×1080 signage
 * Live still images from the Mackinac Bridge Authority bridge cams.
 * Images refresh about every 60 seconds on the source site.
 *
 * @see https://www.mackinacbridge.org/fares-traffic/bridge-cam/
 */

require_once __DIR__ . '/config.php';

define('TITLE', cfg('bridgecam.TITLE', 'Mackinac Bridge'));
define('CAMERA', cfg('bridgecam.CAMERA', '4'));
define('ATTRIBUTION', cfg('bridgecam.ATTRIBUTION', 'Mackinac Bridge Authority'));
define('SHOW_OVERLAY', cfg('bridgecam.SHOW_OVERLAY', true));
define('REFRESH_SEC', cfg('bridgecam.REFRESH_SEC', 60));
define('ROTATE_SEC', cfg('bridgecam.ROTATE_SEC', 45));
define('TIMEZONE', cfg('bridgecam.TIMEZONE', 'America/Detroit'));

/** @return list<array{id:string,label:string,url:string}> */
function bridgecam_cameras(): array
{
    $base = 'https://www.mackinacbridge.org/wp-content/camimages/';
    return [
        ['id' => '1', 'label' => 'Administration building · south', 'url' => $base . 'MacBridge_image1_medium.jpg'],
        ['id' => '2', 'label' => 'St. Ignace dock · south', 'url' => $base . 'MacBridge_image2_large.jpg'],
        ['id' => '3', 'label' => 'Bridge View Park · south', 'url' => $base . 'MacBridge_image3_medium.jpg'],
        ['id' => '4', 'label' => 'Mackinaw City · north', 'url' => $base . 'MacBridge_image4_medium.jpg'],
    ];
}

/** Normalize admin CAMERA value (handles legacy saves that stored labels instead of keys). */
function bridgecam_normalize_camera(string $raw): string
{
    $pick = strtolower(trim($raw));
    if ($pick === '' || $pick === 'all') {
        return 'all';
    }
    if (in_array($pick, ['1', '2', '3', '4'], true)) {
        return $pick;
    }
    if (str_contains($pick, 'rotate')) {
        return 'all';
    }
    if (str_contains($pick, 'administration')) {
        return '1';
    }
    if (str_contains($pick, 'dock')) {
        return '2';
    }
    if (str_contains($pick, 'bridge view')) {
        return '3';
    }
    if (str_contains($pick, 'mackinaw')) {
        return '4';
    }
    return '4';
}

/** @return list<array{id:string,label:string,url:string}> */
function bridgecam_active_cameras(): array
{
    $all = bridgecam_cameras();
    $pick = bridgecam_normalize_camera((string)CAMERA);
    if ($pick === 'all') {
        return $all;
    }
    foreach ($all as $cam) {
        if ($cam['id'] === $pick) {
            return [$cam];
        }
    }
    return [$all[3]];
}

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$cameras = bridgecam_active_cameras();
$rotate = count($cameras) > 1;
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$refreshSec = max(5, (int)REFRESH_SEC);
$rotateSec = max(5, (int)ROTATE_SEC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
  .frame { position:absolute; inset:0; overflow:hidden; background:var(--lake-night); }
  .frame img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; object-position:center;
                opacity:0; transition:opacity 1.2s ease; }
  .frame img.on { opacity:1; }
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
</style>
</head>
<body>
<div class="board">
  <div class="frame">
    <img id="a" alt="">
    <img id="b" alt="">
  </div>
  <?php if (SHOW_OVERLAY): ?>
  <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  <div class="overlay">
    <h1><?= h(TITLE) ?><span class="sub" id="cam-label"><?= h($cameras[0]['label']) ?></span></h1>
  </div>
  <?php endif; ?>
  <?php if (ATTRIBUTION !== ''): ?>
  <div class="stamp"><?= h(ATTRIBUTION) ?></div>
  <?php endif; ?>
</div>
<script>
(function(){
  const cameras = <?= json_encode($cameras, JSON_UNESCAPED_SLASHES) ?>;
  const refreshMs = <?= (int)$refreshSec ?> * 1000;
  const rotateMs = <?= (int)$rotateSec ?> * 1000;
  const rotate = <?= $rotate ? 'true' : 'false' ?>;
  const layers = [document.getElementById('a'), document.getElementById('b')];
  const labelEl = document.getElementById('cam-label');
  let idx = 0;
  let front = 0;

  function bust(url) {
    const u = new URL(url, location.href);
    u.searchParams.set('t', String(Date.now()));
    return u.toString();
  }

  function setLabel(label) {
    if (labelEl) labelEl.textContent = label;
  }

  function loadFront() {
    const cam = cameras[idx];
    layers[front].src = bust(cam.url);
    setLabel(cam.label);
  }

  function crossfadeTo(i) {
    idx = ((i % cameras.length) + cameras.length) % cameras.length;
    const cam = cameras[idx];
    const next = layers[1 - front];
    const url = bust(cam.url);
    let done = false;
    function finish() {
      if (done) return;
      done = true;
      next.onload = null;
      next.classList.add('on');
      layers[front].classList.remove('on');
      front = 1 - front;
    }
    next.onload = finish;
    next.src = url;
    if (next.complete) finish();
    setLabel(cam.label);
  }

  layers[front].classList.add('on');
  loadFront();

  setInterval(loadFront, refreshMs);
  if (rotate) {
    setInterval(function() { crossfadeTo(idx + 1); }, rotateMs);
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
<?php if (!$embedded): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
