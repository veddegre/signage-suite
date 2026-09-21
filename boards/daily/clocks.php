<?php
/**
 * WORLD CLOCKS — 1920×1080 signage
 *
 * Live analog + digital clocks for configured IANA timezones. No API.
 */

require_once dirname(__DIR__, 2) . '/lib/clocks_lib.php';
require_once dirname(__DIR__, 2) . '/lib/rotation_lib.php';

define('TITLE', cfg('clocks.TITLE', 'World Clocks'));
define('SUBTITLE', cfg('clocks.SUBTITLE', 'Local time around the world'));
define('TIMEZONE', clocks_home_timezone_name());
define('RELOAD_SEC', cfg('clocks.RELOAD_SEC', 0));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$embedded = isset($_GET['noticker']);
$screen = isset($_GET['screen']) ? rotation_normalize_screen_key((string)$_GET['screen']) : null;
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$showSeconds = clocks_show_seconds();
$showDate = clocks_show_date();
$showOffset = clocks_show_offset();
$showAnalog = clocks_show_analog();
$cities = clocks_wall_snapshot(null, null, $screen);
$count = count($cities);
$cols = clocks_grid_columns($count);
$invalid = clocks_invalid_timezone_names();
$homeNow = new DateTimeImmutable('now', clocks_timezone(TIMEZONE) ?? new DateTimeZone('America/Detroit'));
$h24 = signage_clock_24h($screen);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$facePx = $boardH < 1080
    ? ($count <= 2 ? 220 : ($count <= 4 ? 170 : 132))
    : ($count <= 2 ? 260 : ($count <= 4 ? 200 : 156));
$timePx = $boardH < 1080
    ? ($count <= 2 ? 72 : ($count <= 4 ? 52 : 40))
    : ($count <= 2 ? 86 : ($count <= 4 ? 60 : 46));
