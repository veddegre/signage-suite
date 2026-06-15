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
$showClock = signage_show_clock();
$tz = new DateTimeZone(TIMEZONE);
$GLOBALS['diag'] = [];

$teams = sports_default_teams();
$baseTtl = max(60, (int)CACHE_TTL);

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

$nextStrip = sports_next_game_strip($cards, $tz);
$hasData = $cards !== [];

$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$gap = $boardH < 1080 ? 14 : 18;
$padY = ($boardH < 1080 ? 20 : 24) * 2;
$stampH = 22;
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowNext = max(128, (int)round(148 * $boardH / 1080));
$rowCards = $boardH - $padY - ($gap * 3) - $stampH - $rowHead - $rowNext;
$rowCards = max(400, $rowCards);
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
           display:grid; gap:<?= $gap ?>px;
           grid-template-columns:1fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowCards ?>px <?= $rowNext ?>px auto;
           grid-template-areas:
             "head head"
             "cards cards"
             "next next"
             "meta meta"; min-height:0; }

  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .cards { grid-area:cards; display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
           gap:<?= $boardH < 1080 ? 14 : 18 ?>px; min-height:0; }
  .card { position:relative; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:<?= $boardH < 1080 ? '16px 18px' : '18px 22px' ?>; min-height:0; overflow:hidden;
          border-top:4px solid var(--accent, var(--beacon)); display:flex; flex-direction:column; }
  .card.live { border-top-color:var(--down); box-shadow:0 0 0 1px rgba(255,93,93,.25); }

  .card-row { display:flex; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; min-height:0; flex:1; align-items:stretch; }
  .logo-wrap { flex:0 0 <?= $boardH < 1080 ? 92 : 108 ?>px; display:flex; align-items:center; justify-content:center;
               background:var(--lake-night); border:1px solid var(--hairline); border-radius:12px; padding:10px;
               overflow:hidden; }
  .logo-wrap img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
  .logo-wrap .sport-fallback { width:100%; height:100%; color:var(--mist); opacity:.55; display:flex;
                               align-items:center; justify-content:center; }
  .logo-wrap .sport-fallback svg { width:72%; height:72%; display:block; }

  .card-copy { flex:1; min-width:0; display:flex; flex-direction:column; min-height:0; }
  .card-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:6px; }
  .card-top .team { font-family:'Big Shoulders Display'; font-weight:700;
                    font-size:<?= $boardH < 1080 ? 32 : 38 ?>px; letter-spacing:.5px; }
  .card-top .meta { display:flex; align-items:center; gap:8px; flex-shrink:0; }
  .pill { font-size:12px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
          border:1px solid var(--hairline); border-radius:999px; padding:3px 9px; }
  .badge { font-size:12px; letter-spacing:1.5px; text-transform:uppercase; font-weight:600;
           border-radius:999px; padding:4px 10px; background:var(--lake-night); color:var(--mist); }
  .badge.live { background:rgba(255,93,93,.18); color:var(--down); animation:pulse 1.6s ease-in-out infinite; }
  .badge.next { background:rgba(255,179,71,.12); color:var(--beacon); }
  .badge.off { opacity:.85; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }

  .headline { font-family:'Big Shoulders Display'; font-weight:700;
              font-size:<?= $boardH < 1080 ? 50 : 60 ?>px; line-height:1.05; margin:4px 0;
              font-variant-numeric:tabular-nums; }
  .card.offseason .headline { color:var(--mist); font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; }
  .detail { font-size:<?= $boardH < 1080 ? 19 : 22 ?>px; color:var(--mist); line-height:1.35;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .standings { margin-top:auto; padding-top:8px; font-size:<?= $boardH < 1080 ? 17 : 20 ?>px;
               color:var(--beacon); font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .nextup { grid-area:next; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $boardH < 1080 ? '12px 16px' : '14px 20px' ?>; min-height:0; overflow:hidden;
            display:flex; flex-direction:column; gap:8px; }
  .nextup .k { font-size:14px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); flex-shrink:0; }
  .next-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:<?= $boardH < 1080 ? 10 : 12 ?>px;
               flex:1; min-height:0; align-items:stretch; }
  .next-item { background:var(--lake-night); border:1px solid var(--hairline); border-radius:10px;
               padding:<?= $boardH < 1080 ? '8px 10px' : '10px 12px' ?>; display:flex; align-items:center;
               gap:10px; min-width:0; min-height:0; overflow:hidden; }
  .next-item .mini-logo { flex:0 0 38px; width:38px; height:38px; display:flex; align-items:center;
                          justify-content:center; }
  .next-item .mini-logo img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
  .next-item .mini-logo .sport-fallback { width:100%; height:100%; color:var(--mist); opacity:.55; display:flex;
                                          align-items:center; justify-content:center; }
  .next-item .mini-logo .sport-fallback svg { width:100%; height:100%; display:block; }
  .next-copy { min-width:0; flex:1; overflow:hidden; }
  .next-copy .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 20 : 24 ?>px;
                  line-height:1.1; }
  .next-copy .m { font-size:<?= $boardH < 1080 ? 15 : 17 ?>px; color:var(--mist); line-height:1.25;
                  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .next-copy .w { font-size:<?= $boardH < 1080 ? 14 : 16 ?>px; color:var(--beacon); margin-top:1px;
                  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

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
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
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
      $icon = (string)($c['icon'] ?? 'default');
      $logo = $c['logo_url'] ?? null;
      $standings = (string)($c['standings_line'] ?? '');
    ?>
    <article class="card <?= h($mode) ?><?= $badge === 'Off season' ? ' offseason' : '' ?>"
             style="--accent:<?= h($c['accent'] ?? '#ffb347') ?>">
      <div class="card-row">
        <div class="logo-wrap">
          <?php if ($logo): ?>
          <img src="<?= h($logo) ?>" alt="">
          <?php else: ?>
          <div class="sport-fallback"><?= sports_sport_icon_svg($icon) ?></div>
          <?php endif; ?>
        </div>
        <div class="card-copy">
          <div class="card-top">
            <div class="team"><?= h($c['name']) ?></div>
            <div class="meta">
              <span class="pill"><?= h($c['league']) ?></span>
              <span class="badge <?= h($badgeClass) ?>"><?= h($badge) ?></span>
            </div>
          </div>
          <div class="headline"><?= h($c['headline']) ?></div>
          <div class="detail"><?= h($c['detail']) ?></div>
          <?php if ($standings !== ''): ?>
          <div class="standings"><?= h($standings) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <section class="nextup">
    <div class="k">Next games</div>
    <div class="next-grid">
      <?php foreach ($nextStrip as $n): ?>
      <div class="next-item">
        <div class="mini-logo">
          <?php if (!empty($n['logo'])): ?>
          <img src="<?= h((string)$n['logo']) ?>" alt="">
          <?php else: ?>
          <div class="sport-fallback"><?= sports_sport_icon_svg((string)$n['icon']) ?></div>
          <?php endif; ?>
        </div>
        <div class="next-copy">
          <div class="n"><?= h($n['team']) ?></div>
          <div class="m"><?= h($n['text']) ?></div>
          <?php if ($n['when'] !== ''): ?>
          <div class="w"><?= h($n['when']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
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
<?php if ($showClock): ?>
(function(){
  const tz = <?= json_encode(TIMEZONE) ?>;
  function tick(){
    const el = document.getElementById('clock');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', timeZone: tz });
  }
  tick(); setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>
