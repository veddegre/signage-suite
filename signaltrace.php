<?php
/**
 * SIGNALTRACE THREAT WALL — 1920×1080 signage
 * Live honeypot activity from your SignalTrace instance via its export API.
 *
 * Setup:
 *   1. Set ST_BASE_URL to your SignalTrace instance.
 *   2. Set ST_EXPORT_TOKEN to the EXPORT_API_TOKEN defined in SignalTrace's
 *      config — all API calls happen server-side here, so the token never
 *      reaches the signage browser.
 *
 * Uses: GET /export/stats/extended and GET /export/json with from/to (unix ms),
 * authenticated with "Authorization: Bearer <token>".
 */

require_once __DIR__ . '/config.php';

define('ST_BASE_URL', cfg('signaltrace.ST_BASE_URL', 'https://your-signaltrace-host'));
define('ST_EXPORT_TOKEN', cfg('signaltrace.ST_EXPORT_TOKEN', 'PUT-YOUR-EXPORT-API-TOKEN-HERE'));
define('WINDOW_HOURS', cfg('signaltrace.WINDOW_HOURS', 24));
define('IGNORE_IPS', cfg('signaltrace.IGNORE_IPS', ''));
define('TIMEZONE', cfg('signaltrace.TIMEZONE', 'America/Detroit'));
const CACHE_DIR       = __DIR__ . '/cache';
define('CACHE_TTL', cfg('signaltrace.CACHE_TTL', 60));

date_default_timezone_set(TIMEZONE);
$frameH = signage_frame_height();
$GLOBALS['diag'] = [];

function st_get(string $path, string $key): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/{$key}_" . md5($path) . '.json';
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $ch = curl_init(rtrim(ST_BASE_URL, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ST_EXPORT_TOKEN],
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch); curl_close($ch);
    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d)) { @file_put_contents($f, $body, LOCK_EX); return $d; }
        $GLOBALS['diag'][$key] = 'HTTP 200 but invalid JSON';
    } else {
        $GLOBALS['diag'][$key] = $err !== '' ? "curl: $err" : "HTTP $code";
    }
    if (is_file($f)) { $d = json_decode((string)file_get_contents($f), true); return is_array($d) ? $d : null; }
    return null;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 90) return $d . 's ago';
    if ($d < 5400) return (int)round($d / 60) . 'm ago';
    if ($d < 90000) return (int)round($d / 3600) . 'h ago';
    return (int)round($d / 86400) . 'd ago';
}

/** IPs to hide from the feed (comma-separated in admin — e.g. your signage/homelab server). */
function st_ignore_ips(): array
{
    static $ips = null;
    if ($ips !== null) {
        return $ips;
    }
    $raw = IGNORE_IPS;
    if (is_array($raw)) {
        $ips = array_values(array_filter(array_map('trim', $raw)));
        return $ips;
    }
    $ips = array_values(array_filter(array_map('trim', explode(',', (string)$raw))));
    return $ips;
}

function st_event_ignored(array $row): bool
{
    $ip = (string)($row['ip'] ?? '');
    return $ip !== '' && in_array($ip, st_ignore_ips(), true);
}

function st_event_ts(array $row): int
{
    if (isset($row['clicked_at_unix_ms'])) {
        return (int)($row['clicked_at_unix_ms'] / 1000);
    }
    return strtotime((string)($row['clicked_at'] ?? '')) ?: 0;
}

/** Normalize country rows from SignalTrace export APIs (country + hits). */
function st_country_rows(?array $stats, ?array $byCountry, int $limit = 4): array
{
    $out = [];
    $sources = [];
    if (is_array($byCountry) && array_is_list($byCountry)) {
        $sources = $byCountry;
    } elseif (is_array($stats['top_countries'] ?? null)) {
        $sources = $stats['top_countries'];
    }
    foreach ($sources as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['country'] ?? $row['ip_country'] ?? ''));
        if ($name === '') {
            $name = 'Unknown';
        }
        $hits = (int)($row['hits'] ?? $row['count'] ?? $row['events'] ?? $row['total'] ?? 0);
        $out[] = ['country' => $name, 'hits' => $hits];
    }
    usort($out, fn($a, $b) => $b['hits'] <=> $a['hits']);
    return array_slice($out, 0, $limit);
}

