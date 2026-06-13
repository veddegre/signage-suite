<?php
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
        $f = __DIR__ . '/config/settings.json';
        $conf = [];
        if (is_file($f)) {
            $j = json_decode((string)file_get_contents($f), true);
            if (is_array($j)) $conf = $j;
        }
        $GLOBALS['__cfg_cache'] = $conf;
    }
    $conf = $GLOBALS['__cfg_cache'];
    if (!array_key_exists($key, $conf)) return $default;
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

function cfg_path(): string
{
    return __DIR__ . '/config/settings.json';
}

function cfg_write(array $conf): bool
{
    $dir = __DIR__ . '/config';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    ksort($conf);
    $tmp = $dir . '/settings.json.tmp';
    $json = json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, cfg_path());
}

/** Height reserved at the bottom for the weather alert ticker overlay. */
const SIGNAGE_TICKER_H = 72;

/** Bottom inset (px) when a board is framed in board.php / player.php (?safebottom=). */
function signage_safe_bottom(): int
{
    return max(0, min(120, (int)($_GET['safebottom'] ?? 0)));
}

/** Usable board viewport height — 1080 minus any framed safe-bottom inset. */
function signage_frame_height(): int
{
    return 1080 - signage_safe_bottom();
}

/** html/body height rule for framed signage boards. */
function signage_viewport_css(): string
{
    $h = signage_frame_height();
    return "html,body{height:calc({$h}px - var(--signage-ticker-inset,0px));}";
}

/** Design canvas + scale for boards that render 1080px then shrink to fit the frame. */
function signage_board_framing(int $designH = 1080): array
{
    $frameH = signage_frame_height();
    return [
        'designH' => $designH,
        'frameH' => $frameH,
        'embedded' => isset($_GET['noticker']),
        'scale' => round($frameH / $designH, 5),
    ];
}

/** Admin / preview URL — matches what board.php loads in rotation iframes. */
function signage_board_preview_url(string $file): string
{
    $sep = str_contains($file, '?') ? '&' : '?';
    return $file . $sep . 'noticker=1&safebottom=' . SIGNAGE_TICKER_H;
}

/** In-flow attribution line — place inside .board, not as an absolute body overlay. */
function signage_stamp_css(): string
{
    return '.stamp{display:block;text-align:right;font-size:15px;color:var(--mist);opacity:.7;'
         . 'padding:2px 4px 0;flex-shrink:0;white-space:nowrap;overflow:hidden;'
         . 'text-overflow:ellipsis;max-width:100%;}'
         . '.board>.stamp{grid-column:1/-1;align-self:end;}';
}
