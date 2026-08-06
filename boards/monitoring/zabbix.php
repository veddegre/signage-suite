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

require_once dirname(__DIR__, 2) . '/lib/zabbix_lib.php';

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
$allHostsScope = !empty($data['all_hosts']);
$groupLabel = $allHostsScope ? 'All hosts' : implode(', ', $data['group_names'] ?? []);
$scopePhrase = $allHostsScope ? 'in scope' : 'in selected groups';
$problemCount = count($data['problems'] ?? []);
$hostCount = (int)($data['hosts_total'] ?? count($data['hosts'] ?? []));
$hostsWithProblems = (int)($data['hosts_with_problems'] ?? count(array_filter(
    $data['hosts'] ?? [],
    static fn($h) => is_array($h) && !empty($h['problem'])
)));
$hostsOk = max(0, $hostCount - $hostsWithProblems);
$problemsTotal = (int)($data['problems_total'] ?? 0);
if ($problemsTotal === 0) {
    $problemsTotal = array_sum(array_map('intval', $data['counts'] ?? []));
}
$ackHidden = (int)($data['acknowledged_hidden'] ?? 0);
$displayedBySev = is_array($data['displayed_by_severity'] ?? null) ? $data['displayed_by_severity'] : [];

$boardH = signage_frame_height();
$hostCols = 2;
$hostGap = 12;
$hostPad = '12px 14px';
$hostFont = 22;
$hostMinH = 46;
if (!$allHostsScope) {
    $displayHostCount = count($data['hosts'] ?? []);
    if ($displayHostCount > 48) {
        $hostCols = 4;
    } elseif ($displayHostCount > 24) {
        $hostCols = 3;
    } elseif ($displayHostCount > 12) {
        $hostCols = 3;
    } elseif ($displayHostCount > 8) {
        $hostCols = 2;
    }
    $hostCompact = $displayHostCount > 14 || $boardH < 1008;
    $hostGap = $hostCompact ? 8 : 12;
    $hostPad = $hostCompact ? '8px 12px' : '12px 14px';
    $hostFont = $displayHostCount > 48 ? 16 : ($displayHostCount > 24 ? 18 : ($displayHostCount > 14 ? 19 : 22));
    $hostMinH = $hostCompact ? 38 : 46;
}

$minSevLabel = zabbix_severity_label(max(0, min(5, (int)($page['min_severity'] ?? 2))));

