<?php
/**
 * TV GUIDE BOARD — 1920×1080 prime-time grid
 *
 * Data: Schedules Direct JSON API (~$35/yr). Server-side fetch + cache.
 * Multiple pages: tvguide.php?d=<key> — pick channels per page in admin.
 */

require_once dirname(__DIR__, 2) . '/lib/tvguide_lib.php';
require_once dirname(__DIR__, 2) . '/lib/signage_theme_lib.php';
require_once dirname(__DIR__, 2) . '/lib/rotation_lib.php';

$themePreset = signage_theme_preset(signage_active_theme_key());
$themeLight = $themePreset !== null && ($themePreset['light'] ?? '0') === '1';

$page = tvguide_resolve_page((string)($_GET['d'] ?? ''));
$pageOff = !empty($page['off']);
define('BOARD_TITLE', (string)($page['title'] ?? tvguide_default_page_title()));
define('BOARD_SUB', (string)($page['sub'] ?? tvguide_default_page_sub()));
define('TIMEZONE', tvguide_timezone());

date_default_timezone_set(TIMEZONE);

$configured = tvguide_configured();
$data = $pageOff
    ? ['ok' => false, 'error' => 'This page is marked Off wall in admin.', 'rows' => [], 'hours' => []]
    : tvguide_fetch_grid_data($page);

