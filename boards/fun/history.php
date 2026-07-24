<?php
/**
 * THIS DAY IN HISTORY — 1920×1080 signage
 *
 * Data: Wikipedia REST API "On this day" (free, no key).
 * https://en.wikipedia.org/api/rest_v1/feed/onthisday/all/{MM}/{DD}
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('history.TITLE', 'This Day in History'));
define('SUBTITLE', cfg('history.SUBTITLE', 'Wikipedia'));
define('TIMEZONE', cfg('history.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('history.RELOAD_SEC', 3600));
define('MAX_ITEMS', max(4, min(12, (int)cfg('history.MAX_ITEMS', 8))));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('history.CACHE_TTL', 86400));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function history_cached_json(string $url, string $key): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . '/' . $key . '.json';
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'HomeSignage/HistoryBoard/1.0 (contact: admin@localhost)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d)) {
            @file_put_contents($f, $body, LOCK_EX);
            return $d;
        }
    }
    $GLOBALS['diag']['wikipedia'] = $err !== '' ? "curl: $err" : "HTTP $code";
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

function history_clean_text(string $text): string
{
    $text = preg_replace('/\s*\([^)]*pictured[^)]*\)/i', '', $text) ?? $text;
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

function history_page_thumb(?array $pages): ?string
{
    if (!is_array($pages)) {
        return null;
    }
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $src = $page['thumbnail']['source'] ?? $page['originalimage']['source'] ?? null;
        if (is_string($src) && $src !== '') {
            return $src;
        }
    }
    return null;
}

/** @return list<array{year:?int,text:string,thumb:?string}> */
function history_normalize_items(?array $data, int $limit): array
{
    if (!$data) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach (['selected', 'events'] as $bucket) {
        foreach ($data[$bucket] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = history_clean_text((string)($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $key = strtolower($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $year = isset($item['year']) ? (int)$item['year'] : null;
            $out[] = [
                'year' => $year > 0 ? $year : null,
                'text' => $text,
                'thumb' => history_page_thumb($item['pages'] ?? null),
            ];
            if (count($out) >= $limit) {
                return $out;
            }
        }
    }
    return $out;
}

/** @return list<array{year:?int,text:string}> */
function history_people_snippets(?array $data, string $bucket, int $limit): array
{
    $out = [];
    foreach ($data[$bucket] ?? [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $text = history_clean_text((string)($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $year = isset($item['year']) ? (int)$item['year'] : null;
        $out[] = ['year' => $year > 0 ? $year : null, 'text' => $text];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

$mm = date('m');
$dd = date('d');
$cacheKey = 'wiki_otd_' . $mm . $dd;
$data = history_cached_json(
    'https://en.wikipedia.org/api/rest_v1/feed/onthisday/all/' . $mm . '/' . $dd,
    $cacheKey
);

$items = history_normalize_items($data, MAX_ITEMS);
$hero = $items[0] ?? null;
$list = array_slice($items, 1);
$births = history_people_snippets($data, 'births', 3);
$deaths = history_people_snippets($data, 'deaths', 3);
$hasData = $hero !== null;

$dateLabel = date('F j');
$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
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
           grid-template-columns: 1.15fr 0.85fr;
           grid-template-rows: <?= $rowHead ?>px 1fr auto;
           grid-template-areas:
             "head head"
             "hero side"
             "meta meta";
           min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '20px 24px' : '26px 32px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:12px; }

  .hero { grid-area:hero; display:grid; grid-template-columns: <?= $hero && $hero['thumb'] ? '340px 1fr' : '1fr' ?>;
          gap:<?= $boardH < 1080 ? 18 : 24 ?>px; align-items:stretch; min-height:0; }
  .hero-img { border-radius:12px; overflow:hidden; background:var(--lake-night); border:1px solid var(--hairline);
              min-height:<?= $boardH < 1080 ? 220 : 280 ?>px; }
  .hero-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .hero-body { display:flex; flex-direction:column; justify-content:center; min-height:0; }
  .hero-year { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 72 : 88 ?>px;
               line-height:1; color:var(--lilac); margin-bottom:14px; }
  .hero-text { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 30 : 36 ?>px; line-height:1.42; }

  .side { grid-area:side; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; min-height:0; }
  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 72px 1fr; gap:12px; align-items:start;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; }
  .row .yr { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
             color:var(--beacon); line-height:1.1; font-variant-numeric:tabular-nums; }
  .row .txt { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; line-height:1.38; color:var(--snow); }

  .people { display:grid; grid-template-columns:1fr 1fr; gap:<?= $boardH < 1080 ? 10 : 12 ?>px; }
  .people .col .k { margin-bottom:8px; font-size:15px; }
  .people .mini { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; line-height:1.35; color:var(--mist);
                  padding:8px 0; border-bottom:1px solid var(--hairline); }
  .people .mini:last-child { border-bottom:none; }
  .people .mini b { color:var(--snow); font-weight:600; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h($dateLabel) ?> · <?= h(SUBTITLE) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <section class="panel hero">
    <?php if ($hero['thumb']): ?>
    <div class="hero-img"><img src="<?= h($hero['thumb']) ?>" alt=""></div>
    <?php endif; ?>
    <div class="hero-body">
      <div class="k">Featured</div>
      <?php if ($hero['year']): ?><div class="hero-year"><?= (int)$hero['year'] ?></div><?php endif; ?>
      <div class="hero-text"><?= h($hero['text']) ?></div>
    </div>
  </section>

  <div class="side">
    <section class="panel list-panel" style="flex:1;display:flex;flex-direction:column;min-height:0">
      <div class="k">Also on this day</div>
      <div class="list">
        <?php foreach ($list as $row): ?>
        <div class="row">
          <div class="yr"><?= $row['year'] ? h((string)$row['year']) : '—' ?></div>
          <div class="txt"><?= h($row['text']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($births !== [] || $deaths !== []): ?>
    <section class="panel people">
      <?php if ($births !== []): ?>
      <div class="col">
        <div class="k">Born</div>
        <?php foreach ($births as $p): ?>
        <div class="mini"><?php if ($p['year']): ?><b><?= (int)$p['year'] ?></b> · <?php endif; ?><?= h($p['text']) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($deaths !== []): ?>
      <div class="col">
        <div class="k">Died</div>
        <?php foreach ($deaths as $p): ?>
        <div class="mini"><?php if ($p['year']): ?><b><?= (int)$p['year'] ?></b> · <?php endif; ?><?= h($p['text']) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <section class="panel" style="grid-column:1/-1">
    <div class="notcfg">History feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['wikipedia'] ?? '') : '' ?>.
      Check network access to <code>en.wikipedia.org</code> or try again shortly.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Wikipedia On This Day',
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
</body>
</html>
