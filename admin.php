<?php
/**
 * SIGNAGE ADMIN — web frontend for every board's configuration.
 * Edits config/settings.json; the board PHP files are never modified, so the
 * web server only needs write access to config/ and cache/.
 *
 * First visit: you'll be asked to create an admin password (stored as a
 * password_hash in config/admin.json). To reset it, delete that file.
 *
 * Settings left blank fall back to each board's built-in default. The Tools
 * page can clear the API cache and shows the raw JSON for hand editing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/slides_lib.php';

const ADMIN_FILE = __DIR__ . '/config/admin.json';

slide_background_ensure_assets();

session_start();

/** Drop deny-all .htaccess into config/ and cache/ so tokens and cached API
 *  responses can't be fetched directly (Apache; nginx needs a location block
 *  — see README). */
function protect_dirs(): void
{
    foreach (['/config', '/cache', '/slides'] as $d) {
        $dir = __DIR__ . $d;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) @file_put_contents($ht, "Require all denied\n");
    }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function admin_hash(): ?string
{
    if (!is_file(ADMIN_FILE)) return null;
    $j = json_decode((string)file_get_contents(ADMIN_FILE), true);
    return is_array($j) && !empty($j['hash']) ? $j['hash'] : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

$schema  = admin_schema();
$hash    = admin_hash();
$flash   = null;
$flashOk = true;

// ── Auth actions ─────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'setup' && $hash === null) {
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 8) {
        $flash = 'Password must be at least 8 characters.'; $flashOk = false;
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $flash = 'Passwords do not match.'; $flashOk = false;
    } else {
        if (!is_dir(__DIR__ . '/config')) @mkdir(__DIR__ . '/config', 0775, true);
        protect_dirs();
        @file_put_contents(ADMIN_FILE, json_encode(['hash' => password_hash($pw, PASSWORD_DEFAULT)]), LOCK_EX);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $hash = admin_hash();
        $flash = 'Admin password set. Welcome!';
    }
}
if (($_POST['action'] ?? '') === 'login' && $hash !== null) {
    if (password_verify((string)($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
    } else {
        usleep(400000);
        $flash = 'Wrong password.'; $flashOk = false;
    }
}
if (($_GET['logout'] ?? '') === '1') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}
$authed = !empty($_SESSION['auth']);

// ── Save handler ─────────────────────────────────────────────────────────────
$board = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['board'] ?? $_POST['board'] ?? ''));
if ($board === '' || !isset($schema[$board])) $board = array_key_first($schema);

if ($authed && ($_POST['action'] ?? '') === 'save' && csrf_ok()) {
    $conf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    $errors = [];
    foreach ($schema[$board]['fields'] as $f) {
        $cfgKey = "$board.{$f['key']}";
        $name   = $f['key'];
        switch ($f['type']) {
            case 'bool':
                $conf[$cfgKey] = isset($_POST[$name]);
                break;
            case 'number':
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw === '') { unset($conf[$cfgKey]); break; }
                $conf[$cfgKey] = str_contains($raw, '.') ? (float)$raw : (int)$raw;
                break;
            case 'password':
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw !== '') $conf[$cfgKey] = $raw;   // blank = leave existing secret
                break;
            case 'json':
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw === '') { unset($conf[$cfgKey]); break; }
                $dec = json_decode($raw, true);
                if (!is_array($dec)) { $errors[] = "{$f['label']}: invalid JSON — not saved."; break; }
                $conf[$cfgKey] = $dec;
                break;
            case 'rows':
                $rows = $_POST[$name] ?? [];
                if (!is_array($rows)) $rows = [];
                $keyed  = !empty($f['keyed']);
                $scalar = !empty($f['scalar']);
                $outV = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $row = array_map(fn($v) => trim((string)$v), $row);
                    if ($keyed) {
                        $k = $row['_key'] ?? '';
                        if ($k === '') continue;
                        if ($scalar) {
                            if (($row['_value'] ?? '') === '') continue;
                            $outV[$k] = $row['_value'];
                        } else {
                            $obj = [];
                            foreach ($f['columns'] as $col) {
                                if ($col['key'] === '_key') continue;
                                if (($col['type'] ?? '') === 'check') {
                                    if (($row[$col['key']] ?? '') !== '') $obj[$col['key']] = true;
                                    continue;
                                }
                                $v = $row[$col['key']] ?? '';
                                if ($v === '') continue;
                                $obj[$col['key']] = ($col['cast'] ?? '') === 'int' ? (int)$v : $v;
                            }
                            if ($obj !== []) $outV[$k] = $obj;
                        }
                    } else {
                        $obj = [];
                        $any = false;
                        foreach ($f['columns'] as $col) {
                            if (($col['type'] ?? '') === 'check') {
                                if (($row[$col['key']] ?? '') !== '') $obj[$col['key']] = true;
                                continue;          // a lone checkbox doesn't make a row real
                            }
                            $v = $row[$col['key']] ?? '';
                            if ($v === '') continue;          // omit blank cells (a 0 is not "unset")
                            $any = true;
                            $obj[$col['key']] = ($col['cast'] ?? '') === 'int' ? (int)$v : $v;
                        }
                        if ($any) $outV[] = $obj;
                    }
                }
                if ($outV === []) unset($conf[$cfgKey]); else $conf[$cfgKey] = $outV;
                break;
            default:    // text, select, textarea
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw === '') unset($conf[$cfgKey]); else $conf[$cfgKey] = $raw;
        }
    }
    $conf = array_filter($conf, fn($v) => $v !== null);
    if ($errors) {
        $flash = implode(' ', $errors); $flashOk = false;
    } else {
        protect_dirs();
        if (cfg_write($conf)) {
            if (isset($_POST['clear_cache'])) {
                foreach (glob(__DIR__ . '/cache/*.{dat,json}', GLOB_BRACE) ?: [] as $cf) @unlink($cf);
            }
            $flash = $schema[$board]['title'] . ' saved.' . (isset($_POST['clear_cache']) ? ' Cache cleared.' : '');
            cfg_reload();
            $schema = admin_schema();   // pick up structural changes (e.g. new rotation screens)
        } else {
            $flash = 'Could not write config/settings.json — check directory permissions.'; $flashOk = false;
        }
    }
}