$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
$hours = is_array($data['hours'] ?? null) ? $data['hours'] : [];
$rowCount = count($rows);
$hourCount = max(1, count($hours));
$showClock = signage_show_clock();
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$reloadSec = tvguide_reload_sec();
$embedded = isset($_GET['noticker']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function tvguide_hour_label(int $hour): string
{
    $dt = DateTimeImmutable::createFromFormat('G', (string)$hour, new DateTimeZone(TIMEZONE));
    if ($dt === false) {
        return (string)$hour;
    }

    return $dt->format('g A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(BOARD_TITLE) ?> — Signage</title>
<?= signage_theme_fonts_head_html() ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { --surface: color-mix(in srgb, var(--harbor) 86%, var(--lake-night));
           width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 14 : 18 ?>px;
           grid-template-rows: auto 1fr auto; min-height:0; }
  .head { display:flex; align-items:baseline; justify-content:space-between; gap:16px; min-width:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px;
             min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .head h1 .sub { color:var(--mist); font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; margin-left:16px;
                  font-weight:400; font-family:'IBM Plex Sans',sans-serif; }
  .head .meta { text-align:right; color:var(--mist); font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; line-height:1.35; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; margin-left:24px; }

  .grid-wrap { min-height:0; overflow:hidden; background:var(--surface);
               border:1px solid color-mix(in srgb, var(--hairline) 65%, transparent); border-radius:14px; }
  .grid { display:grid; width:100%; height:100%;
          grid-template-columns: minmax(220px, 260px) repeat(<?= $hourCount ?>, minmax(0, 1fr));
          grid-template-rows: auto repeat(<?= max(1, $rowCount) ?>, minmax(0, 1fr)); }
  .grid .corner, .grid .hour, .grid .ch, .grid .cell {
    border-right:1px solid color-mix(in srgb, var(--hairline) 45%, transparent);
    border-bottom:1px solid color-mix(in srgb, var(--hairline) 45%, transparent);
    min-width:0; min-height:0; }
  .grid .corner { background:color-mix(in srgb, var(--lake-night) 35%, var(--harbor));
                   padding:12px 14px; font-size:18px; letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); }
  .grid .hour { background:color-mix(in srgb, var(--lake-night) 28%, var(--harbor));
                padding:12px 10px; text-align:center; font-family:'Big Shoulders Display'; font-weight:600;
                font-size:<?= $boardH < 1080 ? 28 : 32 ?>px; color:var(--snow); }
  .grid .ch { display:flex; align-items:center; gap:12px; padding:10px 14px; min-height:0; }
  .grid .ch .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 34 : 38 ?>px;
                   color:var(--beacon); min-width:52px; text-align:right; font-variant-numeric:tabular-nums; }
  .grid .ch .id { min-width:0; }
  .grid .ch .call { font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; font-weight:600; line-height:1.1; }
  .grid .ch .net { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--mist); white-space:nowrap;
                   overflow:hidden; text-overflow:ellipsis; }
  .grid .cell { padding:10px 12px; display:flex; flex-direction:column; justify-content:center; gap:4px; min-height:0; }
  .grid .cell .title { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; line-height:1.2; font-weight:600;
                        display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
  .grid .cell .sub { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--mist); line-height:1.25;
                     display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .grid .cell .time { font-size:15px; color:color-mix(in srgb, var(--mist) 80%, transparent); margin-top:2px; }
  .grid .cell.empty { background:color-mix(in srgb, var(--lake-night) 18%, transparent); }
  .grid .cell.live { box-shadow:inset 0 0 0 2px color-mix(in srgb, var(--down) 55%, transparent); }

  .panel { background:var(--surface); border:1px solid color-mix(in srgb, var(--hairline) 65%, transparent);
           border-radius:14px; padding:28px 32px; display:flex; align-items:center; justify-content:center;
           min-height:0; height:100%; }
  .setupmsg, .err { font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; color:var(--mist); line-height:1.55; max-width:1200px; }
  .setupmsg code, .err code { color:var(--snow); background:var(--code-bg); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
<?php if (!$embedded && $reloadSec > 0 && $configured && !$pageOff && ($data['ok'] ?? false)): ?>
<meta http-equiv="refresh" content="<?= (int)$reloadSec ?>">
<?php endif; ?>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?><span class="sub"><?= h(BOARD_SUB) ?></span></h1>
    <div class="meta">
      <?php if (!empty($data['date_label'])): ?>
      <div><?= h((string)$data['date_label']) ?><?= !empty($data['prime_label']) ? ' · ' . h((string)$data['prime_label']) : '' ?></div>
      <?php endif; ?>
      <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
    </div>
  </div>

  <?php if (!$configured): ?>
    <div class="panel">
      <div class="setupmsg">Set <code>SD_USERNAME</code> and <code>SD_PASSWORD</code> in
      <strong>admin.php → TV Guide</strong>. Schedules Direct accounts are ~$35/year at
      <strong>schedulesdirect.org</strong>.</div>
    </div>
  <?php elseif (($data['error'] ?? '') !== '' && $rows === []): ?>
    <div class="panel">
      <div class="err"><?= h((string)$data['error']) ?></div>
    </div>
  <?php elseif ($rows === []): ?>
    <div class="panel">
      <div class="setupmsg">No channels selected — open <strong>admin.php → TV Guide</strong> and check channels for this page.</div>
    </div>
  <?php else: ?>
    <div class="grid-wrap">
      <div class="grid">
        <div class="corner">Channel</div>
        <?php foreach ($hours as $hour): ?>
        <div class="hour"><?= h(tvguide_hour_label((int)$hour)) ?></div>
        <?php endforeach; ?>

        <?php foreach ($rows as $row): ?>
        <div class="ch">
          <div class="num"><?= h((string)($row['channel'] ?? '')) ?></div>
          <div class="id">
            <div class="call"><?= h((string)($row['callsign'] ?? $row['name'] ?? '')) ?></div>
            <?php if (!empty($row['name']) && ($row['callsign'] ?? '') !== ($row['name'] ?? '')): ?>
            <div class="net"><?= h((string)$row['name']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php foreach ($hours as $hour):
            $cell = $row['cells'][(string)$hour] ?? null; ?>
        <div class="cell<?= $cell === null ? ' empty' : '' ?>">
          <?php if (is_array($cell)): ?>
          <div class="title"><?= h((string)($cell['title'] ?? '')) ?></div>
          <?php if (!empty($cell['subtitle'])): ?>
          <div class="sub"><?= h((string)$cell['subtitle']) ?></div>
          <?php endif; ?>
          <div class="time"><?= h((string)($cell['start'] ?? '')) ?>–<?= h((string)($cell['end'] ?? '')) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="stamp">
    Schedules Direct<?php if (!empty($data['cache_age'])): ?> · listings <?= (int)$data['cache_age'] ?>s old<?php endif; ?>
    <?php if (!empty($data['error']) && $rows !== []): ?> · <?= h((string)$data['error']) ?><?php endif; ?>
  </div>
</div>

<?php if ($showClock): ?>
<script>
(function () {
  const el = document.getElementById('clock');
  if (!el) return;
  const tz = <?= json_encode(TIMEZONE, JSON_UNESCAPED_UNICODE) ?>;
  function tick() {
    try {
      el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: tz });
    } catch (e) {
      el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
