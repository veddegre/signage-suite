<?php
/**
 * VIDEO BOARD — 1920×1080 signage
 * Plays locally stored videos fullscreen — the same approach Anthias uses for
 * its native video assets, done by hand so it works as a web asset alongside
 * the other boards (and gets the alert ticker).
 *
 * One file is both the player and the downloader:
 *
 *   PLAYER   video.php?v=<key>      → plays that video (no ?v= = first entry)
 *   FETCHER  php video.php fetch    → run from the CLI; downloads/updates every
 *                                     YouTube entry in VIDEOS via yt-dlp and
 *                                     prints each video's duration so you can
 *                                     set the matching Anthias asset length
 *
 * Requirements on the server: yt-dlp in PATH for fetching (pipx install
 * yt-dlp), and optionally ffprobe (apt install ffmpeg) for duration readout.
 * Videos land in ./videos/ next to this file so the web server itself serves
 * the media with proper range support — easy on a Pi's CPU.
 *
 * Anthias setup: add  video.php?v=drone  as a web asset and set its duration
 * to the length printed by the fetcher. The video loops as a safety net, so a
 * slightly long asset duration just wraps to the start rather than going black.
 *
 * Keep videos muted for signage (MUTED=true). Chromium's autoplay policy
 * blocks un-muted autoplay unless the kiosk is launched with
 * --autoplay-policy=no-user-gesture-required.
 */

require_once __DIR__ . '/config.php';

define('VIDEOS', cfg('video.VIDEOS', [


    'drone'   => ['title' => 'Grand Haven Drone Reel', 'youtube' => 'https://www.youtube.com/watch?v=REPLACE_ME'],
    'ambient' => ['title' => '',                       'youtube' => 'https://www.youtube.com/watch?v=REPLACE_ME_TOO'],

]));

define('VIDEO_DIR', cfg('video.VIDEO_DIR', __DIR__ . '/videos'));
define('MUTED', cfg('video.MUTED', true));
define('FIT', cfg('video.FIT', 'cover'));
define('SHOW_CLOCK', cfg('video.SHOW_CLOCK', true));
define('MAX_HEIGHT', cfg('video.MAX_HEIGHT', 1080));
define('TIMEZONE', cfg('video.TIMEZONE', 'America/Detroit'));

date_default_timezone_set(TIMEZONE);

function video_path(string $key, array $v): ?string
{
    if (isset($v['file'])) {
        $p = VIDEO_DIR . '/' . basename($v['file']);
        return is_file($p) ? $p : null;
    }
    foreach (['mp4', 'webm', 'mkv'] as $ext) {
        $p = VIDEO_DIR . "/$key.$ext";
        if (is_file($p)) return $p;
    }
    return null;
}

function video_duration(string $path): ?float
{
    $out = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($path) . ' 2>/dev/null');
    $d = is_string($out) ? (float)trim($out) : 0;
    return $d > 0 ? $d : null;
}

// ── CLI fetcher: php video.php fetch ─────────────────────────────────────────
// (bare `php video.php` with no argument falls through and renders the player)
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    if ($argv[1] !== 'fetch') {
        fwrite(STDERR, "Usage: php video.php fetch\n");
        exit(1);
    }
    if (!is_dir(VIDEO_DIR)) mkdir(VIDEO_DIR, 0775, true);
    $fmt = sprintf('bv*[ext=mp4][height<=%d]+ba[ext=m4a]/b[ext=mp4][height<=%d]/b',
        MAX_HEIGHT, MAX_HEIGHT);

    $report = function (string $key, array $v): void {
        $p = video_path($key, $v);
        if ($p === null) { echo "[$key] no local file yet\n"; return; }
        if ($d = video_duration($p)) {
            printf("[%s] %s — duration %s  → Anthias asset length: %d s\n",
                $key, basename($p), gmdate('i:s', (int)$d), (int)ceil($d));
        } else {
            echo "[$key] " . basename($p) . " — install ffmpeg/ffprobe for a duration readout\n";
        }
    };

    foreach (VIDEOS as $key => $v) {
        if (!isset($v['youtube'])) {
            $report($key, $v);
            continue;
        }
        if (str_contains($v['youtube'], 'REPLACE_ME')) {
            echo "[$key] skipped — put a real YouTube URL in VIDEOS\n";
            $report($key, $v);
            continue;
        }
        echo "[$key] fetching {$v['youtube']}\n";
        $out = VIDEO_DIR . "/$key.%(ext)s";
        passthru('yt-dlp -f ' . escapeshellarg($fmt)
            . ' --merge-output-format mp4 --no-progress --force-overwrites'
            . ' -o ' . escapeshellarg($out)
            . ' ' . escapeshellarg($v['youtube']), $rc);
        if ($rc !== 0) { echo "[$key] yt-dlp failed (exit $rc)\n"; continue; }
        $report($key, $v);
    }
    exit(0);
}

