<?php
/**
 * SIGNAGE ADMIN — web frontend for every board's configuration.
 * Edits config/settings.json; the board PHP files are never modified, so the
 * web server only needs write access to config/, cache/, videos/, slides/, photos/, and bin/.
 *
 * First visit: create a super admin using the one-time key in config/setup.key
 * (on the server via SSH — not downloadable over HTTP). Accounts live in
 * config/users.json (local auth today; SSO-ready via auth_provider per user).
 * Change your password under Account; manage users under Users (super only).
 * Tools (raw JSON, cache) is super-admin only.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/family_lib.php';
require_once __DIR__ . '/slides_lib.php';
require_once __DIR__ . '/rotator_lib.php';
require_once __DIR__ . '/video_lib.php';
require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/presence_lib.php';
require_once __DIR__ . '/traffic_lib.php';
require_once __DIR__ . '/splunk_lib.php';
require_once __DIR__ . '/web_lib.php';
require_once __DIR__ . '/users_lib.php';

slide_background_ensure_assets();
slide_background_ensure_photos();

const ADMIN_FILE = __DIR__ . '/config/admin.json'; // legacy; migrated to users.json

signage_admin_security_headers();
signage_session_start();
signage_setup_key_ready();
users_migrate_from_legacy();

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
$needSetup = users_need_setup();
$flash   = null;
$flashOk = true;

// ── Auth actions ─────────────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'setup' && $needSetup) {
    $username = users_normalize_username((string)($_POST['username'] ?? 'admin'));
    $pw = (string)($_POST['password'] ?? '');
    $setupKey = (string)($_POST['setup_key'] ?? '');
    if ($username === '') {
        $flash = 'Choose a username.'; $flashOk = false;
    } elseif (!signage_setup_key_valid($setupKey)) {
        $flash = 'Invalid setup key — read config/setup.key on the server (SSH).'; $flashOk = false;
    } elseif (strlen($pw) < 8) {
        $flash = 'Password must be at least 8 characters.'; $flashOk = false;
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $flash = 'Passwords do not match.'; $flashOk = false;
    } else {
        if (!is_dir(__DIR__ . '/config')) @mkdir(__DIR__ . '/config', 0775, true);
        protect_dirs();
        if (users_create_super($username, $pw)) {
            $user = admin_authenticate_local($username, $pw);
            if ($user !== null) {
                admin_login_user($user);
                signage_setup_key_consume();
                signage_login_succeeded();
                $needSetup = false;
                $flash = 'Super admin account created. Welcome!';
            } else {
                $flash = 'Account created but login failed — try signing in.'; $flashOk = false;
            }
        } else {
            $flash = 'Could not create admin account.'; $flashOk = false;
        }
    }
}
if (($_POST['action'] ?? '') === 'login' && !$needSetup) {
    $gate = signage_login_allowed();
    if (!$gate['ok']) {
        $flash = 'Too many failed attempts — try again in ' . (int)ceil(($gate['wait'] ?? 900) / 60) . ' minutes.';
        $flashOk = false;
    } else {
        $user = admin_authenticate_local(
            (string)($_POST['username'] ?? ''),
            (string)($_POST['password'] ?? '')
        );
        if ($user !== null) {
            admin_login_user($user);
            signage_login_succeeded();
        } else {
            signage_login_failed();
            usleep(400000);
            $flash = 'Invalid username or password.'; $flashOk = false;
        }
    }
}
if (($_GET['logout'] ?? '') === '1') {
    admin_logout_user();
    header('Location: admin.php');
    exit;
}
$authed = admin_is_authenticated();
if ($authed && !signage_admin_idle_check()) {
    $authed = false;
    $flash = 'Session expired from inactivity — log in again.';
    $flashOk = false;
}

// ── Save handler ─────────────────────────────────────────────────────────────
$board = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['board'] ?? $_POST['board'] ?? ''));
$virtualBoards = ['tools', 'users', 'account'];
if ($board === '') {
    $board = $authed ? admin_default_board() : (string)array_key_first($schema);
} elseif (!isset($schema[$board]) && !in_array($board, $virtualBoards, true)) {
    $board = $authed ? admin_default_board() : (string)array_key_first($schema);
}
if ($authed) {
    admin_enforce_board_access($board, $flash, $flashOk);
}
$tools = ($board === 'tools');
$usersBoard = ($board === 'users');
$accountBoard = ($board === 'account');

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $contentLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLen > 0 && $_POST === [] && $_FILES === []) {
        $flash = 'Request body too large for PHP post_max_size (' . ini_get('post_max_size')
            . '). Slides allow up to ' . slide_upload_max_label()
            . ' — raise post_max_size and upload_max_filesize on the server.';
        $flashOk = false;
    }
}

if ($authed && $board === 'slides' && isset($_GET['deleted'])) {
    $deleted = slide_safe_filename((string)$_GET['deleted']);
    if ($deleted !== null) {
        $flash = 'Deleted ' . $deleted . '. Rotation updated on all displays.';
    }
}

if ($authed && $board === 'slides' && isset($_GET['replaced'])) {
    $replaced = slide_safe_filename((string)$_GET['replaced']);
    if ($replaced !== null) {
        $flash = 'Replaced ' . $replaced . '. Schedule and rotation URLs are unchanged.';
        $flashOk = true;
    }
}

if ($authed && $board === 'splunk' && admin_can_board('splunk') && ($_POST['action'] ?? '') === 'splunk_test_panel' && csrf_ok()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(splunk_test_panel([
        'title' => $_POST['title'] ?? '',
        'type' => $_POST['type'] ?? 'single',
        'spl' => $_POST['spl'] ?? '',
        'field' => $_POST['field'] ?? '',
        'label' => $_POST['label'] ?? '',
        'value' => $_POST['value'] ?? '',
        'unit' => $_POST['unit'] ?? '',
        'earliest' => $_POST['earliest'] ?? '',
        'latest' => $_POST['latest'] ?? '',
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($authed && $board === 'rotation' && ($_GET['action'] ?? '') === 'presence') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(admin_filter_presence_dashboard(signage_presence_dashboard()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($authed && ($_POST['action'] ?? '') === 'save' && csrf_ok()) {
    if (!admin_can_board($board)) {
        $flash = 'You do not have access to that section.';
        $flashOk = false;
    } else {
    $rotationSuperFieldKeys = ['TIMEZONE', 'FADE_MS', 'SETTLE_MS', 'HANG_MS'];
    $conf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    $errors = [];
    foreach ($schema[$board]['fields'] as $f) {
        if (!admin_can_board_settings($board) && $f['type'] !== 'rows') {
            continue;
        }
        if ($board === 'rotation' && !admin_is_super()) {
            if ($f['key'] === 'SCREENS' || in_array($f['key'], $rotationSuperFieldKeys, true)) {
                continue;
            }
            if (strpos($f['key'], 'PAGES_') === 0) {
                $screenKey = substr($f['key'], 6);
                if (!admin_can_screen($screenKey)) {
                    continue;
                }
            }
        }
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
                $existingRows = $conf[$cfgKey] ?? [];
                if (!is_array($existingRows)) $existingRows = [];
                $keyed  = !empty($f['keyed']);
                $scalar = !empty($f['scalar']);
                $outV = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $row = admin_normalize_form_row($row);
                    if ($keyed) {
                        $k = $row['_key'] ?? '';
                        if ($k === '') continue;
                        if ($scalar) {
                            if (($row['_value'] ?? '') === '') continue;
                            $prev = is_array($existingRows[$k] ?? null) ? $existingRows[$k] : null;
                            $outV[$k] = admin_finalize_entry(['value' => $row['_value']], $prev, $row);
                        } else {
                            $obj = [];
                            foreach ($f['columns'] as $col) {
                                if ($col['key'] === '_key') continue;
                                if (($col['type'] ?? '') === 'check') {
                                    if (($row[$col['key']] ?? '') !== '') $obj[$col['key']] = true;
                                    continue;
                                }
                                if (($col['type'] ?? '') === 'password') {
                                    $v = $row[$col['key']] ?? '';
                                    if ($v !== '') {
                                        $obj[$col['key']] = $v;
                                    } else {
                                        foreach ($existingRows as $er) {
                                            if (!is_array($er)) continue;
                                            $match = ($row['url'] ?? '') !== '' && ($row['url'] ?? '') === ($er['url'] ?? '');
                                            if (!$match && ($row['name'] ?? '') !== '') {
                                                $match = ($row['name'] ?? '') === ($er['name'] ?? '');
                                            }
                                            if ($match && ($er[$col['key']] ?? '') !== '') {
                                                $obj[$col['key']] = $er[$col['key']];
                                                break;
                                            }
                                        }
                                    }
                                    continue;
                                }
                                $v = $row[$col['key']] ?? '';
                                if ($v === '') continue;
                                $obj[$col['key']] = ($col['cast'] ?? '') === 'int' ? (int)$v : $v;
                            }
                            if ($obj !== []) {
                                $prev = is_array($existingRows[$k] ?? null) ? $existingRows[$k] : null;
                                $outV[$k] = admin_finalize_entry($obj, $prev, $row);
                            }
                        }
                    } else {
                        $obj = [];
                        $any = false;
                        foreach ($f['columns'] as $col) {
                            if (($col['type'] ?? '') === 'check') {
                                if (($row[$col['key']] ?? '') !== '') $obj[$col['key']] = true;
                                continue;          // a lone checkbox doesn't make a row real
                            }
                            if (($col['type'] ?? '') === 'password') {
                                $v = $row[$col['key']] ?? '';
                                if ($v !== '') {
                                    $obj[$col['key']] = $v;
                                    $any = true;
                                } else {
                                    foreach ($existingRows as $er) {
                                        if (!is_array($er)) continue;
                                        $match = ($row['url'] ?? '') !== '' && ($row['url'] ?? '') === ($er['url'] ?? '');
                                        if (!$match && ($row['name'] ?? '') !== '') {
                                            $match = ($row['name'] ?? '') === ($er['name'] ?? '');
                                        }
                                        if (!$match && ($row['key'] ?? '') !== '') {
                                            $match = ($row['key'] ?? '') === ($er['key'] ?? $er['name'] ?? '');
                                        }
                                        if ($match && ($er[$col['key']] ?? '') !== '') {
                                            $obj[$col['key']] = $er[$col['key']];
                                            break;
                                        }
                                    }
                                }
                                continue;
                            }
                            $v = $row[$col['key']] ?? '';
                            if ($v === '') continue;          // omit blank cells (a 0 is not "unset")
                            $any = true;
                            $obj[$col['key']] = ($col['cast'] ?? '') === 'int' ? (int)$v : $v;
                        }
                        if ($any) {
                            $prev = admin_find_owned_list_entry($existingRows, $obj);
                            $outV[] = admin_finalize_entry($obj, $prev, $row);
                        }
                    }
                }
                if ($keyed && $scalar) {
                    $outV = admin_merge_owned_scalar_map($existingRows, $outV);
                } elseif ($keyed) {
                    $outV = admin_merge_owned_map($existingRows, $outV);
                } elseif ($outV !== []) {
                    $outV = admin_merge_owned_list($existingRows, $outV);
                }
                if ($outV === []) unset($conf[$cfgKey]); else $conf[$cfgKey] = $outV;
                break;
            default:    // text, select, textarea
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw === '') unset($conf[$cfgKey]); else $conf[$cfgKey] = $raw;
        }
    }
    if ($board === 'slides') {
        require_once __DIR__ . '/slides_lib.php';
        $allScreenKeys = array_keys(rotation_screens());
        sort($allScreenKeys);
        $existingSlides = is_array($conf['slides.SLIDES'] ?? null) ? $conf['slides.SLIDES'] : [];
        $outV = [];
        foreach ($_POST['SLIDES'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = admin_normalize_form_row($row);
            $obj = [];
            foreach (['file', 'caption', 'schedule', 'date_start', 'date_end', 'month_day', 'month_day_end', 'weekdays'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') {
                    $obj[$k] = $v;
                }
            }
            foreach (['dwell', 'day_of_month', 'hour_from', 'hour_to'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') {
                    $obj[$k] = (int)$v;
                }
            }
            if (!empty($row['priority'])) {
                $obj['priority'] = true;
            }
            if (!empty($row['off'])) {
                $obj['off'] = true;
            }
            $screens = [];
            if (isset($row['screens']) && is_array($row['screens'])) {
                foreach ($row['screens'] as $scr) {
                    $sk = rotation_normalize_screen_key((string)$scr);
                    if ($sk !== '') {
                        $screens[$sk] = true;
                    }
                }
            }
            $screens = array_keys($screens);
            sort($screens);
            if (!isset($row['screens'])) {
                if (!empty($row['_screens_form'])) {
                    $obj['screens'] = [];
                }
            } elseif ($screens !== $allScreenKeys) {
                $obj['screens'] = $screens;
            }
            if (($obj['file'] ?? '') !== '' || ($obj['caption'] ?? '') !== '' || ($obj['schedule'] ?? '') !== '') {
                $prev = admin_find_owned_list_entry($existingSlides, $obj);
                $outV[] = admin_finalize_entry($obj, $prev, $row);
            }
        }
        $mergedSlides = admin_merge_owned_list($existingSlides, $outV);
        if ($mergedSlides === []) {
            unset($conf['slides.SLIDES']);
        } else {
            $conf['slides.SLIDES'] = $mergedSlides;
        }
    }
    if ($board === 'rotator') {
        $allScreenKeys = array_keys(rotation_screens());
        sort($allScreenKeys);
        $existingPhotos = is_array($conf['rotator.PHOTOS'] ?? null) ? $conf['rotator.PHOTOS'] : [];
        $outV = [];
        foreach ($_POST['PHOTOS'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = admin_normalize_form_row($row);
            $obj = [];
            foreach (['file', 'caption', 'group'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v !== '') {
                    $obj[$k] = $k === 'group' ? rotator_normalize_group($v) : $v;
                }
            }
            $v = trim((string)($row['dwell'] ?? ''));
            if ($v !== '') {
                $obj['dwell'] = (int)$v;
            }
            if (!empty($row['off'])) {
                $obj['off'] = true;
            }
            $screens = [];
            if (isset($row['screens']) && is_array($row['screens'])) {
                foreach ($row['screens'] as $scr) {
                    $sk = rotation_normalize_screen_key((string)$scr);
                    if ($sk !== '') {
                        $screens[$sk] = true;
                    }
                }
            }
            $screens = array_keys($screens);
            sort($screens);
            if (!isset($row['screens'])) {
                if (!empty($row['_screens_form'])) {
                    $obj['screens'] = [];
                }
            } elseif ($screens !== $allScreenKeys) {
                $obj['screens'] = $screens;
            }
            if (($obj['file'] ?? '') !== '' || ($obj['caption'] ?? '') !== '') {
                $prev = admin_find_owned_list_entry($existingPhotos, $obj);
                $outV[] = admin_finalize_entry($obj, $prev, $row);
            }
        }
        $mergedPhotos = admin_merge_owned_list($existingPhotos, $outV);
        if ($mergedPhotos === []) {
            unset($conf['rotator.PHOTOS']);
        } else {
            $conf['rotator.PHOTOS'] = $mergedPhotos;
        }
    }
    if ($board === 'splunk') {
        if (!admin_is_super() && !empty($_POST['splunk_use_json'])) {
            $errors[] = 'Advanced JSON import is restricted to super admins.';
        } elseif (!empty($_POST['splunk_use_json'])) {
            $parsed = splunk_pages_from_json_string((string)($_POST['PAGES_JSON'] ?? ''));
            if ($parsed === null) {
                $errors[] = 'Pages JSON: invalid — not saved.';
            } elseif ($parsed === []) {
                unset($conf['splunk.PAGES'], $conf['splunk.PANELS']);
            } else {
                $conf['splunk.PAGES'] = $parsed;
                unset($conf['splunk.PANELS']);
            }
        } else {
            $existingPages = is_array($conf['splunk.PAGES'] ?? null) ? $conf['splunk.PAGES'] : [];
            $outV = splunk_pages_from_post($_POST['PAGES'] ?? []);
            $finalized = [];
            foreach ($_POST['PAGES'] ?? [] as $prow) {
                if (!is_array($prow)) {
                    continue;
                }
                $prow = admin_normalize_form_row($prow);
                $key = splunk_normalize_page_key((string)($prow['_key'] ?? ''));
                if ($key === '' || !isset($outV[$key])) {
                    continue;
                }
                $prev = is_array($existingPages[$key] ?? null) ? $existingPages[$key] : null;
                $finalized[$key] = admin_finalize_entry($outV[$key], $prev, $prow);
            }
            $mergedPages = admin_merge_owned_map($existingPages, $finalized);
            if ($mergedPages === []) {
                unset($conf['splunk.PAGES'], $conf['splunk.PANELS']);
            } else {
                $conf['splunk.PAGES'] = $mergedPages;
                unset($conf['splunk.PANELS']);
            }
        }
    }
    if ($board === 'rotation') {
        if (admin_is_super()) {
        $screensOut = [];
        foreach ($_POST['SCREENS'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = rotation_normalize_screen_key((string)($row['_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            $entry = ['name' => $name !== '' ? $name : ($key === 'main' ? 'Main Display' : $key)];
            if (isset($row['shuffle'])) {
                $entry['shuffle'] = true;
            }
            $entry['show_ticker'] = isset($row['show_ticker']);
            $entry['show_clock'] = isset($row['show_clock']);
            $entry['show_debug'] = isset($row['show_debug']);
            if (isset($row['weighted'])) {
                $entry['weighted'] = true;
            }
            $off = trim((string)($row['cec_off'] ?? ''));
            $on = trim((string)($row['cec_on'] ?? ''));
            $offH = $off !== '' ? max(0, min(23, (int)$off)) : 23;
            $onH = $on !== '' ? max(0, min(23, (int)$on)) : 6;
            if (isset($row['schedule_enabled'])) {
                $entry['schedule'] = [
                    'enabled' => true,
                    'off' => $offH,
                    'on' => $onH,
                ];
            }
            if (isset($row['cec_enabled'])) {
                $entry['cec'] = [
                    'enabled' => true,
                    'off' => $offH,
                    'on' => $onH,
                    'device' => 0,
                ];
            }
            $screensOut[$key] = $entry;
        }
        if ($screensOut === []) {
            unset($conf['rotation.SCREENS']);
        } else {
            if (!isset($screensOut['main'])) {
                $screensOut = ['main' => ['name' => 'Main Display']] + $screensOut;
            }
            $conf['rotation.SCREENS'] = $screensOut;
        }
        }

        $schemaPageKeys = [];
        foreach ($schema[$board]['fields'] as $sf) {
            if (strpos($sf['key'], 'PAGES_') === 0) {
                $schemaPageKeys[$sf['key']] = true;
            }
        }
        foreach ($_POST as $name => $rows) {
            if (!is_string($name) || !preg_match('/^PAGES_[a-z0-9_-]+$/i', $name)) {
                continue;
            }
            if (!empty($schemaPageKeys[$name]) || !is_array($rows)) {
                continue;
            }
            $screenKey = substr($name, 6);
            if (!admin_can_screen($screenKey)) {
                continue;
            }
            $cfgKey = "$board.$name";
            $outV = rotation_parse_pages_rows($rows);
            if ($outV === []) {
                unset($conf[$cfgKey]);
            } else {
                $conf[$cfgKey] = $outV;
            }
        }
    }
    $conf = array_filter($conf, fn($v) => $v !== null);
    if ($errors) {
        $flash = implode(' ', $errors); $flashOk = false;
    } else {
        protect_dirs();
        if (cfg_write($conf)) {
            if (isset($_POST['clear_cache']) && admin_can_tools()) {
                foreach (glob(__DIR__ . '/cache/*.{dat,json}', GLOB_BRACE) ?: [] as $cf) @unlink($cf);
            }
            cfg_reload();
            $extra = '';
            if ($board === 'video') {
                $screens = admin_filter_deploy_screens(admin_deploy_screens_from_post($_POST));
                if ($screens !== []) {
                    $parts = [];
                    foreach ($screens as $screen) {
                        $sync = video_sync_rotation($screen);
                        if (video_rotation_pages_write($sync['screen'], $sync['pages'])) {
                            $n = count($sync['added']);
                            $label = rotation_screen_display_name($screen, rotation_screens());
                            if ($n > 0) {
                                $parts[] = $label . ': added ' . $n . ' video' . ($n === 1 ? '' : 's');
                            } elseif (count($sync['updated']) > 0) {
                                $parts[] = $label . ': updated dwell times';
                            }
                        } else {
                            $parts[] = rotation_screen_display_name($screen, rotation_screens()) . ': could not update';
                            $flashOk = false;
                        }
                    }
                    if ($parts !== []) {
                        cfg_reload();
                        $extra = ' ' . implode('; ', $parts) . '.';
                    }
                }
            } elseif ($board === 'slides') {
                $deployScreens = admin_deploy_screens_from_post($_POST);
                if ($deployScreens === [] && isset($_POST['sync_rotation_slides'])) {
                    $deployScreens = admin_default_deploy_screens();
                }
                if ($deployScreens !== []) {
                    $result = slides_deploy_to_screens(admin_filter_deploy_screens($deployScreens));
                    cfg_reload();
                    $extra = slides_deploy_flash_message($result);
                }
            } elseif ($board === 'rotator') {
                $deployScreens = admin_deploy_screens_from_post($_POST);
                if ($deployScreens !== []) {
                    $result = rotator_deploy_to_screens(admin_filter_deploy_screens($deployScreens));
                    cfg_reload();
                    $extra = rotator_deploy_flash_message($result);
                }
            } elseif ($board === 'rss') {
                $screens = admin_filter_deploy_screens(admin_deploy_screens_from_post($_POST));
                if ($screens !== []) {
                    $parts = [];
                    foreach ($screens as $screen) {
                        $sync = rotation_sync_rss($screen);
                        if (rotation_pages_write($sync['screen'], $sync['pages'])) {
                            $n = count($sync['added']);
                            $label = rotation_screen_display_name($screen, rotation_screens());
                            if ($n > 0) {
                                $parts[] = $label . ': added ' . $n . ' feed' . ($n === 1 ? '' : 's');
                            } elseif (count($sync['updated']) > 0) {
                                $parts[] = $label . ': updated dwell times';
                            }
                        } else {
                            $parts[] = rotation_screen_display_name($screen, rotation_screens()) . ': could not update';
                            $flashOk = false;
                        }
                    }
                    if ($parts !== []) {
                        cfg_reload();
                        $extra = ' ' . implode('; ', $parts) . '.';
                    }
                }
            }
            if (($flashOk ?? true) && (
                $board === 'rotation'
                || ($board === 'video' && admin_deploy_screens_from_post($_POST) !== [])
                || ($board === 'slides' && (admin_deploy_screens_from_post($_POST) !== [] || isset($_POST['sync_rotation_slides'])))
                || ($board === 'rotator' && admin_deploy_screens_from_post($_POST) !== [])
                || ($board === 'rss' && admin_deploy_screens_from_post($_POST) !== [])
            )) {
                $extra .= ($extra !== '' ? ' ' : '') . 'Wall displays refresh within 30 seconds.';
            }
            $flash = $schema[$board]['title'] . ' saved.'
                . (isset($_POST['clear_cache']) && admin_can_tools() ? ' Cache cleared.' : '') . $extra;
            $schema = admin_schema();   // pick up structural changes (e.g. new rotation screens)
        } else {
            $flash = 'Could not write config/settings.json — check directory permissions.'; $flashOk = false;
        }
    }
    }
}

if ($authed && admin_can_manage_users() && ($_POST['action'] ?? '') === 'save_users' && csrf_ok()) {
    $result = users_save_from_post($_POST['USERS'] ?? []);
    if ($result['ok']) {
        $flash = 'Saved ' . (int)($result['count'] ?? 0) . ' user account' . ((int)($result['count'] ?? 0) === 1 ? '' : 's') . '.';
    } else {
        $flash = (string)($result['error'] ?? 'Could not save users.');
        $flashOk = false;
    }
}

if ($authed && ($_POST['action'] ?? '') === 'clearcache' && csrf_ok()) {
    if (!admin_can_tools()) {
        $flash = 'Only super admins can clear the cache.'; $flashOk = false;
    } else {
    $n = 0;
    foreach (glob(__DIR__ . '/cache/*.{dat,json}', GLOB_BRACE) ?: [] as $cf) { @unlink($cf); $n++; }
    $flash = "Cleared $n cached file" . ($n === 1 ? '' : 's') . '.';
    }
}

if ($authed && ($_POST['action'] ?? '') === 'changepassword' && csrf_ok()) {
    $cur = (string)($_POST['current_password'] ?? '');
    $pw = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password2'] ?? '');
    if ($pw !== $pw2) {
        $flash = 'New passwords do not match.'; $flashOk = false;
    } else {
        $result = admin_change_own_password($cur, $pw);
        if ($result['ok']) {
            $flash = 'Password updated.';
        } else {
            $flash = (string)($result['error'] ?? 'Could not update password.');
            $flashOk = false;
        }
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
if ($authed && $board === 'slides') {
    $slideDir = slides_dir();
    if (!is_dir($slideDir)) @mkdir($slideDir, 0775, true);
    protect_dirs();

    $slideAction = (string)($_POST['action'] ?? '');

    if ($slideAction === 'delete_slide') {
        if (!csrf_ok()) {
            $flash = 'Session expired — refresh the page and try again.';
            $flashOk = false;
        } else {
            $deck = is_array($rawConf['slides.SLIDES'] ?? null) ? $rawConf['slides.SLIDES'] : [];
            if (!admin_can_delete_deck_file($deck, (string)($_POST['file'] ?? ''), 'slide_safe_filename', static fn($e) => slide_safe_filename((string)($e['file'] ?? '')))) {
                $flash = 'You do not have permission to delete that slide.';
                $flashOk = false;
            } else {
                $result = slide_delete_file((string)($_POST['file'] ?? ''));
                if (!empty($result['ok'])) {
                    header('Location: ?board=slides&deleted=' . rawurlencode((string)$result['file']));
                    exit;
                }
                $flash = (string)($result['error'] ?? 'Could not delete slide.');
                $flashOk = false;
            }
        }
    } elseif ($slideAction === 'replace_slide') {
        if (!csrf_ok()) {
            $flash = 'Session expired — refresh the page and try again.';
            $flashOk = false;
        } elseif (!isset($_FILES['slide'])) {
            $flash = 'Upload did not arrive — the file may exceed PHP post_max_size ('
                . ini_get('post_max_size') . '). Slides allow up to ' . slide_upload_max_label()
                . '; raise post_max_size and upload_max_filesize on the server.';
            $flashOk = false;
        } else {
            $result = slide_replace_upload((string)($_POST['file'] ?? ''), $_FILES['slide']);
            if (!empty($result['ok'])) {
                header('Location: ?board=slides&replaced=' . rawurlencode((string)$result['file']));
                exit;
            }
            $flash = (string)($result['error'] ?? 'Could not replace slide.');
            $flashOk = false;
        }
    } elseif ($slideAction === 'create_slide') {
        if (!csrf_ok()) {
            $flash = 'Session expired — refresh the page and try again.';
            $flashOk = false;
        } else {
            $title = trim((string)($_POST['creator_title'] ?? ''));
            $slug  = trim((string)($_POST['creator_name'] ?? ''));
            if ($slug === '' && $title !== '') {
                $slug = $title;
            }
            if ($slug === '') {
                $slug = 'slide';
            }
            $png = slide_creator_read_png(
                (string)($_POST['creator_png'] ?? ''),
                isset($_FILES['slide_image']) && is_array($_FILES['slide_image']) ? $_FILES['slide_image'] : null
            );
            if ($png === null) {
                $flash = 'Could not read the rendered slide — try again. If it keeps failing, refresh the page first.';
                $flashOk = false;
            } elseif (strlen($png) > 8 * 1024 * 1024) {
                $flash = 'Rendered slide must be under 8 MB.';
                $flashOk = false;
            } else {
                $name = slide_unique_filename($slug, 'png', $slideDir);
                if (@file_put_contents($slideDir . '/' . $name, $png) === false) {
                    $flash = 'Could not write to ' . slides_dir() . ' — check permissions.';
                    $flashOk = false;
                } else {
                    $caption = $title !== '' ? $title : trim((string)($_POST['creator_subtitle'] ?? ''));
                    $extra = ['schedule' => 'always'];
                    if ($caption !== '') {
                        $extra['caption'] = $caption;
                    }
                    if (slide_append_to_deck($name, $extra)) {
                        $deploy = admin_default_deploy_screens();
                        if ($deploy !== []) {
                            slides_deploy_to_screens($deploy);
                        }
                        cfg_reload();
                        slide_creator_finish($name);
                    } else {
                        $flash = 'Slide saved but could not update settings.json.';
                        $flashOk = false;
                    }
                }
            }
        }
    } elseif (csrf_ok()) {
    if (($_POST['action'] ?? '') === 'deploy_slides') {
        $screens = admin_filter_deploy_screens(array_values(array_filter(array_map(
            'rotation_normalize_screen_key',
            (array)($_POST['deploy_screens'] ?? [])
        ))));
        if ($screens === []) {
            $flash = 'Pick at least one display you are allowed to deploy to.'; $flashOk = false;
        } else {
            $result = slides_deploy_to_screens($screens);
            cfg_reload();
            $flash = slides_deploy_flash_message($result) . ' Wall displays refresh within 30 seconds.';
        }
    }

    if (($_POST['action'] ?? '') === 'remove_slides_rotation') {
        $screen = rotation_normalize_screen_key((string)($_POST['remove_screen'] ?? $_POST['screen'] ?? ''));
        if ($screen === '' || !admin_can_screen($screen)) {
            $flash = 'Invalid display.'; $flashOk = false;
        } else {
            $rm = rotation_remove_all_slides($screen);
            if ($rm['removed'] && rotation_pages_write($rm['screen'], $rm['pages'])) {
                cfg_reload();
                $name = rotation_screen_display_name($screen, rotation_screens());
                $flash = 'Removed ' . (int)$rm['removed_count'] . ' slide entr'
                    . ((int)$rm['removed_count'] === 1 ? 'y' : 'ies') . ' from ' . $name . ' playlist.';
            } else {
                $flash = 'Custom slides were not on that playlist.'; $flashOk = false;
            }
        }
    }

    if (($_POST['action'] ?? '') === 'upload_slide') {
        if (!isset($_FILES['slide'])) {
            $flash = 'Upload did not arrive — the file may exceed PHP post_max_size ('
                . ini_get('post_max_size') . '). Slides allow up to ' . slide_upload_max_label()
                . '; raise post_max_size and upload_max_filesize on the server.';
            $flashOk = false;
        } else {
            $saved = slide_save_upload($_FILES['slide'], $slideDir);
            if (empty($saved['ok'])) {
                $flash = (string)($saved['error'] ?? 'Upload failed — try again.');
                $flashOk = false;
            } elseif (slide_append_to_deck((string)$saved['name'])) {
                $deploy = admin_default_deploy_screens();
                if ($deploy !== []) {
                    slides_deploy_to_screens($deploy);
                }
                cfg_reload();
                header('Location: ?board=slides&highlight=' . rawurlencode((string)$saved['name']));
                exit;
            } else {
                $flash = 'File saved but could not update settings.json.'; $flashOk = false;
            }
        }
    }

    if (($_POST['action'] ?? '') === 'add_slide_to_deck') {
        $file = slide_safe_filename((string)($_POST['file'] ?? ''));
        if ($file === null || !is_file($slideDir . '/' . $file)) {
            $flash = 'Invalid slide file.'; $flashOk = false;
        } elseif (in_array($file, slides_deck_files(), true)) {
            $flash = $file . ' is already in the slide deck.';
        } elseif (slide_append_to_deck($file)) {
            cfg_reload();
            header('Location: ?board=slides&highlight=' . rawurlencode($file));
            exit;
        } else {
            $flash = 'Could not update settings.json.'; $flashOk = false;
        }
    }

    } elseif ($slideAction !== '') {
        $flash = 'Session expired — refresh the page and try again.';
        $flashOk = false;
    }
}

// ── Video board: YouTube fetch / yt-dlp upkeep ──────────────────────────────
$videoFetchLog = null;
$videoMaintOpen = ($board === 'video');
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
        $videoMaintOpen = true;
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
        $videoMaintOpen = true;
    }

    if (($_POST['action'] ?? '') === 'ytdlp_update') {
        $result = video_ytdlp_update();
        $videoFetchLog = implode("\n", $result['lines']);
        if ($result['ok']) {
            $flash = $result['message'];
        } else {
            $flash = $result['message']; $flashOk = false;
        }
        $videoMaintOpen = true;
    }

    if (($_POST['action'] ?? '') === 'ytdlp_refresh') {
        video_ytdlp_latest_release(true);
        video_deno_latest_release(true);
        $flash = 'Checked GitHub for the latest yt-dlp and deno releases.';
        $videoMaintOpen = true;
    }

    if (($_POST['action'] ?? '') === 'deno_update') {
        $result = video_deno_update();
        $videoFetchLog = implode("\n", $result['lines']);
        if ($result['ok']) {
            $flash = $result['message'];
        } else {
            $flash = $result['message']; $flashOk = false;
        }
        $videoMaintOpen = true;
    }

    if (($_POST['action'] ?? '') === 'upload_youtube_cookies' && isset($_FILES['youtube_cookies'])) {
        $result = video_ytdlp_save_cookies_upload($_FILES['youtube_cookies']);
        if ($result['ok']) {
            $flash = $result['message'];
        } else {
            $flash = $result['message']; $flashOk = false;
        }
        $videoMaintOpen = true;
    }
}

// ── Photo rotator: upload / delete / deploy ─────────────────────────────────
if ($authed && $board === 'rotator') {
    $photoDir = rotator_photo_dir();
    if (!is_dir($photoDir)) @mkdir($photoDir, 0775, true);
    protect_dirs();

    $photoAction = (string)($_POST['action'] ?? '');

    if ($photoAction === 'delete_photo') {
        if (!csrf_ok()) {
            $flash = 'Session expired — refresh the page and try again.';
            $flashOk = false;
        } else {
            $deck = is_array($rawConf['rotator.PHOTOS'] ?? null) ? $rawConf['rotator.PHOTOS'] : [];
            if (!admin_can_delete_deck_file($deck, (string)($_POST['file'] ?? ''), 'rotator_safe_filename', static fn($e) => rotator_safe_filename((string)($e['file'] ?? '')))) {
                $flash = 'You do not have permission to delete that photo.';
                $flashOk = false;
            } else {
                $result = rotator_delete_file((string)($_POST['file'] ?? ''));
                if (!empty($result['ok'])) {
                    header('Location: ?board=rotator&deleted=' . rawurlencode((string)$result['file']));
                    exit;
                }
                $flash = (string)($result['error'] ?? 'Could not delete photo.');
                $flashOk = false;
            }
        }
    } elseif (csrf_ok()) {
        if ($photoAction === 'deploy_photos') {
            $screens = admin_filter_deploy_screens(array_values(array_filter(array_map(
                'rotation_normalize_screen_key',
                (array)($_POST['deploy_screens'] ?? [])
            ))));
            if ($screens === []) {
                $flash = 'Pick at least one display you are allowed to deploy to.'; $flashOk = false;
            } else {
                $result = rotator_deploy_to_screens($screens);
                cfg_reload();
                $flash = rotator_deploy_flash_message($result) . ' Wall displays refresh within 30 seconds.';
            }
        }

        if ($photoAction === 'remove_photos_rotation') {
            $screen = rotation_normalize_screen_key((string)($_POST['remove_screen'] ?? $_POST['screen'] ?? ''));
            if ($screen === '' || !admin_can_screen($screen)) {
                $flash = 'Invalid display.'; $flashOk = false;
            } else {
                $rm = rotation_remove_all_photos($screen);
                if ($rm['removed'] && rotation_pages_write($rm['screen'], $rm['pages'])) {
                    cfg_reload();
                    $name = rotation_screen_display_name($screen, rotation_screens());
                    $flash = 'Removed ' . (int)$rm['removed_count'] . ' photo entr'
                        . ((int)$rm['removed_count'] === 1 ? 'y' : 'ies') . ' from ' . $name . ' playlist.';
                } else {
                    $flash = 'Photos were not on that playlist.'; $flashOk = false;
                }
            }
        }

        if ($photoAction === 'upload_photo' && isset($_FILES['photo'])) {
            $uploaded = [];
            $errors = [];
            foreach (rotator_normalize_uploads($_FILES['photo']) as $f) {
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $result = rotator_save_upload($f, $photoDir);
                if ($result['ok']) {
                    rotator_append_to_deck((string)$result['name']);
                    $uploaded[] = $result['name'];
                } else {
                    $errors[] = ($f['name'] ?? 'file') . ': ' . ($result['error'] ?? 'failed');
                }
            }
            if ($uploaded) {
                $deploy = admin_default_deploy_screens();
                if ($deploy !== []) {
                    rotator_deploy_to_screens($deploy);
                }
                cfg_reload();
                header('Location: ?board=rotator&highlight=' . rawurlencode((string)$uploaded[0]));
                exit;
            } elseif ($errors) {
                $flash = implode('; ', $errors);
                $flashOk = false;
            } else {
                $flash = 'No files selected.'; $flashOk = false;
            }
        }

        if ($photoAction === 'add_photo_to_deck') {
            $file = rotator_safe_filename((string)($_POST['file'] ?? ''));
            if ($file === null || !is_file($photoDir . '/' . $file)) {
                $flash = 'Invalid photo file.'; $flashOk = false;
            } elseif (in_array($file, rotator_deck_files(), true)) {
                $flash = $file . ' is already in the photo deck.';
            } elseif (rotator_append_to_deck($file)) {
                cfg_reload();
                header('Location: ?board=rotator&highlight=' . rawurlencode($file));
                exit;
            } else {
                $flash = 'Could not update settings.json.'; $flashOk = false;
            }
        }
    } elseif ($photoAction !== '') {
        $flash = 'Session expired — refresh the page and try again.';
        $flashOk = false;
    }
}

// Current value resolution for form display: configured value or null
$rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
function current_val(array $rawConf, string $board, string $key)
{
    return $rawConf["$board.$key"] ?? null;
}
$videoYtdlpStatus = null;
$videoDenoStatus = null;
$videoStatuses = [];
$videoStatusByKey = [];
if ($authed && $board === 'video') {
    $videoYtdlpStatus = video_ytdlp_status();
    $videoDenoStatus = video_deno_status();
    $videoYtdlpSupport = video_ytdlp_support_status();
    foreach (video_registry() as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        if (!admin_is_super() && !admin_entry_visible($v)) {
            continue;
        }
        $st = video_entry_status($k, $v);
        $st['in_rotation'] = video_in_rotation($k, 'main');
        $videoStatuses[] = $st;
        $videoStatusByKey[$k] = $st;
    }
}

$navGroups = [
    'Setup'           => ['security', 'rotation', 'ticker'],
    'Weather & home'  => ['index', 'lake', 'webcam', 'photo', 'air', 'sports', 'family', 'traffic'],
    'Monitoring'      => ['homelab', 'signaltrace'],
    'Media'           => ['slides', 'rotator', 'video', 'rss'],
    'Dashboards'      => ['grafana', 'splunk', 'splunkdash', 'web'],
];
$slidesBoardKeys = ['SLIDE_DIR', 'DEFAULT_DWELL', 'SHUFFLE', 'FIT', 'SHOW_CLOCK', 'TIMEZONE'];
$rotatorBoardKeys = ['PHOTO_DIR', 'BRAND', 'DEFAULT_DWELL', 'INTERVAL_SEC', 'DEPLOY_MODE', 'SHUFFLE', 'SHOW_EXIF', 'SHOW_CLOCK', 'TIMEZONE'];
$splunkBoardKeys = ['SPLUNK_BASE', 'SPLUNK_TOKEN', 'SPLUNK_VERIFY_TLS', 'BOARD_TITLE', 'BOARD_SUB', 'TIMEZONE', 'CACHE_TTL'];
$videoBoardKeys = ['VIDEO_DIR', 'FIT', 'SHOW_CLOCK', 'MAX_HEIGHT', 'YTDLP_COOKIES_FILE', 'YTDLP_JS_RUNTIME', 'TIMEZONE'];
$rotationBoardKeys = ['TIMEZONE', 'FADE_MS', 'SETTLE_MS', 'HANG_MS'];
$rotationQuickAdd = rotation_quick_add_items();
$rotationQuickGroups = [];
foreach ($rotationQuickAdd as $item) {
    $rotationQuickGroups[$item['group']][] = $item;
}
$rotationStarterPages = rotation_starter_pages();
$rotationMainPages = rotation_screen_pages('main');
if ($rotationMainPages === []) {
    $rotationMainPages = $rotationStarterPages;
}
$slidesDeckForUser = admin_filter_owned_list(is_array($rawConf['slides.SLIDES'] ?? null) ? $rawConf['slides.SLIDES'] : []);
$slidesDeckStats = slides_deck_stats($slidesDeckForUser);
$slidesDeployStatus = admin_filter_deploy_status(slides_deploy_status($slidesDeckForUser !== [] ? $slidesDeckForUser : null));
$slideHighlight = slide_safe_filename((string)($_GET['highlight'] ?? ''));
$slidesOrphanFiles = ($board === 'slides')
    ? slides_orphan_files($rawConf['slides.SLIDES'] ?? null)
    : [];
$slidesLibrary = ($board === 'slides')
    ? admin_filter_library_entries(
        slides_library_entries($rawConf['slides.SLIDES'] ?? null),
        admin_filter_owned_list(is_array($rawConf['slides.SLIDES'] ?? null) ? $rawConf['slides.SLIDES'] : []),
        static fn($e) => slide_safe_filename((string)($e['file'] ?? ''))
    )
    : [];
if ($board === 'rotator') {
    rotator_migrate_deck_from_files();
    $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : $rawConf;
}
$rotatorDeckForUser = admin_filter_owned_list(is_array($rawConf['rotator.PHOTOS'] ?? null) ? $rawConf['rotator.PHOTOS'] : []);
$rotatorDeckStats = rotator_deck_stats($rotatorDeckForUser);
$rotatorDeployStatus = admin_filter_deploy_status(rotator_deploy_status($rotatorDeckForUser !== [] ? $rotatorDeckForUser : null));
$userAdminRows = admin_is_super() ? users_admin_rows() : [];
$navGroupsFiltered = $authed ? admin_filter_nav_groups($navGroups, $schema) : $navGroups;
$photoHighlight = rotator_safe_filename((string)($_GET['highlight'] ?? ''));
$rotatorLibrary = ($board === 'rotator')
    ? admin_filter_library_entries(
        rotator_library_entries($rawConf['rotator.PHOTOS'] ?? null),
        admin_filter_owned_list(is_array($rawConf['rotator.PHOTOS'] ?? null) ? $rawConf['rotator.PHOTOS'] : []),
        static fn($e) => rotator_safe_filename((string)($e['file'] ?? ''))
    )
    : [];
$splunkPages = [];
$splunkActivePage = 'main';
if ($board === 'splunk') {
    $splunkPages = admin_filter_owned_map(splunk_admin_pages($rawConf));
    $splunkActivePage = splunk_normalize_page_key((string)($_GET['page'] ?? ''));
    if (!isset($splunkPages[$splunkActivePage])) {
        $splunkActivePage = (string)(array_key_first($splunkPages) ?: 'main');
    }
}

const ADMIN_SCREEN_PICKER_COMPACT = 5; // rotation target dropdown gets a filter above this count

/** @return list<array{key:string,name:string}> */
function admin_screen_options(array $screens): array
{
    $out = [];
    foreach ($screens as $sk => $sm) {
        $out[] = [
            'key' => (string)$sk,
            'name' => (string)(is_array($sm) ? ($sm['name'] ?? $sk) : $sm),
        ];
    }
    return $out;
}

