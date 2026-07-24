<?php
/**
 * TODAY AT A GLANCE — 1920×1080 signage
 * Today's calendar (from Calendar board feeds), weather summary, and headline panels.
 *
 * Setup: configure ICS feeds on the Calendar board (calendar.ICS_FEEDS).
 * Headlines: per-display under Rotation → Kiosk settings (site defaults on Today at a Glance in admin).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/security_lib.php';
require_once dirname(__DIR__, 2) . '/lib/calendar_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';
require_once dirname(__DIR__, 2) . '/lib/weather_lib.php';
require_once dirname(__DIR__, 2) . '/lib/glance_headlines_lib.php';

define('TITLE', cfg('glance.TITLE', 'Today at a glance'));
define('SUBTITLE', cfg('glance.SUBTITLE', ''));
define('MAX_TODAY', max(3, min(16, (int)cfg('glance.MAX_TODAY', 8))));
define('SHOW_TOMORROW', (bool)cfg('glance.SHOW_TOMORROW', true));
define('SHOW_WEATHER', (bool)cfg('glance.SHOW_WEATHER', true));
define('RELOAD_SEC', max(60, (int)cfg('glance.RELOAD_SEC', 300)));
define('TIMEZONE', cfg('glance.TIMEZONE', cfg('calendar.TIMEZONE', 'America/Detroit')));

$icsFeeds = cfg('calendar.ICS_FEEDS', []);
if (!is_array($icsFeeds)) {
    $icsFeeds = [];
}

define('SIGNAGE_CALENDAR_LIB_ONLY', true);
require_once __DIR__ . '/calendar.php';

date_default_timezone_set(TIMEZONE);
$frameH = signage_frame_height();
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

$SCREEN = signage_request_screen();
$LOC = rotation_screen_location($SCREEN);
$lat = (float)$LOC['lat'];
$lon = (float)$LOC['lon'];

$winStart = strtotime('today');
$winEnd = strtotime('today +2 days') - 1;
$events = calendar_collect_events($winStart, $winEnd);

$todayKey = date('Y-m-d');
$tomorrowKey = date('Y-m-d', strtotime('+1 day'));
$todayEvents = [];
$tomorrowEvents = [];
foreach ($events as $e) {
    $key = date('Y-m-d', $e['ts']);
    if ($key === $todayKey) {
        $todayEvents[] = $e;
    } elseif ($key === $tomorrowKey) {
        $tomorrowEvents[] = $e;
    }
}

$calLegend = calendar_legend(is_array(ICS_FEEDS) ? ICS_FEEDS : []);

$weather = SHOW_WEATHER ? weather_glance_summary($lat, $lon) : null;

$glanceHeadlines = rotation_screen_glance_headlines($SCREEN);
$headlinePanel1 = $glanceHeadlines['panel1'];
$headlinePanel2 = $glanceHeadlines['panel2'];
$headlinesTtl = (int)$glanceHeadlines['ttl'];
$headlines1 = ['items' => [], 'source' => ''];
$headlines2 = ['items' => [], 'source' => ''];
$headlines1Active = (bool)$headlinePanel1['active'];
$headlines2Active = (bool)$headlinePanel2['active'];
if ($headlines1Active) {
    $headlines1 = glance_headlines_panel(
        'page',
        (string)$headlinePanel1['page_url'],
        (string)$headlinePanel1['rss'],
        (int)$headlinePanel1['max'],
        $headlinesTtl
    );
}
if ($headlines2Active) {
    $headlines2 = glance_headlines_panel(
        'rss',
        '',
        (string)$headlinePanel2['rss'],
        (int)$headlinePanel2['max'],
        $headlinesTtl
    );
}
$skyBottomPanels = (int)$headlines1Active + (int)$headlines2Active;

function glance_render_headlines(string $title, array $panel, bool $wide, string $emptyHint): void
{
    $items = $panel['items'] ?? [];
    $wideClass = $wide ? ' headlines-wide' : '';
    echo '<section class="headlines' . $wideClass . '">';
    echo '<div class="k">' . h($title) . '</div>';
    echo '<div class="headline-list">';
    if ($items !== []) {
        foreach ($items as $item) {
            echo '<div class="headline-item">' . h((string)($item['title'] ?? '')) . '</div>';
        }
    } else {
        echo '<div class="headline-empty">' . $emptyHint . '</div>';
    }
    echo '</div></section>';
}

$boardH = $frameH;
$compact = $boardH < 1080;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:<?= $compact ? 22 : 28 ?>px <?= $compact ? 26 : 32 ?>px;
           display:grid; gap:<?= $compact ? 18 : 24 ?>px;
           grid-template-columns: <?= $compact ? 560 : 600 ?>px 1fr;
           grid-template-rows: minmax(0,1fr) auto;
           grid-template-areas: "agenda sky" "meta meta"; }

  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 88 : 110 ?>px; line-height:1; }
  #clock span { font-size:<?= $compact ? 36 : 44 ?>px; color:var(--mist); }
  .dateline { font-size:<?= $compact ? 24 : 30 ?>px; color:var(--mist); margin-top:6px; }
  .board-title { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 28 : 32 ?>px;
                  letter-spacing:2px; text-transform:uppercase; color:var(--beacon); margin-top:10px; }
  .board-sub { font-size:<?= $compact ? 20 : 22 ?>px; color:var(--mist); margin-top:4px; }

  .agenda { grid-area:agenda; height:100%; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $compact ? 28 : 38 ?>px <?= $compact ? 32 : 42 ?>px; min-height:0; overflow:hidden;
            display:flex; flex-direction:column; }
  .agenda > .k { font-size:<?= $compact ? 18 : 20 ?>px; letter-spacing:3px; text-transform:uppercase; color:var(--mist);
                  margin:<?= $compact ? 22 : 30 ?>px 0 8px; border-top:1px solid var(--hairline); padding-top:<?= $compact ? 18 : 24 ?>px; }
  .cal-legend { display:flex; flex-wrap:wrap; gap:<?= $compact ? 14 : 18 ?>px <?= $compact ? 22 : 28 ?>px; margin-top:<?= $compact ? 18 : 22 ?>px; }
  .cal-legend .leg { display:flex; align-items:center; gap:8px; font-size:17px; color:var(--snow); }
  .cal-legend .dot { width:12px; height:12px; border-radius:50%; flex-shrink:0;
                     box-shadow:0 0 0 2px rgba(255,255,255,.12); }
  .tev { display:flex; gap:14px; align-items:baseline; padding:10px 0; border-bottom:1px solid rgba(38,52,77,.55); }
  .tev:last-child { border-bottom:none; }
  .tev .who { font-size:16px; font-weight:600; letter-spacing:1px; text-transform:uppercase;
              min-width:48px; flex-shrink:0; }
  .tev .t { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 26 : 28 ?>px;
            min-width:110px; font-variant-numeric:tabular-nums; }
  .tev .s { font-size:<?= $compact ? 24 : 26 ?>px; line-height:1.25; }
  .free { font-size:<?= $compact ? 24 : 26 ?>px; color:var(--mist); padding:12px 0; }
  .setup { font-size:22px; color:var(--mist); line-height:1.55; }
  .setup code { background:var(--lake-night); padding:2px 8px; border-radius:6px; color:var(--snow); }
  .tomorrow { margin-top:auto; padding-top:18px; border-top:1px solid var(--hairline); }
  .tomorrow .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:10px; }
  .tomorrow .tev { padding:7px 0; border-bottom:none; }
  .tomorrow .tev .s { font-size:<?= $compact ? 20 : 22 ?>px; }

  .sky { grid-area:sky; height:100%; min-height:0; display:grid; gap:<?= $compact ? 14 : 18 ?>px;
         grid-template-columns:1fr 1fr;
         grid-template-rows:<?= SHOW_WEATHER ? ($skyBottomPanels > 0 ? 'minmax(0,1fr) minmax(0,1fr)' : 'minmax(0,1fr)') : ($skyBottomPanels > 0 ? 'minmax(0,1fr)' : 'none') ?>; }
  .sky.no-weather { grid-template-columns:1fr<?= $skyBottomPanels > 1 ? ' 1fr' : '' ?>; }
  .sky.no-headlines { grid-template-rows:<?= SHOW_WEATHER ? 'minmax(0,1fr)' : 'none' ?>; }
  .weather, .headlines { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
                      padding:<?= $compact ? 22 : 28 ?>px; min-height:0; overflow:hidden; }
  .weather { grid-column:1 / -1; display:grid; grid-template-rows:auto minmax(0,1fr); gap:<?= $compact ? 12 : 16 ?>px; }
  .weather .k, .headlines .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .weather-head { display:flex; align-items:baseline; justify-content:space-between; gap:16px; }
  .weather .place { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); letter-spacing:1px; }
  .weather-body { min-height:0; display:grid; grid-template-columns:minmax(240px,42%) 1fr; gap:<?= $compact ? 16 : 20 ?>px; }
  .weather-main { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; min-height:0; }
  .weather-main img { width:<?= $compact ? 120 : 150 ?>px; height:<?= $compact ? 120 : 150 ?>px; margin-bottom:<?= $compact ? 6 : 10 ?>px; }
  .weather-temp { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 96 : 118 ?>px; line-height:1; color:var(--beacon); }
  .weather-desc { font-size:<?= $compact ? 24 : 28 ?>px; color:var(--snow); margin-top:6px; text-transform:capitalize; }
  .weather-feels { font-size:<?= $compact ? 20 : 22 ?>px; color:var(--mist); margin-top:6px; }
  .weather-meta { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr; gap:<?= $compact ? 10 : 12 ?>px; min-height:0; }
  .weather-stat { background:rgba(12,20,34,.55); border:1px solid rgba(38,52,77,.85); border-radius:12px;
                  padding:<?= $compact ? 14 : 18 ?>px <?= $compact ? 16 : 22 ?>px; display:flex; flex-direction:column;
                  justify-content:space-between; min-height:0; }
  .weather-meta .lab { font-size:<?= $compact ? 13 : 14 ?>px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .stat-body { flex:1; display:flex; flex-direction:column; justify-content:space-evenly; min-height:0; margin-top:<?= $compact ? 8 : 10 ?>px; }
  .stat-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; }
  .stat-row .stat-k { font-size:<?= $compact ? 13 : 14 ?>px; letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); }
  .stat-row .stat-v { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 36 : 42 ?>px; color:var(--beacon); line-height:1; }
  .stat-row .stat-v small { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); font-weight:500; }
  .stat-solo { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 52 : 60 ?>px; color:var(--beacon); line-height:1; }
  .weather-meta .sub { font-size:<?= $compact ? 16 : 18 ?>px; color:var(--mist); margin-top:<?= $compact ? 4 : 6 ?>px; }
  .weather-meta .val { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 34 : 40 ?>px; color:var(--beacon); line-height:1.1; }
  .weather-meta .val small { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); font-weight:500; }
  .weather-empty { font-size:<?= $compact ? 20 : 22 ?>px; color:var(--mist); line-height:1.5; display:flex; align-items:center; justify-content:center; min-height:0; }
  .headlines { display:flex; flex-direction:column; min-height:0; }
  .headlines-wide { grid-column:1 / -1; }
  .headlines .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); flex-shrink:0; }
  .headline-list { flex:1; display:flex; flex-direction:column; justify-content:space-evenly; gap:<?= $compact ? 8 : 10 ?>px;
                   min-height:0; margin-top:<?= $compact ? 10 : 14 ?>px; overflow:hidden; }
  .headline-item { font-size:<?= $compact ? 19 : 22 ?>px; line-height:1.28; color:var(--snow);
                   padding:<?= $compact ? 8 : 10 ?>px 0; border-bottom:1px solid rgba(38,52,77,.55);
                   display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
  .headline-item:last-child { border-bottom:none; }
  .headline-empty { font-size:<?= $compact ? 18 : 20 ?>px; color:var(--mist); line-height:1.45; margin:auto 0; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <section class="agenda">
    <?php if ($showClock): ?><div id="clock">--:--<span> --</span></div><?php endif; ?>
    <div class="dateline" id="dateline">&nbsp;</div>
    <?php if (TITLE !== ''): ?><div class="board-title"><?= h(TITLE) ?></div><?php endif; ?>
    <?php if (SUBTITLE !== ''): ?><div class="board-sub"><?= h(SUBTITLE) ?></div><?php endif; ?>
    <?php if ($calLegend !== []): ?>
    <div class="cal-legend" aria-label="Calendar key">
      <?php foreach ($calLegend as $leg): ?>
      <span class="leg"><span class="dot" style="background:<?= h($leg['hex']) ?>"></span><?= h($leg['key']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="k">Today</div>
    <?php if (ICS_FEEDS === []): ?>
      <div class="setup">Add calendar feeds on the <strong>Calendar</strong> board in admin —
        iCal URLs or CalDAV with user/password when needed.</div>
    <?php elseif ($todayEvents !== []): ?>
      <?php foreach (array_slice($todayEvents, 0, MAX_TODAY) as $e):
        $hex = $e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''));
      ?>
      <div class="tev">
        <span class="who" style="color:<?= h($hex) ?>"><?= h($e['cal']) ?></span>
        <span class="t" style="color:<?= h($hex) ?>"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
        <span class="s"><?= h($e['summary']) ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (count($todayEvents) > MAX_TODAY): ?>
      <div class="free">+<?= count($todayEvents) - MAX_TODAY ?> more today</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="free">Nothing on the calendar — enjoy it.</div>
    <?php endif; ?>

    <?php if (SHOW_TOMORROW && $tomorrowEvents !== []): ?>
    <div class="tomorrow">
      <div class="k">Tomorrow</div>
      <?php foreach (array_slice($tomorrowEvents, 0, 4) as $e):
        $hex = $e['hex'] ?? calendar_color_hex((string)($e['color'] ?? ''));
      ?>
      <div class="tev">
        <span class="who" style="color:<?= h($hex) ?>"><?= h($e['cal']) ?></span>
        <span class="t" style="color:<?= h($hex) ?>"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
        <span class="s"><?= h($e['summary']) ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (count($tomorrowEvents) > 4): ?>
      <div class="free">+<?= count($tomorrowEvents) - 4 ?> more tomorrow</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <div class="sky<?= SHOW_WEATHER ? '' : ' no-weather' ?><?= $skyBottomPanels === 0 ? ' no-headlines' : '' ?>">
    <?php if (SHOW_WEATHER): ?>
    <section class="weather">
      <div class="weather-head">
        <div class="k">Weather</div>
        <div class="place"><?= h($LOC['place'] ?? 'Local') ?></div>
      </div>
      <?php if ($weather): ?>
      <div class="weather-body">
        <div class="weather-main">
          <img src="<?= h(weather_icon_url($weather['icon'], 4)) ?>" alt="">
          <div class="weather-temp"><?= (int)$weather['temp'] ?>°</div>
          <div class="weather-desc"><?= h($weather['desc']) ?></div>
          <?php if ($weather['feels'] !== (int)$weather['temp']): ?>
          <div class="weather-feels">Feels like <?= (int)$weather['feels'] ?>°</div>
          <?php endif; ?>
        </div>
        <div class="weather-meta">
          <div class="weather-stat">
            <div class="lab">Today</div>
            <div class="stat-body">
              <div class="stat-row">
                <span class="stat-k">Hi</span>
                <span class="stat-v"><?= $weather['hi'] !== null ? (int)$weather['hi'] . '°' : '—' ?></span>
              </div>
              <div class="stat-row">
                <span class="stat-k">Lo</span>
                <span class="stat-v"><?= $weather['lo'] !== null ? (int)$weather['lo'] . '°' : '—' ?></span>
              </div>
            </div>
          </div>
          <div class="weather-stat">
            <div class="lab">Precip chance</div>
            <div class="stat-body">
              <div class="stat-solo"><?= $weather['pop'] !== null ? (int)$weather['pop'] . '%' : '—' ?></div>
              <div class="sub"><?= $weather['humidity'] > 0 ? 'Humidity ' . (int)$weather['humidity'] . '%' : 'Today outlook' ?></div>
            </div>
          </div>
          <div class="weather-stat">
            <div class="lab">Wind</div>
            <div class="stat-body">
              <div class="stat-row">
                <span class="stat-k">Speed</span>
                <span class="stat-v"><?= (int)$weather['wind_mph'] ?> <small>mph</small></span>
              </div>
              <div class="stat-row">
                <span class="stat-k">From</span>
                <span class="stat-v"><?= h($weather['wind_dir']) ?></span>
              </div>
            </div>
          </div>
          <?php if ($weather['tomorrow_hi'] !== null): ?>
          <div class="weather-stat">
            <div class="lab">Tomorrow</div>
            <div class="stat-body">
              <div class="stat-row">
                <span class="stat-k">Hi</span>
                <span class="stat-v"><?= (int)$weather['tomorrow_hi'] ?>°</span>
              </div>
              <div class="stat-row">
                <span class="stat-k">Lo</span>
                <span class="stat-v"><?= $weather['tomorrow_lo'] !== null ? (int)$weather['tomorrow_lo'] . '°' : '—' ?></span>
              </div>
              <?php if ($weather['tomorrow_pop'] !== null && $weather['tomorrow_pop'] > 0): ?>
              <div class="sub"><?= (int)$weather['tomorrow_pop'] ?>% precip</div>
              <?php endif; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="weather-stat">
            <div class="lab">Humidity</div>
            <div class="stat-body">
              <div class="stat-solo"><?= (int)$weather['humidity'] ?>%</div>
              <div class="sub">Right now</div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="weather-empty">Set your OpenWeatherMap key on the <strong>Weather</strong> board to show conditions here.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($headlines1Active):
        glance_render_headlines(
            (string)$headlinePanel1['title'],
            $headlines1,
            !$headlines2Active,
            (string)$headlinePanel1['page_url'] !== ''
                ? 'No headlines from that page yet — check the URL or RSS fallback in Rotation kiosk settings.'
                : 'Set a page URL or RSS feed under <strong>Rotation → Kiosk settings</strong> or Today at a Glance defaults.'
        );
    endif; ?>
    <?php if ($headlines2Active):
        glance_render_headlines(
            (string)$headlinePanel2['title'],
            $headlines2,
            !$headlines1Active,
            'Pick an RSS feed key in <strong>Rotation → Kiosk settings</strong> (or Today at a Glance defaults).'
        );
    endif; ?>
  </div>

  <div class="stamp">Calendar · <?= SHOW_WEATHER ? 'Weather · ' : '' ?><?= ($headlines1Active || $headlines2Active) ? 'Headlines · ' : '' ?><?= h($LOC['place'] ?? 'local') ?><?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  function fmtDate() {
    const n = new Date();
    return n.toLocaleDateString(undefined, { weekday:'long', month:'long', day:'numeric' });
  }
  const dl = document.getElementById('dateline');
  if (dl) dl.textContent = fmtDate();
  <?php if ($showClock): ?>
  function tick() {
    const n = new Date();
    let h = n.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    document.getElementById('clock').innerHTML = h + ':' + String(n.getMinutes()).padStart(2, '0') + '<span> ' + ap + '</span>';
  }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(function () { location.reload(); }, <?= (int)RELOAD_SEC ?> * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
