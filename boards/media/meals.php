<?php
/**
 * MEAL CALENDAR — 1920×1080 signage
 * Rolling 7-day meal plan from admin weekly rows + optional date overrides.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/meals_lib.php';
require_once dirname(__DIR__, 2) . '/lib/users_lib.php';

define('TITLE', cfg('meals.TITLE', 'Meal calendar'));
define('SUBTITLE', cfg('meals.SUBTITLE', 'This week'));
define('SHOW_BREAKFAST', (bool)cfg('meals.SHOW_BREAKFAST', false));
define('SHOW_LUNCH', (bool)cfg('meals.SHOW_LUNCH', false));
define('RELOAD_SEC', max(60, (int)cfg('meals.RELOAD_SEC', 900)));
define('TIMEZONE', cfg('meals.TIMEZONE', cfg('calendar.TIMEZONE', 'America/Detroit')));

$weeklyRows = cfg('meals.WEEKLY_PLAN', []);
if (!is_array($weeklyRows)) {
    $weeklyRows = [];
}
$overrideRows = cfg('meals.DATE_OVERRIDES', []);
if (!is_array($overrideRows)) {
    $overrideRows = [];
}
$weeklyRows = admin_filter_list_for_display($weeklyRows);
$overrideRows = admin_filter_list_for_display($overrideRows);

date_default_timezone_set(TIMEZONE);
$frameH = signage_frame_height();
$showClock = signage_show_clock();

$days = meals_week_window($weeklyRows, $overrideRows);
$todayMeals = $days[0]['meals'] ?? ['breakfast' => '', 'lunch' => '', 'dinner' => '', 'note' => ''];
$hasPlan = false;
foreach ($days as $day) {
    if (meals_day_has_content($day['meals'], SHOW_BREAKFAST, SHOW_LUNCH)) {
        $hasPlan = true;
        break;
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function meals_slot_label(string $slot): string
{
    return match ($slot) {
        'breakfast' => 'Breakfast',
        'lunch' => 'Lunch',
        default => 'Dinner',
    };
}

/** @param array<string,mixed> $meals */
function meals_render_slots(array $meals, bool $compact, bool $hero = false): void
{
    $slots = ['dinner'];
    if (SHOW_LUNCH) {
        array_unshift($slots, 'lunch');
    }
    if (SHOW_BREAKFAST) {
        array_unshift($slots, 'breakfast');
    }
    $shown = 0;
    foreach ($slots as $slot) {
        $text = trim((string)($meals[$slot] ?? ''));
        if ($text === '') {
            continue;
        }
        $shown++;
        echo '<div class="slot' . ($hero ? ' hero' : '') . '">';
        if (count($slots) > 1 || $hero) {
            echo '<div class="slot-k">' . h(meals_slot_label($slot)) . '</div>';
        }
        echo '<div class="slot-v">' . h($text) . '</div>';
        echo '</div>';
    }
    $note = trim((string)($meals['note'] ?? ''));
    if ($note !== '') {
        echo '<div class="note' . ($hero ? ' hero-note' : '') . '">' . h($note) . '</div>';
    }
    if ($shown === 0 && $note === '' && !$hero) {
        echo '<div class="nothing">—</div>';
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
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:<?= $compact ? 22 : 28 ?>px <?= $compact ? 26 : 32 ?>px;
           display:grid; gap:<?= $compact ? 18 : 24 ?>px;
           grid-template-columns: <?= $compact ? 520 : 580 ?>px 1fr;
           grid-template-rows: auto minmax(0,1fr) auto;
           grid-template-areas: "head head" "today week" "meta meta"; }

  .head { grid-area:head; display:flex; align-items:flex-end; justify-content:space-between; gap:24px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 56 : 64 ?>px; line-height:1; }
  .head .sub { font-size:<?= $compact ? 22 : 24 ?>px; color:var(--mist); margin-top:6px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 72 : 88 ?>px; line-height:1; text-align:right; }
  #clock span { font-size:<?= $compact ? 30 : 36 ?>px; color:var(--mist); }
  .dateline { font-size:<?= $compact ? 22 : 26 ?>px; color:var(--mist); text-align:right; margin-top:4px; }

  .today { grid-area:today; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $compact ? 28 : 36 ?>px; display:flex; flex-direction:column; min-height:0; }
  .today .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:12px; }
  .today .when { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 40 : 46 ?>px;
                  color:var(--beacon); margin-bottom:16px; }
  .slot { margin-bottom:14px; }
  .slot.hero .slot-k { font-size:16px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); margin-bottom:4px; }
  .slot.hero .slot-v { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $compact ? 44 : 52 ?>px; line-height:1.08; }
  .note, .hero-note { font-size:<?= $compact ? 22 : 24 ?>px; color:var(--seafoam); line-height:1.35; margin-top:8px; }
  .empty, .setup { font-size:<?= $compact ? 24 : 26 ?>px; color:var(--mist); line-height:1.5; }
  .setup code { background:var(--lake-night); padding:2px 8px; border-radius:6px; color:var(--snow); }

  .week { grid-area:week; display:grid; grid-template-columns:repeat(6,1fr); gap:16px; min-height:0; }
  .day { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
         padding:<?= $compact ? 16 : 18 ?>px; overflow:hidden; display:flex; flex-direction:column; min-height:0; }
  .day.is-today { border-color:var(--beacon); box-shadow:0 0 0 1px rgba(255,179,71,.25) inset; }
  .day .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $compact ? 30 : 34 ?>px;
            letter-spacing:1px; text-transform:uppercase; }
  .day .d { font-size:<?= $compact ? 17 : 19 ?>px; color:var(--mist); margin-bottom:12px; }
  .day .slot { margin-bottom:10px; }
  .day .slot-k { font-size:13px; letter-spacing:1.5px; text-transform:uppercase; color:var(--mist); margin-bottom:2px; }
  .day .slot-v { font-size:<?= $compact ? 20 : 22 ?>px; line-height:1.25; }
  .day .note { font-size:<?= $compact ? 16 : 17 ?>px; color:var(--seafoam); margin-top:auto; padding-top:8px; }
  .day .nothing { font-size:18px; color:var(--mist); opacity:.55; margin-top:auto; }
  .day .tag { align-self:flex-start; font-size:12px; letter-spacing:1px; text-transform:uppercase;
              color:var(--beacon); border:1px solid rgba(255,179,71,.35); border-radius:999px;
              padding:2px 8px; margin-bottom:8px; }
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

  <section class="today">
    <div class="k"><?= (SHOW_BREAKFAST || SHOW_LUNCH) ? 'Today' : 'Tonight' ?></div>
    <div class="when"><?= h(date('l, M j')) ?></div>
    <?php if (!$hasPlan): ?>
      <div class="setup">Add a <strong>Weekly plan</strong> in admin → <strong>Meal calendar</strong>.
        One row per weekday (Mon–Sun). Use <strong>Date overrides</strong> for holidays or swaps.</div>
    <?php elseif (meals_day_has_content($todayMeals, SHOW_BREAKFAST, SHOW_LUNCH)): ?>
      <?php meals_render_slots($todayMeals, $compact, true); ?>
    <?php else: ?>
      <div class="empty">Nothing planned for today yet.</div>
    <?php endif; ?>
  </section>

  <section class="week">
    <?php foreach (array_slice($days, 1) as $day): ?>
    <div class="day<?= !empty($day['meals']['override']) ? ' has-override' : '' ?>">
      <?php if (!empty($day['meals']['override'])): ?><div class="tag">Override</div><?php endif; ?>
      <div class="n"><?= h($day['label']) ?></div>
      <div class="d"><?= h(date('M j', $day['ts'])) ?></div>
      <?php meals_render_slots($day['meals'], $compact, false); ?>
    </div>
    <?php endforeach; ?>
  </section>

  <div class="stamp">Weekly plan<?= SHOW_LUNCH ? ' · lunch' : '' ?><?= SHOW_BREAKFAST ? ' · breakfast' : '' ?></div>
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
