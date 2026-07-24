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
 *   time windows — optional ranges (7 or 7:30) + active weekdays; legacy hour_from/to still supported
 *   priority     — when any priority slide is active, only those show
 *
 * Add each slide to the rotation from admin (Deploy to displays) — one playlist entry per slide with its own dwell.
 */

require_once dirname(__DIR__, 2) . '/lib/slides_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
require_once dirname(__DIR__, 2) . '/lib/rotation_lib.php';

define('DEFAULT_DWELL', cfg('slides.DEFAULT_DWELL', 12));
define('SHUFFLE', cfg('slides.SHUFFLE', false));
define('FIT', cfg('slides.FIT', 'contain'));
define('SHOW_CLOCK', signage_show_clock((bool)cfg('slides.SHOW_CLOCK', true)));
define('TIMEZONE', slides_timezone());

date_default_timezone_set(TIMEZONE);

$dir     = slides_dir();
$frameH = signage_frame_height();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Wall clock overlay — fixed above slide layers (and rotation iframes). */
function slides_clock_css(): string
{
    if (!SHOW_CLOCK) {
        return '';
    }

    return '#clock{position:fixed;top:36px;right:48px;z-index:9000;pointer-events:none;'
         . 'font-family:\'Big Shoulders Display\',system-ui,sans-serif;font-weight:600;font-size:48px;'
         . 'color:var(--snow);font-variant-numeric:tabular-nums;'
         . 'padding:6px 18px;border-radius:10px;background:rgba(12,20,34,.78);'
         . 'box-shadow:0 2px 24px rgba(0,0,0,.55);}';
}

function slides_clock_html(): void
{
    if (!SHOW_CLOCK) {
        return;
    }

    echo '<div id="clock">--:--</div>';
}

function slides_clock_js(): void
{
    if (!SHOW_CLOCK) {
        return;
    }

    echo <<<'JS'
    (function () {
      function tick() {
        const el = document.getElementById('clock');
        if (!el) return;
        const n = new Date();
        let h = n.getHours();
        const ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        el.textContent = h + ':' + String(n.getMinutes()).padStart(2, '0') + ' ' + ap;
      }
      tick();
      setInterval(tick, 1000);
    })();
    JS;
}

// ── Image endpoint ───────────────────────────────────────────────────────────
if (isset($_GET['img'])) {
    $all = slides_list_files($dir);
    $name = slide_safe_filename((string)$_GET['img']);
    if ($name === null || !in_array($name, $all, true)) {
        http_response_code(404);
        exit;
    }
    if (admin_media_img_scope_active()) {
        $slide = slide_deck_by_file($name);
        if (!is_array($slide) || !admin_entry_visible_for_user($slide, admin_display_scope_user_id())) {
            http_response_code(404);
            exit;
        }
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

// ── Single slide (rotation entry) ────────────────────────────────────────────
if (isset($_GET['slide'])) {
    $name = slide_safe_filename((string)$_GET['slide']);
    $preview = isset($_GET['preview']);
    $slide = $name !== null ? slide_deck_by_file($name) : null;
    $tz = new DateTimeZone(TIMEZONE);
    $now = new DateTime('now', $tz);
    $onDisk = $name !== null && is_file($dir . '/' . $name);
    $scheduled = is_array($slide)
        && empty($slide['off'])
        && slide_schedule_active($slide, $now);
    $active = $onDisk && ($preview || $scheduled);
    if ($preview && admin_preview_filter_active() && (!is_array($slide) || !admin_entry_visible_for_user($slide, admin_display_scope_user_id()))) {
        $active = false;
    }
    $pageTitle = is_array($slide) && trim((string)($slide['caption'] ?? '')) !== ''
        ? trim((string)$slide['caption'])
        : ($name ?? 'Slide');
    $fit = FIT;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:#000; color:var(--snow);
              font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .layer { position:absolute; inset:0; z-index:1; background-position:center; background-repeat:no-repeat;
           background-color:#000; background-size:<?= $fit === 'cover' ? 'cover' : 'contain' ?>; }
  <?= slides_clock_css() ?>
  .empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:var(--mist); background:var(--lake-night); text-align:center; padding:40px; }
  .empty h1 { font-family:'Big Shoulders Display'; font-size:58px; color:var(--beacon); }
  .empty p { font-size:26px; line-height:1.5; max-width:900px; }
</style>
</head>
<body>
<?php if ($active): ?>
  <div class="layer" style="background-image:url('?img=<?= rawurlencode((string)$name) ?>')"></div>
  <?php slides_clock_html(); ?>
  <script>
    <?php slides_clock_js(); ?>
    setTimeout(function () { location.reload(); }, 5 * 60 * 1000);
  </script>
<?php else: ?>
  <div class="empty">
    <h1><?= $onDisk ? 'Slide not scheduled' : 'Slide not found' ?></h1>
    <p><?= $onDisk
        ? 'This slide is off rotation or outside its schedule window.'
        : 'That file is missing from the slide directory.' ?></p>
  </div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
    <?php
    exit;
}

$deck = cfg('slides.SLIDES', []);
if (!is_array($deck)) {
    $deck = [];
}
$slideScreen = rotation_normalize_screen_key((string)($_GET['screen'] ?? 'main'));
$entries = slides_active_entries(admin_filter_list_for_display($deck), $dir, $slideScreen);
if (SHUFFLE) {
    shuffle($entries);
}

$playlist = array_map(fn($s) => [
    'file'  => $s['file'],
    'dwell' => slide_dwell($s, DEFAULT_DWELL),
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
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:#000; color:var(--snow);
              font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .layer { position:absolute; inset:0; z-index:1; background-position:center; background-repeat:no-repeat;
           background-color:#000; opacity:0; transition:opacity 1.4s ease;
           background-size:<?= FIT === 'cover' ? 'cover' : 'contain' ?>; }
  .layer.show { opacity:1; }
  @media (prefers-reduced-motion: reduce) { .layer { transition:none; } }
  <?= slides_clock_css() ?>
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
       then deploy from <strong>Deploy to displays</strong> (one rotation entry per slide).</p>
  </div>
<?php else: ?>
  <div class="layer" id="layerA"></div>
  <div class="layer" id="layerB"></div>
  <?php slides_clock_html(); ?>
  <script>
    const SLIDES = <?= json_encode(array_values($playlist)) ?>;
    const layers = [document.getElementById('layerA'), document.getElementById('layerB')];
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
      clearTimeout(timer);
      timer = setTimeout(showNext, slide.dwell * 1000);
    }

    showNext();

    <?php slides_clock_js(); ?>

    // Reload periodically so schedule boundaries (midnight, hours, birthdays) pick up
    setTimeout(function () { location.reload(); }, 5 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
