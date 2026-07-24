<?php
/**
 * XKCD COMIC OF THE DAY — 1920×1080 signage
 *
 * Data: xkcd.com JSON API (free, no key).
 * https://xkcd.com/info.0.json
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('xkcd.TITLE', 'XKCD'));
define('SUBTITLE', cfg('xkcd.SUBTITLE', 'Comic of the Day'));
define('TIMEZONE', cfg('xkcd.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('xkcd.RELOAD_SEC', 0));
define('SHOW_ALT', cfg('xkcd.SHOW_ALT', true));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('xkcd.CACHE_TTL', 86400));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function xkcd_valid_img_url(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($host, ['imgs.xkcd.com', 'xkcd.com', 'www.xkcd.com'], true)) {
        return false;
    }
    $path = (string)($parts['path'] ?? '');
    return str_starts_with($path, '/comics/');
}

/** @return array{num:int,title:string,alt:string,img:string,date:string,url:string}|null */
function xkcd_normalize(?array $d): ?array
{
    if (!$d || !isset($d['num'], $d['img'])) {
        return null;
    }
    $num = (int)$d['num'];
    $img = trim((string)$d['img']);
    if ($num <= 0 || !xkcd_valid_img_url($img)) {
        return null;
    }
    $title = trim((string)($d['title'] ?? $d['safe_title'] ?? ''));
    if ($title === '') {
        $title = 'Comic #' . $num;
    }
    $alt = trim((string)($d['alt'] ?? ''));
    $year = trim((string)($d['year'] ?? ''));
    $month = trim((string)($d['month'] ?? ''));
    $day = trim((string)($d['day'] ?? ''));
    $date = ($year !== '' && $month !== '' && $day !== '')
        ? sprintf('%04d-%02d-%02d', (int)$year, (int)$month, (int)$day)
        : '';

    return [
        'num' => $num,
        'title' => $title,
        'alt' => $alt,
        'img' => $img,
        'date' => $date,
        'url' => 'https://xkcd.com/' . $num . '/',
    ];
}

/** @return array{num:int,title:string,alt:string,img:string,date:string,url:string}|null */
function xkcd_fetch_latest(): ?array
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $f = CACHE_DIR . '/xkcd_latest.json';
    if (CACHE_TTL > 0 && is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        $norm = xkcd_normalize(is_array($d) ? $d : null);
        if ($norm !== null) {
            return $norm;
        }
    }

    $ch = curl_init('https://xkcd.com/info.0.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'HomeSignage/XkcdBoard/1.0 (signage-suite)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        $norm = xkcd_normalize(is_array($d) ? $d : null);
        if ($norm !== null) {
            if (CACHE_TTL > 0) {
                @file_put_contents($f, $body, LOCK_EX);
            }
            return $norm;
        }
    }
    $GLOBALS['diag']['xkcd'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return xkcd_normalize(is_array($d) ? $d : null);
    }
    return null;
}

$comic = xkcd_fetch_latest();
$hasData = $comic !== null;
$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$showAlt = SHOW_ALT && $comic && $comic['alt'] !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Serif:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-rows: <?= $rowHead ?>px 1fr auto;
           grid-template-areas: "head" "main" "meta"; min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; gap:24px; min-width:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px;
             white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }

  .main { grid-area:main; min-height:0; display:flex; align-items:center; justify-content:center; }
  .panel { width:100%; height:100%; max-width:1760px; background:var(--harbor); border:1px solid var(--hairline);
           border-radius:20px; padding:<?= $boardH < 1080 ? '20px 24px' : '24px 28px' ?>;
           display:grid; gap:<?= $showAlt ? ($boardH < 1080 ? '16px' : '20px') : '0' ?>;
           grid-template-rows: auto 1fr<?= $showAlt ? ' auto' : '' ?>; min-height:0; }
  .comic-head { display:flex; align-items:baseline; justify-content:space-between; gap:16px; min-width:0; }
  .comic-title { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 34 : 40 ?>px; font-weight:600;
                 white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .comic-num { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; letter-spacing:2px; text-transform:uppercase;
               color:var(--gold); flex-shrink:0; }
  .comic-frame { min-height:0; display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .comic-frame img { max-width:100%; max-height:100%; width:auto; height:auto; object-fit:contain; display:block; }
  .comic-alt { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; line-height:1.45;
               color:var(--mist); font-style:italic; border-top:1px solid var(--hairline);
               padding-top:<?= $boardH < 1080 ? '14px' : '18px' ?>; max-height:<?= $boardH < 1080 ? '120px' : '148px' ?>;
               overflow:hidden; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; text-align:center; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <section class="main">
    <div class="panel">
      <div class="comic-head">
        <div class="comic-title"><?= h($comic['title']) ?></div>
        <div class="comic-num">#<?= (int)$comic['num'] ?><?= $comic['date'] !== '' ? ' · ' . h($comic['date']) : '' ?></div>
      </div>
      <div class="comic-frame">
        <img src="<?= h($comic['img']) ?>" alt="<?= h($comic['alt'] !== '' ? $comic['alt'] : $comic['title']) ?>">
      </div>
      <?php if ($showAlt): ?>
      <div class="comic-alt"><?= h($comic['alt']) ?></div>
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="main">
    <div class="notcfg">XKCD feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['xkcd'] ?? '') : '' ?>.
      Check network access to <code>xkcd.com</code>.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'xkcd.com',
    $comic ? '#' . $comic['num'] : '',
    $comic && $comic['date'] !== '' ? $comic['date'] : '',
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
  ]))) ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick() {
    const n = new Date();
    let h = n.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const m = String(n.getMinutes()).padStart(2, '0');
    const el = document.getElementById('clock');
    if (el) el.textContent = h + ':' + m + ' ' + ap;
  }
  tick();
  setInterval(tick, 1000);
  <?php endif; ?>
  <?php if (!$embedded && RELOAD_SEC > 0): ?>
  setTimeout(() => location.reload(), <?= (int)RELOAD_SEC * 1000 ?>);
  <?php endif; ?>
</script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
