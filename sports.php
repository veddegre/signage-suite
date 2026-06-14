<?php
/**
 * DETROIT SPORTS — 1920×1080 signage
 * Lions, Tigers, Pistons, and Red Wings on one board.
 *
 * Data: ESPN public site API (no key). Server-side fetch + cache.
 * Season logic uses calendar windows plus nearby games (live / next 3 weeks).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sports_lib.php';

define('TITLE', cfg('sports.TITLE', 'Detroit Sports'));
define('SUBTITLE', cfg('sports.SUBTITLE', 'Lions · Tigers · Pistons · Red Wings'));
define('TIMEZONE', cfg('sports.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('sports.RELOAD_SEC', 120));
const SPORTS_CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('sports.CACHE_TTL', 300));

date_default_timezone_set(TIMEZONE);
$tz = new DateTimeZone(TIMEZONE);
$GLOBALS['diag'] = [];

$teams = sports_default_teams();
$baseTtl = max(60, (int)CACHE_TTL);

// Scoreboards — one per league (shared across teams).
$scoreboardsByLeague = [];
foreach (['nfl', 'mlb', 'nba', 'nhl'] as $lg) {
    $sport = match ($lg) {
        'nfl' => 'football',
        'mlb' => 'baseball',
        default => $lg === 'nba' ? 'basketball' : 'hockey',
    };
    $scoreboardsByLeague[$lg] = sports_scoreboard_by_team($sport, $lg, min($baseTtl, 90));
}

$cards = [];
$anyLive = false;
foreach ($teams as $teamCfg) {
    $card = sports_build_team_card($teamCfg, $scoreboardsByLeague, $baseTtl, $tz);
    if (($card['mode'] ?? '') === 'live') {
        $anyLive = true;
    }
    $cards[] = $card;
}

[$bannerTitle, $bannerSub, $bannerColor] = sports_board_summary($cards);
$hasData = $cards !== [];

$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = $embedded
    ? $boardH . 'px'
    : 'calc(1080px - var(--signage-ticker-inset, 0px))';
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowCards = max(520, (int)round(640 * $boardH / 1080));
$reloadSec = $anyLive ? max(45, (int)RELOAD_SEC) : max(300, (int)RELOAD_SEC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<?php if ($anyLive): ?>
<meta http-equiv="refresh" content="<?= (int)$reloadSec ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --up:#39c46d; --down:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-columns:1fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowCards ?>px auto auto;
           grid-template-areas:
             "head head"
             "cards cards"
             "banner banner"
             "meta meta"; min-height:0; }

  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .cards { grid-area:cards; display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
           gap:<?= $boardH < 1080 ? 14 : 18 ?>px; min-height:0; }
  .card { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:<?= $boardH < 1080 ? '18px 22px' : '22px 28px' ?>; min-height:0;
          display:flex; flex-direction:column; overflow:hidden; border-top:4px solid var(--accent, var(--beacon)); }
  .card.live { border-top-color:var(--down); box-shadow:0 0 0 1px rgba(255,93,93,.25); }
  .card-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; }
  .card-top .team { font-family:'Big Shoulders Display'; font-weight:700;
                    font-size:<?= $boardH < 1080 ? 34 : 40 ?>px; letter-spacing:.5px; }
  .card-top .meta { display:flex; align-items:center; gap:10px; flex-shrink:0; }
  .pill { font-size:13px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
          border:1px solid var(--hairline); border-radius:999px; padding:4px 10px; }
  .badge { font-size:13px; letter-spacing:1.5px; text-transform:uppercase; font-weight:600;
           border-radius:999px; padding:5px 12px; background:var(--lake-night); color:var(--mist); }
  .badge.live { background:rgba(255,93,93,.18); color:var(--down); animation:pulse 1.6s ease-in-out infinite; }
  .badge.next { background:rgba(255,179,71,.12); color:var(--beacon); }
  .badge.off { opacity:.85; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }

  .headline { font-family:'Big Shoulders Display'; font-weight:700;
              font-size:<?= $boardH < 1080 ? 56 : 68 ?>px; line-height:1.05; margin:8px 0 6px;
              font-variant-numeric:tabular-nums; flex:1; display:flex; align-items:center; }
  .card.live .headline { color:var(--snow); }
  .card.final .headline { color:var(--snow); }
  .card.offseason .headline { color:var(--mist); font-size:<?= $boardH < 1080 ? 44 : 52 ?>px; }
  .detail { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); line-height:1.35;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .banner { grid-area:banner; border-radius:14px; border:1px solid var(--hairline);
            padding:<?= $boardH < 1080 ? '16px 22px' : '20px 28px' ?>; display:flex;
            align-items:baseline; justify-content:space-between; gap:24px;
            background:linear-gradient(90deg, rgba(20,31,51,.95), rgba(12,20,34,.95)); min-height:0; }
  .banner .t { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 36 : 44 ?>px;
               letter-spacing:1px; color:<?= h($bannerColor) ?>; }
  .banner .s { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); text-align:right; }

  .empty { grid-area:cards; display:flex; align-items:center; justify-content:center;
           background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           font-size:24px; color:var(--mist); }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?></span></h1>
    <div id="clock">--:--</div>
  </div>

  <?php if ($hasData): ?>
  <div class="cards">
    <?php foreach ($cards as $c):
      $mode = (string)($c['mode'] ?? 'off');
      $badge = (string)($c['badge'] ?? '');
      $badgeClass = match ($badge) {
          'Live' => 'live',
          'Up next' => 'next',
          'Off season' => 'off',
          default => '',
      };
    ?>
    <article class="card <?= h($mode) ?><?= $badge === 'Off season' ? ' offseason' : '' ?>"
             style="--accent:<?= h($c['accent'] ?? '#ffb347') ?>">
      <div class="card-top">
        <div class="team"><?= h($c['name']) ?></div>
        <div class="meta">
          <span class="pill"><?= h($c['league']) ?></span>
          <span class="badge <?= h($badgeClass) ?>"><?= h($badge) ?></span>
        </div>
      </div>
      <div class="headline"><?= h($c['headline']) ?></div>
      <div class="detail"><?= h($c['detail']) ?></div>
    </article>
    <?php endforeach; ?>
  </div>

  <div class="banner">
    <div class="t"><?= h($bannerTitle) ?></div>
    <div class="s"><?= h($bannerSub) ?></div>
  </div>
  <?php else: ?>
  <div class="empty">Sports data unavailable — check network or try again shortly.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'ESPN',
    $anyLive ? 'Live refresh ' . (int)$reloadSec . 's' : '',
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
  ]))) ?></div>
</div>
<script>
(function(){
  const tz = <?= json_encode(TIMEZONE) ?>;
  function tick(){
    const el = document.getElementById('clock');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', timeZone: tz });
  }
  tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
