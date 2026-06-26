<?php
/**
 * NEW CVEs — 1920×1080 signage
 *
 * Data: NIST National Vulnerability Database API 2.0 (free; API key optional).
 * https://nvd.nist.gov/developers/vulnerabilities
 */

require_once dirname(__DIR__, 2) . '/config.php';

define('TITLE', cfg('cve.TITLE', 'New CVEs'));
define('SUBTITLE', cfg('cve.SUBTITLE', 'NIST NVD'));
define('TIMEZONE', cfg('cve.TIMEZONE', 'America/Detroit'));
define('RELOAD_SEC', cfg('cve.RELOAD_SEC', 0));
define('MAX_ITEMS', max(4, min(12, (int)cfg('cve.MAX_ITEMS', 8))));
define('LOOKBACK_DAYS', max(1, min(30, (int)cfg('cve.LOOKBACK_DAYS', 7))));
define('USER_AGENT', cfg('cve.USER_AGENT', 'HomeSignage/CVEBoard/1.0 (signage-suite)'));
define('NVD_API_KEY', trim((string)cfg('cve.NVD_API_KEY', '')));
const CACHE_DIR = SIGNAGE_ROOT . '/cache';
define('CACHE_TTL', cfg('cve.CACHE_TTL', 3600));

date_default_timezone_set(TIMEZONE);
$showClock = signage_show_clock();
$GLOBALS['diag'] = [];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function cve_plain(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/** @return array{score:?float,severity:string,version:string} */
function cve_extract_cvss(array $cve): array
{
    $metrics = $cve['metrics'] ?? [];
    if (!is_array($metrics)) {
        return ['score' => null, 'severity' => '', 'version' => ''];
    }
    foreach (['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $version) {
        $bucket = $metrics[$version] ?? null;
        if (!is_array($bucket) || $bucket === []) {
            continue;
        }
        $cvss = $bucket[0]['cvssData'] ?? null;
        if (!is_array($cvss)) {
            continue;
        }
        $score = isset($cvss['baseScore']) ? (float)$cvss['baseScore'] : null;
        $severity = strtoupper(trim((string)($cvss['baseSeverity'] ?? '')));
        return ['score' => $score, 'severity' => $severity, 'version' => $version];
    }
    return ['score' => null, 'severity' => '', 'version' => ''];
}

/** @return array{id:string,published:string,summary:string,score:?float,severity:string,cvss_version:string,url:string}|null */
function cve_normalize(?array $entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }
    $cve = $entry['cve'] ?? $entry;
    if (!is_array($cve)) {
        return null;
    }
    $id = trim((string)($cve['id'] ?? ''));
    if ($id === '') {
        return null;
    }
    $summary = '';
    foreach ($cve['descriptions'] ?? [] as $desc) {
        if (!is_array($desc)) {
            continue;
        }
        if (($desc['lang'] ?? '') === 'en' && trim((string)($desc['value'] ?? '')) !== '') {
            $summary = cve_plain((string)$desc['value']);
            break;
        }
    }
    $cvss = cve_extract_cvss($cve);
    return [
        'id' => $id,
        'published' => trim((string)($cve['published'] ?? '')),
        'summary' => $summary,
        'score' => $cvss['score'],
        'severity' => $cvss['severity'],
        'cvss_version' => $cvss['version'],
        'url' => 'https://nvd.nist.gov/vuln/detail/' . rawurlencode($id),
    ];
}

function cve_nvd_query_url(): string
{
    $end = new DateTime('now', new DateTimeZone('UTC'));
    $start = (clone $end)->modify('-' . LOOKBACK_DAYS . ' days');
    $params = [
        'resultsPerPage' => min(100, max(MAX_ITEMS, 20)),
        'pubStartDate' => $start->format('Y-m-d\TH:i:s.000'),
        'pubEndDate' => $end->format('Y-m-d\TH:i:s.000'),
    ];
    return 'https://services.nvd.nist.gov/rest/json/cves/2.0?' . http_build_query($params);
}

/** @return list<array<string,mixed>>|null */
function cve_fetch_recent(): ?array
{
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $cacheKey = 'cve_' . LOOKBACK_DAYS . '_' . MAX_ITEMS;
    $f = CACHE_DIR . '/' . $cacheKey . '.json';
    if (CACHE_TTL > 0 && is_file($f) && (time() - filemtime($f)) < CACHE_TTL) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            return $d;
        }
    }

    $headers = ['User-Agent: ' . USER_AGENT];
    if (NVD_API_KEY !== '') {
        $headers[] = 'apiKey: ' . NVD_API_KEY;
    }

    $ch = curl_init(cve_nvd_query_url());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        $items = is_array($d['vulnerabilities'] ?? null) ? $d['vulnerabilities'] : null;
        if (is_array($items)) {
            @file_put_contents($f, json_encode($items), LOCK_EX);
            return $items;
        }
    }
    $GLOBALS['diag']['nvd'] = $err !== '' ? "curl: $err" : "HTTP $code";

    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/** @param list<array<string,mixed>> $raw */