/** @param list<string> $checked */
function admin_screen_picker_summary(array $options, array $checked, string $mode): string
{
    $total = count($options);
    $n = count($checked);
    if ($mode === 'assign') {
        if ($n === 0) {
            return 'No displays';
        }
        if ($n === $total) {
            return 'All displays (' . $total . ')';
        }
        return $n . ' of ' . $total . ' displays';
    }
    if ($n === 0) {
        return 'Hidden on all displays';
    }
    if ($n === $total) {
        return 'All displays (' . $total . ')';
    }
    return $n . ' of ' . $total . ' displays';
}

/**
 * Checkbox group for targeting rotation displays. Collapses to a summary when many screens exist.
 *
 * @param list<array{key:string,name:string}> $options
 * @param list<string> $checked
 * @param array<string,mixed> $cfg flat, name, name_key, form_marker, form_marker_key, summary_mode, compact, label, class
 */
function admin_screen_picker(string $prefix, array $options, array $checked, array $cfg = []): void
{
    if ($options === []) {
        echo '<span class="help" style="margin:0">No displays configured.</span>';
        return;
    }
    $nameKey = (string)($cfg['name_key'] ?? 'screens');
    $flat = !empty($cfg['flat']);
    $flatName = (string)($cfg['name'] ?? 'deploy_screens');
    $summaryMode = (string)($cfg['summary_mode'] ?? 'deck');
    $compact = !array_key_exists('compact', $cfg) || (bool)$cfg['compact'];
    $label = (string)($cfg['label'] ?? '');
    $extraClass = (string)($cfg['class'] ?? '');
    $checkedSet = array_flip($checked);
    $checkboxName = $flat ? $flatName . '[]' : $prefix . '[' . $nameKey . '][]';
    $pickerClass = 'screen-picker' . ($compact ? ' screen-picker-compact' : '') . ($extraClass !== '' ? ' ' . $extraClass : '');

    echo '<div class="' . h($pickerClass) . '" data-screen-picker data-summary-mode="' . h($summaryMode) . '">';
    if (!empty($cfg['form_marker'])) {
        $mk = (string)($cfg['form_marker_key'] ?? '_screens_form');
        echo '<input type="hidden" name="' . h($prefix . '[' . $mk . ']') . '" value="1">';
    }
    if ($compact) {
        $summaryText = admin_screen_picker_summary($options, $checked, $summaryMode);
        echo '<div class="screen-picker-bar">';
        echo '<span class="screen-picker-summary" data-screen-summary>' . h($summaryText) . '</span>';
        echo '<button type="button" class="screen-picker-toggle secondary" aria-expanded="false">Choose…</button>';
        echo '</div>';
        echo '<div class="screen-picker-panel" hidden>';
        echo '<input type="search" class="screen-picker-filter" placeholder="Filter displays…" autocomplete="off">';
        echo '<div class="screen-picker-quick">';
        echo '<button type="button" class="secondary" data-pick="all">All</button>';
        echo '<button type="button" class="secondary" data-pick="none">None</button>';
        echo '</div>';
    } elseif ($label !== '') {
        echo '<span class="mini">' . h($label) . '</span>';
    }
    echo '<div class="screen-picker-list slide-screen-checks">';
    foreach ($options as $opt) {
        $k = $opt['key'];
        $isChecked = isset($checkedSet[$k]);
        echo '<label data-screen-key="' . h($k) . '"><input type="checkbox" name="' . h($checkboxName) . '" value="' . h($k) . '"'
            . ($isChecked ? ' checked' : '') . '> ' . h($opt['name']) . '</label>';
    }
    echo '</div>';
    if ($compact) {
        echo '</div>';
    }
    echo '</div>';
}

/** @return list<string> */
function admin_deploy_screens_from_post(array $post): array
{
    if (!isset($post['deploy_screens']) || !is_array($post['deploy_screens'])) {
        return [];
    }
    $screens = [];
    foreach ($post['deploy_screens'] as $scr) {
        $sk = rotation_normalize_screen_key((string)$scr);
        if ($sk !== '') {
            $screens[$sk] = true;
        }
    }
    $list = array_keys($screens);
    sort($list);
    return $list;
}

/** @param array<string,array<string,mixed>> $deployStatus */
function admin_deploy_picker_from_status(array $deployStatus, array $checked, array $cfg = []): void
{
    $options = [];
    foreach ($deployStatus as $sk => $dep) {
        $options[] = ['key' => (string)$sk, 'name' => (string)($dep['name'] ?? $sk)];
    }
    admin_screen_picker('', $options, $checked, array_merge([
        'flat' => true,
        'name' => 'deploy_screens',
        'summary_mode' => 'assign',
        'compact' => true,
        'class' => 'screen-picker-inline',
    ], $cfg));
}

function admin_deploy_picker_from_screens(array $screens, array $checked, array $cfg = []): void
{
    admin_screen_picker('', admin_screen_options($screens), $checked, array_merge([
        'flat' => true,
        'name' => 'deploy_screens',
        'summary_mode' => 'assign',
        'compact' => true,
        'class' => 'screen-picker-inline',
    ], $cfg));
}

