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

const ADMIN_FILE = __DIR__ . '/config/admin.json';

session_start();

/** Drop deny-all .htaccess into config/ and cache/ so tokens and cached API
 *  responses can't be fetched directly (Apache; nginx needs a location block
 *  — see README). */
function protect_dirs(): void
{
    foreach (['/config', '/cache'] as $d) {
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
$board = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['board'] ?? ''));
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
            default:    // text, password, select, textarea
                $raw = trim((string)($_POST[$name] ?? ''));
                if ($raw === '') unset($conf[$cfgKey]); else $conf[$cfgKey] = $raw;
        }
    }
    $conf = array_filter($conf, fn($v) => $v !== null);
    if ($errors) {
        $flash = implode(' ', $errors); $flashOk = false;
    } elseif (protect_dirs() === null && cfg_write($conf)) {
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

if ($authed && ($_POST['action'] ?? '') === 'clearcache' && csrf_ok()) {
    $n = 0;
    foreach (glob(__DIR__ . '/cache/*.{dat,json}', GLOB_BRACE) ?: [] as $cf) { @unlink($cf); $n++; }
    $flash = "Cleared $n cached file" . ($n === 1 ? '' : 's') . '.';
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
      <form method="post" id="boardform">
        <input type="hidden" name="action" value="save">
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
                     name="<?= h($f['key']) ?>" value="<?= h($val !== null ? (string)$val : '') ?>"
                     placeholder="(default)" autocomplete="off">
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
        'check' => ($c['type'] ?? '') === 'check'], $ff['columns']);
    echo json_encode($colMap);
  ?>;
  (cols[field] || []).forEach(c => {
    const td = document.createElement('td');
    if (c.wide) td.className = 'wide';
    const inp = document.createElement('input');
    if (c.check) {
      inp.type = 'checkbox';
      inp.style.cssText = 'width:20px;height:20px;accent-color:var(--beacon);min-width:0';
      td.style.cssText = 'text-align:center;vertical-align:middle';
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
<?php endif; ?>
</body>
</html>
