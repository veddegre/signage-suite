<?php
/**
 * ANNOUNCEMENTS & COUNTDOWN — 1920×1080 signage
 *
 * Operator-authored messages and event countdowns — no external API.
 * Multiple items: announce.php?d=<key>
 */

require_once dirname(__DIR__, 2) . '/lib/announce_lib.php';

$item = announce_resolve_item((string)($_GET['d'] ?? ''));
$pageOff = !empty($item['off']);
define('BOARD_TITLE', (string)($item['title'] ?? announce_default_title()));
define('BOARD_SUB', announce_default_sub());
define('TIMEZONE', announce_timezone());

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$embedded = isset($_GET['noticker']);
$payload = $pageOff
    ? ['ok' => false, 'active' => false, 'mode' => 'announcement', 'title' => BOARD_TITLE, 'body' => 'This item is marked Off wall in admin.', 'sub' => BOARD_SUB]
    : announce_wall_payload($item);
$isCountdown = ($payload['mode'] ?? '') === 'countdown';
$countdownReady = $isCountdown
    && announce_parse_datetime((string)($item['countdown_until'] ?? '')) !== null;
$countdown = is_array($payload['countdown'] ?? null) ? $payload['countdown'] : announce_countdown_parts($item);
$active = !empty($payload['active']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(BOARD_TITLE) ?> — Signage</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --seafoam:#7ec8a4; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:48px 64px; display:flex; flex-direction:column;
           justify-content:center; gap:32px; min-height:1080px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 auto; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:56px; color:var(--beacon); letter-spacing:1px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:52px; color:var(--mist); }

  .hero { flex:1; display:flex; flex-direction:column; justify-content:center; gap:28px;
          padding:40px 48px; background:var(--harbor); border:1px solid var(--hairline); border-radius:18px; }
  .hero .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .hero .title { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $isCountdown ? 88 : 96 ?>px;
                 line-height:1.05; color:var(--snow); max-width:100%; }
  .hero .body { font-size:<?= $isCountdown ? 32 : 38 ?>px; line-height:1.55; color:var(--mist); max-width:92%; white-space:pre-wrap; }

  .countdown { display:flex; align-items:baseline; gap:24px; flex-wrap:wrap; margin-top:8px; }
  .countdown .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:148px; line-height:1;
                    color:var(--seafoam); font-variant-numeric:tabular-nums; }
  .countdown .unit { font-size:42px; color:var(--mist); margin-right:28px; }
  .countdown.past .num { color:var(--beacon); font-size:72px; }

  .inactive { font-size:32px; color:var(--mist); line-height:1.5; }
  .setupmsg { font-size:26px; color:var(--mist); line-height:1.6; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h($isCountdown ? 'Countdown' : 'Announcement') ?><?php if (BOARD_SUB !== ''): ?> · <?= h(BOARD_SUB) ?><?php endif; ?></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($pageOff): ?>
    <div class="hero"><div class="inactive"><?= h((string)$payload['body']) ?></div></div>
  <?php elseif (!$active): ?>
    <div class="hero">
      <div class="k">Not scheduled now</div>
      <div class="title"><?= h(BOARD_TITLE) ?></div>
      <div class="inactive">This announcement is outside its active window or the countdown has ended.</div>
    </div>
  <?php elseif ($isCountdown && $countdownReady): ?>
    <div class="hero">
      <div class="k">Countdown</div>
      <div class="title"><?= h(BOARD_TITLE) ?></div>
      <?php if (($payload['body'] ?? '') !== ''): ?>
      <div class="body"><?= h((string)$payload['body']) ?></div>
      <?php endif; ?>
      <div class="countdown<?= !empty($countdown['past']) ? ' past' : '' ?>" id="countdown">
        <?php if (!empty($countdown['past'])): ?>
          <span class="num"><?= h((string)$countdown['label']) ?></span>
        <?php else: ?>
          <?php if ((int)$countdown['days'] > 0): ?>
            <span class="num"><?= (int)$countdown['days'] ?></span><span class="unit">days</span>
          <?php endif; ?>
          <span class="num"><?= (int)$countdown['hours'] ?></span><span class="unit">hours</span>
          <span class="num"><?= (int)$countdown['minutes'] ?></span><span class="unit">min</span>
          <span class="num" id="cd-sec"><?= (int)$countdown['seconds'] ?></span><span class="unit">sec</span>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="hero">
      <div class="k">Announcement</div>
      <div class="title"><?= h(BOARD_TITLE) ?></div>
      <?php if (($payload['body'] ?? '') !== ''): ?>
      <div class="body"><?= h((string)$payload['body']) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="stamp">Announcements<?php if ($countdownReady && !$countdown['past']): ?> · live countdown<?php endif; ?></div>
</div>

<?php if ($showClock): ?>
<script>
(function () {
  const tz = <?= json_encode(TIMEZONE, JSON_UNESCAPED_SLASHES) ?>;
  const el = document.getElementById('clock');
  function tick() {
    const now = new Date();
    el.textContent = now.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', timeZone: tz });
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

<?php if ($countdownReady && $active && empty($countdown['past'])): ?>
<script>
(function () {
  const target = new Date(<?= json_encode((string)($payload['countdown_until'] ?? '')) ?>).getTime();
  const secEl = document.getElementById('cd-sec');
  function tick() {
    const left = Math.max(0, Math.floor((target - Date.now()) / 1000));
    if (left <= 0) { location.reload(); return; }
    if (secEl) secEl.textContent = String(left % 60);
  }
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

<?php if (empty($embedded) && !isset($_GET['noticker'])): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