/** Per-display photo rotator sync — rendered on Rotation; deploy actions stay on Photo Rotator. */
function admin_rotator_sync_panel(array $deployStatus, array $deckStats, string $deployMode, ?string $removeFormId = null): void
{
    if ($deployStatus === []) {
        return;
    }
    $gridId = 'rotation-photos-deploy-grid';
    ?>
    <details class="panel panel-muted" style="margin-bottom:16px">
      <summary>Photo rotator sync (<?= (int)$deckStats['on_disk'] ?> in deck)</summary>
      <div class="panel-body" style="padding-top:8px">
        <div class="help" style="margin-bottom:12px">
          Sync status by display (<strong><?= h($deployMode) ?></strong> mode, <?= (int)$deckStats['playlist_entries'] ?> playlist entr<?= (int)$deckStats['playlist_entries'] === 1 ? 'y' : 'ies' ?>).
          Deploy from <a href="?board=rotator&amp;tab=deploy">Photo Rotator → Deploy</a>.
          <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px;margin-left:6px" href="<?= h(signage_board_preview_url('rotator.php')) ?>" target="_blank" rel="noopener">Preview slideshow ↗</a>
        </div>
        <input type="search" class="deploy-filter" placeholder="Filter displays…" autocomplete="off" data-deploy-filter="<?= h($gridId) ?>">
        <div class="slides-deploy-grid" id="<?= h($gridId) ?>">
          <?php foreach ($deployStatus as $screenKey => $dep): ?>
          <div class="slides-deploy-row" data-deploy-screen="<?= h($screenKey) ?>">
            <div class="deploy-row-title"><strong><?= h($dep['name']) ?></strong><code><?= h($screenKey) ?></code></div>
            <div class="deploy-detail">
              <?php if ($dep['mirrors_main']): ?>
                <span class="pill">Mirrors main</span>
              <?php elseif ($dep['on_playlist']): ?>
                <span class="pill ok">Synced</span> <?= (int)$dep['sync']['synced'] ?>/<?= (int)$dep['expected'] ?>
              <?php elseif ($dep['partial'] ?? false): ?>
                <span class="pill warn">Partial</span>
              <?php else: ?>
                <span class="pill warn">Not deployed</span>
              <?php endif; ?>
            </div>
            <div class="deploy-actions">
              <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px"
                 href="<?= h(rotation_screen_preview_url($screenKey)) ?>" target="_blank" rel="noopener">Preview ↗</a>
              <?php if ($removeFormId !== null && ($dep['on_playlist'] || ($dep['partial'] ?? false)) && !$dep['mirrors_main']): ?>
              <button type="submit" class="secondary" style="padding:6px 12px;font-size:13px"
                      form="<?= h($removeFormId) ?>" name="action" value="remove_photos_rotation"
                      onclick="document.getElementById('<?= h($removeFormId) ?>Screen').value='<?= h($screenKey) ?>'; return confirm('Remove photos from <?= h($dep['name']) ?>?');">Remove</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </details>
    <?php
}

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
  main.main-wide { max-width:none; width:100%; }
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
  .rows-scroll { overflow-x:auto; margin-top:4px; max-width:100%; -webkit-overflow-scrolling:touch; }
  .rows-scroll table.rows { width:max-content; min-width:100%; }
  table.rows[data-field="SCREENS"] th,
  table.rows[data-field="SCREENS"] td { white-space:nowrap; }
  table.rows[data-field="SCREENS"] td:first-child input { min-width:64px; width:72px; }
  table.rows[data-field="SCREENS"] td:nth-child(2) input { min-width:140px; width:min(180px, 28vw); }
  table.rows[data-field="SCREENS"] td:nth-child(n+3):nth-child(-n+8),
  table.rows[data-field="SCREENS"] th:nth-child(n+3):nth-child(-n+8) { text-align:center; width:1%; padding-left:4px; padding-right:4px; }
  table.rows[data-field="SCREENS"] td:nth-child(n+9):nth-child(-n+11) { text-align:center; width:1%; }
  table.rows[data-field="SCREENS"] td:nth-child(n+9):nth-child(-n+10) input { width:52px; min-width:52px; }
  table.rows[data-field="SCREENS"] td:last-child { padding-right:0; }
  table.rows th { text-align:left; font-size:12.5px; letter-spacing:1px; text-transform:uppercase;
                  color:var(--mist); font-weight:500; padding:4px 8px 8px 0; }
  table.rows td { padding:0 8px 10px 0; vertical-align:middle; }
  .cal-palette-cell { display:flex; align-items:center; gap:10px; min-width:148px; }
  .cal-palette-cell select { min-width:108px; }
  .cal-swatch { width:22px; height:22px; border-radius:50%; flex-shrink:0;
                box-shadow:0 0 0 2px var(--line); }
  .cal-legend-preview { display:flex; flex-wrap:wrap; gap:12px 20px; margin:10px 0 0; }
  .cal-legend-preview .leg { display:flex; align-items:center; gap:8px; font-size:14px; color:var(--mist); }
  .cal-legend-preview .dot { width:12px; height:12px; border-radius:50%; }
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
  .slide-card.dragging { opacity:.55; }
  .slide-card-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:14px; }
  .slide-card-head .drag-handle { cursor:grab; color:var(--mist); font-size:18px; line-height:1; padding:4px 6px 0 0; user-select:none; flex-shrink:0; }
  .slide-card-head .drag-handle:active { cursor:grabbing; }
  .slide-card-title { flex:1; min-width:0; }
  .slide-card-head strong { display:block; font-size:15px; color:var(--snow); word-break:break-all; margin-bottom:4px; }
  .slide-card-meta-line { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; font-size:12px; color:var(--mist); }
  .slide-card-meta-line .schedule-summary { color:var(--mist); }
  .slide-card-head .rowdel { width:32px; height:32px; font-size:16px; flex-shrink:0; }
  .slides-deploy-panel { margin:0 0 22px; padding:18px 20px; border:1px solid var(--line); border-radius:12px; background:var(--harbor); }
  .slides-section-nav { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 18px;
                        padding:10px 14px; border:1px solid var(--line); border-radius:10px; background:var(--lake-night); }
  .slides-section-nav .nav-label { font-size:11px; letter-spacing:.8px; text-transform:uppercase; color:var(--mist); margin-right:4px; }
  .slides-section-nav a { padding:6px 14px; border-radius:8px; border:1px solid var(--line); background:var(--harbor);
                          color:var(--snow); text-decoration:none; font-size:13px; font-weight:500; }
  .slides-section-nav a:hover { border-color:var(--beacon); color:var(--beacon); }
  .slides-section-nav a.is-active { border-color:var(--beacon); color:var(--beacon); }
  .admin-tabs { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 16px; }
  .admin-tab { appearance:none; background:var(--harbor); border:1px solid var(--line); color:var(--mist);
               padding:8px 16px; border-radius:8px; cursor:pointer; font:inherit; font-size:14px; }
  .admin-tab:hover { border-color:var(--beacon); color:var(--snow); }
  .admin-tab.active { border-color:var(--beacon); color:var(--beacon); font-weight:600; background:rgba(255,179,71,.08); }
  .admin-tab .tab-count { font-size:12px; opacity:.75; margin-left:4px; }
  .admin-tab-panel { display:none; }
  .admin-tab-panel.active { display:block; }
  .slides-form-footer { margin-top:20px; padding-top:16px; border-top:1px solid var(--line); }
  .slide-card-quick { display:flex; align-items:flex-end; gap:10px; margin:0 0 10px; max-width:120px; }
  .slide-card-edit { margin-top:0; }
  .slide-card-edit > summary { cursor:pointer; color:var(--mist); font-size:13px; padding:6px 0; list-style:none; }
  .slide-card-edit > summary::-webkit-details-marker { display:none; }
  .slide-card-edit[open] > summary { color:var(--beacon); margin-bottom:8px; }
  .rotation-global-add { background:var(--lake-night); border:1px solid var(--line); border-radius:12px;
                         padding:14px 16px; margin:16px 0; }
  .rotation-global-add-row { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:flex-end; margin-bottom:10px; }
  .rotation-global-add-row label.mini { display:block; margin-bottom:4px; }
  .rotation-global-add-row select { min-width:180px; padding:8px 10px; font-size:14px; background:var(--harbor);
                                    border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .rotation-card-edit > summary { cursor:pointer; color:var(--mist); font-size:13px; padding:4px 0 8px; list-style:none; }
  .rotation-card-edit > summary::-webkit-details-marker { display:none; }
  .rotation-card-edit[open] > summary { color:var(--beacon); }
  .rotation-card-head .rotation-card-head-meta { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; margin-left:auto; }
  #slide-deck-panel, #slide-library-panel, #add-slides-panel, #slides-deploy-panel { scroll-margin-top:12px; }
  .slides-deploy-panel .deploy-stats { display:flex; flex-wrap:wrap; gap:8px 16px; margin-bottom:14px; font-size:14px; color:var(--mist); }
  .slides-deploy-panel .deploy-stats strong { color:var(--snow); }
  .slides-deploy-grid { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
  .slides-deploy-row { display:flex; flex-wrap:wrap; gap:10px 14px; align-items:center; padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--lake-night); }
  .slides-deploy-row .deploy-row-title { min-width:140px; font-size:14px; color:var(--snow); }
  .slides-deploy-row .deploy-row-title code { font-size:12px; color:var(--mist); margin-left:6px; }
  .slides-deploy-row .deploy-detail { flex:1; min-width:200px; font-size:13px; color:var(--mist); }
  .slides-deploy-row .deploy-actions { display:flex; gap:8px; flex-wrap:wrap; }
  .slides-deploy-tools { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
  .slides-save-deploy { display:flex; flex-direction:column; gap:10px; margin-top:18px; padding-top:16px; border-top:1px solid var(--line); }
  .slides-save-deploy .deploy-checks { display:flex; flex-wrap:wrap; gap:12px 18px; }
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
  .slide-card-screens { margin-top:14px; padding-top:12px; border-top:1px solid var(--line); }
  .entry-sharing { margin-top:14px; padding-top:12px; border-top:1px dashed var(--line); }
  .entry-sharing select { width:100%; max-width:280px; padding:8px 10px; font-size:14px;
    background:#0f1728; border:1px solid var(--line); border-radius:8px; color:var(--snow); margin-top:4px; }
  .entry-sharing-shared-label { display:block; margin-top:10px; margin-bottom:4px; }
  .entry-sharing-panel-head { margin-bottom:8px; font-size:13px; color:var(--snow); }
  .entry-sharing-owner-list { display:flex; flex-direction:column; gap:2px; max-height:120px; overflow-y:auto; margin-top:4px; padding-right:2px; }
  .entry-sharing-owner-list label { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--snow); padding:4px 2px; cursor:pointer; }
  .entry-sharing-owner-list input { width:15px; height:15px; min-width:15px; accent-color:var(--beacon); margin:0; }
  .entry-sharing-users-scroll { max-height:140px; overflow-y:auto; margin-top:4px; padding-right:4px; border-top:1px solid var(--line); padding-top:8px; }
  .entry-sharing-users-scroll label { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--snow); padding:4px 2px; cursor:pointer; }
  .entry-sharing-users-scroll input { width:15px; height:15px; min-width:15px; accent-color:var(--beacon); margin:0; }
  .entry-sharing--popover { margin:0; padding:0; border:0; position:relative; }
  .entry-sharing-trigger { padding:8px 10px; font-size:14px; width:100%; min-width:96px; max-width:118px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left; position:relative; z-index:1; }
  .entry-sharing-menu { position:fixed; z-index:1200; width:min(280px, calc(100vw - 24px)); padding:14px;
    background:var(--harbor); border:1px solid var(--line); border-radius:12px; box-shadow:0 16px 40px rgba(0,0,0,.55); }
  .entry-sharing-menu[hidden] { display:none !important; }
  .entry-sharing-menu select { width:100%; max-width:none; margin-top:4px; }
  .entry-sharing-cell { width:118px; max-width:118px; vertical-align:middle; padding-right:6px !important; }
  table.rows td.entry-sharing-cell .entry-sharing-trigger { display:block; }
  .slide-screen-checks { display:flex; flex-wrap:wrap; gap:8px 16px; margin-top:8px; }
  .slide-screen-checks label { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--snow); }
  .slide-screen-checks label[hidden] { display:none; }
  .screen-picker { margin-top:8px; }
  .screen-picker-bar { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; }
  .screen-picker-summary { font-size:13px; color:var(--snow); flex:1; min-width:140px; }
  .screen-picker-toggle { padding:5px 12px; font-size:12px; flex-shrink:0; }
  .screen-picker-panel { margin-top:10px; padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:var(--lake-night); }
  .screen-picker-filter { width:100%; max-width:none; margin-bottom:8px; padding:7px 10px; font-size:14px;
    background:var(--harbor); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .screen-picker-quick { display:flex; gap:8px; margin-bottom:8px; }
  .screen-picker-quick button { padding:4px 10px; font-size:12px; }
  .screen-picker-compact .screen-picker-list { max-height:168px; overflow:auto; margin-top:0; padding-right:4px; }
  .screen-picker-inline { margin-top:0; }
  .screen-picker-deploy-tab { margin-bottom:14px; }
  .deploy-save-screens { margin-bottom:4px; }
  .deploy-filter { width:100%; max-width:360px; margin-bottom:12px; padding:8px 11px; font-size:14px;
    background:var(--harbor); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .slides-deploy-row[hidden] { display:none; }
  .slide-deck-empty { border:1px dashed var(--line); border-radius:12px; padding:18px; color:var(--mist); font-size:14px; margin-bottom:14px; }
  .slide-added-notice { margin-bottom:12px; padding:12px 14px; border-radius:10px; background:rgba(255,179,71,.12); border:1px solid var(--beacon); color:var(--snow); font-size:14px; }
  .slide-orphan-notice { margin-bottom:12px; padding:12px 14px; border-radius:10px; background:var(--lake-night); border:1px solid var(--line); color:var(--mist); font-size:13px; }
  .slide-card-highlight { border-color:var(--beacon); box-shadow:0 0 0 2px rgba(255,179,71,.28); }
  .slide-card-thumb { width:128px; height:72px; border-radius:8px; object-fit:cover; background:#000; border:1px solid var(--line); flex-shrink:0; }
  .slide-card-head-with-thumb { align-items:center; }
  .slide-card-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; align-items:center; }
  .slide-library-panel { margin-top:18px; }
  .slide-library-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:14px; margin-top:12px; }
  .slide-library-tile { background:var(--harbor); border:1px solid var(--line); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
  .slide-library-tile.not-in-deck { border-color:var(--beacon); }
  .slide-library-tile img { width:100%; aspect-ratio:16/9; object-fit:cover; background:#000; display:block; }
  .slide-library-tile-body { padding:10px 12px 12px; display:flex; flex-direction:column; gap:8px; flex:1; }
  .slide-library-tile-body strong { font-size:14px; color:var(--snow); line-height:1.35; word-break:break-word; }
  .slide-library-tile-body code { font-size:11px; color:var(--mist); word-break:break-all; }
  .slide-library-tile-actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:auto; }
  .slide-library-tile-actions form { margin:0; display:inline; }
  .slide-library-tile-actions .secondary { padding:4px 10px; font-size:12px; }
  @media (max-width: 760px) { .slide-card-grid { grid-template-columns:1fr; } .slide-card-grid .span-2 { grid-column:span 1; } }
  .video-playlist, .rotation-playlist { display:flex; flex-direction:column; gap:14px; margin-top:8px; min-height:72px; }
  .rotation-playlist-empty { border:1px dashed var(--line); border-radius:12px; padding:18px; color:var(--mist); font-size:14px; text-align:center; }
  .rotation-screen-tools { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:10px 0 6px; }
  .rotation-playlist-panel { margin-top:16px; }
  .rotation-playlist-panel > summary { display:flex; align-items:center; gap:10px 14px; flex-wrap:wrap; padding-right:48px; }
  .rotation-playlist-panel > summary code { font-size:13px; color:var(--beacon); font-weight:400; }
  .rotation-playlist-panel > summary .rotation-summary-note { color:var(--mist); font-size:14px; font-weight:400; }
  .rotation-playlist-panel > summary .rotation-summary-actions { margin-left:auto; display:flex; gap:8px; align-items:center; }
  .rotation-playlist-panel > summary .rotation-summary-actions a { padding:4px 10px; font-size:12px; text-decoration:none; }
  .rotation-playlist-controls { display:flex; flex-wrap:wrap; gap:8px; margin-top:18px; }
  .rotation-effective-list { margin:0 0 10px; padding:12px 14px; background:var(--lake-night); border:1px solid var(--line); border-radius:10px; font-size:13px; color:var(--mist); }
  .rotation-effective-list ol { margin:6px 0 0 18px; color:var(--snow); }
  .rotation-effective-list li { margin:4px 0; }
  .rotation-effective-list .mirror-note { color:var(--beacon); font-size:12px; margin-bottom:4px; }
  .video-card, .rotation-card { background:var(--harbor); border:1px solid var(--line); border-radius:12px; padding:14px 16px; }
  .video-card.dragging, .rotation-card.dragging { opacity:.55; }
  .video-card-head, .rotation-card-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:12px; }
  .video-card-head .drag-handle, .rotation-card-head .drag-handle { cursor:grab; color:var(--mist); font-size:18px; line-height:1; padding:4px 6px 0 0; user-select:none; }
  .video-card-head .drag-handle:active, .rotation-card-head .drag-handle:active { cursor:grabbing; }
  .video-card-title, .rotation-card-title { flex:1; min-width:0; }
  .video-card-title strong, .rotation-card-title strong { display:block; font-size:15px; color:var(--snow); margin-bottom:4px; }
  .video-card-title code, .rotation-card-title code { font-size:12px; color:var(--beacon); }
  .video-card-grid, .rotation-card-grid { display:grid; grid-template-columns:120px 1fr 1fr; gap:12px 14px; }
  .rotation-card-grid.cols-4 { grid-template-columns:1fr 100px 80px 80px; }
  .video-card-grid label.mini, .rotation-card-grid label.mini { display:block; font-size:11px; letter-spacing:.8px; text-transform:uppercase;
                                  color:var(--mist); margin-bottom:4px; }
  .video-card-grid input, .rotation-card-grid input { width:100%; min-width:0; padding:8px 10px; font-size:14px;
                            background:var(--lake-night); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .video-card-meta, .rotation-card-meta { display:flex; flex-wrap:wrap; gap:8px 14px; margin-top:12px; font-size:13px; color:var(--mist); align-items:center; }
  .splunk-playlist { display:flex; flex-direction:column; gap:14px; margin-top:8px; }
  .splunk-pages-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0 16px; }
  .splunk-page-tab { background:var(--harbor); border:1px solid var(--line); color:var(--snow);
    border-radius:8px; padding:8px 14px; font-size:14px; cursor:pointer; font-family:inherit; }
  .splunk-page-tab.active { border-color:var(--beacon); color:var(--beacon); font-weight:600; }
  .splunk-page-tab code { font-size:12px; color:var(--mist); margin-left:6px; }
  .splunk-page-editor { margin-top:8px; }
  .splunk-page-head { display:grid; grid-template-columns:1fr 1fr auto; gap:12px 14px; margin-bottom:14px; align-items:end; }
  .splunk-page-head label.mini { display:block; font-size:11px; letter-spacing:.8px; text-transform:uppercase; color:var(--mist); margin-bottom:4px; }
  .splunk-page-head input { width:100%; padding:8px 10px; font-size:14px; background:var(--lake-night); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  @media (max-width: 900px) { .splunk-page-head { grid-template-columns:1fr; } }
  .splunk-panel-card.is-off { opacity:.62; }
  .splunk-panel-card-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px 14px; }
  .splunk-panel-card-grid .span-3 { grid-column:1 / -1; }
  .splunk-panel-card-grid textarea { width:100%; min-width:0; padding:8px 10px; font-size:13px; font-family:'IBM Plex Mono',monospace;
    background:var(--lake-night); border:1px solid var(--line); border-radius:8px; color:var(--snow); min-height:72px; resize:vertical; }
  .splunk-panel-card-grid select, .splunk-panel-card-grid input[type=text] { width:100%; min-width:0; padding:8px 10px; font-size:14px;
    background:var(--lake-night); border:1px solid var(--line); border-radius:8px; color:var(--snow); }
  .splunk-panel-card-grid label.mini { display:block; font-size:11px; letter-spacing:.8px; text-transform:uppercase; color:var(--mist); margin-bottom:4px; }
  .splunk-test-result { font-size:13px; color:var(--mist); min-height:1.2em; }
  .splunk-test-result.ok { color:#7dd3a8; }
  .splunk-test-result.err { color:#ff9d9d; }
  @media (max-width: 900px) { .splunk-panel-card-grid { grid-template-columns:1fr; } .splunk-panel-card-grid .span-3 { grid-column:span 1; } }
  .rotation-slides-group { background:var(--lake-night); border:1px solid var(--line); border-radius:12px; padding:0; margin:0; }
  .rotation-slides-group > summary { list-style:none; display:flex; align-items:center; gap:10px 14px; flex-wrap:wrap; padding:14px 16px; cursor:pointer; }
  .rotation-slides-group > summary::-webkit-details-marker { display:none; }
  .rotation-slides-group-body { display:flex; flex-direction:column; gap:10px; padding:0 12px 12px; }
  .rotation-card-slide { background:#101826; border-color:#243044; }
  .rotation-card-slide input[readonly] { opacity:.85; cursor:default; }
  .rotation-card-grid-compact { grid-template-columns:1fr 90px 80px 80px; }
  .rotation-slides-group-handle { cursor:grab; }
  .video-card-actions, .rotation-card-actions { display:flex; flex-wrap:wrap; gap:8px; margin-left:auto; align-items:center; }
  .quick-add-bar { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 4px; align-items:center; }
  .quick-add-bar .group-label { font-size:11px; letter-spacing:.8px; text-transform:uppercase; color:var(--mist); margin-right:4px; }
  @media (max-width: 900px) { .video-card-grid, .rotation-card-grid, .rotation-card-grid.cols-4 { grid-template-columns:1fr; } }
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
  .creator-box h3 { font-family:'Big Shoulders Display'; font-size:20px; margin-bottom:6px; }
  .creator-lead { font-size:14px; color:var(--mist); line-height:1.55; margin-bottom:20px; }
  .creator-layout { display:flex; flex-direction:column; gap:28px; width:100%; }
  .creator-preview-block { width:100%; }
  .creator-panel { width:100%; display:flex; flex-direction:column; gap:22px; }
  .creator-editor { display:grid; grid-template-columns:1fr 1fr; gap:22px 28px; width:100%; }
  .creator-editor .span-full { grid-column:1 / -1; }
  .creator-editor .field-block { display:flex; flex-direction:column; min-width:0; }
  @media (max-width: 860px) { .creator-editor { grid-template-columns:1fr; } .creator-editor .span-full { grid-column:1; } }
  .creator-fields label.l { margin-top:0; margin-bottom:8px; }
  .creator-fields input[type=text],
  .creator-fields textarea,
  .creator-fields select { width:100%; max-width:none; font-family:inherit; font-size:20px;
                             line-height:1.55; padding:16px 18px; border-radius:10px; resize:vertical;
                             background:var(--harbor); border:1px solid var(--line); color:var(--snow); }
  .creator-fields input[type=text] { min-height:56px; }
  #creator_title, #creator_subtitle, #creator_footer { min-height:110px; }
  #creator_body { min-height:300px; }
  .creator-fields select { min-height:56px; cursor:pointer; font-size:17px; }
  .field-hint { font-size:12px; color:var(--mist); margin-top:5px; line-height:1.4; }
  .field-hint code { font-size:11px; color:var(--snow); background:rgba(255,255,255,.06); padding:1px 5px; border-radius:4px; }
  .template-pick { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
  .template-chip { appearance:none; background:var(--harbor); border:1px solid var(--line); color:var(--snow);
                   font:inherit; font-size:14px; padding:9px 15px; border-radius:999px; cursor:pointer;
                   transition:border-color .15s, color .15s, background .15s; }
  .template-chip:hover { border-color:var(--beacon); color:var(--beacon); }
  .template-chip.active { background:rgba(255,179,71,.14); border-color:var(--beacon); color:var(--beacon); font-weight:600; }
  .bg-pick { display:grid; grid-template-columns:repeat(auto-fill, minmax(104px, 1fr)); gap:10px; margin-top:8px; }
  .bg-pick label { cursor:pointer; min-width:0; }
  .bg-pick input { position:absolute; opacity:0; pointer-events:none; }
  .bg-swatch { display:block; width:100%; aspect-ratio:16/9; border-radius:8px; border:2px solid var(--line);
               overflow:hidden; position:relative; background:#141f33 center/cover no-repeat;
               transition:border-color .15s, box-shadow .15s; }
  .bg-swatch img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
  .bg-pick input:checked + .bg-swatch { border-color:var(--beacon); box-shadow:0 0 0 1px var(--beacon); }
  .bg-pick input:focus-visible + .bg-swatch { outline:2px solid var(--beacon); outline-offset:2px; }
  .bg-swatch span { position:absolute; left:8px; bottom:6px; z-index:1; font-size:11px; color:#edf2fb;
                     text-shadow:0 1px 4px rgba(0,0,0,.8); letter-spacing:.3px; font-weight:500; }
  .bg-swatch.photo::after { content:''; position:absolute; inset:0; pointer-events:none;
    background:linear-gradient(180deg, rgba(12,20,34,.72) 0%, rgba(12,20,34,.28) 42%, rgba(12,20,34,.68) 100%); }
  .bg-section { margin-top:18px; }
  .bg-section:first-child { margin-top:8px; }
  .bg-section-title { font-size:12px; letter-spacing:.55px; text-transform:uppercase; color:var(--mist);
                      margin-bottom:10px; }
  .bg-section-help { font-size:12px; color:var(--mist); margin:-4px 0 10px; line-height:1.45; }
  .seg-control { display:inline-flex; border:1px solid var(--line); border-radius:9px; overflow:hidden; margin-top:8px; }
  .seg-control label { display:flex; margin:0; cursor:pointer; }
  .seg-control input { position:absolute; opacity:0; pointer-events:none; }
  .seg-control span { display:block; padding:8px 18px; font-size:14px; color:var(--mist);
                      background:var(--harbor); transition:background .15s, color .15s; }
  .seg-control input:checked + span { background:var(--beacon); color:var(--lake-night); font-weight:600; }
  .preview-head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-bottom:10px; }
  .preview-badge { font-size:11px; letter-spacing:.45px; text-transform:uppercase; color:var(--mist); }
  .preview-wrap { background:#000; border-radius:12px; border:1px solid var(--line); overflow:hidden;
                  width:100%; max-width:100%; aspect-ratio:16/9; box-shadow:0 10px 40px rgba(0,0,0,.4); }
  .preview-wrap canvas { width:100%; height:100%; display:block; }
  .creator-actions { margin-top:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
  .creator-actions .help { margin:0; flex:1 1 180px; }
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
  .presence-panel { margin-bottom:22px; }
  table.presence-status { width:100%; border-collapse:collapse; font-size:14px; }
  table.presence-status th { text-align:left; font-size:12px; letter-spacing:1px; text-transform:uppercase;
                             color:var(--mist); padding:0 10px 8px 0; font-weight:500; }
  table.presence-status td { padding:10px 10px 10px 0; border-top:1px solid var(--line); vertical-align:top; }
  .presence-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:8px;
                  background:var(--bad); vertical-align:middle; }
  .presence-dot.online { background:var(--ok); box-shadow:0 0 0 3px rgba(57,196,109,.18); }
  .presence-now { color:var(--snow); font-weight:500; }
  .presence-now.muted { color:var(--mist); font-weight:400; font-size:13px; }
  .presence-stats { font-size:12px; color:var(--mist); font-weight:400; }
  .presence-top { font-size:12px; color:var(--mist); margin-top:5px; line-height:1.45; }
  .pill.play-proof { color:var(--ok); border-color:rgba(57,196,109,.35); text-transform:none; letter-spacing:0; }
  .play-log-panel { margin-top:14px; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  .play-log-panel summary { cursor:pointer; padding:10px 14px; font-size:13px; color:var(--mist);
                             background:rgba(255,255,255,.02); list-style:none; }
  .play-log-panel summary::-webkit-details-marker { display:none; }
  .play-log-panel[open] summary { border-bottom:1px solid var(--line); }
  table.play-log { width:100%; border-collapse:collapse; font-size:13px; }
  table.play-log th { text-align:left; font-size:11px; letter-spacing:.8px; text-transform:uppercase;
                      color:var(--mist); padding:8px 12px; font-weight:500; }
  table.play-log td { padding:8px 12px; border-top:1px solid var(--line); vertical-align:top; color:var(--snow); }
  table.play-log td code { font-size:12px; color:var(--mist); word-break:break-all; }
</style>
</head>
<body>
<div class="top">
  <h1>Signage <span>&middot; Admin</span></h1>
  <?php if ($authed): ?>
    <span class="admin-user" style="margin-right:14px;font-size:14px;color:var(--mist)"><?= h(admin_username()) ?><?= admin_is_super() ? '' : ' · operator' ?></span>
    <a href="?board=account" style="margin-right:12px">Account</a>
    <a href="?logout=1">Log out</a>
  <?php endif; ?>
</div>

<?php if (!$authed): ?>
  <div class="login">
    <?php if ($flash): ?><div class="flash<?= $flashOk ? '' : ' bad' ?>"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($needSetup): ?>
      <h2>Create super admin</h2>
      <p class="help" style="margin-bottom:16px">First-time setup requires the one-time key in
        <code>config/setup.key</code> on the server (not downloadable over HTTP). SSH in and run:
        <code>sudo cat /var/www/html/boards/config/setup.key</code></p>
      <form method="post">
        <input type="hidden" name="action" value="setup">
        <div class="field"><label class="l">Setup key</label>
          <input type="password" name="setup_key" autocomplete="off" required></div>
        <div class="field"><label class="l">Username</label>
          <input type="text" name="username" value="admin" autocomplete="username" required></div>
        <div class="field"><label class="l">Password (8+ characters)</label>
          <input type="password" name="password" autofocus autocomplete="new-password"></div>
        <div class="field"><label class="l">Confirm</label>
          <input type="password" name="password2" autocomplete="new-password"></div>
        <div class="actions"><button class="save">Create account</button></div>
      </form>
    <?php else: ?>
      <h2>Log in</h2>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="field"><label class="l">Username</label>
          <input type="text" name="username" autocomplete="username" autofocus required></div>
        <div class="field"><label class="l">Password</label>
          <input type="password" name="password" autocomplete="current-password" required></div>
        <div class="actions"><button class="save">Log in</button></div>
      </form>
    <?php endif; ?>
  </div>

<?php else: ?>
<div class="wrap">
  <nav>
    <?php
    $navSeen = [];
    foreach ($navGroupsFiltered as $groupLabel => $keys):
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
        <a href="?board=<?= h($k) ?>" class="<?= (!$tools && !$usersBoard && !$accountBoard && $k === $board) ? 'active' : '' ?>"><?= h($schema[$k]['title']) ?></a>
      <?php endforeach; ?>
    <?php endforeach;
    foreach ($schema as $k => $b) {
        if (!empty($navSeen[$k])) continue;
        echo '<a href="?board=' . h($k) . '" class="' . ((!$tools && !$usersBoard && !$accountBoard && $k === $board) ? 'active' : '') . '">' . h($b['title']) . "</a>\n";
    }
    ?>
    <div class="sep"></div>
    <?php if (admin_can_manage_users()): ?>
    <a href="?board=users" class="<?= $usersBoard ? 'active' : '' ?>">Users</a>
    <?php endif; ?>
    <a href="?board=account" class="<?= $accountBoard ? 'active' : '' ?>">Account</a>
    <?php if (admin_can_tools()): ?>
    <a href="?board=tools" class="<?= $tools ? 'active' : '' ?>">Tools</a>
    <?php endif; ?>
  </nav>
  <main<?= (!$tools && in_array($board, ['slides', 'rotation'], true)) ? ' class="main-wide"' : '' ?>>
    <?php if ($flash): ?><div class="flash<?= $flashOk ? '' : ' bad' ?>"><?= h($flash) ?></div><?php endif; ?>

    <?php if ($tools): ?>
      <h2>Tools</h2>
      <div class="sub">Super-admin maintenance — cache and raw configuration.</div>
      <h2 style="font-size:22px;margin-top:0">Clear API cache</h2>
      <form method="post" style="margin-bottom:28px">
        <input type="hidden" name="action" value="clearcache">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="save">Clear API cache</button>
        <div class="help" style="margin-top:8px">Deletes everything in cache/ — boards refetch on next load. Useful after changing API keys or sources.</div>
      </form>
      <h2 style="font-size:22px">config/settings.json</h2>
      <div class="sub">Only keys that differ from board defaults are stored. Edit by hand if you like — boards pick it up on next render.</div>
      <pre class="raw"><?= h(json_encode($rawConf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>

    <?php elseif ($accountBoard): ?>
      <h2>Account</h2>
      <div class="sub">Signed in as <strong><?= h(admin_username()) ?></strong>
        — <?= admin_is_super() ? 'super admin (full access)' : 'screen operator' ?>.</div>
      <?php if (!admin_is_super()): ?>
      <div class="help" style="margin-bottom:18px">You only see and edit content you own. Legacy items without an owner are super-admin only. Assigned displays:
        <?php $allowed = admin_allowed_screen_keys(); ?>
        <?php if ($allowed === []): ?>
          <span class="pill warn">none — ask a super admin to assign screens</span>
        <?php else: ?>
          <?php foreach ($allowed as $sk): ?>
            <code><?= h($sk) ?></code>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <h2 style="font-size:22px;margin-top:0">Change password</h2>
      <form method="post" style="margin-bottom:28px;max-width:420px">
        <input type="hidden" name="action" value="changepassword">
        <input type="hidden" name="board" value="account">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field"><label class="l">Current password</label>
          <input type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="field"><label class="l">New password (8+ characters)</label>
          <input type="password" name="new_password" autocomplete="new-password" required></div>
        <div class="field"><label class="l">Confirm new password</label>
          <input type="password" name="new_password2" autocomplete="new-password" required></div>
        <div class="actions"><button class="save">Update password</button></div>
      </form>

    <?php elseif ($usersBoard):
      $allScreens = rotation_screens();
    ?>
      <h2>Users</h2>
      <div class="sub">Local accounts — SSO can be added later via <code>auth_provider</code> on each user.</div>
      <form method="post" id="usersForm">
        <input type="hidden" name="action" value="save_users">
        <input type="hidden" name="board" value="users">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="rows-scroll">
        <table class="rows" id="usersTable">
          <thead><tr>
            <th>Username</th><th>Role</th><th>Displays</th><th>Disabled</th><th>New password</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($userAdminRows as $ui => $urow): ?>
            <tr>
              <td>
                <input type="hidden" name="USERS[<?= (int)$ui ?>][id]" value="<?= h((string)$urow['id']) ?>">
                <input type="text" name="USERS[<?= (int)$ui ?>][username]" value="<?= h((string)$urow['username']) ?>" required>
              </td>
              <td>
                <select name="USERS[<?= (int)$ui ?>][role]">
                  <option value="super" <?= ($urow['role'] ?? '') === 'super' ? 'selected' : '' ?>>super</option>
                  <option value="operator" <?= ($urow['role'] ?? '') === 'operator' ? 'selected' : '' ?>>operator</option>
                </select>
              </td>
              <td class="wide">
                <?php if (($urow['role'] ?? '') === 'super'): ?>
                  <span class="help" style="margin:0">All displays</span>
                <?php else:
                  admin_screen_picker('USERS[' . (int)$ui . ']', admin_screen_options($allScreens), $urow['screens'] ?? [], [
                      'summary_mode' => 'assign',
                  ]);
                endif; ?>
              </td>
              <td style="text-align:center"><input type="checkbox" name="USERS[<?= (int)$ui ?>][disabled]" value="1"
                <?= !empty($urow['disabled']) ? 'checked' : '' ?> style="width:20px;height:20px;accent-color:var(--beacon)"></td>
              <td><input type="password" name="USERS[<?= (int)$ui ?>][new_password]" autocomplete="new-password" placeholder="Leave blank to keep"></td>
              <td><button type="button" class="rowdel" onclick="this.closest('tr').remove()">×</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button type="button" class="addrow" style="margin-top:10px" onclick="addUserRow()">+ Add user</button>
        <div class="help" style="margin-top:10px">At least one <strong>super</strong> account is required. New users need a password in the <strong>New password</strong> column. Operators see items they own or that are shared with them; legacy unowned content is super-admin only until an owner is assigned.</div>
        <div class="actions" style="margin-top:16px">
          <button class="save" type="submit">Save users</button>
        </div>
      </form>
      <?php
      $userScreenOptionsJs = [];
      foreach ($allScreens as $sk => $sm) {
          $userScreenOptionsJs[] = ['key' => $sk, 'name' => (string)($sm['name'] ?? $sk)];
      }
      ?>
      <script>window.USER_SCREEN_OPTIONS = <?= json_encode($userScreenOptionsJs, JSON_UNESCAPED_UNICODE) ?>;</script>

    <?php else: $b = $schema[$board]; ?>
      <h2><?= h($b['title']) ?></h2>
      <div class="sub">Changes save to <code>config/settings.json</code>.
        <?php if ($board === 'rotation'): ?>
          <a href="<?= h(rotation_screen_preview_url('main')) ?>" target="_blank" rel="noopener">Preview main rotation ↗</a>
          · kiosk URL <code><?= h(rotation_screen_kiosk_url('main')) ?></code>
        <?php elseif ($board === 'splunk'): ?>
          Each page is <code>splunk.php?d=<em>key</em></code> in rotation — preview per tab below.
        <?php elseif ($board === 'grafana'): ?>
          Each dashboard is <code>grafana.php?d=<em>key</em></code> in rotation — preview per row below.
        <?php elseif ($board === 'splunkdash'): ?>
          Each dashboard is <code>splunkdash.php?d=<em>key</em></code> in rotation — preview per row below.
        <?php elseif ($board === 'web'): ?>
          Each site is <code>web.php?d=<em>key</em></code> in rotation — preview per row below.
        <?php elseif (!empty($b['file'])): ?>
          <a href="<?= h(signage_board_preview_url($b['file'])) ?>" target="_blank" rel="noopener">Preview board ↗</a>
        <?php endif; ?></div>

      <?php if ($board === 'rotator'): ?>
      <form id="photoDeleteForm" method="post" action="?board=rotator" hidden>
        <input type="hidden" name="action" value="delete_photo">
        <input type="hidden" name="board" value="rotator">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="file" id="photoDeleteFile" value="">
      </form>
      <form id="photoAddForm" method="post" action="?board=rotator" hidden>
        <input type="hidden" name="action" value="add_photo_to_deck">
        <input type="hidden" name="board" value="rotator">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="file" id="photoAddFile" value="">
      </form>
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
      <?php if ($videoFetchLog): ?>
        <pre class="video-log"><?= h($videoFetchLog) ?></pre>
      <?php endif; ?>

      <div class="panel" style="padding:18px 20px;margin-bottom:18px">
        <div class="section-title" style="margin-top:0">YouTube downloads</div>
        <div class="help" style="margin-bottom:12px">Build your playlist below, <strong>Save</strong>, then fetch files into <code>videos/</code>.
          Check <strong>Add playlist to main rotation</strong> to put each video on the wall automatically
          (<code>video.php?v=KEY</code> with dwell from video length).</div>
        <form method="post" class="inline-actions" action="?board=video">
          <input type="hidden" name="action" value="video_fetch">
          <input type="hidden" name="board" value="video">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <button class="save" type="submit">Download all</button>
        </form>
        <span class="help">Large downloads may take several minutes — keep this tab open.</span>

        <details class="panel-muted" style="margin-top:16px;border:1px solid var(--line);border-radius:10px"<?= ($videoMaintOpen ?? false) ? ' open' : '' ?>>
          <summary>yt-dlp maintenance</summary>
          <div style="padding:12px 16px 16px">
            <div class="video-meta">
              <div>yt-dlp installed:
                <?php if ($videoYtdlpStatus['stub'] ?? false): ?>
                  <span class="pill bad">Broken stub</span>
                  <span class="help"> — pip/pipx launcher copied into bin/; click Update yt-dlp</span>
                <?php elseif ($videoYtdlpStatus['installed']): ?>
                  <strong><?= h($videoYtdlpStatus['installed']) ?></strong>
                <?php else: ?>
                  <span class="pill bad">Not found</span>
                <?php endif; ?>
              </div>
              <div>yt-dlp latest:
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
              <div>Deno installed:
                <?php if ($videoDenoStatus['installed'] ?? false): ?>
                  <strong><?= h($videoDenoStatus['installed']) ?></strong>
                  <?php if (!empty($videoDenoStatus['path'])): ?>
                    <code><?= h($videoDenoStatus['path']) ?></code>
                    <?php if ($videoDenoStatus['system'] ?? false): ?>
                      <span class="help">(system)</span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($videoDenoStatus['installed'] && !($videoYtdlpSupport['deno_ok'] ?? false)): ?>
                    <span class="pill warn">below <?= h(video_ytdlp_deno_min_version()) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="pill warn">missing</span>
                <?php endif; ?>
              </div>
              <div>Deno latest:
                <?php if ($videoDenoStatus['latest'] ?? false): ?>
                  <strong><?= h($videoDenoStatus['latest']) ?></strong>
                  <?php if ($videoDenoStatus['outdated'] === true): ?>
                    <span class="pill warn">Update available</span>
                  <?php elseif ($videoDenoStatus['outdated'] === false): ?>
                    <span class="pill ok">Up to date</span>
                  <?php endif; ?>
                <?php elseif ($videoDenoStatus['latest_error'] ?? false): ?>
                  <span class="help"><?= h($videoDenoStatus['latest_error']) ?></span>
                <?php else: ?>
                  <span class="help">Unknown — click Check versions</span>
                <?php endif; ?>
              </div>
              <div>YouTube cookies:
                <?php if ($videoYtdlpSupport['cookies']): ?>
                  <span class="pill ok">found</span>
                  (<?= number_format((int)($videoYtdlpSupport['cookies_bytes'] ?? 0)) ?> bytes)
                <?php else: ?>
                  <span class="pill bad">missing</span>
                <?php endif; ?>
                — <code><?= h($videoYtdlpSupport['cookies_path']) ?></code>
              </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="upload-row" style="margin-top:12px" action="?board=video">
              <input type="hidden" name="action" value="upload_youtube_cookies">
              <input type="hidden" name="board" value="video">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="file" name="youtube_cookies" accept=".txt,text/plain" required>
              <button class="secondary" type="submit">Upload cookies.txt</button>
            </form>
            <div class="help" style="margin-top:10px">YouTube often blocks headless servers (“Sign in to confirm you’re not a bot”).
              Export from youtube.com while signed in using <strong>Get cookies.txt LOCALLY</strong>, test on your Mac with
              <code>yt-dlp --js-runtimes deno --remote-components ejs:github --cookies cookies.txt -F URL</code>
              (need 720p/1080p rows), then upload above — or use a <strong>local file</strong> in the video row.</div>
            <div class="help" style="margin-top:8px"><strong>Update deno</strong> downloads the latest release to
              <code>bin/deno</code> (no root) and uses it when newer than the system copy at
              <code>/usr/local/bin/deno</code>. To upgrade system deno instead: SSH as root
              <code>curl -fsSL https://deno.land/install.sh | DENO_INSTALL=/usr/local sh</code></div>
            <div class="inline-actions">
              <form method="post" action="?board=video">
                <input type="hidden" name="action" value="ytdlp_update">
                <input type="hidden" name="board" value="video">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="secondary" type="submit">Update yt-dlp</button>
              </form>
              <form method="post" action="?board=video">
                <input type="hidden" name="action" value="deno_update">
                <input type="hidden" name="board" value="video">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="secondary" type="submit">Update deno</button>
              </form>
              <form method="post" action="?board=video">
                <input type="hidden" name="action" value="ytdlp_refresh">
                <input type="hidden" name="board" value="video">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="secondary" type="submit">Check versions</button>
              </form>
            </div>
          </div>
        </details>
      </div>
      <?php endif; ?>

      <?php if ($board === 'slides'): ?>
      <form id="slideDeleteForm" method="post" action="?board=slides" hidden>
        <input type="hidden" name="action" value="delete_slide">
        <input type="hidden" name="board" value="slides">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="file" id="slideDeleteFile" value="">
      </form>
      <form id="slideAddForm" method="post" action="?board=slides" hidden>
        <input type="hidden" name="action" value="add_slide_to_deck">
        <input type="hidden" name="board" value="slides">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="file" id="slideAddFile" value="">
      </form>
      <form id="slideReplaceForm" method="post" enctype="multipart/form-data" action="?board=slides" hidden>
        <input type="hidden" name="action" value="replace_slide">
        <input type="hidden" name="board" value="slides">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="file" id="slideReplaceFile" value="">
        <input type="file" name="slide" id="slideReplaceUpload" accept="image/jpeg,image/png,image/webp">
      </form>
      <?php endif; ?>

      <form method="post" id="boardform" action="?board=<?= h($board) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="board" value="<?= h($board) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <?php
        $scheduleOptions = ['always', 'once', 'range', 'yearly', 'yearly_range', 'monthly', 'weekly'];

        if ($board === 'rotation'):
          $rotationScreens = admin_filter_screens(rotation_screens());
          $presenceAll = signage_presence_read_all();
          if (!admin_is_super()) {
              $allowedKeys = array_flip(admin_allowed_screen_keys());
              $presenceAll = array_intersect_key($presenceAll, $allowedKeys);
          }
          $scrVal = current_val($rawConf, $board, 'SCREENS');
          $scrRows = [];
          if (is_array($scrVal)) {
              foreach ($scrVal as $rk => $rv) {
                  $scrRows[] = rotation_admin_screen_row((string)$rk, $rv);
              }
          }
          if ($scrRows === []) {
              foreach ($rotationScreens as $rk => $rv) {
                  $scrRows[] = rotation_admin_screen_row((string)$rk, $rv);
              }
          }
        ?>
          <details class="panel" open style="margin-bottom:16px">
            <summary>Sign status &amp; play log</summary>
            <div class="panel-body" style="padding-top:8px">
          <div class="help" style="margin-bottom:10px">Kiosks report while <code>board.php</code> is open (~30s). Offline = no heartbeat in <?= (int)SIGNAGE_PRESENCE_STALE_SEC ?>s.</div>
          <div id="presencePanel" class="presence-panel">
            <p class="help">Loading sign status…</p>
          </div>
          <div id="playLogPanel"></div>
            </div>
          </details>

          <?php if (admin_is_super()): ?>
          <details class="panel" style="margin-bottom:16px">
            <summary>Display settings (<?= count($scrRows) ?> screen<?= count($scrRows) === 1 ? '' : 's' ?>)</summary>
            <div class="panel-body" style="padding-top:8px">
          <div class="help" style="margin-bottom:12px">Per-display ticker, clock, blank hours, and rotation mode. Kiosk URL: <code>board.php?screen=KEY</code> (plain <code>board.php</code> = main).</div>
          <div class="rows-scroll">
            <table class="rows" data-field="SCREENS">
              <thead><tr>
                <th>Key</th><th>Display name</th><th>Ticker</th><th>Clock</th><th>Debug</th><th>Weighted</th><th>Shuffle</th><th>Blank</th><th>Off hr</th><th>On hr</th><th>CEC</th><th></th>
              </tr></thead>
              <tbody>
                <?php foreach ($scrRows as $sri => $srow): ?>
                <tr>
                  <td><input type="text" name="SCREENS[<?= (int)$sri ?>][_key]" value="<?= h((string)($srow['_key'] ?? '')) ?>" placeholder="garage"></td>
                  <td><input type="text" name="SCREENS[<?= (int)$sri ?>][name]" value="<?= h((string)($srow['name'] ?? '')) ?>" placeholder="Garage TV"></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][show_ticker]" value="1" <?= !empty($srow['show_ticker']) ? 'checked' : '' ?>></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][show_clock]" value="1" <?= !empty($srow['show_clock']) ? 'checked' : '' ?>></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][show_debug]" value="1" <?= !empty($srow['show_debug']) ? 'checked' : '' ?>></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][weighted]" value="1" <?= !empty($srow['weighted']) ? 'checked' : '' ?>></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][shuffle]" value="1" <?= !empty($srow['shuffle']) ? 'checked' : '' ?>></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][schedule_enabled]" value="1" <?= !empty($srow['schedule_enabled']) ? 'checked' : '' ?>></td>
                  <td><input type="text" name="SCREENS[<?= (int)$sri ?>][cec_off]" value="<?= h((string)($srow['cec_off'] ?? '23')) ?>" placeholder="23" style="width:52px"></td>
                  <td><input type="text" name="SCREENS[<?= (int)$sri ?>][cec_on]" value="<?= h((string)($srow['cec_on'] ?? '6')) ?>" placeholder="6" style="width:52px"></td>
                  <td style="text-align:center;vertical-align:middle"><input type="checkbox" style="width:20px;height:20px;accent-color:var(--beacon);min-width:0"
                         name="SCREENS[<?= (int)$sri ?>][cec_enabled]" value="1" <?= !empty($srow['cec_enabled']) ? 'checked' : '' ?>></td>
                  <td><button type="button" class="rowdel" onclick="this.closest('tr').remove()">×</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="addrow" onclick="addRow(this)">+ Add screen</button>
            </div>
          </details>
          <?php elseif ($rotationScreens === []): ?>
          <div class="flash bad" style="margin-bottom:16px">No displays assigned to your account — ask a super admin to assign screens under <strong>Users</strong>.</div>
          <?php endif; ?>

          <?php if (admin_can_board('rotator')): ?>
          <form id="rotatorSyncRemoveForm" method="post" action="?board=rotator" hidden>
            <input type="hidden" name="board" value="rotator">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="remove_screen" id="rotatorSyncRemoveFormScreen" value="">
          </form>
          <?php admin_rotator_sync_panel($rotatorDeployStatus, $rotatorDeckStats, rotator_deploy_mode(), 'rotatorSyncRemoveForm'); ?>
          <?php endif; ?>

          <div class="section-title">Playlists</div>
          <div class="rotation-global-add" id="rotationGlobalAdd">
            <div class="rotation-global-add-row">
              <div>
                <label class="mini" for="rotationTargetScreen">Add to display</label>
                <?php if (count($rotationScreens) > ADMIN_SCREEN_PICKER_COMPACT): ?>
                <input type="search" class="deploy-filter" style="max-width:100%;margin-bottom:6px" placeholder="Filter displays…" autocomplete="off" data-rotation-target-filter="rotationTargetScreen">
                <?php endif; ?>
                <select id="rotationTargetScreen">
                  <?php foreach ($rotationScreens as $sk => $sm):
                    $did = 'rotationDeck-' . preg_replace('/[^a-z0-9_\-]/i', '', (string)$sk);
                  ?>
                  <option value="<?= h($did) ?>"<?= $sk === 'main' ? ' selected' : '' ?>><?= h(rotation_screen_display_name((string)$sk, $rotationScreens)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="button" class="addrow" onclick="addRotationPage(document.getElementById('rotationTargetScreen').value)">+ Page</button>
              <button type="button" class="secondary" onclick="loadRotationStarter(document.getElementById('rotationTargetScreen').value)">Starter playlist</button>
              <button type="button" class="secondary" id="rotationCopyMainBtn" onclick="copyRotationFromMain(document.getElementById('rotationTargetScreen').value)">Copy from main</button>
            </div>
            <?php foreach ($rotationQuickGroups as $groupName => $groupItems): ?>
            <div class="quick-add-bar">
              <span class="group-label"><?= h($groupName) ?></span>
              <?php foreach ($groupItems as $qa): ?>
              <button type="button" class="secondary quick-add-rotation" style="padding:6px 12px;font-size:13px"
                      data-url="<?= h($qa['url']) ?>" data-dwell="<?= (int)$qa['dwell'] ?>"><?= h($qa['label']) ?></button>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if (count($rotationScreens) > 1): ?>
          <div class="rotation-playlist-controls">
            <button type="button" class="secondary" onclick="setRotationPlaylistsOpen(true)">Expand all playlists</button>
            <button type="button" class="secondary" onclick="setRotationPlaylistsOpen(false)">Collapse all playlists</button>
          </div>
          <?php endif; ?>

          <?php foreach ($rotationScreens as $screenKey => $screenMeta):
            $fieldKey = 'PAGES_' . $screenKey;
            $pagesVal = current_val($rawConf, $board, $fieldKey);
            $pageRows = is_array($pagesVal) ? $pagesVal : [];
            $screenName = rotation_screen_display_name($screenKey, $rotationScreens);
            $deckId = 'rotationDeck-' . preg_replace('/[^a-z0-9_\-]/i', '', $screenKey);
            $effectivePages = rotation_screen_effective_pages($screenKey);
            $mirrorsMain = $pageRows === [] && $screenKey !== 'main' && rotation_screen_own_pages($screenKey) === [];
            $screenSettings = rotation_screen_settings($screenKey);
            $screenPresence = is_array($presenceAll[$screenKey] ?? null) ? $presenceAll[$screenKey] : null;
            $pageCount = rotation_playlist_counts($pageRows)['total'];
            $slideEntryCount = rotation_playlist_counts($pageRows)['slide_entries'];
            $activeEffective = count(rotation_effective_playlist_lines(
                array_values(array_filter($effectivePages, static fn($ep) => is_array($ep) && !empty($ep['url']) && empty($ep['off'])))
            ));
            if ($mirrorsMain) {
                $summaryNote = 'mirrors main (' . $activeEffective . ' page' . ($activeEffective === 1 ? '' : 's') . ')';
            } elseif ($pageCount === 0) {
                $summaryNote = 'empty';
            } else {
                $summaryNote = $pageCount . ' page' . ($pageCount === 1 ? '' : 's');
                if ($slideEntryCount > 0) {
                    $summaryNote .= ' · ' . $slideEntryCount . ' slide entr' . ($slideEntryCount === 1 ? 'y' : 'ies');
                }
            }
            $playlistOpen = $screenKey === 'main' && $pageCount <= 6;
          ?>
          <details class="panel rotation-playlist-panel" data-rotation-screen="<?= h($screenKey) ?>"<?= $playlistOpen ? ' open' : '' ?>>
            <summary>
              <span>Playlist — <?= h($screenName) ?></span>
              <code><?= h($screenKey) ?></code>
              <span class="rotation-summary-note"><?= h($summaryNote) ?></span>
              <?php if ($screenSettings['shuffle']): ?><span class="pill ok">Shuffle</span><?php else: ?><span class="pill">Sequential</span><?php endif; ?>
              <?php if (!empty($screenSettings['weighted'])): ?><span class="pill ok">Weighted</span><?php endif; ?>
              <?php if ($screenSettings['show_debug']): ?><span class="pill">Debug</span><?php endif; ?>
              <?php if ($screenSettings['schedule']['enabled']): ?><span class="pill ok">Blank <?= (int)$screenSettings['schedule']['off'] ?>→<?= (int)$screenSettings['schedule']['on'] ?></span><?php endif; ?>
              <?php if ($screenSettings['cec']['enabled']): ?><span class="pill">CEC</span><?php endif; ?>
              <span class="rotation-summary-actions">
                <a class="secondary" href="<?= h(rotation_screen_preview_url($screenKey)) ?>" target="_blank" rel="noopener"
                   onclick="event.stopPropagation()">Preview ↗</a>
              </span>
            </summary>
            <div class="panel-body">
          <div class="help" style="margin-bottom:8px">Drag <strong>⋮⋮</strong> to reorder. Expand a card for URL, dwell, and hour windows. Save — kiosks pick up changes within ~30s.</div>

          <div class="rotation-screen-tools">
            <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px"
               href="<?= h(rotation_screen_preview_url($screenKey)) ?>" target="_blank" rel="noopener">Preview ↗</a>
            <span class="help" style="margin:0"><code><?= h(rotation_screen_kiosk_url($screenKey)) ?></code></span>
            <?php if ($screenKey !== 'main'): ?>
            <button type="button" class="secondary" onclick="document.getElementById('rotationTargetScreen').value='<?= h($deckId) ?>'; copyRotationFromMain('<?= h($deckId) ?>')">Copy from main</button>
            <?php endif; ?>
          </div>

          <?php if ($effectivePages !== [] && $mirrorsMain): ?>
          <div class="rotation-effective-list mirror-note">Mirrors main — <?= (int)$activeEffective ?> page<?= $activeEffective === 1 ? '' : 's' ?> on wall</div>
          <?php endif; ?>

          <div class="rotation-playlist" id="<?= h($deckId) ?>" data-field="<?= h($fieldKey) ?>">
            <?php if ($pageRows === []): ?>
            <div class="rotation-playlist-empty" data-rotation-empty>No pages yet — quick-add a board above, load the starter playlist, or add a blank page.</div>
            <?php endif; ?>
            <?php $pri = 0;
            $playlistSegments = rotation_playlist_segments($pageRows);
            foreach ($playlistSegments as $segment):
              if (($segment['type'] ?? '') === 'slides'):
                $slideItems = $segment['items'] ?? [];
                $slideCount = count($slideItems);
                $legacyOnly = $slideCount === 1 && rotation_is_legacy_slides_url((string)($slideItems[0]['url'] ?? ''));
            ?>
            <details class="rotation-slides-group">
              <summary>
                <span class="drag-handle rotation-slides-group-handle" title="Drag slide block" draggable="true">⋮⋮</span>
                <strong><?= $legacyOnly ? 'Custom slides (legacy)' : 'Custom slides (' . (int)$slideCount . ')' ?></strong>
                <span class="help" style="margin:0">Managed from Custom Slides — deploy or save deck to sync dwell &amp; order</span>
                <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="?board=slides">Edit deck ↗</a>
              </summary>
              <div class="rotation-slides-group-body">
                <?php foreach ($slideItems as $item):
                  $prow = $item['row'];
                  $purl = (string)$item['url'];
                  if ($legacyOnly):
                ?>
                <div class="rotation-card rotation-card-legacy" data-rotation-card>
                  <div class="rotation-card-head">
                    <div class="rotation-card-title">
                      <strong>Legacy single entry</strong>
                      <code>slides.php</code>
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <span class="pill warn">Deploy from Custom Slides to split into per-slide entries</span>
                  </div>
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>">
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>">
                  <?php if (!empty($prow['from'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)$prow['from']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['to'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)$prow['to']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['off'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" value="1"><?php endif; ?>
                </div>
                <?php $pri++; continue; endif;
                  $slideFile = slide_rotation_parse_file($purl);
                  $slideMeta = $slideFile !== null ? slide_deck_by_file($slideFile, $rawConf['slides.SLIDES'] ?? null) : null;
                  $slideLabel = rotation_page_label($purl);
                ?>
                <div class="rotation-card rotation-card-slide" data-rotation-card>
                  <div class="rotation-card-head">
                    <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                    <div class="rotation-card-title">
                      <strong data-rotation-label><?= h($slideLabel) ?></strong>
                      <code data-rotation-url-display><?= h($purl) ?></code>
                    </div>
                  </div>
                  <div class="rotation-card-grid rotation-card-grid-compact">
                    <div style="grid-column:1 / -1">
                      <label class="mini">URL</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>" data-rotation-url readonly>
                    </div>
                    <div>
                      <label class="mini">Dwell (s)</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>" placeholder="12" readonly>
                    </div>
                    <div>
                      <label class="mini">From hr</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)($prow['from'] ?? '')) ?>" placeholder="0-23">
                    </div>
                <div>
                  <label class="mini">To hr</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)($prow['to'] ?? '')) ?>" placeholder="0-23">
                </div>
                <div>
                  <label class="mini">Weight</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][weight]" value="<?= h((string)($prow['weight'] ?? '')) ?>" placeholder="1" title="Used when Weighted is on for this display (1–20)">
                </div>
              </div>
              <div class="rotation-card-meta">
                <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip this slide</label>
                    <?php
                    $slideProof = signage_presence_page_proof_label($screenPresence, $purl);
                    if ($slideProof !== ''): ?>
                      <span class="pill play-proof" title="Proof of play (kiosk reported on screen)"><?= h($slideProof) ?></span>
                    <?php endif; ?>
                    <?php if (is_array($slideMeta)): ?>
                      <span><?= h(slide_schedule_summary($slideMeta)) ?></span>
                    <?php endif; ?>
                    <div class="rotation-card-actions">
                      <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h($purl) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                    </div>
                  </div>
                </div>
                <?php $pri++; endforeach; ?>
              </div>
            </details>
            <?php continue; endif;
              if (($segment['type'] ?? '') === 'photos'):
                $photoItems = $segment['items'] ?? [];
                $photoCount = count($photoItems);
                $legacyRotator = $photoCount === 1 && rotation_is_legacy_rotator_url((string)($photoItems[0]['url'] ?? ''));
            ?>
            <details class="rotation-slides-group">
              <summary>
                <span class="drag-handle rotation-slides-group-handle" title="Drag photo block" draggable="true">⋮⋮</span>
                <strong><?= $legacyRotator ? 'Photo rotator (legacy)' : 'Photos (' . (int)$photoCount . ')' ?></strong>
                <span class="help" style="margin:0">Managed from Photo Rotator — deploy or save deck to sync dwell &amp; order</span>
                <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="?board=rotator">Edit deck ↗</a>
              </summary>
              <div class="rotation-slides-group-body">
                <?php foreach ($photoItems as $item):
                  $prow = $item['row'];
                  $purl = (string)$item['url'];
                  if ($legacyRotator):
                ?>
                <div class="rotation-card rotation-card-legacy" data-rotation-card>
                  <div class="rotation-card-head">
                    <div class="rotation-card-title">
                      <strong>Legacy single entry</strong>
                      <code>rotator.php</code>
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <span class="pill warn">Switch deploy mode in Photo Rotator to split into per-photo entries</span>
                  </div>
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>">
                  <input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>">
                  <?php if (!empty($prow['from'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)$prow['from']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['to'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)$prow['to']) ?>"><?php endif; ?>
                  <?php if (!empty($prow['off'])): ?><input type="hidden" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" value="1"><?php endif; ?>
                </div>
                <?php $pri++; continue; endif;
                  $photoFile = rotator_rotation_parse_file($purl);
                  $photoMeta = $photoFile !== null ? rotator_deck_by_file($photoFile, $rawConf['rotator.PHOTOS'] ?? null) : null;
                  $photoLabel = rotation_page_label($purl);
                ?>
                <div class="rotation-card rotation-card-slide" data-rotation-card>
                  <div class="rotation-card-head">
                    <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                    <div class="rotation-card-title">
                      <strong data-rotation-label><?= h($photoLabel) ?></strong>
                      <code data-rotation-url-display><?= h($purl) ?></code>
                    </div>
                  </div>
                  <div class="rotation-card-grid rotation-card-grid-compact">
                    <div style="grid-column:1 / -1">
                      <label class="mini">URL</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>" data-rotation-url readonly>
                    </div>
                    <div>
                      <label class="mini">Dwell (s)</label>
                      <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>" placeholder="18" readonly>
                    </div>
                  </div>
                  <div class="rotation-card-meta">
                    <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip this photo</label>
                    <?php
                    $photoProof = signage_presence_page_proof_label($screenPresence, $purl);
                    if ($photoProof !== ''): ?>
                      <span class="pill play-proof" title="Proof of play"><?= h($photoProof) ?></span>
                    <?php endif; ?>
                    <?php if (is_array($photoMeta) && trim((string)($photoMeta['group'] ?? '')) !== ''): ?>
                      <span class="pill">Group: <?= h((string)$photoMeta['group']) ?></span>
                    <?php endif; ?>
                    <div class="rotation-card-actions">
                      <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h($purl) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                    </div>
                  </div>
                </div>
                <?php $pri++; endforeach; ?>
              </div>
            </details>
            <?php continue; endif;
              $prow = $segment['row'] ?? [];
              $purl = (string)($segment['url'] ?? '');
            ?>
            <div class="rotation-card" data-rotation-card>
              <div class="rotation-card-head">
                <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                <div class="rotation-card-title">
                  <strong data-rotation-label><?= h(rotation_page_label($purl)) ?></strong>
                  <code data-rotation-url-display><?= h($purl !== '' ? $purl : 'board URL') ?></code>
                </div>
                <div class="rotation-card-head-meta">
                  <label class="check" style="margin:0"><input type="checkbox" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][off]" <?= !empty($prow['off']) ? 'checked' : '' ?>> Skip</label>
                  <?php
                  $pageProof = signage_presence_page_proof_label($screenPresence, $purl);
                  if ($pageProof !== ''): ?>
                    <span class="pill play-proof" title="Proof of play"><?= h($pageProof) ?></span>
                  <?php endif; ?>
                  <?php if ($purl !== ''): ?>
                  <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="<?= h($purl) ?>" target="_blank" rel="noopener" data-rotation-preview>Preview</a>
                  <?php endif; ?>
                  <button type="button" class="rowdel" onclick="removeRotationCard(this, '<?= h($deckId) ?>')" title="Remove">×</button>
                </div>
              </div>
              <?php
              $dwellShow = trim((string)($prow['dwell'] ?? ''));
              $weightShow = trim((string)($prow['weight'] ?? ''));
              $editSummary = 'Dwell ' . ($dwellShow !== '' ? $dwellShow : '60') . 's';
              if ($weightShow !== '' && (int)$weightShow > 1) {
                  $editSummary .= ' · weight ' . (int)$weightShow;
              }
              if (!empty($prow['from']) || !empty($prow['to'])) {
                  $editSummary .= ' · hrs';
              }
              ?>
              <details class="rotation-card-edit">
                <summary><?= h($editSummary) ?></summary>
              <div class="rotation-card-grid">
                <div style="grid-column:1 / -1">
                  <label class="mini">URL</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][url]" value="<?= h($purl) ?>"
                         placeholder="index.php or rss.php?feed=ars" data-rotation-url required>
                </div>
                <div>
                  <label class="mini">Dwell (s)</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][dwell]" value="<?= h((string)($prow['dwell'] ?? '')) ?>" placeholder="60">
                </div>
                <div>
                  <label class="mini">From hr</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][from]" value="<?= h((string)($prow['from'] ?? '')) ?>" placeholder="0-23">
                </div>
                <div>
                  <label class="mini">To hr</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][to]" value="<?= h((string)($prow['to'] ?? '')) ?>" placeholder="0-23">
                </div>
                <div>
                  <label class="mini">Weight</label>
                  <input type="text" name="<?= h($fieldKey) ?>[<?= (int)$pri ?>][weight]" value="<?= h((string)($prow['weight'] ?? '')) ?>" placeholder="1" title="Used when Weighted is on for this display (1–20)">
                </div>
              </div>
              </details>
            </div>
            <?php $pri++; endforeach; ?>
          </div>
            </div>
          </details>
          <?php endforeach; ?>

          <?php if (admin_is_super()): ?>
          <details class="panel panel-muted" style="margin-top:22px">
            <summary>Transition settings</summary>
            <div class="panel-body">
              <div class="field-grid">
                <?php foreach ($b['fields'] as $f):
                  if (!in_array($f['key'], $rotationBoardKeys, true)) continue;
                  $val = current_val($rawConf, $board, $f['key']); ?>
                  <div class="field"><?php admin_field($f, $val, $board); ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>
          <?php endif; ?>

        <?php elseif ($board === 'video'):
          $videoVal = current_val($rawConf, $board, 'VIDEOS');
          $videoVal = is_array($videoVal) ? admin_filter_owned_map($videoVal) : [];
          $videoRows = is_array($videoVal) ? $videoVal : [];
          $mutedVal = current_val($rawConf, $board, 'MUTED');
        ?>
          <div class="field" style="margin-bottom:18px;padding:14px 16px;border:1px solid var(--line);border-radius:10px;background:var(--harbor)">
            <label class="check"><input type="checkbox" name="MUTED"
              <?= ($mutedVal ?? true) ? 'checked' : '' ?>> Mute all videos</label>
            <div class="help" style="margin-top:8px;margin-bottom:0">Leave checked for silent wall displays. Uncheck to play audio —
              Pi/kiosk boxes must be set up with <code>setup-kiosk.sh</code> (autoplay policy is already included).</div>
          </div>

          <div class="section-title">Video playlist</div>
          <div class="help" style="margin-bottom:12px">Drag cards to set play order (top = first). Each entry needs a unique <strong>Key</strong>
            and either a YouTube URL or a local filename in <code>videos/</code>. After saving, videos appear on the wall only when
            listed in <strong>Admin → Rotation</strong> as <code>video.php?v=KEY</code> — or check the box below to add them automatically.</div>

          <div class="video-playlist" id="videoPlaylist" data-field="VIDEOS">
            <?php $vri = 0; foreach ($videoRows as $vk => $row):
              if (!is_array($row)) $row = [];
              $st = $videoStatusByKey[$vk] ?? null;
              $label = trim((string)($row['title'] ?? ''));
              if ($label === '') $label = (string)$vk;
            ?>
            <div class="video-card" data-video-card>
              <div class="video-card-head">
                <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                <div class="video-card-title">
                  <strong><?= h($label) ?></strong>
                  <code><?= h(video_rotation_url($vk)) ?></code>
                </div>
                <button type="button" class="rowdel" onclick="this.closest('[data-video-card]').remove(); reindexVideoPlaylist();" title="Remove">×</button>
              </div>
              <div class="video-card-grid">
                <div>
                  <label class="mini">Key</label>
                  <input type="text" name="VIDEOS[<?= (int)$vri ?>][_key]" value="<?= h((string)$vk) ?>" placeholder="lantern" required data-video-key>
                </div>
                <div>
                  <label class="mini">Title (optional)</label>
                  <input type="text" name="VIDEOS[<?= (int)$vri ?>][title]" value="<?= h((string)($row['title'] ?? '')) ?>" placeholder="On-screen title" data-video-title>
                </div>
                <div>
                  <label class="mini">YouTube URL</label>
                  <input type="text" name="VIDEOS[<?= (int)$vri ?>][youtube]" value="<?= h((string)($row['youtube'] ?? '')) ?>" placeholder="https://youtube.com/watch?v=…">
                </div>
                <div style="grid-column:2 / -1">
                  <label class="mini">or local file</label>
                  <input type="text" name="VIDEOS[<?= (int)$vri ?>][file]" value="<?= h((string)($row['file'] ?? '')) ?>" placeholder="lantern.mp4 in videos/">
                </div>
              </div>
              <?php admin_entry_sharing_html('VIDEOS[' . (int)$vri . ']', $row); ?>
              <div class="video-card-meta">
                <?php if ($st): ?>
                  <?php if ($st['file']): ?>
                    <span>File: <code><?= h($st['file']) ?></code></span>
                  <?php else: ?>
                    <span>Not downloaded yet</span>
                  <?php endif; ?>
                  <?php if ($st['duration_label']): ?>
                    <span>Length: <?= h($st['duration_label']) ?></span>
                    <span>Dwell: <?= h((string)$st['rotation_dwell']) ?> s</span>
                  <?php endif; ?>
                  <?php if ($st['in_rotation']): ?>
                    <span class="pill ok">On main rotation</span>
                  <?php else: ?>
                    <span class="pill warn">Not on rotation</span>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="video-card-actions">
                  <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h(video_rotation_url($vk)) ?>" target="_blank" rel="noopener">Preview</a>
                  <?php if ($st && $st['fetchable']): ?>
                    <button type="submit" class="secondary" form="video-fetch-<?= h(preg_replace('/[^a-z0-9_\-]/i', '', $vk)) ?>">Fetch</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php $vri++; endforeach; ?>
          </div>
          <button type="button" class="addrow" style="margin-top:12px" onclick="addVideoCard()">+ Add video</button>

          <?php
          $videoDeployScreens = admin_filter_screens(rotation_screens());
          $videoDeployChecked = [];
          if (isset($videoDeployScreens['main']) && admin_can_screen('main')) {
              $videoDeployChecked[] = 'main';
          } elseif ($videoDeployChecked === []) {
              $videoDeployChecked = admin_default_deploy_screens();
          }
          ?>
          <div class="actions deploy-save-screens" style="margin-top:18px;margin-bottom:0;padding-top:16px;border-top:1px solid var(--line)">
            <span class="help" style="margin:0;width:100%">On Save, add playlist to rotation on:</span>
            <?php admin_deploy_picker_from_screens($videoDeployScreens, $videoDeployChecked); ?>
            <span class="help" style="margin:8px 0 0">Adds <code>video.php?v=KEY</code> entries with dwell from each video length. Leave all unchecked to skip rotation sync.</span>
          </div>

          <details class="panel panel-muted" style="margin-top:22px">
            <summary>Board settings</summary>
            <div class="panel-body">
              <div class="field-grid">
                <?php foreach ($b['fields'] as $f):
                  if ($f['key'] === 'VIDEOS' || !in_array($f['key'], $videoBoardKeys, true)) continue;
                  $val = current_val($rawConf, $board, $f['key']); ?>
                  <div class="field"><?php admin_field($f, $val, $board); ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

        <?php elseif ($board === 'splunk'): ?>
          <div class="section-title">Splunk panel pages</div>
          <div class="help" style="margin-bottom:4px">Each page is its own 1080p wall — add them separately to rotation as
            <code>splunk.php?d=<em>key</em></code> (like Grafana). Drag panels to reorder within a page.</div>

          <div class="splunk-pages-bar" id="splunkPagesBar">
            <?php foreach ($splunkPages as $pk => $pg): ?>
            <button type="button" class="splunk-page-tab<?= $pk === $splunkActivePage ? ' active' : '' ?>"
                    data-splunk-page-tab="<?= h($pk) ?>">
              <?= h((string)($pg['title'] ?? $pk)) ?><code><?= h($pk) ?></code>
            </button>
            <?php endforeach; ?>
            <button type="button" class="addrow" onclick="addSplunkPage()">+ Add page</button>
          </div>

          <?php foreach ($splunkPages as $pk => $pg):
            $panelRows = is_array($pg['panels'] ?? null) ? $pg['panels'] : [];
          ?>
          <div class="splunk-page-editor" data-splunk-page-editor="<?= h($pk) ?>"
               style="<?= $pk === $splunkActivePage ? '' : 'display:none' ?>">
            <input type="hidden" name="PAGES[<?= h($pk) ?>][_key]" value="<?= h($pk) ?>" data-splunk-page-key>
            <div class="splunk-page-head">
              <div>
                <label class="mini">Page title</label>
                <input type="text" name="PAGES[<?= h($pk) ?>][title]" value="<?= h((string)($pg['title'] ?? '')) ?>"
                       placeholder="SOC Overview" data-splunk-page-title>
              </div>
              <div>
                <label class="mini">Subtitle</label>
                <input type="text" name="PAGES[<?= h($pk) ?>][sub]" value="<?= h((string)($pg['sub'] ?? '')) ?>"
                       placeholder="Home network" data-splunk-page-sub>
              </div>
              <div style="display:flex;gap:10px;align-items:center;padding-bottom:4px">
                <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px;white-space:nowrap"
                   href="<?= h(splunk_preview_url($pk)) ?>" target="_blank" rel="noopener" data-splunk-page-preview>Preview ↗</a>
                <?php if (count($splunkPages) > 1): ?>
                <button type="button" class="rowdel" style="width:auto;padding:6px 12px;font-size:13px"
                        onclick="removeSplunkPage('<?= h($pk) ?>')" title="Remove page">Remove page</button>
                <?php endif; ?>
              </div>
            </div>
            <?php admin_entry_sharing_html('PAGES[' . $pk . ']', $pg); ?>
            <div class="help" style="margin-bottom:10px">Rotation URL: <code><?= h(splunk_page_url($pk)) ?></code></div>

            <div class="splunk-playlist video-playlist" data-splunk-panels-deck="<?= h($pk) ?>">
              <?php if ($panelRows === []): ?>
              <div class="rotation-playlist-empty">No panels yet — add one below.</div>
              <?php endif; ?>
              <?php foreach ($panelRows as $spi => $row):
                if (!is_array($row)) continue;
                splunk_admin_panel_card($pk, (int)$spi, $row);
              endforeach; ?>
            </div>
            <button type="button" class="addrow" style="margin-top:12px" onclick="addSplunkPanelCard('<?= h($pk) ?>')">+ Add panel</button>
          </div>
          <?php endforeach; ?>

          <details class="panel panel-muted" style="margin-top:22px"<?= admin_is_super() ? '' : ' hidden' ?>>
            <summary>Advanced — paste JSON</summary>
            <div class="panel-body">
              <label class="check"><input type="checkbox" name="splunk_use_json"> Replace all pages from JSON on save (ignores cards above)</label>
              <div class="help" style="margin:10px 0">Keyed object: <code>{"soc":{"title":"…","panels":[…]}}</code>.
                A legacy panel array becomes the <code>main</code> page.</div>
              <textarea name="PAGES_JSON" spellcheck="false" style="width:100%;min-height:220px;font-family:'IBM Plex Mono',monospace;font-size:13px"><?=
                h(json_encode($splunkPages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
              ?></textarea>
            </div>
          </details>

          <details class="panel panel-muted" style="margin-top:22px"<?= admin_can_board_settings('splunk') ? '' : ' hidden' ?>>
            <summary>Board settings</summary>
            <div class="panel-body">
              <div class="field-grid">
                <?php foreach ($b['fields'] as $f):
                  if (!in_array($f['key'], $splunkBoardKeys, true)) continue;
                  $val = current_val($rawConf, $board, $f['key']); ?>
                  <div class="field"><?php admin_field($f, $val, $board); ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

        <?php elseif ($board === 'rotator'):
          $rotationScreens = admin_filter_screens(rotation_screens());
          $photoVal = current_val($rawConf, $board, 'PHOTOS');
          $photoRows = admin_filter_owned_list(is_array($photoVal) ? $photoVal : []);
          $rotatorTab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'deck'));
          if (!in_array($rotatorTab, ['deck', 'library', 'upload', 'deploy'], true)) {
              $rotatorTab = 'deck';
          }
          if ($photoHighlight !== null) {
              $rotatorTab = 'deck';
          }
          $deployMode = rotator_deploy_mode();
        ?>
          <div class="admin-tabs" id="photosTabs" role="tablist">
            <button type="button" class="admin-tab<?= $rotatorTab === 'deck' ? ' active' : '' ?>" data-tab="deck">Deck<span class="tab-count"><?= count($photoRows) ?></span></button>
            <button type="button" class="admin-tab<?= $rotatorTab === 'library' ? ' active' : '' ?>" data-tab="library">Library<span class="tab-count"><?= count($rotatorLibrary) ?></span></button>
            <button type="button" class="admin-tab<?= $rotatorTab === 'upload' ? ' active' : '' ?>" data-tab="upload">Upload</button>
            <button type="button" class="admin-tab<?= $rotatorTab === 'deploy' ? ' active' : '' ?>" data-tab="deploy">Deploy</button>
          </div>

          <div class="admin-tab-panel<?= $rotatorTab === 'deck' ? ' active' : '' ?>" data-tab-panel="deck" id="photo-deck-panel">
          <div class="help" style="margin-bottom:12px">Drag to reorder. Set <strong>Sec</strong> and optional <strong>Group</strong> (for group deploy mode). Target specific displays per photo, then deploy from the <strong>Deploy</strong> tab.</div>
          <?php if ($photoHighlight !== null): ?>
          <div class="slide-added-notice">Added <code><?= h($photoHighlight) ?></code> to the deck — review settings, then Save.</div>
          <?php endif; ?>
          <div class="deck-list" id="photoDeck" data-field="PHOTOS">
            <?php if ($photoRows === []): ?>
            <div class="slide-deck-empty">No photos in the deck yet — use <strong>Upload</strong> or add from <strong>Library</strong>.</div>
            <?php endif; ?>
            <?php foreach ($photoRows as $ri => $row):
              if (!is_array($row)) continue;
              $fileLabel = (string)($row['file'] ?? 'New photo');
              $fileOk = rotator_safe_filename($fileLabel) !== null && is_file(rotator_photo_dir() . '/' . rotator_safe_filename($fileLabel));
              $highlightCard = $photoHighlight !== null
                  && rotator_safe_filename($fileLabel) === $photoHighlight;
              $thumbUrl = $fileOk ? rotator_thumb_url($fileLabel) : null;
              $previewUrl = $fileOk ? rotator_preview_url($fileLabel) : null;
              $displayLabel = rotator_display_label($fileLabel, $photoRows);
              $photoScreens = array_key_exists('screens', $row) ? rotator_target_screens($row) : [];
              $photoAllScreens = !array_key_exists('screens', $row);
              $groupLabel = trim((string)($row['group'] ?? ''));
            ?>
            <div class="slide-card<?= !empty($row['off']) ? ' is-off' : '' ?><?= $highlightCard ? ' slide-card-highlight' : '' ?>" data-photo-card data-photo-file="<?= h($fileLabel) ?>">
              <div class="slide-card-head slide-card-head-with-thumb">
                <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                <?php if ($thumbUrl): ?>
                <a href="<?= h($previewUrl ?? $thumbUrl) ?>" target="_blank" rel="noopener" title="Preview photo">
                  <img class="slide-card-thumb" src="<?= h($thumbUrl) ?>" alt="">
                </a>
                <?php endif; ?>
                <div class="slide-card-title">
                  <strong><?= h($displayLabel !== '' ? $displayLabel : 'New photo') ?></strong>
                  <?php if ($displayLabel !== $fileLabel && $fileLabel !== ''): ?>
                  <code style="font-size:12px;color:var(--mist)"><?= h($fileLabel) ?></code>
                  <?php endif; ?>
                  <span class="slide-card-meta-line">
                    <?php if (!$fileOk && $fileLabel !== ''): ?><span class="pill warn">File missing</span><?php endif; ?>
                    <?php if ($groupLabel !== ''): ?><span class="pill">Group: <?= h($groupLabel) ?></span><?php endif; ?>
                  </span>
                </div>
                <button type="button" class="rowdel" onclick="this.closest('[data-photo-card]').remove(); reindexPhotoDeck();" title="Remove from deck (file stays in library)">×</button>
              </div>
              <div class="slide-card-quick">
                <div>
                  <label class="mini">Sec</label>
                  <input type="text" name="PHOTOS[<?= h((string)$ri) ?>][dwell]" value="<?= h((string)($row['dwell'] ?? '')) ?>" placeholder="<?= h((string)rotator_default_dwell()) ?>">
                </div>
                <div>
                  <label class="mini">Group</label>
                  <input type="text" name="PHOTOS[<?= h((string)$ri) ?>][group]" value="<?= h($groupLabel) ?>" placeholder="travel">
                </div>
              </div>
              <details class="slide-card-edit">
                <summary>Options &amp; displays</summary>
              <div class="slide-card-grid">
                <div class="span-2">
                  <label class="mini">Image file</label>
                  <input type="text" name="PHOTOS[<?= h((string)$ri) ?>][file]" value="<?= h($fileLabel) ?>" placeholder="filename.jpg" list="photo-file-options">
                </div>
                <div class="span-3">
                  <label class="mini">Label</label>
                  <input type="text" name="PHOTOS[<?= h((string)$ri) ?>][caption]" value="<?= h((string)($row['caption'] ?? '')) ?>" placeholder="Admin label only">
                </div>
              </div>
                <div class="slide-card-flags">
                  <label><input type="checkbox" name="PHOTOS[<?= h((string)$ri) ?>][off]" <?= !empty($row['off']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <?php if (count($rotationScreens) > 0):
                  $photoPickerChecked = $photoAllScreens
                      ? array_keys($rotationScreens)
                      : $photoScreens;
                ?>
                <div class="slide-card-screens">
                  <span class="mini">Show on displays</span>
                  <?php admin_screen_picker('PHOTOS[' . (int)$ri . ']', admin_screen_options($rotationScreens), $photoPickerChecked, [
                      'form_marker' => true,
                      'summary_mode' => 'deck',
                  ]); ?>
                </div>
                <?php endif; ?>
                <?php admin_entry_sharing_html('PHOTOS[' . (int)$ri . ']', $row); ?>
              </details>
              <?php if ($fileOk):
                $deleteFile = rotator_safe_filename($fileLabel);
              ?>
              <div class="slide-card-actions">
                <?php if ($previewUrl): ?>
                <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Preview ↗</a>
                <?php endif; ?>
                <?php if ($deleteFile !== null): ?>
                <button type="button" class="secondary photo-delete-btn" style="padding:6px 12px;font-size:13px"
                        data-delete-file="<?= h($deleteFile) ?>">Delete file</button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($rotatorLibrary !== []): ?>
          <datalist id="photo-file-options">
            <?php foreach ($rotatorLibrary as $lib): ?>
            <option value="<?= h($lib['file']) ?>"><?= h($lib['label']) ?></option>
            <?php endforeach; ?>
          </datalist>
          <?php endif; ?>
          <button type="button" class="addrow" style="margin-top:12px" onclick="addPhotoCard()">+ Add blank deck row</button>
          <?php
          $photoScreenOptionsJs = [];
          foreach ($rotationScreens as $sk => $sm) {
              $photoScreenOptionsJs[] = ['key' => $sk, 'name' => (string)($sm['name'] ?? $sk)];
          }
          ?>
          <script>window.PHOTO_SCREEN_OPTIONS = <?= json_encode($photoScreenOptionsJs, JSON_UNESCAPED_UNICODE) ?>;</script>
          </div>

          <div class="admin-tab-panel<?= $rotatorTab === 'library' ? ' active' : '' ?>" data-tab-panel="library" id="photo-library-panel">
              <div class="help" style="margin-bottom:8px">All images in <code>photos/</code>. Thumbnails show what is on disk — add orphans to the deck or delete here.</div>
              <?php if ($rotatorLibrary === []): ?>
              <div class="slide-deck-empty">No photo files yet — use <strong>Upload</strong>.</div>
              <?php else: ?>
              <div class="slide-library-grid">
                <?php foreach ($rotatorLibrary as $lib): ?>
                <div class="slide-library-tile<?= !$lib['in_deck'] ? ' not-in-deck' : '' ?>">
                  <?php if ($lib['thumb']): ?>
                  <a href="<?= h($lib['preview'] ?? $lib['thumb']) ?>" target="_blank" rel="noopener">
                    <img src="<?= h($lib['thumb']) ?>" alt="" loading="lazy">
                  </a>
                  <?php endif; ?>
                  <div class="slide-library-tile-body">
                    <strong><?= h($lib['label']) ?></strong>
                    <?php if ($lib['label'] !== $lib['file']): ?><code><?= h($lib['file']) ?></code><?php endif; ?>
                    <div>
                      <?php if ($lib['in_deck']): ?>
                        <span class="pill ok">In deck</span>
                        <?php if ($lib['off']): ?><span class="pill">Disabled</span><?php endif; ?>
                        <?php if ($lib['group'] !== ''): ?><span class="pill"><?= h($lib['group']) ?></span><?php endif; ?>
                      <?php else: ?>
                        <span class="pill warn">Not in deck</span>
                      <?php endif; ?>
                    </div>
                    <div class="slide-library-tile-actions">
                      <?php if ($lib['preview']): ?>
                      <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="<?= h($lib['preview']) ?>" target="_blank" rel="noopener">Preview</a>
                      <?php endif; ?>
                      <?php if (!$lib['in_deck']): ?>
                      <button type="button" class="secondary photo-add-btn" data-add-file="<?= h($lib['file']) ?>">Add to deck</button>
                      <?php endif; ?>
                      <button type="button" class="secondary photo-delete-btn" data-delete-file="<?= h($lib['file']) ?>">Delete</button>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
          </div>

          <div class="slides-form-footer slides-save-deploy">
            <?php
            $rotatorDeployPickerChecked = [];
            foreach ($rotatorDeployStatus as $screenKey => $dep) {
                if ($dep['on_playlist'] || $screenKey === 'main') {
                    $rotatorDeployPickerChecked[] = $screenKey;
                }
            }
            ?>
            <div class="deploy-checks">
              <span class="help" style="margin:0;width:100%">On Save, update rotation on:</span>
              <?php admin_deploy_picker_from_status($rotatorDeployStatus, $rotatorDeployPickerChecked); ?>
            </div>
          </div>

          <details class="panel panel-muted" style="margin-top:8px">
            <summary>Photo rotator settings</summary>
            <div class="panel-body">
              <div class="help" style="margin-bottom:10px">Deploy mode <strong><?= h($deployMode) ?></strong>:
                <?php if ($deployMode === 'individual'): ?>one rotation entry per photo (<code>rotator.php?photo=…</code>)
                <?php elseif ($deployMode === 'groups'): ?>grouped entries (<code>rotator.php?group=…</code>) plus ungrouped singles
                <?php else: ?>single legacy page (<code>rotator.php</code>)<?php endif; ?></div>
              <div class="field-grid">
                <?php foreach ($b['fields'] as $f):
                  if ($f['key'] === 'PHOTOS' || !in_array($f['key'], $rotatorBoardKeys, true)) continue;
                  $val = current_val($rawConf, $board, $f['key']); ?>
                  <div class="field"><?php admin_field($f, $val, $board); ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>

        <?php elseif ($board === 'slides'):
          $rotationScreens = admin_filter_screens(rotation_screens());
          $slideVal = current_val($rawConf, $board, 'SLIDES');
          $slideRows = admin_filter_owned_list(is_array($slideVal) ? $slideVal : []);
          $slideNow = new DateTime('now', new DateTimeZone(slides_timezone()));
          $slidesTab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'deck'));
          if (!in_array($slidesTab, ['deck', 'library', 'deploy', 'create'], true)) {
              $slidesTab = 'deck';
          }
          if ($slideHighlight !== null) {
              $slidesTab = 'deck';
          }
        ?>
          <div class="admin-tabs" id="slidesTabs" role="tablist">
            <button type="button" class="admin-tab<?= $slidesTab === 'deck' ? ' active' : '' ?>" data-tab="deck">Deck<span class="tab-count"><?= count($slideRows) ?></span></button>
            <button type="button" class="admin-tab<?= $slidesTab === 'library' ? ' active' : '' ?>" data-tab="library">Library<span class="tab-count"><?= count($slidesLibrary) ?></span></button>
            <button type="button" class="admin-tab<?= $slidesTab === 'create' ? ' active' : '' ?>" data-tab="create">Add / Create</button>
            <button type="button" class="admin-tab<?= $slidesTab === 'deploy' ? ' active' : '' ?>" data-tab="deploy">Deploy</button>
          </div>

          <div class="admin-tab-panel<?= $slidesTab === 'deck' ? ' active' : '' ?>" data-tab-panel="deck" id="slide-deck-panel">
          <div class="help" style="margin-bottom:12px">Drag to reorder. Set <strong>Sec</strong> on each card; expand for schedule and display targeting. New images go on <strong>Add / Create</strong>; push to TVs on <strong>Deploy</strong>.</div>
          <?php if ($slideHighlight !== null): ?>
          <div class="slide-added-notice">Added <code><?= h($slideHighlight) ?></code> to the deck — review schedule, then Save.</div>
          <?php endif; ?>
          <div class="deck-list" id="slideDeck" data-field="SLIDES">
            <?php if ($slideRows === []): ?>
            <div class="slide-deck-empty">No slides yet — use <strong>Add / Create</strong> to upload or design one.</div>
            <?php endif; ?>
            <?php foreach ($slideRows as $ri => $row):
              if (!is_array($row)) continue;
              $sched = strtolower((string)($row['schedule'] ?? 'always'));
              if ($sched === '') $sched = 'always';
              $fileLabel = (string)($row['file'] ?? 'New slide');
              $fileOk = slide_safe_filename($fileLabel) !== null && is_file(slides_dir() . '/' . slide_safe_filename($fileLabel));
              $activeNow = empty($row['off']) && $fileOk && slide_schedule_active($row, $slideNow);
              $schedSummary = slide_schedule_summary($row);
              $highlightCard = $slideHighlight !== null
                  && slide_safe_filename($fileLabel) === $slideHighlight;
              $thumbUrl = $fileOk ? slide_thumb_url($fileLabel) : null;
              $previewUrl = $fileOk ? slide_preview_url($fileLabel) : null;
              $displayLabel = slide_display_label($fileLabel, $slideRows);
              $slideScreens = array_key_exists('screens', $row) ? slide_target_screens($row) : [];
              $slideAllScreens = !array_key_exists('screens', $row);
            ?>
            <div class="slide-card<?= !empty($row['off']) ? ' is-off' : '' ?><?= $highlightCard ? ' slide-card-highlight' : '' ?>" data-slide-card data-slide-file="<?= h($fileLabel) ?>">
              <div class="slide-card-head slide-card-head-with-thumb">
                <span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>
                <?php if ($thumbUrl): ?>
                <a href="<?= h($previewUrl ?? $thumbUrl) ?>" target="_blank" rel="noopener" title="Preview slide">
                  <img class="slide-card-thumb" src="<?= h($thumbUrl) ?>" alt="">
                </a>
                <?php endif; ?>
                <div class="slide-card-title">
                  <strong><?= h($displayLabel !== '' ? $displayLabel : 'New slide') ?></strong>
                  <?php if ($displayLabel !== $fileLabel && $fileLabel !== ''): ?>
                  <code style="font-size:12px;color:var(--mist)"><?= h($fileLabel) ?></code>
                  <?php endif; ?>
                  <span class="slide-card-meta-line">
                    <?php if ($activeNow): ?><span class="pill ok">Active now</span><?php endif; ?>
                    <?php if (!$fileOk && $fileLabel !== ''): ?><span class="pill warn">File missing</span><?php endif; ?>
                    <span class="schedule-summary" data-schedule-summary><?= h($schedSummary) ?></span>
                  </span>
                </div>
                <button type="button" class="rowdel" onclick="this.closest('[data-slide-card]').remove(); reindexSlideDeck();" title="Remove from deck (file stays in library)">×</button>
              </div>
              <div class="slide-card-quick">
                <div>
                  <label class="mini">Sec</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][dwell]" value="<?= h((string)($row['dwell'] ?? '')) ?>" placeholder="12">
                </div>
              </div>
              <details class="slide-card-edit">
                <summary>Schedule &amp; options</summary>
              <div class="slide-card-grid">
                <div class="span-2">
                  <label class="mini">Image file</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][file]" value="<?= h((string)($row['file'] ?? '')) ?>" placeholder="filename.png" list="slide-file-options">
                </div>
                <div class="span-3">
                  <label class="mini">Label</label>
                  <input type="text" name="SLIDES[<?= h((string)$ri) ?>][caption]" value="<?= h((string)($row['caption'] ?? '')) ?>" placeholder="Admin label only (not shown on wall)">
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
                <?php if (count($rotationScreens) > 0):
                  $slidePickerChecked = $slideAllScreens
                      ? array_keys($rotationScreens)
                      : $slideScreens;
                ?>
                <div class="slide-card-screens">
                  <span class="mini">Show on displays</span>
                  <?php admin_screen_picker('SLIDES[' . (int)$ri . ']', admin_screen_options($rotationScreens), $slidePickerChecked, [
                      'form_marker' => true,
                      'summary_mode' => 'deck',
                  ]); ?>
                </div>
                <?php endif; ?>
                <?php admin_entry_sharing_html('SLIDES[' . (int)$ri . ']', $row); ?>
              </details>
              <?php if ($fileOk):
                $deleteFile = slide_safe_filename($fileLabel);
              ?>
              <div class="slide-card-actions">
                <?php if ($previewUrl): ?>
                <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Preview ↗</a>
                <?php endif; ?>
                <?php if ($deleteFile !== null): ?>
                <button type="button" class="secondary slide-replace-btn" style="padding:6px 12px;font-size:13px"
                        data-replace-file="<?= h($deleteFile) ?>">Replace image</button>
                <button type="button" class="secondary slide-delete-btn" style="padding:6px 12px;font-size:13px"
                        data-delete-file="<?= h($deleteFile) ?>">Delete file</button>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($slidesLibrary !== []): ?>
          <datalist id="slide-file-options">
            <?php foreach ($slidesLibrary as $lib): ?>
            <option value="<?= h($lib['file']) ?>"><?= h($lib['label']) ?></option>
            <?php endforeach; ?>
          </datalist>
          <?php endif; ?>
          <button type="button" class="addrow" style="margin-top:12px" onclick="addSlideCard()">+ Add blank deck row</button>
          <?php
          $slideScreenOptionsJs = [];
          foreach ($rotationScreens as $sk => $sm) {
              $slideScreenOptionsJs[] = ['key' => $sk, 'name' => (string)($sm['name'] ?? $sk)];
          }
          ?>
          <script>window.SLIDE_SCREEN_OPTIONS = <?= json_encode($slideScreenOptionsJs, JSON_UNESCAPED_UNICODE) ?>;</script>
          </div>

          <div class="admin-tab-panel<?= $slidesTab === 'library' ? ' active' : '' ?>" data-tab-panel="library" id="slide-library-panel">
              <div class="help" style="margin-bottom:8px">All images in <code>slides/</code>. Add orphans back to the deck or delete files here.</div>
              <?php if ($slidesLibrary === []): ?>
              <div class="slide-deck-empty">No slide files yet — use <strong>Add / Create</strong>.</div>
              <?php else: ?>
              <div class="slide-library-grid">
                <?php foreach ($slidesLibrary as $lib): ?>
                <div class="slide-library-tile<?= !$lib['in_deck'] ? ' not-in-deck' : '' ?>">
                  <?php if ($lib['thumb']): ?>
                  <a href="<?= h($lib['preview'] ?? $lib['thumb']) ?>" target="_blank" rel="noopener">
                    <img src="<?= h($lib['thumb']) ?>" alt="" loading="lazy">
                  </a>
                  <?php endif; ?>
                  <div class="slide-library-tile-body">
                    <strong><?= h($lib['label']) ?></strong>
                    <?php if ($lib['label'] !== $lib['file']): ?><code><?= h($lib['file']) ?></code><?php endif; ?>
                    <div>
                      <?php if ($lib['in_deck']): ?>
                        <span class="pill ok">In deck</span>
                        <?php if ($lib['off']): ?><span class="pill">Disabled</span><?php endif; ?>
                      <?php else: ?>
                        <span class="pill warn">Not in deck</span>
                      <?php endif; ?>
                    </div>
                    <div class="slide-library-tile-actions">
                      <?php if ($lib['preview']): ?>
                      <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="<?= h($lib['preview']) ?>" target="_blank" rel="noopener">Preview</a>
                      <?php endif; ?>
                      <button type="button" class="secondary slide-replace-btn" data-replace-file="<?= h($lib['file']) ?>">Replace</button>
                      <?php if (!$lib['in_deck']): ?>
                      <button type="button" class="secondary slide-add-btn" data-add-file="<?= h($lib['file']) ?>">Add to deck</button>
                      <?php endif; ?>
                      <button type="button" class="secondary slide-delete-btn" data-delete-file="<?= h($lib['file']) ?>">Delete</button>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
          </div>

          <div class="slides-form-footer slides-save-deploy">
            <?php
            $slidesDeployPickerChecked = [];
            foreach ($slidesDeployStatus as $screenKey => $dep) {
                if ($dep['on_playlist'] || $screenKey === 'main') {
                    $slidesDeployPickerChecked[] = $screenKey;
                }
            }
            ?>
            <div class="deploy-checks">
              <span class="help" style="margin:0;width:100%">On Save, update rotation on:</span>
              <?php admin_deploy_picker_from_status($slidesDeployStatus, $slidesDeployPickerChecked); ?>
            </div>
          </div>

          <details class="panel panel-muted" style="margin-top:8px">
            <summary>Slide board settings</summary>
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
          if (!admin_can_board_settings($board) && $f['type'] !== 'rows') {
              continue;
          }
          $val = current_val($rawConf, $board, $f['key']); ?>
          <div class="field">
            <?php if ($f['type'] === 'rows'):
              $cols = $f['columns'];
              $rows = [];
              if (is_array($val)) {
                  if (!empty($f['keyed'])) {
                      if (!admin_is_super()) {
                          $val = !empty($f['scalar'])
                              ? admin_filter_owned_scalar_map($val)
                              : admin_filter_owned_map($val);
                      }
                      foreach ($val as $rk => $rv) {
                          if (!empty($f['scalar'])) {
                              $rows[] = ['_key' => $rk, '_value' => admin_owned_scalar_value($rv)];
                          } else {
                              $first = null;
                              foreach ($cols as $c) if ($c['key'] !== '_key') { $first = $c['key']; break; }
                              $rows[] = ['_key' => $rk] + (is_array($rv) ? $rv : ($first ? [$first => $rv] : []));
                          }
                      }
                  } else {
                      $rows = admin_filter_owned_list($val);
                  }
              }
              $hasKeyCol = false;
              foreach ($cols as $c) {
                  if (($c['key'] ?? '') === 'key') {
                      $hasKeyCol = true;
                      break;
                  }
              }
            ?>
              <label class="l"><?= h($f['label']) ?></label>
              <div class="rows-scroll">
              <table class="rows" data-field="<?= h($f['key']) ?>"<?php
                if ($board === 'grafana' && $f['key'] === 'DASHBOARDS') {
                    echo ' data-preview-script="grafana.php"';
                } elseif ($board === 'splunkdash' && $f['key'] === 'DASHBOARDS') {
                    echo ' data-preview-script="splunkdash.php"';
                }
              ?>>
                <thead><tr>
                  <?php foreach ($cols as $c): ?><th><?= h($c['label']) ?></th><?php endforeach; ?>
                  <?php if (($board === 'rss' && $f['key'] === 'FEEDS')
                      || ($board === 'web' && $f['key'] === 'SITES')
                      || (in_array($board, ['grafana', 'splunkdash'], true) && $f['key'] === 'DASHBOARDS')): ?><th></th><?php endif; ?>
                  <?php if (admin_is_super()): ?><th>Access</th><?php endif; ?>
                  <th></th>
                </tr></thead>
                <tbody>
                  <?php foreach ($rows as $ri => $row):
                    if ($hasKeyCol && ($row['key'] ?? '') === '' && ($row['name'] ?? '') !== '') {
                        $row['key'] = $row['name'];
                    }
                  ?>
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
                          <?php elseif (($c['type'] ?? '') === 'password'): ?>
                            <input type="password" name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]"
                                   autocomplete="off"
                                   placeholder="<?= h(!empty($row[$c['key']]) ? '(unchanged)' : '') ?>">
                          <?php elseif (($c['type'] ?? '') === 'palette'): ?>
                            <?php
                              $palVal = (string)($row[$c['key']] ?? '');
                              if ($palVal === '' || ($palVal[0] !== '#' && !family_palette_has_key($palVal))) {
                                  $palVal = family_calendar_palette()[$ri % count(family_calendar_palette())]['key'];
                              }
                              $palHex = family_calendar_color_hex($palVal);
                            ?>
                            <div class="cal-palette-cell">
                              <span class="cal-swatch" data-swatch style="background:<?= h($palHex) ?>"></span>
                              <select name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]" class="cal-palette-select"
                                      onchange="syncCalSwatch(this)">
                                <?php foreach (family_calendar_palette() as $p): ?>
                                <option value="<?= h($p['key']) ?>" data-hex="<?= h($p['hex']) ?>"
                                  <?= $palVal === $p['key'] || $palVal === $p['hex'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                          <?php else: ?>
                            <input type="text" name="<?= h($f['key']) ?>[<?= $ri ?>][<?= h($c['key']) ?>]"
                                   value="<?= h((string)($row[$c['key']] ?? '')) ?>"
                                   placeholder="<?= h($c['placeholder'] ?? '') ?>">
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <?php if ($board === 'rss' && $f['key'] === 'FEEDS'):
                        $feedKey = trim((string)($row['_key'] ?? ''));
                        $rssPrev = $feedKey !== '' ? rss_preview_url($feedKey) : '';
                      ?>
                      <td>
                        <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px<?= $rssPrev === '' ? ';display:none' : '' ?>"
                           href="<?= h($rssPrev !== '' ? $rssPrev : '#') ?>" target="_blank" rel="noopener" data-rss-preview>Preview ↗</a>
                      </td>
                      <?php elseif ($board === 'web' && $f['key'] === 'SITES'):
                        $siteKey = trim((string)($row['_key'] ?? ''));
                        $webPrev = $siteKey !== '' ? web_preview_url($siteKey) : '';
                      ?>
                      <td>
                        <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px<?= $webPrev === '' ? ';display:none' : '' ?>"
                           href="<?= h($webPrev !== '' ? $webPrev : '#') ?>" target="_blank" rel="noopener" data-web-preview>Preview ↗</a>
                      </td>
                      <?php elseif (in_array($board, ['grafana', 'splunkdash'], true) && $f['key'] === 'DASHBOARDS'):
                        $dashKey = trim((string)($row['_key'] ?? ''));
                        $dashPrev = $dashKey !== '' ? ($board === 'grafana' ? grafana_preview_url($dashKey) : splunkdash_preview_url($dashKey)) : '';
                      ?>
                      <td>
                        <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px<?= $dashPrev === '' ? ';display:none' : '' ?>"
                           href="<?= h($dashPrev !== '' ? $dashPrev : '#') ?>" target="_blank" rel="noopener" data-board-preview>Preview ↗</a>
                      </td>
                      <?php endif; ?>
                      <?php if (admin_is_super()):
                        $shareEntry = null;
                        if (!empty($f['keyed'])) {
                            $shareKey = (string)($row['_key'] ?? '');
                            if ($shareKey !== '' && is_array($val) && array_key_exists($shareKey, $val)) {
                                $rawEntry = $val[$shareKey];
                                $shareEntry = is_array($rawEntry) ? $rawEntry : null;
                            }
                        } elseif (is_array($row)) {
                            $shareEntry = $row;
                        }
                      ?>
                      <td class="entry-sharing-cell">
                        <?php admin_entry_sharing_html($f['key'] . '[' . (int)$ri . ']', $shareEntry, true); ?>
                      </td>
                      <?php endif; ?>
                      <td><button type="button" class="rowdel" onclick="this.closest('tr').remove()">×</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              </div>
              <button type="button" class="addrow" onclick="addRow(this)">+ Add row</button>
              <?php if ($board === 'family' && $f['key'] === 'ICS_FEEDS'): ?>
              <div class="cal-legend-preview" id="calLegendPreview" aria-label="Wall legend preview"></div>
              <?php endif; ?>
              <?php if (!empty($f['help'])): ?><div class="help"><?= h($f['help']) ?></div><?php endif; ?>

            <?php else:
              admin_field($f, $val, $board);
            endif; ?>
          </div>
        <?php endforeach; endif; ?>

        <?php if ($board === 'rss'):
          $rssDeployScreens = admin_filter_screens(rotation_screens());
          $rssDeployChecked = [];
          if (isset($rssDeployScreens['main']) && admin_can_screen('main')) {
              $rssDeployChecked[] = 'main';
          } elseif ($rssDeployChecked === []) {
              $rssDeployChecked = admin_default_deploy_screens();
          }
        ?>
        <div class="actions deploy-save-screens" style="margin-top:18px;margin-bottom:0;padding-top:16px;border-top:1px solid var(--line)">
          <span class="help" style="margin:0;width:100%">On Save, add all feeds to rotation on:</span>
          <?php admin_deploy_picker_from_screens($rssDeployScreens, $rssDeployChecked); ?>
          <span class="help" style="margin:8px 0 0">Adds <code>rss.php?feed=KEY</code> for each feed with dwell from stories × seconds per story. Leave all unchecked to skip rotation sync.</span>
        </div>
        <?php endif; ?>

        <div class="actions">
          <button class="save">Save</button>
          <?php if (admin_can_tools()): ?>
          <label class="check"><input type="checkbox" name="clear_cache"> Clear cache after save</label>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($board === 'rotator'): ?>
      <div class="admin-tab-panel<?= $rotatorTab === 'deploy' ? ' active' : '' ?>" data-tab-panel="deploy" id="photos-deploy-panel">
            <p class="help" style="margin-bottom:12px">Push the current deck to rotation on selected displays. Per-display sync status lives on <a href="?board=rotation">Rotation → Photo rotator sync</a>. You can also check displays in the Save bar on the Deck tab and click <strong>Save</strong>.</p>
            <form method="post" action="?board=rotator" class="slides-deploy-form">
              <input type="hidden" name="board" value="rotator">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <?php
              $rotatorDeployTabChecked = [];
              foreach ($rotatorDeployStatus as $screenKey => $dep) {
                  if ($dep['on_playlist'] || ($screenKey === 'main' && !$dep['mirrors_main'] && !$dep['on_playlist'] && $rotatorDeckStats['on_disk'] > 0)) {
                      $rotatorDeployTabChecked[] = $screenKey;
                  }
              }
              ?>
              <span class="help" style="margin:0 0 8px;display:block">Deploy to:</span>
              <?php admin_deploy_picker_from_status($rotatorDeployStatus, $rotatorDeployTabChecked, ['class' => 'screen-picker-inline screen-picker-deploy-tab']); ?>
              <div class="slides-deploy-tools" style="margin-top:14px">
                <button class="save" type="submit" name="action" value="deploy_photos">Deploy now</button>
              </div>
            </form>
      </div>
      <div class="admin-tab-panel<?= $rotatorTab === 'upload' ? ' active' : '' ?>" data-tab-panel="upload" id="photo-upload-panel" style="margin-top:0">
          <div class="upload-box">
            <h3>Upload photos</h3>
            <form method="post" enctype="multipart/form-data" class="upload-row" action="?board=rotator">
              <input type="hidden" name="action" value="upload_photo">
              <input type="hidden" name="board" value="rotator">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="file" name="photo[]" accept="image/jpeg,image/png" multiple>
              <button class="secondary" type="submit">Upload</button>
            </form>
            <div class="help" style="margin-top:8px">JPG or PNG, up to 25 MB each — added to the deck and main rotation automatically.</div>
          </div>
      </div>
      <?php endif; ?>
      <?php if ($board === 'slides'): ?>
      <div class="admin-tab-panel<?= $slidesTab === 'deploy' ? ' active' : '' ?>" data-tab-panel="deploy" id="slides-deploy-panel">
            <p class="help" style="margin-bottom:12px">Push slides to rotation immediately. Or check displays in the Save bar above and click <strong>Save</strong>.</p>
            <div class="deploy-stats">
              <span><strong><?= (int)$slidesDeckStats['on_disk'] ?></strong> in deck</span>
              <span><strong><?= (int)$slidesDeckStats['active_now'] ?></strong> active now</span>
              <a class="secondary" style="padding:4px 10px;text-decoration:none;font-size:12px" href="slides.php" target="_blank" rel="noopener">Preview deck ↗</a>
            </div>
            <form method="post" action="?board=slides" class="slides-deploy-form">
              <input type="hidden" name="board" value="slides">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <?php
              $slidesDeployTabChecked = [];
              foreach ($slidesDeployStatus as $screenKey => $dep) {
                  if ($dep['on_playlist'] || ($screenKey === 'main' && !$dep['mirrors_main'] && !$dep['on_playlist'] && $slidesDeckStats['on_disk'] > 0)) {
                      $slidesDeployTabChecked[] = $screenKey;
                  }
              }
              ?>
              <span class="help" style="margin:0 0 8px;display:block">Deploy to:</span>
              <?php admin_deploy_picker_from_status($slidesDeployStatus, $slidesDeployTabChecked, ['class' => 'screen-picker-inline screen-picker-deploy-tab']); ?>
              <input type="search" class="deploy-filter" placeholder="Filter status list…" autocomplete="off" data-deploy-filter="slides-deploy-grid">
              <div class="slides-deploy-grid" id="slides-deploy-grid">
                <?php foreach ($slidesDeployStatus as $screenKey => $dep): ?>
                <div class="slides-deploy-row" data-deploy-screen="<?= h($screenKey) ?>">
                  <div class="deploy-row-title"><strong><?= h($dep['name']) ?></strong><code><?= h($screenKey) ?></code></div>
                  <div class="deploy-detail">
                    <?php if ($dep['mirrors_main']): ?>
                      <span class="pill">Mirrors main</span>
                    <?php elseif ($dep['on_playlist']): ?>
                      <span class="pill ok">Synced</span> <?= (int)$dep['sync']['synced'] ?>/<?= (int)$dep['expected'] ?>
                    <?php elseif ($dep['partial'] ?? false): ?>
                      <span class="pill warn">Partial</span>
                    <?php else: ?>
                      <span class="pill warn">Not deployed</span>
                    <?php endif; ?>
                  </div>
                  <div class="deploy-actions">
                    <a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px"
                       href="<?= h(rotation_screen_preview_url($screenKey)) ?>" target="_blank" rel="noopener">Preview ↗</a>
                    <?php if (($dep['on_playlist'] || ($dep['partial'] ?? false)) && !$dep['mirrors_main']): ?>
                    <button type="submit" class="secondary" style="padding:6px 12px;font-size:13px"
                            name="action" value="remove_slides_rotation"
                            onclick="this.form.remove_screen.value='<?= h($screenKey) ?>'; return confirm('Remove slides from <?= h($dep['name']) ?>?');">Remove</button>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="remove_screen" value="">
              <div class="slides-deploy-tools">
                <button class="save" type="submit" name="action" value="deploy_slides">Deploy now</button>
              </div>
            </form>
      </div>
      <div class="admin-tab-panel<?= $slidesTab === 'create' ? ' active' : '' ?>" data-tab-panel="create" id="add-slides-panel" style="margin-top:0">
          <div class="upload-box">
            <h3>Upload an image</h3>
            <form method="post" enctype="multipart/form-data" class="upload-row" action="?board=slides">
              <input type="hidden" name="action" value="upload_slide">
              <input type="hidden" name="board" value="slides">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="file" name="slide" accept="image/jpeg,image/png,image/webp" required>
              <button class="secondary" type="submit">Upload</button>
            </form>
            <div class="help" style="margin-top:8px">JPG, PNG, or WebP — added to the deck automatically. Then Save or Deploy.</div>
          </div>

          <div class="creator-box" style="margin-top:22px">
            <h3>Create a text slide</h3>
            <p class="creator-lead">Design a PNG slide and add it to the deck. Set schedule on the <strong>Deck</strong> tab, then Save.</p>
            <div class="creator-layout">
              <div class="creator-preview-block">
                <div class="preview-head">
                  <label class="l" style="margin:0">Preview</label>
                  <span class="preview-badge">1920 × 1080</span>
                </div>
                <div class="preview-wrap"><canvas id="slidePreview" width="1920" height="1080"></canvas></div>
              </div>

              <div class="creator-panel creator-fields">
                <div class="span-full">
                  <label class="l">Template</label>
                  <div class="template-pick" id="templatePick" role="group" aria-label="Slide templates">
                    <?php foreach (slide_creator_templates() as $tid => $tpl): ?>
                      <button type="button" class="template-chip" data-template="<?= h($tid) ?>"><?= h($tpl['label']) ?></button>
                    <?php endforeach; ?>
                  </div>
                  <div class="field-hint">Prefills text and background — replace bracketed placeholders like <code>[Name]</code>.</div>
                </div>

                <div class="creator-editor">
                  <div class="field-block span-full">
                    <label class="l">Background</label>
                    <div class="bg-section">
                      <div class="bg-section-title">Photo scenes</div>
                      <div class="bg-section-help">Real photography from Unsplash, dimmed so your text stays readable — like Canva.</div>
                      <div class="bg-pick" id="bgPickPhoto">
                        <?php foreach (slide_photo_background_presets() as $id => $preset):
                          $bgUrl = slide_background_url($id); ?>
                          <label title="<?= h($preset['label']) ?>">
                            <input type="radio" name="creator_bg" value="<?= h($id) ?>">
                            <div class="bg-swatch photo" data-bg="<?= h($id) ?>">
                              <?php if ($bgUrl): ?><img src="<?= h($bgUrl) ?>" alt="" loading="lazy"><?php endif; ?>
                              <span><?= h($preset['label']) ?></span>
                            </div>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div class="bg-section">
                      <div class="bg-section-title">Theme colors</div>
                      <div class="bg-pick" id="bgPickTheme">
                        <?php foreach (slide_theme_background_presets() as $id => $preset):
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
                    </div>
                  </div>

                  <div class="field-block span-full">
                    <label class="l">Alignment</label>
                    <div class="seg-control" role="group" aria-label="Text alignment">
                      <label><input type="radio" name="creator_align" value="left" checked><span>Left</span></label>
                      <label><input type="radio" name="creator_align" value="center"><span>Center</span></label>
                    </div>
                  </div>

                  <div class="field-block">
                    <label class="l" for="creator_title">Title</label>
                    <textarea id="creator_title" rows="3" placeholder="Happy Birthday, Mom!"></textarea>
                    <div class="field-hint">Large headline — up to three lines on the wall.</div>
                  </div>
                  <div class="field-block">
                    <label class="l" for="creator_subtitle">Subtitle</label>
                    <textarea id="creator_subtitle" rows="3" placeholder="March 15"></textarea>
                    <div class="field-hint">Date, occasion, or short tagline.</div>
                  </div>

                  <div class="field-block span-full">
                    <label class="l" for="creator_body">Body</label>
                    <textarea id="creator_body" rows="8" placeholder="Wishing you a wonderful day…"></textarea>
                    <div class="field-hint">Details and directions. Blank lines add extra spacing.</div>
                  </div>

                  <div class="field-block">
                    <label class="l" for="creator_footer">Footer (optional)</label>
                    <textarea id="creator_footer" rows="3" placeholder="Love, the family"></textarea>
                  </div>
                  <div class="field-block">
                    <label class="l" for="creator_name">Filename</label>
                    <input type="text" id="creator_name" placeholder="mom-birthday (optional — .png added)">
                    <div class="field-hint">Auto-filled from the title; edit to override.</div>
                  </div>

                  <div class="field-block span-full creator-actions">
                    <button type="button" class="save" id="creatorSave">Create slide</button>
                    <p class="help">Adds to the deck. Requires a title or body — then Save or Deploy.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
      </div>
      <?php endif; ?>
      <?php if ($board === 'video'): foreach ($videoStatuses as $st):
        if (empty($st['fetchable'])) continue;
        $fid = preg_replace('/[^a-z0-9_\-]/i', '', $st['key']);
        if ($fid === '') continue;
      ?>
      <form id="video-fetch-<?= h($fid) ?>" method="post" action="?board=video" hidden>
        <input type="hidden" name="action" value="video_fetch_one">
        <input type="hidden" name="board" value="video">
        <input type="hidden" name="video_key" value="<?= h($st['key']) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      </form>
      <?php endforeach; endif; ?>
    <?php endif; ?>
  </main>
</div>

<script>
<?php if ($authed && admin_is_super()): ?>
window.SHARING_USER_OPTIONS = <?= json_encode(admin_sharing_user_options(), JSON_UNESCAPED_UNICODE) ?>;
<?php endif; ?>
const RSS_PREVIEW_SUFFIX = <?= json_encode('noticker=1' . (signage_ticker_enabled() ? '&safebottom=' . SIGNAGE_TICKER_H : '')) ?>;

function rssPreviewUrl(key) {
  key = (key || '').replace(/[^a-z0-9_\-]/gi, '');
  if (!key) return '';
  return 'rss.php?feed=' + encodeURIComponent(key) + '&' + RSS_PREVIEW_SUFFIX;
}

function syncRssPreviewLink(tr) {
  const a = tr.querySelector('[data-rss-preview]');
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!a || !keyInp) return;
  const u = rssPreviewUrl(keyInp.value.trim());
  if (u) { a.href = u; a.style.display = ''; }
  else { a.href = '#'; a.style.display = 'none'; }
}

