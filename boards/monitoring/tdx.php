<?php
/**
 * TEAMDYNAMIX BOARD — 1920×1080 signage
 * Open tickets from TeamDynamix TDWebApi — no iframe, no login wall on the kiosk.
 * JWT stays server-side.
 *
 * Setup:
 *   1. TDAdmin → Organization → BEID + Web Services Key (admin auth), or use a service user.
 *   2. admin.php → TeamDynamix → set TDX_BASE_URL and credentials.
 *   3. Add pages with app ID and filters — e.g. tdx.php?d=itsm
 *
 * Multiple pages: tdx.php?d=<key> — same pattern as zabbix.php / splunk.php.
 */

require_once dirname(__DIR__, 2) . '/lib/tdx_lib.php';

$page = tdx_resolve_page((string)($_GET['d'] ?? ''));
$pageOff = !empty($page['off']);
define('BOARD_TITLE', (string)($page['title'] ?? tdx_default_page_title()));
define('BOARD_SUB', (string)($page['sub'] ?? tdx_default_page_sub()));
define('TIMEZONE', cfg('tdx.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$configured = tdx_configured();
$data = $pageOff
    ? ['ok' => false, 'error' => 'This page is marked Off wall in admin.', 'tickets' => [], 'counts' => []]
    : tdx_fetch_wall_data($page);
$cacheTtl = tdx_cache_ttl();
$tickets = is_array($data['tickets'] ?? null) ? $data['tickets'] : [];
$ticketCount = count($tickets);
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
$appLabel = (string)($data['app_label'] ?? '');
$appId = (int)($data['app_id'] ?? 0);

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
          --snow:#edf2fb; --mist:#8aa0c0; --accent:#5eb3ff; --ok:#59db8f; --bad:#e45959; --warn:#ffb347; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:flex;
           flex-direction:column; gap:22px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--accent); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .summary { display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px;
          border-radius:999px; border:1px solid var(--hairline); background:var(--harbor);
          font-size:22px; color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; font-variant-numeric:tabular-nums; }
  .pill.bad strong { color:var(--bad); }
  .pill.warn strong { color:var(--warn); }

  .main { flex:1; min-height:0; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:22px 26px; min-height:0; height:100%; overflow:hidden; display:flex; flex-direction:column; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:14px; flex:0 0 auto; }
  .panel .body { flex:1; min-height:0; overflow:hidden; }

  .tickets { display:flex; flex-direction:column; gap:8px; }
  .ticket { display:grid; grid-template-columns:90px 130px 1fr 180px 120px; gap:16px; align-items:start;
            padding:12px 0; border-bottom:1px solid rgba(38,52,77,.55); }
  .ticket:last-child { border-bottom:none; }
  .tid { font-size:22px; font-weight:600; color:var(--accent); font-variant-numeric:tabular-nums; }
  .prio { font-size:18px; font-weight:600; letter-spacing:.3px; text-transform:uppercase; }
  .ttitle { font-size:26px; line-height:1.25; }
  .tmeta { font-size:18px; color:var(--mist); margin-top:4px; }
  .tage { font-size:22px; color:var(--mist); text-align:right; white-space:nowrap; }
  .tstatus { font-size:20px; color:var(--snow); text-align:right; }
  .flag { display:inline-block; font-size:14px; padding:2px 8px; border-radius:6px; margin-top:4px;
          background:rgba(228,89,89,.15); color:var(--bad); margin-right:6px; }

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
      <div class="setupmsg">Set <code>TDX_BASE_URL</code> and credentials in
      <strong>admin.php → TeamDynamix</strong>. Use BEID + Web Services Key (admin auth) or username/password.</div>
    </div>
  <?php elseif (($data['error'] ?? '') !== '' && empty($tickets)): ?>
    <div class="panel">
      <div class="k">Configuration</div>
      <div class="err"><?= h((string)$data['error']) ?></div>
    </div>
  <?php else: ?>
    <div class="summary">
      <div class="pill">App <strong><?= h($appLabel !== '' ? $appLabel : ($appId > 0 ? (string)$appId : '—')) ?></strong></div>
      <div class="pill">Open <strong><?= (int)$ticketCount ?></strong></div>
      <?php if ((int)($counts['overdue'] ?? 0) > 0): ?>
      <div class="pill bad">Overdue <strong><?= (int)$counts['overdue'] ?></strong></div>
      <?php endif; ?>
      <?php if ((int)($counts['sla'] ?? 0) > 0): ?>
      <div class="pill warn">SLA breach <strong><?= (int)$counts['sla'] ?></strong></div>
      <?php endif; ?>
      <?php foreach ((array)($counts['by_priority'] ?? []) as $pName => $n):
          if ((int)$n === 0) continue; ?>
      <div class="pill"><?= h((string)$pName) ?> <strong><?= (int)$n ?></strong></div>
      <?php endforeach; ?>
    </div>

    <div class="main">
      <div class="panel">
        <div class="k">Tickets</div>
        <div class="body">
          <?php if ($ticketCount === 0): ?>
            <div class="nodata">No tickets match this page's filters.</div>
          <?php else: ?>
            <div class="tickets">
              <?php foreach ($tickets as $ticket):
                  if (!is_array($ticket)) continue;
                  $prioId = (int)($ticket['priority_id'] ?? 0);
                  $modified = (string)($ticket['modified'] ?? '');
                  $created = (string)($ticket['created'] ?? '');
                  $ageIso = $modified !== '' ? $modified : $created; ?>
              <div class="ticket">
                <div class="tid">#<?= (int)($ticket['id'] ?? 0) ?></div>
                <div class="prio" style="color:<?= h(tdx_priority_color($prioId)) ?>">
                  <?= h((string)($ticket['priority'] ?? '—')) ?></div>
                <div>
                  <div class="ttitle"><?= h((string)($ticket['title'] ?? 'Ticket')) ?></div>
                  <div class="tmeta">
                    <?= h((string)($ticket['type'] ?? '')) ?>
                    <?php if ((string)($ticket['group'] ?? '') !== ''): ?>
                      &middot; <?= h((string)$ticket['group']) ?>
                    <?php endif; ?>
                    <?php if ((string)($ticket['responsible'] ?? '') !== ''): ?>
                      &middot; <?= h((string)$ticket['responsible']) ?>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($ticket['overdue'])): ?><span class="flag">Overdue</span><?php endif; ?>
                  <?php if (!empty($ticket['sla_violation'])): ?><span class="flag">SLA</span><?php endif; ?>
                </div>
                <div class="tstatus"><?= h((string)($ticket['status'] ?? '—')) ?></div>
                <div class="tage"><?= h(tdx_format_age($ageIso)) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="stamp">TeamDynamix API &middot; refresh <?= (int)$cacheTtl ?>s<?= !empty($data['error']) && $configured ? ' · ' . h((string)$data['error']) : '' ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php endif; ?>
  setTimeout(() => location.reload(), <?= (int)$cacheTtl ?> * 1000);
</script>
<?php include dirname(__DIR__, 2) . '/ticker.php'; ?>
</body>
</html>
