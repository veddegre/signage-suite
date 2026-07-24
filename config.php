<?php
if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', __DIR__);
}
require_once __DIR__ . '/lib/json_store_lib.php';

/**
 * CONFIG LOADER — shared by every board and by admin.php
 * Values live in config/settings.json, edited through admin.php (or by hand).
 * Boards call cfg('board.SETTING', $default): if the key is present in the
 * JSON it wins; otherwise the in-code default applies. Deleting the JSON file
 * simply returns every board to its defaults.
 */

function cfg(string $key, $default = null)
{
    if (!isset($GLOBALS['__cfg_cache']) || $GLOBALS['__cfg_cache'] === null) {
        $f = SIGNAGE_ROOT . '/config/settings.json';
        $conf = [];
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j)) {
                $conf = $j;
            }
        }
        require_once __DIR__ . '/lib/calendar_lib.php';
        $migrated = calendar_migrate_from_family($conf);
        if ($migrated['changed']) {
            ksort($migrated['conf']);
            cfg_write($migrated['conf']);
            $conf = $migrated['conf'];
        } else {
            $conf = $migrated['conf'];
        }
        $GLOBALS['__cfg_cache'] = $conf;
    }
    $conf = $GLOBALS['__cfg_cache'];
    if (!array_key_exists($key, $conf) && str_starts_with($key, 'calendar.')) {
        $legacyKey = 'family.' . substr($key, 9);
        if (array_key_exists($legacyKey, $conf)) {
            $key = $legacyKey;
        }
    }
    if (!array_key_exists($key, $conf)) {
        return $default;
    }
    $v = $conf[$key];
    if ($v === '' || $v === null) return $default;       // empty field = use default
    if (is_array($v) && $v === []) return $default;
    return $v;
}

/** Drop the in-process cache so cfg() rereads settings.json (used by admin after saves). */
function cfg_reload(): void
{
    $GLOBALS['__cfg_cache'] = null;
}

/** Full settings array from the in-process cache (loads settings.json once per request). */
function cfg_all(): array
{
    cfg('_', null);
    $conf = $GLOBALS['__cfg_cache'] ?? null;

    return is_array($conf) ? $conf : [];
}

function cfg_path(): string
{
    return SIGNAGE_ROOT . '/config/settings.json';
}

/**
 * Read fresh settings under the settings.json lock, merge, and write.
 * Use this for any read-modify-write on settings.json.
 *
 * @param callable(array): (array|false|null) $mutator
 */
/** Compact JSON for large decks — pretty-printing huge settings.json is very slow. */
function cfg_use_pretty_json(array $conf): bool
{
    $slides = is_array($conf['slides.SLIDES'] ?? null) ? count($conf['slides.SLIDES']) : 0;
    $photos = is_array($conf['rotator.PHOTOS'] ?? null) ? count($conf['rotator.PHOTOS']) : 0;

    return ($slides + $photos) <= 48;
}

function cfg_update(callable $mutator): bool
{
    $result = signage_json_file_update(cfg_path(), $mutator, [
        'default' => [],
        'pretty' => static fn(array $conf): bool => cfg_use_pretty_json($conf),
        'sort_keys' => true,
        'ensure_dir' => true,
        'backup' => true,
    ]);
    if ($result['ok']) {
        $GLOBALS['__cfg_cache'] = $result['data'];

        return true;
    }

    return false;
}

/** Replace settings.json entirely (serialized with other writers). */
function cfg_write(array $conf): bool
{
    ksort($conf);

    return cfg_update(static fn(): array => $conf);
}

/** Height reserved at the bottom for the weather alert ticker overlay. */
const SIGNAGE_TICKER_H = 72;

/** Whether the NWS alert ticker is enabled (admin → Alert Ticker). */
function signage_ticker_enabled(): bool
{
    return (bool)cfg('ticker.TICKER_ENABLED', true);
}

/** True when rotation iframe requests clock hidden (?clock=0 from board.php). */
function signage_clock_suppressed(): bool
{
    return isset($_GET['clock']) && (string)$_GET['clock'] === '0';
}

/** Whether to render a board clock (board setting AND not suppressed by rotation screen). */
function signage_show_clock(bool $boardEnabled = true): bool
{
    if (!$boardEnabled) {
        return false;
    }

    return !signage_clock_suppressed();
}

/** Bottom inset (px) when a board is framed in board.php / player.php (?safebottom=). */
function signage_safe_bottom(): int
{
    return max(0, min(120, (int)($_GET['safebottom'] ?? 0)));
}

/** Usable board viewport height — 1080 minus ticker/safe-bottom inset when framed. */
function signage_frame_height(): int
{
    $full = 1080;

    if (isset($_GET['noticker'])) {
        $sb = signage_safe_bottom();
        if ($sb > 0) {
            return max(720, $full - $sb);
        }
        if (signage_ticker_enabled()) {
            return max(720, $full - SIGNAGE_TICKER_H);
        }

        return $full;
    }

    if (signage_ticker_enabled()) {
        return max(720, $full - SIGNAGE_TICKER_H);
    }

    return $full;
}

