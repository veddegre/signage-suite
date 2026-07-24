<?php
/**
 * WEATHER ALERT TICKER — shared include
 * Add  <?php include __DIR__ . '/ticker.php'; ?>  just before </body> on any
 * board. Overlays a scrolling ticker when NWS alerts are active; hidden otherwise.
 *
 * Polls ticker.php?api=1 on a short interval so alerts appear/disappear (and demo
 * mode toggles) without reloading the page — board.php / player.php can run for days.
 *
 * player.php loads board.php?noticker=1 in an iframe and includes this file in the
 * outer document so polling runs in the top-level PWA context (not a nested iframe).
 *
 * Seamless across slide changes: scroll/static phase is computed from the wall
 * clock so every board shows the same position at the same moment.
 *
 * All boards share one cache file per alert point, so the NWS API is hit at most
 * once per TICKER_TTL for that location (demo mode bypasses the cache).
 *
 * TICKER_MODE 'scroll' = marquee; 'static' = fixed bar cycling one alert at a
 * time (also clock-phased, also seamless).
 * Set TICKER_DEMO = true to preview with a fake alert, then turn it back off.
 */

// Inside the rotation shell (board.php) the shell renders the one true
// ticker; framed boards get ?noticker=1 appended and skip theirs.
if (isset($_GET['noticker'])) return;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/emergency_lib.php';
require_once __DIR__ . '/lib/screen_scope_lib.php';

$emergencyTicker = emergency_ticker_forces_display();

if (!signage_ticker_enabled() && !$emergencyTicker) {
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'alerts' => [],
            'mode'   => 'scroll',
            'demo'   => false,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    return;
}

$tickerScreen = signage_request_screen();
signage_ticker_bootstrap($tickerScreen);

