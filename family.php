<?php
/**
 * FAMILY BOARD — 1920×1080 signage
 * Today + the week ahead from one or more ICS calendar feeds, trash/recycle
 * day, and countdowns to dates that matter.
 *
 * Setup:
 *   ICS_FEEDS — secret iCal URLs (Google Calendar: Settings → "Secret address
 *     in iCal format"). Add as many as you like; each gets a color.
 *   TRASH_WEEKDAY — pickup day. RECYCLE_ANCHOR — any date recycling was
 *     collected, used to compute the every-other-week cadence ('' to disable).
 *   COUNTDOWNS — [label => YYYY-MM-DD].
 *
 * Recurring events: DAILY, WEEKLY (BYDAY), MONTHLY (BYMONTHDAY), and YEARLY
 * rules are expanded, with INTERVAL/UNTIL/EXDATE honored. Exotic RRULEs beyond
 * that are shown only on their original date.
 */

require_once __DIR__ . '/config.php';

define('ICS_FEEDS', cfg('family.ICS_FEEDS', [


]));
define('TRASH_WEEKDAY', cfg('family.TRASH_WEEKDAY', 'Tuesday'));
define('RECYCLE_ANCHOR', cfg('family.RECYCLE_ANCHOR', '2026-01-06'));
define('COUNTDOWNS', cfg('family.COUNTDOWNS', [

]));
define('TIMEZONE', cfg('family.TIMEZONE', 'America/Detroit'));
const CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('family.CACHE_TTL', 600));

date_default_timezone_set(TIMEZONE);
$GLOBALS['diag'] = [];

function cached_get(string $url, string $key): ?string
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.dat";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) return (string)file_get_contents($f);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'HomeSignage/1.0']);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); return $body; }
    $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    return is_file($f) ? (string)file_get_contents($f) : null;
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── Minimal ICS parsing ──────────────────────────────────────────────────────
function ics_unfold(string $ics): array
{
    $lines = preg_split('/\R/', $ics);
    $out = [];
    foreach ($lines as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $out) {
            $out[count($out) - 1] .= substr($line, 1);
        } else {
            $out[] = $line;
        }
    }
    return $out;
}

/** Parse a DTSTART/DTEND value (+params) into [unix_ts, all_day]. */
function ics_time(string $params, string $value): ?array
{
    if (stripos($params, 'VALUE=DATE') !== false || preg_match('/^\d{8}$/', $value)) {
        $t = DateTime::createFromFormat('Ymd', substr($value, 0, 8), new DateTimeZone(TIMEZONE));
        return $t ? [$t->setTime(0, 0)->getTimestamp(), true] : null;
    }
    $tz = new DateTimeZone(TIMEZONE);
    if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
        try { $tz = new DateTimeZone($m[1]); } catch (Throwable $e) {}
    }
    if (str_ends_with($value, 'Z')) {
        $t = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
    } else {
        $t = DateTime::createFromFormat('Ymd\THis', $value, $tz);
    }
    return $t ? [$t->getTimestamp(), false] : null;
}

function parse_rrule(string $r): array
{
    $out = [];
    foreach (explode(';', $r) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) $out[strtoupper($kv[0])] = strtoupper($kv[1]);
    }
    return $out;
}

/** Expand one VEVENT into instances inside [winStart, winEnd]. */
function expand_event(array $ev, int $winStart, int $winEnd): array
{
    $out = [];
    $start = $ev['start']; $allDay = $ev['all_day'];
    $push = function (int $ts) use (&$out, $ev, $allDay) {
        if (in_array(date('Ymd', $ts), $ev['exdates'], true)) return;
        $out[] = ['ts' => $ts, 'all_day' => $allDay, 'summary' => $ev['summary'],
                  'cal' => $ev['cal'], 'color' => $ev['color']];
    };

    if (!$ev['rrule']) {
        if ($start >= $winStart && $start <= $winEnd) $push($start);
        return $out;
    }

    $r = $ev['rrule'];
    $freq     = $r['FREQ'] ?? '';
    $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $until    = isset($r['UNTIL']) ? (ics_time('', $r['UNTIL'])[0] ?? PHP_INT_MAX) : PHP_INT_MAX;
    $dayMap   = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
    $byday    = [];
    foreach (explode(',', $r['BYDAY'] ?? '') as $d) {
        $d = preg_replace('/^[+-]?\d+/', '', trim($d));    // ignore ordinals like 2TU
        if (isset($dayMap[$d])) $byday[] = $dayMap[$d];
    }

    // Walk each day in the window and test membership — simple and safe.
    $tod = $allDay ? 0 : ($start - strtotime('today', $start));   // seconds into day
    for ($day = strtotime('today', $winStart); $day <= $winEnd; $day += 86400) {
        $ts = $day + $tod;
        if ($ts < $start || $ts > $until || $ts < $winStart || $ts > $winEnd) continue;
        $match = false;
        switch ($freq) {
            case 'DAILY':
                $match = ((int)floor(($day - strtotime('today', $start)) / 86400)) % $interval === 0;
                break;
            case 'WEEKLY':
                $weeks = (int)floor(($day - strtotime('monday this week', $start)) / 604800);
                $dow   = (int)date('N', $day);
                $days  = $byday ?: [(int)date('N', $start)];
                $match = $weeks % $interval === 0 && in_array($dow, $days, true);
                break;
            case 'MONTHLY':
                $dom   = (int)($r['BYMONTHDAY'] ?? date('j', $start));
                $months = ((int)date('Y', $day) * 12 + (int)date('n', $day))
                        - ((int)date('Y', $start) * 12 + (int)date('n', $start));
                $match = (int)date('j', $day) === $dom && $months % $interval === 0;
                break;
            case 'YEARLY':
                $match = date('md', $day) === date('md', $start);
                break;
        }
        if ($match) $push($ts);
    }
    return $out;
}

