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

// Which screen is this device? board.php?screen=garage etc.; default 'main'.
$SCREEN = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['screen'] ?? ''));
if ($SCREEN === '') $SCREEN = 'main';

$BUILTIN_PAGES = [
    ['url' => 'index.php',           'dwell' => 180],
    ['url' => 'lake.php',            'dwell' => 60, 'from' => 7,  'to' => 22],
    ['url' => 'photo.php',           'dwell' => 60, 'from' => 14, 'to' => 23],
    ['url' => 'family.php',          'dwell' => 90, 'from' => 6,  'to' => 21],
    ['url' => 'homelab.php',         'dwell' => 45],
    // ['url' => 'signaltrace.php',  'dwell' => 45],
    // ['url' => 'rss.php?feed=ars', 'dwell' => 96],
    // ['url' => 'rotator.php',      'dwell' => 300, 'from' => 22, 'to' => 6],
];

// Fallback chain: this screen's pages → main's pages → legacy single-rotation key → built-in default.
define('PAGES', cfg("rotation.PAGES_$SCREEN",
              cfg('rotation.PAGES_main',
              cfg('rotation.PAGES', $BUILTIN_PAGES))));

// Per-screen shuffle flag (Screens table in admin → Rotation).
$screensCfg = cfg('rotation.SCREENS', []);
$scr = is_array($screensCfg) ? ($screensCfg[$SCREEN] ?? null) : null;
define('SHUFFLE', is_array($scr) && !empty($scr['shuffle']));
define('FADE_MS',    cfg('rotation.FADE_MS', 800));        // crossfade duration
define('SETTLE_MS',  cfg('rotation.SETTLE_MS', 1200));     // wait after load before reveal
define('HANG_MS',    cfg('rotation.HANG_MS', 20000));      // skip a page that never loads
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Signage</title>
<style>
  * { margin:0; padding:0; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:#0c1422; cursor:none; }
  iframe { position:absolute; inset:0; width:1920px; height:1080px; border:0;
           opacity:0; transition:opacity <?= (int)FADE_MS ?>ms ease; }
  iframe.show { opacity:1; }
</style>
</head>
<body>
<iframe id="fA"></iframe>
<iframe id="fB"></iframe>
<script>
  const PAGES   = <?= json_encode(array_values(array_filter((array)PAGES,
                      fn($p) => !empty($p['url']) && (int)($p['dwell'] ?? 0) > 0 && empty($p['off'])))) ?>;
  const SETTLE  = <?= (int)SETTLE_MS ?>;
  const HANG    = <?= (int)HANG_MS ?>;
  const SHUFFLE = <?= SHUFFLE ? 'true' : 'false' ?>;
  const frames  = [document.getElementById('fA'), document.getElementById('fB')];
  let front = 0, idx = -1, gen = 0;

  function inWindow(p) {
    if (p.from == null || p.to == null || p.from === '' || p.to === '') return true;
    const h = new Date().getHours(), a = +p.from, b = +p.to;
    return a <= b ? (h >= a && h < b) : (h >= a || h < b);   // overnight supported
  }

  // Play order: identity when sequential; Fisher-Yates once per full pass when
  // shuffled — every page still shows once per cycle, and the first page of a
  // new pass is nudged away from the page just shown so nothing repeats
  // back-to-back across the reshuffle boundary.
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
  if (SHUFFLE) reshuffle(-1);

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
    f.src = p.url + sep + 'noticker=1&r=' + Date.now();   // cache-bust each cycle
    setTimeout(reveal, HANG);                              // safety net for hung pages
  }

  rotate();

  // Nightly reload of the shell itself to keep long kiosk sessions fresh
  setTimeout(() => location.reload(), 24 * 60 * 60 * 1000);
</script>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
