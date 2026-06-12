<?php
/**
 * VEDDERS VISUALS — ambient photo rotator, 1920×1080 signage
 * Crossfading slideshow from a local directory, with optional camera EXIF
 * captions. Photos can live outside the webroot; this script serves them.
 *
 * Setup: upload in admin or point PHOTO_DIR at a folder of JPG/PNG images (default ./photos).
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/rotator_lib.php';

define('PHOTO_DIR', rotator_photo_dir());
define('BRAND', cfg('rotator.BRAND', 'VEDDERS VISUALS'));
define('INTERVAL_SEC', cfg('rotator.INTERVAL_SEC', 18));
define('SHUFFLE', cfg('rotator.SHUFFLE', true));
define('SHOW_EXIF', cfg('rotator.SHOW_EXIF', true));
define('TIMEZONE', cfg('rotator.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

$photos = rotator_list_photos(PHOTO_DIR);

// ── Image + metadata endpoints (filenames validated against the listing) ────
if (isset($_GET['img']) || isset($_GET['meta'])) {
    $name = rotator_safe_filename(basename((string)($_GET['img'] ?? $_GET['meta'] ?? '')));
    if ($name === null || !in_array($name, $photos, true)) { http_response_code(404); exit; }
    $path = PHOTO_DIR . '/' . $name;

    if (isset($_GET['meta'])) {
        header('Content-Type: application/json');
        $meta = ['camera' => null, 'date' => null];
        if (SHOW_EXIF && function_exists('exif_read_data') && preg_match('/\.jpe?g$/i', $name)) {
            $exif = @exif_read_data($path);
            if (is_array($exif)) {
                $model = trim((string)($exif['Model'] ?? ''));
                $make  = trim((string)($exif['Make'] ?? ''));
                if ($model !== '') {
                    // Avoid "SONY SONY ILCE-..." style duplication
                    $meta['camera'] = (stripos($model, $make) === false && $make !== '')
                        ? ucwords(strtolower($make)) . ' ' . $model : $model;
                }
                $dt = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                if ($dt) { $t = strtotime($dt); if ($t) $meta['date'] = date('F Y', $t); }
            }
        }
        echo json_encode($meta);
        exit;
    }

    $mime = preg_match('/\.png$/i', $name) ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (SHUFFLE) shuffle($photos);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars(BRAND) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --mist:#8aa0c0; --beacon:#ffb347; --snow:#edf2fb; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:#000;
              font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .layer { position:absolute; inset:0; background-position:center; background-size:contain;
           background-repeat:no-repeat; background-color:#000;
           opacity:0; transition:opacity 2.2s ease; }
  .layer.show { opacity:1; }
  @media (prefers-reduced-motion: reduce) { .layer { transition:none; } }

  .brand { position:absolute; left:44px; bottom:36px; z-index:10;
           font-family:'Big Shoulders Display'; font-weight:600; font-size:34px;
           letter-spacing:7px; color:var(--snow); opacity:.85;
           text-shadow:0 1px 14px rgba(0,0,0,.8); }
  .brand b { color:var(--beacon); font-weight:600; }
  .caption { position:absolute; left:46px; bottom:84px; z-index:10; font-size:21px;
             letter-spacing:1px; color:var(--mist); text-shadow:0 1px 10px rgba(0,0,0,.8); }
  #clock { position:absolute; right:44px; bottom:36px; z-index:10;
           font-family:'Big Shoulders Display'; font-weight:600; font-size:40px;
           color:var(--snow); opacity:.8; text-shadow:0 1px 14px rgba(0,0,0,.8);
           font-variant-numeric:tabular-nums; }
  .empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:var(--mist); background:var(--lake-night); }
  .empty h1 { font-family:'Big Shoulders Display'; font-size:64px; color:var(--beacon); }
  .empty p { font-size:28px; } .empty code { color:var(--snow); }
</style>
</head>
<body>
<?php if (!$photos): ?>
  <div class="empty">
    <h1><?= htmlspecialchars(BRAND) ?></h1>
    <p>No images yet — upload JPGs in <code>admin.php → Photo Rotator</code> or add files to <code><?= htmlspecialchars(PHOTO_DIR) ?></code>.</p>
  </div>
<?php else: ?>
  <div class="layer" id="layerA"></div>
  <div class="layer" id="layerB"></div>
  <div class="caption" id="caption"></div>
  <?php [$brandFirst, $brandRest] = array_pad(explode(' ', BRAND, 2), 2, ''); ?>
  <div class="brand"><b><?= htmlspecialchars($brandFirst) ?></b><?= $brandRest !== '' ? '&nbsp;' . htmlspecialchars($brandRest) : '' ?></div>
  <div id="clock">--:--</div>

  <script>
    const PHOTOS   = <?= json_encode(array_values($photos)) ?>;
    const INTERVAL = <?= (int)INTERVAL_SEC ?> * 1000;
    const SHOW_EXIF = <?= SHOW_EXIF ? 'true' : 'false' ?>;
    const layers = [document.getElementById('layerA'), document.getElementById('layerB')];
    const cap = document.getElementById('caption');
    let idx = 0, front = 0;

    function url(name)  { return '?img='  + encodeURIComponent(name); }
    function meta(name) { return '?meta=' + encodeURIComponent(name); }

    function preload(name) {
      return new Promise(res => { const i = new Image(); i.onload = i.onerror = () => res(); i.src = url(name); });
    }

    async function showNext() {
      const name = PHOTOS[idx];
      idx = (idx + 1) % PHOTOS.length;
      await preload(name);
      const back = 1 - front;
      layers[back].style.backgroundImage = "url('" + url(name) + "')";
      layers[back].classList.add('show');
      layers[front].classList.remove('show');
      front = back;

      cap.textContent = '';
      if (SHOW_EXIF) {
        try {
          const r = await fetch(meta(name));
          const m = await r.json();
          const bits = [m.camera, m.date].filter(Boolean);
          cap.textContent = bits.join('  ·  ');
        } catch (e) { /* caption is optional */ }
      }
    }

    showNext();
    setInterval(showNext, INTERVAL);

    function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
      document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
    tick(); setInterval(tick, 1000);

    // Pick up newly added photos a few times a day
    setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