if (!function_exists('signage_ticker_event_kind')) {
/** NWS event name → banner kind (warning/watch/advisory/statement). */
function signage_ticker_event_kind(string $event): string
{
    $e = strtolower(trim($event));
    if ($e === '' || $e === 'weather alert') {
        return 'alert';
    }
    if (str_ends_with($e, ' warning') || $e === 'warning') {
        return 'warning';
    }
    if (str_ends_with($e, ' watch') || $e === 'watch') {
        return 'watch';
    }
    if (str_ends_with($e, ' advisory') || $e === 'advisory') {
        return 'advisory';
    }
    if (str_ends_with($e, ' statement') || $e === 'statement') {
        return 'statement';
    }

    return 'alert';
}

/** Collapse NWS whitespace for one-line ticker text. */
function signage_ticker_clean_text(string $s): string
{
    $s = str_replace("\xc2\xa0", ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return trim($s);
}

/** First useful sentence from NWS description (skips boilerplate). */
function signage_ticker_description_snippet(string $description): string
{
    $description = signage_ticker_clean_text($description);
    if ($description === '') {
        return '';
    }
    foreach (preg_split('/(?<=[.!?])\s+/u', $description) ?: [] as $sentence) {
        $sentence = trim($sentence, " \t\n\r\0\x0B*-");
        if ($sentence === '' || strlen($sentence) < 16) {
            continue;
        }
        if (stripos($sentence, 'National Weather Service') === 0) {
            continue;
        }
        if (str_starts_with($sentence, '*')) {
            continue;
        }

        return strlen($sentence) > 300 ? substr($sentence, 0, 297) . '…' : $sentence;
    }

    return strlen($description) > 300 ? substr($description, 0, 297) . '…' : $description;
}

/** Build scroll text: timing, hazards, and instructions beyond the alert name. */
function signage_ticker_alert_detail(array $props): string
{
    $event = trim((string)($props['event'] ?? 'Weather Alert'));
    $headline = signage_ticker_clean_text((string)($props['headline'] ?? ''));
    $instruction = signage_ticker_clean_text((string)($props['instruction'] ?? ''));
    $description = (string)($props['description'] ?? '');
    $kind = signage_ticker_event_kind($event);
    $severity = (string)($props['severity'] ?? '');

    $parts = [];

    if ($headline !== '') {
        $detail = $headline;
        if (str_starts_with(strtolower($headline), strtolower($event))) {
            $tail = trim(substr($headline, strlen($event)));
            $tail = ltrim($tail, " \t-—:");
            if ($tail !== '') {
                $detail = $tail;
            }
        }
        $parts[] = $detail;
    }

    $snippet = signage_ticker_description_snippet($description);
    if ($snippet !== '' && !signage_ticker_text_contains($parts, $snippet)) {
        $parts[] = $snippet;
    }

    if ($instruction !== ''
        && ($kind === 'warning' || in_array($severity, ['Severe', 'Extreme'], true))
        && !signage_ticker_text_contains($parts, $instruction)) {
        $instr = strlen($instruction) > 220 ? substr($instruction, 0, 217) . '…' : $instruction;
        $parts[] = $instr;
    }

    if ($parts === []) {
        $parts[] = $event;
    }

    $text = signage_ticker_clean_text(implode(' — ', $parts));

    return strlen($text) > 520 ? substr($text, 0, 517) . '…' : $text;
}

function signage_ticker_text_contains(array $parts, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    $hay = strtolower(implode(' ', $parts));

    return str_contains($hay, strtolower($needle));
}
}

if (!function_exists('signage_weather_ticker_alerts')) {
function signage_weather_ticker_alerts(?string $screen = null): array
{
    if (TICKER_DEMO) {
        return [[
            'event'    => 'Beach Hazards Statement',
            'severity' => 'Moderate',
            'kind'     => 'statement',
            'headline' => 'Beach Hazards Statement issued until 10 PM EDT this evening — '
                        . 'dangerous swimming conditions and structural currents expected '
                        . 'along Lake Michigan beaches near Grand Haven and Holland.',
            'text'     => 'until 10 PM EDT this evening — dangerous swimming conditions and structural currents expected '
                        . 'along Lake Michigan beaches near Grand Haven and Holland.',
        ], [
            'event'    => 'Severe Thunderstorm Warning',
            'severity' => 'Severe',
            'kind'     => 'warning',
            'headline' => 'Severe Thunderstorm Warning for Ottawa County until 8:45 PM — '
                        . '60 mph wind gusts and quarter size hail possible.',
            'text'     => 'for Ottawa County until 8:45 PM EDT — 60 mph wind gusts and quarter size hail possible '
                        . '— For your protection move to an interior room on the lowest floor of a building.',
        ]];
    }

    if ($screen === null) {
        $screen = signage_request_screen();
    }
    $loc = rotation_screen_location($screen);
    $lat = (float)$loc['lat'];
    $lon = (float)$loc['lon'];

    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = $dir . '/ticker_alerts_' . sprintf('%.4F_%.4F', $lat, $lon) . '.dat';
    $maxAge = min(max((int)TICKER_TTL, 30), 90);   // cap so new alerts show within ~90s
    $raw = null;
    if (is_file($f) && (time() - filemtime($f)) < $maxAge) {
        $raw = (string)file_get_contents($f);
    } else {
        $ch = curl_init(sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', $lat, $lon));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4,
            CURLOPT_TIMEOUT=>8, CURLOPT_USERAGENT=>TICKER_UA,
            CURLOPT_HTTPHEADER=>['Accept: application/geo+json']]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) { @file_put_contents($f, $body, LOCK_EX); $raw = $body; }
        elseif (is_file($f)) { $raw = (string)file_get_contents($f); } // stale fallback, retry later
    }
    if ($raw === null) return [];

    $j = json_decode($raw, true);
    $rank = ['Minor' => 1, 'Moderate' => 2, 'Severe' => 3, 'Extreme' => 4];
    $min  = $rank[TICKER_MIN_SEVERITY] ?? 1;
    $out = [];
    foreach (($j['features'] ?? []) as $feat) {
        $p = $feat['properties'] ?? [];
        $sev = $p['severity'] ?? 'Minor';
        if (($rank[$sev] ?? 1) < $min) continue;
        $out[] = [
            'event'    => (string)($p['event'] ?? 'Weather Alert'),
            'severity' => $sev,
            'kind'     => signage_ticker_event_kind((string)($p['event'] ?? '')),
            'headline' => (string)($p['headline'] ?? $p['event'] ?? ''),
            'text'     => signage_ticker_alert_detail($p),
        ];
    }
    // Most severe first
    usort($out, fn($a, $b) => ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0));
    return $out;
}
}

if (!function_exists('signage_ticker_alerts')) {
function signage_ticker_alerts(?string $screen = null): array
{
    require_once __DIR__ . '/lib/emergency_lib.php';
    $emergency = emergency_ticker_alert();
    if ($emergency !== null) {
        if (emergency_ticker_show_weather()) {
            return array_merge([$emergency], signage_weather_ticker_alerts($screen));
        }

        return [$emergency];
    }

    return signage_weather_ticker_alerts($screen);
}
}