// ── Gather events for today + 6 days ────────────────────────────────────────
$winStart = strtotime('today');
$winEnd   = strtotime('today +7 days') - 1;
$events   = [];

foreach (ICS_FEEDS as $i => $feed) {
    $raw = cached_get($feed['url'], 'ics_' . $i);
    if ($raw === null) continue;
    $lines = ics_unfold($raw);
    $cur = null;
    foreach ($lines as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $cur = ['start'=>null,'all_day'=>false,'summary'=>'','rrule'=>null,'exdates'=>[],
                    'cal'=>$feed['name'],'color'=>$feed['color']];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($cur && $cur['start'] !== null) {
                foreach (expand_event($cur, $winStart, $winEnd) as $inst) $events[] = $inst;
            }
            $cur = null; continue;
        }
        if ($cur === null) continue;
        $sep = strpos($line, ':');
        if ($sep === false) continue;
        $left = substr($line, 0, $sep); $value = substr($line, $sep + 1);
        $parts = explode(';', $left, 2);
        $prop = strtoupper($parts[0]); $params = $parts[1] ?? '';
        switch ($prop) {
            case 'DTSTART':
                $t = ics_time($params, $value);
                if ($t) { $cur['start'] = $t[0]; $cur['all_day'] = $t[1]; }
                break;
            case 'SUMMARY':
                $cur['summary'] = str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $value);
                break;
            case 'RRULE':
                $cur['rrule'] = parse_rrule($value);
                break;
            case 'EXDATE':
                foreach (explode(',', $value) as $x) $cur['exdates'][] = substr(trim($x), 0, 8);
                break;
        }
    }
}
usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);

// Bucket by day
$days = [];
for ($d = 0; $d < 7; $d++) {
    $key = date('Y-m-d', strtotime("+$d day", $winStart));
    $days[$key] = [];
}
foreach ($events as $e) {
    $key = date('Y-m-d', $e['ts']);
    if (isset($days[$key])) $days[$key][] = $e;
}

// ── Trash & recycling ────────────────────────────────────────────────────────
$trashNext   = strtotime('this ' . TRASH_WEEKDAY, $winStart);
if (date('l') === TRASH_WEEKDAY) $trashNext = $winStart;
$daysToTrash = (int)floor(($trashNext - $winStart) / 86400);
$recycleWeek = false;
if (RECYCLE_ANCHOR !== '') {
    $weeks = (int)floor(($trashNext - strtotime(RECYCLE_ANCHOR)) / 604800);
    $recycleWeek = $weeks % 2 === 0;
}
$trashLabel = $daysToTrash === 0 ? 'TODAY' : ($daysToTrash === 1 ? 'TOMORROW' : date('l', $trashNext));