function bindRssFeedRow(tr) {
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!keyInp || keyInp.dataset.rssPreviewBound) return;
  keyInp.dataset.rssPreviewBound = '1';
  keyInp.addEventListener('input', function () { syncRssPreviewLink(tr); });
  syncRssPreviewLink(tr);
}

function initRssFeedPreviews() {
  document.querySelectorAll('table.rows[data-field="FEEDS"] tbody tr').forEach(bindRssFeedRow);
}

function webPreviewUrl(key) {
  key = (key || '').replace(/[^a-z0-9_\-]/gi, '').toLowerCase();
  if (!key) return '';
  return 'web.php?d=' + encodeURIComponent(key) + '&' + RSS_PREVIEW_SUFFIX;
}

function syncWebPreviewLink(tr) {
  const a = tr.querySelector('[data-web-preview]');
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!a || !keyInp) return;
  const u = webPreviewUrl(keyInp.value.trim());
  if (u) { a.href = u; a.style.display = ''; }
  else { a.href = '#'; a.style.display = 'none'; }
}

function bindWebSiteRow(tr) {
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!keyInp || keyInp.dataset.webPreviewBound) return;
  keyInp.dataset.webPreviewBound = '1';
  keyInp.addEventListener('input', function () { syncWebPreviewLink(tr); });
  syncWebPreviewLink(tr);
}