// ── Player ───────────────────────────────────────────────────────────────────
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$key = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['v'] ?? ''));
if ($key === '' || !isset(VIDEOS[$key])) $key = array_key_first(VIDEOS);
$video = VIDEOS[$key];
$path  = video_path($key, $video);
$src   = $path ? 'videos/' . rawurlencode(basename($path)) : null;
$title = $video['title'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($title !== '' ? $title : 'Video') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --lake-night:#0c1422; --mist:#8aa0c0; --beacon:#ffb347; --snow:#edf2fb; }
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { width:1920px; height:1080px; overflow:hidden; background:#000;
              font-family:'IBM Plex Sans',sans-serif; cursor:none; }
  video { width:1920px; height:1080px; object-fit:<?= FIT ?>; background:#000; }
  .chrome { position:absolute; top:36px; left:48px; right:48px; z-index:5;
            display:flex; justify-content:space-between; align-items:baseline;
            text-shadow:0 1px 14px rgba(0,0,0,.8); }
  .title { font-family:'Big Shoulders Display'; font-weight:700; font-size:48px;
           color:var(--snow); letter-spacing:1px; }
  #clock { font-family:'Big Shoulders Display'; font-weight:600; font-size:44px;
           color:var(--mist); font-variant-numeric:tabular-nums; }
  .empty { position:absolute; inset:0; display:flex; flex-direction:column; gap:16px;
           align-items:center; justify-content:center; background:var(--lake-night);
           color:var(--mist); }
  .empty h2 { font-family:'Big Shoulders Display'; font-size:58px; color:var(--snow); }
  .empty p { font-size:27px; max-width:1100px; text-align:center; line-height:1.6; }
  .empty code { color:var(--beacon); background:#141f33; padding:2px 12px; border-radius:6px; }
</style>
</head>
<body>
<?php if ($src === null): ?>
  <div class="empty">
    <h2>No video downloaded for &ldquo;<?= h($key) ?>&rdquo;</h2>
    <p>Run <code>php video.php fetch</code> on the server to download the entries
       in <code>VIDEOS</code>, or drop a file at
       <code>videos/<?= h($key) ?>.mp4</code>.</p>
  </div>
<?php else: ?>
  <video src="<?= h($src) ?>" autoplay <?= MUTED ? 'muted' : '' ?> loop playsinline></video>
  <div class="chrome">
    <div class="title"><?= h($title) ?></div>
    <?php if (SHOW_CLOCK): ?><div id="clock">--:--</div><?php endif; ?>
  </div>
  <script>
    <?php if (SHOW_CLOCK): ?>
    function tick(){ const n=new Date(); let h=n.getHours(); const ap=h>=12?'PM':'AM'; h=h%12||12;
      document.getElementById('clock').textContent = h+':'+String(n.getMinutes()).padStart(2,'0')+' '+ap; }
    tick(); setInterval(tick, 1000);
    <?php endif; ?>
    // Belt and suspenders: some Chromium builds pause looped video on decode
    // hiccups; nudge it back.
    const v = document.querySelector('video');
    setInterval(() => { if (v.paused) v.play().catch(()=>{}); }, 5000);
  </script>
<?php endif; ?>
<?php include __DIR__ . '/ticker.php'; ?>
</body>
</html>