// Countdowns
$counts = [];
foreach (COUNTDOWNS as $label => $date) {
    $d = (int)ceil((strtotime($date) - time()) / 86400);
    if ($d >= 0) $counts[] = [$label, $d];
}
usort($counts, fn($a, $b) => $a[1] <=> $b[1]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Family Board</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:1080px; padding:28px 32px; display:grid; gap:24px;
           grid-template-columns: 600px 1fr; grid-template-rows: 1fr 150px;
           grid-template-areas: "today week" "strip strip"; }

  .today { grid-area:today; background:var(--harbor); border:1px solid var(--hairline);
           border-radius:14px; padding:38px 42px; display:flex; flex-direction:column; min-height:0; }
  #clock { font-family:'Big Shoulders Display'; font-weight:700; font-size:110px; line-height:1; }
  #clock span { font-size:44px; color:var(--mist); }
  .dateline { font-size:30px; color:var(--mist); margin-top:6px; }
  .today .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist);
              margin:30px 0 8px; border-top:1px solid var(--hairline); padding-top:24px; }
  .tev { display:flex; gap:16px; align-items:baseline; padding:11px 0; }
  .tev .t { font-family:'Big Shoulders Display'; font-weight:600; font-size:30px; min-width:120px;
            color:var(--beacon); font-variant-numeric:tabular-nums; }
  .tev .s { font-size:28px; }
  .free { font-size:28px; color:var(--mist); padding:14px 0; }

  .week { grid-area:week; display:grid; grid-template-columns:repeat(6,1fr); gap:16px; min-height:0; }
  .day { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
         padding:18px; overflow:hidden; display:flex; flex-direction:column; }
  .day .n { font-family:'Big Shoulders Display'; font-weight:600; font-size:34px;
            letter-spacing:1px; text-transform:uppercase; }
  .day .d { font-size:19px; color:var(--mist); margin-bottom:12px; }
  .ev { font-size:20px; line-height:1.3; padding:7px 0 7px 14px; border-left:4px solid var(--beacon);
        margin-bottom:8px; overflow:hidden; }
  .ev .et { color:var(--mist); font-size:17px; display:block; }
  .more { font-size:18px; color:var(--mist); margin-top:auto; }
  .nothing { font-size:19px; color:var(--mist); opacity:.6; }

  .strip { grid-area:strip; display:flex; gap:24px; }
  .chip { flex:1; background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
          padding:20px 28px; display:flex; align-items:center; justify-content:space-between; }
  .chip .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist); }
  .chip .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:50px; }
  .chip .v small { font-size:26px; color:var(--mist); font-weight:600; }
  .chip.trash .v { color:var(--beacon); }
  .setup { font-size:24px; color:var(--mist); line-height:1.6; }
  .setup code { background:var(--lake-night); padding:2px 8px; border-radius:6px; color:var(--snow); }
  .stamp { position:absolute; bottom:6px; right:36px; font-size:15px; color:var(--mist); opacity:.7; }
</style>
</head>
<body>
<div class="board">
  <section class="today">
    <div id="clock">--:--<span> --</span></div>
    <div class="dateline" id="dateline">&nbsp;</div>
    <div class="k">Today</div>
    <?php $todayKey = date('Y-m-d');
    if (ICS_FEEDS === []) : ?>
      <div class="setup">Add calendar feeds to <code>ICS_FEEDS</code> — in Google Calendar:
        Settings &rarr; your calendar &rarr; <em>Secret address in iCal format</em>.</div>
    <?php elseif ($days[$todayKey]): foreach (array_slice($days[$todayKey], 0, 7) as $e): ?>
      <div class="tev">
        <span class="t" style="color:<?= h($e['color']) ?>"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
        <span class="s"><?= h($e['summary']) ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="free">Nothing on the calendar — enjoy it.</div>
    <?php endif; ?>
  </section>

  <section class="week">
    <?php $keys = array_keys($days);
    foreach (array_slice($keys, 1) as $key): $list = $days[$key]; $ts = strtotime($key); ?>
      <div class="day">
        <div class="n"><?= date('D', $ts) ?></div>
        <div class="d"><?= date('M j', $ts) ?></div>
        <?php foreach (array_slice($list, 0, 4) as $e): ?>
          <div class="ev" style="border-color:<?= h($e['color']) ?>">
            <?= h($e['summary']) ?>
            <span class="et"><?= $e['all_day'] ? 'All day' : date('g:i A', $e['ts']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (count($list) > 4): ?><div class="more">+<?= count($list) - 4 ?> more</div><?php endif; ?>
        <?php if (!$list): ?><div class="nothing">—</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="strip">
    <div class="chip trash">
      <span class="k"><?= $recycleWeek ? 'Trash + Recycling' : 'Trash' ?></span>
      <span class="v"><?= h($trashLabel) ?></span>
    </div>
    <?php foreach (array_slice($counts, 0, 3) as $c): ?>
      <div class="chip">
        <span class="k"><?= h($c[0]) ?></span>
        <span class="v"><?= $c[1] ?><small> <?= $c[1] === 1 ? 'day' : 'days' ?></small></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$counts): ?>
      <div class="chip"><span class="k">Countdowns</span>
        <span class="v" style="font-size:26px;color:var(--mist)">add dates to COUNTDOWNS</span></div>
    <?php endif; ?>
  </section>
</div>
<div class="stamp">ICS feeds refresh every 10 min<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
<script>
  function tick(){
    const n = new Date(); let h = n.getHours(); const ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12;
    document.getElementById('clock').innerHTML =
      h + ':' + String(n.getMinutes()).padStart(2,'0') + '<span> ' + ap + '</span>';
    document.getElementById('dateline').textContent =
      n.toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric' });
  }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), 10 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
