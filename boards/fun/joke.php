<?php
/**
 * DAD JOKES — 1920×1080 signage
 *
 * Data: icanhazdadjoke.com (free, no key — custom User-Agent required).
 * https://icanhazdadjoke.com/api
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('joke.TITLE', 'Dad Joke'));
define('SUBTITLE', cfg('joke.SUBTITLE', 'icanhazdadjoke.com'));
define('TIMEZONE', cfg('joke.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('joke.RELOAD_SEC', 0));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('joke.CACHE_TTL', 90));
define('USER_AGENT', cfg('joke.USER_AGENT', 'HomeSignage/JokeBoard/1.0 (signage-suite)'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** @return array{setup:string,punchline:string,split:bool} */
function joke_split(string $text): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return ['setup' => '', 'punchline' => '', 'split' => false];
    }
    // "Why did the chicken...? To get to the other side."
    if (preg_match('/^(.+\?)\s+(.+)$/u', $text, $m)) {
        return ['setup' => trim($m[1]), 'punchline' => trim($m[2]), 'split' => true];
    }
    // "Setup sentence. Punchline sentence."
    if (preg_match('/^(.+\.)\s+([A-Z0-9"\'].+)$/u', $text, $m)) {
        return ['setup' => trim($m[1]), 'punchline' => trim($m[2]), 'split' => true];
    }
    return ['setup' => '', 'punchline' => $text, 'split' => false];
}

/** @return array{id:string,joke:string}|null */
function joke_fetch_random(): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $slot = (int)floor(time() / max(1, CACHE_TTL));
    $f = CACHE_DIR . '/dadjoke_' . $slot . '.json';

    if (CACHE_TTL > 0 && is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['joke'])) {
            return $d;
        }
    }

    $ch = curl_init('https://icanhazdadjoke.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: ' . USER_AGENT,
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d) && !empty($d['joke'])) {
            $out = [
                'id' => (string)($d['id'] ?? ''),
                'joke' => trim((string)$d['joke']),
            ];
            if (CACHE_TTL > 0) {
                @file_put_contents($f, json_encode($out), LOCK_EX);
            }
            return $out;
        }
    }
    $GLOBALS['diag']['icanhazdadjoke'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['joke'])) {
            return $d;
        }
    }
    return null;
}

$joke = joke_fetch_random();
$parts = $joke ? joke_split($joke['joke']) : ['setup' => '', 'punchline' => '', 'split' => false];
$hasData = $joke !== null;
$permalink = ($joke && $joke['id'] !== '')
    ? 'https://icanhazdadjoke.com/j/' . rawurlencode($joke['id'])
    : 'https://icanhazdadjoke.com/';

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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --gold:#ffd089; }
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

  .main { grid-area:main; display:flex; align-items:center; justify-content:center; min-height:0; }
  .card { width:100%; max-width:1680px; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:20px; padding:<?= $boardH < 1080 ? '48px 56px' : '64px 72px' ?>;
          position:relative; overflow:hidden; }
  .card::before { content:'“'; position:absolute; top:<?= $boardH < 1080 ? '8px' : '12px' ?>; left:<?= $boardH < 1080 ? '28px' : '36px' ?>;
                  font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 120 : 148 ?>px; line-height:1;
                  color:rgba(255,179,71,.18); pointer-events:none; }
  .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:24px; }
  .joke { max-width:1520px; }
  .joke-setup,
  .joke-punch,
  .joke-single { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 52 : 64 ?>px; line-height:1.35;
                 color:var(--snow); font-weight:500; }
  .joke-break { height:<?= $boardH < 1080 ? 28 : 36 ?>px; max-width:480px;
                border-bottom:2px solid rgba(255,179,71,.35); margin-bottom:<?= $boardH < 1080 ? 28 : 36 ?>px; }
  .badge { display:inline-block; margin-top:28px; padding:10px 18px; border-radius:999px;
           background:var(--lake-night); border:1px solid var(--hairline);
           font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; letter-spacing:2px; text-transform:uppercase; color:var(--gold); }

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
    <div class="card">
      <div class="k">Fresh from the API</div>
      <div class="joke">
      <?php if ($parts['split']): ?>
        <div class="joke-setup"><?= h($parts['setup']) ?></div>
        <div class="joke-break" aria-hidden="true"></div>
        <div class="joke-punch"><?= h($parts['punchline']) ?></div>
      <?php else: ?>
        <div class="joke-single"><?= h($parts['punchline']) ?></div>
      <?php endif; ?>
      </div>
      <div class="badge">Dad joke</div>
    </div>
  </section>
  <?php else: ?>
  <section class="main">
    <div class="notcfg">Joke feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['icanhazdadjoke'] ?? '') : '' ?>.
      Check network access to <code>icanhazdadjoke.com</code>.</div>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'icanhazdadjoke.com',
    $joke && $joke['id'] !== '' ? 'j/' . $joke['id'] : '',
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
