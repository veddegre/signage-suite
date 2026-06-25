<?php
/**
 * WORD OF THE DAY — 1920×1080 signage
 *
 * Data: Wordsmith.org A.Word.A.Day RSS + Free Dictionary API (no keys).
 * https://wordsmith.org/awad/rss1.xml
 * https://dictionaryapi.dev/
 */

require_once __DIR__ . '/config.php';

define('TITLE', cfg('wotd.TITLE', 'Word of the Day'));
define('SUBTITLE', cfg('wotd.SUBTITLE', 'A.Word.A.Day · Wordsmith.org'));
define('TIMEZONE', cfg('wotd.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('wotd.RELOAD_SEC', 3600));
const CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('wotd.CACHE_TTL', 86400));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function wotd_cached(string $url, string $key, string $diagKey): ?string
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . '/' . $key . '.dat';
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        return (string)file_get_contents($f);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'HomeSignage/WOTD/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200 && $body !== '') {
        @file_put_contents($f, $body, LOCK_EX);
        return $body;
    }
    $GLOBALS['diag'][$diagKey] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;
}

/** @return array{word:string,pos:string,blurb:string,link:string}|null */
function wotd_parse_rss(string $raw): ?array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false || !isset($xml->channel->item[0])) {
        return null;
    }
    $item = $xml->channel->item[0];
    $word = trim((string)$item->title);
    $desc = trim(strip_tags((string)$item->description));
    $link = trim((string)$item->link);
    if ($word === '') {
        return null;
    }
    $pos = '';
    $blurb = $desc;
    if (preg_match('/^([a-z]+(?:\s+[a-z]+)?):\s*(.+)$/i', $desc, $m)) {
        $pos = trim($m[1]);
        $blurb = trim($m[2]);
    }
    return ['word' => $word, 'pos' => $pos, 'blurb' => $blurb, 'link' => $link];
}

/** @return list<array{pos:string,def:string,example:?string}> */
function wotd_parse_dictionary(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data[0]['meanings'])) {
        return [];
    }
    $out = [];
    foreach ($data[0]['meanings'] as $meaning) {
        $pos = (string)($meaning['partOfSpeech'] ?? '');
        foreach ($meaning['definitions'] ?? [] as $def) {
            if (!is_array($def)) {
                continue;
            }
            $text = trim((string)($def['definition'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'pos' => $pos,
                'def' => $text,
                'example' => isset($def['example']) ? trim((string)$def['example']) : null,
            ];
            if (count($out) >= 4) {
                return $out;
            }
        }
    }
    return $out;
}

$dayKey = date('Y-m-d');
$rssRaw = wotd_cached('https://wordsmith.org/awad/rss1.xml', 'wotd_rss_' . $dayKey, 'wordsmith');
$wotd = $rssRaw ? wotd_parse_rss($rssRaw) : null;

$phonetic = null;
$definitions = [];
if ($wotd) {
    $dictRaw = wotd_cached(
        'https://api.dictionaryapi.dev/api/v2/entries/en/' . rawurlencode(strtolower($wotd['word'])),
        'wotd_dict_' . $dayKey . '_' . md5(strtolower($wotd['word'])),
        'dictionary'
    );
    if ($dictRaw) {
        $definitions = wotd_parse_dictionary($dictRaw);
        $decoded = json_decode($dictRaw, true);
        if (is_array($decoded) && isset($decoded[0]['phonetic']) && $decoded[0]['phonetic'] !== '') {
            $phonetic = (string)$decoded[0]['phonetic'];
        } elseif (is_array($decoded) && !empty($decoded[0]['phonetics'][0]['text'])) {
            $phonetic = (string)$decoded[0]['phonetics'][0]['text'];
        }
    }
}

$hasData = $wotd !== null;
$primaryPos = $wotd['pos'] ?? '';
if ($primaryPos === '' && $definitions !== []) {
    $primaryPos = $definitions[0]['pos'];
}

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
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --seafoam:#6ee7c8; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-rows: <?= $rowHead ?>px 1fr auto;
           grid-template-areas: "head" "main" "meta"; min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .main { grid-area:main; display:grid; grid-template-columns: 1.05fr 0.95fr; gap:<?= $boardH < 1080 ? 16 : 20 ?>px; min-height:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '24px 28px' : '32px 36px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:14px; }

  .word-panel { display:flex; flex-direction:column; justify-content:center; }
  .word { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 112 : 136 ?>px;
          line-height:1.05; color:var(--seafoam); letter-spacing:1px; word-break:break-word; }
  .phonetic { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 28 : 34 ?>px; color:var(--mist);
              margin-top:12px; font-style:italic; }
  .pos { display:inline-block; margin-top:18px; padding:8px 16px; border-radius:999px;
         background:var(--lake-night); border:1px solid var(--hairline);
         font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; letter-spacing:2px; text-transform:uppercase; color:var(--beacon); }
  .blurb { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 28 : 34 ?>px; line-height:1.45;
           color:var(--snow); margin-top:22px; max-width:920px; }

  .defs { display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 16 ?>px; min-height:0; overflow:hidden; }
  .def { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
         padding:<?= $boardH < 1080 ? '16px 18px' : '18px 22px' ?>; }
  .def .pos { margin:0 0 8px 0; padding:0; border:0; background:none; font-size:15px; color:var(--mist); }
  .def .text { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; line-height:1.45; }
  .def .ex { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); margin-top:10px; font-style:italic; line-height:1.4; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
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
  <div class="main">
    <section class="panel word-panel">
      <div class="k"><?= h(date('l, F j')) ?></div>
      <div class="word"><?= h($wotd['word']) ?></div>
      <?php if ($phonetic): ?><div class="phonetic"><?= h($phonetic) ?></div><?php endif; ?>
      <?php if ($primaryPos !== ''): ?><div class="pos"><?= h($primaryPos) ?></div><?php endif; ?>
      <?php if ($wotd['blurb'] !== ''): ?><div class="blurb"><?= h($wotd['blurb']) ?></div><?php endif; ?>
    </section>

    <section class="panel">
      <div class="k">Dictionary</div>
      <div class="defs">
      <?php if ($definitions !== []): ?>
        <?php foreach ($definitions as $d): ?>
        <div class="def">
          <?php if ($d['pos'] !== ''): ?><div class="pos"><?= h($d['pos']) ?></div><?php endif; ?>
          <div class="text"><?= h($d['def']) ?></div>
          <?php if ($d['example']): ?><div class="ex">“<?= h($d['example']) ?>”</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="def"><div class="text"><?= h($wotd['blurb']) ?></div></div>
      <?php endif; ?>
      </div>
    </section>
  </div>
  <?php else: ?>
  <section class="panel main" style="grid-column:1/-1">
    <div class="notcfg">Word of the day unavailable<?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?>.
      Check network access to <code>wordsmith.org</code> and <code>api.dictionaryapi.dev</code>.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'Wordsmith.org · Free Dictionary API',
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
