<?php
/**
 * BOARD ROTATION SHELL — point the kiosk browser at this one page.
 * This replaces Anthias's scheduling: it cycles the boards with crossfades,
 * per-page dwell times, and optional hour windows, all editable in admin.php
 * under "Rotation".
 *
 * Each page entry: url (relative, e.g. "rss.php?feed=krebs"), dwell (seconds),
 * and optional from/to hours (0–23). Pages outside their window are skipped;
 * overnight windows (from 22, to 6) work. If every page is out of window the
 * first one shows as a fallback.
 *
 * The weather-alert ticker lives HERE, in the shell — genuinely persistent
 * across page transitions. Framed boards get ?noticker=1 so they don't render
 * a second copy (they still show their own ticker when opened directly).
 *
 * Two stacked iframes preload each board fully before it's revealed, so there
 * are no white flashes; a safety timeout moves on if a page ever hangs.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/presence_lib.php';

// Which screen is this device? board.php?screen=garage etc.; default 'main'.
$SCREEN = rotation_normalize_screen_key((string)($_GET['screen'] ?? 'main'));

$runtime = rotation_screen_runtime($SCREEN);
$blankActive = (bool)$runtime['blank'];
$showTicker = rotation_screen_ticker_enabled($SCREEN) && !$blankActive;
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
  * { margin:0; padding:0; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:#0c1422; cursor:none; }
  iframe { position:absolute; top:0; left:0; width:1920px;
           height:calc(1080px - var(--signage-ticker-inset, 0px)); border:0;
           opacity:0; transition:opacity <?= (int)$runtime['fade_ms'] ?>ms ease; }
  iframe.show { opacity:1; }
  #empty { position:absolute; inset:0; display:none; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:#8aa0c0; font-family:system-ui,sans-serif; }
  #empty h1 { font-size:48px; color:#ffb347; }
  #empty p { font-size:24px; }
  #blank { position:absolute; inset:0; z-index:10000; background:#000; display:none; }
  body.signage-blank #signage-ticker-root,
  body.signage-blank #signage-ticker { display:none !important; }
  #rotate-debug { position:absolute; top:24px; left:24px; z-index:9500; pointer-events:none;
                  max-width:880px; padding:14px 18px; border-radius:10px;
                  background:rgba(0,0,0,.78); color:#edf2fb; font:600 22px/1.35 system-ui,sans-serif;
                  box-shadow:0 4px 28px rgba(0,0,0,.55); display:none; }
  #rotate-debug .rd-pos { font-size:18px; letter-spacing:.04em; text-transform:uppercase; color:#ffb347; margin-bottom:6px; }
  #rotate-debug .rd-label { font-size:28px; margin-bottom:4px; }
  #rotate-debug .rd-url { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                          font-size:20px; font-weight:500; color:#8aa0c0; word-break:break-all; }
  #rotate-debug .rd-src { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                          font-size:16px; font-weight:400; color:#6a809c; word-break:break-all; margin-top:6px; }
  #rotate-debug .rd-status { margin-top:8px; font-size:18px; color:#ffb347; }
  #rotate-debug.rd-wait .rd-status { color:#8aa0c0; }
</style>
</head>
<body>
<div id="empty">
  <h1>No pages in rotation</h1>
  <p>Add boards in admin.php → Rotation, or check hour windows and Skip flags.</p>
</div>
<div id="blank"<?= $blankActive ? ' style="display:block"' : '' ?>></div>
<?php if ($showDebug): ?><div id="rotate-debug" aria-live="polite"></div><?php endif; ?>
<iframe id="fA" allow="autoplay; fullscreen"></iframe>
<iframe id="fB" allow="autoplay; fullscreen"></iframe>
<script>
  const PAGES   = <?= json_encode($runtime['pages'], JSON_UNESCAPED_SLASHES) ?>;
  const REVISION = <?= json_encode($runtime['revision']) ?>;
  const SETTLE  = <?= (int)$runtime['settle_ms'] ?>;
  const HANG    = <?= (int)$runtime['hang_ms'] ?>;
  const SHUFFLE = <?= json_encode((bool)$runtime['shuffle']) ?>;
  const WEIGHTED = <?= json_encode((bool)$runtime['weighted']) ?>;
  const SHOW_CLOCK = <?= json_encode((bool)$runtime['show_clock']) ?>;
  const BLANK_INIT = <?= json_encode($blankActive) ?>;
  const SHOW_DEBUG = <?= json_encode($showDebug) ?>;
  const SCREEN  = <?= json_encode($runtime['screen']) ?>;
  const POLL_MS = 30000;
  const BLANK_POLL_MS = 30000;
  const frames  = [document.getElementById('fA'), document.getElementById('fB')];
  let front = 0, idx = -1, gen = 0, rotateTimer = null;
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
    rotateTimer = setTimeout(function () {
      rotateTimer = null;
      rotate();
    }, ms);
  }

  function isRssUrl(url) {
    return /(?:^|[?&/])rss\.php(?:[?&#]|$)/.test(String(url));
  }

  function debugEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function updateRotateDebug(status, page, pageIdx, fullSrc) {
    if (!SHOW_DEBUG) return;
    const el = document.getElementById('rotate-debug');
    if (!el || !page) return;
    const pos = (pageIdx + 1) + ' / ' + PAGES.length;
    const label = page.label || page.url || '—';
    const wt = WEIGHTED ? pageWeight(page) : 0;
    el.className = status === 'loading' ? 'rd-wait' : '';
    el.style.display = 'block';
    el.innerHTML =
      '<div class="rd-pos">' + debugEsc(pos) + (wt > 1 ? ' · weight ' + wt : '') + '</div>'
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
    rotate();
  });

  function inWindow(p) {
    if (p.from == null || p.to == null || p.from === '' || p.to === '') return true;
    const h = new Date().getHours(), a = +p.from, b = +p.to;
    return a <= b ? (h >= a && h < b) : (h >= a || h < b);   // overnight supported
  }

  function pageWeight(p) {
    const w = parseInt(p.weight, 10);
    return (!isNaN(w) && w > 0) ? Math.min(20, w) : 1;
  }

  function pickWeightedPage(excludeIdx) {
    let total = 0;
    const pool = [];
    for (let i = 0; i < PAGES.length; i++) {
      if (!inWindow(PAGES[i])) continue;
      if (i === excludeIdx && PAGES.length > 1) continue;
      const w = pageWeight(PAGES[i]);
      pool.push({ i: i, w: w });
      total += w;
    }
    if (pool.length === 0) {
      for (let j = 0; j < PAGES.length; j++) {
        if (inWindow(PAGES[j])) return j;
      }
      return excludeIdx >= 0 ? excludeIdx : 0;
    }
    let r = Math.random() * total;
    for (let k = 0; k < pool.length; k++) {
      r -= pool[k].w;
      if (r <= 0) return pool[k].i;
    }
    return pool[pool.length - 1].i;
  }

  // Play order: weighted random, shuffled deck, or sequential list.
  let order = PAGES.map((_, i) => i), pos = -1;
  function reshuffle(lastShown) {
    for (let i = order.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [order[i], order[j]] = [order[j], order[i]];
    }
    if (order.length > 1 && order[0] === lastShown) {
      [order[0], order[order.length - 1]] = [order[order.length - 1], order[0]];
    }
  }
  if (WEIGHTED) {
    pos = -1;
  } else if (SHUFFLE) {
    reshuffle(-1);
    pos = -1;
  } else if (order.length > 1) {
    // Sequential mode: random starting slot so kiosks don't all boot on playlist item 1.
    pos = Math.floor(Math.random() * order.length) - 1;
  }

  function nextPage() {
    if (WEIGHTED) {
      return pickWeightedPage(idx);
    }
    for (let n = 0; n < order.length; n++) {
      pos++;
      if (pos >= order.length) {
        if (SHUFFLE) reshuffle(idx);
        pos = 0;
      }
      const cand = order[pos];
      if (inWindow(PAGES[cand])) return cand;
    }
    return order[0] ?? 0;
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
      sendPresence('blank');
    } else {
      location.reload();
    }
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

  function rotate() {
    if (blankActive || PAGES.length === 0) return;
    clearRotateTimer();
    postToFrame(frames[0], { type: 'signage-stop' });
    postToFrame(frames[1], { type: 'signage-stop' });
    idx = nextPage();
    const p = PAGES[idx];
    presencePage = p;
    const myGen = ++gen;
    const back = 1 - front;
    const f = frames[back];
    let revealed = false;
    const sep = p.url.includes('?') ? '&' : '?';
    let qs = 'noticker=1&settle=' + SETTLE;
    if (!SHOW_CLOCK) qs += '&clock=0';
    const fullSrc = p.url + sep + qs + '&r=' + Date.now();
    updateRotateDebug('loading…', p, idx, fullSrc);
    sendPresence('loading');

    const reveal = () => {
      if (revealed || myGen !== gen) return;
      revealed = true;
      stopFrame(frames[front]);
      f.classList.add('show');
      frames[front].classList.remove('show');
      front = back;
      postToFrame(f, { type: 'signage-show' });
      updateRotateDebug('on screen', p, idx, fullSrc);
      sendPresence('on screen');
      const dwellMs = (+p.dwell) * 1000;
      // RSS boards report signage-done when their carousel finishes; dwell is only a safety cap.
      const safetyMs = isRssUrl(p.url) ? Math.max(dwellMs + 15000, 60000) : dwellMs;
      scheduleRotate(safetyMs);
    };

    stopFrame(f);
    f.onload = () => setTimeout(function () {
      if (myGen !== gen) return;
      if (revealed) {
        // Frame was revealed early (HANG) before this document finished loading.
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

  if (!blankActive) {
    rotate();
  } else {
    document.body.classList.add('signage-blank');
    sendPresence('blank');
  }

  function pollRotationConfig() {
    const q = SCREEN === 'main' ? 'board.php?api=1' : ('board.php?api=1&screen=' + encodeURIComponent(SCREEN));
    fetch(q, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        if (data.revision && data.revision !== REVISION) {
          location.reload();
          return;
        }
        if (typeof data.blank === 'boolean') setBlank(data.blank);
        sendPresence();
      })
      .catch(function () {});
  }
  setInterval(pollRotationConfig, blankActive ? BLANK_POLL_MS : POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') pollRotationConfig();
  });

  // Nightly reload of the shell itself to keep long kiosk sessions fresh
  setTimeout(() => location.reload(), 24 * 60 * 60 * 1000);
</script>
<?php if ($showTicker): include __DIR__ . '/ticker.php'; endif; ?>
</body>
</html>