if ($authed && ($_POST['action'] ?? '') === 'clearcache' && csrf_ok()) {
    $n = 0;
    foreach (glob(__DIR__ . '/cache/*.{dat,json}', GLOB_BRACE) ?: [] as $cf) { @unlink($cf); $n++; }
    $flash = "Cleared $n cached file" . ($n === 1 ? '' : 's') . '.';
}

// ── Custom slides: upload / delete ──────────────────────────────────────────
if ($authed && $board === 'slides' && csrf_ok()) {
    $slideDir = slides_dir();
    if (!is_dir($slideDir)) @mkdir($slideDir, 0775, true);
    protect_dirs();

    if (($_POST['action'] ?? '') === 'upload_slide' && isset($_FILES['slide'])) {
        $f = $_FILES['slide'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = 'Upload failed — try again.'; $flashOk = false;
        } elseif (($f['size'] ?? 0) > 15 * 1024 * 1024) {
            $flash = 'Image must be under 15 MB.'; $flashOk = false;
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extMap[$mime])) {
                $flash = 'Only JPG, PNG, or WebP images are allowed.'; $flashOk = false;
            } else {
                $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($f['name'], PATHINFO_FILENAME));
                $base = trim($base, '-._');
                if ($base === '') $base = 'slide';
                $name = $base . '.' . $extMap[$mime];
                if (is_file($slideDir . '/' . $name)) {
                    $name = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $extMap[$mime];
                }
                if (@move_uploaded_file($f['tmp_name'], $slideDir . '/' . $name)) {
                    if (slide_append_to_deck($name)) {
                        $flash = 'Uploaded ' . $name . ' — set to Always; edit schedule below and Save.';
                    } else {
                        $flash = 'File saved but could not update settings.json.'; $flashOk = false;
                    }
                } else {
                    $flash = 'Could not write to ' . slides_dir() . ' — check permissions.'; $flashOk = false;
                }
            }
        }
    }

    if (($_POST['action'] ?? '') === 'delete_slide') {
        $del = slide_safe_filename((string)($_POST['file'] ?? ''));
        if ($del === null) {
            $flash = 'Invalid filename.'; $flashOk = false;
        } else {
            $conf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
            $deck = $conf['slides.SLIDES'] ?? [];
            if (is_array($deck)) {
                $conf['slides.SLIDES'] = array_values(array_filter($deck, fn($s) =>
                    !is_array($s) || ($s['file'] ?? '') !== $del));
            }
            cfg_write($conf);
            cfg_reload();
            @unlink($slideDir . '/' . $del);
            $flash = 'Deleted ' . $del . '.';
        }
    }

    if (($_POST['action'] ?? '') === 'create_slide' && isset($_FILES['slide_image'])) {
        $f = $_FILES['slide_image'];
        $title = trim((string)($_POST['creator_title'] ?? ''));
        $slug  = trim((string)($_POST['creator_name'] ?? ''));
        if ($slug === '' && $title !== '') {
            $slug = $title;
        }
        if ($slug === '') {
            $slug = 'slide';
        }

        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = 'Could not create slide — try again.'; $flashOk = false;
        } elseif (($f['size'] ?? 0) > 8 * 1024 * 1024) {
            $flash = 'Rendered slide must be under 8 MB.'; $flashOk = false;
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if ($mime !== 'image/png') {
                $flash = 'Slide must be a PNG image.'; $flashOk = false;
            } else {
                $info = @getimagesize($f['tmp_name']);
                if (!$info || ($info[0] ?? 0) !== 1920 || ($info[1] ?? 0) !== 1080) {
                    $flash = 'Slide must be exactly 1920×1080 pixels.'; $flashOk = false;
                } else {
                    $name = slide_unique_filename($slug, 'png', $slideDir);
                    if (@move_uploaded_file($f['tmp_name'], $slideDir . '/' . $name)) {
                        $caption = $title !== '' ? $title : trim((string)($_POST['creator_subtitle'] ?? ''));
                        $extra = ['schedule' => 'always'];
                        if ($caption !== '') {
                            $extra['caption'] = $caption;
                        }
                        if (slide_append_to_deck($name, $extra)) {
                            $flash = 'Created ' . $name . ' — set to Always; edit schedule below and Save.';
                        } else {
                            $flash = 'Slide saved but could not update settings.json.'; $flashOk = false;
                        }
                    } else {
                        $flash = 'Could not write to ' . slides_dir() . ' — check permissions.'; $flashOk = false;
                    }
                }
            }
        }
    }
}

