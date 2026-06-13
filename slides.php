<?php
/**
 * CUSTOM SLIDES — 1920×1080 signage
 * Upload JPG/PNG/WebP slides in admin and schedule each one:
 *   always       — show whenever this board is in rotation
 *   once         — single calendar date (date_start)
 *   range        — date_start … date_end (YYYY-MM-DD)
 *   yearly       — month_day MM-DD (birthdays, anniversaries)
 *   yearly_range — month_day … month_day_end every year (Dec 24–Jan 6)
 *   monthly      — day_of_month (1–31)
 *   weekly       — weekday and/or weekdays (Mon,Wed,Fri)
 *   hour_from/to — optional 0–23 window on any schedule (overnight OK)
 *   priority     — when any priority slide is active, only those show
 *
 * Add slides.php to the rotation in admin (dwell = longest slide or a comfortable average).
 */

require_once __DIR__ . '/slides_lib.php';

define('DEFAULT_DWELL', cfg('slides.DEFAULT_DWELL', 12));
define('SHUFFLE', cfg('slides.SHUFFLE', false));
define('FIT', cfg('slides.FIT', 'contain'));
define('TIMEZONE', slides_timezone());

date_default_timezone_set(TIMEZONE);

$dir     = slides_dir();
$entries = slides_active_entries(null, $dir);
if (SHUFFLE) {
    shuffle($entries);
}

$frameH = signage_frame_height();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Image endpoint ───────────────────────────────────────────────────────────
if (isset($_GET['img'])) {
    $all = slides_list_files($dir);
    $name = slide_safe_filename((string)$_GET['img']);
    if ($name === null || !in_array($name, $all, true)) {
        http_response_code(404);
        exit;
    }
    $path = $dir . '/' . $name;
    $mime = preg_match('/\.png$/i', $name) ? 'image/png'
          : (preg_match('/\.webp$/i', $name) ? 'image/webp' : 'image/jpeg');
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$playlist = array_map(fn($s) => [
    'file'    => $s['file'],
    'caption' => (string)($s['caption'] ?? ''),
    'dwell'   => slide_dwell($s, DEFAULT_DWELL),
], $entries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Custom Slides</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --mist:#8aa0c0; --beacon:#ffb347; --snow:#edf2fb; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:#000; color:var(--snow);
              font-family:'IBM Plex Sans',sans-serif; cursor:none;
              height:calc(<?= $frameH ?>px - var(--signage-ticker-inset, 0px)); }
  .layer { position:absolute; inset:0; background-position:center; background-repeat:no-repeat;
           background-color:#000; opacity:0; transition:opacity 1.4s ease;
           background-size:<?= FIT === 'cover' ? 'cover' : 'contain' ?>; }
  .layer.show { opacity:1; }
  @media (prefers-reduced-motion: reduce) { .layer { transition:none; } }
  .caption { position:absolute; left:44px; bottom:36px; z-index:10; font-size:24px;
             letter-spacing:.5px; color:var(--mist); text-shadow:0 1px 12px rgba(0,0,0,.85); max-width:70%; }
  #clock { position:absolute; right:44px; bottom:36px; z-index:10;
           font-family:'Big Shoulders Display'; font-weight:600; font-size:40px; color:var(--snow);
           opacity:.85; text-shadow:0 1px 12px rgba(0,0,0,.85); font-variant-numeric:tabular-nums; }
  .empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:var(--mist); background:var(--lake-night); text-align:center; padding:40px; }
  .empty h1 { font-family:'Big Shoulders Display'; font-size:58px; color:var(--beacon); }
  .empty p { font-size:26px; line-height:1.5; max-width:900px; }
  .empty code { color:var(--snow); background:#141f33; padding:2px 10px; border-radius:6px; }
</style>
</head>
<body>
<?php if (!$playlist): ?>
  <div class="empty">
    <h1>No slides scheduled</h1>
    <p>Upload images in <code>admin.php → Custom Slides</code>, set each slide's schedule,
       then add <code>slides.php</code> to the rotation.</p>
  </div>
<?php else: ?>
  <div class="layer" id="layerA"></div>
  <div class="layer" id="layerB"></div>
  <div class="caption" id="caption"></div>
  <div id="clock">--:--</div>
  <script>
    const SLIDES = <?= json_encode(array_values($playlist)) ?>;
    const layers = [document.getElementById('layerA'), document.getElementById('layerB')];
    const cap = document.getElementById('caption');
    let idx = 0, front = 0, timer = null;

    function imgUrl(file) { return '?img=' + encodeURIComponent(file); }

    function preload(file) {
      return new Promise(function (res) {
        const i = new Image();
        i.onload = i.onerror = function () { res(); };
        i.src = imgUrl(file);
      });
    }

    async function showNext() {
      const slide = SLIDES[idx];
      idx = (idx + 1) % SLIDES.length;
      await preload(slide.file);
      const back = 1 - front;
      layers[back].style.backgroundImage = "url('" + imgUrl(slide.file) + "')";
      layers[back].classList.add('show');
      layers[front].classList.remove('show');
      front = back;
      cap.textContent = slide.caption || '';
      clearTimeout(timer);
      timer = setTimeout(showNext, slide.dwell * 1000);
    }

    showNext();

    function tick() {
      const n = new Date();
      let h = n.getHours();
      const ap = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      document.getElementById('clock').textContent =
        h + ':' + String(n.getMinutes()).padStart(2, '0') + ' ' + ap;
    }
    tick();
    setInterval(tick, 1000);

    // Reload periodically so schedule boundaries (midnight, hours, birthdays) pick up
    setTimeout(function () { location.reload(); }, 5 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
