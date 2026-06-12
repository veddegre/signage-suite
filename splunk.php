<?php
/**
 * SPLUNK BOARD — 1920×1080 signage
 * Renders a grid of panels from Splunk searches via the REST API — no Splunk
 * Web, no iframe, no login wall on the kiosk. Each panel is a oneshot search
 * executed server-side with an auth token; the token never reaches the
 * display browser.
 *
 * Splunk-side setup (one time):
 *   1. Create a low-privilege user for signage (can run the searches it needs,
 *      nothing else).
 *   2. Settings → Tokens → New Token for that user; paste it below.
 *   3. SPLUNK_BASE is the management port (8089), not Splunk Web.
 *
 * Panel types:
 *   'single'  big number — first result row, field named by 'field'
 *   'list'    label + bar + count rows — fields named by 'label'/'value'
 *   'trend'   area chart from a timechart — y field named by 'value'
 * Optional per panel: 'earliest'/'latest' (Splunk time modifiers, default
 * -24h@h/now), 'unit' (suffix on singles), 'wide' => true (span 2 columns).
 *
 * The grid is 3 columns; panels flow in order. Six normal panels, or four
 * with one wide trend, fills 1080p nicely.
 */

require_once __DIR__ . '/config.php';

define('SPLUNK_BASE', cfg('splunk.SPLUNK_BASE', 'https://192.168.86.30:8089'));
define('SPLUNK_TOKEN', cfg('splunk.SPLUNK_TOKEN', 'PUT-YOUR-SPLUNK-AUTH-TOKEN-HERE'));
define('SPLUNK_VERIFY_TLS', cfg('splunk.SPLUNK_VERIFY_TLS', false));
define('BOARD_TITLE', cfg('splunk.BOARD_TITLE', 'Splunk'));
define('BOARD_SUB', cfg('splunk.BOARD_SUB', 'Home SOC'));

define('PANELS', cfg('splunk.PANELS', [
    ['title' => 'Events Today', 'type' => 'single', 'field' => 'count', 'earliest' => '@d',
     'spl' => 'index=network | stats count'],
    ['title' => 'Blocked Today', 'type' => 'single', 'field' => 'count', 'earliest' => '@d',
     'spl' => 'index=network action=denied | stats count'],
    ['title' => 'Active Sources (1h)', 'type' => 'single', 'field' => 'dc', 'earliest' => '-1h',
     'spl' => 'index=network | stats dc(src_ip) as dc'],
    ['title' => 'Top Blocked Countries (24h)', 'type' => 'list', 'label' => 'country', 'value' => 'count',
     'spl' => 'index=network action=denied | stats count by country | sort -count | head 6'],
    ['title' => 'Events Over Time (24h)', 'type' => 'trend', 'value' => 'count', 'wide' => true,
     'spl' => 'index=network | timechart span=1h count'],
]));

define('TIMEZONE', cfg('splunk.TIMEZONE', 'America/Detroit'));
const CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('splunk.CACHE_TTL', 120));

date_default_timezone_set(TIMEZONE);
$GLOBALS['diag'] = [];

function splunk_oneshot(string $spl, string $earliest, string $latest): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $key = 'splunk_' . md5($spl . $earliest . $latest);
    $f = CACHE_DIR . "/$key.json";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $search = stripos(ltrim($spl), 'search ') === 0 || ltrim($spl)[0] === '|' ? $spl : 'search ' . $spl;
    $ch = curl_init(rtrim(SPLUNK_BASE, '/') . '/services/search/jobs?output_mode=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'exec_mode'     => 'oneshot',
            'search'        => $search,
            'earliest_time' => $earliest,
            'latest_time'   => $latest,
            'count'         => 0,
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . SPLUNK_TOKEN],
        CURLOPT_SSL_VERIFYPEER => SPLUNK_VERIFY_TLS,
        CURLOPT_SSL_VERIFYHOST => SPLUNK_VERIFY_TLS ? 2 : 0,
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) {
        $j = json_decode($body, true);
        if (isset($j['results']) && is_array($j['results'])) {
            @file_put_contents($f, json_encode($j['results']), LOCK_EX);
            return $j['results'];
        }
        $GLOBALS['diag'][substr(md5($spl), 0, 6)] = 'no results array in response';
    } else {
        $GLOBALS['diag'][substr(md5($spl), 0, 6)] = $err !== '' ? "curl: $err" : "HTTP $code";
    }
    if (is_file($f)) { $d = json_decode((string)file_get_contents($f), true); return is_array($d) ? $d : null; }
    return null;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_num($n): string
{
    $n = (float)$n;
    if ($n >= 1e6) return number_format($n / 1e6, 1) . 'M';
    if ($n >= 1e4) return number_format($n / 1e3, 1) . 'k';
    return number_format($n);
}

$configured = SPLUNK_TOKEN !== 'PUT-YOUR-SPLUNK-AUTH-TOKEN-HERE';
$panels = [];
foreach (PANELS as $p) {
    $rows = $configured
        ? splunk_oneshot($p['spl'], $p['earliest'] ?? '-24h@h', $p['latest'] ?? 'now')
        : null;
    $panels[] = $p + ['rows' => $rows];
}

