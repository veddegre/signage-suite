<?php
/**
 * WORD OF THE DAY — 1920×1080 signage
 *
 * Data: Wordsmith.org A.Word.A.Day (RSS + word page) + Free Dictionary API fallback.
 * https://wordsmith.org/awad/rss1.xml
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('wotd.TITLE', 'Word of the Day'));
define('SUBTITLE', cfg('wotd.SUBTITLE', 'A.Word.A.Day · Wordsmith.org'));
define('TIMEZONE', cfg('wotd.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('wotd.RELOAD_SEC', 3600));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
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
        CURLOPT_USERAGENT => 'HomeSignage/WOTD/1.0 (signage-suite)',
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

function wotd_plain(string $html): string
{
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
}

function wotd_similar(string $a, string $b): bool
{
    $a = strtolower(preg_replace('/[^a-z0-9\s]/', '', $a) ?? $a);
    $b = strtolower(preg_replace('/[^a-z0-9\s]/', '', $b) ?? $b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return true;
    }
    similar_text($a, $b, $pct);
    return $pct >= 82;
}

/** @return array{word:string,link:string}|null */
function wotd_parse_rss(string $raw): ?array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml === false || !isset($xml->channel->item[0])) {
        return null;
    }
    $item = $xml->channel->item[0];
    $word = trim((string)$item->title);
    $link = trim((string)$item->link);
    if ($word === '') {
        return null;
    }
    return ['word' => $word, 'link' => $link];
}

/** @return array<string,string> */
function wotd_parse_word_page(string $html): array
{
    $out = [
        'pronunciation' => '',
        'meaning' => '',
        'pos' => '',
        'definition' => '',
        'etymology' => '',
        'usage' => '',
        'thought' => '',
    ];
    $parts = preg_split(
        '/<div style="font-family:Verdana; color:#555555; font-size:13px;">([A-Z][^<]+):<\/div>/',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if (!is_array($parts)) {
        return $out;
    }
    $map = [
        'PRONUNCIATION' => 'pronunciation',
        'MEANING' => 'meaning',
        'ETYMOLOGY' => 'etymology',
        'USAGE' => 'usage',
        'A THOUGHT FOR TODAY' => 'thought',
    ];
    for ($i = 1; $i + 1 < count($parts); $i += 2) {
        $label = trim($parts[$i]);
        $key = $map[$label] ?? null;
        if ($key === null) {
            continue;
        }
        if (preg_match('/<div[^>]*>(.*?)<\/div>/s', $parts[$i + 1], $m)) {
            $out[$key] = wotd_plain($m[1]);
        }
    }
    if ($out['meaning'] !== '' && preg_match('/^([a-z]+(?:\s+[a-z]+)?)\s*:\s*(.+)$/i', $out['meaning'], $m)) {
        $out['pos'] = trim($m[1]);
        $out['definition'] = trim($m[2]);
    } elseif ($out['meaning'] !== '') {
        $out['definition'] = $out['meaning'];
    }
    return $out;
}

/** @return list<array{pos:string,def:string,example:?string}> */
function wotd_dictionary_defs(string $raw, int $limit = 3): array
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
            if (count($out) >= $limit) {
                return $out;
            }
        }
    }
    return $out;
}

function wotd_dictionary_phonetic(string $raw): ?string
{
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data[0])) {
        return null;
    }
    if (!empty($data[0]['phonetic'])) {
        return (string)$data[0]['phonetic'];
    }
    foreach ($data[0]['phonetics'] ?? [] as $p) {
        if (!empty($p['text'])) {
            return (string)$p['text'];
        }
    }
    return null;
}

