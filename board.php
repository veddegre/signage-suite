<?php
/**
 * BOARD ROTATION SHELL — point the kiosk browser at this one page.
 * Cycles boards with crossfades, per-page dwell times, and optional hour
 * windows, all editable in admin.php under "Rotation".
 *
 * Each page entry: url (relative, e.g. "rss.php?feed=krebs"), dwell (seconds),
 * and optional time window(s) (0–23 or HH:MM). Single window uses from/to;
 * multiple use windows: [{from,to},…]. Optional weekdays per row. Pages outside
 * windows (from 22, to 6) work. If every page is out of window the first one
 * shows as a fallback.
 *
 * The weather-alert ticker lives HERE, in the shell — genuinely persistent
 * across page transitions. Framed boards get ?noticker=1 so they don't render
 * a second copy (they still show their own ticker when opened directly).
 *
 * Two stacked iframes preload each board fully before it's revealed, so there
 * are no white flashes; a safety timeout moves on if a page ever hangs.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/rotation_lib.php';
require_once __DIR__ . '/lib/presence_lib.php';
require_once __DIR__ . '/lib/hero_strip_lib.php';
require_once __DIR__ . '/lib/emergency_lib.php';
require_once __DIR__ . '/lib/screen_scope_lib.php';

// Which screen is this device? board.php?screen=garage etc.; default 'main'.
$SCREEN = rotation_normalize_screen_key((string)($_GET['screen'] ?? 'main'));

require_once __DIR__ . '/lib/signage_theme_lib.php';
$signageThemeKey = signage_theme_for_screen($SCREEN);
$signageThemeCss = signage_theme_css_block($signageThemeKey);

$runtime = rotation_screen_runtime($SCREEN);
$heroStrip = hero_strip_render(is_array($runtime['hero_strip'] ?? null) ? $runtime['hero_strip'] : [], $SCREEN);
$heroStripHeight = !empty($heroStrip['enabled']) ? (int)($heroStrip['height'] ?? 120) : 0;
$blankActive = (bool)$runtime['blank'];
$emergencyTicker = emergency_ticker_forces_display();
$showTicker = rotation_screen_ticker_enabled($SCREEN);
$showDebug = !empty($runtime['show_debug']) || (isset($_GET['debug']) && (string)$_GET['debug'] === '1');

if (($_GET['api'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($runtime, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['api'] ?? '') === 'cec') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(rotation_cec_api_payload($SCREEN), JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['api'] ?? '') === 'hero') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $strip = hero_strip_render(is_array($runtime['hero_strip'] ?? null) ? $runtime['hero_strip'] : [], $SCREEN);
    echo json_encode([
        'enabled' => !empty($strip['enabled']),
        'html' => (string)($strip['html'] ?? ''),
        'height' => (int)($strip['height'] ?? 0),
        'class' => (string)($strip['class'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_GET['api'] ?? '') === 'presence') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = (string)file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        signage_presence_touch($SCREEN, $payload);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Signage</title>
<style>
  <?= $signageThemeCss ?>
  * { margin:0; padding:0; }
  <?= signage_kiosk_cursor_css() ?>
  html,body { width:1920px; height:1080px; overflow:hidden; background:var(--lake-night); }
  iframe { position:absolute; top:0; left:0; width:1920px;
           height:calc(1080px - var(--signage-ticker-inset, 0px) - var(--signage-hero-inset, 0px)); border:0;
           opacity:0; transition:opacity <?= (int)$runtime['fade_ms'] ?>ms ease;
           pointer-events:none; }
  iframe.show { opacity:1; }
  #hero-strip { position:absolute; left:0; right:0; bottom:var(--signage-ticker-inset, 0px); height:var(--signage-hero-inset, 0px);
               display:flex; align-items:center; gap:18px; padding:0 28px; overflow:hidden;
               background:color-mix(in srgb, var(--harbor) 94%, transparent); border-top:1px solid var(--hairline); z-index:9000;
               font:600 22px/1.3 'IBM Plex Sans',system-ui,sans-serif; color:var(--snow); }
  #hero-strip:empty { display:none; }
  #hero-strip .hero-strip-item { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:640px; }
  #hero-strip .hero-strip-item.label { color:var(--beacon); text-transform:uppercase; letter-spacing:.08em; font-size:18px; max-width:220px; }
  #hero-strip .hero-strip-item.ok { color:var(--ok); }
  #hero-strip .hero-strip-item.bad { color:var(--bad); }
  #hero-strip .hero-strip-item.warn { color:var(--warn); }
  #hero-strip .hero-strip-item.muted { color:var(--mist); }
  #empty { position:absolute; inset:0; display:none; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:var(--mist); font-family:system-ui,sans-serif; }
  #empty h1 { font-size:48px; color:var(--beacon); }
  #empty p { font-size:24px; }
  #blank { position:absolute; inset:0; z-index:10000; background:#000; display:none; }
  body.signage-blank:not(.signage-emergency-ticker) #signage-ticker-root,
  body.signage-blank:not(.signage-emergency-ticker) #signage-ticker { display:none !important; }
  #rotate-debug { position:absolute; top:24px; left:24px; z-index:9500; pointer-events:none;
                  max-width:880px; padding:14px 18px; border-radius:10px;
                  background:rgba(0,0,0,.78); color:var(--snow); font:600 22px/1.35 system-ui,sans-serif;
                  box-shadow:0 4px 28px rgba(0,0,0,.55); display:none; }
  #rotate-debug .rd-pos { font-size:18px; letter-spacing:.04em; text-transform:uppercase; color:var(--beacon); margin-bottom:6px; }
  #rotate-debug .rd-label { font-size:28px; margin-bottom:4px; }
  #rotate-debug .rd-url { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                          font-size:20px; font-weight:500; color:var(--mist); word-break:break-all; }
  #rotate-debug .rd-src { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                          font-size:16px; font-weight:400; color:var(--mist); opacity:.85; word-break:break-all; margin-top:6px; }
  #rotate-debug .rd-status { margin-top:8px; font-size:18px; color:var(--beacon); }
  #rotate-debug.rd-wait .rd-status { color:var(--mist); }
</style>
</head>
<body<?= $blankActive ? ' class="signage-blank' . ($emergencyTicker ? ' signage-emergency-ticker' : '') . '"' : ($emergencyTicker ? ' class="signage-emergency-ticker"' : '') ?> style="--signage-hero-inset: <?= (int)$heroStripHeight ?>px">
<div id="empty">
  <h1>No pages in rotation</h1>
  <p>Add boards in admin.php → Rotation, or check hour windows and Skip flags.</p>
</div>
<div id="blank"<?= $blankActive ? ' style="display:block"' : '' ?>></div>
<div id="rotate-debug" aria-live="polite"<?= $showDebug ? '' : ' style="display:none"' ?>></div>
<iframe id="fA" allow="autoplay; fullscreen"></iframe>
<iframe id="fB" allow="autoplay; fullscreen"></iframe>
<div id="hero-strip" aria-live="polite"><?= !empty($heroStrip['html']) ? $heroStrip['html'] : '' ?></div>
<?php signage_kiosk_pointer_shield_html(); ?>
<script>
  const PAGES   = <?= json_encode($runtime['pages'], JSON_UNESCAPED_SLASHES) ?>;
  const REVISION = <?= json_encode($runtime['revision']) ?>;
  const SETTLE  = <?= (int)$runtime['settle_ms'] ?>;
  const HANG    = <?= (int)$runtime['hang_ms'] ?>;
  const FADE    = <?= (int)$runtime['fade_ms'] ?>;
  const SHUFFLE = <?= json_encode((bool)$runtime['shuffle'] && !(bool)$runtime['weighted']) ?>;
  const WEIGHTED = <?= json_encode((bool)$runtime['weighted']) ?>;
  const ROTATION_TZ = <?= json_encode($runtime['timezone']) ?>;
  const SHOW_CLOCK = <?= json_encode((bool)$runtime['show_clock']) ?>;
  const SHOW_TICKER = <?= json_encode($showTicker) ?>;
  const TICKER_H = <?= (int)SIGNAGE_TICKER_H ?>;
  const BLANK_INIT = <?= json_encode($blankActive) ?>;
  const SHOW_DEBUG = <?= json_encode($showDebug) ?>;
  const KEYBOARD_NAV = <?= json_encode(!empty($runtime['keyboard_nav'])) ?>;
  const SCREEN  = <?= json_encode($runtime['screen']) ?>;
  const THEME   = <?= json_encode($signageThemeKey) ?>;
  const HERO_STRIP = <?= json_encode(!empty($heroStrip['enabled'])) ?>;
  const POLL_MS = 30000;
  const BLANK_POLL_MS = 30000;
  let showDebug = SHOW_DEBUG;
  let lastAdvanceAt = Date.now();
  let pollFails = 0;
  let watchdogTrips = 0;
  const frames  = [document.getElementById('fA'), document.getElementById('fB')];
  const KIOSK_CURSOR_CSS = <?= json_encode(signage_kiosk_cursor_css()) ?>;
  let front = 0, idx = -1, gen = 0, rotateTimer = null;
  let pageHistory = [];
  let blankActive = BLANK_INIT;
  let presencePage = null;
  let presenceStatus = blankActive ? 'blank' : '';

  function presenceQuery() {
    return SCREEN === 'main'
      ? 'board.php?api=presence'
      : ('board.php?api=presence&screen=' + encodeURIComponent(SCREEN));
  }

  function sendPresence(status) {
    if (status) presenceStatus = status;
    const p = presencePage;
    const body = {
      revision: REVISION,
      blank: blankActive,
      page_url: blankActive ? '' : (p ? (p.url || '') : ''),
      page_label: blankActive ? '' : (p ? (p.label || p.url || '') : ''),
      page_index: blankActive ? -1 : idx,
      page_total: PAGES.length,
      status: presenceStatus,
    };
    fetch(presenceQuery(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store',
    }).catch(function () {});
  }

  function clearRotateTimer() {
    if (rotateTimer) {
      clearTimeout(rotateTimer);
      rotateTimer = null;
    }
  }

  function scheduleRotate(ms) {
    clearRotateTimer();
    const wait = Math.max(5000, ms | 0);
    rotateTimer = setTimeout(function () {
      rotateTimer = null;
      rotate();
    }, wait);
  }

  function isRssUrl(url) {
    return /(?:^|[?&/])rss\.php(?:[?&#]|$)/.test(String(url));
  }

  function debugEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function updateRotateDebug(status, page, pageIdx, fullSrc) {
    if (!showDebug) return;
    const el = document.getElementById('rotate-debug');
    if (!el || !page) return;
    const inWin = pagesInWindow().length;
    const pos = (pageIdx + 1) + ' / ' + PAGES.length;
    const label = page.label || page.url || '—';
    const wt = pageWeight(page);
    const mode = WEIGHTED ? 'weighted' : (SHUFFLE ? 'shuffle' : 'sequential');
    el.className = status === 'loading' ? 'rd-wait' : '';
    el.style.display = 'block';
    el.innerHTML =
      '<div class="rd-pos">' + debugEsc(pos)
      + ' · ' + inWin + ' in window'
      + ' · weight ' + wt
      + (WEIGHTED ? ' · weighted' : (' · ' + mode)) + '</div>'
      + '<div class="rd-label">' + debugEsc(label) + '</div>'
      + '<div class="rd-url">' + debugEsc(page.url || '') + '</div>'
      + (fullSrc ? '<div class="rd-src">' + debugEsc(fullSrc) + '</div>' : '')
      + '<div class="rd-status">' + debugEsc(status) + '</div>';
  }

  function postToFrame(frame, msg) {
    try {
      if (frame && frame.contentWindow) frame.contentWindow.postMessage(msg, '*');
    } catch (e) {}
  }

  function hideKioskCursor(frame) {
    if (!frame) return;
    try {
      const doc = frame.contentDocument;
      if (!doc || !doc.head || doc.getElementById('signage-kiosk-cursor')) return;
      const style = doc.createElement('style');
      style.id = 'signage-kiosk-cursor';
      style.textContent = KIOSK_CURSOR_CSS;
      doc.head.appendChild(style);
    } catch (e) {}
  }

  window.addEventListener('message', function (ev) {
    if (!ev.data || !ev.data.type) return;
    if (ev.data.type === 'signage-ready') {
      for (var i = 0; i < frames.length; i++) {
        try {
          if (frames[i].classList.contains('show') && frames[i].contentWindow === ev.source) {
            postToFrame(frames[i], { type: 'signage-show' });
            break;
          }
        } catch (e) {}
      }
      return;
    }
    if (ev.data.type === 'signage-gesture') {
      for (var j = 0; j < frames.length; j++) {
        if (frames[j].classList.contains('show')) {
          postToFrame(frames[j], { type: 'signage-gesture' });
        }
      }
      return;
    }
    if (ev.data.type === 'signage-nav' && KEYBOARD_NAV) {
      if (blankActive || PAGES.length === 0) return;
      if (ev.data.dir === 'prev') rotateBack();
      else if (ev.data.dir === 'next') rotateForward();
      return;
    }
    if (ev.data.type !== 'signage-done') return;
    var fromVisible = false;
    for (var i = 0; i < frames.length; i++) {
      try {
        if (frames[i].classList.contains('show') && frames[i].contentWindow === ev.source) {
          fromVisible = true;
          break;
        }
      } catch (e) {}
    }
    if (!fromVisible) return;
    clearRotateTimer();
    rotateForward();
  });

  function parseWindowTime(v) {
    if (v == null || v === '') return null;
    const s = String(v).trim();
    if (/^\d+$/.test(s)) return parseInt(s, 10) * 60;
    const m = s.match(/^(\d{1,2}):(\d{2})$/);
    if (m) return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    return null;
  }

  function rotationNowParts() {
    try {
      const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: ROTATION_TZ,
        hour: 'numeric',
        minute: 'numeric',
        weekday: 'long',
        hourCycle: 'h23',
      }).formatToParts(new Date());
      const map = {};
      parts.forEach(function (p) { map[p.type] = p.value; });
      return map;
    } catch (e) {}
    const d = new Date();
    return {
      hour: String(d.getHours()),
      minute: String(d.getMinutes()),
      weekday: d.toLocaleDateString('en-US', { weekday: 'long' }),
    };
  }

  function rotationMinutesNow() {
    const p = rotationNowParts();
    const h = parseInt(p.hour, 10);
    const m = parseInt(p.minute, 10);
    if (isNaN(h) || isNaN(m)) {
      const d = new Date();
      return d.getHours() * 60 + d.getMinutes();
    }
    return (h % 24) * 60 + m;
  }

  function pageWeekdaysActive(p) {
    if (!Array.isArray(p.weekdays) || !p.weekdays.length) return true;
    const today = rotationNowParts().weekday || '';
    return p.weekdays.indexOf(today) >= 0;
  }

  function pageWindows(p) {
    let raw = [];
    if (Array.isArray(p.windows) && p.windows.length) raw = p.windows;
    else if (p.from != null && p.to != null && p.from !== '' && p.to !== '') raw = [{ from: p.from, to: p.to }];
    return raw.map(function (w) {
      return { from: parseWindowTime(w.from), to: parseWindowTime(w.to) };
    }).filter(function (w) {
      return w.from != null && w.to != null;
    });
  }

  function minutesInRange(nowMin, fromMin, toMin) {
    return fromMin <= toMin ? (nowMin >= fromMin && nowMin < toMin) : (nowMin >= fromMin || nowMin < toMin);
  }

  function inWindow(p) {
    if (!pageWeekdaysActive(p)) return false;
    const windows = pageWindows(p);
    if (!windows.length) return true;
    const nowMin = rotationMinutesNow();
    for (let i = 0; i < windows.length; i++) {
      const w = windows[i];
      if (minutesInRange(nowMin, w.from, w.to)) return true;
    }
    return false;
  }

  function pagesInWindow() {
    const out = [];
    for (let i = 0; i < PAGES.length; i++) {
      if (inWindow(PAGES[i])) out.push(i);
    }
    return out;
  }

  function pageWeight(p) {
    const w = parseInt(p.weight, 10);
    return (!isNaN(w) && w > 0) ? Math.min(20, w) : 1;
  }

  // Play order: weighted deck, shuffled deck, or sequential list.
  let order = PAGES.map((_, i) => i), pos = -1;
  let shuffleDeck = [];
  let shufflePos = 0;
  let shuffleEligibleKey = '';
  let weightedDeck = [];
  let weightedPos = 0;
  let weightedEligibleKey = '';

  function eligiblePageIndices() {
    const eligible = pagesInWindow();
    return eligible.length ? eligible : order.slice();
  }

  function eligibleFingerprint(indices) {
    return indices.map(function (i) {
      return i + ':' + pageWeight(PAGES[i]);
    }).join(',');
  }

  function shuffleEligibleIndices() {
    return eligiblePageIndices();
  }

  function shuffleEligibleFingerprint() {
    return eligibleFingerprint(shuffleEligibleIndices());
  }

  function rebuildShuffleDeck(lastShown) {
    shuffleDeck = shuffleEligibleIndices();
    if (shuffleDeck.length === 0) {
      shufflePos = 0;
      return;
    }
    for (let i = shuffleDeck.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [shuffleDeck[i], shuffleDeck[j]] = [shuffleDeck[j], shuffleDeck[i]];
    }
    if (shuffleDeck.length > 1 && shuffleDeck[0] === lastShown) {
      const last = shuffleDeck.length - 1;
      [shuffleDeck[0], shuffleDeck[last]] = [shuffleDeck[last], shuffleDeck[0]];
    }
    shufflePos = 0;
    shuffleEligibleKey = shuffleEligibleFingerprint();
  }

  function nextShufflePage() {
    const fp = shuffleEligibleFingerprint();
    if (fp !== shuffleEligibleKey || shufflePos >= shuffleDeck.length) {
      rebuildShuffleDeck(idx);
    }
    if (!shuffleDeck.length) {
      return 0;
    }
    return shuffleDeck[shufflePos++];
  }

  function rebuildWeightedDeck(lastShown) {
    const eligible = eligiblePageIndices();
    weightedDeck = [];
    for (let k = 0; k < eligible.length; k++) {
      const i = eligible[k];
      const w = pageWeight(PAGES[i]);
      for (let c = 0; c < w; c++) {
        weightedDeck.push(i);
      }
    }
    if (weightedDeck.length === 0) {
      weightedPos = 0;
      weightedEligibleKey = '';
      return;
    }
    for (let i = weightedDeck.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [weightedDeck[i], weightedDeck[j]] = [weightedDeck[j], weightedDeck[i]];
    }
    if (weightedDeck.length > 1 && weightedDeck[0] === lastShown) {
      for (let s = weightedDeck.length - 1; s > 0; s--) {
        if (weightedDeck[s] !== lastShown) {
          [weightedDeck[0], weightedDeck[s]] = [weightedDeck[s], weightedDeck[0]];
          break;
        }
      }
    }
    weightedPos = 0;
    weightedEligibleKey = eligibleFingerprint(eligible);
  }

  function nextWeightedPage() {
    const fp = eligibleFingerprint(eligiblePageIndices());
    if (fp !== weightedEligibleKey || weightedPos >= weightedDeck.length) {
      rebuildWeightedDeck(idx);
    }
    if (!weightedDeck.length) {
      return 0;
    }
    return weightedDeck[weightedPos++];
  }

  if (WEIGHTED) {
    rebuildWeightedDeck(-1);
    pos = -1;
  } else if (SHUFFLE) {
    rebuildShuffleDeck(-1);
    pos = -1;
  } else if (order.length > 1) {
    // Sequential mode: random starting slot so kiosks don't all boot on playlist item 1.
    pos = Math.floor(Math.random() * order.length) - 1;
  }

  function nextPage() {
    if (WEIGHTED) {
      return nextWeightedPage();
    }
    if (SHUFFLE) {
      return nextShufflePage();
    }
    for (let n = 0; n < order.length; n++) {
      pos++;
      if (pos >= order.length) {
        pos = 0;
      }
      const cand = order[pos];
      if (inWindow(PAGES[cand])) return cand;
    }
    const fallback = shuffleEligibleIndices();
    return fallback.length ? fallback[0] : (order[0] ?? 0);
  }

  if (PAGES.length === 0 && !blankActive) {
    document.getElementById('empty').style.display = 'flex';
    sendPresence('empty');
  }

  function setBlank(on) {
    if (on === blankActive) return;
    blankActive = on;
    const blankEl = document.getElementById('blank');
    if (on) {
      clearRotateTimer();
      gen++;
      presencePage = null;
      idx = -1;
      postToFrame(frames[0], { type: 'signage-stop' });
      postToFrame(frames[1], { type: 'signage-stop' });
      frames.forEach(function (f) {
        stopFrame(f);
        f.classList.remove('show');
        f.removeAttribute('src');
      });
      document.getElementById('empty').style.display = 'none';
      if (blankEl) blankEl.style.display = 'block';
      document.body.classList.add('signage-blank');
      document.dispatchEvent(new CustomEvent('signage-blank', { detail: { on: true } }));
      notifyParentBlank(true);
      sendPresence('blank');
    } else {
      if (blankEl) blankEl.style.display = 'none';
      document.body.classList.remove('signage-blank');
      document.dispatchEvent(new CustomEvent('signage-blank', { detail: { on: false } }));
      notifyParentBlank(false);
      if (PAGES.length === 0) {
        document.getElementById('empty').style.display = 'flex';
        sendPresence('empty');
      } else {
        if (SHUFFLE) rebuildShuffleDeck(-1);
        else if (WEIGHTED) rebuildWeightedDeck(-1);
        rotate();
      }
    }
  }

  function notifyParentBlank(on) {
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'signage-blank', on: on }, '*');
      }
    } catch (e) {}
  }

  function stopFrame(frame) {
    if (!frame) return;
    try {
      const doc = frame.contentDocument;
      if (doc) {
        doc.querySelectorAll('video, audio').forEach(function (el) {
          el.pause();
          try { el.currentTime = 0; } catch (e) {}
          el.muted = true;
          el.removeAttribute('src');
          if (typeof el.load === 'function') el.load();
        });
      }
    } catch (e) {}
  }

  /** Tear down a hidden iframe so Chromium releases GPU/JS from the previous board. */
  function unloadFrame(frame) {
    if (!frame) return;
    stopFrame(frame);
    frame.onload = null;
    frame.classList.remove('show');
    try {
      frame.removeAttribute('src');
    } catch (e) {}
  }

  function boardNeedsScope(url) {
    return /(?:^|[?&/])(calendar|family|glance|rss|video|grafana|splunkdash|powerbi|splunk|zabbix|web|slides|rotator|index|air|uv|photo|traffic|sports)\.php(?:[?&#]|$)/i.test(String(url));
  }

  function rotateToIndex(targetIdx) {
    if (blankActive || PAGES.length === 0) return;
    clearRotateTimer();
    postToFrame(frames[0], { type: 'signage-stop' });
    postToFrame(frames[1], { type: 'signage-stop' });
    idx = targetIdx;
    const p = PAGES[idx];
    if (!p) return;
    presencePage = p;
    const myGen = ++gen;
    const back = 1 - front;
    const f = frames[back];
    let revealed = false;
    const sep = p.url.includes('?') ? '&' : '?';
    let qs = 'noticker=1&settle=' + SETTLE;
    if (SHOW_TICKER) qs += '&safebottom=' + TICKER_H;
    if (SCREEN && SCREEN !== 'main' && boardNeedsScope(p.url)) qs += '&screen=' + encodeURIComponent(SCREEN);
    if (THEME && THEME !== 'lake_night') qs += '&theme=' + encodeURIComponent(THEME);
    if (!SHOW_CLOCK) qs += '&clock=0';
    const fullSrc = p.url + sep + qs + '&r=' + Date.now();
    updateRotateDebug('loading…', p, idx, fullSrc);
    sendPresence('loading');

    const reveal = () => {
      if (revealed || myGen !== gen) return;
      revealed = true;
      const hidden = frames[front];
      stopFrame(hidden);
      f.classList.add('show');
      frames[front].classList.remove('show');
      front = back;
      postToFrame(f, { type: 'signage-show' });
      updateRotateDebug('on screen', p, idx, fullSrc);
      sendPresence('on screen');
      lastAdvanceAt = Date.now();
      watchdogTrips = 0;
      const dwellMs = (+p.dwell) * 1000;
      const safetyMs = isRssUrl(p.url) ? Math.max(dwellMs + 15000, 60000) : dwellMs;
      scheduleRotate(safetyMs);
      const unloadGen = myGen;
      setTimeout(function () {
        if (unloadGen !== gen) return;
        unloadFrame(hidden);
      }, FADE + 80);
    };

    unloadFrame(f);
    f.onload = () => setTimeout(function () {
      hideKioskCursor(f);
      if (myGen !== gen) return;
      if (revealed) {
        postToFrame(f, { type: 'signage-show' });
        return;
      }
      reveal();
    }, SETTLE);
    f.src = fullSrc;
    setTimeout(function () {
      if (myGen !== gen || revealed) return;
      reveal();
      updateRotateDebug('on screen (hang timeout)', p, idx, fullSrc);
    }, HANG);
  }

  function rotateForward() {
    if (blankActive || PAGES.length === 0) return;
    if (idx >= 0) {
      pageHistory.push(idx);
      if (pageHistory.length > 100) pageHistory.shift();
    }
    rotateToIndex(nextPage());
  }

  function rotateBack() {
    if (blankActive || PAGES.length === 0 || pageHistory.length === 0) return;
    rotateToIndex(pageHistory.pop());
  }

  function rotate() {
    rotateForward();
  }

  if (KEYBOARD_NAV) {
    document.addEventListener('keydown', function (e) {
      if (blankActive || PAGES.length === 0) return;
      if (e.key === 'ArrowRight' || e.key === 'PageDown') {
        e.preventDefault();
        rotateForward();
      } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
        e.preventDefault();
        rotateBack();
      }
    });
  }

  if (!blankActive) {
    rotate();
    notifyParentBlank(false);
  } else {
    document.body.classList.add('signage-blank');
    document.dispatchEvent(new CustomEvent('signage-blank', { detail: { on: true } }));
    notifyParentBlank(true);
    sendPresence('blank');
  }

  function pollRotationConfig() {
    const q = SCREEN === 'main' ? 'board.php?api=1' : ('board.php?api=1&screen=' + encodeURIComponent(SCREEN));
    fetch(q, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) {
          pollFails++;
          if (pollFails >= 3) location.reload();
          return;
        }
        pollFails = 0;
        if (data.revision && data.revision !== REVISION) {
          location.reload();
          return;
        }
        if (!!data.show_debug !== !!showDebug) {
          location.reload();
          return;
        }
        if (typeof data.blank === 'boolean') setBlank(data.blank);
        sendPresence();
      })
      .catch(function () {
        pollFails++;
        if (pollFails >= 3) location.reload();
      });
  }
  setInterval(pollRotationConfig, blankActive ? BLANK_POLL_MS : POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') pollRotationConfig();
  });

  function pollHeroStrip() {
    if (!HERO_STRIP) return;
    const q = SCREEN === 'main' ? 'board.php?api=hero' : ('board.php?api=hero&screen=' + encodeURIComponent(SCREEN));
    fetch(q, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.enabled) return;
        const el = document.getElementById('hero-strip');
        if (!el) return;
        if ((data.html || '') !== el.innerHTML) el.innerHTML = data.html || '';
        const h = Math.max(0, (+data.height || 0)) + 'px';
        if (document.body.style.getPropertyValue('--signage-hero-inset') !== h) {
          document.body.style.setProperty('--signage-hero-inset', h);
        }
      })
      .catch(function () {});
  }
  if (HERO_STRIP) {
    setInterval(pollHeroStrip, POLL_MS);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') pollHeroStrip();
    });
  }

  // If rotation stalls (lost timer, hung iframe, throttled background tab), recover.
  setInterval(function () {
    if (blankActive || PAGES.length === 0) return;
    const p = PAGES[idx];
    const dwellMs = p ? Math.max(1000, (+p.dwell || 60) * 1000) : 60000;
    const staleMs = Math.max(dwellMs + 90000, dwellMs * 2, 120000);
    if (Date.now() - lastAdvanceAt < staleMs) return;
    watchdogTrips++;
    if (watchdogTrips >= 2) {
      location.reload();
      return;
    }
    clearRotateTimer();
    frames.forEach(unloadFrame);
    rotateForward();
  }, 30000);

  // Periodic shell reload flushes Chromium memory and stuck renderer state.
  setTimeout(function () { location.reload(); }, 8 * 60 * 60 * 1000);
</script>
<?php signage_kiosk_hide_pointer_script(); ?>
<?php if ($showTicker):
    $signageTickerScreen = $SCREEN;
    signage_ticker_bootstrap($SCREEN);
    include __DIR__ . '/ticker.php';
endif; ?>
</body>
</html>