// Current value resolution for form display: configured value or null
$rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
function current_val(array $rawConf, string $board, string $key)
{
    return $rawConf["$board.$key"] ?? null;
}
$tools = ($_GET['board'] ?? '') === 'tools';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signage Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400&display=swap" rel="stylesheet">
<style>
  :root { --night:#0c1422; --harbor:#141f33; --line:#26344d; --snow:#edf2fb;
          --mist:#8aa0c0; --beacon:#ffb347; --ok:#39c46d; --bad:#ff5d5d; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--night); color:var(--snow); font-family:'IBM Plex Sans',sans-serif;
         min-height:100vh; }
  a { color:var(--beacon); text-decoration:none; }
  .top { display:flex; align-items:baseline; justify-content:space-between;
         padding:22px 30px; border-bottom:1px solid var(--line); }
  .top h1 { font-family:'Big Shoulders Display'; font-weight:700; font-size:34px; }
  .top h1 span { color:var(--beacon); }
  .top a { color:var(--mist); font-size:15px; }
  .wrap { display:flex; min-height:calc(100vh - 79px); }
  nav { width:230px; border-right:1px solid var(--line); padding:18px 0; flex:0 0 auto; }
  nav a { display:block; padding:11px 26px; color:var(--snow); font-size:16px; }
  nav a:hover { background:var(--harbor); }
  nav a.active { background:var(--harbor); border-left:3px solid var(--beacon); padding-left:23px; }
  nav .sep { margin:14px 26px; border-top:1px solid var(--line); }
  main { flex:1; padding:30px 38px; max-width:1000px; }
  h2 { font-family:'Big Shoulders Display'; font-weight:600; font-size:30px; margin-bottom:4px; }
  .sub { color:var(--mist); font-size:15px; margin-bottom:24px; }
  .sub a { margin-left:10px; }
  .field { margin-bottom:22px; }
  label.l { display:block; font-size:14px; letter-spacing:1px; text-transform:uppercase;
            color:var(--mist); margin-bottom:7px; }
  input[type=text], input[type=password], input[type=number], select, textarea {
    width:100%; max-width:560px; background:var(--harbor); border:1px solid var(--line);
    border-radius:8px; color:var(--snow); padding:10px 13px; font-size:16px;
    font-family:inherit; }
  textarea { max-width:100%; font-family:'IBM Plex Mono',monospace; font-size:14px; min-height:220px; }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--beacon); }
  .help { font-size:13.5px; color:var(--mist); margin-top:6px; }
  .check { display:flex; gap:10px; align-items:center; font-size:16px; }
  .check input { width:20px; height:20px; accent-color:var(--beacon); }
  table.rows { border-collapse:collapse; width:100%; margin-top:4px; }
  table.rows th { text-align:left; font-size:12.5px; letter-spacing:1px; text-transform:uppercase;
                  color:var(--mist); font-weight:500; padding:4px 8px 8px 0; }
  table.rows td { padding:0 8px 10px 0; vertical-align:top; }
  table.rows input { max-width:none; min-width:90px; padding:8px 10px; font-size:15px; }
  td.wide { width:38%; }
  .rowdel { background:none; border:1px solid var(--line); color:var(--bad); border-radius:8px;
            width:36px; height:38px; cursor:pointer; font-size:18px; }
  .addrow { background:var(--harbor); border:1px solid var(--line); color:var(--snow);
            border-radius:8px; padding:8px 16px; font-size:14px; cursor:pointer; }
  .actions { margin-top:30px; display:flex; gap:16px; align-items:center; }
  button.save { background:var(--beacon); border:0; color:var(--night); font-weight:600;
                font-size:17px; padding:12px 30px; border-radius:9px; cursor:pointer; }
  .flash { padding:13px 18px; border-radius:9px; margin-bottom:22px; font-size:15.5px;
           background:rgba(57,196,109,.12); border:1px solid var(--ok); }
  .flash.bad { background:rgba(255,93,93,.12); border-color:var(--bad); }
  .login { max-width:420px; margin:10vh auto; background:var(--harbor); border:1px solid var(--line);
           border-radius:14px; padding:38px; }
  .login h2 { margin-bottom:18px; }
  .login .field input { max-width:100%; }
  pre.raw { background:var(--harbor); border:1px solid var(--line); border-radius:10px;
            padding:18px; font-family:'IBM Plex Mono',monospace; font-size:13.5px;
            overflow:auto; max-height:480px; }
  .upload-box { background:var(--harbor); border:1px solid var(--line); border-radius:12px;
                padding:20px 22px; margin-bottom:24px; }
  .upload-box h3 { font-family:'Big Shoulders Display'; font-size:22px; margin-bottom:12px; }
  .upload-row { display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
  .upload-row input[type=file] { max-width:100%; color:var(--mist); font-size:14px; }
  button.secondary { background:var(--harbor); border:1px solid var(--line); color:var(--snow);
                     font-weight:600; font-size:15px; padding:10px 18px; border-radius:9px; cursor:pointer; }
  .filelist { margin-top:16px; font-size:14px; color:var(--mist); }
  .filelist li { display:flex; align-items:center; justify-content:space-between; gap:12px;
                 padding:6px 0; border-bottom:1px solid var(--line); list-style:none; }
  .filelist code { color:var(--snow); font-size:13px; }
  .filelist form { margin:0; }
  .filelist button { font-size:13px; padding:4px 10px; }
  table.rows select { width:100%; min-width:90px; padding:8px 10px; font-size:15px;
                       background:var(--harbor); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .creator-box { background:var(--harbor); border:1px solid var(--line); border-radius:12px;
                  padding:20px 22px; margin-bottom:24px; }
  .creator-box h3 { font-family:'Big Shoulders Display'; font-size:22px; margin-bottom:12px; }
  .creator-grid { display:grid; grid-template-columns:1fr min(672px, 48vw); gap:28px; align-items:start; }
  @media (max-width: 1100px) { .creator-grid { grid-template-columns:1fr; } }
  .creator-fields label.l { margin-top:14px; }
  .creator-fields label.l:first-child { margin-top:0; }
  .creator-fields textarea { max-width:100%; min-height:88px; font-family:inherit; font-size:15px; resize:vertical; }
  .bg-pick { display:flex; flex-wrap:wrap; gap:10px; margin-top:8px; }
  .bg-pick label { cursor:pointer; }
  .bg-pick input { position:absolute; opacity:0; pointer-events:none; }
  .bg-swatch { display:block; width:108px; height:62px; border-radius:8px; border:2px solid var(--line);
               overflow:hidden; position:relative; background:#141f33 center/cover no-repeat; }
  .bg-swatch img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
  .bg-pick input:checked + .bg-swatch { border-color:var(--beacon); box-shadow:0 0 0 1px var(--beacon); }
  .bg-swatch span { position:absolute; left:8px; bottom:6px; z-index:1; font-size:11px; color:#edf2fb;
                     text-shadow:0 1px 4px rgba(0,0,0,.8); letter-spacing:.3px; }
  .align-row { display:flex; gap:16px; margin-top:8px; }
  .align-row label { display:flex; align-items:center; gap:7px; font-size:15px; cursor:pointer; }
  .preview-wrap { background:#000; border-radius:10px; border:1px solid var(--line); overflow:hidden;
                  width:672px; max-width:100%; aspect-ratio:16/9; }
  .preview-wrap canvas { width:100%; height:100%; display:block; }
  .creator-actions { margin-top:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
</style>
</head>
<body>
<div class="top">
  <h1>Signage <span>&middot; Admin</span></h1>
  <?php if ($authed): ?><a href="?logout=1">Log out</a><?php endif; ?>
</div>

<?php if (!$authed): ?>
  <div class="login">
    <?php if ($flash): ?><div class="flash<?= $flashOk ? '' : ' bad' ?>"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($hash === null): ?>
      <h2>Create admin password</h2>
      <form method="post">
        <input type="hidden" name="action" value="setup">
        <div class="field"><label class="l">Password (8+ characters)</label>
          <input type="password" name="password" autofocus></div>
        <div class="field"><label class="l">Confirm</label>
          <input type="password" name="password2"></div>
        <div class="actions"><button class="save">Set password</button></div>
      </form>
    <?php else: ?>
      <h2>Log in</h2>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="field"><label class="l">Password</label>
          <input type="password" name="password" autofocus></div>
        <div class="actions"><button class="save">Log in</button></div>
      </form>
    <?php endif; ?>
  </div>

<?php else: ?>
<div class="wrap">
  <nav>
    <?php foreach ($schema as $k => $b): ?>
      <a href="?board=<?= h($k) ?>" class="<?= (!$tools && $k === $board) ? 'active' : '' ?>"><?= h($b['title']) ?></a>
    <?php endforeach; ?>
    <div class="sep"></div>
    <a href="?board=tools" class="<?= $tools ? 'active' : '' ?>">Tools / Raw JSON</a>
  </nav>
  <main>
    <?php if ($flash): ?><div class="flash<?= $flashOk ? '' : ' bad' ?>"><?= h($flash) ?></div><?php endif; ?>

    <?php if ($tools): ?>
      <h2>Tools</h2>
      <div class="sub">Housekeeping and the raw configuration.</div>
      <form method="post" style="margin-bottom:28px">
        <input type="hidden" name="action" value="clearcache">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="save">Clear API cache</button>
        <div class="help" style="margin-top:8px">Deletes everything in cache/ — boards refetch on next load. Useful after changing API keys or sources.</div>
      </form>
      <h2 style="font-size:22px">config/settings.json</h2>
      <div class="sub">Only keys that differ from board defaults are stored. Edit by hand if you like — boards pick it up on next render.</div>
      <pre class="raw"><?= h(json_encode($rawConf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>

    <?php else: $b = $schema[$board]; ?>
      <h2><?= h($b['title']) ?></h2>
      <div class="sub">Saves to config/settings.json — blank fields use the board's built-in default.
        <a href="<?= h($b['file']) ?>" target="_blank">View board ↗</a></div>

      <?php if ($board === 'slides'): ?>
      <div class="upload-box">
        <h3>Upload a slide</h3>
        <form method="post" enctype="multipart/form-data" class="upload-row" action="?board=slides">
          <input type="hidden" name="action" value="upload_slide">
          <input type="hidden" name="board" value="slides">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="file" name="slide" accept="image/jpeg,image/png,image/webp" required>
          <button class="secondary" type="submit">Upload</button>
        </form>
        <div class="help" style="margin-top:10px">1920×1080 JPG/PNG recommended. New uploads default to <strong>Always</strong> — set birthday/weekday/range below.</div>
        <?php $diskFiles = slides_list_files();
        if ($diskFiles): ?>
        <ul class="filelist">
          <?php foreach ($diskFiles as $df): ?>
            <li>
              <code><?= h($df) ?></code>
              <form method="post" action="?board=slides" onsubmit="return confirm('Delete <?= h($df) ?>?');">
                <input type="hidden" name="action" value="delete_slide">
                <input type="hidden" name="board" value="slides">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="file" value="<?= h($df) ?>">
                <button class="secondary" type="submit">Delete</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>

      <div class="creator-box">
        <h3>Create a slide</h3>
        <p class="help" style="margin-bottom:16px">Pick a background from <code>slide_backgrounds/</code>, add title and body text, preview at 1920×1080, then save as PNG into your slide deck.</p>
        <div class="creator-grid">
          <div class="creator-fields">
            <label class="l">Background</label>
            <div class="bg-pick" id="bgPick">
              <?php foreach (slide_background_presets() as $id => $preset):
                $bgUrl = slide_background_url($id); ?>
                <label title="<?= h($preset['label']) ?>">
                  <input type="radio" name="creator_bg" value="<?= h($id) ?>" <?= $id === 'lake_night' ? 'checked' : '' ?>>
                  <div class="bg-swatch" data-bg="<?= h($id) ?>">
                    <?php if ($bgUrl): ?><img src="<?= h($bgUrl) ?>" alt="" loading="lazy"><?php endif; ?>
                    <span><?= h($preset['label']) ?></span>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <label class="l">Alignment</label>
            <div class="align-row">
              <label><input type="radio" name="creator_align" value="left" checked> Left</label>
              <label><input type="radio" name="creator_align" value="center"> Center</label>
            </div>

            <label class="l" for="creator_title">Title</label>
            <input type="text" id="creator_title" placeholder="Happy Birthday, Mom!">

            <label class="l" for="creator_subtitle">Subtitle</label>
            <input type="text" id="creator_subtitle" placeholder="March 15">

            <label class="l" for="creator_body">Body</label>
            <textarea id="creator_body" placeholder="Dinner at 6 — cake after."></textarea>

            <label class="l" for="creator_footer">Footer (optional)</label>
            <input type="text" id="creator_footer" placeholder="Love, the family">

            <label class="l" for="creator_name">Filename</label>
            <input type="text" id="creator_name" placeholder="mom-birthday (optional — .png added)">

            <div class="creator-actions">
              <button type="button" class="secondary" id="creatorRefresh">Refresh preview</button>
              <button type="button" class="save" id="creatorSave">Create slide</button>
            </div>
          </div>
          <div>
            <label class="l">Preview</label>
            <div class="preview-wrap"><canvas id="slidePreview" width="1920" height="1080"></canvas></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <form method="post" id="boardform" action="?board=<?= h($board) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="board" value="<?= h($board) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <?php foreach ($b['fields'] as $f):
          $val = current_val($rawConf, $board, $f['key']); ?>
          <div class="field">
            <?php if ($f['type'] === 'bool'): ?>
              <label class="check"><input type="checkbox" name="<?= h($f['key']) ?>"
                <?= ($val ?? ($f['default'] ?? false)) ? 'checked' : '' ?>> <?= h($f['label']) ?></label>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php elseif ($f['type'] === 'select'): ?>
              <label class="l"><?= h($f['label']) ?></label>
              <select name="<?= h($f['key']) ?>">
                <option value="">(default)</option>
                <?php foreach ($f['options'] as $o): ?>
                  <option value="<?= h($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php elseif ($f['type'] === 'json'): ?>
              <label class="l"><?= h($f['label']) ?></label>
              <textarea name="<?= h($f['key']) ?>" spellcheck="false"><?=
                $val !== null ? h(json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : '' ?></textarea>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php elseif ($f['type'] === 'rows'):
              $cols = $f['columns'];
              $rows = [];
              if (is_array($val)) {
                  if (!empty($f['keyed'])) {
                      foreach ($val as $rk => $rv) {
                          if (!empty($f['scalar'])) {
                              $rows[] = ['_key' => $rk, '_value' => $rv];
                          } else {
                              // legacy scalar value → put it in the first real column
                              $first = null;
                              foreach ($cols as $c) if ($c['key'] !== '_key') { $first = $c['key']; break; }
                              $rows[] = ['_key' => $rk] + (is_array($rv) ? $rv : ($first ? [$first => $rv] : []));
                          }
                      }
                  } else {
                      $rows = $val;
                  }
              }
            ?>
              <label class="l"><?= h($f['label']) ?></label>
              <table class="rows" data-field="<?= h($f['key']) ?>">
                <thead><tr>
                  <?php foreach ($cols as $c): ?><th><?= h($c['label']) ?></th><?php endforeach; ?><th></th>
                </tr></thead>
                <tbody>
                  <?php foreach ($rows as $ri => $row): ?>
                    <tr>
                      <?php foreach ($cols as $c): ?>
                        <td class="<?= !empty($c['wide']) ? 'wide' : '' ?>"<?= ($c['type'] ?? '') === 'check' ? ' style="text-align:center;vertical-align:middle"' : '' ?>>
                          <?php if (($c['type'] ?? '') === 'check'): ?>
                            <input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                                   name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]"
                                   <?= !empty($row[$c['key']]) ? 'checked' : '' ?>>
                          <?php elseif (($c['type'] ?? '') === 'select'): ?>
                            <select name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]">
                              <option value=""></option>
                              <?php foreach ($c['options'] as $o): ?>
                                <option value="<?= h($o) ?>" <?= ($row[$c['key']] ?? '') === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                              <?php endforeach; ?>
                            </select>
                          <?php else: ?>
                            <input type="text" name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]"
                                   value="<?= h((string)($row[$c['key']] ?? '')) ?>"
                                   placeholder="<?= h($c['placeholder'] ?? '') ?>">
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <td><button type="button" class="rowdel" onclick="this.closest('tr').remove()">×</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <button type="button" class="addrow" onclick="addRow(this)">+ Add row</button>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php else: ?>
              <label class="l"><?= h($f['label']) ?></label>
              <input type="<?= $f['type'] === 'password' ? 'password' : ($f['type'] === 'number' ? 'number' : 'text') ?>"
                     <?= $f['type'] === 'number' ? 'step="' . h($f['step'] ?? '1') . '"' : '' ?>
                     name="<?= h($f['key']) ?>"
                     <?php if ($f['type'] !== 'password'): ?>value="<?= h($val !== null ? (string)$val : '') ?>"<?php endif; ?>
                     placeholder="<?= h($f['type'] === 'password' && $val !== null ? '(unchanged)' : '(default)') ?>"
                     autocomplete="off">
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="actions">
          <button class="save">Save</button>
          <label class="check"><input type="checkbox" name="clear_cache" checked> Clear cache after save</label>
        </div>
      </form>
    <?php endif; ?>
  </main>
</div>

<script>
function addRow(btn) {
  const table = btn.previousElementSibling;
  const field = table.dataset.field;
  const head = table.querySelectorAll('thead th');
  const idx = 'n' + Date.now() % 1e7 + Math.floor(Math.random() * 1000);
  const tr = document.createElement('tr');
  const cols = <?php
    $colMap = [];
    foreach ($schema as $bk => $bb) foreach ($bb['fields'] as $ff)
      if ($ff['type'] === 'rows') $colMap[$ff['key']] = array_map(fn($c) => ['key' => $c['key'],
        'wide' => !empty($c['wide']), 'ph' => $c['placeholder'] ?? '',
        'check' => ($c['type'] ?? '') === 'check',
        'select' => ($c['type'] ?? '') === 'select',
        'options' => $c['options'] ?? []], $ff['columns']);
    echo json_encode($colMap);
  ?>;
  (cols[field] || []).forEach(c => {
    const td = document.createElement('td');
    if (c.wide) td.className = 'wide';
    let inp = document.createElement('input');
    if (c.check) {
      inp.type = 'checkbox';
      inp.style.cssText = 'width:20px;height:20px;accent-color:var(--beacon);min-width:0';
      td.style.cssText = 'text-align:center;vertical-align:middle';
    } else if (c.select) {
      inp = document.createElement('select');
      const blank = document.createElement('option');
      blank.value = ''; blank.textContent = '';
      inp.appendChild(blank);
      (c.options || []).forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o; opt.textContent = o;
        inp.appendChild(opt);
      });
    } else {
      inp.type = 'text'; inp.placeholder = c.ph;
    }
    inp.name = field + '[' + idx + '][' + c.key + ']';
    td.appendChild(inp); tr.appendChild(td);
  });
  const td = document.createElement('td');
  td.innerHTML = '<button type="button" class="rowdel" onclick="this.closest(\'tr\').remove()">×</button>';
  tr.appendChild(td);
  table.querySelector('tbody').appendChild(tr);
}
</script>
<?php if ($authed && $board === 'slides'): ?>
<script>
(function () {
  const canvas = document.getElementById('slidePreview');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const W = 1920, H = 1080;
  const padX = 88, padTop = 100, padBottom = 120;
  const BACKGROUNDS = <?php
    $bgJs = slide_background_presets();
    foreach ($bgJs as $id => &$bp) {
        $bp['url'] = slide_background_url($id);
    }
    unset($bp);
    echo json_encode($bgJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
  ?>;
  const bgImageCache = {};

  function loadBgImage(url) {
    if (!url) return Promise.resolve(null);
    if (bgImageCache[url]) return Promise.resolve(bgImageCache[url]);
    return new Promise(function (resolve) {
      const img = new Image();
      img.onload = function () { bgImageCache[url] = img; resolve(img); };
      img.onerror = function () { resolve(null); };
      img.src = url;
    });
  }

  async function preloadBackgrounds() {
    await Promise.all(Object.keys(BACKGROUNDS).map(function (id) {
      return loadBgImage(BACKGROUNDS[id].url);
    }));
  }

  function linearGradient(c, bg) {
    const rad = (bg.angle || 0) * Math.PI / 180;
    const x0 = W / 2 - Math.cos(rad) * W / 2;
    const y0 = H / 2 - Math.sin(rad) * H / 2;
    const x1 = W / 2 + Math.cos(rad) * W / 2;
    const y1 = H / 2 + Math.sin(rad) * H / 2;
    const g = c.createLinearGradient(x0, y0, x1, y1);
    (bg.stops || []).forEach(function (s) { g.addColorStop(s[0], s[1]); });
    return g;
  }

  function radialGradient(c, bg) {
    const g = c.createRadialGradient(
      bg.cx * W, bg.cy * H, 0,
      bg.cx * W, bg.cy * H, (bg.r || 1) * Math.max(W, H)
    );
    (bg.stops || []).forEach(function (s) { g.addColorStop(s[0], s[1]); });
    return g;
  }

  function drawBackground(c, preset) {
    if (preset.url && bgImageCache[preset.url]) {
      c.drawImage(bgImageCache[preset.url], 0, 0, W, H);
      return;
    }
    const bg = preset.bg || {};
    c.fillStyle = bg.type === 'radial' ? radialGradient(c, bg) : linearGradient(c, bg);
    c.fillRect(0, 0, W, H);
    const accent = preset.accent;
    if (!accent) return;
    if (accent.type === 'bar') {
      c.fillStyle = accent.color;
      c.fillRect(0, 0, accent.width || 12, H);
    } else if (accent.type === 'glow') {
      const g = c.createRadialGradient(W * 0.85, H * 0.1, 0, W * 0.85, H * 0.1, W * 0.45);
      g.addColorStop(0, accent.color);
      g.addColorStop(1, 'rgba(0,0,0,0)');
      c.save();
      c.globalAlpha = accent.opacity || 0.2;
      c.fillStyle = g;
      c.fillRect(0, 0, W, H);
      c.restore();
    }
  }

  function wrapLines(c, text, maxWidth) {
    const words = text.replace(/\s+/g, ' ').trim().split(' ');
    if (!words[0]) return [];
    const lines = [];
    let line = words[0];
    for (let i = 1; i < words.length; i++) {
      const test = line + ' ' + words[i];
      if (c.measureText(test).width <= maxWidth) line = test;
      else { lines.push(line); line = words[i]; }
    }
    lines.push(line);
    return lines;
  }

  async function ensureFonts() {
    await Promise.all([
      document.fonts.load('600 88px "Big Shoulders Display"'),
      document.fonts.load('500 42px "Big Shoulders Display"'),
      document.fonts.load('400 30px "IBM Plex Sans"'),
      document.fonts.load('400 24px "IBM Plex Sans"'),
    ]);
  }

  function selectedPreset() {
    const id = document.querySelector('input[name="creator_bg"]:checked');
    return BACKGROUNDS[(id && id.value) || 'lake_night'] || BACKGROUNDS.lake_night;
  }

  function textAlign() {
    const el = document.querySelector('input[name="creator_align"]:checked');
    return (el && el.value) === 'center' ? 'center' : 'left';
  }

  async function renderPreview() {
    await ensureFonts();
    const preset = selectedPreset();
    await loadBgImage(preset.url);
    const align = textAlign();
    const title = document.getElementById('creator_title').value.trim();
    const subtitle = document.getElementById('creator_subtitle').value.trim();
    const body = document.getElementById('creator_body').value.trim();
    const footer = document.getElementById('creator_footer').value.trim();
    const maxW = align === 'center' ? W - padX * 2 : 1240;
    const x = align === 'center' ? W / 2 : padX;

    ctx.clearRect(0, 0, W, H);
    drawBackground(ctx, preset);
    ctx.textAlign = align;
    ctx.textBaseline = 'top';
    let y = padTop;

    function drawBlock(text, font, color, lineH, maxLines) {
      if (!text) return;
      ctx.font = font;
      ctx.fillStyle = color;
      let lines = wrapLines(ctx, text, maxW);
      if (maxLines && lines.length > maxLines) {
        lines = lines.slice(0, maxLines);
        lines[maxLines - 1] = lines[maxLines - 1].replace(/\s+\S*$/, '') + '\u2026';
      }
      lines.forEach(function (ln) {
        ctx.fillText(ln, x, y);
        y += lineH;
      });
    }

    drawBlock(title, '600 88px "Big Shoulders Display", sans-serif', preset.title, 94, 3);
    if (title && subtitle) y += 8;
    drawBlock(subtitle, '500 42px "Big Shoulders Display", sans-serif', preset.subtitle, 50);
    if ((title || subtitle) && body) y += 20;
    drawBlock(body, '400 30px "IBM Plex Sans", sans-serif', preset.body, 44, 8);

    if (footer) {
      ctx.font = '400 24px "IBM Plex Sans", sans-serif';
      ctx.fillStyle = preset.footer || preset.body;
      ctx.textAlign = align;
      ctx.fillText(footer, x, H - padBottom);
    }
  }

  document.querySelectorAll('input[name="creator_bg"], input[name="creator_align"]').forEach(function (el) {
    el.addEventListener('change', renderPreview);
  });

  preloadBackgrounds().then(renderPreview);

  document.getElementById('creatorRefresh').addEventListener('click', renderPreview);
  ['creator_title', 'creator_subtitle', 'creator_body', 'creator_footer'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () {
      clearTimeout(window._slidePreviewT);
      window._slidePreviewT = setTimeout(renderPreview, 220);
    });
  });

  document.getElementById('creatorSave').addEventListener('click', function () {
    const btn = this;
    const title = document.getElementById('creator_title').value.trim();
    const body = document.getElementById('creator_body').value.trim();
    if (!title && !body) {
      alert('Add a title or body before creating a slide.');
      return;
    }
    btn.disabled = true;
    renderPreview().then(function () {
      canvas.toBlob(function (blob) {
        if (!blob) {
          alert('Could not render slide.');
          btn.disabled = false;
          return;
        }
        const fd = new FormData();
        fd.append('action', 'create_slide');
        fd.append('board', 'slides');
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fd.append('creator_title', title);
        fd.append('creator_subtitle', document.getElementById('creator_subtitle').value.trim());
        fd.append('creator_name', document.getElementById('creator_name').value.trim());
        fd.append('slide_image', blob, 'slide.png');
        fetch('?board=slides', { method: 'POST', body: fd })
          .then(function (res) {
            if (res.ok) location.href = '?board=slides';
            else {
              alert('Save failed — check server permissions.');
              btn.disabled = false;
            }
          })
          .catch(function () {
            alert('Save failed — network error.');
            btn.disabled = false;
          });
      }, 'image/png');
    }).catch(function () {
      btn.disabled = false;
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