if (!function_exists('signage_ticker_news_items')) {
function signage_ticker_news_items(): array
{
    if (!defined('TICKER_NEWS_FEED') || TICKER_NEWS_FEED === '') {
        return [];
    }
    require_once __DIR__ . '/lib/rss_ticker_lib.php';

    return rss_ticker_headlines(TICKER_NEWS_FEED);
}
}

if (!function_exists('signage_ticker_news_label')) {
function signage_ticker_news_label(): string
{
    if (!defined('TICKER_NEWS_FEED') || TICKER_NEWS_FEED === '') {
        return 'News';
    }
    require_once __DIR__ . '/lib/rss_ticker_lib.php';
    $feed = rss_ticker_resolve_feed(TICKER_NEWS_FEED);
    if ($feed === null) {
        return 'News';
    }
    $name = trim((string)($feed['name'] ?? ''));

    return $name !== '' ? $name : 'News';
}
}

// JSON feed for client-side polling (board.php shell, direct board views).
if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $alerts = signage_ticker_alerts($tickerScreen);
    $news = [];
    $newsLabel = '';
    if ($alerts === [] && !$emergencyTicker) {
        $news = signage_ticker_news_items();
        if ($news !== []) {
            $newsLabel = signage_ticker_news_label();
        }
    }
    echo json_encode([
        'alerts' => $alerts,
        'news' => $news,
        'news_label' => $newsLabel,
        'mode'   => TICKER_MODE,
        'demo'   => (bool)TICKER_DEMO,
        'emergency' => $emergencyTicker,
        'emergency_weather' => emergency_ticker_show_weather(),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$tickerPollMs = TICKER_DEMO ? 15000 : 30000;
$tickerApiUrl = signage_ticker_api_url($signageTickerScreen ?? null);
require_once __DIR__ . '/lib/signage_theme_lib.php';
$tickerThemeKey = signage_active_theme_key();
?>
<style>
  <?= signage_theme_css_block($tickerThemeKey) ?>
  <?= signage_kiosk_cursor_css() ?>
  <?= signage_ticker_css_rules() ?>
</style>
<div id="signage-ticker-root"></div>
<script>
(function () {
  var API = <?= json_encode($tickerApiUrl) ?>;
  var POLL = <?= (int)$tickerPollMs ?>;
  var scrollRAF = null;
  var staticTimer = null;
  var lastKey = '';

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function alertBannerClass(kind) {
    if (kind === 'warning') return 'tk-severe';
    if (kind === 'watch') return 'tk-watch';
    if (kind === 'advisory' || kind === 'statement' || kind === 'alert') return 'tk-advisory';
    return 'tk-advisory';
  }

  function topAlertKind(alerts) {
    var rank = { warning: 4, watch: 3, advisory: 2, statement: 1, alert: 0 };
    var labels = { warning: 'Warning', watch: 'Watch', advisory: 'Advisory', statement: 'Statement', alert: 'Advisory' };
    var top = 'alert';
    alerts.forEach(function (a) {
      var k = a.kind || 'alert';
      if ((rank[k] || 0) > (rank[top] || 0)) top = k;
    });
    return { kind: top, label: labels[top] || 'Advisory' };
  }

  function alertDetail(a) {
    var text = (a && (a.text || a.headline || '')).trim();
    var event = (a && a.event) ? String(a.event).trim() : '';
    if (!text) return event;
    if (event && text.toLowerCase().indexOf(event.toLowerCase()) === 0) {
      text = text.slice(event.length).replace(/^[\s\-—:]+/, '').trim();
    }
    return text || event;
  }

  function itemHtml(a) {
    var detail = alertDetail(a);
    if (!detail || detail === a.event) {
      return '<span class="tk-item"><b>' + esc(a.event) + '</b></span>';
    }
    return '<span class="tk-item"><b>' + esc(a.event) + '</b><span class="tk-sep">&bull;</span>'
         + esc(detail) + '</span>';
  }

  function stopAnim() {
    if (scrollRAF) { cancelAnimationFrame(scrollRAF); scrollRAF = null; }
    if (staticTimer) { clearInterval(staticTimer); staticTimer = null; }
  }

  function startScroll(track) {
    var SPEED = 110;
    var half = 0;
    function step() {
      if (!track.isConnected) return;
      if (!half) {
        half = track.scrollWidth / 2;
        if (!half) { scrollRAF = requestAnimationFrame(step); return; }
      }
      var x = (Date.now() / 1000 * SPEED) % half;
      track.style.transform = 'translateX(' + (-x) + 'px)';
      scrollRAF = requestAnimationFrame(step);
    }
    scrollRAF = requestAnimationFrame(step);
  }

  function startStatic(track) {
    var items = track.querySelectorAll('.tk-item');
    function flip() {
      if (!items.length) return;
      var slot = Math.floor(Date.now() / 9000) % items.length;
      items.forEach(function (el, i) { el.style.display = i === slot ? 'inline-block' : 'none'; });
    }
    flip();
    staticTimer = setInterval(flip, 500);
  }

  function newsItemHtml(item) {
    return '<span class="tk-item">' + esc(item.title) + '</span>';
  }

  function apply(data) {
    var emergency = !!(data && data.emergency);
    if (document.body.classList.contains('signage-blank') && !emergency) {
      var blankRoot = document.getElementById('signage-ticker-root');
      stopAnim();
      if (blankRoot) blankRoot.innerHTML = '';
      document.documentElement.style.setProperty('--signage-ticker-inset', '0px');
      lastKey = '';
      return;
    }

    var hasAlerts = !!(data.alerts && data.alerts.length);
    var hasNews = !hasAlerts && !!(data.news && data.news.length);
    var key = JSON.stringify(data.alerts || []) + '|' + JSON.stringify(data.news || [])
      + '|' + (data.news_label || '') + '|' + (data.mode || 'scroll');
    if (key === lastKey) return;
    lastKey = key;

    var root = document.getElementById('signage-ticker-root');
    stopAnim();

    if (!hasAlerts && !hasNews) {
      root.innerHTML = '';
      document.documentElement.style.setProperty('--signage-ticker-inset', '0px');
      return;
    }

    if (hasNews) {
      var newsLabel = (data.news_label || 'News').trim() || 'News';
      var newsMode = data.mode === 'static' ? 'static' : 'scroll';
      var newsItems = '';
      if (newsMode === 'static') {
        data.news.forEach(function (item) {
          newsItems += '<span class="tk-item" style="display:none">' + esc(item.title) + '</span>';
        });
      } else {
        data.news.forEach(function (item) { newsItems += newsItemHtml(item); });
        newsItems += newsItems;
      }
      root.innerHTML =
        '<div id="signage-ticker" class="tk-news' + (newsMode === 'static' ? ' tk-static' : '') + '">'
        + '<div class="tk-tag">' + esc(newsLabel) + '</div>'
        + '<div class="tk-scroll"><div class="tk-track" id="tk-track">' + newsItems + '</div></div></div>';
      document.documentElement.style.setProperty('--signage-ticker-inset', '<?= SIGNAGE_TICKER_H ?>px');
      var newsTrack = document.getElementById('tk-track');
      if (newsMode === 'static') startStatic(newsTrack);
      else startScroll(newsTrack);
      return;
    }

    var banner = topAlertKind(data.alerts);
    var kindCls = alertBannerClass(banner.kind);
    var mode = data.mode === 'static' ? 'static' : 'scroll';
    var items = '';
    if (mode === 'static') {
      data.alerts.forEach(function (a) {
        var detail = alertDetail(a);
        items += '<span class="tk-item" style="display:none"><b>' + esc(a.event)
               + '</b><span class="tk-sep">&bull;</span>' + esc(detail) + '</span>';
      });
    } else {
      data.alerts.forEach(function (a) { items += itemHtml(a); });
      items += items;   // duplicate for seamless loop
    }

    root.innerHTML =
      '<div id="signage-ticker" class="' + kindCls + (mode === 'static' ? ' tk-static' : '') + '">'
      + '<div class="tk-tag"><span class="tk-dot"></span>' + esc(banner.label) + '</div>'
      + '<div class="tk-scroll"><div class="tk-track" id="tk-track">' + items + '</div></div></div>';

    document.documentElement.style.setProperty('--signage-ticker-inset', '<?= SIGNAGE_TICKER_H ?>px');

    var track = document.getElementById('tk-track');
    if (mode === 'static') startStatic(track);
    else startScroll(track);
  }

  function refresh() {
    fetch(API, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) { if (data) apply(data); })
      .catch(function () {});
  }

  refresh();
  setInterval(refresh, POLL);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') refresh();
  });
  document.addEventListener('signage-blank', function (ev) {
    if (ev.detail && ev.detail.on) refresh();
    else refresh();
  });
  if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(function () {
      refresh();
    }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
  }
})();
</script>
