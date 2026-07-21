<?php
/**
 * SPORTS BOARD — 1920×1080 signage
 * Up to four ESPN teams per display (see Rotation → Display options).
 *
 * Data: ESPN public site API (no key). Server-side fetch + cache.
 * Live scores poll via sports.php?api=1 without full page reload.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/sports_lib.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';

const SPORTS_CACHE_DIR = SIGNAGE_ROOT . '/cache';

$SCREEN = signage_request_screen();
$sportsLabels = rotation_screen_sports_labels($SCREEN);
define('TITLE', $sportsLabels['title']);
define('SUBTITLE', $sportsLabels['subtitle']);
define('TIMEZONE', cfg('sports.TIMEZONE', 'America/Detroit'));

$board = sports_board_data($SCREEN);

if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'cards' => array_map('sports_card_api_payload', $board['cards']),
        'any_live' => (bool)$board['any_live'],
        'focus_live' => (bool)$board['focus_live'],
        'show_next_strip' => (bool)$board['show_next_strip'],
        'next_strip' => $board['next_strip'],
        'recent_strip' => $board['recent_strip'],
        'cache_age' => (int)$board['cache_age'],
        'stamp' => sports_board_stamp_text($board),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function sports_board_stamp_text(array $board): string
{
    $parts = ['ESPN'];
    $age = (int)($board['cache_age'] ?? 0);
    if ($age > 0) {
        $parts[] = 'Scores ' . sports_format_cache_age($age);
    }
    if (!empty($board['any_live'])) {
        $parts[] = 'Live poll ' . (int)($board['reload_sec'] ?? 120) . 's';
    }
    if (!empty($GLOBALS['diag']) && is_array($GLOBALS['diag'])) {
        $parts[] = implode('; ', array_map(
            static fn($k, $v) => "$k: $v",
            array_keys($GLOBALS['diag']),
            $GLOBALS['diag']
        ));
    }

    return implode(' · ', array_filter($parts));
}

$cards = $board['cards'];
$teamCount = (int)$board['team_count'];
$anyLive = (bool)$board['any_live'];
$focusLive = (bool)$board['focus_live'];
$nextStrip = $board['next_strip'];
$showNextStrip = (bool)$board['show_next_strip'];
$recentStrip = $board['recent_strip'];
$hasData = (bool)$board['has_data'];
$reloadSec = (int)$board['reload_sec'];
$embedded = isset($_GET['noticker']);
$showClock = signage_show_clock();
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$gap = $boardH < 1080 ? 14 : 18;
$padY = ($boardH < 1080 ? 20 : 24) * 2;
$stampH = 22;
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$rowRecent = $recentStrip !== [] ? max(72, (int)round(88 * $boardH / 1080)) : 0;
$rowNext = $showNextStrip ? max(128, (int)round(148 * $boardH / 1080)) : 0;
$extraRows = ($rowRecent > 0 ? 1 : 0) + ($rowNext > 0 ? 1 : 0);
$rowCards = $boardH - $padY - ($gap * (2 + $extraRows)) - $stampH - $rowHead - $rowRecent - $rowNext;
$rowCards = max(400, $rowCards);
$layoutClass = 'layout-' . max(1, min(4, $teamCount)) . ($focusLive ? ' focus-live' : '');
$pollMs = $anyLive ? max(45000, $reloadSec * 1000) : 120000;
$apiUrl = 'sports.php?api=1&screen=' . rawurlencode($SCREEN);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
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
           display:grid; gap:<?= $gap ?>px; min-height:0;
           grid-template-columns:1fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowCards ?>px<?= $rowRecent ? ' ' . $rowRecent . 'px' : '' ?><?= $rowNext ? ' ' . $rowNext . 'px' : '' ?> auto;
           grid-template-areas:
             "head head"
             "cards cards"<?= $rowRecent ? "\n             \"recent recent\"" : '' ?><?= $rowNext ? "\n             \"next next\"" : '' ?>
             "meta meta"; }

  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .cards { grid-area:cards; display:grid; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; min-height:0; }
  .cards.layout-1 { grid-template-columns:1fr; grid-template-rows:1fr; }
  .cards.layout-2 { grid-template-columns:1fr 1fr; grid-template-rows:1fr; }
  .cards.layout-3 { grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; }
  .cards.layout-3 .card:first-child { grid-column:1 / -1; }
  .cards.layout-4 { grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; }
  .cards.focus-live { grid-template-columns:1.45fr .85fr; grid-template-rows:1fr 1fr; }
  .cards.focus-live .card.live.focus { grid-row:1 / span 2; }

  .card { position:relative; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:<?= $boardH < 1080 ? '16px 18px' : '18px 22px' ?>; min-height:0; overflow:hidden;
          border-top:4px solid var(--accent, var(--beacon)); display:flex; flex-direction:column; }
  .card.live { border-top-color:var(--down); box-shadow:0 0 0 1px rgba(255,93,93,.25); }
  .card.result-win { border-top-color:var(--up); }
  .card.result-loss { border-top-color:var(--down); opacity:.96; }
  .card.error { border-top-color:var(--mist); }

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
  .badge.final { background:rgba(57,196,109,.12); color:var(--up); }
  .badge.off { opacity:.85; }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.65} }

  .scoreboard { display:flex; align-items:center; gap:16px; margin:2px 0 4px; }
  .score-team { display:flex; align-items:center; gap:12px; min-width:0; }
  .score-team.opp { flex-direction:row-reverse; }
  .score-logo { width:<?= $boardH < 1080 ? 44 : 52 ?>px; height:<?= $boardH < 1080 ? 44 : 52 ?>px; object-fit:contain; }
  .score-num { font-family:'Big Shoulders Display'; font-weight:700;
               font-size:<?= $boardH < 1080 ? 52 : 64 ?>px; line-height:1; font-variant-numeric:tabular-nums; }
  .card.result-win .score-num { color:var(--up); }
  .card.result-loss .score-num { color:var(--down); }
  .score-dash { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; color:var(--mist); }

  .clock-line { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 22 : 26 ?>px;
                color:var(--down); letter-spacing:1px; margin-bottom:2px; }
  .headline { font-family:'Big Shoulders Display'; font-weight:700;
              font-size:<?= $boardH < 1080 ? 50 : 60 ?>px; line-height:1.05; margin:4px 0;
              font-variant-numeric:tabular-nums; }
  .card.offseason .headline { color:var(--mist); font-size:<?= $boardH < 1080 ? 40 : 48 ?>px; }
  .detail { font-size:<?= $boardH < 1080 ? 19 : 22 ?>px; color:var(--mist); line-height:1.35;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .standings { margin-top:auto; padding-top:8px; font-size:<?= $boardH < 1080 ? 17 : 20 ?>px;
               color:var(--beacon); font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .recent { grid-area:recent; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $boardH < 1080 ? '10px 16px' : '12px 20px' ?>; min-height:0; overflow:hidden; }
  .recent .k { font-size:14px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:8px; }
  .recent-grid { display:grid; grid-template-columns:repeat(<?= max(1, count($recentStrip)) ?>,1fr); gap:10px; }
  .recent-item { background:var(--lake-night); border:1px solid var(--hairline); border-radius:10px;
                 padding:8px 12px; min-width:0; }
  .recent-item .top { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
  .recent-item .mini-logo { width:28px; height:28px; flex:0 0 28px; display:flex; align-items:center; justify-content:center; }
  .recent-item .mini-logo img { max-width:100%; max-height:100%; object-fit:contain; }
  .recent-item .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:20px; }
  .recent-item .streak { margin-left:auto; font-family:'IBM Plex Mono',monospace; font-size:16px; color:var(--beacon); }
  .recent-item .line { font-size:15px; color:var(--mist); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .nextup { grid-area:next; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $boardH < 1080 ? '12px 16px' : '14px 20px' ?>; min-height:0; overflow:hidden;
            display:flex; flex-direction:column; gap:8px; }
  .nextup .k { font-size:14px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); flex-shrink:0; }
  .next-grid { display:grid; grid-template-columns:repeat(<?= max(1, $teamCount) ?>,1fr); gap:<?= $boardH < 1080 ? 10 : 12 ?>px;
               flex:1; min-height:0; align-items:stretch; }
  .next-item { background:var(--lake-night); border:1px solid var(--hairline); border-radius:10px;
               padding:<?= $boardH < 1080 ? '8px 10px' : '10px 12px' ?>; display:flex; align-items:center;
               gap:10px; min-width:0; min-height:0; overflow:hidden; }
  .next-item .mini-logo { flex:0 0 38px; width:38px; height:38px; display:flex; align-items:center;
                          justify-content:center; }
  .next-item .mini-logo img { max-width:100%; max-height:100%; object-fit:contain; display:block; }
  .next-item .mini-logo .sport-fallback { width:100%; height:100%; color:var(--mist); opacity:.55; display:flex;
                                          align-items:center; justify-content:center; }
  .next-copy { min-width:0; flex:1; overflow:hidden; }
  .next-copy .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; line-height:1.1; }
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
  <div class="cards <?= h($layoutClass) ?>" id="sports-cards">
    <?php foreach ($cards as $c):
      $isFocus = $focusLive && ($c['mode'] ?? '') === 'live';
      echo sports_render_card($c, $isFocus);
    endforeach; ?>
  </div>

  <?php if ($recentStrip !== []): ?>
  <section class="recent" id="sports-recent">
    <div class="k">Recent results</div>
    <div class="recent-grid">
      <?php foreach ($recentStrip as $r): ?>
      <div class="recent-item">
        <div class="top">
          <div class="mini-logo">
            <?php if (!empty($r['logo'])): ?>
            <img src="<?= h((string)$r['logo']) ?>" alt="">
            <?php else: ?>
            <?= sports_sport_icon_svg((string)$r['icon']) ?>
            <?php endif; ?>
          </div>
          <div class="n"><?= h($r['team']) ?></div>
          <?php if ($r['streak'] !== ''): ?><div class="streak"><?= h($r['streak']) ?></div><?php endif; ?>
        </div>
        <div class="line"><?= h(implode(' · ', $r['recent'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($showNextStrip): ?>
  <section class="nextup" id="sports-next">
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
  <?php endif; ?>
  <?php else: ?>
  <div class="empty">Sports data unavailable — check network or try again shortly.</div>
  <?php endif; ?>

  <div class="stamp" id="sports-stamp"><?= h(sports_board_stamp_text($board)) ?></div>
</div>
<script>
(function () {
  var API = <?= json_encode($apiUrl) ?>;
  var POLL = <?= (int)$pollMs ?>;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function setText(el, value) {
    if (!el) return;
    var v = value == null ? '' : String(value);
    if (v === '') {
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    el.style.display = '';
    el.textContent = v;
  }

  function applyCard(card) {
    var root = document.querySelector('[data-card-key="' + CSS.escape(card.key) + '"]');
    if (!root) return;
    var prevMode = root.getAttribute('data-mode') || '';
    if (prevMode && prevMode !== card.mode && (card.mode === 'live' || prevMode === 'live' || card.mode === 'final' || prevMode === 'final')) {
      location.reload();
      return;
    }
    root.setAttribute('data-mode', card.mode || 'off');
    root.className = 'card ' + (card.mode || 'off')
      + (card.badge === 'Off season' ? ' offseason' : '')
      + (card.result_class ? ' result-' + card.result_class : '')
      + (card.data_error ? ' error' : '');
    root.style.setProperty('--accent', card.accent || '#ffb347');
    setText(root.querySelector('[data-field="name"]'), card.name);
    setText(root.querySelector('[data-field="league"]'), card.league);
    var badgeEl = root.querySelector('[data-field="badge"]');
    if (badgeEl) {
      badgeEl.textContent = card.badge || '';
      badgeEl.className = 'badge ' + ({
        Live: 'live', 'Up next': 'next', Final: 'final', 'Off season': 'off', Unavailable: 'off'
      }[card.badge] || '');
    }
    var scoreboard = root.querySelector('[data-field="scoreboard"]');
    var headline = root.querySelector('[data-field="headline"]');
    var showScore = (card.mode === 'live' || card.mode === 'final') && card.us_score != null && card.them_score != null;
    if (showScore && scoreboard) {
      if (headline) headline.style.display = 'none';
      scoreboard.style.display = '';
      setText(root.querySelector('[data-field="us_score"]'), card.us_score);
      setText(root.querySelector('[data-field="them_score"]'), card.them_score);
      var oppLogo = root.querySelector('[data-field="opp_logo"]');
      if (oppLogo && card.opponent_logo) oppLogo.src = card.opponent_logo;
      setText(root.querySelector('[data-field="clock"]'), card.clock_line);
    } else {
      if (scoreboard) scoreboard.style.display = 'none';
      if (headline) {
        headline.style.display = '';
        setText(headline, card.headline);
      }
      setText(root.querySelector('[data-field="clock"]'), '');
    }
    setText(root.querySelector('[data-field="detail"]'), card.detail);
    setText(root.querySelector('[data-field="standings"]'), card.standings_line);
  }

  function refresh() {
    fetch(API, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.cards) return;
        data.cards.forEach(applyCard);
        var stamp = document.getElementById('sports-stamp');
        if (stamp && data.stamp) stamp.textContent = data.stamp;
      })
      .catch(function () {});
  }

  refresh();
  setInterval(refresh, POLL);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') refresh();
  });
})();
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
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