function zabbix_problem_show_host(string $problemName, string $hostText): bool
{
    if ($hostText === '' || $hostText === '—') {
        return false;
    }
    if (count(explode(', ', $hostText)) > 1) {
        return true;
    }
    if (stripos($problemName, $hostText) !== false) {
        return false;
    }

    return true;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; min-height:0; overflow:hidden; padding:28px 32px; display:flex;
           flex-direction:column; gap:22px; }
  .board.all-hosts-view { padding:22px 28px 24px; gap:16px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 auto; min-height:72px; }
  .board.all-hosts-view .head { min-height:56px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .board.all-hosts-view .head h1 { font-size:56px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }
  .board.all-hosts-view #clock { font-size:48px; }

  .summary { display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
  .summary.compact { gap:10px; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px;
          border-radius:999px; border:1px solid var(--hairline); background:var(--harbor);
          font-size:22px; color:var(--mist); }
  .summary.compact .pill { font-size:19px; padding:8px 16px; }
  .pill strong { color:var(--snow); font-weight:600; font-variant-numeric:tabular-nums; }
  .pill.stat strong { color:var(--beacon); }
  .pill.muted { color:var(--mist); font-size:17px; }
  .summary.compact .pill.muted { font-size:15px; }
  .pill.muted strong { color:var(--mist); font-weight:500; }
  .sev-pill { font-size:18px; padding:8px 14px; }
  .summary.compact .sev-pill { font-size:16px; padding:6px 12px; }
  .sev-pill .dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
  .summary.compact .sev-pill .dot { width:10px; height:10px; }

  .main { flex:1; min-height:0; display:grid; grid-template-columns:1.35fr 1fr; gap:24px; }
  .main.all-hosts { grid-template-columns:1fr; }
  .main.all-hosts .panel { padding:16px 18px 14px; border-radius:12px; }
  .main.all-hosts .panel .k { display:none; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:22px 26px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:14px; flex:0 0 auto; }
  .panel .body { flex:1; min-height:0; overflow:hidden; }
  .main.all-hosts .problems-panel .body { overflow-x:hidden; overflow-y:auto; scrollbar-width:none; }
  .main.all-hosts .problems-panel .body::-webkit-scrollbar { display:none; }
  .panel.hosts-panel .body { overflow-x:hidden; overflow-y:auto; scrollbar-width:none; }
  .panel.hosts-panel .body::-webkit-scrollbar { display:none; }

  .problems { display:flex; flex-direction:column; gap:10px; }
  .main.all-hosts .problems {
    display:grid; grid-template-columns:1fr 1fr; column-gap:24px; row-gap:6px;
    align-content:start;
  }
  .main.all-hosts .sev-head {
    grid-column:1 / -1; display:flex; align-items:baseline; gap:10px;
    padding:12px 2px 2px; margin-top:2px;
    font-size:13px; font-weight:600; letter-spacing:1.6px; text-transform:uppercase;
    color:var(--sev-color); border-bottom:1px solid color-mix(in srgb, var(--sev-color) 35%, transparent);
  }
  .main.all-hosts .sev-head:first-child { padding-top:0; margin-top:0; }
  .main.all-hosts .sev-head .n { font-size:12px; letter-spacing:.5px; color:var(--mist); font-weight:500; }
  .problem { display:grid; grid-template-columns:110px 1fr 90px; gap:16px; align-items:start;
             padding:12px 0; border-bottom:1px solid rgba(38,52,77,.55); }
  .main.all-hosts .problem {
    display:flex; gap:10px; align-items:stretch; padding:0; border-bottom:none;
    background:color-mix(in srgb, var(--harbor) 88%, #000 12%);
    border:1px solid color-mix(in srgb, var(--hairline) 65%, transparent);
    border-radius:8px; min-height:0;
  }
  .main.all-hosts .problem-bar {
    width:4px; flex:0 0 4px; border-radius:8px 0 0 8px; background:var(--sev-color);
  }
  .main.all-hosts .problem-body { flex:1; min-width:0; padding:8px 12px 8px 0; }
  .main.all-hosts .problem-top {
    display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:3px;
  }
  .main.all-hosts .phost {
    font-size:13px; color:var(--mist); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    min-width:0; flex:1;
  }
  .main.all-hosts .pmeta {
    font-size:14px; color:var(--mist); white-space:nowrap; flex:0 0 auto; font-variant-numeric:tabular-nums;
  }
  .main.all-hosts .pname {
    font-size:18px; line-height:1.28; color:var(--snow);
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
  }
  .main.all-hosts .pname.only {
    flex:1; min-width:0; margin-top:0;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    font-size:18px; line-height:1.28; color:var(--snow);
  }
  .problem:last-child { border-bottom:none; }
  .sev { font-size:18px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; }
  .pname { font-size:26px; line-height:1.25; }
  .phost { font-size:18px; color:var(--mist); margin-top:4px; }
  .pmeta { font-size:22px; color:var(--mist); text-align:right; white-space:nowrap; }
  .ack { color:var(--beacon); font-size:16px; margin-top:4px; }

  <?php if (!$allHostsScope): ?>
  .hosts { display:grid; grid-template-columns:repeat(<?= (int)$hostCols ?>, minmax(0, 1fr));
           gap:<?= (int)$hostGap ?>px; align-content:start; }
  .host { display:flex; align-items:center; gap:10px; padding:<?= h($hostPad) ?>; border-radius:10px;
          background:var(--tile-bg); border:1px solid color-mix(in srgb, var(--hairline) 72%, transparent);
          min-width:0; min-height:<?= (int)$hostMinH ?>px; box-sizing:border-box; }
  .host .dot { width:14px; height:14px; border-radius:50%; flex:0 0 14px; background:var(--ok); }
  .host.problem .dot { background:var(--bad); }
  .host.disabled .dot { background:var(--mist); opacity:.55; }
  .host .name { font-size:<?= (int)$hostFont ?>px; line-height:1.25; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  <?php endif; ?>

  .all-clear { text-align:center; padding:80px 24px; }
  .all-clear .big { font-family:'Big Shoulders Display'; font-size:72px; font-weight:700; color:var(--ok);
                    margin-bottom:12px; }
  .all-clear .sub { font-size:28px; color:var(--mist); }

  .nodata, .err, .setupmsg { font-size:24px; color:var(--mist); line-height:1.6; }
  .setupmsg code { color:var(--snow); background:var(--code-bg); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board<?= $allHostsScope ? ' all-hosts-view' : '' ?>">
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
    <div class="summary<?= $allHostsScope ? ' compact' : '' ?>">
      <?php if ($allHostsScope && $hostCount > 0): ?>
      <div class="pill stat"><strong><?= (int)$hostCount ?></strong> monitored
        · <strong><?= (int)$problemsTotal ?></strong> open problems
        · <strong><?= (int)$hostsWithProblems ?></strong> hosts
        <?php if ($hostsOk > 0): ?> · <strong><?= (int)$hostsOk ?></strong> OK<?php endif; ?>
      </div>
      <?php if ($ackHidden > 0): ?>
      <div class="pill muted"><strong><?= (int)$ackHidden ?></strong> acknowledged hidden</div>
      <?php endif; ?>
      <?php elseif (!$allHostsScope): ?>
      <div class="pill">Scope <strong><?= h($groupLabel !== '' ? $groupLabel : '—') ?></strong></div>
      <div class="pill">Problems <strong><?= (int)$problemsTotal ?></strong></div>
      <div class="pill">Hosts <strong><?= (int)$hostsWithProblems ?> / <?= (int)$hostCount ?></strong> with issues</div>
      <?php if ($ackHidden > 0): ?>
      <div class="pill muted"><strong><?= (int)$ackHidden ?></strong> acknowledged hidden</div>
      <?php endif; ?>
      <?php endif; ?>
      <?php foreach (array_reverse(zabbix_severity_options(), true) as $sev):
          $n = (int)(($data['counts'] ?? [])[$sev] ?? 0);
          if ($n === 0) continue; ?>
      <div class="pill sev-pill" title="Open problems<?= $ackHidden > 0 ? ' (unacknowledged)' : '' ?>"><span class="dot" style="background:<?= h(zabbix_severity_color($sev)) ?>"></span>
        <?= h(zabbix_severity_label($sev)) ?> <strong><?= $n ?></strong></div>
      <?php endforeach; ?>
    </div>

    <div class="main<?= $allHostsScope ? ' all-hosts' : '' ?>">
      <div class="panel problems-panel">
        <div class="k">Active problems</div>
        <div class="body">
          <?php if ($problemCount === 0 && $allHostsScope && $hostCount > 0): ?>
            <div class="all-clear">
              <div class="big">All clear</div>
              <div class="sub"><?= (int)$hostCount ?> host<?= $hostCount === 1 ? '' : 's' ?> monitored — no active problems at <?= h($minSevLabel) ?>+</div>
            </div>
          <?php elseif ($problemCount === 0): ?>
            <div class="nodata">No active problems at <?= h($minSevLabel) ?>+ <?= h($scopePhrase) ?>.</div>
          <?php else: ?>
            <div class="problems">
              <?php
              $prevSev = null;
              foreach ($data['problems'] as $problem):
                  if (!is_array($problem)) continue;
                  $sev = max(0, min(5, (int)($problem['severity'] ?? 0)));
                  $sevColor = zabbix_severity_color($sev);
                  if ($allHostsScope && $sev !== $prevSev):
                      $prevSev = $sev;
                      $sevTotal = (int)(($data['counts'] ?? [])[$sev] ?? 0);
                      $sevShown = (int)($displayedBySev[$sev] ?? 0); ?>
              <div class="sev-head" style="--sev-color:<?= h($sevColor) ?>">
                <?= h(zabbix_severity_label($sev)) ?><?php if ($sevTotal > 0): ?>
                <span class="n"><?php if ($sevShown > 0 && $sevShown < $sevTotal): ?><?= (int)$sevShown ?> of <?= (int)$sevTotal ?><?php else: ?><?= (int)$sevTotal ?><?php endif; ?></span>
                <?php endif; ?>
              </div>
                  <?php endif;
                  $hosts = [];
                  foreach ((array)($problem['hosts'] ?? []) as $hr) {
                      if (is_array($hr) && ($hr['name'] ?? '') !== '') {
                          $hosts[] = (string)$hr['name'];
                      }
                  }
                  $hostText = $hosts !== [] ? implode(', ', $hosts) : '—';
                  $problemName = (string)($problem['name'] ?? 'Problem');
                  $showHost = zabbix_problem_show_host($problemName, $hostText);
                  $clock = (int)($problem['clock'] ?? 0);
                  $ack = !empty($problem['acknowledged']);
                  if ($allHostsScope): ?>
              <div class="problem" style="--sev-color:<?= h($sevColor) ?>">
                <div class="problem-bar"></div>
                <div class="problem-body">
                  <?php if ($showHost): ?>
                  <div class="problem-top">
                    <div class="phost"><?= h($hostText) ?></div>
                    <div class="pmeta"><?= $clock > 0 ? h(zabbix_format_age($clock)) : '—' ?></div>
                  </div>
                  <div class="pname"><?= h($problemName) ?></div>
                  <?php else: ?>
                  <div class="problem-top">
                    <div class="pname only"><?= h($problemName) ?></div>
                    <div class="pmeta"><?= $clock > 0 ? h(zabbix_format_age($clock)) : '—' ?></div>
                  </div>
                  <?php endif; ?>
                  <?php if ($ack): ?><div class="ack">Acknowledged</div><?php endif; ?>
                </div>
              </div>
                  <?php else: ?>
              <div class="problem">
                <div class="sev" style="color:<?= h($sevColor) ?>"><?= h(zabbix_severity_label($sev)) ?></div>
                <div>
                  <div class="pname"><?= h($problemName) ?></div>
                  <div class="phost"><?= h($hostText) ?></div>
                  <?php if ($ack): ?><div class="ack">Acknowledged</div><?php endif; ?>
                </div>
                <div class="pmeta"><?= $clock > 0 ? h(zabbix_format_age($clock)) : '—' ?></div>
              </div>
                  <?php endif;
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$allHostsScope): ?>
      <div class="panel hosts-panel">
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
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="stamp">Zabbix JSON-RPC &middot; refresh <?= (int)$cacheTtl ?>s<?= !empty($data['error']) && $configured ? ' · ' . h((string)$data['error']) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  <?= signage_clock_tick_script('clock', TIMEZONE) ?>
  <?php endif; ?>

  setTimeout(() => location.reload(), <?= (int)$cacheTtl ?> * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
