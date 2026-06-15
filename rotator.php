<?php
/**
 * VEDDERS VISUALS — ambient photo rotator, 1920×1080 signage
 * Crossfading slideshow from a local directory, with optional camera EXIF
 * captions. Photos can live outside the webroot; this script serves them.
 *
 * Rotation modes (deploy from admin):
 *   rotator.php?photo=FILE  — one photo per playlist entry (dwell from rotation)
 *   rotator.php?group=KEY   — internal slideshow for one group
 *   rotator.php             — legacy all-photos slideshow
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rotator_lib.php';

define('PHOTO_DIR', rotator_photo_dir());
define('BRAND', cfg('rotator.BRAND', 'VEDDERS VISUALS'));
define('INTERVAL_SEC', max(1, (int)cfg('rotator.INTERVAL_SEC', 18)));
define('DEFAULT_DWELL', rotator_default_dwell());
define('SHUFFLE', cfg('rotator.SHUFFLE', true));
define('SHOW_EXIF', cfg('rotator.SHOW_EXIF', true));
define('SHOW_CLOCK', signage_show_clock((bool)cfg('rotator.SHOW_CLOCK', true)));
define('TIMEZONE', cfg('rotator.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

$diskPhotos = rotator_list_photos(PHOTO_DIR);
$deckPhotos = rotator_photos_for_screen(null);

function rotator_exif_meta(string $path, string $name): array
{
    $meta = ['camera' => null, 'date' => null];
    if (!SHOW_EXIF || !function_exists('exif_read_data') || !preg_match('/\.jpe?g$/i', $name)) {
        return $meta;
    }
    $exif = @exif_read_data($path);
    if (!is_array($exif)) {
        return $meta;
    }
    $model = trim((string)($exif['Model'] ?? ''));
    $make  = trim((string)($exif['Make'] ?? ''));
    if ($model !== '') {
        $meta['camera'] = (stripos($model, $make) === false && $make !== '')
            ? ucwords(strtolower($make)) . ' ' . $model : $model;
    }
    $dt = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
    if ($dt) {
        $t = strtotime($dt);
        if ($t) {
            $meta['date'] = date('F Y', $t);
        }
    }
    return $meta;
}

// ── Image + metadata endpoints ───────────────────────────────────────────────
if (isset($_GET['img']) || isset($_GET['meta'])) {
    $name = rotator_safe_filename(basename((string)($_GET['img'] ?? $_GET['meta'] ?? '')));
    if ($name === null || !in_array($name, $diskPhotos, true)) {
        http_response_code(404);
        exit;
    }
    $path = PHOTO_DIR . '/' . $name;

    if (isset($_GET['meta'])) {
        header('Content-Type: application/json');
        echo json_encode(rotator_exif_meta($path, $name));
        exit;
    }

    $mime = preg_match('/\.png$/i', $name) ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function rotator_img_url(string $name): string
{
    return '?img=' . rawurlencode($name);
}

function rotator_meta_url(string $name): string
{
    return '?meta=' . rawurlencode($name);
}

function rotator_brand_html(): void
{
    [$brandFirst, $brandRest] = array_pad(explode(' ', BRAND, 2), 2, '');
    echo '<div class="brand"><b>' . htmlspecialchars($brandFirst) . '</b>';
    if ($brandRest !== '') {
        echo '&nbsp;' . htmlspecialchars($brandRest);
    }
    echo '</div>';
}

function rotator_clock_html(): void
{
    if (!SHOW_CLOCK) {
        return;
    }
    echo '<div id="clock">--:--</div>';
}

function rotator_clock_js(): void
{
    if (!SHOW_CLOCK) {
        return;
    }
    echo <<<'JS'
    function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
      document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
    tick(); setInterval(tick, 1000);
    JS;
}

function rotator_page_shell_open(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --mist:#8aa0c0; --beacon:#ffb347; --snow:#edf2fb; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; <?= signage_viewport_css() ?> overflow:hidden; background:#000;
              font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .layer { position:absolute; inset:0; background-position:center; background-size:contain;
           background-repeat:no-repeat; background-color:#000;
           opacity:0; transition:opacity 2.2s ease; }
  .layer.show { opacity:1; }
  .layer.single { opacity:1; transition:none; }
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
           flex-direction:column; gap:16px; color:var(--mist); background:var(--lake-night); text-align:center; padding:40px; }
  .empty h1 { font-family:'Big Shoulders Display'; font-size:64px; color:var(--beacon); }
  .empty p { font-size:28px; line-height:1.5; max-width:900px; } .empty code { color:var(--snow); }
</style>
</head>
<body>
    <?php
}

function rotator_page_shell_close(): void
{
    include __DIR__ . '/ticker.php';
    echo '</body></html>';
}

// ── Single photo (rotation entry) ────────────────────────────────────────────
if (isset($_GET['photo'])) {
    $name = rotator_safe_filename((string)$_GET['photo']);
    $preview = isset($_GET['preview']);
    $photo = $name !== null ? rotator_deck_by_file($name) : null;
    $onDisk = $name !== null && is_file(PHOTO_DIR . '/' . $name);
    $active = $onDisk && ($preview || (is_array($photo) && empty($photo['off'])));
    $pageTitle = is_array($photo) && trim((string)($photo['caption'] ?? '')) !== ''
        ? trim((string)$photo['caption'])
        : ($name ?? 'Photo');
    rotator_page_shell_open($pageTitle);
    if ($active):
        $path = PHOTO_DIR . '/' . $name;
        $meta = rotator_exif_meta($path, $name);
        $caption = implode('  ·  ', array_filter([$meta['camera'], $meta['date']]));
    ?>
  <div class="layer single" style="background-image:url('<?= rotator_img_url((string)$name) ?>')"></div>
  <?php if ($caption !== ''): ?><div class="caption"><?= htmlspecialchars($caption) ?></div><?php endif; ?>
  <?php rotator_brand_html(); rotator_clock_html(); ?>
  <script><?php rotator_clock_js(); ?> setTimeout(function(){ location.reload(); }, 5 * 60 * 1000);</script>
    <?php else: ?>
  <div class="empty">
    <h1><?= $onDisk ? 'Photo disabled' : 'Photo not found' ?></h1>
    <p><?= $onDisk
        ? 'This photo is disabled in the deck or missing from rotation.'
        : 'That file is missing from the photo directory.' ?></p>
  </div>
    <?php endif;
    rotator_page_shell_close();
    exit;
}

// ── Group slideshow ──────────────────────────────────────────────────────────
if (isset($_GET['group'])) {
    $group = rotator_normalize_group((string)$_GET['group']);
    $groupPhotos = $group !== '' ? rotator_photos_in_group($group) : [];
    $names = array_values(array_map(static fn($p) => (string)$p['file'], $groupPhotos));
    if (SHUFFLE) {
        shuffle($names);
    }
    rotator_page_shell_open(BRAND . ' — ' . $group);
    if ($names === []): ?>
  <div class="empty">
    <h1><?= htmlspecialchars(BRAND) ?></h1>
    <p>No photos in group <code><?= htmlspecialchars($group) ?></code> — assign a group in admin and deploy.</p>
  </div>
    <?php else: ?>
  <div class="layer" id="layerA"></div>
  <div class="layer" id="layerB"></div>
  <div class="caption" id="caption"></div>
  <?php rotator_brand_html(); rotator_clock_html(); ?>
  <script>
    const PHOTOS   = <?= json_encode($names) ?>;
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
          cap.textContent = [m.camera, m.date].filter(Boolean).join('  ·  ');
        } catch (e) {}
      }
    }
    showNext();
    setInterval(showNext, INTERVAL);
    <?php rotator_clock_js(); ?>
    setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);
  </script>
    <?php endif;
    rotator_page_shell_close();
    exit;
}

// ── Legacy / full-deck slideshow ─────────────────────────────────────────────
$photos = $deckPhotos !== []
    ? array_values(array_map(static fn($p) => (string)$p['file'], $deckPhotos))
    : $diskPhotos;
if (SHUFFLE) {
    shuffle($photos);
}

rotator_page_shell_open(BRAND);
if (!$photos): ?>
  <div class="empty">
    <h1><?= htmlspecialchars(BRAND) ?></h1>
    <p>No images yet — upload JPGs in <code>admin.php → Photo Rotator</code> or add files to <code><?= htmlspecialchars(PHOTO_DIR) ?></code>.</p>
  </div>
<?php else: ?>
  <div class="layer" id="layerA"></div>
  <div class="layer" id="layerB"></div>
  <div class="caption" id="caption"></div>
  <?php rotator_brand_html(); rotator_clock_html(); ?>
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

    <?php rotator_clock_js(); ?>
    setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);
  </script>
<?php endif;
rotator_page_shell_close();
