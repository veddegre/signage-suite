<?php
/**
 * ZABBIX BOARD — 1920×1080 signage
 * Active problems and host status from Zabbix 7.x JSON-RPC — no iframe, no login
 * wall on the kiosk. API token stays server-side.
 *
 * Setup:
 *   1. Zabbix → Users → API tokens → create a token for a read-only user.
 *   2. admin.php → Zabbix Monitoring → set ZABBIX_URL and ZABBIX_TOKEN.
 *   3. Add pages filtered by host group name(s) — e.g. zabbix.php?d=network
 *
 * Multiple pages: zabbix.php?d=<key> — same pattern as splunk.php / grafana.php.
 */

require_once __DIR__ . '/zabbix_lib.php';

$page = zabbix_resolve_page((string)($_GET['d'] ?? ''));
$pageOff = !empty($page['off']);
define('BOARD_TITLE', (string)($page['title'] ?? zabbix_default_page_title()));
define('BOARD_SUB', (string)($page['sub'] ?? zabbix_default_page_sub()));
define('TIMEZONE', cfg('zabbix.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$configured = zabbix_configured();
$data = $pageOff
    ? ['ok' => false, 'error' => 'This page is marked Off wall in admin.', 'group_names' => [], 'problems' => [], 'hosts' => [], 'counts' => []]
    : zabbix_fetch_wall_data($page);
$cacheTtl = zabbix_cache_ttl();
$groupLabel = implode(', ', $data['group_names'] ?? []);
$problemCount = count($data['problems'] ?? []);
$hostCount = count($data['hosts'] ?? []);
$hostsWithProblems = count(array_filter($data['hosts'] ?? [], static fn($h) => is_array($h) && !empty($h['problem'])));

$minSevLabel = zabbix_severity_label(max(0, min(5, (int)($page['min_severity'] ?? 2))));
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --ok:#59db8f; --bad:#e45959; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:flex;
           flex-direction:column; gap:22px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .summary { display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px;
          border-radius:999px; border:1px solid var(--hairline); background:var(--harbor);
          font-size:22px; color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; font-variant-numeric:tabular-nums; }
  .sev-pill { font-size:18px; padding:8px 14px; }
  .sev-pill .dot { width:12px; height:12px; border-radius:50%; display:inline-block; }

  .main { flex:1; min-height:0; display:grid; grid-template-columns:1.35fr 1fr; gap:24px; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:22px 26px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:14px; flex:0 0 auto; }
  .panel .body { flex:1; min-height:0; overflow:hidden; }

  .problems { display:flex; flex-direction:column; gap:10px; }
  .problem { display:grid; grid-template-columns:110px 1fr 90px; gap:16px; align-items:start;
             padding:12px 0; border-bottom:1px solid rgba(38,52,77,.55); }
  .problem:last-child { border-bottom:none; }
  .sev { font-size:18px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; }
  .pname { font-size:26px; line-height:1.25; }
  .phost { font-size:18px; color:var(--mist); margin-top:4px; }
  .pmeta { font-size:22px; color:var(--mist); text-align:right; white-space:nowrap; }
  .ack { color:var(--beacon); font-size:16px; margin-top:4px; }

  .hosts { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; align-content:start; }
  .host { display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:10px;
          background:var(--lake-night); border:1px solid rgba(38,52,77,.55); min-width:0; }
  .host .dot { width:14px; height:14px; border-radius:50%; flex:0 0 14px; background:var(--ok); }
  .host.problem .dot { background:var(--bad); }
  .host.disabled .dot { background:var(--mist); opacity:.55; }
  .host .name { font-size:22px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .nodata, .err, .setupmsg { font-size:24px; color:var(--mist); line-height:1.6; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?> <span>&middot; <?= h(BOARD_SUB) ?></span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if (!$configured): ?>
    <div class="panel">
      <div class="k">Setup</div>
      <div class="setupmsg">Set <code>ZABBIX_URL</code> and <code>ZABBIX_TOKEN</code> in
      <strong>admin.php → Zabbix Monitoring</strong>. Create the token under
      Users → API tokens for a read-only Zabbix user.</div>
    </div>
  <?php elseif (($data['error'] ?? '') !== '' && empty($data['problems'])): ?>
    <div class="panel">
      <div class="k">Configuration</div>
      <div class="err"><?= h((string)$data['error']) ?></div>
    </div>
  <?php else: ?>
    <div class="summary">
      <div class="pill">Groups <strong><?= h($groupLabel !== '' ? $groupLabel : '—') ?></strong></div>
      <div class="pill">Problems <strong><?= (int)$problemCount ?></strong></div>
      <div class="pill">Hosts <strong><?= (int)$hostsWithProblems ?> / <?= (int)$hostCount ?></strong> with issues</div>
      <?php foreach (array_reverse(zabbix_severity_options(), true) as $sev):
          $n = (int)(($data['counts'] ?? [])[$sev] ?? 0);
          if ($n === 0) continue; ?>
      <div class="pill sev-pill"><span class="dot" style="background:<?= h(zabbix_severity_color($sev)) ?>"></span>
        <?= h(zabbix_severity_label($sev)) ?> <strong><?= $n ?></strong></div>
      <?php endforeach; ?>
    </div>

    <div class="main">
      <div class="panel">
        <div class="k">Active problems</div>
        <div class="body">
          <?php if ($problemCount === 0): ?>
            <div class="nodata">No active problems at <?= h($minSevLabel) ?>+ in selected groups.</div>
          <?php else: ?>
            <div class="problems">
              <?php foreach ($data['problems'] as $problem):
                  if (!is_array($problem)) continue;
                  $sev = max(0, min(5, (int)($problem['severity'] ?? 0)));
                  $hosts = [];
                  foreach ((array)($problem['hosts'] ?? []) as $hr) {
                      if (is_array($hr) && ($hr['name'] ?? '') !== '') {
                          $hosts[] = (string)$hr['name'];
                      }
                  }
                  $hostText = $hosts !== [] ? implode(', ', $hosts) : '—';
                  $clock = (int)($problem['clock'] ?? 0);
                  $ack = !empty($problem['acknowledged']); ?>
              <div class="problem">
                <div class="sev" style="color:<?= h(zabbix_severity_color($sev)) ?>"><?= h(zabbix_severity_label($sev)) ?></div>
                <div>
                  <div class="pname"><?= h((string)($problem['name'] ?? 'Problem')) ?></div>
                  <div class="phost"><?= h($hostText) ?></div>
                  <?php if ($ack): ?><div class="ack">Acknowledged</div><?php endif; ?>
                </div>
                <div class="pmeta"><?= $clock > 0 ? h(zabbix_format_age($clock)) : '—' ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="k">Hosts in scope</div>
        <div class="body">
          <?php if ($hostCount === 0): ?>
            <div class="nodata">No hosts in selected group(s).</div>
          <?php else: ?>
            <div class="hosts">
              <?php foreach ($data['hosts'] as $host):
                  if (!is_array($host)) continue;
                  $cls = 'host';
                  if (!empty($host['disabled'])) {
                      $cls .= ' disabled';
                  } elseif (!empty($host['problem'])) {
                      $cls .= ' problem';
                  } ?>
              <div class="<?= h($cls) ?>">
                <span class="dot"></span>
                <span class="name"><?= h((string)($host['name'] ?? '')) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="stamp">Zabbix JSON-RPC &middot; refresh <?= (int)$cacheTtl ?>s<?= !empty($data['error']) && $configured ? ' · ' . h((string)$data['error']) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(() => location.reload(), <?= (int)$cacheTtl ?> * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