/** Build an SVG area chart from timechart rows. */
function trend_svg(array $rows, string $valueField, int $w = 1140, int $hgt = 240): string
{
    $pts = [];
    foreach ($rows as $r) {
        if (isset($r[$valueField]) && is_numeric($r[$valueField])) $pts[] = (float)$r[$valueField];
    }
    $n = count($pts);
    if ($n < 2) return '<div class="nodata">not enough data</div>';
    $max = max($pts); $max = $max > 0 ? $max : 1;
    $coords = [];
    foreach ($pts as $i => $v) {
        $x = round($i / ($n - 1) * $w, 1);
        $y = round($hgt - ($v / $max) * ($hgt - 14) - 4, 1);
        $coords[] = "$x,$y";
    }
    $line = implode(' ', $coords);
    $area = "0,$hgt " . $line . " $w,$hgt";
    return '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none">'
         . '<polygon points="' . $area . '" fill="rgba(255,179,71,.16)"/>'
         . '<polyline points="' . $line . '" fill="none" stroke="#ffb347" stroke-width="3" '
         . 'stroke-linejoin="round" stroke-linecap="round"/></svg>';
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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:1080px; padding:28px 32px; display:flex;
           flex-direction:column; gap:24px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .grid { flex:1; min-height:0; display:grid; grid-template-columns:repeat(3, 1fr);
          grid-auto-rows:1fr; gap:24px; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:26px 32px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel.wide { grid-column:span 2; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:12px; }
  .single { flex:1; display:flex; align-items:center; }
  .single .v { font-family:'Big Shoulders Display'; font-weight:700; font-size:128px;
               line-height:1; color:var(--beacon); font-variant-numeric:tabular-nums; }
  .single .v small { font-size:48px; color:var(--mist); font-weight:600; }
  .lrow { display:grid; grid-template-columns:minmax(110px,auto) 1fr 90px; align-items:center;
          gap:14px; padding:8px 0; }
  .lrow .n { font-size:24px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .lrow .track { height:14px; background:var(--lake-night); border-radius:7px; overflow:hidden; }
  .lrow .fill { height:100%; background:var(--beacon); border-radius:7px; }
  .lrow .c { font-size:26px; font-weight:600; text-align:right; font-variant-numeric:tabular-nums; }
  .trend { flex:1; min-height:0; }
  .trend svg { width:100%; height:100%; }
  .nodata, .err { font-size:24px; color:var(--mist); }
  .setupmsg { font-size:28px; color:var(--mist); line-height:1.7; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  .stamp { position:absolute; bottom:6px; right:36px; font-size:15px; color:var(--mist); opacity:.7; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?> <span>&middot; <?= h(BOARD_SUB) ?></span></h1>
    <div id="clock">--:--</div>
  </div>
  <div class="grid">
    <?php if (!$configured): ?>
      <div class="panel wide"><div class="k">Setup</div>
        <div class="setupmsg">Set <code>SPLUNK_BASE</code> (management port 8089) and
        <code>SPLUNK_TOKEN</code> at the top of this file. Create the token under
        Settings &rarr; Tokens for a low-privilege signage user.</div></div>
    <?php endif; ?>
    <?php foreach ($panels as $p): ?>
      <div class="panel<?= !empty($p['wide']) ? ' wide' : '' ?>">
        <div class="k"><?= h($p['title']) ?></div>
        <?php $rows = $p['rows'];
        if ($rows === null): ?>
          <div class="err"><?= $configured ? 'no data — see diagnostics' : '—' ?></div>
        <?php elseif ($p['type'] === 'single'):
            $v = $rows[0][$p['field']] ?? null; ?>
          <div class="single"><div class="v"><?= $v !== null ? fmt_num($v) : '—'
            ?><?= !empty($p['unit']) ? '<small> ' . h($p['unit']) . '</small>' : '' ?></div></div>
        <?php elseif ($p['type'] === 'list'):
            $vals = array_map(fn($r) => (float)($r[$p['value']] ?? 0), $rows);
            $maxV = max(1, $vals ? max($vals) : 1);
            foreach ($rows as $r): ?>
          <div class="lrow">
            <span class="n"><?= h((string)($r[$p['label']] ?? '?')) ?></span>
            <div class="track"><div class="fill" style="width:<?= (int)round((float)($r[$p['value']] ?? 0) / $maxV * 100) ?>%"></div></div>
            <span class="c"><?= fmt_num($r[$p['value']] ?? 0) ?></span>
          </div>
        <?php endforeach;
              if (!$rows): ?><div class="nodata">no results</div><?php endif;
        elseif ($p['type'] === 'trend'): ?>
          <div class="trend"><?= trend_svg($rows, $p['value']) ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="stamp">Splunk REST &middot; refresh <?= CACHE_TTL ?>s<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
<script>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), <?= CACHE_TTL ?> * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
