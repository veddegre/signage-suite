<?php
/**
 * NTFY RECENT ALERTS — compact board + webhook target cache reader.
 */

require_once dirname(__DIR__, 2) . '/lib/ntfy_lib.php';

define('BOARD_TITLE', (string)cfg('ntfy.BOARD_TITLE', 'Recent Alerts'));
define('BOARD_SUB', (string)cfg('ntfy.BOARD_SUB', 'ntfy'));
define('TIMEZONE', cfg('ntfy.TIMEZONE', 'America/Detroit'));
define('MAX_SHOWN', max(4, min(20, (int)cfg('ntfy.MAX_SHOWN', 10))));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
ntfy_poll_refresh_if_due();
$messages = ntfy_recent_messages(MAX_SHOWN);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(BOARD_TITLE) ?> — Signage</title>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d; --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --bad:#e45959; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night); color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; <?= signage_viewport_css() ?> }
  .board { padding:36px 40px; display:flex; flex-direction:column; gap:20px; min-height:1080px; }
  h1 { font-family:'Big Shoulders Display'; font-size:58px; color:var(--beacon); }
  .list { display:flex; flex-direction:column; gap:12px; }
  .msg { padding:16px 18px; border-radius:12px; background:var(--harbor); border:1px solid var(--hairline); }
  .msg.high { border-color:rgba(228,89,89,.45); }
  .msg .title { font-size:28px; }
  .msg .body { font-size:20px; color:var(--mist); margin-top:6px; }
  .empty { font-size:26px; color:var(--mist); }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <h1><?= h(BOARD_TITLE) ?> · <?= h(BOARD_SUB) ?></h1>
  <?php if ($messages === []): ?>
    <div class="empty">No alerts cached yet — point ntfy (or Apprise) at <code>ntfy_webhook.php</code> with your webhook token.</div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($messages as $msg):
        $prio = (int)($msg['priority'] ?? 3);
      ?>
      <div class="msg<?= $prio >= 4 ? ' high' : '' ?>">
        <div class="title"><?= h((string)($msg['title'] ?? '')) ?></div>
        <?php if (trim((string)($msg['message'] ?? '')) !== '' && ($msg['message'] ?? '') !== ($msg['title'] ?? '')): ?>
        <div class="body"><?= h((string)$msg['message']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="stamp">ntfy cache</div>
</div>
<?php if (!isset($_GET['noticker'])): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