function initWebSitePreviews() {
  document.querySelectorAll('table.rows[data-field="SITES"] tbody tr').forEach(bindWebSiteRow);
}

function keyedBoardPreviewUrl(script, key) {
  key = (key || '').replace(/[^a-z0-9_\-]/gi, '');
  if (!key || !script) return '';
  return script + '?d=' + encodeURIComponent(key) + '&' + RSS_PREVIEW_SUFFIX;
}

function syncKeyedBoardPreviewLink(tr) {
  const table = tr.closest('table.rows');
  const script = table && table.getAttribute('data-preview-script');
  const a = tr.querySelector('[data-board-preview]');
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!a || !keyInp) return;
  const u = keyedBoardPreviewUrl(script, keyInp.value.trim());
  if (u) { a.href = u; a.style.display = ''; }
  else { a.href = '#'; a.style.display = 'none'; }
}

function bindKeyedBoardRow(tr) {
  const keyInp = tr.querySelector('input[name*="[_key]"]');
  if (!keyInp || keyInp.dataset.boardPreviewBound) return;
  keyInp.dataset.boardPreviewBound = '1';
  keyInp.addEventListener('input', function () { syncKeyedBoardPreviewLink(tr); });
  syncKeyedBoardPreviewLink(tr);
}

function initKeyedBoardPreviews() {
  document.querySelectorAll('table.rows[data-preview-script] tbody tr').forEach(bindKeyedBoardRow);
}

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
        'password' => ($c['type'] ?? '') === 'password',
        'palette' => ($c['type'] ?? '') === 'palette',
        'paletteOptions' => ($c['type'] ?? '') === 'palette' ? family_calendar_palette() : [],
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
      if (field === 'SCREENS' && (c.key === 'show_ticker' || c.key === 'show_clock')) inp.checked = true;
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
    } else if (c.password) {
      inp.type = 'password';
      inp.autocomplete = 'off';
    } else if (c.palette) {
      td.className = (td.className ? td.className + ' ' : '') + 'cal-palette-cell';
      const sw = document.createElement('span');
      sw.className = 'cal-swatch';
      sw.setAttribute('data-swatch', '');
      inp = document.createElement('select');
      inp.className = 'cal-palette-select';
      inp.onchange = function () { syncCalSwatch(this); };
      (c.paletteOptions || []).forEach(function (p) {
        const opt = document.createElement('option');
        opt.value = p.key;
        opt.textContent = p.label;
        opt.setAttribute('data-hex', p.hex);
        inp.appendChild(opt);
      });
      td.appendChild(sw);
    } else {
      inp.type = 'text';
      inp.placeholder = c.ph;
    }
    inp.name = field + '[' + idx + '][' + c.key + ']';
    td.appendChild(inp); tr.appendChild(td);
  });
  if (field === 'FEEDS' || field === 'SITES' || table.getAttribute('data-preview-script')) {
    const prevTd = document.createElement('td');
    const prevA = document.createElement('a');
    prevA.className = 'secondary';
    prevA.style.cssText = 'padding:4px 10px;text-decoration:none;font-size:12px;display:none';
    prevA.href = '#';
    prevA.target = '_blank';
    prevA.rel = 'noopener';
    if (field === 'FEEDS') prevA.setAttribute('data-rss-preview', '');
    else if (field === 'SITES') prevA.setAttribute('data-web-preview', '');
    else prevA.setAttribute('data-board-preview', '');
    prevA.textContent = 'Preview ↗';
    prevTd.appendChild(prevA);
    tr.appendChild(prevTd);
  }
  if (window.SHARING_USER_OPTIONS && window.SHARING_USER_OPTIONS.length) {
    const shareTd = document.createElement('td');
    shareTd.className = 'entry-sharing-cell';
    shareTd.innerHTML = entrySharingHtml(field + '[' + idx + ']', '', [], true);
    tr.appendChild(shareTd);
    initEntrySharingPopovers(tr);
  }
  const td = document.createElement('td');
  td.innerHTML = '<button type="button" class="rowdel" onclick="this.closest(\'tr\').remove()">×</button>';
  tr.appendChild(td);
  table.querySelector('tbody').appendChild(tr);
  if (field === 'FEEDS') bindRssFeedRow(tr);
  else if (field === 'SITES') bindWebSiteRow(tr);
  else if (table.getAttribute('data-preview-script')) bindKeyedBoardRow(tr);
  const palSel = tr.querySelector('.cal-palette-select');
  if (palSel) syncCalSwatch(palSel);
  updateCalLegendPreview();
}

