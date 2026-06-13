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

// Which screen is this device? board.php?screen=garage etc.; default 'main'.
$SCREEN = rotation_normalize_screen_key((string)($_GET['screen'] ?? 'main'));

$runtime = rotation_screen_runtime($SCREEN);

if (($_GET['api'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($runtime, JSON_UNESCAPED_SLASHES);
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
  iframe { position:absolute; top:0; left:0; width:1920px; height:<?= 1080 - SIGNAGE_TICKER_H ?>px; border:0;
           opacity:0; transition:opacity <?= (int)$runtime['fade_ms'] ?>ms ease; }
  iframe.show { opacity:1; }
  #empty { position:absolute; inset:0; display:none; align-items:center; justify-content:center;
           flex-direction:column; gap:16px; color:#8aa0c0; font-family:system-ui,sans-serif; }
  #empty h1 { font-size:48px; color:#ffb347; }
  #empty p { font-size:24px; }
</style>
</head>
<body>
<div id="empty">
  <h1>No pages in rotation</h1>
  <p>Add boards in admin.php → Rotation, or check hour windows and Skip flags.</p>
</div>
<iframe id="fA"></iframe>
<iframe id="fB"></iframe>
<script>
  const PAGES   = <?= json_encode($runtime['pages'], JSON_UNESCAPED_SLASHES) ?>;
  const REVISION = <?= json_encode($runtime['revision']) ?>;
  const SETTLE  = <?= (int)$runtime['settle_ms'] ?>;
  const HANG    = <?= (int)$runtime['hang_ms'] ?>;
  const SHUFFLE = <?= json_encode((bool)$runtime['shuffle']) ?>;
  const SCREEN  = <?= json_encode($runtime['screen']) ?>;
  const POLL_MS = 30000;
  const frames  = [document.getElementById('fA'), document.getElementById('fB')];
  let front = 0, idx = -1, gen = 0;

  function inWindow(p) {
    if (p.from == null || p.to == null || p.from === '' || p.to === '') return true;
    const h = new Date().getHours(), a = +p.from, b = +p.to;
    return a <= b ? (h >= a && h < b) : (h >= a || h < b);   // overnight supported
  }

  // Play order: sequential list order, or a shuffled order that reshuffles each full pass.
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
  if (SHUFFLE) {
    reshuffle(-1);
    pos = -1;
  } else if (order.length > 1) {
    // Sequential mode: random starting slot so kiosks don't all boot on playlist item 1.
    pos = Math.floor(Math.random() * order.length) - 1;
  }

  function nextPage() {
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

  if (PAGES.length === 0) {
    document.getElementById('empty').style.display = 'flex';
  }

  function rotate() {
    if (PAGES.length === 0) return;
    idx = nextPage();
    const p = PAGES[idx];
    const myGen = ++gen;
    const back = 1 - front;
    const f = frames[back];
    let revealed = false;

    const reveal = () => {
      if (revealed || myGen !== gen) return;
      revealed = true;
      f.classList.add('show');
      frames[front].classList.remove('show');
      front = back;
      setTimeout(rotate, (+p.dwell) * 1000);
    };

    f.onload = () => setTimeout(reveal, SETTLE);
    const sep = p.url.includes('?') ? '&' : '?';
    f.src = p.url + sep + 'noticker=1&safebottom=<?= SIGNAGE_TICKER_H ?>&r=' + Date.now();
    setTimeout(reveal, HANG);                              // safety net for hung pages
  }

  rotate();

  function pollRotationConfig() {
    const q = SCREEN === 'main' ? 'board.php?api=1' : ('board.php?api=1&screen=' + encodeURIComponent(SCREEN));
    fetch(q, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (data && data.revision && data.revision !== REVISION) location.reload();
      })
      .catch(function () {});
  }
  setInterval(pollRotationConfig, POLL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') pollRotationConfig();
  });

  // Nightly reload of the shell itself to keep long kiosk sessions fresh
  setTimeout(() => location.reload(), 24 * 60 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
