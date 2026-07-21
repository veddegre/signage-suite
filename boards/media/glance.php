<?php
/**
 * TODAY AT A GLANCE — 1920×1080 signage
 * Today's calendar (from Calendar board feeds) plus moon phase and sun times.
 *
 * Setup: configure ICS feeds on the Calendar board (calendar.ICS_FEEDS).
 * Location for sun/moon follows the display's rotation location (same as Weather).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/security_lib.php';
require_once dirname(__DIR__, 2) . '/lib/calendar_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';
require_once dirname(__DIR__, 2) . '/lib/screen_scope_lib.php';
require_once dirname(__DIR__, 2) . '/lib/weather_lib.php';

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

$sun = date_sun_info(time(), $lat, $lon);
$synodic = 29.530588853;
$daysSinceNew = fmod((time() - 947182440) / 86400, $synodic);
if ($daysSinceNew < 0) {
    $daysSinceNew += $synodic;
}
$phaseFrac = $daysSinceNew / $synodic;
$illum = (1 - cos(2 * M_PI * $phaseFrac)) / 2;
$phaseNames = [
    [0.0325, 'New Moon'], [0.2175, 'Waxing Crescent'], [0.2825, 'First Quarter'],
    [0.4675, 'Waxing Gibbous'], [0.5325, 'Full Moon'], [0.7175, 'Waning Gibbous'],
    [0.7825, 'Last Quarter'], [0.9675, 'Waning Crescent'], [1.01, 'New Moon'],
];
$phaseName = 'Moon';
foreach ($phaseNames as [$lim, $name]) {
    if ($phaseFrac <= $lim) {
        $phaseName = $name;
        break;
    }
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
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --sky:#7ec8ff; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:<?= $compact ? 22 : 28 ?>px <?= $compact ? 26 : 32 ?>px;
           display:grid; gap:<?= $compact ? 18 : 24 ?>px;
           grid-template-columns: 1fr <?= $compact ? 520 : 580 ?>px;
           grid-template-rows: auto minmax(0,1fr) auto;
           grid-template-areas: "head sky" "agenda sky" "meta meta"; }

  .head { grid-area:head; display:flex; align-items:flex-end; justify-content:space-between; gap:24px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 56 : 64 ?>px; line-height:1; }
  .head .sub { font-size:<?= $compact ? 22 : 24 ?>px; color:var(--mist); margin-top:6px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 72 : 88 ?>px; line-height:1; text-align:right; }
  #clock span { font-size:<?= $compact ? 30 : 36 ?>px; color:var(--mist); }
  .dateline { font-size:<?= $compact ? 22 : 26 ?>px; color:var(--mist); text-align:right; margin-top:4px; }

  .agenda { grid-area:agenda; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
            padding:<?= $compact ? 24 : 32 ?>px <?= $compact ? 28 : 36 ?>px; min-height:0; overflow:hidden;
            display:flex; flex-direction:column; }
  .agenda .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:14px; }
  .cal-legend { display:flex; flex-wrap:wrap; gap:14px 22px; margin-bottom:18px; }
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
  .tomorrow .k { margin-bottom:10px; }
  .tomorrow .tev { padding:7px 0; border-bottom:none; }
  .tomorrow .tev .s { font-size:<?= $compact ? 20 : 22 ?>px; }

  .sky { grid-area:sky; display:flex; flex-direction:column; gap:<?= $compact ? 14 : 18 ?>px; min-height:0; }
  .weather, .moon, .suntimes { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
                      padding:<?= $compact ? 22 : 28 ?>px; }
  .weather .k, .moon .k, .suntimes .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .weather .place { font-size:17px; color:var(--mist); margin-top:4px; }
  .weather-main { display:flex; align-items:center; gap:16px; margin-top:14px; }
  .weather-main img { width:<?= $compact ? 72 : 84 ?>px; height:<?= $compact ? 72 : 84 ?>px; }
  .weather-temp { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 64 : 72 ?>px; line-height:1; }
  .weather-desc { font-size:<?= $compact ? 22 : 24 ?>px; color:var(--snow); margin-top:4px; text-transform:capitalize; }
  .weather-meta { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; margin-top:16px; }
  .weather-meta .lab { font-size:14px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:4px; }
  .weather-meta .val { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 28 : 32 ?>px; color:var(--beacon); }
  .weather-meta .val small { font-size:18px; color:var(--mist); font-weight:500; }
  .weather-empty { font-size:18px; color:var(--mist); line-height:1.5; margin-top:12px; }
  .moon { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; }
  .moon .k { align-self:flex-start; }
  .moon svg { width:<?= $compact ? 160 : 200 ?>px; height:<?= $compact ? 160 : 200 ?>px; margin:<?= $compact ? 10 : 16 ?>px 0 8px; }
  .moon .name { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 40 : 46 ?>px; }
  .moon .pct { font-size:20px; color:var(--mist); margin-top:4px; }
  .suntimes { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; }
  .suntimes .cell .lab { font-size:15px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:6px; }
  .suntimes .cell .val { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 36 : 42 ?>px; color:var(--beacon); }
  .suntimes .cell .note { font-size:17px; color:var(--mist); margin-top:4px; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <header class="head">
    <div>
      <h1><?= h(TITLE) ?></h1>
      <?php if (SUBTITLE !== ''): ?><div class="sub"><?= h(SUBTITLE) ?></div><?php endif; ?>
    </div>
    <div>
      <?php if ($showClock): ?><div id="clock">--:--<span> --</span></div><?php endif; ?>
      <div class="dateline" id="dateline">&nbsp;</div>
    </div>
  </header>

  <section class="agenda">
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

  <div class="sky">
    <?php if (SHOW_WEATHER): ?>
    <section class="weather">
      <div class="k">Weather</div>
      <?php if ($weather): ?>
      <div class="place"><?= h($LOC['place'] ?? 'Local') ?></div>
      <div class="weather-main">
        <img src="<?= h(weather_icon_url($weather['icon'], 2)) ?>" alt="">
        <div>
          <div class="weather-temp"><?= (int)$weather['temp'] ?>°</div>
          <div class="weather-desc"><?= h($weather['desc']) ?></div>
        </div>
      </div>
      <div class="weather-meta">
        <div>
          <div class="lab">Today</div>
          <div class="val"><?= $weather['hi'] !== null ? 'Hi ' . (int)$weather['hi'] . '°' : '—' ?><?= $weather['lo'] !== null ? ' · Lo ' . (int)$weather['lo'] . '°' : '' ?></div>
        </div>
        <div>
          <div class="lab">Precip chance</div>
          <div class="val"><?= $weather['pop'] !== null ? (int)$weather['pop'] . '%' : '—' ?></div>
        </div>
        <div>
          <div class="lab">Wind</div>
          <div class="val"><?= (int)$weather['wind_mph'] ?> <small><?= h($weather['wind_dir']) ?></small></div>
        </div>
        <?php if ($weather['tomorrow_hi'] !== null): ?>
        <div>
          <div class="lab">Tomorrow</div>
          <div class="val"><?= (int)$weather['tomorrow_hi'] ?>°<?= $weather['tomorrow_pop'] !== null && $weather['tomorrow_pop'] > 0 ? ' · ' . (int)$weather['tomorrow_pop'] . '% precip' : '' ?></div>
        </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="weather-empty">Set your OpenWeatherMap key on the <strong>Weather</strong> board to show conditions here.</div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="suntimes">
      <div class="k" style="grid-column:1/-1">Sun</div>
      <div class="cell">
        <div class="lab">Sunrise</div>
        <div class="val"><?= $sun['sunrise'] ? date('g:i A', $sun['sunrise']) : '—' ?></div>
        <div class="note">Civil twilight <?= $sun['civil_twilight_begin'] ? date('g:i A', $sun['civil_twilight_begin']) : '—' ?></div>
      </div>
      <div class="cell">
        <div class="lab">Sunset</div>
        <div class="val"><?= $sun['sunset'] ? date('g:i A', $sun['sunset']) : '—' ?></div>
        <div class="note">Twilight ends <?= $sun['civil_twilight_end'] ? date('g:i A', $sun['civil_twilight_end']) : '—' ?></div>
      </div>
    </section>

    <section class="moon">
      <div class="k">Moon</div>
      <svg viewBox="0 0 100 100" aria-hidden="true">
        <?php
          $r = 46;
          $k = cos(2 * M_PI * $phaseFrac);
          $waxing = $phaseFrac < 0.5;
          $lit = 'var(--snow)';
          $dark = '#1b2840';
        ?>
        <circle cx="50" cy="50" r="<?= $r ?>" fill="<?= $dark ?>"/>
        <path d="M 50 4
                 A <?= $r ?> <?= $r ?> 0 0 <?= $waxing ? 1 : 0 ?> 50 96
                 A <?= abs($k) * $r ?> <?= $r ?> 0 0 <?= ($k < 0 ? ($waxing ? 1 : 0) : ($waxing ? 0 : 1)) ?> 50 4 Z"
              fill="<?= $lit ?>"/>
        <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="var(--hairline)" stroke-width="1.5"/>
      </svg>
      <div class="name"><?= h($phaseName) ?></div>
      <div class="pct"><?= (int)round($illum * 100) ?>% illuminated</div>
    </section>
  </div>

  <div class="stamp">Calendar · <?= SHOW_WEATHER ? 'Weather · ' : '' ?><?= h($LOC['place'] ?? 'local') ?><?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
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