/** Split usage into quote + citation when possible. @return array{0:string,1:string} */
function wotd_split_usage(string $usage): array
{
    $usage = trim($usage);
    if ($usage === '') {
        return ['', ''];
    }
    if (preg_match('/^[\x{201C}\x{201D}"\x{0022}](.+?)[\x{201C}\x{201D}"\x{0022}]\s*(.+)$/u', $usage, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [$usage, ''];
}

$dayKey = date('Y-m-d');
$rssRaw = wotd_cached('https://wordsmith.org/awad/rss1.xml', 'wotd_rss_' . $dayKey, 'wordsmith');
$rss = $rssRaw ? wotd_parse_rss($rssRaw) : null;

$page = [];
$phonetic = null;
$extraExample = null;
$extraDefs = [];

if ($rss) {
    if ($rss['link'] !== '') {
        $pageRaw = wotd_cached($rss['link'], 'wotd_page_' . $dayKey . '_' . md5($rss['link']), 'wordsmith_page');
        if ($pageRaw) {
            $page = wotd_parse_word_page($pageRaw);
        }
    }

    $dictRaw = wotd_cached(
        'https://api.dictionaryapi.dev/api/v2/entries/en/' . rawurlencode(strtolower($rss['word'])),
        'wotd_dict_' . $dayKey . '_' . md5(strtolower($rss['word'])),
        'dictionary'
    );
    if ($dictRaw) {
        if ($page['pronunciation'] === '') {
            $phonetic = wotd_dictionary_phonetic($dictRaw);
        }
        $primaryDef = $page['definition'] ?? '';
        foreach (wotd_dictionary_defs($dictRaw, 4) as $d) {
            if ($primaryDef !== '' && wotd_similar($d['def'], $primaryDef)) {
                if ($extraExample === null && !empty($d['example']) && ($page['usage'] ?? '') === '') {
                    $extraExample = $d['example'];
                }
                continue;
            }
            $extraDefs[] = $d;
            if (count($extraDefs) >= 2) {
                break;
            }
        }
        if ($extraExample === null && ($page['usage'] ?? '') === '') {
            foreach (wotd_dictionary_defs($dictRaw, 1) as $d) {
                if (!empty($d['example'])) {
                    $extraExample = $d['example'];
                    break;
                }
            }
        }
    }
}

$word = $rss['word'] ?? '';
$pos = $page['pos'] ?? '';
$definition = $page['definition'] ?? '';
$pronunciation = $page['pronunciation'] ?: ($phonetic ?? '');
$etymology = $page['etymology'] ?? '';
[$usageQuote, $usageCite] = wotd_split_usage($page['usage'] ?? '');
$thought = $page['thought'] ?? '';
if ($usageQuote === '' && $extraExample !== null) {
    $usageQuote = $extraExample;
    $usageCite = 'Dictionary example';
}

$hasData = $rss !== null && $word !== '';
$hasMeaning = $definition !== '';
$hasEtymology = $etymology !== '';
$hasUsage = $usageQuote !== '';
$hasThought = $thought !== '';
$hasExtras = $extraDefs !== [];

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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --seafoam:#6ee7c8; --lilac:#c4a8ff; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 14 : 18 ?>px;
           grid-template-rows: <?= $rowHead ?>px auto 1fr auto;
           grid-template-areas:
             "head"
             "hero"
             "body"
             "meta";
           min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 50 : 58 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .date { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); margin-left:14px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .hero { grid-area:hero; display:flex; align-items:flex-end; justify-content:space-between; gap:24px;
          padding:<?= $boardH < 1080 ? '8px 4px 0' : '12px 8px 0' ?>; border-bottom:1px solid var(--hairline); }
  .hero-left { min-width:0; flex:1; }
  .word { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 118 : 148 ?>px;
          line-height:0.95; color:var(--seafoam); letter-spacing:2px; text-transform:lowercase; }
  .word::first-letter { text-transform:uppercase; }
  .meta-row { display:flex; flex-wrap:wrap; align-items:center; gap:14px; margin-top:10px; }
  .phonetic { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 26 : 32 ?>px; color:var(--mist); font-style:italic; }
  .pos { padding:6px 14px; border-radius:999px; background:var(--harbor); border:1px solid var(--hairline);
         font-size:16px; letter-spacing:2px; text-transform:uppercase; color:var(--beacon); font-weight:600; }

  .body { grid-area:body; display:grid; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; min-height:0;
          grid-template-columns: <?= ($hasEtymology || $hasExtras) ? '1.15fr 0.85fr' : '1fr' ?>;
          grid-template-rows: <?= ($hasUsage || $hasThought) ? '1fr auto' : '1fr' ?>; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '22px 26px' : '28px 34px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:16px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:12px; }

  .meaning { display:flex; flex-direction:column; justify-content:center; position:relative; }
  .meaning::before { content:'“'; position:absolute; top:<?= $boardH < 1080 ? '6px' : '10px' ?>; left:<?= $boardH < 1080 ? '18px' : '24px' ?>;
                     font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 100 : 120 ?>px; line-height:1;
                     color:rgba(255,179,71,.15); pointer-events:none; }
  .def-text { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 38 : 46 ?>px; line-height:1.42;
              color:var(--snow); padding-left:<?= $boardH < 1080 ? '36px' : '44px' ?>; max-width:1100px; }

  .side { display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 12 : 14 ?>px; min-height:0; }
  .side-block { background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px;
                padding:<?= $boardH < 1080 ? '16px 18px' : '18px 22px' ?>; flex:1; min-height:0; overflow:hidden; }
  .side-block .k { margin-bottom:8px; font-size:14px; }
  .etym { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; line-height:1.5; color:var(--snow); }
  .alt-def { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; line-height:1.45; color:var(--mist); padding-top:10px;
             border-top:1px solid var(--hairline); margin-top:10px; }
  .alt-def:first-of-type { border-top:none; margin-top:0; padding-top:0; }
  .alt-def .pos { display:inline; padding:0; border:0; background:none; font-size:13px; color:var(--beacon); margin-right:8px; }

  .usage-row { grid-column:1 / -1; display:grid; grid-template-columns: <?= $hasThought ? '1.2fr 0.8fr' : '1fr' ?>; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; }
  .quote { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 26 : 30 ?>px; line-height:1.45;
           font-style:italic; color:var(--snow); }
  .cite { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; color:var(--mist); margin-top:12px; line-height:1.4; }
  .thought { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; line-height:1.45; color:var(--lilac); }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="date"><?= h(date('l, F j')) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <header class="hero">
    <div class="hero-left">
      <div class="word"><?= h($word) ?></div>
      <div class="meta-row">
        <?php if ($pronunciation !== ''): ?><span class="phonetic"><?= h($pronunciation) ?></span><?php endif; ?>
        <?php if ($pos !== ''): ?><span class="pos"><?= h($pos) ?></span><?php endif; ?>
      </div>
    </div>
  </header>

  <div class="body">
    <?php if ($hasMeaning): ?>
    <section class="panel meaning">
      <div class="k">Definition</div>
      <div class="def-text"><?= h($definition) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($hasEtymology || $hasExtras): ?>
    <aside class="panel side">
      <?php if ($hasEtymology): ?>
      <div class="side-block">
        <div class="k">Etymology</div>
        <div class="etym"><?= h($etymology) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($hasExtras): ?>
      <div class="side-block">
        <div class="k">Also means</div>
        <?php foreach ($extraDefs as $d): ?>
        <div class="alt-def">
          <?php if ($d['pos'] !== ''): ?><span class="pos"><?= h($d['pos']) ?></span><?php endif; ?>
          <?= h($d['def']) ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </aside>
    <?php endif; ?>

    <?php if ($hasUsage || $hasThought): ?>
    <div class="usage-row">
      <?php if ($hasUsage): ?>
      <section class="panel">
        <div class="k">In use</div>
        <div class="quote">“<?= h($usageQuote) ?>”</div>
        <?php if ($usageCite !== ''): ?><div class="cite"><?= h($usageCite) ?></div><?php endif; ?>
      </section>
      <?php endif; ?>
      <?php if ($hasThought): ?>
      <section class="panel">
        <div class="k">A thought for today</div>
        <div class="thought"><?= h($thought) ?></div>
      </section>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <section class="panel body" style="grid-column:1/-1">
    <div class="notcfg">Word of the day unavailable<?= $GLOBALS['diag'] ? ' — ' . h(implode('; ', $GLOBALS['diag'])) : '' ?>.
      Check network access to <code>wordsmith.org</code>.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    SUBTITLE,
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