function labelColor(string $l): string
{
    return ['bot'=>'#ff5d5d','suspicious'=>'#ffb347','uncertain'=>'#8aa0c0','human'=>'#39c46d'][strtolower($l)] ?? '#8aa0c0';
}

$configured = ST_EXPORT_TOKEN !== 'PUT-YOUR-EXPORT-API-TOKEN-HERE'
           && ST_BASE_URL !== 'https://your-signaltrace-host';

$fromMs = (time() - WINDOW_HOURS * 3600) * 1000;
$toMs   = time() * 1000;
$qs     = "?from=$fromMs&to=$toMs";

$stats     = $configured ? st_get("/export/stats/extended$qs", 'st_stats') : null;
$byCountry = $configured ? st_get("/export/by-country$qs&limit=4", 'st_countries') : null;
$clicks    = $configured ? st_get("/export/json$qs", 'st_clicks') : null;

$total      = (int)($stats['total_events'] ?? $stats['total'] ?? 0);
$uniqueIps  = (int)($stats['unique_ips'] ?? 0);
$uniqueToks = (int)($stats['unique_tokens'] ?? 0);
$labels = [
    'bot'        => ['Bot',        (int)($stats['bot_events'] ?? 0),        '#ff5d5d'],
    'suspicious' => ['Suspicious', (int)($stats['suspicious_events'] ?? 0), '#ffb347'],
    'uncertain'  => ['Uncertain',  (int)($stats['uncertain_events'] ?? 0),  '#8aa0c0'],
    'human'      => ['Human',      (int)($stats['human_events'] ?? 0),      '#39c46d'],
];
$labelMax = max(1, max(array_values(array_map(fn($l) => $l[1], $labels))));
$topCountries = st_country_rows($stats, $byCountry, 4);

