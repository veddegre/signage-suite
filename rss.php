<?php
/**
 * RSS STORY BOARD — 1920×1080 signage
 * Full-bleed story photo with headline + synopsis, cycling through the latest
 * items from an RSS or Atom feed.
 *
 * One file serves many feeds: define them in FEEDS below, then point each
 * Anthias web asset at  rss.php?feed=<name>  (e.g. rss.php?feed=krebs).
 * With no ?feed= parameter the first feed in the list is used.
 *
 * Per feed you can set how many stories to cycle ('stories') and seconds per
 * story ('dwell'); omit either to use the DEFAULT_* values.
 *
 * Images are pulled from media:content / media:thumbnail / enclosure /
 * itunes:image, falling back to the first <img> inside the article body.
 * Stories with no image render as a typographic card — nothing breaks.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

define('FEEDS', cfg('rss.FEEDS', [
    'ars'        => ['name' => 'Ars Technica',      'url' => 'https://feeds.arstechnica.com/arstechnica/index'],
    'krebs'      => ['name' => 'Krebs on Security',  'url' => 'https://krebsonsecurity.com/feed/'],
    'bleeping'   => ['name' => 'BleepingComputer',   'url' => 'https://www.bleepingcomputer.com/feed/', 'stories' => 6],
    'petapixel'  => ['name' => 'PetaPixel',          'url' => 'https://petapixel.com/feed/', 'dwell' => 15],

]));

define('DEFAULT_STORIES', cfg('rss.DEFAULT_STORIES', 8));
define('DEFAULT_DWELL', cfg('rss.DEFAULT_DWELL', 12));
define('SYNOPSIS_CHARS', cfg('rss.SYNOPSIS_CHARS', 280));
define('TIMEZONE', cfg('rss.TIMEZONE', 'America/Detroit'));
const CACHE_DIR       = __DIR__ . '/cache';
define('CACHE_TTL', cfg('rss.CACHE_TTL', 600));

date_default_timezone_set(TIMEZONE);
$frameH = signage_frame_height();
$GLOBALS['diag'] = [];

// ── Feed selection ───────────────────────────────────────────────────────────
$feedKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['feed'] ?? ''));
if ($feedKey === '' || !isset(FEEDS[$feedKey])) $feedKey = array_key_first(FEEDS);
$feed    = FEEDS[$feedKey];
$stories = max(1, (int)($feed['stories'] ?? DEFAULT_STORIES));
$dwell   = max(3, (int)($feed['dwell'] ?? DEFAULT_DWELL));

function cached_get(string $url, string $key): ?string
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        $GLOBALS['diag'][$key] = $policy['error'] ?? 'blocked URL';
        return null;
    }
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) return (string)file_get_contents($f);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>4,
        CURLOPT_USERAGENT=>'HomeSignage/1.0 (RSS board)', CURLOPT_ENCODING=>'']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function clean_text(string $html, int $max): string
{
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    // Strip common feed cruft like "The post X appeared first on Y."
    $t = preg_replace('/\s*The post .{0,120} appeared first on .{0,80}\.?$/u', '', $t);
    $t = preg_replace('/\s*Read more\.{0,3}$/iu', '', $t);
    if (mb_strlen($t) > $max) {
        $t = mb_substr($t, 0, $max);
        $cut = mb_strrpos($t, ' ');
        if ($cut !== false && $cut > $max * 0.6) $t = mb_substr($t, 0, $cut);
        $t .= '…';
    }
    return $t;
}

function first_img(string $html): ?string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
        $src = html_entity_decode($m[1]);
        if (str_starts_with($src, 'http')) return $src;
    }
    return null;
}

function item_image(SimpleXMLElement $item): ?string
{
    $media = $item->children('http://search.yahoo.com/mrss/');
    // media:content — pick the largest by width if several
    $best = null; $bestW = -1;
    foreach (['content', 'thumbnail'] as $tag) {
        foreach ($media->{$tag} as $m) {
            $a = $m->attributes();
            $url = (string)($a['url'] ?? '');
            $type = (string)($a['type'] ?? '');
            $medium = (string)($a['medium'] ?? '');
            if ($url === '') continue;
            if ($type !== '' && stripos($type, 'image') === false && $medium !== 'image') continue;
            $w = (int)($a['width'] ?? 0);
            if ($w >= $bestW) { $bestW = $w; $best = $url; }
        }
        if ($best !== null) return $best;
    }
    foreach ($item->enclosure as $e) {
        $a = $e->attributes();
        if (stripos((string)($a['type'] ?? ''), 'image') !== false && (string)($a['url'] ?? '') !== '') {
            return (string)$a['url'];
        }
    }
    $itunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
    if (isset($itunes->image)) {
        $href = (string)$itunes->image->attributes()['href'];
        if ($href !== '') return $href;
    }
    $content = $item->children('http://purl.org/rss/1.0/modules/content/');
    if (isset($content->encoded)) {
        $img = first_img((string)$content->encoded);
        if ($img) return $img;
    }
    return first_img((string)$item->description);
}

// ── Fetch + parse (RSS 2.0 and Atom) ────────────────────────────────────────
$items = [];
$raw = cached_get($feed['url'], 'rss_' . $feedKey);
if ($raw !== null) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NOCDATA);
    if ($xml !== false) {
        if (isset($xml->channel->item)) {                          // RSS 2.0
            foreach ($xml->channel->item as $it) {
                $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                $body = isset($content->encoded) && trim((string)$content->encoded) !== ''
                      ? (string)$content->encoded : (string)$it->description;
                $items[] = [
                    'title'    => clean_text((string)$it->title, 160),
                    'synopsis' => clean_text($body, SYNOPSIS_CHARS),
                    'image'    => item_image($it),
                    'time'     => strtotime((string)$it->pubDate) ?: null,
                ];
            }
        } elseif (isset($xml->entry)) {                            // Atom
            foreach ($xml->entry as $it) {
                $body = trim((string)$it->summary) !== '' ? (string)$it->summary : (string)$it->content;
                $img = null;
                $media = $it->children('http://search.yahoo.com/mrss/');
                if (isset($media->thumbnail)) $img = (string)$media->thumbnail->attributes()['url'];
                if (!$img) $img = first_img((string)$it->content) ?? first_img((string)$it->summary);
                $items[] = [
                    'title'    => clean_text((string)$it->title, 160),
                    'synopsis' => clean_text($body, SYNOPSIS_CHARS),
                    'image'    => $img,
                    'time'     => strtotime((string)($it->updated ?? $it->published)) ?: null,
                ];
            }
        } else {
            $GLOBALS['diag']['parse'] = 'unrecognized feed format';
        }
    } else {
        $GLOBALS['diag']['parse'] = 'XML parse failed';
    }
}
$items = array_values(array_filter($items, fn($i) => $i['title'] !== ''));
$items = array_slice($items, 0, $stories);

$embedded = isset($_GET['noticker']);
$settleMs = max(0, (int)($_GET['settle'] ?? 0));

function ago(?int $ts): string
{
    if ($ts === null) return '';
    $d = time() - $ts;
    if ($d < 3600)   return max(1, (int)round($d / 60)) . 'm ago';
    if ($d < 86400)  return (int)round($d / 3600) . 'h ago';
    return (int)round($d / 86400) . 'd ago';
}
$payload = array_map(fn($i) => [
    'title' => $i['title'], 'synopsis' => $i['synopsis'],
    'image' => $i['image'], 'ago' => ago($i['time']),
], $items);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($feed['name']) ?> — Stories</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }

  .photo { position:absolute; inset:0; background-position:center; background-size:cover;
           opacity:0; transition:opacity 1.6s ease; }
  .photo.show { opacity:1; }
  .photo::after { content:''; position:absolute; inset:0;
    background:linear-gradient(90deg, rgba(12,20,34,.96) 0%, rgba(12,20,34,.86) 34%,
                                       rgba(12,20,34,.45) 62%, rgba(12,20,34,.25) 100%),
               linear-gradient(0deg, rgba(12,20,34,.85) 0%, rgba(12,20,34,0) 38%); }
  @media (prefers-reduced-motion: reduce) { .photo, .text { transition:none !important; } }

  .chrome { position:absolute; top:36px; left:48px; right:48px; z-index:5;
            display:flex; justify-content:space-between; align-items:flex-start; }
  .brand-block { display:flex; flex-direction:column; gap:6px; max-width:70%; }
  .brand { font-family:'Big Shoulders Display'; font-weight:700; font-size:52px; letter-spacing:1px; }
  .brand span { color:var(--beacon); }
  .stamp { font-size:15px; color:var(--mist); opacity:.7; white-space:nowrap;
           overflow:hidden; text-overflow:ellipsis; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:48px; color:var(--mist);
           font-variant-numeric:tabular-nums; text-shadow:0 1px 12px rgba(0,0,0,.6); }

  .text { position:absolute; left:48px; bottom:120px; width:1080px; z-index:5;
          opacity:0; transform:translateY(14px); transition:opacity .9s ease, transform .9s ease; }
  .text.show { opacity:1; transform:none; }
  .meta { font-size:24px; letter-spacing:2px; text-transform:uppercase; color:var(--beacon);
          margin-bottom:18px; font-weight:600; }
  .meta span { color:var(--mist); font-weight:400; letter-spacing:1px; text-transform:none; }
  h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:84px; line-height:1.02;
       text-wrap:balance; text-shadow:0 2px 24px rgba(0,0,0,.55); }
  .syn { font-size:32px; line-height:1.5; color:var(--snow); opacity:.92; margin-top:24px;
         max-width:1000px; text-shadow:0 1px 14px rgba(0,0,0,.6); }

  .dots { position:absolute; left:48px; bottom:52px; z-index:5; display:flex; gap:12px; }
  .dot { width:46px; height:7px; border-radius:4px; background:var(--hairline); overflow:hidden; }
  .dot .p { display:block; height:100%; width:0; background:var(--beacon); }
  .dot.done .p { width:100%; }
  .dot.active .p { animation:fillbar linear forwards; }
  @keyframes fillbar { from { width:0 } to { width:100% } }

  .empty { position:absolute; inset:0; display:flex; flex-direction:column; gap:18px;
           align-items:center; justify-content:center; color:var(--mist); }
  .empty h2 { font-family:'Big Shoulders Display'; font-size:60px; color:var(--snow); }
  .empty p { font-size:28px; }
</style>
</head>
<body>
<div class="photo" id="photoA"></div>
<div class="photo" id="photoB"></div>

<div class="chrome">
  <div class="brand-block">
    <div class="brand"><?= h($feed['name']) ?> <span>&middot; Stories</span></div>
    <div class="stamp"><?= h($feedKey) ?> &middot; <?= count($payload) ?> stories &middot; <?= $dwell ?>s<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
  </div>
  <div id="clock">--:--</div>
</div>

<?php if (!$payload): ?>
  <div class="empty">
    <h2>No stories right now</h2>
    <p>Feed: <?= h($feed['url']) ?><?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?></p>
  </div>
<?php else: ?>
  <div class="text" id="text">
    <div class="meta" id="meta"></div>
    <h1 id="title"></h1>
    <div class="syn" id="syn"></div>
  </div>
  <div class="dots" id="dots"></div>

  <script>
    const STORIES = <?= json_encode($payload, JSON_UNESCAPED_SLASHES) ?>;
    const DWELL   = <?= $dwell ?> * 1000;
    const FEED    = <?= json_encode($feed['name']) ?>;
    const EMBEDDED = <?= json_encode($embedded) ?>;
    const SETTLE  = <?= (int)$settleMs ?>;
    const photos  = [document.getElementById('photoA'), document.getElementById('photoB')];
    const textEl  = document.getElementById('text');
    let idx = -1, front = 0;

    const dots = document.getElementById('dots');
    STORIES.forEach(() => {
      const d = document.createElement('div'); d.className = 'dot';
      d.innerHTML = '<span class="p"></span>'; dots.appendChild(d);
    });

    function preload(src) {
      return new Promise(res => {
        if (!src) return res(false);
        const i = new Image();
        i.onload = () => res(true); i.onerror = () => res(false);
        i.src = src;
      });
    }

    async function show() {
      idx++;
      if (idx >= STORIES.length) {
        if (EMBEDDED) return;
        idx = 0;
      }
      const s = STORIES[idx];
      const ok = await preload(s.image);

      const back = 1 - front;
      photos[back].style.backgroundImage = ok ? "url('" + s.image.replace(/'/g, "%27") + "')" : 'none';
      photos[back].classList.add('show');
      photos[front].classList.remove('show');
      front = back;

      textEl.classList.remove('show');
      setTimeout(() => {
        document.getElementById('meta').innerHTML =
          FEED + (s.ago ? ' &nbsp;<span>' + s.ago + '</span>' : '');
        document.getElementById('title').textContent = s.title;
        document.getElementById('syn').textContent = s.synopsis;
        textEl.classList.add('show');
      }, 350);

      [...dots.children].forEach((d, i) => {
        d.className = 'dot' + (i < idx ? ' done' : '');
        d.firstChild.style.animationDuration = DWELL + 'ms';
        if (i === idx) requestAnimationFrame(() => d.className = 'dot active');
      });

      setTimeout(show, DWELL);
    }
    setTimeout(show, SETTLE);

    function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
      document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
    tick(); setInterval(tick, 1000);

    // Refetch the feed periodically (Anthias also reloads per cycle)
    setTimeout(() => location.reload(), 15 * 60 * 1000);
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
