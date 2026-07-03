<?php
/**
 * EMERGENCY ANNOUNCEMENT — full-screen message from rotation.EMERGENCY config.
 * Used when admin activates emergency announce mode without a pre-built announce item.
 */

require_once dirname(__DIR__, 2) . '/lib/emergency_lib.php';

$payload = emergency_announce_payload();
if (!emergency_active() || emergency_mode() !== 'announce') {
    $payload = [
        'title' => 'Emergency',
        'body' => 'No active emergency announcement.',
        'sub' => '',
    ];
}

define('BOARD_TITLE', (string)($payload['title'] ?? 'Emergency'));
define('BOARD_SUB', (string)($payload['sub'] ?? ''));
define('TIMEZONE', (string)cfg('rotation.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --alert:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:48px 64px; display:flex; flex-direction:column;
           justify-content:center; gap:32px; min-height:1080px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 auto; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:56px; color:var(--alert); letter-spacing:1px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:52px; color:var(--mist); }
  .hero { flex:1; display:flex; flex-direction:column; justify-content:center; gap:28px;
          padding:40px 48px; background:var(--harbor); border:2px solid var(--alert); border-radius:18px; }
  .hero .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--alert); }
  .hero .title { font-family:'Big Shoulders Display'; font-weight:700; font-size:96px;
                 line-height:1.05; color:var(--snow); max-width:100%; }
  .hero .body { font-size:38px; line-height:1.55; color:var(--mist); max-width:92%; white-space:pre-wrap; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Emergency<?php if (BOARD_SUB !== ''): ?> · <?= h(BOARD_SUB) ?><?php endif; ?></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>
  <div class="hero">
    <div class="k">Important</div>
    <div class="title"><?= h(BOARD_TITLE) ?></div>
    <?php if (trim((string)($payload['body'] ?? '')) !== ''): ?>
    <div class="body"><?= h((string)$payload['body']) ?></div>
    <?php endif; ?>
  </div>
  <div class="stamp">Emergency override</div>
</div>
<?php if ($showClock): ?>
<script>
(function () {
  const tz = <?= json_encode(TIMEZONE) ?>;
  function tick() {
    const el = document.getElementById('clock');
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: tz });
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