function syncCalSwatch(sel) {
  const cell = sel.closest('.cal-palette-cell');
  if (!cell) return;
  const sw = cell.querySelector('[data-swatch]');
  const opt = sel.options[sel.selectedIndex];
  if (sw && opt) sw.style.background = opt.getAttribute('data-hex') || '#ffb347';
  updateCalLegendPreview();
}

function updateCalLegendPreview() {
  const box = document.getElementById('calLegendPreview');
  if (!box) return;
  const table = document.querySelector('table.rows[data-field="ICS_FEEDS"]');
  if (!table) return;
  const palette = <?= json_encode(family_calendar_palette()) ?>;
  const hexFor = function (key) {
    const hit = palette.find(function (x) { return x.key === key; });
    return hit ? hit.hex : '#ffb347';
  };
  const seen = {};
  const parts = [];
  table.querySelectorAll('tbody tr').forEach(function (tr) {
    const keyInp = tr.querySelector('input[name*="[key]"]');
    const colorSel = tr.querySelector('select[name*="[color]"]');
    const key = keyInp && keyInp.value.trim();
    if (!key) return;
    const id = key.toLowerCase();
    if (seen[id]) return;
    seen[id] = true;
    const hex = colorSel ? hexFor(colorSel.value) : '#ffb347';
    parts.push('<span class="leg"><span class="dot" style="background:' + hex + '"></span>'
      + key.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span>');
  });
  box.innerHTML = parts.join('');
}

document.querySelectorAll('.cal-palette-select').forEach(function (sel) { syncCalSwatch(sel); });
updateCalLegendPreview();
const calFeedsTable = document.querySelector('table.rows[data-field="ICS_FEEDS"]');
if (calFeedsTable) {
  calFeedsTable.addEventListener('input', updateCalLegendPreview);
  calFeedsTable.addEventListener('change', updateCalLegendPreview);
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
  const summary = card.querySelector('[data-schedule-summary]');
  if (summary) {
    const off = card.querySelector('input[name*="[off]"]');
    if (off && off.checked) {
      summary.textContent = 'Disabled';
    } else {
      const parts = [sel.options[sel.selectedIndex]?.text || type];
      const ds = card.querySelector('input[name*="[date_start]"]');
      const de = card.querySelector('input[name*="[date_end]"]');
      const md = card.querySelector('input[name*="[month_day]"]');
      const mde = card.querySelector('input[name*="[month_day_end]"]');
      const dom = card.querySelector('input[name*="[day_of_month]"]');
      const wd = card.querySelector('input[name*="[weekdays]"]');
      if (type === 'once' && ds && ds.value) parts.push(ds.value);
      if (type === 'range' && ds && ds.value) parts.push(ds.value + ' → ' + (de?.value || '?'));
      if (type === 'yearly' && md && md.value) parts.push(md.value);
      if (type === 'yearly_range' && md && md.value) parts.push(md.value + ' → ' + (mde?.value || '?'));
      if (type === 'monthly' && dom && dom.value) parts.push('day ' + dom.value);
      if (type === 'weekly' && wd && wd.value) parts.push(wd.value);
      summary.textContent = parts.join(' · ');
    }
  }
}

function reindexSlideDeck() {
  const deck = document.getElementById('slideDeck');
  if (!deck || !deck.dataset.field) return;
  const field = deck.dataset.field;
  deck.querySelectorAll('[data-slide-card]').forEach(function (card, i) {
    card.querySelectorAll('[name^="' + field + '["]').forEach(function (inp) {
      inp.name = inp.name.replace(new RegExp(field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]]+\\]'), field + '[' + i + ']');
    });
  });
}

function bindSlideCard(card) {
  const sel = card.querySelector('[data-schedule-select]');
  if (sel && !sel.dataset.bound) {
    sel.dataset.bound = '1';
    sel.addEventListener('change', function () { syncSlideCard(card); });
  }
  syncSlideCard(card);
  card.querySelectorAll('input[type="text"]').forEach(function (inp) {
    if (inp.dataset.summaryBound) return;
    inp.dataset.summaryBound = '1';
    inp.addEventListener('input', function () { syncSlideCard(card); });
  });
  const fileInp = card.querySelector('input[name*="[file]"]');
  const head = card.querySelector('.slide-card-title strong');
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
      syncSlideCard(card);
    });
  }
}

function initSlideDeck() {
  const deck = document.getElementById('slideDeck');
  if (!deck) return;
  bindPlaylistDeckDrag(deck, '[data-slide-card]', '.drag-handle', reindexSlideDeck, function (card, d) {
    bindPlaylistCardHandle(card, d, '.drag-handle', reindexSlideDeck);
    bindSlideCard(card);
  });
  const hl = deck.querySelector('.slide-card-highlight');
  if (hl) {
    setTimeout(function () { hl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 120);
  }
}

function initAdminTabs(tabRootId, boardName) {
  const root = document.getElementById(tabRootId);
  if (!root) return;
  const tabs = root.querySelectorAll('.admin-tab[data-tab]');
  const panels = document.querySelectorAll('.admin-tab-panel[data-tab-panel]');
  function show(id) {
    tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === id); });
    panels.forEach(function (p) { p.classList.toggle('active', p.dataset.tabPanel === id); });
    const url = new URL(location.href);
    url.searchParams.set('board', boardName);
    url.searchParams.set('tab', id);
    if (history.replaceState) history.replaceState(null, '', url.pathname + url.search + url.hash);
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { show(t.dataset.tab); });
  });
}

function initSlidesSectionNav() {
  initAdminTabs('slidesTabs', 'slides');
}

function reindexPhotoDeck() {
  const deck = document.getElementById('photoDeck');
  if (!deck || !deck.dataset.field) return;
  const field = deck.dataset.field;
  deck.querySelectorAll('[data-photo-card]').forEach(function (card, i) {
    card.querySelectorAll('[name^="' + field + '["]').forEach(function (inp) {
      inp.name = inp.name.replace(new RegExp(field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]]+\\]'), field + '[' + i + ']');
    });
  });
}

