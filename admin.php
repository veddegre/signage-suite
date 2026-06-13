<?php
/**
 * SIGNAGE ADMIN — web frontend for every board's configuration.
 * Edits config/settings.json; the board PHP files are never modified, so the
 * web server only needs write access to config/, cache/, videos/, slides/, photos/, and bin/.
 *
 * First visit: create an admin password using the one-time key in config/setup.key
 * (on the server via SSH — not downloadable over HTTP). Stored as password_hash
 * in config/admin.json. Change password under Tools; delete admin.json to reset
 * (a new setup.key is created).
 *
 * Settings left blank fall back to each board's built-in default. The Tools
 * page can clear the API cache and shows the raw JSON for hand editing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/slides_lib.php';
require_once __DIR__ . '/rotator_lib.php';
require_once __DIR__ . '/video_lib.php';
require_once __DIR__ . '/traffic_lib.php';

slide_background_ensure_assets();

const ADMIN_FILE = __DIR__ . '/config/admin.json';

signage_admin_security_headers();
signage_session_start();
signage_setup_key_ready();

/** Drop deny-all .htaccess into config/ and cache/ so tokens and cached API
 *  responses can't be fetched directly (Apache; nginx needs a location block
 *  — see README). */
function protect_dirs(): void
{
    foreach (['/config', '/cache', '/slides', '/photos', '/bin'] as $d) {
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
    $setupKey = (string)($_POST['setup_key'] ?? '');
    if (!signage_setup_key_valid($setupKey)) {
        $flash = 'Invalid setup key — read config/setup.key on the server (SSH).'; $flashOk = false;
    } elseif (strlen($pw) < 8) {
        $flash = 'Password must be at least 8 characters.'; $flashOk = false;
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $flash = 'Passwords do not match.'; $flashOk = false;
    } else {
        if (!is_dir(__DIR__ . '/config')) @mkdir(__DIR__ . '/config', 0775, true);
        protect_dirs();
        @file_put_contents(ADMIN_FILE, json_encode(['hash' => password_hash($pw, PASSWORD_DEFAULT)]), LOCK_EX);
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['last_active'] = time();
        signage_setup_key_consume();
        signage_login_succeeded();
        $hash = admin_hash();
        $flash = 'Admin password set. Welcome!';
    }
}
if (($_POST['action'] ?? '') === 'login' && $hash !== null) {
    $gate = signage_login_allowed();
    if (!$gate['ok']) {
        $flash = 'Too many failed attempts — try again in ' . (int)ceil(($gate['wait'] ?? 900) / 60) . ' minutes.';
        $flashOk = false;
    } elseif (password_verify((string)($_POST['password'] ?? ''), $hash)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['last_active'] = time();
        signage_login_succeeded();
    } else {
        signage_login_failed();
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
if ($authed && !signage_admin_idle_check()) {
    $authed = false;
    $flash = 'Session expired from inactivity — log in again.';
    $flashOk = false;
}

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

if ($authed && ($_POST['action'] ?? '') === 'changepassword' && csrf_ok()) {
    $cur = (string)($_POST['current_password'] ?? '');
    $pw = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password2'] ?? '');
    $curHash = admin_hash();
    if ($curHash === null || !password_verify($cur, $curHash)) {
        $flash = 'Current password is wrong.'; $flashOk = false;
    } elseif (strlen($pw) < 8) {
        $flash = 'New password must be at least 8 characters.'; $flashOk = false;
    } elseif ($pw !== $pw2) {
        $flash = 'New passwords do not match.'; $flashOk = false;
    } else {
        @file_put_contents(ADMIN_FILE, json_encode(['hash' => password_hash($pw, PASSWORD_DEFAULT)]), LOCK_EX);
        $flash = 'Admin password updated.';
    }
}

// ── Traffic board: TomTom tile test ─────────────────────────────────────────
$trafficTestResult = null;
if ($authed && $board === 'traffic' && csrf_ok() && ($_POST['action'] ?? '') === 'traffic_test') {
    $trafficTestResult = traffic_test_connection();
    if ($trafficTestResult['ok']) {
        $api = $trafficTestResult['api'] ?? 'unknown';
        $flash = 'TomTom tile test OK — ' . $api . ' API, HTTP ' . (int)$trafficTestResult['http']
            . ', ' . (int)$trafficTestResult['bytes'] . ' bytes PNG.';
    } else {
        $flash = 'TomTom tile test failed — ' . ($trafficTestResult['error'] ?? 'unknown error');
        if (!empty($trafficTestResult['detail'])) {
            $flash .= ': ' . $trafficTestResult['detail'];
        }
        $flashOk = false;
    }
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

// ── Video board: YouTube fetch / yt-dlp upkeep ──────────────────────────────
$videoFetchLog = null;
if ($authed && $board === 'video' && csrf_ok()) {
    $videoDir = video_dir();
    if (!is_dir($videoDir)) {
        @mkdir($videoDir, 0775, true);
    }
    protect_dirs();

    if (($_POST['action'] ?? '') === 'video_fetch') {
        @set_time_limit(0);
        $result = video_fetch_all();
        $videoFetchLog = implode("\n", $result['lines']);
        if ($result['ok']) {
            $flash = 'YouTube downloads finished.';
        } else {
            $flash = 'Download finished with errors — see log below.'; $flashOk = false;
        }
    }

    if (($_POST['action'] ?? '') === 'video_fetch_one') {
        @set_time_limit(0);
        $fetchKey = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_POST['video_key'] ?? ''));
        $result = video_fetch_one($fetchKey);
        $videoFetchLog = implode("\n", $result['lines']);
        if ($result['ok']) {
            $flash = 'Downloaded "' . $fetchKey . '".';
        } else {
            $flash = 'Download failed for "' . $fetchKey . '" — see log below.'; $flashOk = false;
        }
    }

    if (($_POST['action'] ?? '') === 'ytdlp_update') {
        $result = video_ytdlp_update();
        $videoFetchLog = implode("\n", $result['lines']);
        if ($result['ok']) {
            $flash = $result['message'];
        } else {
            $flash = $result['message']; $flashOk = false;
        }
    }

    if (($_POST['action'] ?? '') === 'ytdlp_refresh') {
        video_ytdlp_latest_release(true);
        $flash = 'Checked GitHub for the latest yt-dlp release.';
    }
}

// ── Photo rotator: upload / delete ────────────────────────────────────────────
if ($authed && $board === 'rotator' && csrf_ok()) {
    $photoDir = rotator_photo_dir();
    if (!is_dir($photoDir)) @mkdir($photoDir, 0775, true);
    protect_dirs();

    if (($_POST['action'] ?? '') === 'upload_photo' && isset($_FILES['photo'])) {
        $uploaded = [];
        $errors = [];
        foreach (rotator_normalize_uploads($_FILES['photo']) as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $result = rotator_save_upload($f, $photoDir);
            if ($result['ok']) {
                $uploaded[] = $result['name'];
            } else {
                $errors[] = ($f['name'] ?? 'file') . ': ' . ($result['error'] ?? 'failed');
            }
        }
        if ($uploaded) {
            $flash = 'Uploaded ' . implode(', ', $uploaded) . '.';
            if ($errors) {
                $flash .= ' Some failed: ' . implode('; ', $errors);
            }
        } elseif ($errors) {
            $flash = implode('; ', $errors);
            $flashOk = false;
        } else {
            $flash = 'No files selected.'; $flashOk = false;
        }
    }

    if (($_POST['action'] ?? '') === 'delete_photo') {
        $del = (string)($_POST['file'] ?? '');
        if (!rotator_delete_photo($del, $photoDir)) {
            $flash = 'Could not delete photo.'; $flashOk = false;
        } else {
            $flash = 'Deleted ' . basename($del) . '.';
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
$videoYtdlpStatus = null;
$videoStatuses = [];
if ($authed && $board === 'video') {
    $videoYtdlpStatus = video_ytdlp_status();
    $videoYtdlpSupport = video_ytdlp_support_status();
    foreach (video_registry() as $k => $v) {
        $videoStatuses[] = video_entry_status($k, $v);
    }
}

$navGroups = [
    'Setup'           => ['security', 'rotation', 'ticker'],
    'Weather & home'  => ['index', 'lake', 'photo', 'family', 'traffic'],
    'Monitoring'      => ['homelab', 'signaltrace'],
    'Media'           => ['slides', 'rotator', 'video', 'rss'],
    'Dashboards'      => ['grafana', 'splunk', 'splunkdash'],
];
$slidesBoardKeys = ['SLIDE_DIR', 'DEFAULT_DWELL', 'SHUFFLE', 'FIT', 'TIMEZONE'];

function admin_field(array $f, $val, string $board): void
{
    if ($f['type'] === 'bool'): ?>
              <label class="check"><input type="checkbox" name="<?= h($f['key']) ?>"
                <?= ($val ?? ($f['default'] ?? false)) ? 'checked' : '' ?>> <?= h($f['label']) ?></label>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif;
    elseif ($f['type'] === 'select'): ?>
              <label class="l"><?= h($f['label']) ?></label>
              <select name="<?= h($f['key']) ?>">
                <option value="">(default)</option>
                <?php foreach ($f['options'] as $o): ?>
                  <option value="<?= h($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif;
    elseif ($f['type'] === 'json'): ?>
              <label class="l"><?= h($f['label']) ?></label>
              <textarea name="<?= h($f['key']) ?>" spellcheck="false"><?=
                $val !== null ? h(json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) : '' ?></textarea>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif;
    else: ?>
              <label class="l"><?= h($f['label']) ?></label>
              <input type="<?= $f['type'] === 'password' ? 'password' : ($f['type'] === 'number' ? 'number' : 'text') ?>"
                     <?= $f['type'] === 'number' ? 'step="' . h($f['step'] ?? '1') . '"' : '' ?>
                     name="<?= h($f['key']) ?>"
                     <?php if ($f['type'] !== 'password'): ?>value="<?= h($val !== null ? (string)$val : '') ?>"<?php endif; ?>
                     placeholder="<?= h($f['type'] === 'password' && $val !== null ? '(unchanged)' : '(default)') ?>"
                     autocomplete="off">
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif;
    endif;
}
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
  nav { width:220px; border-right:1px solid var(--line); padding:12px 0 18px; flex:0 0 auto; }
  nav a { display:block; padding:9px 22px; color:var(--snow); font-size:15px; }
  nav a:hover { background:var(--harbor); }
  nav a.active { background:var(--harbor); border-left:3px solid var(--beacon); padding-left:19px; font-weight:600; }
  nav .sep { margin:12px 22px; border-top:1px solid var(--line); }
  nav .nav-label { padding:14px 22px 6px; font-size:11px; letter-spacing:1.2px; text-transform:uppercase;
                   color:var(--mist); opacity:.85; }
  main { flex:1; padding:28px 34px 40px; max-width:920px; }
  h2 { font-family:'Big Shoulders Display'; font-weight:600; font-size:28px; margin-bottom:4px; }
  .sub { color:var(--mist); font-size:14px; margin-bottom:20px; line-height:1.45; }
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
  .rows-scroll { overflow-x:auto; margin-top:4px; max-width:100%; }
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
                padding:18px 20px; margin-bottom:0; }
  .upload-box h3 { font-family:'Big Shoulders Display'; font-size:20px; margin-bottom:10px; }
  .panel { background:var(--harbor); border:1px solid var(--line); border-radius:12px;
           margin-bottom:18px; overflow:hidden; }
  .panel > summary { cursor:pointer; list-style:none; padding:16px 20px; font-family:'Big Shoulders Display';
                     font-size:20px; color:var(--snow); user-select:none; }
  .panel > summary::-webkit-details-marker { display:none; }
  .panel > summary::after { content:'+'; float:right; color:var(--mist); font-size:22px; line-height:1; }
  .panel[open] > summary::after { content:'−'; color:var(--beacon); }
  .panel > summary:hover { background:rgba(255,255,255,.03); }
  .panel-body { padding:0 20px 20px; border-top:1px solid var(--line); }
  .panel-body .upload-box, .panel-body .creator-box { border:0; background:transparent; padding:16px 0 0; border-radius:0; }
  .panel-body .creator-box { padding-top:20px; margin-top:8px; border-top:1px dashed var(--line); }
  .panel-muted > summary { font-size:16px; font-family:'IBM Plex Sans',sans-serif; font-weight:600;
                           letter-spacing:.2px; text-transform:none; padding:12px 16px; }
  .section-title { font-family:'Big Shoulders Display'; font-size:22px; margin:8px 0 14px; }
  .field-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px 20px; }
  .field-grid .field { margin-bottom:0; }
  .deck-list { display:flex; flex-direction:column; gap:14px; margin-top:8px; }
  .slide-card { background:var(--harbor); border:1px solid var(--line); border-radius:12px; padding:16px 18px; }
  .slide-card.is-off { opacity:.55; }
  .slide-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
  .slide-card-head strong { font-size:15px; color:var(--snow); word-break:break-all; }
  .slide-card-head .rowdel { width:32px; height:32px; font-size:16px; }
  .slide-card-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px 14px; }
  .slide-card-grid .span-2 { grid-column:span 2; }
  .slide-card-grid .span-3 { grid-column:1 / -1; }
  .slide-card-grid label.mini { display:block; font-size:11px; letter-spacing:.8px; text-transform:uppercase;
                                color:var(--mist); margin-bottom:5px; }
  .slide-card-grid input, .slide-card-grid select { width:100%; min-width:0; padding:8px 10px; font-size:14px;
    background:#0f1728; border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .slide-card-advanced { margin-top:12px; padding-top:12px; border-top:1px dashed var(--line); }
  .slide-card-advanced summary { cursor:pointer; color:var(--mist); font-size:13px; margin-bottom:10px; }
  .slide-card-flags { display:flex; flex-wrap:wrap; gap:16px; margin-top:4px; }
  .slide-card-flags label { display:flex; align-items:center; gap:8px; font-size:14px; color:var(--snow); }
  @media (max-width: 760px) { .slide-card-grid { grid-template-columns:1fr; } .slide-card-grid .span-2 { grid-column:span 1; } }
  .inline-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:14px; }
  .video-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-top:14px; }
  .video-toolbar .help { margin:0; max-width:420px; }
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
  .creator-box { background:transparent; border:0; border-radius:0;
                  padding:0; margin-bottom:0; }
  .creator-box h3 { font-family:'Big Shoulders Display'; font-size:18px; margin-bottom:10px; }
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
  .video-meta { display:grid; gap:8px; font-size:15px; color:var(--mist); margin:12px 0 16px; }
  .video-meta strong { color:var(--snow); font-weight:600; }
  .video-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top:14px; }
  .pill { display:inline-block; font-size:12px; letter-spacing:.4px; text-transform:uppercase;
          padding:3px 9px; border-radius:999px; border:1px solid var(--line); color:var(--mist); }
  .pill.ok { color:var(--ok); border-color:rgba(57,196,109,.45); }
  .pill.warn { color:var(--beacon); border-color:rgba(255,179,71,.45); }
  .pill.bad { color:var(--bad); border-color:rgba(255,93,93,.45); }
  table.video-status { width:100%; border-collapse:collapse; margin-top:12px; font-size:14px; }
  table.video-status th { text-align:left; font-size:12px; letter-spacing:1px; text-transform:uppercase;
                           color:var(--mist); padding:0 10px 8px 0; font-weight:500; }
  table.video-status td { padding:8px 10px 8px 0; border-top:1px solid var(--line); vertical-align:top; }
  table.video-status td.actions { white-space:nowrap; }
  table.video-status form { margin:0; }
  table.video-status button { font-size:13px; padding:5px 12px; }
  table.video-status code { font-size:13px; color:var(--snow); }
  pre.video-log { background:#0a101b; border:1px solid var(--line); border-radius:10px; padding:14px 16px;
                  font-family:'IBM Plex Mono',monospace; font-size:13px; line-height:1.45; color:var(--mist);
                  overflow:auto; max-height:320px; white-space:pre-wrap; margin-top:14px; }
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
      <p class="help" style="margin-bottom:16px">First-time setup requires the one-time key in
        <code>config/setup.key</code> on the server (not downloadable over HTTP). SSH in and run:
        <code>sudo cat /var/www/html/boards/config/setup.key</code></p>
      <form method="post">
        <input type="hidden" name="action" value="setup">
        <div class="field"><label class="l">Setup key</label>
          <input type="password" name="setup_key" autocomplete="off" required></div>
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
    <?php
    $navSeen = [];
    foreach ($navGroups as $groupLabel => $keys):
      $any = false;
      foreach ($keys as $k) {
          if (isset($schema[$k])) { $any = true; break; }
      }
      if (!$any) continue;
    ?>
      <div class="nav-label"><?= h($groupLabel) ?></div>
      <?php foreach ($keys as $k):
        if (!isset($schema[$k])) continue;
        $navSeen[$k] = true;
      ?>
        <a href="?board=<?= h($k) ?>" class="<?= (!$tools && $k === $board) ? 'active' : '' ?>"><?= h($schema[$k]['title']) ?></a>
      <?php endforeach; ?>
    <?php endforeach;
    foreach ($schema as $k => $b) {
        if (!empty($navSeen[$k])) continue;
        echo '<a href="?board=' . h($k) . '" class="' . ((!$tools && $k === $board) ? 'active' : '') . '">' . h($b['title']) . "</a>\n";
    }
    ?>
    <div class="sep"></div>
    <a href="?board=tools" class="<?= $tools ? 'active' : '' ?>">Tools</a>
  </nav>
  <main>
    <?php if ($flash): ?><div class="flash<?= $flashOk ? '' : ' bad' ?>"><?= h($flash) ?></div><?php endif; ?>

    <?php if ($tools): ?>
      <h2>Tools</h2>
      <div class="sub">Cache, admin password, and raw JSON.</div>
      <h2 style="font-size:22px;margin-top:0">Change admin password</h2>
      <form method="post" style="margin-bottom:28px;max-width:420px">
        <input type="hidden" name="action" value="changepassword">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field"><label class="l">Current password</label>
          <input type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="field"><label class="l">New password (8+ characters)</label>
          <input type="password" name="new_password" autocomplete="new-password" required></div>
        <div class="field"><label class="l">Confirm new password</label>
          <input type="password" name="new_password2" autocomplete="new-password" required></div>
        <div class="actions"><button class="save">Update password</button></div>
        <div class="help" style="margin-top:8px">To fully reset admin (e.g. if you forgot the password), delete
          <code>config/admin.json</code> on the server — a new <code>config/setup.key</code> is created for setup.</div>
      </form>
      <h2 style="font-size:22px">Clear API cache</h2>
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
      <div class="sub">Changes save to <code>config/settings.json</code>.
        <?php if (!empty($b['file'])): ?><a href="<?= h($b['file']) ?>" target="_blank">Preview board ↗</a><?php endif; ?></div>

      <?php if ($board === 'slides'): ?>
      <details class="panel">
        <summary>Add slides</summary>
        <div class="panel-body">
          <div class="upload-box">
            <h3>Upload an image</h3>
            <form method="post" enctype="multipart/form-data" class="upload-row" action="?board=slides">
              <input type="hidden" name="action" value="upload_slide">
              <input type="hidden" name="board" value="slides">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="file" name="slide" accept="image/jpeg,image/png,image/webp" required>
              <button class="secondary" type="submit">Upload</button>
            </form>
            <div class="help" style="margin-top:8px">JPG, PNG, or WebP. New files start on the <strong>Always</strong> schedule.</div>
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
            <h3>Or create a text slide</h3>
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
        </div>
      </details>
      <?php endif; ?>

      <?php if ($board === 'rotator'): ?>
      <details class="panel" open>
        <summary>Upload photos</summary>
        <div class="panel-body">
      <div class="upload-box">
        <h3>Upload photos</h3>
        <form method="post" enctype="multipart/form-data" class="upload-row" action="?board=rotator">
          <input type="hidden" name="action" value="upload_photo">
          <input type="hidden" name="board" value="rotator">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="file" name="photo[]" accept="image/jpeg,image/png" multiple>
          <button class="secondary" type="submit">Upload</button>
        </form>
        <div class="help" style="margin-top:10px">JPG or PNG, up to 25 MB each.</div>
        <?php $photoFiles = rotator_list_photos();
        if ($photoFiles): ?>
        <ul class="filelist">
          <?php foreach ($photoFiles as $pf): ?>
            <li>
              <code><?= h($pf) ?></code>
              <form method="post" action="?board=rotator" onsubmit="return confirm('Delete <?= h($pf) ?>?');">
                <input type="hidden" name="action" value="delete_photo">
                <input type="hidden" name="board" value="rotator">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="file" value="<?= h($pf) ?>">
                <button class="secondary" type="submit">Delete</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
        </div>
      </details>
      <?php endif; ?>

      <?php if ($board === 'traffic'):
        $trafficKeyOk = traffic_api_key() !== null;
        $trafficLastErr = traffic_last_error();
      ?>
      <div class="panel" style="padding:18px 20px;margin-bottom:18px">
        <div class="section-title" style="margin-top:0">TomTom connection</div>
        <div class="video-meta">
          <div>API key in config: <strong><?= $trafficKeyOk ? 'yes' : 'no' ?></strong>
            <?php if (!$trafficKeyOk): ?> — paste key below and click <strong>Save</strong><?php endif; ?></div>
          <?php $trafficMode = traffic_cached_api_mode(); ?>
          <div>Working tile API: <strong><?= $trafficMode ? h($trafficMode) : 'not detected yet' ?></strong>
            (auto tries Orbis, then legacy)</div>
          <?php if ($trafficLastErr): ?>
            <div>Last tile error: <code style="font-size:13px;color:var(--bad)"><?= h($trafficLastErr) ?></code></div>
          <?php endif; ?>
        </div>
        <form method="post" action="?board=traffic" style="margin-bottom:14px">
          <input type="hidden" name="action" value="traffic_test">
          <input type="hidden" name="board" value="traffic">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <button class="secondary" type="submit">Test tile fetch</button>
        </form>
        <div class="help">Tiles are fetched <strong>server-side</strong> via <code>traffic_tiles.php</code>. Enable
          <strong>Traffic Flow API</strong> on your key at
          <a href="https://developer.tomtom.com/user/me/apps" target="_blank" rel="noopener">developer.tomtom.com</a>.
          New keys often require the <strong>Orbis</strong> tile API — leave <strong>Tile API</strong> on Auto (default).
          Leave <strong>domain whitelisting off</strong> for server-side PHP. After changing the key, Save, then Test tile fetch.</div>
      </div>
      <?php endif; ?>

      <?php if ($board === 'video' && $videoYtdlpStatus !== null): ?>
      <div class="panel" style="padding:18px 20px;margin-bottom:18px">
        <div class="section-title" style="margin-top:0">YouTube downloads</div>
        <div class="help">Save video URLs below, then fetch. Files land in <code>videos/</code>.</div>

        <table class="video-status">
          <thead>
            <tr><th>Key</th><th>File</th><th>Length</th><th>Dwell</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($videoStatuses as $st): ?>
              <tr>
                <td><code><?= h($st['key']) ?></code></td>
                <td><?= $st['file'] ? h($st['file']) : '—' ?></td>
                <td><?= $st['duration_label'] ? h($st['duration_label']) : '—' ?></td>
                <td><?= $st['rotation_dwell'] ? h((string)$st['rotation_dwell'] . ' s') : '—' ?></td>
                <td class="actions">
                  <?php if ($st['fetchable']): ?>
                    <form method="post" action="?board=video">
                      <input type="hidden" name="action" value="video_fetch_one">
                      <input type="hidden" name="board" value="video">
                      <input type="hidden" name="video_key" value="<?= h($st['key']) ?>">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                      <button class="secondary" type="submit">Fetch</button>
                    </form>
                  <?php else: ?>
                    <span class="help">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="video-toolbar">
          <form method="post" class="inline-actions" action="?board=video">
            <input type="hidden" name="action" value="video_fetch">
            <input type="hidden" name="board" value="video">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button class="save" type="submit">Download all</button>
          </form>
          <div class="help">Large downloads may take several minutes — keep this tab open.</div>
        </div>

        <details class="panel-muted" style="margin-top:16px;border:1px solid var(--line);border-radius:10px">
          <summary>yt-dlp maintenance</summary>
          <div style="padding:12px 16px 16px">
            <div class="video-meta">
              <div>Installed:
                <?php if ($videoYtdlpStatus['stub'] ?? false): ?>
                  <span class="pill bad">Broken stub</span>
                  <span class="help"> — pip/pipx launcher copied into bin/; click Update yt-dlp</span>
                <?php elseif ($videoYtdlpStatus['installed']): ?>
                  <strong><?= h($videoYtdlpStatus['installed']) ?></strong>
                <?php else: ?>
                  <span class="pill bad">Not found</span>
                <?php endif; ?>
              </div>
              <div>Latest:
                <?php if ($videoYtdlpStatus['latest']): ?>
                  <strong><?= h($videoYtdlpStatus['latest']) ?></strong>
                  <?php if ($videoYtdlpStatus['outdated'] === true): ?>
                    <span class="pill warn">Update available</span>
                  <?php elseif ($videoYtdlpStatus['outdated'] === false): ?>
                    <span class="pill ok">Up to date</span>
                  <?php endif; ?>
                <?php elseif ($videoYtdlpStatus['latest_error']): ?>
                  <span class="help"><?= h($videoYtdlpStatus['latest_error']) ?></span>
                <?php else: ?>
                  <span class="help">Unknown</span>
                <?php endif; ?>
              </div>
              <div>Deno (JS runtime):
                <?php if ($videoYtdlpSupport['deno_ok'] ?? false): ?>
                  <span class="pill ok"><?= h($videoYtdlpSupport['deno_version'] ?? 'installed') ?></span>
                  <?php if (!empty($videoYtdlpSupport['deno_path'])): ?>
                    <code><?= h($videoYtdlpSupport['deno_path']) ?></code>
                  <?php endif; ?>
                <?php elseif ($videoYtdlpSupport['deno'] ?? false): ?>
                  <span class="pill warn">too old</span>
                  <span class="help"> — need <?= h(video_ytdlp_deno_min_version()) ?>+ (have <?= h($videoYtdlpSupport['deno_version'] ?? '?') ?>); upgrade deno</span>
                <?php else: ?>
                  <span class="pill warn">missing</span>
                  — run <code>setup-server.sh</code> or install to <code>/usr/local/bin/deno</code>
                <?php endif; ?>
              </div>
              <div>YouTube cookies:
                <?php if ($videoYtdlpSupport['cookies']): ?>
                  <span class="pill ok">found</span>
                  (<?= number_format((int)($videoYtdlpSupport['cookies_bytes'] ?? 0)) ?> bytes)
                <?php else: ?>
                  <span class="pill bad">missing</span>
                  — export to <code><?= h($videoYtdlpSupport['cookies_path']) ?></code>
                <?php endif; ?>
              </div>
            </div>
            <div class="help" style="margin-top:10px">YouTube often blocks headless servers (“Sign in to confirm you’re not a bot”).
              On <strong>Mac + Chrome</strong>, <code>yt-dlp --cookies-from-browser</code> usually does not export usable auth cookies — use the
              <strong>Get cookies.txt LOCALLY</strong> extension on youtube.com instead. Then on your Mac:
              <code>brew install deno && yt-dlp -U</code>, test with
              <code>yt-dlp --js-runtimes deno --remote-components ejs:github --cookies cookies.txt -F URL</code>
              — you need real video formats (720p/1080p), not just <code>sb0</code> storyboards.
              Upload to <code><?= h($videoYtdlpSupport['cookies_path']) ?></code>, or use a <strong>local file</strong> in the video row.</div>
            <div class="help" style="margin-top:8px">Admin updates download the verified GitHub release to <code>bin/yt-dlp</code>
              (~3&nbsp;MB standalone script for <code>www-data</code>, not the pipx launcher). From SSH as root:
              <code>sudo php video.php fetch</code> after updating.</div>
            <div class="inline-actions">
              <form method="post" action="?board=video">
                <input type="hidden" name="action" value="ytdlp_update">
                <input type="hidden" name="board" value="video">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="secondary" type="submit">Update yt-dlp</button>
              </form>
              <form method="post" action="?board=video">
                <input type="hidden" name="action" value="ytdlp_refresh">
                <input type="hidden" name="board" value="video">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="secondary" type="submit">Check version</button>
              </form>
            </div>
          </div>
        </details>
      </div>
      <?php if ($videoFetchLog): ?>
        <pre class="video-log"><?= h($videoFetchLog) ?></pre>
      <?php endif; ?>
      <?php endif; ?>

      <form method="post" id="boardform" action="?board=<?= h($board) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="board" value="<?= h($board) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <?php
        $scheduleOptions = ['always', 'once', 'range', 'yearly', 'yearly_range', 'monthly', 'weekly'];

        if ($board === 'slides'):
          $slideVal = current_val($rawConf, $board, 'SLIDES');
          $slideRows = is_array($slideVal) ? $slideVal : [];
        ?>
          <div class="section-title">Slide deck</div>
          <div class="help" style="margin-bottom:12px">Each card is one image on the wall. Pick a schedule type — only the fields that apply will show.</div>
          <div class="deck-list" id="slideDeck" data-field="SLIDES">
            <?php foreach ($slideRows as $ri => $row):
              if (!is_array($row)) continue;
              $sched = strtolower((string)($row['schedule'] ?? 'always'));
              if ($sched === '') $sched = 'always';
              $fileLabel = (string)($row['file'] ?? 'New slide');
            ?>
            <div class="slide-card<?= !empty($row['off']) ? ' is-off' : '' ?>" data-slide-card>
              <div class="slide-card-head">
                <strong><?= h($fileLabel !== '' ? $fileLabel : 'New slide') ?></strong>
                <button type="button" class="rowdel" onclick="this.closest('[data-slide-card]').remove()" title="Remove">×</button>
              </div>
              <div class="slide-card-grid">
                <div class="span-2">
                  <label class="mini">Image file</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][file]" value="<?= h((string)($row['file'] ?? '')) ?>" placeholder="filename.png">
                </div>
                <div>
                  <label class="mini">Seconds</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][dwell]" value="<?= h((string)($row['dwell'] ?? '')) ?>" placeholder="12">
                </div>
                <div class="span-3">
                  <label class="mini">Caption</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][caption]" value="<?= h((string)($row['caption'] ?? '')) ?>" placeholder="Optional on-screen caption">
                </div>
                <div>
                  <label class="mini">Schedule</label>
                  <select name="SLIDES[<?= h((string)$ri) ?>][schedule]" data-schedule-select>
                    <?php foreach ($scheduleOptions as $o): ?>
                      <option value="<?= h($o) ?>" <?= $sched === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div data-sched-group="once,range">
                  <label class="mini">Start date</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][date_start]" value="<?= h((string)($row['date_start'] ?? '')) ?>" placeholder="YYYY-MM-DD">
                </div>
                <div data-sched-group="range">
                  <label class="mini">End date</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][date_end]" value="<?= h((string)($row['date_end'] ?? '')) ?>" placeholder="YYYY-MM-DD">
                </div>
                <div data-sched-group="yearly,yearly_range">
                  <label class="mini">MM-DD start</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][month_day]" value="<?= h((string)($row['month_day'] ?? '')) ?>" placeholder="12-24">
                </div>
                <div data-sched-group="yearly_range">
                  <label class="mini">MM-DD end</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][month_day_end]" value="<?= h((string)($row['month_day_end'] ?? '')) ?>" placeholder="01-06">
                </div>
                <div data-sched-group="monthly">
                  <label class="mini">Day of month</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][day_of_month]" value="<?= h((string)($row['day_of_month'] ?? '')) ?>" placeholder="1-31">
                </div>
                <div class="span-2" data-sched-group="weekly">
                  <label class="mini">Weekdays</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][weekdays]" value="<?= h((string)($row['weekdays'] ?? '')) ?>" placeholder="Mon,Wed or Saturday,Sunday">
                </div>
              </div>
              <details class="slide-card-advanced">
                <summary>Time window &amp; flags</summary>
                <div class="slide-card-grid" style="margin-top:10px">
                  <div>
                    <label class="mini">Hour from</label>
                    <input type="text" name="SLIDES[<?= h((string)$ri) ?>][hour_from]" value="<?= h((string)($row['hour_from'] ?? '')) ?>" placeholder="0-23">
                  </div>
                  <div>
                    <label class="mini">Hour to</label>
                    <input type="text" name="SLIDES[<?= h((string)$ri) ?>][hour_to]" value="<?= h((string)($row['hour_to'] ?? '')) ?>" placeholder="0-23">
                  </div>
                </div>
                <div class="slide-card-flags">
                  <label><input type="checkbox" name="SLIDES[<?= h((string)$ri) ?>][priority]" <?= !empty($row['priority']) ? 'checked' : '' ?>> Priority override</label>
                  <label><input type="checkbox" name="SLIDES[<?= h((string)$ri) ?>][off]" <?= !empty($row['off']) ? 'checked' : '' ?>> Disabled</label>
                </div>
              </details>
            </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="addrow" style="margin-top:12px" onclick="addSlideCard()">+ Add slide</button>

          <details class="panel panel-muted" style="margin-top:22px">
            <summary>Board settings</summary>
            <div class="panel-body">
              <div class="field-grid">
                <?php foreach ($b['fields'] as $f):
                  if ($f['key'] === 'SLIDES' || !in_array($f['key'], $slidesBoardKeys, true)) continue;
                  $val = current_val($rawConf, $board, $f['key']); ?>
                  <div class="field"><?php admin_field($f, $val, $board); ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

        <?php else: foreach ($b['fields'] as $f):
          $val = current_val($rawConf, $board, $f['key']); ?>
          <div class="field">
            <?php if ($f['type'] === 'rows'):
              $cols = $f['columns'];
              $rows = [];
              if (is_array($val)) {
                  if (!empty($f['keyed'])) {
                      foreach ($val as $rk => $rv) {
                          if (!empty($f['scalar'])) {
                              $rows[] = ['_key' => $rk, '_value' => $rv];
                          } else {
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
              <div class="rows-scroll">
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
              </div>
              <button type="button" class="addrow" onclick="addRow(this)">+ Add row</button>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php else:
              admin_field($f, $val, $board);
            endif; ?>
          </div>
        <?php endforeach; endif; ?>

        <div class="actions">
          <button class="save">Save</button>
          <label class="check"><input type="checkbox" name="clear_cache"> Clear cache after save</label>
        </div>
      </form>
    <?php endif; ?>
  </main>
</div>

<script>
function addRow(btn) {
  const wrap = btn.previousElementSibling;
  const table = wrap && wrap.classList && wrap.classList.contains('rows-scroll')
    ? wrap.querySelector('table.rows') : wrap;
  if (!table || !table.dataset.field) return;
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

const SLIDE_SCHEDULES = <?= json_encode(['always', 'once', 'range', 'yearly', 'yearly_range', 'monthly', 'weekly']) ?>;

function syncSlideCard(card) {
  const sel = card.querySelector('[data-schedule-select]');
  if (!sel) return;
  const type = sel.value || 'always';
  card.querySelectorAll('[data-sched-group]').forEach(function (el) {
    const groups = (el.getAttribute('data-sched-group') || '').split(',');
    el.hidden = !groups.includes(type);
  });
}

function bindSlideCard(card) {
  const sel = card.querySelector('[data-schedule-select]');
  if (sel && !sel.dataset.bound) {
    sel.dataset.bound = '1';
    sel.addEventListener('change', function () { syncSlideCard(card); });
  }
  syncSlideCard(card);
  const fileInp = card.querySelector('input[name*="[file]"]');
  const head = card.querySelector('.slide-card-head strong');
  if (fileInp && head) {
    fileInp.addEventListener('input', function () {
      head.textContent = fileInp.value.trim() || 'New slide';
    });
  }
  const off = card.querySelector('input[name*="[off]"]');
  if (off && !off.dataset.bound) {
    off.dataset.bound = '1';
    off.addEventListener('change', function () {
      card.classList.toggle('is-off', off.checked);
    });
  }
}

function initSlideDeck() {
  const deck = document.getElementById('slideDeck');
  if (!deck) return;
  deck.querySelectorAll('[data-slide-card]').forEach(bindSlideCard);
}

function addSlideCard() {
  const deck = document.getElementById('slideDeck');
  if (!deck) return;
  const idx = 'n' + (Date.now() % 1e7);
  const proto = deck.querySelector('[data-slide-card]');
  let card;
  if (proto) {
    card = proto.cloneNode(true);
    card.classList.remove('is-off');
    card.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
    card.querySelectorAll('input[type="checkbox"]').forEach(function (i) { i.checked = false; });
    const sel = card.querySelector('[data-schedule-select]');
    if (sel) { sel.value = 'always'; sel.removeAttribute('data-bound'); }
    card.querySelectorAll('[data-bound]').forEach(function (el) { el.removeAttribute('data-bound'); });
  } else {
    card = document.createElement('div');
    card.className = 'slide-card';
    card.setAttribute('data-slide-card', '');
    card.innerHTML =
      '<div class="slide-card-head"><strong>New slide</strong><button type="button" class="rowdel" onclick="this.closest(\'[data-slide-card]\').remove()" title="Remove">×</button></div>' +
      '<div class="slide-card-grid">' +
      '<div class="span-2"><label class="mini">Image file</label><input type="text" name="SLIDES[' + idx + '][file]" placeholder="filename.png"></div>' +
      '<div><label class="mini">Seconds</label><input type="text" name="SLIDES[' + idx + '][dwell]" placeholder="12"></div>' +
      '<div class="span-3"><label class="mini">Caption</label><input type="text" name="SLIDES[' + idx + '][caption]" placeholder="Optional on-screen caption"></div>' +
      '<div><label class="mini">Schedule</label><select name="SLIDES[' + idx + '][schedule]" data-schedule-select>' +
      SLIDE_SCHEDULES.map(function (s) { return '<option value="' + s + '">' + s + '</option>'; }).join('') +
      '</select></div></div>';
  }
  if (proto) {
    card.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/SLIDES\[[^\]]+\]/, 'SLIDES[' + idx + ']');
    });
  }
  deck.appendChild(card);
  bindSlideCard(card);
}

document.addEventListener('DOMContentLoaded', initSlideDeck);
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