/** True when loaded in the board.php rotation iframe (not admin preview). */
function signage_rotation_frame(): bool
{
    return isset($_GET['noticker']) && signage_safe_bottom() === 0;
}

/** CSS height value for html/body on signage boards. */
function signage_viewport_height(): string
{
    if (signage_rotation_frame()) {
        return '100%';
    }
    if (isset($_GET['noticker']) && signage_safe_bottom() > 0) {
        return (1080 - signage_safe_bottom()) . 'px';
    }

    return 'calc(1080px - var(--signage-ticker-inset, 0px))';
}

/** html/body height declaration for framed signage boards (property only — not a nested rule). */
function signage_viewport_css(): string
{
    return 'height:' . signage_viewport_height() . ';';
}

/** CSS :root tokens for the active display theme (?theme= or ?screen=). */
function signage_theme_css(): string
{
    require_once __DIR__ . '/lib/signage_theme_lib.php';
    $key = signage_active_theme_key();

    return signage_theme_css_block($key) . signage_theme_inset_surface_css();
}

/** Admin / preview URL — matches what board.php loads in rotation iframes. */
function signage_board_preview_url(string $file): string
{
    require_once __DIR__ . '/lib/screen_scope_lib.php';
    require_once __DIR__ . '/lib/signage_theme_lib.php';
    $sep = str_contains($file, '?') ? '&' : '?';
    $qs = signage_board_rotation_query(signage_request_screen(), signage_active_theme_key(), signage_ticker_enabled());

    return $file . $sep . $qs;
}

/** In-flow attribution line — place inside .board, not as an absolute body overlay. */
function signage_stamp_css(): string
{
    return '.stamp{display:block;text-align:right;font-size:15px;color:var(--mist);opacity:.7;'
         . 'padding:2px 4px 0;flex-shrink:0;white-space:nowrap;overflow:hidden;'
         . 'text-overflow:ellipsis;max-width:100%;}'
         . '.board>.stamp{grid-column:1/-1;align-self:end;}';
}

/** Frosted title/clock chips on photo and webcam boards (uses active theme). */
function signage_glass_panel_css(): string
{
    return 'background:color-mix(in srgb,var(--harbor) 88%, transparent);'
         . 'border:1px solid color-mix(in srgb,var(--hairline) 60%, transparent);'
         . 'backdrop-filter:blur(8px);';
}

/** Top fade on camera tile captions (cam wall grid). */
function signage_cam_cap_gradient_css(): string
{
    return 'background:linear-gradient(180deg,'
         . 'color-mix(in srgb,var(--harbor) 94%, transparent) 0%,'
         . 'color-mix(in srgb,var(--harbor) 62%, transparent) 68%,'
         . 'transparent 100%);';
}

/** Hide the mouse pointer on kiosk displays (shell + signage boards). */
function signage_kiosk_cursor_css(): string
{
    return 'html,body,html *,body *{cursor:none!important}'
         . signage_kiosk_pointer_shield_css();
}

/** Full-screen layer that captures the pointer above rotation iframes. */
function signage_kiosk_pointer_shield_css(): string
{
    return '#signage-pointer-shield{position:fixed;inset:0;z-index:8000;'
         . 'cursor:none!important;background:transparent;touch-action:none;}';
}

/** Transparent hit target — keeps the OS pointer off embedded iframes. */
function signage_kiosk_pointer_shield_html(): void
{
    echo '<div id="signage-pointer-shield" aria-hidden="true"></div>';
}

/** Emit a style block that hides the kiosk pointer. */
function signage_kiosk_cursor_style(): void
{
    echo '<style>', signage_kiosk_cursor_css(), '</style>';
}

/** Request pointer lock so Chromium/Wayland kiosks hide the hardware cursor. */
function signage_kiosk_hide_pointer_script(): void
{
    ?>
<script>
(function () {
  function lockPointer() {
    if (document.pointerLockElement) return;
    var el = document.getElementById('signage-pointer-shield') || document.documentElement;
    var req = el.requestPointerLock ? el.requestPointerLock()
      : el.webkitRequestPointerLock ? el.webkitRequestPointerLock() : null;
    if (req && req.catch) req.catch(function () {});
  }
  document.addEventListener('pointerlockchange', function () {
    if (!document.pointerLockElement) lockPointer();
  });
  document.addEventListener('pointerlockerror', lockPointer);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', lockPointer);
  } else {
    lockPointer();
  }
  window.addEventListener('focus', lockPointer);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') lockPointer();
  });
  setInterval(lockPointer, 3000);
})();
</script>
    <?php
}