// Recent feed: newest first, optional IP filter, trimmed for display
$recent = [];
if (is_array($clicks)) {
    if (!array_is_list($clicks)) {
        $clicks = array_values($clicks);
    }
    $clicks = array_values(array_filter($clicks, fn($r) => is_array($r) && !st_event_ignored($r)));
    usort($clicks, fn($a, $b) => st_event_ts($b) <=> st_event_ts($a));
    $recent = array_slice($clicks, 0, 11);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SignalTrace Threat Wall</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:grid; gap:24px;
           grid-template-columns: 640px 1fr; grid-template-rows: 96px minmax(0,1fr) auto;
           grid-template-areas: "head head" "left feed" "meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .left { grid-area:left; display:flex; flex-direction:column; gap:24px; min-height:0; }
  .totals { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px; padding:30px 34px; }
  .totals .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .totals .big { font-family:'Big Shoulders Display'; font-weight:700; font-size:128px; line-height:1;
                 color:var(--beacon); font-variant-numeric:tabular-nums; }
  .subtotals { display:flex; gap:40px; margin-top:14px; }
  .subtotals div { font-size:24px; color:var(--mist); }
  .subtotals b { color:var(--snow); font-size:32px; font-family:'Big Shoulders Display'; }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:26px 34px; }
  .panel .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:18px; }
  .bar { display:grid; grid-template-columns:130px 1fr 80px; align-items:center; gap:14px; margin-bottom:14px; }
  .bar .n { font-size:24px; }
  .bar .track { height:18px; background:var(--lake-night); border-radius:9px; overflow:hidden; }
  .bar .fill { height:100%; border-radius:9px; }
  .bar .c { font-size:26px; font-weight:600; text-align:right; font-variant-numeric:tabular-nums; }

  .countries { flex:1; min-height:0; overflow:hidden; }
  .country { display:flex; justify-content:space-between; align-items:baseline;
             border-bottom:1px solid var(--hairline); padding:9px 2px; }
  .country:last-child { border-bottom:none; }
  .country .n { font-size:26px; }
  .country .c { font-family:'Big Shoulders Display'; font-weight:600; font-size:34px;
                color:var(--beacon); font-variant-numeric:tabular-nums; }

  .feed { grid-area:feed; background:var(--harbor); border:1px solid var(--hairline);
          border-radius:14px; padding:26px 34px; overflow:hidden; display:flex; flex-direction:column; }
  .feed .k { font-size:20px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:14px; }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:18px; letter-spacing:1px; text-transform:uppercase;
       color:var(--mist); font-weight:500; padding:8px 10px; border-bottom:1px solid var(--hairline); }
  td { font-size:23px; padding:13px 10px; border-bottom:1px solid var(--hairline); white-space:nowrap;
       overflow:hidden; text-overflow:ellipsis; max-width:330px; }
  td.mono { font-family:'IBM Plex Mono',monospace; font-size:21px; }
  .badge { display:inline-block; font-size:18px; font-weight:600; letter-spacing:1px;
           text-transform:uppercase; padding:3px 12px; border-radius:7px; color:var(--lake-night); }
  .dim { color:var(--mist); }
  .setupmsg { font-size:30px; color:var(--mist); line-height:1.6; padding:30px; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>SignalTrace <span>&middot; Threat Wall</span></h1>
    <div id="clock">--:--</div>
  </div>

  <?php if (!$configured): ?>
    <div class="setupmsg" style="grid-column:1/3">
      Set <code>ST_BASE_URL</code> and <code>ST_EXPORT_TOKEN</code> at the top of this file.
      The token is SignalTrace's <code>EXPORT_API_TOKEN</code> from
      <code>includes/config.local.php</code>. Calls are made server-side only.
    </div>
  <?php else: ?>

  <section class="left">
    <div class="totals">
      <div class="k">Events &middot; last <?= WINDOW_HOURS ?>h</div>
      <div class="big"><?= number_format($total) ?></div>
      <div class="subtotals">
        <div><b><?= number_format($uniqueIps) ?></b> unique IPs</div>
        <div><b><?= number_format($uniqueToks) ?></b> tokens hit</div>
      </div>
    </div>

    <div class="panel">
      <div class="k">Classification</div>
      <?php foreach ($labels as $l): ?>
        <div class="bar">
          <span class="n"><?= h($l[0]) ?></span>
          <div class="track"><div class="fill" style="width:<?= (int)round($l[1] / $labelMax * 100) ?>%;background:<?= $l[2] ?>"></div></div>
          <span class="c"><?= number_format($l[1]) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="panel countries">
      <div class="k">Top Source Countries</div>
      <?php if ($topCountries): foreach ($topCountries as $c): ?>
        <div class="country">
          <span class="n"><?= h($c['country'] ?? '??') ?></span>
          <span class="c"><?= number_format((int)($c['hits'] ?? 0)) ?></span>
        </div>
      <?php endforeach; else: ?>
        <div class="country"><span class="n dim">No events in window</span></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="feed">
    <div class="k">Recent Activity</div>
    <table>
      <tr><th>When</th><th>Token</th><th>Source</th><th>Org</th><th>Class</th></tr>
      <?php foreach ($recent as $r):
        $when = st_event_ts($r) ?: time();
        $lab  = strtolower($r['confidence_label'] ?? 'uncertain');
      ?>
        <tr>
          <td class="dim"><?= h(ago($when)) ?></td>
          <td><?= h($r['description'] ?: ($r['token'] ?? '')) ?></td>
          <td class="mono"><?= h($r['ip'] ?? '') ?> <span class="dim"><?= h($r['ip_country'] ?? '') ?></span></td>
          <td class="dim"><?= h($r['ip_org'] ?? '') ?></td>
          <td><span class="badge" style="background:<?= labelColor($lab) ?>"><?= h($lab) ?></span></td>
        </tr>
      <?php endforeach; if (!$recent): ?>
        <tr><td colspan="5" class="dim">Quiet out there — no events in the last <?= WINDOW_HOURS ?> hours.</td></tr>
      <?php endif; ?>
    </table>
  </section>

  <?php endif; ?>
  <div class="stamp">SignalTrace export API<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  setTimeout(() => location.reload(), 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