$cityPx = $boardH < 1080
    ? ($count <= 2 ? 42 : ($count <= 4 ? 32 : 26))
    : ($count <= 2 ? 48 : ($count <= 4 ? 36 : 28));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<?= signage_theme_fonts_head_html() ?>
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-rows: <?= $rowHead ?>px 1fr auto;
           grid-template-areas: "head" "main" "meta"; min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; gap:24px; min-width:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px;
             white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; flex-shrink:0; }

  .main { grid-area:main; min-height:0; display:grid;
          grid-template-columns:repeat(<?= (int)$cols ?>, minmax(0, 1fr));
          grid-auto-rows:1fr; gap:<?= $boardH < 1080 ? 14 : 18 ?>px; }

  .card { background:var(--harbor); border:1px solid var(--hairline); border-radius:18px;
          padding:<?= $boardH < 1080 ? '16px 18px' : '20px 22px' ?>;
          display:flex; flex-direction:column; align-items:center; justify-content:center;
          gap:<?= $boardH < 1080 ? 8 : 10 ?>px; min-height:0; position:relative; overflow:hidden; }
  .card.home { border-color:color-mix(in srgb, var(--beacon) 70%, var(--hairline));
               box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--beacon) 35%, transparent); }
  .card.night { background:color-mix(in srgb, var(--harbor) 82%, var(--lake-night)); }
  .card .city { font-family:'Big Shoulders Display'; font-weight:700; letter-spacing:1px;
                font-size:<?= (int)$cityPx ?>px; line-height:1.05; text-align:center;
                white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
  .card .time { font-family:'Big Shoulders Display'; font-weight:700;
                font-size:<?= (int)$timePx ?>px; line-height:1; font-variant-numeric:tabular-nums;
                color:var(--snow); letter-spacing:0.5px; }
  .card.night .time { color:color-mix(in srgb, var(--snow) 88%, var(--mist)); }
  .card .meta { display:flex; flex-wrap:wrap; align-items:center; justify-content:center;
                gap:8px 10px; font-size:<?= $boardH < 1080 ? 16 : 18 ?>px; color:var(--mist); }
  .card .meta .date { font-variant-numeric:tabular-nums; }
  .pill { display:inline-block; padding:3px 10px; border-radius:999px; letter-spacing:1.4px;
          text-transform:uppercase; font-size:<?= $boardH < 1080 ? 12 : 13 ?>px; font-weight:600;
          border:1px solid var(--hairline); background:var(--tile-bg); }
  .pill.home { color:var(--beacon); border-color:color-mix(in srgb, var(--beacon) 50%, var(--hairline)); }
  .pill.night { color:var(--mist); }
  .pill.day { color:var(--gold); }
  .pill.shift { color:var(--ok); }

  .face { width:<?= (int)$facePx ?>px; height:<?= (int)$facePx ?>px; flex:0 0 auto; }
  .face svg { width:100%; height:100%; display:block; }
  .face .ring { fill:color-mix(in srgb, var(--tile-bg) 88%, var(--lake-night));
                stroke:var(--hairline); stroke-width:1.6; }
  .card.home .face .ring { stroke:color-mix(in srgb, var(--beacon) 55%, var(--hairline)); }
  .face .tick { stroke:var(--mist); stroke-width:1.4; stroke-linecap:round; opacity:.7; }
  .face .tick.major { stroke:var(--snow); stroke-width:2.1; opacity:.9; }
  .face .hand { stroke-linecap:round; transform-origin:50px 50px; }
  .face .hand.hour { stroke:var(--snow); stroke-width:3.2; }
  .face .hand.min { stroke:var(--snow); stroke-width:2.2; }
  .face .hand.sec { stroke:var(--beacon); stroke-width:1.3; }
  .card.night .face .hand.hour, .card.night .face .hand.min { stroke:color-mix(in srgb, var(--snow) 80%, var(--mist)); }
  .face .hub { fill:var(--beacon); }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; text-align:center; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><?php if (SUBTITLE !== ''): ?><span class="sub"><?= h(SUBTITLE) ?></span><?php endif; ?></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($cities === []): ?>
  <section class="main" style="grid-template-columns:1fr">
    <div class="notcfg">No clocks to show. Add IANA timezone rows under admin → <strong>World Clocks</strong>.</div>
  </section>
  <?php else: ?>
  <section class="main" id="clocks">
    <?php foreach ($cities as $c):
      $hourDeg = (($c['hour'] % 12) * 30) + ($c['minute'] * 0.5);
      $minDeg = ($c['minute'] * 6) + ($c['second'] * 0.1);
      $secDeg = $c['second'] * 6;
    ?>
    <article class="card<?= !empty($c['home']) ? ' home' : '' ?><?= !empty($c['night']) ? ' night' : '' ?>"
             data-tz="<?= h((string)$c['tz']) ?>"
             data-home="<?= !empty($c['home']) ? '1' : '0' ?>">
      <?php if ($showAnalog): ?>
      <div class="face" aria-hidden="true">
        <svg viewBox="0 0 100 100">
          <circle class="ring" cx="50" cy="50" r="46"></circle>
          <?php for ($t = 0; $t < 12; $t++):
              $a = deg2rad($t * 30 - 90);
              $inner = ($t % 3 === 0) ? 34 : 37;
              $x1 = 50 + cos($a) * $inner;
              $y1 = 50 + sin($a) * $inner;
              $x2 = 50 + cos($a) * 42.5;
              $y2 = 50 + sin($a) * 42.5;
          ?>
          <line class="tick<?= $t % 3 === 0 ? ' major' : '' ?>"
                x1="<?= round($x1, 2) ?>" y1="<?= round($y1, 2) ?>"
                x2="<?= round($x2, 2) ?>" y2="<?= round($y2, 2) ?>"></line>
          <?php endfor; ?>
          <line class="hand hour" x1="50" y1="50" x2="50" y2="28"
                style="transform:rotate(<?= h((string)round($hourDeg, 2)) ?>deg)"></line>
          <line class="hand min" x1="50" y1="50" x2="50" y2="20"
                style="transform:rotate(<?= h((string)round($minDeg, 2)) ?>deg)"></line>
          <?php if ($showSeconds): ?>
          <line class="hand sec" x1="50" y1="54" x2="50" y2="16"
                style="transform:rotate(<?= h((string)round($secDeg, 2)) ?>deg)"></line>
          <?php endif; ?>
          <circle class="hub" cx="50" cy="50" r="2.8"></circle>
        </svg>
      </div>
      <?php endif; ?>
      <div class="city"><?= h((string)$c['label']) ?></div>
      <div class="time" data-time><?= h((string)$c['time']) ?></div>
      <div class="meta">
        <?php if ($showDate): ?><span class="date" data-date><?= h((string)$c['date']) ?></span><?php endif; ?>
        <?php if ($showOffset): ?>
        <span data-offset><?= h((string)$c['offset']) ?></span>
        <span data-abbr><?= h((string)$c['abbr']) ?></span>
        <?php endif; ?>
        <?php if (!empty($c['home'])): ?><span class="pill home">Home</span><?php endif; ?>
        <span class="pill <?= !empty($c['night']) ? 'night' : 'day' ?>" data-phase><?= !empty($c['night']) ? 'Night' : 'Day' ?></span>
        <?php if (($c['day_rel'] ?? 'today') !== 'today'): ?>
        <span class="pill shift" data-rel><?= h((string)$c['day_rel']) ?></span>
        <?php else: ?>
        <span class="pill shift" data-rel hidden>today</span>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    $count . ($count === 1 ? ' clock' : ' clocks'),
    TIMEZONE,
    $h24 ? '24h' : '12h',
    $invalid !== [] ? 'skipped: ' . implode(', ', $invalid) : '',
  ]))) ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  <?= signage_clock_tick_script('clock', TIMEZONE, $screen) ?>
  <?php endif; ?>
