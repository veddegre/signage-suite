<?php
/**
 * UNIFI NETWORK BOARD — 1920×1080 signage
 * Gateway, switches, and AP status from your local UniFi controller — no iframe.
 *
 * Dream Machine / UDM (most common):
 *   1. admin.php → UniFi Network → UNIFI_URL = https://<gateway-ip>
 *   2. Local admin user + password (Settings → Admins → local account with local access)
 *   3. Leave API key blank — Integrations is not on all UDM firmware yet
 *   4. Security → Allow private URL fetches
 *
 * Newer controllers (Network 9.3+): optional Integration API key instead of password.
 */

require_once dirname(__DIR__, 2) . '/lib/unifi_lib.php';

define('BOARD_TITLE', (string)cfg('unifi.BOARD_TITLE', unifi_default_title()));
define('BOARD_SUB', (string)cfg('unifi.BOARD_SUB', unifi_default_sub()));
define('TIMEZONE', cfg('unifi.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$embedded = isset($_GET['noticker']);
$configured = unifi_configured();
$data = unifi_fetch_wall_data();
$cacheTtl = unifi_cache_ttl();
$devices = is_array($data['devices'] ?? null) ? $data['devices'] : [];
$clients = is_array($data['clients'] ?? null) ? $data['clients'] : ['total' => 0, 'wireless' => 0, 'wired' => 0, 'guest' => 0];
$health = is_array($data['health'] ?? null) ? $data['health'] : [];
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : ['devices' => 0, 'online' => 0, 'offline' => 0];
$offlineDevices = array_values(array_filter($devices, static fn($d) => empty($d['online'])));

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
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347; --ok:#59db8f; --warn:#ffc859; --bad:#e45959; }
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
  .pill .dot.unknown { background:var(--mist); opacity:.55; }

  .main { flex:1; min-height:0; display:grid; grid-template-columns:1.4fr 1fr; gap:24px; }
  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:22px 26px; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
  .panel .k { font-size:20px; letter-spacing:2px; text-transform:uppercase; color:var(--mist);
              margin-bottom:14px; flex:0 0 auto; }
  .panel .body { flex:1; min-height:0; overflow:hidden; }

  .devices { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; align-content:start; }
  .device { display:grid; grid-template-columns:14px 1fr auto; gap:12px; align-items:center;
            padding:12px 14px; border-radius:10px; background:var(--lake-night);
            border:1px solid rgba(38,52,77,.55); min-width:0; }
  .device .dot { width:14px; height:14px; border-radius:50%; background:var(--ok); }
  .device.offline .dot { background:var(--bad); }
  .device .name { font-size:22px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .device .meta { font-size:17px; color:var(--mist); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .device .side { text-align:right; font-size:18px; color:var(--mist); font-variant-numeric:tabular-nums; }
  .device .side .kind { font-size:15px; letter-spacing:1px; text-transform:uppercase; }

  .issues { display:flex; flex-direction:column; gap:12px; }
  .issue { padding:14px 16px; border-radius:10px; background:var(--lake-night); border:1px solid rgba(38,52,77,.55); }
  .issue .title { font-size:24px; }
  .issue .sub { font-size:18px; color:var(--mist); margin-top:4px; }
  .issue.warn { border-color:rgba(255,200,89,.35); }
  .issue.bad { border-color:rgba(228,89,89,.35); }

  .nodata, .err, .setupmsg { font-size:24px; color:var(--mist); line-height:1.6; }
  .setupmsg code { color:var(--snow); background:var(--lake-night); padding:2px 10px; border-radius:6px; }
  <?= signage_stamp_css() ?>
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(BOARD_TITLE) ?> <span><?= h(BOARD_SUB) ?></span></h1>
    <?php if ($showClock): ?><div id="clock"></div><?php endif; ?>
  </div>

  <?php if (!$configured): ?>
    <div class="setupmsg">
      <strong>Dream Machine / UDM:</strong> set <code>UNIFI_URL</code> to <code>https://&lt;gateway-ip&gt;</code>,
      then a <strong>local admin</strong> username and password (Settings → Admins).
      Leave the API key blank — Integrations is only on newer Network firmware.<br><br>
      Also enable <strong>Allow private URL fetches</strong> under Security.
    </div>
  <?php else: ?>
    <div class="summary">
      <span class="pill"><strong><?= (int)($counts['online'] ?? 0) ?></strong> online</span>
      <?php if ((int)($counts['offline'] ?? 0) > 0): ?>
        <span class="pill"><strong><?= (int)$counts['offline'] ?></strong> offline</span>
      <?php endif; ?>
      <span class="pill"><strong><?= (int)($clients['total'] ?? 0) ?></strong> clients</span>
      <span class="pill"><?= (int)($clients['wireless'] ?? 0) ?> Wi‑Fi · <?= (int)($clients['wired'] ?? 0) ?> wired<?php if ((int)($clients['guest'] ?? 0) > 0): ?> · <?= (int)$clients['guest'] ?> guest<?php endif; ?></span>
      <span class="pill">Site <strong><?= h((string)($data['site'] ?? '')) ?></strong></span>
      <?php foreach (['wan' => 'WAN', 'wlan' => 'Wi‑Fi', 'lan' => 'LAN'] as $key => $label): ?>
        <?php $st = (string)($health[$key] ?? 'unknown'); ?>
        <span class="pill"><?= h($label) ?> <span class="dot <?= h(unifi_health_class($st)) ?>"></span> <?= h(unifi_health_label($st)) ?></span>
      <?php endforeach; ?>
      <?php if ((int)($data['pending'] ?? 0) > 0): ?>
        <span class="pill"><strong><?= (int)$data['pending'] ?></strong> pending adoption</span>
      <?php endif; ?>
    </div>

    <div class="main">
      <div class="panel">
        <div class="k">Devices</div>
        <div class="body">
          <?php if ($devices === []): ?>
            <div class="nodata"><?= h(unifi_error_hint($data['error'] ?? null)) ?></div>
          <?php else: ?>
            <div class="devices">
              <?php foreach ($devices as $dev): ?>
                <div class="device<?= empty($dev['online']) ? ' offline' : '' ?>">
                  <span class="dot"></span>
                  <div>
                    <div class="name"><?= h((string)($dev['name'] ?? 'Device')) ?></div>
                    <div class="meta"><?= h(unifi_device_meta_line($dev)) ?></div>
                  </div>
                  <div class="side">
                    <div class="kind"><?= h((string)($dev['kind_label'] ?? '')) ?></div>
                    <?php if ((string)($dev['kind'] ?? '') === 'ap' && (int)($dev['clients'] ?? 0) > 0): ?>
                      <div><?= (int)$dev['clients'] ?> clients</div>
                    <?php elseif ((string)($dev['uptime_label'] ?? '') !== '—'): ?>
                      <div><?= h((string)$dev['uptime_label']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="k">Status</div>
        <div class="body issues">
          <?php if (!empty($data['stale'])): ?>
            <div class="issue warn">
              <div class="title">Serving cached data</div>
              <div class="sub"><?= h(unifi_error_hint($data['error'] ?? 'Controller unreachable')) ?></div>
            </div>
          <?php endif; ?>
          <?php if ($offlineDevices !== []): ?>
            <div class="issue bad">
              <div class="title"><?= count($offlineDevices) ?> device<?= count($offlineDevices) === 1 ? '' : 's' ?> offline</div>
              <div class="sub"><?= h(implode(', ', array_map(static fn($d) => (string)($d['name'] ?? 'Device'), array_slice($offlineDevices, 0, 6)))) ?></div>
            </div>
          <?php elseif (($data['ok'] ?? false) && $devices !== []): ?>
            <div class="issue">
              <div class="title">All monitored devices online</div>
              <div class="sub"><?= count($devices) ?> device<?= count($devices) === 1 ? '' : 's' ?> on <?= h((string)($data['site'] ?? 'site')) ?></div>
            </div>
          <?php endif; ?>
          <?php if ((int)($data['pending'] ?? 0) > 0): ?>
            <div class="issue warn">
              <div class="title"><?= (int)$data['pending'] ?> pending adoption</div>
              <div class="sub">Open UniFi to adopt new hardware.</div>
            </div>
          <?php endif; ?>
          <?php if (in_array(unifi_health_class((string)($health['wan'] ?? '')), ['warn', 'bad'], true)): ?>
            <div class="issue bad">
              <div class="title">WAN health <?= h(unifi_health_label((string)($health['wan'] ?? ''))) ?></div>
              <div class="sub">Check gateway / ISP on the UniFi console.</div>
            </div>
          <?php endif; ?>
          <?php if (($data['ok'] ?? false) && $offlineDevices === [] && (int)($data['pending'] ?? 0) === 0 && !in_array(unifi_health_class((string)($health['wan'] ?? '')), ['warn', 'bad'], true)): ?>
            <div class="issue">
              <div class="title">Network healthy</div>
              <div class="sub"><?= (int)($clients['total'] ?? 0) ?> active clients · cache <?= (int)$cacheTtl ?>s</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="stamp">UniFi · <?= h((string)($data['api'] ?? 'api')) ?> · cache <?= (int)$cacheTtl ?>s<?php if (!empty($data['stale'])): ?> · stale<?php endif; ?></div>
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

<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