function cve_recent_items(array $raw, int $limit): array
{
    $out = [];
    foreach ($raw as $entry) {
        $norm = cve_normalize($entry);
        if ($norm !== null) {
            $out[] = $norm;
        }
    }
    usort($out, static fn($a, $b) => strcmp((string)$b['published'], (string)$a['published']));
    return array_slice($out, 0, $limit);
}

function cve_format_date(string $iso): string
{
    if ($iso === '') {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('M j, Y', $t) : substr($iso, 0, 10);
}

function cve_severity_class(string $severity): string
{
    return match (strtoupper($severity)) {
        'CRITICAL' => 'crit',
        'HIGH' => 'high',
        'MEDIUM' => 'med',
        'LOW' => 'low',
        default => 'unk',
    };
}

$raw = cve_fetch_recent();
$cves = $raw ? cve_recent_items($raw, MAX_ITEMS) : [];
$hero = $cves[0] ?? null;
$list = array_slice($cves, 1);
$hasData = $hero !== null;
$embedded = isset($_GET['noticker']);
$heightCss = signage_viewport_height();
$boardH = signage_frame_height();
$rowHead = max(72, (int)round(88 * $boardH / 1080));
$heroSev = $hero ? cve_severity_class((string)$hero['severity']) : 'unk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h(TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&family=IBM+Plex+Serif:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --harbor:#141f33; --hairline:#26344d;
          --snow:#edf2fb; --mist:#8aa0c0; --beacon:#ffb347;
          --crit:#ff4d6d; --high:#ff8f47; --med:#ffd166; --low:#8aa0c0; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:<?= $heightCss ?>; overflow:hidden; background:var(--lake-night);
              color:var(--snow); font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  .board { width:1920px; height:<?= $heightCss ?>; padding:<?= $boardH < 1080 ? '20px 28px' : '24px 32px' ?>;
           display:grid; gap:<?= $boardH < 1080 ? 16 : 20 ?>px;
           grid-template-columns: 1.15fr 0.85fr;
           grid-template-rows: <?= $rowHead ?>px 1fr auto;
           grid-template-areas: "head head" "hero side" "meta meta";
           min-height:0; }
  .head { grid-area:head; display:flex; align-items:baseline; justify-content:space-between; }
  .head h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 54 : 62 ?>px; }
  .head h1 span { color:var(--beacon); }
  .head .sub { font-size:<?= $boardH < 1080 ? 22 : 26 ?>px; color:var(--mist); margin-left:16px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:<?= $boardH < 1080 ? 44 : 52 ?>px;
           color:var(--mist); font-variant-numeric:tabular-nums; }

  .panel { background:var(--harbor); border:1px solid var(--hairline); border-radius:14px;
           padding:<?= $boardH < 1080 ? '20px 24px' : '26px 32px' ?>; min-height:0; overflow:hidden; }
  .panel .k { font-size:18px; letter-spacing:3px; text-transform:uppercase; color:var(--mist); margin-bottom:12px; }

  .hero { grid-area:hero; display:grid; grid-template-columns: auto 1fr; gap:<?= $boardH < 1080 ? 18 : 24 ?>px;
          align-items:stretch; min-height:0; }
  .hero-body { display:flex; flex-direction:column; min-height:0; min-width:0; }
  .hero-id { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 34 : 40 ?>px; font-weight:500;
             color:var(--beacon); writing-mode:vertical-rl; transform:rotate(180deg); letter-spacing:1px; }
  .hero-title { font-family:'Big Shoulders Display'; font-weight:700; font-size:<?= $boardH < 1080 ? 42 : 48 ?>px;
                line-height:1.1; margin-bottom:12px; }
  .hero-meta { display:flex; flex-wrap:wrap; gap:10px 16px; margin-bottom:14px; }
  .pill { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:999px;
          border:1px solid var(--hairline); background:var(--lake-night); font-size:<?= $boardH < 1080 ? 17 : 19 ?>px;
          color:var(--mist); }
  .pill strong { color:var(--snow); font-weight:600; }
  .pill.crit strong { color:var(--crit); }
  .pill.high strong { color:var(--high); }
  .pill.med strong { color:var(--med); }
  .pill.low strong { color:var(--low); }
  .hero-desc { font-family:'IBM Plex Serif',serif; font-size:<?= $boardH < 1080 ? 24 : 28 ?>px; line-height:1.42;
               flex:1; min-height:0; overflow:hidden; }

  .side { grid-area:side; display:flex; flex-direction:column; min-height:0; }
  .list { flex:1; min-height:0; display:flex; flex-direction:column; gap:<?= $boardH < 1080 ? 8 : 10 ?>px; overflow:hidden; }
  .row { display:grid; grid-template-columns: 1fr auto; gap:12px; align-items:center;
         padding:<?= $boardH < 1080 ? '10px 12px' : '12px 14px' ?>; background:var(--lake-night);
         border:1px solid var(--hairline); border-radius:10px; min-width:0; }
  .row .id { font-family:'IBM Plex Mono',monospace; font-size:<?= $boardH < 1080 ? 17 : 18 ?>px; color:var(--beacon); }
  .row .sub { font-size:<?= $boardH < 1080 ? 16 : 17 ?>px; color:var(--mist); margin-top:4px;
              white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .row .score { text-align:right; white-space:nowrap; }
  .row .score .num { font-family:'Big Shoulders Display'; font-size:<?= $boardH < 1080 ? 30 : 34 ?>px;
                     font-variant-numeric:tabular-nums; line-height:1; }
  .row .score .sev { font-size:<?= $boardH < 1080 ? 14 : 15 ?>px; letter-spacing:1px; text-transform:uppercase; margin-top:2px; }
  .row .score.crit .num, .row .score.crit .sev { color:var(--crit); }
  .row .score.high .num, .row .score.high .sev { color:var(--high); }
  .row .score.med .num, .row .score.med .sev { color:var(--med); }
  .row .score.low .num, .row .score.low .sev { color:var(--low); }

  .notcfg { font-size:24px; color:var(--mist); line-height:1.55; padding:20px 0; grid-column:1/-1; }
  <?= signage_stamp_css() ?>
  .stamp { grid-area:meta; }
</style>
</head>
<body>
<div class="board">
  <div class="head">
    <h1><?= h(TITLE) ?><span class="sub"><?= h(SUBTITLE) ?> · last <?= (int)LOOKBACK_DAYS ?> days</span></h1>
    <?php if ($showClock): ?><div id="clock">--:--</div><?php endif; ?>
  </div>

  <?php if ($hasData): ?>
  <section class="panel hero">
    <div class="hero-id"><?= h($hero['id']) ?></div>
    <div class="hero-body">
      <div class="k">Latest published</div>
      <div class="hero-meta">
        <?php if ($hero['score'] !== null): ?>
        <span class="pill <?= h($heroSev) ?>"><strong><?= h(number_format((float)$hero['score'], 1)) ?></strong> CVSS</span>
        <?php endif; ?>
        <?php if ($hero['severity'] !== ''): ?>
        <span class="pill <?= h($heroSev) ?>"><strong><?= h($hero['severity']) ?></strong></span>
        <?php endif; ?>
        <?php if ($hero['published'] !== ''): ?>
        <span class="pill">Published <strong><?= h(cve_format_date($hero['published'])) ?></strong></span>
        <?php endif; ?>
      </div>
      <?php if ($hero['summary'] !== ''): ?>
      <div class="hero-desc"><?= h($hero['summary']) ?></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel side">
    <div class="k">Also published recently</div>
    <div class="list">
      <?php foreach ($list as $c):
        $sev = cve_severity_class((string)$c['severity']);
      ?>
      <div class="row">
        <div>
          <div class="id"><?= h($c['id']) ?></div>
          <div class="sub"><?= h($c['summary'] !== '' ? $c['summary'] : cve_format_date($c['published'])) ?></div>
        </div>
        <div class="score <?= h($sev) ?>">
          <?php if ($c['score'] !== null): ?>
          <div class="num"><?= h(number_format((float)$c['score'], 1)) ?></div>
          <?php endif; ?>
          <?php if ($c['severity'] !== ''): ?>
          <div class="sev"><?= h($c['severity']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php else: ?>
  <div class="notcfg">CVE feed unavailable<?= $GLOBALS['diag'] ? ' — ' . h($GLOBALS['diag']['nvd'] ?? '') : '' ?>.
    Check network access to <code>services.nvd.nist.gov</code>.</div>
  <?php endif; ?>

  <div class="stamp"><?= h(implode(' · ', array_filter([
    'nvd.nist.gov',
    count($cves) . ' CVEs',
    LOOKBACK_DAYS . 'd window',
    $GLOBALS['diag'] ? implode('; ', array_map(fn($k, $v) => "$k: $v", array_keys($GLOBALS['diag']), $GLOBALS['diag'])) : '',
  ]))) ?></div>
</div>
<script>
  <?php if ($showClock): ?>
  function tick() {
    const n = new Date();
    let h = n.getHours();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const m = String(n.getMinutes()).padStart(2, '0');
    const el = document.getElementById('clock');
    if (el) el.textContent = h + ':' + m + ' ' + ap;
  }
  tick();
  setInterval(tick, 1000);
  <?php endif; ?>
  <?php if (!$embedded && RELOAD_SEC > 0): ?>
  setTimeout(() => location.reload(), <?= (int)RELOAD_SEC * 1000 ?>);
  <?php endif; ?>
</script>
<?php if (!$embedded): include dirname(__DIR__, 2) . '/ticker.php'; endif; ?>
</body>
</html>
