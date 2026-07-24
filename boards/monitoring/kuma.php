<?php
/**
 * UPTIME KUMA BOARD — 1920×1080 signage
 * Monitor up/down grid from your Uptime Kuma instance — no iframe.
 *
 * Setup:
 *   1. admin.php → Uptime Kuma → KUMA_URL (e.g. http://192.168.x.x:3001)
 *   2. Add pages with a status page slug and/or use the board API key (Settings → API Keys)
 *   3. Optional tag filter per page (comma-separated)
 *   4. Security → Allow private URL fetches when Kuma is on your LAN
 *
 * Multiple pages: kuma.php?d=<key> — same pattern as zabbix.php / splunk.php.
 */

require_once dirname(__DIR__, 2) . '/lib/kuma_lib.php';

$page = kuma_resolve_page((string)($_GET['d'] ?? ''));
$pageOff = !empty($page['off']);
define('BOARD_TITLE', (string)($page['title'] ?? kuma_default_page_title()));
define('BOARD_SUB', (string)($page['sub'] ?? kuma_default_page_sub()));
define('TIMEZONE', cfg('kuma.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$embedded = isset($_GET['noticker']);
$configured = kuma_configured() && kuma_page_has_source($page);
$data = $pageOff
    ? ['ok' => false, 'error' => 'This page is marked Off wall in admin.', 'monitors' => [], 'counts' => []]
    : kuma_fetch_wall_data($page);
$cacheTtl = kuma_cache_ttl();
$monitors = is_array($data['monitors'] ?? null) ? $data['monitors'] : [];
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : ['total' => 0, 'up' => 0, 'down' => 0, 'pending' => 0, 'maintenance' => 0];
$downMonitors = array_values(array_filter($monitors, static fn($m) => (int)($m['status'] ?? 0) === KUMA_STATUS_DOWN));

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(BOARD_TITLE) ?> — Signage</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  <?= signage_theme_css() ?>

  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none;
              <?= signage_viewport_css() ?> }
  .board { width:1920px; height:100%; padding:28px 32px; display:flex; flex-direction:column; gap:22px; }
  .head { display:flex; align-items:baseline; justify-content:space-between; flex:0 0 96px; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:64px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:56px; color:var(--mist); }

  .summary { display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
  .pill { display:inline-flex; align-items:center; gap:10px; padding:10px 18px;
          border-radius:999px; border:1px solid var(--hairline); background:var(--harbor);
          font-size:22px; color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; font-variant-numeric:tabular-nums; }
  .pill .dot { width:12px; height:12px; border-radius:50%; display:inline-block; }
  .pill .dot.ok { background:var(--ok); }
  .pill .dot.warn { background:var(--warn); }
  .pill .dot.bad { background:var(--bad); }
  .pill .dot.maint { background:#7499ff; }

  .main { flex:1; min-height:0; display:grid; grid-template-columns:1.45fr 1fr; gap:24px; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:22px 26px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:14px; flex:0 0 auto; }
  .panel .body { flex:1; min-height:0; overflow:hidden; }

  .mon-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; align-content:start; }
  .mon { display:grid; grid-template-columns:14px 1fr auto; gap:12px; align-items:center;
         padding:12px 14px; border-radius:10px; background:var(--lake-night);
         border:1px solid rgba(38,52,77,.55); min-width:0; }
  .mon .dot { width:14px; height:14px; border-radius:50%; background:var(--ok); }
  .mon.down .dot { background:var(--bad); }
  .mon.pending .dot { background:var(--warn); }
  .mon.maint .dot { background:#7499ff; }
  .mon .name { font-size:22px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mon .meta { font-size:17px; color:var(--mist); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mon .side { text-align:right; font-size:18px; color:var(--mist); font-variant-numeric:tabular-nums; white-space:nowrap; }
  .mon .side .uptime { font-size:15px; margin-top:4px; }

  .issues { display:flex; flex-direction:column; gap:12px; }
  .issue { padding:14px 16px; border-radius:10px; background:var(--lake-night); border:1px solid rgba(38,52,77,.55); }
  .issue .title { font-size:24px; }
  .issue .sub { font-size:18px; color:var(--mist); margin-top:4px; }
  .issue.bad { border-color:rgba(228,89,89,.35); }

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
      <div class="setupmsg">Set <code>KUMA_URL</code> in <strong>admin.php → Uptime Kuma</strong>, then add a page with a
      <code>status page slug</code> and/or set the board <code>API key</code>.
      Enable <strong>Allow private URL fetches</strong> when Kuma runs on your LAN.</div>
    </div>
  <?php elseif (($data['error'] ?? '') !== '' && $monitors === []): ?>
    <div class="panel">
      <div class="k">Configuration</div>
      <div class="err"><?= h((string)$data['error']) ?></div>
    </div>
  <?php else: ?>
    <div class="summary">
      <div class="pill"><span class="dot ok"></span> Up <strong><?= (int)($counts['up'] ?? 0) ?></strong></div>
      <div class="pill"><span class="dot bad"></span> Down <strong><?= (int)($counts['down'] ?? 0) ?></strong></div>
      <?php if ((int)($counts['pending'] ?? 0) > 0): ?>
      <div class="pill"><span class="dot warn"></span> Pending <strong><?= (int)$counts['pending'] ?></strong></div>
      <?php endif; ?>
      <?php if ((int)($counts['maintenance'] ?? 0) > 0): ?>
      <div class="pill"><span class="dot maint"></span> Maintenance <strong><?= (int)$counts['maintenance'] ?></strong></div>
      <?php endif; ?>
      <div class="pill">Monitors <strong><?= (int)($counts['total'] ?? 0) ?></strong></div>
    </div>

    <div class="main">
      <div class="panel">
        <div class="k">Monitors</div>
        <div class="body">
          <?php if ($monitors === []): ?>
            <div class="nodata">No monitors matched your filters.</div>
          <?php else: ?>
            <div class="mon-grid">
              <?php foreach ($monitors as $mon):
                $st = (int)($mon['status'] ?? KUMA_STATUS_PENDING);
                $cls = match ($st) {
                    KUMA_STATUS_DOWN => 'down',
                    KUMA_STATUS_PENDING => 'pending',
                    KUMA_STATUS_MAINTENANCE => 'maint',
                    default => '',
                };
              ?>
              <div class="mon <?= h($cls) ?>">
                <span class="dot"></span>
                <div>
                  <div class="name"><?= h((string)($mon['name'] ?? '')) ?></div>
                  <div class="meta"><?= h((string)($mon['target'] ?? $mon['type'] ?? '')) ?></div>
                </div>
                <div class="side">
                  <?= h((string)($mon['status_label'] ?? '')) ?>
                  <?php if (($mon['ping'] ?? null) !== null): ?>
                    <div><?= (int)$mon['ping'] ?> ms</div>
                  <?php endif; ?>
                  <?php if (($mon['uptime_24h'] ?? null) !== null): ?>
                    <div class="uptime"><?= h((string)$mon['uptime_24h']) ?>% 24h</div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="k">Down now</div>
        <div class="body">
          <?php if ($downMonitors === []): ?>
            <div class="nodata">All monitored services are up.</div>
          <?php else: ?>
            <div class="issues">
              <?php foreach ($downMonitors as $mon): ?>
              <div class="issue bad">
                <div class="title"><?= h((string)($mon['name'] ?? '')) ?></div>
                <div class="sub"><?= h((string)($mon['msg'] !== '' ? $mon['msg'] : ($mon['target'] ?? 'Down'))) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="stamp">Uptime Kuma · cache <?= (int)$cacheTtl ?>s<?php if (!empty($data['stale'])): ?> · stale<?php endif; ?><?php if (($data['error'] ?? '') !== '' && $monitors !== []): ?> · <?= h((string)$data['error']) ?><?php endif; ?></div>
</div>

<?php if ($showClock): ?>
<script>
(function () {
  const tz = <?= json_encode(TIMEZONE, JSON_UNESCAPED_SLASHES) ?>;
  const el = document.getElementById('clock');
  function tick() {
    const now = new Date();
    el.textContent = now.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', timeZone: tz });
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

<?php if (empty($embedded) && !isset($_GET['noticker'])): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