function bindPhotoCard(card) {
  const fileInp = card.querySelector('input[name*="[file]"]');
  const head = card.querySelector('.slide-card-title strong');
  if (fileInp && head) {
    fileInp.addEventListener('input', function () {
      head.textContent = fileInp.value.trim() || 'New photo';
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

function initPhotoDeck() {
  const deck = document.getElementById('photoDeck');
  if (!deck) return;
  bindPlaylistDeckDrag(deck, '[data-photo-card]', '.drag-handle', reindexPhotoDeck, function (card, d) {
    bindPlaylistCardHandle(card, d, '.drag-handle', reindexPhotoDeck);
    bindPhotoCard(card);
  });
  deck.querySelectorAll('[data-photo-card]').forEach(bindPhotoCard);
  const hl = deck.querySelector('.slide-card-highlight');
  if (hl) {
    setTimeout(function () { hl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 120);
  }
}

function initPhotosSectionNav() {
  initAdminTabs('photosTabs', 'rotator');
}

function submitPhotoDelete(file) {
  if (!file) return;
  if (!confirm('Delete "' + file + '" permanently?\n\nThis removes the image from disk and from the deck. It cannot be undone.')) return;
  const form = document.getElementById('photoDeleteForm');
  const input = document.getElementById('photoDeleteFile');
  if (!form || !input) {
    alert('Could not submit — refresh the page and try again.');
    return;
  }
  input.value = file;
  form.submit();
}

function submitPhotoAddToDeck(file) {
  if (!file) return;
  const form = document.getElementById('photoAddForm');
  const input = document.getElementById('photoAddFile');
  if (!form || !input) {
    alert('Could not submit — refresh the page and try again.');
    return;
  }
  input.value = file;
  form.submit();
}

function screenPickerSummaryText(picker) {
  const mode = picker.dataset.summaryMode || 'deck';
  const boxes = picker.querySelectorAll('.screen-picker-list input[type=checkbox]');
  const total = boxes.length;
  let n = 0;
  boxes.forEach(function (cb) { if (cb.checked) n++; });
  if (mode === 'assign') {
    if (n === 0) return 'No displays';
    if (n === total) return 'All displays (' + total + ')';
    return n + ' of ' + total + ' displays';
  }
  if (n === 0) return 'Hidden on all displays';
  if (n === total) return 'All displays (' + total + ')';
  return n + ' of ' + total + ' displays';
}

function updateScreenPickerSummary(picker) {
  const el = picker.querySelector('[data-screen-summary]');
  if (el) el.textContent = screenPickerSummaryText(picker);
}

function bindScreenPicker(picker) {
  if (picker.dataset.screenPickerBound) return;
  picker.dataset.screenPickerBound = '1';
  updateScreenPickerSummary(picker);
  const toggle = picker.querySelector('.screen-picker-toggle');
  const panel = picker.querySelector('.screen-picker-panel');
  if (toggle && panel) {
    toggle.addEventListener('click', function () {
      const open = panel.hidden;
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Done' : 'Choose…';
      if (open) {
        const filter = panel.querySelector('.screen-picker-filter');
        if (filter) {
          filter.value = '';
          filter.dispatchEvent(new Event('input'));
          filter.focus();
        }
      }
    });
  }
  const filter = picker.querySelector('.screen-picker-filter');
  if (filter) {
    filter.addEventListener('input', function () {
      const q = filter.value.trim().toLowerCase();
      picker.querySelectorAll('.screen-picker-list label[data-screen-key]').forEach(function (lab) {
        lab.hidden = q !== '' && lab.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  }
  picker.querySelectorAll('[data-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const pickAll = btn.getAttribute('data-pick') === 'all';
      picker.querySelectorAll('.screen-picker-list input[type=checkbox]').forEach(function (cb) {
        cb.checked = pickAll;
      });
      updateScreenPickerSummary(picker);
    });
  });
  picker.querySelectorAll('.screen-picker-list input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () { updateScreenPickerSummary(picker); });
  });
}

function initScreenPickers(root) {
  (root || document).querySelectorAll('[data-screen-picker]').forEach(bindScreenPicker);
}

function screenPickerHtml(prefix, opts, checkedKeys, cfg) {
  cfg = cfg || {};
  if (!opts.length) {
    return '<span class="help" style="margin:0">No displays configured.</span>';
  }
  const compact = cfg.compact !== false;
  const summaryMode = cfg.summaryMode || 'deck';
  const nameKey = cfg.nameKey || 'screens';
  const flat = !!cfg.flat;
  const flatName = cfg.name || 'deploy_screens';
  const checkboxName = flat ? flatName + '[]' : prefix + '[' + nameKey + '][]';
  const checked = new Set((checkedKeys || []).map(String));
  let html = '<div class="screen-picker' + (compact ? ' screen-picker-compact' : '') +
    (cfg.class ? ' ' + cfg.class : '') + '" data-screen-picker data-summary-mode="' + summaryMode + '">';
  if (cfg.formMarker) {
    html += '<input type="hidden" name="' + prefix + '[' + (cfg.formMarkerKey || '_screens_form') + ']" value="1">';
  }
  if (compact) {
    html += '<div class="screen-picker-bar"><span class="screen-picker-summary" data-screen-summary></span>';
    html += '<button type="button" class="screen-picker-toggle secondary" aria-expanded="false">Choose…</button></div>';
    html += '<div class="screen-picker-panel" hidden>';
    html += '<input type="search" class="screen-picker-filter" placeholder="Filter displays…" autocomplete="off">';
    html += '<div class="screen-picker-quick"><button type="button" class="secondary" data-pick="all">All</button>';
    html += '<button type="button" class="secondary" data-pick="none">None</button></div>';
  } else if (cfg.label) {
    html += '<span class="mini">' + cfg.label + '</span>';
  }
  html += '<div class="screen-picker-list slide-screen-checks">';
  opts.forEach(function (o) {
    const key = String(o.key).replace(/"/g, '&quot;');
    const name = String(o.name).replace(/</g, '&lt;');
    html += '<label data-screen-key="' + key + '"><input type="checkbox" name="' + checkboxName + '" value="' + key + '"';
    if (checked.has(String(o.key))) html += ' checked';
    html += '> ' + name + '</label>';
  });
  html += '</div>';
  if (compact) html += '</div>';
  html += '</div>';
  return html;
}

function initDeployPanelFilters() {
  document.querySelectorAll('[data-deploy-filter]').forEach(function (input) {
    if (input.dataset.deployFilterBound) return;
    input.dataset.deployFilterBound = '1';
    input.addEventListener('input', function () {
      const q = input.value.trim().toLowerCase();
      const grid = document.getElementById(input.getAttribute('data-deploy-filter') || '');
      if (!grid) return;
      grid.querySelectorAll('.slides-deploy-row').forEach(function (row) {
        row.hidden = q !== '' && row.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });
}

function initRotationTargetFilter() {
  document.querySelectorAll('[data-rotation-target-filter]').forEach(function (input) {
    if (input.dataset.rotationTargetFilterBound) return;
    input.dataset.rotationTargetFilterBound = '1';
    const sel = document.getElementById(input.getAttribute('data-rotation-target-filter') || '');
    if (!sel) return;
    input.addEventListener('input', function () {
      const q = input.value.trim().toLowerCase();
      Array.from(sel.options).forEach(function (opt) {
        opt.hidden = q !== '' && opt.textContent.toLowerCase().indexOf(q) === -1;
      });
      if (sel.selectedOptions.length && sel.selectedOptions[0].hidden) {
        const first = Array.from(sel.options).find(function (o) { return !o.hidden; });
        if (first) sel.value = first.value;
      }
    });
  });
}

function entryAccessTriggerLabel(ownerId, sharedIds) {
  const users = window.SHARING_USER_OPTIONS || [];
  const byId = {};
  users.forEach(function (u) { byId[u.id] = u.username; });
  ownerId = ownerId || '';
  sharedIds = sharedIds || [];
  if (!ownerId && !sharedIds.length) return 'Super only';
  const label = ownerId ? (byId[ownerId] || ownerId) : 'Super only';
  if (!sharedIds.length) return label;
  if (sharedIds.length === 1) return label + ' · ' + (byId[sharedIds[0]] || sharedIds[0]);
  return label + ' · +' + sharedIds.length;
}

function entrySharingOwnerValue(root) {
  const sel = root.querySelector('select[data-entry-sharing-owner]');
  if (sel) return sel.value;
  const checked = root.querySelector('[data-entry-sharing-owner]:checked');
  return checked ? checked.value : '';
}

function syncEntrySharingSharedList(root) {
  const ownerId = entrySharingOwnerValue(root);
  root.querySelectorAll('[data-entry-sharing-user]').forEach(function (label) {
    const uid = label.getAttribute('data-entry-sharing-user') || '';
    const hide = ownerId !== '' && uid === ownerId;
    label.hidden = hide;
    if (hide) {
      const cb = label.querySelector('[data-entry-sharing-shared]');
      if (cb) cb.checked = false;
    }
  });
}

function syncEntrySharingTrigger(root) {
  const trigger = root.querySelector('[data-entry-sharing-trigger]');
  if (!trigger) return;
  const shared = [];
  root.querySelectorAll('[data-entry-sharing-shared]:checked').forEach(function (cb) {
    shared.push(cb.value);
  });
  trigger.textContent = entryAccessTriggerLabel(entrySharingOwnerValue(root), shared);
}

function positionEntrySharingMenu(menu, trigger) {
  if (!trigger || !menu || menu.hidden) return;
  const r = trigger.getBoundingClientRect();
  menu.style.visibility = 'hidden';
  menu.hidden = false;
  const mh = menu.offsetHeight;
  const mw = menu.offsetWidth;
  let top = r.bottom + 8;
  if (top + mh > window.innerHeight - 8) {
    top = Math.max(8, r.top - mh - 8);
  }
  let left = r.right - mw;
  left = Math.max(8, Math.min(left, window.innerWidth - mw - 8));
  menu.style.top = top + 'px';
  menu.style.left = left + 'px';
  menu.style.visibility = '';
}

function closeEntrySharingMenus() {
  document.querySelectorAll('[data-entry-sharing-menu]').forEach(function (menu) {
    menu.hidden = true;
  });
  document.querySelectorAll('[data-entry-sharing-trigger]').forEach(function (btn) {
    btn.setAttribute('aria-expanded', 'false');
  });
}

function openEntrySharingMenu(root, menu, trigger) {
  closeEntrySharingMenus();
  menu.hidden = false;
  trigger.setAttribute('aria-expanded', 'true');
  syncEntrySharingSharedList(root);
  positionEntrySharingMenu(menu, trigger);
}

function initEntrySharingPopovers(scope) {
  (scope || document).querySelectorAll('[data-entry-sharing]').forEach(function (root) {
    if (root.dataset.entrySharingBound) return;
    root.dataset.entrySharingBound = '1';
    const trigger = root.querySelector('[data-entry-sharing-trigger]');
    const menu = root.querySelector('[data-entry-sharing-menu]');
    if (!trigger || !menu) return;
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (!menu.hidden) {
        closeEntrySharingMenus();
        return;
      }
      openEntrySharingMenu(root, menu, trigger);
    });
    menu.addEventListener('click', function (e) { e.stopPropagation(); });
    root.querySelectorAll('[data-entry-sharing-owner], [data-entry-sharing-shared]').forEach(function (el) {
      el.addEventListener('change', function () {
        syncEntrySharingSharedList(root);
        syncEntrySharingTrigger(root);
      });
    });
  });
}

function entrySharingFieldsHtml(prefix, ownerId, sharedIds, popover) {
  const users = window.SHARING_USER_OPTIONS || [];
  ownerId = ownerId || '';
  sharedIds = sharedIds || [];
  const sharedSet = {};
  sharedIds.forEach(function (id) { sharedSet[id] = true; });
  let html = popover ? '<div class="entry-sharing-panel-head"><strong>Access</strong></div>' : '';
  html += '<label class="mini">Owner</label>';
  if (popover) {
    html += '<div class="entry-sharing-owner-list" data-entry-sharing-owner-list>';
    html += '<label><input type="radio" name="' + prefix + '[owner]" value="" data-entry-sharing-owner'
      + (ownerId === '' ? ' checked' : '') + '> Super only</label>';
    users.forEach(function (u) {
      html += '<label><input type="radio" name="' + prefix + '[owner]" value="' + u.id.replace(/"/g, '&quot;') + '"'
        + ' data-entry-sharing-owner' + (ownerId === u.id ? ' checked' : '') + '> '
        + u.username.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</label>';
    });
    html += '</div>';
  } else {
    html += '<select name="' + prefix + '[owner]" data-entry-sharing-owner><option value="">Super only</option>';
    users.forEach(function (u) {
      html += '<option value="' + u.id.replace(/"/g, '&quot;') + '"' + (ownerId === u.id ? ' selected' : '') + '>'
        + u.username.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>';
    });
    html += '</select>';
  }
  html += '<span class="mini entry-sharing-shared-label">Also shared with</span>';
  html += '<div class="entry-sharing-users-scroll entry-sharing-users" data-entry-sharing-shared-list">';
  users.forEach(function (u) {
    if (ownerId && u.id === ownerId) return;
    html += '<label data-entry-sharing-user="' + u.id.replace(/"/g, '&quot;') + '"><input type="checkbox" name="' + prefix + '[shared][]" value="' + u.id.replace(/"/g, '&quot;') + '"'
      + ' data-entry-sharing-shared' + (sharedSet[u.id] ? ' checked' : '') + '> '
      + u.username.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</label>';
  });
  html += '</div>';
  return html;
}

function entrySharingHtml(prefix, ownerId, sharedIds, compact) {
  const users = window.SHARING_USER_OPTIONS || [];
  if (!users.length) return '';
  let html = '<input type="hidden" name="' + prefix + '[_sharing_form]" value="1">';
  if (compact) {
    html += '<div class="entry-sharing entry-sharing--popover" data-entry-sharing>';
    html += '<button type="button" class="secondary entry-sharing-trigger" data-entry-sharing-trigger>'
      + entryAccessTriggerLabel(ownerId, sharedIds).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</button>';
    html += '<div class="entry-sharing-menu" hidden data-entry-sharing-menu role="dialog" aria-label="Access">';
    html += entrySharingFieldsHtml(prefix, ownerId, sharedIds, true);
    html += '</div></div>';
  } else {
    html += '<div class="entry-sharing">';
    html += entrySharingFieldsHtml(prefix, ownerId, sharedIds, false);
    html += '</div>';
  }
  return html;
}

function photoScreenChecksHtml(idx) {
  const opts = window.PHOTO_SCREEN_OPTIONS || [];
  if (!opts.length) return '';
  const allKeys = opts.map(function (o) { return o.key; });
  let html = '<div class="slide-card-flags"><label><input type="checkbox" name="PHOTOS[' + idx + '][off]"> Disabled</label></div>';
  html += '<div class="slide-card-screens"><span class="mini">Show on displays</span>';
  html += screenPickerHtml('PHOTOS[' + idx + ']', opts, allKeys, {
    summaryMode: 'deck',
    formMarker: true
  });
  html += '</div>';
  html += entrySharingHtml('PHOTOS[' + idx + ']', '', []);
  return html;
}

function slideScreenChecksHtml(idx) {
  const opts = window.SLIDE_SCREEN_OPTIONS || [];
  if (!opts.length) return '';
  const allKeys = opts.map(function (o) { return o.key; });
  let html = '<div class="slide-card-flags">';
  html += '<label><input type="checkbox" name="SLIDES[' + idx + '][priority]"> Priority override</label>';
  html += '<label><input type="checkbox" name="SLIDES[' + idx + '][off]"> Disabled</label></div>';
  html += '<div class="slide-card-screens"><span class="mini">Show on displays</span>';
  html += screenPickerHtml('SLIDES[' + idx + ']', opts, allKeys, {
    summaryMode: 'deck',
    formMarker: true
  });
  html += '</div>';
  html += entrySharingHtml('SLIDES[' + idx + ']', '', []);
  return html;
}

function addPhotoCard() {
  const deck = document.getElementById('photoDeck');
  if (!deck) return;
  const idx = 'n' + (Date.now() % 1e7);
  const proto = deck.querySelector('[data-photo-card]');
  let card;
  if (proto) {
    card = proto.cloneNode(true);
    card.classList.remove('is-off', 'slide-card-highlight', 'dragging');
    card.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
    card.querySelectorAll('input[type="checkbox"]').forEach(function (i) {
      if (/\[screens\]/.test(i.name)) {
        i.checked = true;
      } else {
        i.checked = false;
      }
    });
    card.querySelectorAll('[data-bound]').forEach(function (el) { el.removeAttribute('data-bound'); });
    card.querySelectorAll('[data-screen-picker-bound]').forEach(function (el) { el.removeAttribute('data-screen-picker-bound'); });
    const handle = card.querySelector('.drag-handle');
    if (handle) handle.removeAttribute('data-drag-bound');
    const thumb = card.querySelector('.slide-card-thumb');
    if (thumb) thumb.closest('a')?.remove();
    card.querySelectorAll('.slide-card-actions').forEach(function (el) { el.remove(); });
  } else {
    card = document.createElement('div');
    card.className = 'slide-card';
    card.setAttribute('data-photo-card', '');
    card.innerHTML =
      '<div class="slide-card-head"><span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>' +
      '<div class="slide-card-title"><strong>New photo</strong></div>' +
      '<button type="button" class="rowdel" onclick="this.closest(\'[data-photo-card]\').remove(); reindexPhotoDeck();" title="Remove">×</button></div>' +
      '<div class="slide-card-quick"><div><label class="mini">Sec</label><input type="text" name="PHOTOS[' + idx + '][dwell]" placeholder="18"></div>' +
      '<div><label class="mini">Group</label><input type="text" name="PHOTOS[' + idx + '][group]" placeholder="travel"></div></div>' +
      '<details class="slide-card-edit" open><summary>Options &amp; displays</summary><div class="slide-card-grid">' +
      '<div class="span-2"><label class="mini">Image file</label><input type="text" name="PHOTOS[' + idx + '][file]" placeholder="filename.jpg"></div>' +
      '<div class="span-3"><label class="mini">Label</label><input type="text" name="PHOTOS[' + idx + '][caption]" placeholder="Admin label only"></div></div>' +
      photoScreenChecksHtml(idx) + '</details>';
  }
  deck.appendChild(card);
  bindPlaylistCardHandle(card, deck, '.drag-handle', reindexPhotoDeck);
  bindPhotoCard(card);
  initScreenPickers(card);
  reindexPhotoDeck();
}

function submitSlideDelete(file) {
  if (!file) return;
  if (!confirm('Delete "' + file + '" permanently?\n\nThis removes the image from disk and from the deck. It cannot be undone.')) return;
  const form = document.getElementById('slideDeleteForm');
  const input = document.getElementById('slideDeleteFile');
  if (!form || !input) {
    alert('Could not submit — refresh the page and try again.');
    return;
  }
  input.value = file;
  form.submit();
}

function submitSlideAddToDeck(file) {
  if (!file) return;
  const form = document.getElementById('slideAddForm');
  const input = document.getElementById('slideAddFile');
  if (!form || !input) {
    alert('Could not submit — refresh the page and try again.');
    return;
  }
  input.value = file;
  form.submit();
}

function submitSlideReplace(file) {
  if (!file) return;
  const form = document.getElementById('slideReplaceForm');
  const fileInput = document.getElementById('slideReplaceFile');
  const uploadInput = document.getElementById('slideReplaceUpload');
  if (!form || !fileInput || !uploadInput) {
    alert('Could not submit — refresh the page and try again.');
    return;
  }
  fileInput.value = file;
  uploadInput.value = '';
  uploadInput.onchange = function () {
    if (uploadInput.files && uploadInput.files.length) form.submit();
  };
  uploadInput.click();
}

document.addEventListener('click', function (e) {
  const delBtn = e.target.closest('.slide-delete-btn');
  if (delBtn) {
    e.preventDefault();
    submitSlideDelete(delBtn.getAttribute('data-delete-file') || '');
    return;
  }
  const replaceBtn = e.target.closest('.slide-replace-btn');
  if (replaceBtn) {
    e.preventDefault();
    submitSlideReplace(replaceBtn.getAttribute('data-replace-file') || '');
    return;
  }
  const addBtn = e.target.closest('.slide-add-btn');
  if (addBtn) {
    e.preventDefault();
    submitSlideAddToDeck(addBtn.getAttribute('data-add-file') || '');
    return;
  }
  const photoDelBtn = e.target.closest('.photo-delete-btn');
  if (photoDelBtn) {
    e.preventDefault();
    submitPhotoDelete(photoDelBtn.getAttribute('data-delete-file') || '');
    return;
  }
  const photoAddBtn = e.target.closest('.photo-add-btn');
  if (photoAddBtn) {
    e.preventDefault();
    submitPhotoAddToDeck(photoAddBtn.getAttribute('data-add-file') || '');
  }
});

function addSlideCard() {
  const deck = document.getElementById('slideDeck');
  if (!deck) return;
  const idx = 'n' + (Date.now() % 1e7);
  const proto = deck.querySelector('[data-slide-card]');
  let card;
  if (proto) {
    card = proto.cloneNode(true);
    card.classList.remove('is-off', 'dragging');
    card.querySelectorAll('input[type="text"]').forEach(function (i) { i.value = ''; });
    card.querySelectorAll('input[type="checkbox"]').forEach(function (i) {
      if (/\[screens\]/.test(i.name)) {
        i.checked = true;
      } else {
        i.checked = false;
      }
    });
    const sel = card.querySelector('[data-schedule-select]');
    if (sel) { sel.value = 'always'; sel.removeAttribute('data-bound'); }
    card.querySelectorAll('[data-bound], [data-summary-bound]').forEach(function (el) {
      el.removeAttribute('data-bound');
      el.removeAttribute('data-summary-bound');
    });
    card.querySelectorAll('[data-screen-picker-bound]').forEach(function (el) { el.removeAttribute('data-screen-picker-bound'); });
    const handle = card.querySelector('.drag-handle');
    if (handle) handle.removeAttribute('data-drag-bound');
  } else {
    card = document.createElement('div');
    card.className = 'slide-card';
    card.setAttribute('data-slide-card', '');
    card.innerHTML =
      '<div class="slide-card-head"><span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>' +
      '<div class="slide-card-title"><strong>New slide</strong><span class="slide-card-meta-line">' +
      '<span class="schedule-summary" data-schedule-summary>Always</span></span></div>' +
      '<button type="button" class="rowdel" onclick="this.closest(\'[data-slide-card]\').remove(); reindexSlideDeck();" title="Remove">×</button></div>' +
      '<div class="slide-card-quick"><div><label class="mini">Sec</label><input type="text" name="SLIDES[' + idx + '][dwell]" placeholder="12"></div></div>' +
      '<details class="slide-card-edit"><summary>Schedule &amp; options</summary><div class="slide-card-grid">' +
      '<div class="span-2"><label class="mini">Image file</label><input type="text" name="SLIDES[' + idx + '][file]" placeholder="filename.png"></div>' +
      '<div class="span-3"><label class="mini">Label</label><input type="text" name="SLIDES[' + idx + '][caption]" placeholder="Admin label only (not shown on wall)"></div>' +
      '<div><label class="mini">Schedule</label><select name="SLIDES[' + idx + '][schedule]" data-schedule-select>' +
      SLIDE_SCHEDULES.map(function (s) { return '<option value="' + s + '">' + s + '</option>'; }).join('') +
      '</select></div></div>' +
      slideScreenChecksHtml(idx) + '</details>';
  }
  if (proto) {
    card.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/SLIDES\[[^\]]+\]/, 'SLIDES[' + idx + ']');
    });
  }
  deck.appendChild(card);
  bindPlaylistCardHandle(card, deck, '.drag-handle', reindexSlideDeck);
  bindSlideCard(card);
  initScreenPickers(card);
  reindexSlideDeck();
}

function userScreensCellHtml(idx, role, checkedKeys) {
  if (role === 'super') {
    return '<span class="help" style="margin:0">All displays</span>';
  }
  return screenPickerHtml('USERS[' + idx + ']', window.USER_SCREEN_OPTIONS || [], checkedKeys, {
    summaryMode: 'assign'
  });
}

function bindUserRow(tr) {
  const sel = tr.querySelector('select[name*="[role]"]');
  if (!sel || sel.dataset.bound) return;
  sel.dataset.bound = '1';
  sel.addEventListener('change', function () {
    const idxMatch = sel.name.match(/USERS\[([^\]]+)\]/);
    if (!idxMatch) return;
    const idx = idxMatch[1];
    const cell = tr.querySelector('td.wide');
    if (!cell) return;
    const checked = [];
    cell.querySelectorAll('input[name*="[screens]"]:checked').forEach(function (cb) {
      checked.push(cb.value);
    });
    cell.innerHTML = userScreensCellHtml(idx, sel.value, checked);
    initScreenPickers(tr);
  });
}

function addUserRow() {
  const table = document.getElementById('usersTable');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const idx = 'n' + (Date.now() % 1e7);
  const tr = document.createElement('tr');
  tr.innerHTML =
    '<td><input type="hidden" name="USERS[' + idx + '][id]" value="">' +
    '<input type="text" name="USERS[' + idx + '][username]" placeholder="username" required></td>' +
    '<td><select name="USERS[' + idx + '][role]"><option value="operator" selected>operator</option><option value="super">super</option></select></td>' +
    '<td class="wide">' + userScreensCellHtml(idx, 'operator', []) + '</td>' +
    '<td style="text-align:center"><input type="checkbox" name="USERS[' + idx + '][disabled]" value="1" style="width:20px;height:20px;accent-color:var(--beacon)"></td>' +
    '<td><input type="password" name="USERS[' + idx + '][new_password]" autocomplete="new-password" placeholder="Required for new user"></td>' +
    '<td><button type="button" class="rowdel" onclick="this.closest(\'tr\').remove()">×</button></td>';
  tbody.appendChild(tr);
  bindUserRow(tr);
  initScreenPickers(tr);
}

document.addEventListener('DOMContentLoaded', function () {
  initSlideDeck();
  initSlidesSectionNav();
  initPhotoDeck();
  initPhotosSectionNav();
  initScreenPickers(document);
  initEntrySharingPopovers(document);
  initDeployPanelFilters();
  initRotationTargetFilter();
  document.querySelectorAll('#usersTable tbody tr').forEach(bindUserRow);
  initVideoPlaylist();
  initSplunkPanels();
  initPresencePanel();
  initRssFeedPreviews();
  initWebSitePreviews();
  initKeyedBoardPreviews();
  initRotationDecks();
  initRotationGlobalAdd();
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-entry-sharing-menu], [data-entry-sharing-trigger]')) return;
    closeEntrySharingMenus();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeEntrySharingMenus();
  });
  window.addEventListener('resize', function () {
    document.querySelectorAll('[data-entry-sharing]').forEach(function (root) {
      const menu = root.querySelector('[data-entry-sharing-menu]');
      const trigger = root.querySelector('[data-entry-sharing-trigger]');
      if (menu && !menu.hidden) positionEntrySharingMenu(menu, trigger);
    });
  });
  document.querySelectorAll('.quick-add-rotation').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const sel = document.getElementById('rotationTargetScreen');
      const deckId = sel ? sel.value : '';
      const deck = deckId ? document.getElementById(deckId) : null;
      if (!deck) return;
      addRotationPage(deck.id, btn.dataset.url || '', btn.dataset.dwell || '60');
      const panel = deck.closest('.rotation-playlist-panel');
      if (panel) panel.open = true;
    });
  });
});

function initRotationGlobalAdd() {
  const sel = document.getElementById('rotationTargetScreen');
  const copyBtn = document.getElementById('rotationCopyMainBtn');
  if (!sel || !copyBtn) return;
  function syncCopyBtn() {
    copyBtn.style.display = sel.value === 'rotationDeck-main' ? 'none' : '';
  }
  sel.addEventListener('change', syncCopyBtn);
  syncCopyBtn();
}

