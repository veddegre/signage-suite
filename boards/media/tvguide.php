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
$hourPct = $hourCount > 0 ? (100 / $hourCount) : 100;
$showClock = signage_show_clock();
$boardH = signage_frame_height();
$heightCss = signage_viewport_height();
$reloadSec = tvguide_reload_sec();
$embedded = isset($_GET['noticker']);
$channelLabelMode = tvguide_channel_label_mode();
$channelColClass = $channelLabelMode === 'none' ? ' grid-no-badge' : '';

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
               border:1px solid color-mix(in srgb, var(--hairline) 50%, transparent); border-radius:14px; }
  .grid { display:grid; width:100%; height:100%;
          grid-template-columns: minmax(260px, 320px) repeat(<?= $hourCount ?>, minmax(0, 1fr));
          grid-template-rows: auto repeat(<?= max(1, $rowCount) ?>, minmax(0, 1fr)); }
  .grid .corner, .grid .hour, .grid .ch, .grid .track {
    border-right:1px solid color-mix(in srgb, var(--hairline) 35%, transparent);
    border-bottom:1px solid color-mix(in srgb, var(--hairline) 35%, transparent);
    min-width:0; min-height:0; }
  .grid .corner { background:color-mix(in srgb, var(--lake-night) 55%, var(--harbor));
                   padding:12px 14px; font-size:17px; letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); }
  .grid .hour { background:color-mix(in srgb, var(--lake-night) 45%, var(--harbor));
                padding:12px 10px; text-align:center; font-family:'Big Shoulders Display'; font-weight:600;
                font-size:<?= $boardH < 1080 ? 28 : 32 ?>px; color:var(--snow); }
  .grid .ch { display:flex; align-items:center; gap:12px; padding:10px 14px; min-height:0;
              background:color-mix(in srgb, var(--lake-night) 22%, transparent);
              border-left:4px solid var(--net-accent, color-mix(in srgb, var(--mist) 35%, transparent)); }
  .grid .ch.net-nbc { --net-accent:#c4a84a; }
  .grid .ch.net-cbs { --net-accent:#6a9fd4; }
  .grid .ch.net-abc { --net-accent:#7a8fd8; }
  .grid .ch.net-fox { --net-accent:#b89090; }
  .grid .ch.net-pbs  { --net-accent:#7ab0c8; }
  .grid .ch.net-cw   { --net-accent:#8f9fd4; }
  .grid .ch .num { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 30 : 36 ?>px;
                   line-height:1; min-width:<?= $boardH < 1080 ? 36 : 42 ?>px; text-align:center; color:var(--snow);
                   font-variant-numeric:tabular-nums; flex-shrink:0; }
  .grid .ch .logo { width:<?= $boardH < 1080 ? 44 : 52 ?>px; height:<?= $boardH < 1080 ? 44 : 52 ?>px; flex-shrink:0;
                    border-radius:9px; overflow:hidden; display:flex; align-items:center; justify-content:center;
                    background:color-mix(in srgb, var(--lake-night) 35%, var(--harbor));
                    border:1px solid color-mix(in srgb, var(--hairline) 50%, transparent); }
  .grid .ch .logo img { width:100%; height:100%; object-fit:contain; padding:5px; display:block; }
  .grid .ch .logo.fallback { font-family:'Big Shoulders Display'; font-weight:700;
                             font-size:<?= $boardH < 1080 ? 13 : 15 ?>px; letter-spacing:.4px; color:var(--mist); }
  .grid.grid-no-badge .ch .num { display:none; }
  .grid.grid-no-badge { grid-template-columns: minmax(220px, 280px) repeat(<?= $hourCount ?>, minmax(0, 1fr)); }
  .grid .ch .id { min-width:0; }
  .grid .ch .call { font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; font-weight:600; line-height:1.1; }
  .grid .ch .net { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--mist); white-space:nowrap;
                   overflow:hidden; text-overflow:ellipsis; }

  .grid .track { grid-column: 2 / -1; position:relative; min-height:0; padding:8px 10px 8px 4px; }
  .grid .track-pane { position:relative; height:100%; min-height:0; }
  .grid .block { --bar: color-mix(in srgb, var(--beacon) 72%, var(--snow));
                 position:absolute; top:0; bottom:0; min-width:0; border-radius:10px; padding:8px 10px;
                 display:flex; flex-direction:column; justify-content:flex-start; gap:0;
                 background:linear-gradient(90deg,
                   color-mix(in srgb, var(--bar) 24%, var(--lake-night)) 0%,
                   color-mix(in srgb, var(--bar) 12%, var(--lake-night)) 100%);
                 border:1px solid color-mix(in srgb, var(--bar) 28%, transparent);
                 box-shadow:inset 3px 0 0 var(--bar); overflow:hidden; }
  .grid .block .block-body { flex:1 1 auto; min-height:0; overflow:hidden;
                              display:flex; flex-direction:column; gap:2px; }
  .grid .block .title { font-size:<?= $boardH < 1080 ? 19 : 22 ?>px; line-height:1.16; font-weight:600;
                        overflow:hidden; overflow-wrap:break-word;
                        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
  .grid .block .sub { font-size:<?= $boardH < 1080 ? 13 : 15 ?>px; color:color-mix(in srgb, var(--snow) 72%, var(--mist));
                     line-height:1.18; overflow:hidden; overflow-wrap:break-word;
                     display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical; }
  .grid .block .time { flex:0 0 auto; margin-top:auto; padding-top:4px;
                       font-size:<?= $boardH < 1080 ? 13 : 15 ?>px; color:color-mix(in srgb, var(--mist) 88%, transparent);
                       font-variant-numeric:tabular-nums; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .grid .block.fit-sliver { padding:6px 8px; }
  .grid .block.fit-sliver .title { font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; line-height:1.15;
                                    display:block; -webkit-line-clamp:unset;
                                    white-space:nowrap; text-overflow:ellipsis; }
  .grid .block.fit-sliver .sub { display:none; }
  .grid .block.fit-sliver .time { font-size:12px; padding-top:2px; }
  .grid .block.fit-narrow .title { font-size:<?= $boardH < 1080 ? 17 : 19 ?>px; -webkit-line-clamp:2; }
  .grid .block.fit-narrow .sub { display:none; }
  .grid .block.fit-medium .title { -webkit-line-clamp:2; }
  .grid .block.fit-medium .sub { -webkit-line-clamp:1; }
  .grid .block.fit-full .title { -webkit-line-clamp:3; }
  .grid .block.fit-full.has-sub .title { -webkit-line-clamp:2; }
  .grid .block.fit-full .sub { -webkit-line-clamp:2; }
  .grid .block.continues .block-body { padding-right:12px; }
  .grid .block.tone-news    { --bar:#6ea8e8; }
  .grid .block.tone-sports  { --bar:#5ecf8a; }
  .grid .block.tone-kids    { --bar:#e8b86a; }
  .grid .block.tone-movie   { --bar:#b08adf; }
  .grid .block.tone-variety { --bar:#d892b0; }
  .grid .block.tone-series  { --bar:#7aa8c8; }
  .grid .block.tone-default { --bar: color-mix(in srgb, var(--beacon) 68%, var(--snow)); }
  .grid .block.continues::after {
    content:''; position:absolute; right:8px; top:50%; transform:translateY(-50%);
    width:0; height:0; border-top:6px solid transparent; border-bottom:6px solid transparent;
    border-left:7px solid color-mix(in srgb, var(--bar) 55%, transparent); opacity:.55; }
  .grid .block.live { box-shadow:inset 3px 0 0 var(--bar), 0 0 0 1px color-mix(in srgb, var(--up) 45%, transparent); }
  .grid .block.live .time::before { content:'● '; color:var(--up); }

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
      <div class="grid<?= h($channelColClass) ?>">
        <div class="corner">Channel</div>
        <?php foreach ($hours as $hour): ?>
        <div class="hour"><?= h(tvguide_hour_label((int)$hour)) ?></div>
        <?php endforeach; ?>

        <?php foreach ($rows as $row):
            $callsign = tvguide_callsign_short((string)($row['callsign'] ?? ''));
            $customNum = tvguide_channel_number_for($row);
            $netTone = tvguide_affiliate_tone((string)($row['affiliate'] ?? ''));
            $blocks = is_array($row['blocks'] ?? null) ? $row['blocks'] : [];
            $logoUrl = tvguide_row_channel_logo_url($row);
            if ($customNum !== ''):
                $badge = $customNum;
                $sub = '';
            else:
                $badge = tvguide_row_channel_badge($row);
                $sub = tvguide_row_channel_subtitle($row);
                $showBadge = $badge !== '' && ($channelLabelMode !== 'affiliate' || $customNum !== '');
            endif;
        ?>
        <div class="ch net-<?= h($netTone) ?>">
          <?php if ($customNum !== ''): ?>
          <div class="num"><?= h($customNum) ?></div>
          <?php elseif (!empty($showBadge)): ?>
          <div class="num"><?= h($badge) ?></div>
          <?php endif; ?>
          <?php if ($logoUrl !== ''): ?>
          <div class="logo"><img src="<?= h($logoUrl) ?>" alt="" loading="lazy" decoding="async"></div>
          <?php else: ?>
          <div class="logo fallback" aria-hidden="true"><?= h(tvguide_row_channel_logo_fallback($row)) ?></div>
          <?php endif; ?>
          <div class="id">
            <?php if ($customNum !== ''): ?>
            <div class="call"><?= h($callsign !== '' ? $callsign : (string)($row['name'] ?? '')) ?></div>
            <?php elseif ($channelLabelMode === 'custom'): ?>
            <div class="call"><?= h($callsign !== '' ? $callsign : ($sub !== '' ? $sub : (string)($row['name'] ?? ''))) ?></div>
            <?php $affiliate = trim((string)($row['affiliate'] ?? '')); if ($affiliate !== ''): ?>
            <div class="net"><?= h(strtoupper($affiliate)) ?></div>
            <?php endif; ?>
            <?php elseif ($channelLabelMode === 'affiliate' && $callsign !== ''): ?>
            <div class="call"><?= h($callsign) ?></div>
            <?php else: ?>
            <div class="call"><?= h($badge !== '' ? $badge : ($callsign !== '' ? $callsign : (string)($row['name'] ?? ''))) ?></div>
            <?php if ($sub !== ''): ?>
            <div class="net"><?= h($sub) ?></div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="track">
          <div class="track-pane">
          <?php foreach ($blocks as $block):
              $left = max(0.0, min(99.0, (float)($block['left'] ?? 0)));
              $width = max(1.0, min(100.0 - $left, (float)($block['width'] ?? 1)));
              $tone = preg_replace('/[^a-z0-9_-]/', '', (string)($block['tone'] ?? 'default')) ?: 'default';
              $layoutClass = tvguide_block_layout_classes($width, $hourPct);
              $showSub = tvguide_block_show_subtitle($block, $width);
              if ($showSub) {
                  $layoutClass .= ' has-sub';
              }
              $liveClass = !empty($block['live']) ? ' live' : '';
              $style = sprintf(
                  'left:calc(%s%% + 2px);width:calc(%s%% - 4px);',
                  rtrim(rtrim(sprintf('%.4F', $left), '0'), '.'),
                  rtrim(rtrim(sprintf('%.4F', $width), '0'), '.')
              );
          ?>
          <div class="block tone-<?= h($tone) ?><?= h($layoutClass) ?><?= h($liveClass) ?>" style="<?= h($style) ?>">
            <div class="block-body">
            <div class="title"><?= h((string)($block['title'] ?? '')) ?></div>
            <?php if ($showSub): ?>
            <div class="sub"><?= h((string)$block['subtitle']) ?></div>
            <?php endif; ?>
            </div>
            <div class="time"><?= h((string)($block['start'] ?? '')) ?>–<?= h((string)($block['end'] ?? '')) ?></div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
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
