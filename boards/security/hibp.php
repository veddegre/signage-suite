<?php
/**
 * HAVE I BEEN PWNED — latest breaches, 1920×1080 signage
 *
 * Data: HIBP API v3 (User-Agent required; breaches feed needs no API key).
 * https://haveibeenpwned.com/API/v3#Breaches
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('hibp.TITLE', 'Data Breaches'));
define('SUBTITLE', cfg('hibp.SUBTITLE', 'Have I Been Pwned'));
define('TIMEZONE', cfg('hibp.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('hibp.RELOAD_SEC', 0));
define('MAX_ITEMS', max(4, min(12, (int)cfg('hibp.MAX_ITEMS', 8))));
define('USER_AGENT', cfg('hibp.USER_AGENT', 'HomeSignage/HIBPBoard/1.0 (signage-suite)'));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('hibp.CACHE_TTL', 3600));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hibp_plain(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function hibp_logo_url(?string $path): ?string
{
    $path = trim((string)$path);
    if ($path === '') {
        return null;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return 'https://haveibeenpwned.com' . $path;
}

/** @return list<array<string,mixed>>|null */
function hibp_fetch_breaches(): ?array
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $f = CACHE_DIR . '/hibp_breaches.json';
    if (CACHE_TTL > 0 && is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            return $d;
        }
    }

    $ch = curl_init('https://haveibeenpwned.com/api/v3/breaches');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['User-Agent: ' . USER_AGENT],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d)) {
            @file_put_contents($f, $body, LOCK_EX);
            return $d;
        }
    }
    $GLOBALS['diag']['hibp'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/** @return array{name:string,title:string,domain:string,breach_date:string,added_date:string,pwn_count:int,description:string,logo:?string,classes:string,url:string}|null */
function hibp_normalize_breach(?array $b): ?array
{
    if (!$b || empty($b['Name'])) {
        return null;
    }
    if (!empty($b['IsFabricated'])) {
        return null;
    }
    $classes = [];
    foreach ($b['DataClasses'] ?? [] as $c) {
        if (is_string($c) && $c !== '') {
            $classes[] = $c;
        }
    }
    $name = (string)$b['Name'];
    return [
        'name' => $name,
        'title' => trim((string)($b['Title'] ?? $name)),
        'domain' => trim((string)($b['Domain'] ?? '')),
        'breach_date' => trim((string)($b['BreachDate'] ?? '')),
        'added_date' => trim((string)($b['AddedDate'] ?? '')),
        'pwn_count' => max(0, (int)($b['PwnCount'] ?? 0)),
        'description' => hibp_plain((string)($b['Description'] ?? '')),
        'logo' => hibp_logo_url($b['LogoPath'] ?? null),
        'classes' => implode(' · ', array_slice($classes, 0, 6)),
        'url' => 'https://haveibeenpwned.com/Pwned/' . rawurlencode($name),
    ];
}

/** @param list<array<string,mixed>> $raw */
function hibp_recent_breaches(array $raw, int $limit): array
{
    usort($raw, static function ($a, $b) {
        return strcmp((string)($b['AddedDate'] ?? ''), (string)($a['AddedDate'] ?? ''));
    });
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $norm = hibp_normalize_breach($item);
        if ($norm !== null) {
            $out[] = $norm;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function hibp_format_count(int $n): string
{
    if ($n >= 1_000_000_000) {
        return round($n / 1_000_000_000, 1) . 'B';
    }
    if ($n >= 1_000_000) {
        return round($n / 1_000_000, 1) . 'M';
    }
    if ($n >= 10_000) {
        return round($n / 1_000, 0) . 'K';
    }
    return number_format($n);
}

function hibp_format_date(string $iso): string
{
    if ($iso === '') {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('M j, Y', $t) : $iso;
}

$raw = hibp_fetch_breaches();
$breaches = $raw ? hibp_recent_breaches($raw, MAX_ITEMS) : [];
$hero = $breaches[0] ?? null;
$list = array_slice($breaches, 1);
$hasData = $hero !== null;
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
           grid-template-areas: "head head" "hero side" "meta meta";
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

  .hero { grid-area:hero; display:grid; grid-template-columns: <?= $hero && $hero['logo'] ? '200px 1fr' : '1fr' ?>;
          gap:<?= $boardH < 1080 ? 18 : 24 ?>px; align-items:start; min-height:0; }
  .hero-logo { width:<?= $boardH < 1080 ? 180 : 200 ?>px; height:<?= $boardH < 1080 ? 180 : 200 ?>px;
               border-radius:12px; background:var(--lake-night); border:1px solid var(--hairline);
               display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .hero-logo img { max-width:88%; max-height:88%; object-fit:contain; }
  .hero-body { min-height:0; }
  .hero-title { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 48 : 56 ?>px;
                  line-height:1.1; margin-bottom:10px; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px 18px; margin-bottom:14px; }
  .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:999px;
          border:1px solid var(--hairline); background:var(--lake-night); font-size:<?= $boardH < 1080 ? 17 : 19 ?>px;
          color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; }
  .pill.alert strong { color:var(--alert); }
  .hero-desc { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; line-height:1.42;
               color:var(--snow); max-height:<?= $boardH < 1080 ? 200 : 240 ?>px; overflow:hidden; }
  .hero-classes { margin-top:14px; font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; color:var(--mist); line-height:1.4; }

  .side { grid-area:side; display:flex; flex-direction:column; min-height:0; }
  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row .name { font-size:<?= $boardH < 1080 ? 20 : 22 ?>px; font-weight:600; white-space:nowrap;
               overflow:hidden; text-overflow:ellipsis; }
  .row .sub { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; color:var(--mist); margin-top:3px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .cnt { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 28 : 32 ?>px;
              color:var(--alert); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-column:1/-1; }
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
  <section class="panel hero">
    <?php if ($hero['logo']): ?>
    <div class="hero-logo"><img src="<?= h($hero['logo']) ?>" alt=""></div>
    <?php endif; ?>
    <div class="hero-body">
      <div class="k">Latest breach</div>
      <div class="hero-title"><?= h($hero['title']) ?></div>
        <div class="hero-meta">
          <?php if ($hero['domain'] !== ''): ?>
          <span class="pill"><strong><?= h($hero['domain']) ?></strong></span>
          <?php endif; ?>
          <span class="pill alert"><strong><?= h(hibp_format_count($hero['pwn_count'])) ?></strong> accounts</span>
          <?php if ($hero['breach_date'] !== ''): ?>
          <span class="pill">Breach <strong><?= h(hibp_format_date($hero['breach_date'])) ?></strong></span>
          <?php endif; ?>
          <?php if ($hero['added_date'] !== ''): ?>
          <span class="pill">Added <strong><?= h(hibp_format_date($hero['added_date'])) ?></strong></span>
          <?php endif; ?>
        </div>
        <?php if ($hero['description'] !== ''): ?>
        <div class="hero-desc"><?= h($hero['description']) ?></div>
        <?php endif; ?>
        <?php if ($hero['classes'] !== ''): ?>
        <div class="hero-classes">Exposed: <?= h($hero['classes']) ?></div>
        <?php endif; ?>
    </div>
  </section>

  <section class="panel side">
    <div class="k">Recent breaches</div>
    <div class="list">
      <?php foreach ($list as $b): ?>
      <div class="row">
        <div>
          <div class="name"><?= h($b['title']) ?></div>
          <div class="sub"><?= h($b['domain'] !== '' ? $b['domain'] : hibp_format_date($b['added_date'])) ?></div>
        </div>
        <div class="cnt"><?= h(hibp_format_count($b['pwn_count'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php else: ?>
  <div class="notcfg">Breach feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['hibp'] ?? '') : '' ?>.
    Check network access to <code>haveibeenpwned.com</code> and the User-Agent in admin.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'haveibeenpwned.com',
    $hero ? $hero['name'] : '',
    count($breaches) . ' recent',
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