const ROTATION_STARTER = <?= json_encode($rotationStarterPages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const ROTATION_MAIN_PAGES = <?= json_encode($rotationMainPages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function bindPlaylistDeckDrag(deck, cardSelector, handleSelector, reindexFn, bindCardFn) {
  if (deck.dataset.dragDeckBound) {
    deck.querySelectorAll(cardSelector).forEach(function (card) { bindCardFn(card, deck); });
    return;
  }
  deck.dataset.dragDeckBound = '1';
  deck.addEventListener('dragover', function (e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const dragCard = deck._dragCard;
    if (!dragCard) return;
    const cards = Array.from(deck.querySelectorAll(cardSelector)).filter(function (c) { return c !== dragCard; });
    let placed = false;
    for (let i = 0; i < cards.length; i++) {
      const rect = cards[i].getBoundingClientRect();
      if (e.clientY < rect.top + rect.height / 2) {
        deck.insertBefore(dragCard, cards[i]);
        placed = true;
        break;
      }
    }
    if (!placed) deck.appendChild(dragCard);
    reindexFn(deck);
  });
  deck.addEventListener('drop', function (e) { e.preventDefault(); });
  deck.querySelectorAll(cardSelector).forEach(function (card) { bindCardFn(card, deck); });
}

function bindPlaylistCardHandle(card, deck, handleSelector, reindexFn) {
  const handle = card.querySelector(handleSelector);
  if (!handle || handle.dataset.dragBound) return;
  handle.dataset.dragBound = '1';
  handle.setAttribute('draggable', 'true');
  handle.addEventListener('dragstart', function (e) {
    deck._dragCard = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', 'move');
  });
  handle.addEventListener('dragend', function () {
    card.classList.remove('dragging');
    deck._dragCard = null;
    reindexFn(deck);
  });
}

function setRotationPlaylistsOpen(open) {
  document.querySelectorAll('.rotation-playlist-panel').forEach(function (el) {
    if (open) el.setAttribute('open', '');
    else el.removeAttribute('open');
  });
}

function rotationLabelFromUrl(url) {
  url = (url || '').trim();
  if (!url) return 'New page';
  if (/^video\.php\?v=/.test(url)) return 'Video — ' + decodeURIComponent((url.split('=')[1] || '').split('&')[0] || 'video');
  if (/^rss\.php\?feed=/.test(url)) return 'RSS — ' + decodeURIComponent((url.split('=')[1] || '').split('&')[0] || 'feed');
  if (/^grafana\.php\?d=/.test(url)) return 'Grafana — ' + decodeURIComponent((url.split('=')[1] || '').split('&')[0] || 'dashboard');
  if (/^splunk\.php/.test(url)) {
    const m = url.match(/[?&]d=([^&]+)/);
    return 'Splunk — ' + decodeURIComponent((m && m[1]) || 'main');
  }
  if (/^web\.php/.test(url)) {
    const m = url.match(/[?&]d=([^&]+)/);
    return 'Web — ' + decodeURIComponent((m && m[1]) || 'main');
  }
  const slideMatch = url.match(/(?:^|\?|&)slide=([^&]+)/);
  if (/^slides\.php/.test(url) && slideMatch) return 'Slide — ' + decodeURIComponent(slideMatch[1]);
  const boards = {
    'index.php': 'Weather', 'lake.php': 'Lake Michigan', 'webcam.php': 'Grand Haven webcam', 'photo.php': 'Photo conditions',
    'family.php': 'Family calendar', 'traffic.php': 'Traffic map', 'air.php': 'Air & pollen', 'sports.php': 'Detroit sports', 'homelab.php': 'Homelab status',
    'signaltrace.php': 'SignalTrace', 'rotator.php': 'Photo rotator', 'slides.php': 'Custom slides',
    'rss.php': 'RSS stories', 'video.php': 'Video board', 'splunk.php': 'Splunk panels', 'splunkdash.php': 'Splunk dashboard',
    'web.php': 'Website'
  };
  const base = url.split('?')[0];
  return boards[base] || base;
}

function reindexRotationDeck(deck) {
  if (!deck || !deck.dataset.field) return;
  const field = deck.dataset.field;
  deck.querySelectorAll('[data-rotation-card]').forEach(function (card, i) {
    card.querySelectorAll('[name^="' + field + '["]').forEach(function (inp) {
      inp.name = inp.name.replace(new RegExp(field.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[[^\\]]+\\]'), field + '[' + i + ']');
    });
  });
}

function syncRotationEmptyState(deck) {
  if (!deck) return;
  const empty = deck.querySelector('[data-rotation-empty]');
  if (empty) empty.hidden = !!deck.querySelector('[data-rotation-card]');
}

function removeRotationCard(btn, deckId) {
  const card = btn.closest('[data-rotation-card]');
  const deck = document.getElementById(deckId);
  if (card) card.remove();
  if (deck) {
    reindexRotationDeck(deck);
    syncRotationEmptyState(deck);
  }
}

function fillRotationCardTimes(card, page) {
  if (!card || !page) return;
  if (page.from != null && page.from !== '') {
    const from = card.querySelector('[name*="[from]"]');
    if (from) from.value = String(page.from);
  }
  if (page.to != null && page.to !== '') {
    const to = card.querySelector('[name*="[to]"]');
    if (to) to.value = String(page.to);
  }
  if (page.off) {
    const off = card.querySelector('[name*="[off]"]');
    if (off) off.checked = true;
  }
}

function loadRotationStarter(deckId) {
  const deck = document.getElementById(deckId);
  if (!deck) return;
  if (deck.querySelector('[data-rotation-card]') && !confirm('Replace the current playlist with the starter set?')) return;
  deck.querySelectorAll('[data-rotation-card]').forEach(function (c) { c.remove(); });
  ROTATION_STARTER.forEach(function (p) {
    addRotationPage(deckId, p.url || '', String(p.dwell || 60), false);
    fillRotationCardTimes(deck.querySelector('[data-rotation-card]:last-child'), p);
  });
  reindexRotationDeck(deck);
  syncRotationEmptyState(deck);
}

function copyRotationFromMain(deckId) {
  const deck = document.getElementById(deckId);
  if (!deck) return;
  const pages = ROTATION_MAIN_PAGES || [];
  if (!pages.length) {
    alert('Main screen has no saved playlist yet.');
    return;
  }
  if (deck.querySelector('[data-rotation-card]') && !confirm('Replace this playlist with a copy of main?')) return;
  deck.querySelectorAll('[data-rotation-card]').forEach(function (c) { c.remove(); });
  pages.forEach(function (p) {
    addRotationPage(deckId, p.url || '', String(p.dwell || 60), false);
    fillRotationCardTimes(deck.querySelector('[data-rotation-card]:last-child'), p);
  });
  reindexRotationDeck(deck);
  syncRotationEmptyState(deck);
}

function bindRotationCard(card, deck) {
  const urlInp = card.querySelector('[data-rotation-url]');
  const labelEl = card.querySelector('[data-rotation-label]');
  const codeEl = card.querySelector('[data-rotation-url-display]');
  const preview = card.querySelector('[data-rotation-preview]');
  function syncHead() {
    const u = urlInp ? urlInp.value.trim() : '';
    if (labelEl) labelEl.textContent = rotationLabelFromUrl(u);
    if (codeEl) codeEl.textContent = u || 'board URL';
    if (preview) {
      if (u) { preview.href = u; preview.style.display = ''; }
      else { preview.style.display = 'none'; }
    } else if (u) {
      let actions = card.querySelector('.rotation-card-actions');
      if (!actions) {
        actions = document.createElement('div');
        actions.className = 'rotation-card-actions';
        const meta = card.querySelector('.rotation-card-meta');
        if (meta) meta.appendChild(actions);
      }
      actions.innerHTML = '<a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px" href="' +
        u.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener" data-rotation-preview>Preview</a>';
    }
  }
  if (urlInp && !urlInp.dataset.bound) {
    urlInp.dataset.bound = '1';
    urlInp.addEventListener('input', syncHead);
  }
  syncHead();
}

function bindRotationCardDrag(card, deck) {
  bindPlaylistCardHandle(card, deck, '.drag-handle', reindexRotationDeck);
}

function initRotationDecks() {
  document.querySelectorAll('.rotation-playlist').forEach(function (deck) {
    bindPlaylistDeckDrag(deck, '[data-rotation-card]', '.drag-handle', reindexRotationDeck, function (card, d) {
      bindRotationCard(card, d);
      bindRotationCardDrag(card, d);
    });
    syncRotationEmptyState(deck);
  });
}

function addRotationPage(deckId, url, dwell, scroll) {
  if (scroll === undefined) scroll = true;
  const deck = typeof deckId === 'string' ? document.getElementById(deckId) : deckId;
  if (!deck || !deck.dataset.field) return null;
  const field = deck.dataset.field;
  reindexRotationDeck(deck);
  const idx = deck.querySelectorAll('[data-rotation-card]').length;
  url = (url || '').trim();
  dwell = (dwell || '').toString().trim() || '60';
  const card = document.createElement('div');
  card.className = 'rotation-card';
  card.setAttribute('data-rotation-card', '');
  card.innerHTML =
    '<div class="rotation-card-head">' +
      '<span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>' +
      '<div class="rotation-card-title"><strong data-rotation-label>' + rotationLabelFromUrl(url) + '</strong>' +
      '<code data-rotation-url-display>' + (url || 'board URL') + '</code></div>' +
      '<div class="rotation-card-head-meta">' +
        '<label class="check" style="margin:0"><input type="checkbox" name="' + field + '[' + idx + '][off]"> Skip</label>' +
        '<button type="button" class="rowdel" onclick="removeRotationCard(this, \'' + deck.id + '\')" title="Remove">×</button>' +
      '</div>' +
    '</div>' +
    '<details class="rotation-card-edit"><summary>Dwell ' + dwell.replace(/"/g, '') + 's</summary>' +
    '<div class="rotation-card-grid">' +
      '<div style="grid-column:1 / -1"><label class="mini">URL</label>' +
      '<input type="text" name="' + field + '[' + idx + '][url]" value="' + url.replace(/"/g, '&quot;') + '" placeholder="slides.php" data-rotation-url required></div>' +
      '<div><label class="mini">Dwell (s)</label><input type="text" name="' + field + '[' + idx + '][dwell]" value="' + dwell.replace(/"/g, '&quot;') + '" placeholder="60"></div>' +
      '<div><label class="mini">From hr</label><input type="text" name="' + field + '[' + idx + '][from]" placeholder="0-23"></div>' +
      '<div><label class="mini">To hr</label><input type="text" name="' + field + '[' + idx + '][to]" placeholder="0-23"></div>' +
      '<div><label class="mini">Weight</label><input type="text" name="' + field + '[' + idx + '][weight]" placeholder="1" title="Used when Weighted is on for this display (1–20)"></div>' +
    '</div></details>';
  deck.appendChild(card);
  bindRotationCard(card, deck);
  bindRotationCardDrag(card, deck);
  reindexRotationDeck(deck);
  syncRotationEmptyState(deck);
  if (scroll) card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  return card;
}

function reindexVideoPlaylist() {
  const deck = document.getElementById('videoPlaylist');
  if (!deck) return;
  deck.querySelectorAll('[data-video-card]').forEach(function (card, i) {
    card.querySelectorAll('[name^="VIDEOS["]').forEach(function (inp) {
      inp.name = inp.name.replace(/VIDEOS\[[^\]]+\]/, 'VIDEOS[' + i + ']');
    });
  });
}

function bindVideoCard(card) {
  const keyInp = card.querySelector('[data-video-key]');
  const titleInp = card.querySelector('[data-video-title]');
  const headStrong = card.querySelector('.video-card-title strong');
  const headCode = card.querySelector('.video-card-title code');
  function syncHead() {
    const k = keyInp ? keyInp.value.trim() : '';
    if (headStrong) headStrong.textContent = (titleInp && titleInp.value.trim()) || k || 'New video';
    if (headCode) headCode.textContent = k ? ('video.php?v=' + encodeURIComponent(k)) : 'video.php?v=KEY';
  }
  if (keyInp && !keyInp.dataset.bound) {
    keyInp.dataset.bound = '1';
    keyInp.addEventListener('input', syncHead);
  }
  if (titleInp && !titleInp.dataset.bound) {
    titleInp.dataset.bound = '1';
    titleInp.addEventListener('input', syncHead);
  }
  syncHead();
}

function bindVideoCardDrag(card, deck) {
  bindPlaylistCardHandle(card, deck, '.drag-handle', reindexVideoPlaylist);
}

function initVideoPlaylist() {
  const deck = document.getElementById('videoPlaylist');
  if (!deck) return;
  bindPlaylistDeckDrag(deck, '[data-video-card]', '.drag-handle', reindexVideoPlaylist, function (card, d) {
    bindVideoCard(card);
    bindVideoCardDrag(card, d);
  });
}

function addVideoCard() {
  const deck = document.getElementById('videoPlaylist');
  if (!deck) return;
  reindexVideoPlaylist();
  const idx = deck.querySelectorAll('[data-video-card]').length;
  const card = document.createElement('div');
  card.className = 'video-card';
  card.setAttribute('data-video-card', '');
  card.innerHTML =
    '<div class="video-card-head">' +
      '<span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>' +
      '<div class="video-card-title"><strong>New video</strong><code>video.php?v=KEY</code></div>' +
      '<button type="button" class="rowdel" onclick="this.closest(\'[data-video-card]\').remove(); reindexVideoPlaylist();" title="Remove">×</button>' +
    '</div>' +
    '<div class="video-card-grid">' +
      '<div><label class="mini">Key</label><input type="text" name="VIDEOS[' + idx + '][_key]" placeholder="lantern" required data-video-key></div>' +
      '<div><label class="mini">Title (optional)</label><input type="text" name="VIDEOS[' + idx + '][title]" placeholder="On-screen title" data-video-title></div>' +
      '<div><label class="mini">YouTube URL</label><input type="text" name="VIDEOS[' + idx + '][youtube]" placeholder="https://youtube.com/watch?v=…"></div>' +
      '<div style="grid-column:2 / -1"><label class="mini">or local file</label><input type="text" name="VIDEOS[' + idx + '][file]" placeholder="lantern.mp4 in videos/"></div>' +
    '</div>' +
    entrySharingHtml('VIDEOS[' + idx + ']', '', []) +
    '<div class="video-card-meta"><span class="pill warn">Not on rotation</span><span>Save, then fetch or upload file</span></div>';
  deck.appendChild(card);
  bindVideoCard(card);
  bindVideoCardDrag(card, deck);
  reindexVideoPlaylist();
}

function splunkCsrf() {
  const el = document.querySelector('#boardform input[name=csrf]');
  return el ? el.value : '';
}

function syncSplunkPanelTypeFields(card) {
  const type = (card.querySelector('[data-splunk-type]') || {}).value || 'single';
  card.querySelectorAll('[data-splunk-field]').forEach(function (el) {
    const types = (el.getAttribute('data-splunk-field') || '').split(',');
    el.style.display = types.indexOf(type) >= 0 ? '' : 'none';
  });
}

function syncSplunkPanelHead(card) {
  const titleInp = card.querySelector('[data-splunk-title]');
  const typeSel = card.querySelector('[data-splunk-type]');
  const strong = card.querySelector('[data-splunk-title-display]');
  const code = card.querySelector('.video-card-title code');
  const wide = card.querySelector('[name*="[wide]"]');
  const off = card.querySelector('[data-splunk-off]');
  if (strong) strong.textContent = (titleInp && titleInp.value.trim()) || 'New panel';
  if (code && typeSel) {
    const labels = { single: 'Single stat', list: 'Bar list', trend: 'Trend chart' };
    let t = labels[typeSel.value] || typeSel.value;
    if (wide && wide.checked) t += ' · wide';
    code.textContent = t;
  }
  card.classList.toggle('is-off', !!(off && off.checked));
  syncSplunkPanelTypeFields(card);
}

function bindSplunkPanelCard(card) {
  if (card.dataset.bound) return;
  card.dataset.bound = '1';
  ['[data-splunk-title]', '[data-splunk-type]'].forEach(function (sel) {
    const el = card.querySelector(sel);
    if (el) el.addEventListener('input', function () { syncSplunkPanelHead(card); });
    if (el) el.addEventListener('change', function () { syncSplunkPanelHead(card); });
  });
  const wide = card.querySelector('[name*="[wide]"]');
  const off = card.querySelector('[data-splunk-off]');
  if (wide) wide.addEventListener('change', function () { syncSplunkPanelHead(card); });
  if (off) off.addEventListener('change', function () { syncSplunkPanelHead(card); });
  const testBtn = card.querySelector('[data-splunk-test]');
  if (testBtn) testBtn.addEventListener('click', function () { testSplunkPanel(testBtn); });
  syncSplunkPanelHead(card);
}

function reindexSplunkPanels(deck) {
  if (!deck) return;
  const pageKey = deck.getAttribute('data-splunk-panels-deck') || 'main';
  const re = new RegExp('PAGES\\[' + pageKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\]\\[panels\\]\\[\\d+\\]');
  deck.querySelectorAll('[data-splunk-panel-card]').forEach(function (card, i) {
    card.querySelectorAll('[name*="[panels]"]').forEach(function (inp) {
      inp.name = inp.name.replace(re, 'PAGES[' + pageKey + '][panels][' + i + ']');
    });
  });
  const empty = deck.querySelector('.rotation-playlist-empty');
  if (empty) empty.style.display = deck.querySelector('[data-splunk-panel-card]') ? 'none' : '';
}

function bindSplunkPanelDrag(card, deck) {
  bindPlaylistCardHandle(card, deck, '.drag-handle', function () { reindexSplunkPanels(deck); });
}

function showSplunkPage(pageKey) {
  document.querySelectorAll('[data-splunk-page-editor]').forEach(function (el) {
    el.style.display = el.getAttribute('data-splunk-page-editor') === pageKey ? '' : 'none';
  });
  document.querySelectorAll('[data-splunk-page-tab]').forEach(function (btn) {
    btn.classList.toggle('active', btn.getAttribute('data-splunk-page-tab') === pageKey);
  });
}

function syncSplunkPageTabLabel(titleInp) {
  const editor = titleInp.closest('[data-splunk-page-editor]');
  if (!editor) return;
  const pageKey = editor.getAttribute('data-splunk-page-editor');
  const tab = document.querySelector('[data-splunk-page-tab="' + pageKey + '"]');
  if (!tab) return;
  const title = titleInp.value.trim() || pageKey;
  const code = tab.querySelector('code');
  tab.textContent = '';
  tab.appendChild(document.createTextNode(title + ' '));
  const codeEl = code || document.createElement('code');
  codeEl.textContent = pageKey;
  tab.appendChild(codeEl);
}

function bindSplunkPageTab(btn) {
  if (btn.dataset.bound) return;
  btn.dataset.bound = '1';
  btn.addEventListener('click', function () {
    showSplunkPage(btn.getAttribute('data-splunk-page-tab'));
  });
}

function bindSplunkPageTabs() {
  document.querySelectorAll('[data-splunk-page-tab]').forEach(bindSplunkPageTab);
}

function splunkNormalizePageKey(raw) {
  raw = (raw || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
  return raw || 'page';
}

function splunkPreviewHref(pageKey) {
  return 'splunk.php?d=' + encodeURIComponent(pageKey) + '&' + RSS_PREVIEW_SUFFIX;
}

function initPresencePanel() {
  const panel = document.getElementById('presencePanel');
  const logPanel = document.getElementById('playLogPanel');
  if (!panel) return;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderPlayLog(events) {
    if (!logPanel) return;
    if (!events || !events.length) {
      logPanel.innerHTML = '';
      return;
    }
    let rows = '';
    events.forEach(function (ev) {
      rows += '<tr>'
        + '<td>' + esc(ev.time) + '</td>'
        + '<td><code>' + esc(ev.screen) + '</code></td>'
        + '<td>' + esc(ev.label) + '</td>'
        + '<td><code>' + esc(ev.url) + '</code></td>'
        + '</tr>';
    });
    logPanel.innerHTML = '<details class="play-log-panel" open>'
      + '<summary>Play log — ' + events.length + ' recent on-screen events</summary>'
      + '<table class="play-log"><thead><tr>'
      + '<th>When</th><th>Display</th><th>Page</th><th>URL</th>'
      + '</tr></thead><tbody>' + rows + '</tbody></table></details>';
  }

  function render(data) {
    if (!data || !Array.isArray(data.screens)) {
      panel.innerHTML = '<p class="help">No display data yet — open a kiosk on <code>board.php</code>.</p>';
      return;
    }
    let html = '<table class="presence-status"><thead><tr>'
      + '<th>Display</th><th>Status</th><th>Now showing</th><th>Plays today</th>'
      + '</tr></thead><tbody>';
    data.screens.forEach(function (s) {
      const dotCls = s.online ? 'presence-dot online' : 'presence-dot';
      let statusText = s.online ? 'Online' : 'Offline';
      if (s.online && s.blank) statusText = 'Online · blank';
      let nowHtml = '<span class="presence-now muted">—</span>';
      if (s.online && s.now && s.now.label) {
        nowHtml = '<span class="presence-now">' + esc(s.now.label) + '</span>';
        if (s.now.index >= 0 && s.now.total > 0) {
          nowHtml += ' <span class="presence-stats">(' + (s.now.index + 1) + '/' + s.now.total + ')</span>';
        }
        if (s.now.status === 'loading') {
          nowHtml += ' <span class="presence-stats">loading…</span>';
        }
      } else if (!s.online) {
        nowHtml = '<span class="presence-now muted">Last seen ' + esc(s.last_seen_ago || 'never') + '</span>';
      } else if (s.online && s.now && s.now.status === 'empty') {
        nowHtml = '<span class="presence-now muted">No pages in rotation</span>';
      }
      let plays = String(s.plays_today || 0);
      if (s.top_today && s.top_today.length) {
        plays += '<div class="presence-top">'
          + s.top_today.map(function (t) { return esc(t.label) + ' ×' + t.count; }).join(' · ')
          + '</div>';
      }
      html += '<tr>'
        + '<td><span class="' + dotCls + '"></span><code>' + esc(s.screen) + '</code> ' + esc(s.name) + '</td>'
        + '<td>' + esc(statusText) + '</td>'
        + '<td>' + nowHtml + '</td>'
        + '<td>' + plays + '</td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    if (data.stats_day) {
      html += '<p class="help" style="margin-top:8px">Stats day: ' + esc(data.stats_day)
        + ' · refreshes every 20s</p>';
    }
    panel.innerHTML = html;
    renderPlayLog(data.play_log || []);
  }

  function load() {
    fetch('admin.php?board=rotation&action=presence', { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(render)
      .catch(function () {});
  }

  load();
  setInterval(load, 20000);
}

function initSplunkPanels() {
  document.querySelectorAll('[data-splunk-panels-deck]').forEach(function (deck) {
    bindPlaylistDeckDrag(deck, '[data-splunk-panel-card]', '.drag-handle', function () { reindexSplunkPanels(deck); }, function (card, d) {
      bindSplunkPanelCard(card);
      bindSplunkPanelDrag(card, d);
    });
  });
  bindSplunkPageTabs();
  document.querySelectorAll('[data-splunk-page-title]').forEach(function (inp) {
    inp.addEventListener('input', function () { syncSplunkPageTabLabel(inp); });
  });
}

function addSplunkPage() {
  const key = prompt('Page key (letters, numbers, underscore — used in splunk.php?d=KEY):', 'page2');
  if (key === null) return;
  const pageKey = splunkNormalizePageKey(key);
  if (document.querySelector('[data-splunk-page-editor="' + pageKey + '"]')) {
    alert('A page with key "' + pageKey + '" already exists.');
    showSplunkPage(pageKey);
    return;
  }
  const bar = document.getElementById('splunkPagesBar');
  const tab = document.createElement('button');
  tab.type = 'button';
  tab.className = 'splunk-page-tab';
  tab.setAttribute('data-splunk-page-tab', pageKey);
  tab.appendChild(document.createTextNode('New page '));
  const tabCode = document.createElement('code');
  tabCode.textContent = pageKey;
  tab.appendChild(tabCode);
  if (bar) {
    const addBtn = bar.querySelector('.addrow');
    if (addBtn) bar.insertBefore(tab, addBtn);
    else bar.appendChild(tab);
  }
  bindSplunkPageTab(tab);

  const editor = document.createElement('div');
  editor.className = 'splunk-page-editor';
  editor.setAttribute('data-splunk-page-editor', pageKey);
  editor.style.display = 'none';
  editor.innerHTML =
    '<input type="hidden" name="PAGES[' + pageKey + '][_key]" value="' + pageKey + '" data-splunk-page-key>' +
    '<div class="splunk-page-head">' +
      '<div><label class="mini">Page title</label><input type="text" name="PAGES[' + pageKey + '][title]" placeholder="SOC Overview" data-splunk-page-title></div>' +
      '<div><label class="mini">Subtitle</label><input type="text" name="PAGES[' + pageKey + '][sub]" placeholder="Home network" data-splunk-page-sub></div>' +
      '<div style="display:flex;gap:10px;align-items:center;padding-bottom:4px">' +
        '<a class="secondary" style="padding:6px 12px;text-decoration:none;font-size:13px;white-space:nowrap" href="' + splunkPreviewHref(pageKey) + '" target="_blank" rel="noopener" data-splunk-page-preview>Preview ↗</a>' +
        '<button type="button" class="rowdel" style="width:auto;padding:6px 12px;font-size:13px" onclick="removeSplunkPage(\'' + pageKey + '\')" title="Remove page">Remove page</button>' +
      '</div>' +
    '</div>' +
    '<div class="help" style="margin-bottom:10px">Rotation URL: <code>splunk.php?d=' + pageKey + '</code></div>' +
    entrySharingHtml('PAGES[' + pageKey + ']', '', []) +
    '<div class="splunk-playlist video-playlist" data-splunk-panels-deck="' + pageKey + '">' +
      '<div class="rotation-playlist-empty">No panels yet — add one below.</div>' +
    '</div>' +
    '<button type="button" class="addrow" style="margin-top:12px" onclick="addSplunkPanelCard(\'' + pageKey + '\')">+ Add panel</button>';

  const jsonDetails = document.querySelector('textarea[name="PAGES_JSON"]');
  const mount = jsonDetails ? jsonDetails.closest('details') : null;
  if (mount && mount.parentNode) mount.parentNode.insertBefore(editor, mount);
  else document.getElementById('boardform').appendChild(editor);

  const titleInp = editor.querySelector('[data-splunk-page-title]');
  if (titleInp) titleInp.addEventListener('input', function () { syncSplunkPageTabLabel(titleInp); });

  const deck = editor.querySelector('[data-splunk-panels-deck]');
  bindPlaylistDeckDrag(deck, '[data-splunk-panel-card]', '.drag-handle', function () { reindexSplunkPanels(deck); }, function (card, d) {
    bindSplunkPanelCard(card);
    bindSplunkPanelDrag(card, d);
  });
  showSplunkPage(pageKey);
}

function removeSplunkPage(pageKey) {
  if (!confirm('Remove page "' + pageKey + '" and all its panels?')) return;
  const editor = document.querySelector('[data-splunk-page-editor="' + pageKey + '"]');
  const tab = document.querySelector('[data-splunk-page-tab="' + pageKey + '"]');
  if (editor) editor.remove();
  if (tab) tab.remove();
  const remaining = document.querySelector('[data-splunk-page-tab]');
  if (remaining) showSplunkPage(remaining.getAttribute('data-splunk-page-tab'));
}

function addSplunkPanelCard(pageKey) {
  pageKey = pageKey || 'main';
  const deck = document.querySelector('[data-splunk-panels-deck="' + pageKey + '"]');
  if (!deck) return;
  reindexSplunkPanels(deck);
  const idx = deck.querySelectorAll('[data-splunk-panel-card]').length;
  const p = 'PAGES[' + pageKey + '][panels][' + idx + ']';
  const empty = deck.querySelector('.rotation-playlist-empty');
  if (empty) empty.style.display = 'none';
  const card = document.createElement('div');
  card.className = 'video-card splunk-panel-card';
  card.setAttribute('data-splunk-panel-card', '');
  card.innerHTML =
    '<div class="video-card-head">' +
      '<span class="drag-handle" title="Drag to reorder" draggable="true">⋮⋮</span>' +
      '<div class="video-card-title"><strong data-splunk-title-display>New panel</strong><code>Single stat</code></div>' +
      '<button type="button" class="rowdel" onclick="this.closest(\'[data-splunk-panel-card]\').remove(); reindexSplunkPanels(this.closest(\'[data-splunk-panels-deck]\'));" title="Remove">×</button>' +
    '</div>' +
    '<div class="splunk-panel-card-grid">' +
      '<div><label class="mini">Title</label><input type="text" name="' + p + '[title]" placeholder="Events Today" data-splunk-title></div>' +
      '<div><label class="mini">Type</label><select name="' + p + '[type]" data-splunk-type>' +
        '<option value="single">Single stat</option><option value="list">Bar list</option><option value="trend">Trend chart</option>' +
      '</select></div>' +
      '<div data-splunk-field="single"><label class="mini">Unit (single)</label><input type="text" name="' + p + '[unit]" placeholder="events"></div>' +
      '<div class="span-3"><label class="mini">SPL</label><textarea name="' + p + '[spl]" placeholder="index=main | stats count" data-splunk-spl></textarea></div>' +
      '<div data-splunk-field="single"><label class="mini">Value field (single)</label><input type="text" name="' + p + '[field]" placeholder="count"></div>' +
      '<div data-splunk-field="list"><label class="mini">Label field (list)</label><input type="text" name="' + p + '[label]" placeholder="country"></div>' +
      '<div data-splunk-field="list,trend"><label class="mini">Value field (list / trend)</label><input type="text" name="' + p + '[value]" placeholder="count"></div>' +
      '<div><label class="mini">Earliest</label><input type="text" name="' + p + '[earliest]" placeholder="-24h@h"></div>' +
      '<div><label class="mini">Latest</label><input type="text" name="' + p + '[latest]" placeholder="now"></div>' +
      '<div style="display:flex;align-items:flex-end;gap:16px;padding-bottom:4px">' +
        '<label class="check" style="margin:0"><input type="checkbox" name="' + p + '[wide]"> Wide (2 cols)</label>' +
        '<label class="check" style="margin:0"><input type="checkbox" name="' + p + '[off]" data-splunk-off> Off wall</label>' +
      '</div>' +
    '</div>' +
    '<div class="video-card-meta"><div class="splunk-test-result" data-splunk-test-result></div>' +
      '<div class="video-card-actions"><button type="button" class="secondary" style="padding:6px 12px;font-size:13px" data-splunk-test>Test search</button></div></div>';
  deck.appendChild(card);
  bindSplunkPanelCard(card);
  bindSplunkPanelDrag(card, deck);
  reindexSplunkPanels(deck);
}

function testSplunkPanel(btn) {
  const card = btn.closest('[data-splunk-panel-card]');
  if (!card) return;
  const resultEl = card.querySelector('[data-splunk-test-result]');
  const fd = new FormData();
  fd.append('action', 'splunk_test_panel');
  fd.append('csrf', splunkCsrf());
  fd.append('board', 'splunk');
  ['title', 'type', 'spl', 'field', 'label', 'value', 'unit', 'earliest', 'latest'].forEach(function (k) {
    const el = card.querySelector('[name*="[' + k + ']"]');
    if (el) fd.append(k, el.value);
  });
  if (resultEl) {
    resultEl.className = 'splunk-test-result';
    resultEl.textContent = 'Testing…';
  }
  btn.disabled = true;
  fetch('?board=splunk', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!resultEl) return;
      if (data.ok) {
        resultEl.className = 'splunk-test-result ok';
        resultEl.textContent = (data.rows || 0) + ' rows — ' + (data.preview || 'OK');
      } else {
        resultEl.className = 'splunk-test-result err';
        resultEl.textContent = data.error || 'Search failed';
      }
    })
    .catch(function () {
      if (resultEl) {
        resultEl.className = 'splunk-test-result err';
        resultEl.textContent = 'Request failed';
      }
    })
    .finally(function () { btn.disabled = false; });
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
        if (slide_background_is_photo($bp)) {
            $bp['kind'] = 'photo';
        }
    }
    unset($bp);
    echo json_encode($bgJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
  ?>;
  const TEMPLATES = <?= json_encode(slide_creator_templates(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  const bgImageCache = {};

  function loadBgImage(url) {
    if (!url) return Promise.resolve(null);
    if (bgImageCache[url]) return Promise.resolve(bgImageCache[url]);
    return new Promise(function (resolve) {
      const img = new Image();
      img.crossOrigin = 'anonymous';
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

  function drawImageCover(c, img, w, h) {
    const ir = img.width / img.height;
    const cr = w / h;
    let sw, sh, sx, sy;
    if (ir > cr) {
      sh = img.height;
      sw = sh * cr;
      sx = (img.width - sw) / 2;
      sy = 0;
    } else {
      sw = img.width;
      sh = sw / cr;
      sx = 0;
      sy = (img.height - sh) / 2;
    }
    c.drawImage(img, sx, sy, sw, sh, 0, 0, w, h);
  }

  function drawPhotoOverlay(c, preset) {
    const ov = preset.overlay || {};
    if (ov.gradient) {
      c.fillStyle = ov.gradient.type === 'radial' ? radialGradient(c, ov.gradient) : linearGradient(c, ov.gradient);
      c.fillRect(0, 0, W, H);
    }
    if (ov.base) {
      c.fillStyle = 'rgba(12, 20, 34, ' + ov.base + ')';
      c.fillRect(0, 0, W, H);
    }
  }

  function drawBackground(c, preset) {
    const isPhoto = preset.kind === 'photo' || !!preset.photo;
    if (isPhoto && preset.url && bgImageCache[preset.url]) {
      drawImageCover(c, bgImageCache[preset.url], W, H);
      drawPhotoOverlay(c, preset);
      return;
    }
    if (preset.url && bgImageCache[preset.url] && !isPhoto) {
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
    const paragraphs = String(text).replace(/\r\n/g, '\n').split('\n');
    const lines = [];
    paragraphs.forEach(function (para, pi) {
      const words = para.replace(/\s+/g, ' ').trim().split(' ');
      if (!words[0] || words[0] === '') {
        if (pi < paragraphs.length - 1) lines.push('');
        return;
      }
      let line = words[0];
      for (let i = 1; i < words.length; i++) {
        const test = line + ' ' + words[i];
        if (c.measureText(test).width <= maxWidth) line = test;
        else { lines.push(line); line = words[i]; }
      }
      lines.push(line);
    });
    return lines;
  }

  async function ensureFonts() {
    const specs = [
      '600 88px "Big Shoulders Display"',
      '500 42px "Big Shoulders Display"',
      '400 30px "IBM Plex Sans"',
      '400 24px "IBM Plex Sans"',
    ];
    await Promise.all(specs.map(function (spec) {
      return document.fonts.load(spec).catch(function () { return null; });
    }));
  }

  function selectedPreset() {
    const id = document.querySelector('input[name="creator_bg"]:checked');
    return BACKGROUNDS[(id && id.value) || 'lake_night'] || BACKGROUNDS.lake_night;
  }

  function textAlign() {
    const el = document.querySelector('input[name="creator_align"]:checked');
    return (el && el.value) === 'center' ? 'center' : 'left';
  }

  function isLightPreset(preset) {
    return !!preset.light;
  }

  function resetTextShadow() {
    ctx.shadowColor = 'transparent';
    ctx.shadowBlur = 0;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 0;
  }

  function applyTextShadow() {
    ctx.shadowColor = 'rgba(0,0,0,0.42)';
    ctx.shadowBlur = 14;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 2;
  }

  function drawAccentRule(preset, align, x, y, maxW) {
    if (!preset.subtitle) return y;
    const ruleW = align === 'center' ? Math.min(maxW * 0.38, 440) : 128;
    const ruleX = align === 'center' ? x - ruleW / 2 : x;
    ctx.save();
    ctx.fillStyle = preset.subtitle;
    ctx.globalAlpha = 0.72;
    ctx.fillRect(ruleX, y + 4, ruleW, 4);
    ctx.restore();
    return y + 18;
  }

  function slugifyTitle(raw) {
    const first = String(raw).split('\n')[0];
    return first.trim().toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 48);
  }

  function creatorHasContent() {
    return ['creator_title', 'creator_subtitle', 'creator_body', 'creator_footer'].some(function (id) {
      return document.getElementById(id).value.trim() !== '';
    });
  }

  function setBackground(id) {
    const radio = document.querySelector('input[name="creator_bg"][value="' + id + '"]');
    if (radio) radio.checked = true;
  }

  function setAlign(val) {
    const radio = document.querySelector('input[name="creator_align"][value="' + val + '"]');
    if (radio) radio.checked = true;
  }

  let filenameTouched = false;
  let activeTemplate = '';
  const nameEl = document.getElementById('creator_name');
  if (nameEl) {
    nameEl.addEventListener('input', function () { filenameTouched = true; });
  }

  function applyTemplate(id) {
    const tpl = TEMPLATES[id];
    if (!tpl) return;
    if (creatorHasContent() && !window.confirm('Replace the current slide text with this template?')) {
      return;
    }
    document.getElementById('creator_title').value = tpl.title || '';
    document.getElementById('creator_subtitle').value = tpl.subtitle || '';
    document.getElementById('creator_body').value = tpl.body || '';
    document.getElementById('creator_footer').value = tpl.footer || '';
    if (tpl.bg) setBackground(tpl.bg);
    if (tpl.align) setAlign(tpl.align);
    filenameTouched = false;
    if (nameEl) {
      nameEl.value = tpl.filename || slugifyTitle(tpl.title || '');
    }
    activeTemplate = id;
    document.querySelectorAll('.template-chip').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-template') === id);
    });
    renderPreview();
  }

  document.querySelectorAll('.template-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      applyTemplate(btn.getAttribute('data-template'));
    });
  });

  document.querySelectorAll('input[name="creator_bg"]').forEach(function (el) {
    el.addEventListener('change', function () {
      activeTemplate = '';
      document.querySelectorAll('.template-chip').forEach(function (btn) {
        btn.classList.remove('active');
      });
      renderPreview();
    });
  });

  document.querySelectorAll('input[name="creator_align"]').forEach(function (el) {
    el.addEventListener('change', renderPreview);
  });

  preloadBackgrounds().then(renderPreview);

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
    const light = isLightPreset(preset);

    ctx.clearRect(0, 0, W, H);
    drawBackground(ctx, preset);
    ctx.textAlign = align;
    ctx.textBaseline = 'top';

    if (!title && !subtitle && !body && !footer) {
      ctx.font = '400 28px "IBM Plex Sans", sans-serif';
      ctx.fillStyle = preset.body;
      ctx.globalAlpha = 0.42;
      ctx.textAlign = 'center';
      ctx.fillText('Your slide preview will appear here', W / 2, H / 2 - 16);
      ctx.globalAlpha = 1;
      return;
    }

    let y = padTop;

    const footerLineH = 32;
    const footerGap = 24;
    let footerLines = [];
    let footerBlockH = 0;
    if (footer) {
      ctx.font = '400 24px "IBM Plex Sans", sans-serif';
      footerLines = wrapLines(ctx, footer, maxW);
      footerBlockH = footerLines.length * footerLineH + footerGap;
    }
    const bodyMaxY = H - padBottom - footerBlockH;

    function drawBlock(text, font, color, lineH, maxLines, shadow, maxY) {
      if (!text) return;
      ctx.font = font;
      ctx.fillStyle = color;
      if (shadow && !light) applyTextShadow();
      else resetTextShadow();
      let lines = wrapLines(ctx, text, maxW);
      if (maxLines && lines.length > maxLines) {
        lines = lines.slice(0, maxLines);
        lines[maxLines - 1] = lines[maxLines - 1].replace(/\s+\S*$/, '') + '\u2026';
      }
      let truncated = false;
      lines.forEach(function (ln) {
        if (maxY && y + lineH > maxY) {
          truncated = true;
          return;
        }
        if (ln === '') y += Math.round(lineH * 0.55);
        else {
          ctx.fillText(ln, x, y);
          y += lineH;
        }
      });
      if (truncated && y > padTop + lineH) {
        resetTextShadow();
        ctx.font = font;
        ctx.fillStyle = color;
        const ellY = Math.min(y - Math.round(lineH * 0.15), maxY ? maxY - 4 : y);
        ctx.fillText('\u2026', x, ellY);
      }
      resetTextShadow();
    }

    drawBlock(title, '600 88px "Big Shoulders Display", sans-serif', preset.title, 94, 3, true, null);
    if (title) y = drawAccentRule(preset, align, x, y - 8, maxW);
    if (title && subtitle) y += 6;
    drawBlock(subtitle, '500 42px "Big Shoulders Display", sans-serif', preset.subtitle, 50, 2, true, bodyMaxY);
    if ((title || subtitle) && body) y += 22;
    drawBlock(body, '400 30px "IBM Plex Sans", sans-serif', preset.body, 44, null, false, bodyMaxY);

    if (footer && footerLines.length) {
      resetTextShadow();
      ctx.font = '400 24px "IBM Plex Sans", sans-serif';
      ctx.fillStyle = preset.footer || preset.body;
      ctx.textAlign = align;
      if (!light) {
        ctx.globalAlpha = 0.92;
        applyTextShadow();
      }
      let fy = H - padBottom - (footerLines.length - 1) * footerLineH;
      footerLines.forEach(function (ln) {
        ctx.fillText(ln, x, fy);
        fy += footerLineH;
      });
      resetTextShadow();
      ctx.globalAlpha = 1;
    }
  }

  ['creator_title', 'creator_subtitle', 'creator_body', 'creator_footer'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () {
      if (id === 'creator_title' && nameEl && !filenameTouched) {
        nameEl.value = slugifyTitle(this.value);
      }
      clearTimeout(window._slidePreviewT);
      window._slidePreviewT = setTimeout(renderPreview, 180);
    });
  });

  function canvasToBlob(targetCanvas) {
    return new Promise(function (resolve, reject) {
      targetCanvas.toBlob(function (blob) {
        if (blob) {
          resolve(blob);
          return;
        }
        try {
          const dataUrl = targetCanvas.toDataURL('image/png');
          const parts = dataUrl.split(',');
          if (parts.length < 2) {
            reject(new Error('Could not export PNG from preview.'));
            return;
          }
          const bin = atob(parts[1]);
          const arr = new Uint8Array(bin.length);
          for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
          resolve(new Blob([arr], { type: 'image/png' }));
        } catch (err) {
          reject(err);
        }
      }, 'image/png');
    });
  }

  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      const reader = new FileReader();
      reader.onload = function () {
        const result = String(reader.result || '');
        const comma = result.indexOf(',');
        resolve(comma >= 0 ? result.slice(comma + 1) : result);
      };
      reader.onerror = function () { reject(new Error('Could not encode PNG.')); };
      reader.readAsDataURL(blob);
    });
  }

  function submitCreatorForm(fields) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '?board=slides';
    form.style.display = 'none';
    Object.keys(fields).forEach(function (key) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = fields[key];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  document.getElementById('creatorSave').addEventListener('click', function () {
    const btn = this;
    const title = document.getElementById('creator_title').value.trim();
    const body = document.getElementById('creator_body').value.trim();
    if (!title && !body) {
      alert('Add a title or body before creating a slide.');
      return;
    }
    btn.disabled = true;
    if (canvas.width !== W || canvas.height !== H) {
      canvas.width = W;
      canvas.height = H;
    }
    renderPreview().then(function () {
      return canvasToBlob(canvas);
    }).then(function (blob) {
      return blobToBase64(blob);
    }).then(function (b64) {
      submitCreatorForm({
        action: 'create_slide',
        board: 'slides',
        csrf: <?= json_encode(csrf_token()) ?>,
        creator_title: title,
        creator_subtitle: document.getElementById('creator_subtitle').value.trim(),
        creator_name: document.getElementById('creator_name').value.trim(),
        creator_png: b64
      });
    }).catch(function (err) {
      alert(err && err.message ? err.message : 'Could not create slide — try refreshing the page.');
      btn.disabled = false;
    });
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