(function () {
  const cards = document.querySelectorAll('#clocks .card[data-tz]');
  if (!cards.length) return;
  const homeTz = <?= json_encode(TIMEZONE, JSON_UNESCAPED_SLASHES) ?>;
  const hour12 = <?= $h24 ? 'false' : 'true' ?>;
  const showSeconds = <?= $showSeconds ? 'true' : 'false' ?>;
  const showDate = <?= $showDate ? 'true' : 'false' ?>;
  const showOffset = <?= $showOffset ? 'true' : 'false' ?>;

  function parts(date, tz) {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: tz,
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      hour: hour12 ? 'numeric' : '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: hour12,
      hourCycle: hour12 ? 'h12' : 'h23',
      timeZoneName: 'shortOffset'
    });
    const out = { weekday:'', month:'', day:'', hour:0, minute:0, second:0, dayPeriod:'', offset:'' };
    fmt.formatToParts(date).forEach(function (p) {
      if (p.type === 'weekday') out.weekday = p.value;
      if (p.type === 'month') out.month = p.value;
      if (p.type === 'day') out.day = p.value;
      if (p.type === 'hour') out.hour = parseInt(p.value, 10) || 0;
      if (p.type === 'minute') out.minute = parseInt(p.value, 10) || 0;
      if (p.type === 'second') out.second = parseInt(p.value, 10) || 0;
      if (p.type === 'dayPeriod') out.dayPeriod = p.value;
      if (p.type === 'timeZoneName') out.offset = p.value;
    });
    return out;
  }

  function hour24(p) {
    let h = p.hour;
    const ap = (p.dayPeriod || '').toLowerCase();
    if (!hour12) return h;
    if (ap === 'pm' && h < 12) h += 12;
    if (ap === 'am' && h === 12) h = 0;
    return h;
  }

  function ymd(date, tz) {
    const f = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year:'numeric', month:'2-digit', day:'2-digit' });
    return f.format(date);
  }

  function utcLabel(offset) {
    const m = String(offset || '').match(/GMT([+-])(\d{1,2})(?::?(\d{2}))?/i)
           || String(offset || '').match(/UTC([+-])(\d{1,2})(?::?(\d{2}))?/i);
    if (!m) return offset || '';
    const sign = m[1] === '-' ? '\u2212' : '+';
    const hours = parseInt(m[2], 10);
    const mins = m[3] ? parseInt(m[3], 10) : 0;
    return mins ? ('UTC' + sign + hours + ':' + String(mins).padStart(2, '0')) : ('UTC' + sign + hours);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function formatTime(p) {
    if (hour12) {
      const h = p.hour === 0 ? 12 : p.hour;
      const body = showSeconds ? (h + ':' + pad(p.minute) + ':' + pad(p.second)) : (h + ':' + pad(p.minute));
      return body + (p.dayPeriod ? (' ' + p.dayPeriod.toUpperCase()) : '');
    }
    const h = pad(hour24(p));
    return showSeconds ? (h + ':' + pad(p.minute) + ':' + pad(p.second)) : (h + ':' + pad(p.minute));
  }

  function setHand(card, cls, deg) {
    const el = card.querySelector('.hand.' + cls);
    if (el) el.style.transform = 'rotate(' + deg + 'deg)';
  }

  function tick() {
    const now = new Date();
    const homeDay = ymd(now, homeTz);
    cards.forEach(function (card) {
      const tz = card.getAttribute('data-tz');
      if (!tz) return;
      const p = parts(now, tz);
      const h24v = hour24(p);
      const timeEl = card.querySelector('[data-time]');
      if (timeEl) timeEl.textContent = formatTime(p);
      if (showDate) {
        const dateEl = card.querySelector('[data-date]');
        if (dateEl) dateEl.textContent = p.weekday + ' ' + p.day + ' ' + p.month;
      }
      if (showOffset) {
        const offEl = card.querySelector('[data-offset]');
        if (offEl) offEl.textContent = utcLabel(p.offset);
      }
      const night = h24v < 6 || h24v >= 20;
      card.classList.toggle('night', night);
      const phase = card.querySelector('[data-phase]');
      if (phase) {
        phase.textContent = night ? 'Night' : 'Day';
        phase.classList.toggle('night', night);
        phase.classList.toggle('day', !night);
      }
      const cityDay = ymd(now, tz);
      const rel = cityDay < homeDay ? 'yesterday' : (cityDay > homeDay ? 'tomorrow' : 'today');
      const relEl = card.querySelector('[data-rel]');
      if (relEl) {
        relEl.textContent = rel;
        relEl.hidden = rel === 'today';
      }
      const hourDeg = ((h24v % 12) * 30) + (p.minute * 0.5);
      const minDeg = (p.minute * 6) + (p.second * 0.1);
      const secDeg = p.second * 6;
      setHand(card, 'hour', hourDeg);
      setHand(card, 'min', minDeg);
      setHand(card, 'sec', secDeg);
    });
  }
  tick();
  setInterval(tick, 1000);
})();
  <?php if (!$embedded && RELOAD_SEC > 0): ?>
  setTimeout(() => location.reload(), <?= (int)RELOAD_SEC * 1000 ?>);
  <?php endif; ?>
</script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
