<?php
/**
 * HOMELAB OPS — 1920×1080 signage
 * Proxmox node/VM health + AdGuard Home DNS stats + HTTP service checks + WAN latency.
 *
 * Setup:
 *   PROXMOX — create an API token: Datacenter → Permissions → API Tokens
 *     (read-only role like PVEAuditor is plenty). Format below.
 *   ADGUARD — uses the same credentials as the web UI (HTTP basic auth).
 *   SERVICES — any URLs you want up/down + response-time checks for.
 * All calls are server-side; nothing sensitive reaches the signage browser.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

define('PVE_HOST', cfg('homelab.PVE_HOST', 'https://192.168.86.2:8006'));
define('PVE_TOKEN_ID', cfg('homelab.PVE_TOKEN_ID', 'signage@pve!signage'));
define('PVE_TOKEN_SECRET', cfg('homelab.PVE_TOKEN_SECRET', 'PUT-TOKEN-SECRET-HERE'));
define('PVE_VERIFY_TLS', cfg('homelab.PVE_VERIFY_TLS', false));

define('ADGUARD_URL', cfg('homelab.ADGUARD_URL', 'http://192.168.86.3'));
define('ADGUARD_USER', cfg('homelab.ADGUARD_USER', 'admin'));
define('ADGUARD_PASS', cfg('homelab.ADGUARD_PASS', 'PUT-PASSWORD-HERE'));

define('SERVICES', cfg('homelab.SERVICES', []));
define('LATENCY_TARGET', cfg('homelab.LATENCY_TARGET', 'https://1.1.1.1'));

define('TIMEZONE', cfg('homelab.TIMEZONE', 'America/Detroit'));
const CACHE_DIR = __DIR__ . '/cache';
define('CACHE_TTL', cfg('homelab.CACHE_TTL', 30));

date_default_timezone_set(TIMEZONE);
$embedded = isset($_GET['noticker']);
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(96 * $boardH / 1080));
$rowMid  = max(248, (int)round(300 * $boardH / 1080));
$heightCss = signage_viewport_height();
$vmLimit = 8;
$GLOBALS['diag'] = [];

function http_get(string $url, array $headers = [], ?string $userpass = null, bool $verify = true, int $timeout = 6): array
{
    $policy = signage_fetch_url_allowed($url, true);
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'ms' => 0, 'err' => $policy['error'] ?? 'blocked URL'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_NOBODY => false,
        CURLOPT_USERAGENT => 'HomeSignage/ServiceCheck/1.0',
    ]);
    if ($userpass !== null) curl_setopt($ch, CURLOPT_USERPWD, $userpass);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'code' => $code, 'ms' => $ms, 'err' => $err];
}

function cached_json(string $key, callable $fetch): ?array
{
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
    $f = CACHE_DIR . "/$key.json";
    if (is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $d = $fetch();
    if (is_array($d)) { @file_put_contents($f, json_encode($d), LOCK_EX); return $d; }
    if (is_file($f)) { $d = json_decode((string)file_get_contents($f), true); return is_array($d) ? $d : null; }
    return null;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function gb(float $bytes): string { return number_format($bytes / 1073741824, $bytes > 107374182400 ? 0 : 1); }

// ── Proxmox: cluster resources ───────────────────────────────────────────────
$pveConfigured = PVE_TOKEN_SECRET !== 'PUT-TOKEN-SECRET-HERE';
$nodes = []; $vms = []; $storage = [];
if ($pveConfigured) {
    $res = cached_json('pve_resources', function () {
        $r = http_get(rtrim(PVE_HOST, '/') . '/api2/json/cluster/resources',
            ['Authorization: PVEAPIToken=' . PVE_TOKEN_ID . '=' . PVE_TOKEN_SECRET],
            null, PVE_VERIFY_TLS);
        if ($r['code'] === 200 && $r['body']) { $j = json_decode($r['body'], true); return $j['data'] ?? null; }
        $GLOBALS['diag']['proxmox'] = $r['err'] !== '' ? 'curl: ' . $r['err'] : 'HTTP ' . $r['code'];
        return null;
    });
    foreach ((array)$res as $item) {
        switch ($item['type'] ?? '') {
            case 'node':
                $nodes[] = $item; break;
            case 'qemu': case 'lxc':
                $vms[] = $item; break;
            case 'storage':
                if (($item['status'] ?? '') === 'available') $storage[$item['storage']] = $item;
                break;
        }
    }
    usort($vms, fn($a, $b) => ($b['cpu'] ?? 0) <=> ($a['cpu'] ?? 0));
}
$running = count(array_filter($vms, fn($v) => ($v['status'] ?? '') === 'running'));

// ── AdGuard Home stats ───────────────────────────────────────────────────────
$agConfigured = ADGUARD_PASS !== 'PUT-PASSWORD-HERE';
$ag = $agConfigured ? cached_json('adguard_stats', function () {
    $r = http_get(rtrim(ADGUARD_URL, '/') . '/control/stats', [], ADGUARD_USER . ':' . ADGUARD_PASS);
    if ($r['code'] === 200 && $r['body']) return json_decode($r['body'], true);
    $GLOBALS['diag']['adguard'] = $r['err'] !== '' ? 'curl: ' . $r['err'] : 'HTTP ' . $r['code'];
    return null;
}) : null;
$agQueries = (int)($ag['num_dns_queries'] ?? 0);
$agBlocked = (int)($ag['num_blocked_filtering'] ?? 0);
$agPct     = $agQueries > 0 ? round($agBlocked / $agQueries * 100, 1) : 0;

// ── Service checks + WAN latency (cached together, run live each TTL) ───────
$checkKey = 'service_checks_' . md5(json_encode(SERVICES) . '|' . LATENCY_TARGET);
$checks = cached_json($checkKey, function () {
    $out = ['services' => [], 'wan_ms' => null];
    foreach (SERVICES as $name => $url) {
        $r = http_get($url, [], null, false, 4);
        $up = $r['err'] === '' && $r['code'] > 0 && $r['code'] < 500;
        $out['services'][] = ['name' => $name, 'up' => $up, 'ms' => $r['ms'], 'code' => $r['code']];
    }
    $r = http_get(LATENCY_TARGET, [], null, false, 4);
    if ($r['err'] === '') $out['wan_ms'] = $r['ms'];
    return $out;
});
$services = $checks['services'] ?? [];
$wanMs    = $checks['wan_ms'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Homelab Ops</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
          --up:#39c46d; --down:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '28px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 18 : 24 ?>px;
           grid-template-columns: 1fr 1fr 1fr;
           grid-template-rows: <?= $rowHead ?>px <?= $rowMid ?>px minmax(0, 1fr) auto;
           grid-template-areas:
             "head head head"
             "node dns wan"
             "vms vms svc"
             "meta meta meta"; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; min-height:0; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 64 ?>px; }
  .head h1 span { color:var(--beacon); }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 46 : 56 ?>px; color:var(--mist); }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '18px 22px' : '26px 32px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); }
  .bignum { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 88 : 110 ?>px; line-height:1;
            color:var(--beacon); font-variant-numeric:tabular-nums; }
  .bignum small { font-size:<?= $boardH < 1080 ? 36 : 44 ?>px; color:var(--mist); font-weight:600; }
  .sub { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); margin-top:6px; }

  .meter { margin-top:<?= $boardH < 1080 ? 10 : 16 ?>px; }
  .meter .lab { display:flex; justify-content:space-between; font-size:<?= $boardH < 1080 ? 18 : 21 ?>px; color:var(--mist); margin-bottom:6px; }
  .meter .track { height:16px; background:var(--lake-night); border-radius:8px; overflow:hidden; }
  .meter .fill { height:100%; background:var(--beacon); border-radius:8px; }
  .meter .fill.hot { background:var(--down); }

  .node { grid-area:node; } .dns { grid-area:dns; } .wan { grid-area:wan; }
  .vms { grid-area:vms; min-height:0; } .svc { grid-area:svc; min-height:0; }

  table { width:100%; border-collapse:collapse; margin-top:<?= $boardH < 1080 ? 8 : 14 ?>px; }
  th { text-align:left; font-size:<?= $boardH < 1080 ? 15 : 17 ?>px; letter-spacing:1px; text-transform:uppercase; color:var(--mist);
       font-weight:500; padding:<?= $boardH < 1080 ? '4px 6px' : '6px 8px' ?>; border-bottom:1px solid var(--hairline); }
  td { font-size:<?= $boardH < 1080 ? 20 : 23 ?>px; padding:<?= $boardH < 1080 ? '8px 6px' : '11px 8px' ?>; border-bottom:1px solid var(--hairline);
       white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  td.mono { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 18 : 20 ?>px; color:var(--mist); }
  .dot { display:inline-block; width:16px; height:16px; border-radius:50%; margin-right:12px;
         vertical-align:-1px; }
  .ok { background:var(--up); } .bad { background:var(--down); }
  .svcrow { display:flex; align-items:center; justify-content:space-between;
            border-bottom:1px solid var(--hairline); padding:<?= $boardH < 1080 ? '12px 4px' : '17px 4px' ?>; }
  .svcrow:last-child { border-bottom:none; }
  .svcrow .n { font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; font-weight:500; }
  .svcrow .ms { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 20 : 23 ?>px; color:var(--mist); }
  .storagebars { margin-top:<?= $boardH < 1080 ? 8 : 14 ?>px; }
  .notcfg { font-size:<?= $boardH < 1080 ? 20 : 24 ?>px; color:var(--mist); margin-top:14px; line-height:1.5; }
  .notcfg code { background:var(--lake-night); padding:2px 8px; border-radius:6px; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1>Homelab <span>&middot; Ops</span></h1>
    <div id="clock">--:--</div>
  </div>

  <section class="panel node">
    <div class="k">Proxmox</div>
    <?php if ($pveConfigured && $nodes): $n = $nodes[0];
        $cpuPct = (int)round(($n['cpu'] ?? 0) * 100);
        $memPct = ($n['maxmem'] ?? 0) > 0 ? (int)round(($n['mem'] ?? 0) / $n['maxmem'] * 100) : 0; ?>
      <div class="bignum"><?= $running ?><small> / <?= count($vms) ?> running</small></div>
      <div class="meter"><div class="lab"><span>Node CPU</span><span><?= $cpuPct ?>%</span></div>
        <div class="track"><div class="fill<?= $cpuPct > 85 ? ' hot' : '' ?>" style="width:<?= $cpuPct ?>%"></div></div></div>
      <div class="meter"><div class="lab"><span>Node RAM</span><span><?= $memPct ?>% &middot; <?= gb($n['mem'] ?? 0) ?>/<?= gb($n['maxmem'] ?? 0) ?> GB</span></div>
        <div class="track"><div class="fill<?= $memPct > 90 ? ' hot' : '' ?>" style="width:<?= $memPct ?>%"></div></div></div>
    <?php elseif ($pveConfigured): ?>
      <div class="notcfg">Proxmox unreachable — <?= h($GLOBALS['diag']['proxmox'] ?? 'no data') ?></div>
    <?php else: ?>
      <div class="notcfg">Set <code>PVE_TOKEN_SECRET</code> to enable. Create a read-only API token under Datacenter &rarr; Permissions.</div>
    <?php endif; ?>
  </section>

  <section class="panel dns">
    <div class="k">AdGuard Home &middot; DNS</div>
    <?php if ($ag): ?>
      <div class="bignum"><?= $agPct ?><small>% blocked</small></div>
      <div class="sub"><?= number_format($agBlocked) ?> of <?= number_format($agQueries) ?> queries</div>
      <div class="meter"><div class="lab"><span>Block rate</span><span></span></div>
        <div class="track"><div class="fill" style="width:<?= min(100, $agPct) ?>%"></div></div></div>
    <?php elseif ($agConfigured): ?>
      <div class="notcfg">AdGuard unreachable — <?= h($GLOBALS['diag']['adguard'] ?? 'no data') ?></div>
    <?php else: ?>
      <div class="notcfg">Set <code>ADGUARD_PASS</code> to enable DNS stats.</div>
    <?php endif; ?>
  </section>

  <section class="panel wan">
    <div class="k">Internet</div>
    <div class="bignum"><?= $wanMs !== null ? $wanMs : '—' ?><small> ms</small></div>
    <div class="sub">Round trip to <?= h(parse_url(LATENCY_TARGET, PHP_URL_HOST)) ?></div>
    <div class="meter"><div class="lab"><span>Latency</span>
      <span><?= $wanMs !== null ? ($wanMs < 40 ? 'excellent' : ($wanMs < 100 ? 'good' : 'degraded')) : 'offline?' ?></span></div>
      <div class="track"><div class="fill<?= ($wanMs ?? 0) > 150 ? ' hot' : '' ?>" style="width:<?= min(100, (int)(($wanMs ?? 0) / 2)) ?>%"></div></div></div>
  </section>

  <section class="panel vms">
    <div class="k">Guests by CPU</div>
    <?php if ($vms): ?>
      <table>
        <tr><th></th><th>Name</th><th>Type</th><th>CPU</th><th>RAM</th><th>Uptime</th></tr>
        <?php foreach (array_slice($vms, 0, $vmLimit) as $v):
          $up = ($v['status'] ?? '') === 'running'; ?>
          <tr>
            <td style="width:36px"><span class="dot <?= $up ? 'ok' : 'bad' ?>"></span></td>
            <td><?= h($v['name'] ?? ('#' . ($v['vmid'] ?? '?'))) ?></td>
            <td class="mono"><?= h($v['type'] ?? '') ?></td>
            <td class="mono"><?= $up ? (int)round(($v['cpu'] ?? 0) * 100) . '%' : '—' ?></td>
            <td class="mono"><?= $up && ($v['maxmem'] ?? 0) > 0 ? (int)round(($v['mem'] ?? 0) / $v['maxmem'] * 100) . '%' : '—' ?></td>
            <td class="mono"><?= $up ? floor(($v['uptime'] ?? 0) / 86400) . 'd' : h($v['status'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="storagebars">
        <?php foreach (array_slice($storage, 0, 2) as $name => $s):
          $pct = ($s['maxdisk'] ?? 0) > 0 ? (int)round(($s['disk'] ?? 0) / $s['maxdisk'] * 100) : 0; ?>
          <div class="meter"><div class="lab"><span>Storage &middot; <?= h($name) ?></span>
            <span><?= $pct ?>% &middot; <?= gb($s['disk'] ?? 0) ?>/<?= gb($s['maxdisk'] ?? 0) ?> GB</span></div>
            <div class="track"><div class="fill<?= $pct > 85 ? ' hot' : '' ?>" style="width:<?= $pct ?>%"></div></div></div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="notcfg">Guest list appears once Proxmox is connected.</div>
    <?php endif; ?>
  </section>

  <section class="panel svc">
    <div class="k">Services</div>
    <?php if ($services): foreach ($services as $s): ?>
      <div class="svcrow">
        <span class="n"><span class="dot <?= $s['up'] ? 'ok' : 'bad' ?>"></span><?= h($s['name']) ?></span>
        <span class="ms"><?= $s['up'] ? $s['ms'] . ' ms' : 'DOWN' ?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="notcfg">Add services in admin &rarr; Homelab &rarr; <strong>Service checks</strong> (name + URL per row).</div>
    <?php endif; ?>
  </section>
  <div class="stamp">Proxmox API &middot; AdGuard Home<?= $GLOBALS['diag'] ? ' · ' . h(implode('; ', array_map(fn($k,$v)=>"$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag']))) : '' ?></div>
</div>
<script>
  function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
    document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
  tick(); setInterval(tick, 1000);
  <?php if (!$embedded): ?>
  setTimeout(() => location.reload(), 60 * 1000);
  <?php endif; ?>
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
