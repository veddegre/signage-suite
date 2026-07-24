<?php
/**
 * Per-display color schemes for native signage boards and the rotation shell.
 * Preset keys match Custom Slides theme backgrounds (slide_theme_background_presets).
 */

require_once __DIR__ . '/../config.php';

/** @return array<string,string> */
function signage_theme_status_tokens(): array
{
    return [
        'ok' => '#59db8f',
        'bad' => '#e45959',
        'warn' => '#ffc859',
        'up' => '#39c46d',
        'down' => '#ff5d5d',
        'gold' => '#ffd089',
    ];
}

function signage_normalize_theme_key(string $raw): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_]/', '', $raw) ?? '');

    return $key;
}

/** @return array{0:int,1:int,2:int}|null */
function signage_theme_hex_rgb(string $hex): ?array
{
    $hex = trim($hex);
    if ($hex === '') {
        return null;
    }
    if ($hex[0] === '#') {
        $hex = substr($hex, 1);
    }
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return null;
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function signage_theme_rgb_hex(int $r, int $g, int $b): string
{
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/** @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b */
function signage_theme_mix_rgb(array $a, array $b, float $weightB): string
{
    $weightB = max(0.0, min(1.0, $weightB));
    $weightA = 1.0 - $weightB;

    return signage_theme_rgb_hex(
        (int)round($a[0] * $weightA + $b[0] * $weightB),
        (int)round($a[1] * $weightA + $b[1] * $weightB),
        (int)round($a[2] * $weightA + $b[2] * $weightB)
    );
}

/** Card/panel fill — tinted from page background + accent, not legacy slide gradient mid-stop. */
function signage_theme_derive_harbor(string $lakeNight, string $beacon, bool $light): string
{
    $base = signage_theme_hex_rgb($lakeNight);
    $accent = signage_theme_hex_rgb($beacon);
    $white = signage_theme_hex_rgb('#ffffff');
    if ($base === null) {
        return '#141f33';
    }
    if ($light) {
        return $white !== null ? signage_theme_mix_rgb($base, $white, 0.12) : $lakeNight;
    }
    $lifted = $white !== null ? signage_theme_mix_rgb($base, $white, 0.07) : $lakeNight;
    $liftedRgb = signage_theme_hex_rgb($lifted) ?? $base;
    if ($accent !== null) {
        return signage_theme_mix_rgb($liftedRgb, $accent, 0.06);
    }

    return $lifted;
}

function signage_theme_derive_hairline(string $lakeNight, string $harbor, bool $light): string
{
    if ($light) {
        return '#c8d4e8';
    }
    if (strtolower($harbor) === '#141f33') {
        return '#26344d';
    }
    $harborRgb = signage_theme_hex_rgb($harbor);
    $white = signage_theme_hex_rgb('#ffffff');
    if ($harborRgb !== null && $white !== null) {
        return signage_theme_mix_rgb($harborRgb, $white, 0.14);
    }
    $lake = signage_theme_hex_rgb($lakeNight);

    return $lake !== null ? signage_theme_mix_rgb($lake, $white ?? [255, 255, 255], 0.18) : '#26344d';
}

/** Inset tiles on cards (weather stats, map rows, etc.). */
function signage_theme_derive_panel_dim(string $lakeNight, string $harbor): string
{
    $a = signage_theme_hex_rgb($lakeNight);
    $b = signage_theme_hex_rgb($harbor);
    if ($a === null || $b === null) {
        return $lakeNight;
    }

    return signage_theme_mix_rgb($a, $b, 0.42);
}

/** @return array<string,array<string,string>>|null */
function signage_theme_preset(string $key): ?array
{
    $key = signage_normalize_theme_key($key);
    if ($key === '') {
        return null;
    }
    $all = signage_theme_presets();

    return $all[$key] ?? null;
}

/**
 * Named schemes for admin + runtime (keys align with slide creator themes).
 *
 * @return array<string,array<string,string>>
 */
function signage_theme_presets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    require_once __DIR__ . '/slides_lib.php';
    $status = signage_theme_status_tokens();
    $out = [];
    foreach (slide_theme_background_presets() as $key => $preset) {
        if (!is_array($preset)) {
            continue;
        }
        $out[$key] = signage_theme_tokens_from_slide_preset($key, $preset, $status);
    }
    if (!isset($out['lake_night'])) {
        $out['lake_night'] = signage_theme_tokens_from_slide_preset('lake_night', [
            'label' => 'Lake Night',
            'title' => '#edf2fb',
            'subtitle' => '#ffb347',
            'body' => '#8aa0c0',
            'bg' => ['stops' => [[0, '#0c1422'], [0.55, '#141f33'], [1, '#0a1020']]],
        ], $status);
    }
    ksort($out);
    $cache = $out;

    return $cache;
}

/** @param array<string,mixed> $preset @param array<string,string> $status */
function signage_theme_tokens_from_slide_preset(string $key, array $preset, array $status): array
{
    $stops = [];
    $bg = $preset['bg'] ?? null;
    if (is_array($bg) && is_array($bg['stops'] ?? null)) {
        $stops = $bg['stops'];
    }
    $lakeNight = '#0c1422';
    $harbor = '#141f33';
    if ($stops !== []) {
        $lakeNight = (string)($stops[0][1] ?? $lakeNight);
        $lastStop = (string)($stops[count($stops) - 1][1] ?? '');
        if ($lastStop !== '' && strtolower($lastStop) !== strtolower($lakeNight)) {
            $lakeNight = signage_theme_mix_rgb(
                signage_theme_hex_rgb($lakeNight) ?? [12, 20, 34],
                signage_theme_hex_rgb($lastStop) ?? [12, 20, 34],
                0.35
            );
        }
    }
    $pageOverride = trim((string)($preset['signage_page'] ?? ''));
    if ($pageOverride !== '') {
        $lakeNight = $pageOverride;
    }
    $beacon = trim((string)($preset['signage_beacon'] ?? $preset['highlight'] ?? $preset['subtitle'] ?? '#ffb347'));
    $harborTint = trim((string)($preset['harbor_tint'] ?? $beacon));
    $light = !empty($preset['light']);
    $harborOverride = trim((string)($preset['signage_harbor'] ?? ''));
    if ($harborOverride !== '') {
        $harbor = $harborOverride;
        $hairline = signage_theme_derive_hairline($lakeNight, $harbor, $light);
    } elseif ($key === 'lake_night') {
        $harbor = '#141f33';
        $hairline = $light ? '#c8d4e8' : '#26344d';
    } else {
        $harbor = signage_theme_derive_harbor($lakeNight, $harborTint, $light);
        $hairline = signage_theme_derive_hairline($lakeNight, $harbor, $light);
    }
    $panelDim = signage_theme_derive_panel_dim($lakeNight, $harbor);
    $snow = trim((string)($preset['title'] ?? '#edf2fb'));
    $mist = trim((string)($preset['body'] ?? '#8aa0c0'));

    return array_merge($status, [
        'label' => trim((string)($preset['label'] ?? $key)),
        'lake-night' => $lakeNight,
        'harbor' => $harbor,
        'hairline' => $hairline,
        'panel-dim' => $panelDim,
        'snow' => $snow,
        'mist' => $mist,
        'beacon' => $beacon,
        'light' => $light ? '1' : '0',
    ]);
}

/** Saved theme for a rotation display (defaults to lake_night). */
function signage_theme_for_screen(string $screen): string
{
    require_once __DIR__ . '/screen_scope_lib.php';
    $scr = rotation_screen_raw_entry($screen);
    $raw = is_array($scr) ? trim((string)($scr['theme'] ?? '')) : '';
    $key = signage_normalize_theme_key($raw);
    if ($key !== '' && signage_theme_preset($key) !== null) {
        return $key;
    }

    return 'lake_night';
}

/** Active theme for this HTTP request (?theme= wins, else display from ?screen=). */
function signage_active_theme_key(): string
{
    $q = signage_normalize_theme_key((string)($_GET['theme'] ?? ''));
    if ($q !== '' && signage_theme_preset($q) !== null) {
        return $q;
    }
    require_once __DIR__ . '/screen_scope_lib.php';

    return signage_theme_for_screen(signage_request_screen());
}

/** CSS custom properties for native boards (echo inside &lt;style&gt;). */
function signage_theme_css_block(string $key): string
{
    $preset = signage_theme_preset($key) ?? signage_theme_preset('lake_night');
    if ($preset === null) {
        return '';
    }

    $pairs = [
        '--lake-night' => $preset['lake-night'],
        '--night' => $preset['lake-night'],
        '--harbor' => $preset['harbor'],
        '--hairline' => $preset['hairline'],
        '--line' => $preset['hairline'],
        '--snow' => $preset['snow'],
        '--mist' => $preset['mist'],
        '--beacon' => $preset['beacon'],
        '--ok' => $preset['ok'],
        '--bad' => $preset['bad'],
        '--warn' => $preset['warn'],
        '--up' => $preset['up'],
        '--down' => $preset['down'],
        '--gold' => $preset['gold'],
        '--panel-dim' => $preset['panel-dim'] ?? $preset['harbor'],
    ];
    $parts = [];
    foreach ($pairs as $name => $value) {
        $parts[] = $name . ':' . $value;
    }
    foreach (signage_theme_ticker_root_tokens($preset) as $name => $value) {
        $parts[] = $name . ':' . $value;
    }

    return ':root{' . implode(';', $parts) . ';}';
}

/** Optional rules for nested tiles — safe to append after signage_theme_css_block on any board. */
function signage_theme_inset_surface_css(): string
{
    return <<<'CSS'
  .weather-stat{background:color-mix(in srgb,var(--panel-dim) 78%, var(--lake-night));
    border:1px solid color-mix(in srgb,var(--hairline) 92%, transparent);}
CSS;
}

/** Standard NWS ticker colors (watch/advisory/warning) — not tied to wall palette. */
function signage_ticker_nws_tokens(): array
{
    return [
        'yellow-bar' => '#33260e',
        'yellow-border' => '#ffb347',
        'yellow-tag' => '#ffb347',
        'yellow-tag-fg' => '#0c1422',
        'yellow-em' => '#ffd089',
        'warning-bar' => '#3a1016',
        'warning-border' => '#ff5d5d',
        'warning-tag' => '#ff5d5d',
        'warning-tag-fg' => '#0c1422',
        'warning-em' => '#ff9d9d',
    ];
}

/** Extra :root tokens for the weather/RSS ticker (included in signage_theme_css_block). */
function signage_theme_ticker_root_tokens(array $preset): array
{
    $nws = signage_ticker_nws_tokens();

    return [
        '--tk-bar-bg' => $preset['harbor'],
        '--tk-bar-border' => $preset['beacon'],
        '--tk-tag-bg' => $preset['beacon'],
        '--tk-tag-fg' => $preset['lake-night'],
        '--tk-text' => $preset['snow'],
        '--tk-emphasis' => $preset['gold'],
        '--tk-sep' => $preset['beacon'],
        '--tk-news-bar' => $preset['lake-night'],
        '--tk-news-border' => $preset['beacon'],
        '--tk-news-tag' => $preset['beacon'],
        '--tk-news-tag-fg' => $preset['lake-night'],
        '--tk-nws-yellow-bar' => $nws['yellow-bar'],
        '--tk-nws-yellow-border' => $nws['yellow-border'],
        '--tk-nws-yellow-tag' => $nws['yellow-tag'],
        '--tk-nws-yellow-tag-fg' => $nws['yellow-tag-fg'],
        '--tk-nws-yellow-em' => $nws['yellow-em'],
        '--tk-nws-warning-bar' => $nws['warning-bar'],
        '--tk-nws-warning-border' => $nws['warning-border'],
        '--tk-nws-warning-tag' => $nws['warning-tag'],
        '--tk-nws-warning-tag-fg' => $nws['warning-tag-fg'],
        '--tk-nws-warning-em' => $nws['warning-em'],
    ];
}

/** CSS rules for ticker.php (expects theme :root tokens from signage_theme_css_block). */
function signage_ticker_css_rules(): string
{
    return <<<'CSS'
  #signage-ticker-root { position:fixed; left:0; right:0; bottom:0; z-index:9999; pointer-events:none; }
  #signage-ticker { display:flex; align-items:stretch; height:72px;
    font-family:'IBM Plex Sans',sans-serif;
    background:var(--tk-bar-bg); border-top:2px solid var(--tk-bar-border);
    box-shadow:0 -8px 30px rgba(0,0,0,.45); }
  #signage-ticker .tk-tag { flex:0 0 auto; display:flex; align-items:center; gap:14px;
    padding:0 28px; font-weight:700; font-size:26px; letter-spacing:2px;
    color:var(--tk-tag-fg); background:var(--tk-tag-bg); text-transform:uppercase; white-space:nowrap; }
  #signage-ticker .tk-dot { width:14px; height:14px; border-radius:50%; background:var(--tk-tag-fg);
    animation:tk-blink 1.2s steps(2,start) infinite; }
  @keyframes tk-blink { to { visibility:hidden; } }
  #signage-ticker .tk-scroll { flex:1; overflow:hidden; display:flex; align-items:center; }
  #signage-ticker .tk-track { display:flex; white-space:nowrap; will-change:transform; }
  #signage-ticker .tk-item { font-size:27px; color:var(--tk-text); padding-right:90px; }
  #signage-ticker .tk-item b { color:var(--tk-emphasis); font-weight:600; letter-spacing:1px; text-transform:uppercase; }
  #signage-ticker .tk-item .tk-sep { color:var(--tk-sep); padding:0 18px; }
  #signage-ticker.tk-watch,
  #signage-ticker.tk-advisory,
  #signage-ticker.tk-statement {
    background:var(--tk-nws-yellow-bar); border-top-color:var(--tk-nws-yellow-border); }
  #signage-ticker.tk-watch .tk-tag,
  #signage-ticker.tk-advisory .tk-tag,
  #signage-ticker.tk-statement .tk-tag {
    color:var(--tk-nws-yellow-tag-fg); background:var(--tk-nws-yellow-tag); }
  #signage-ticker.tk-watch .tk-dot,
  #signage-ticker.tk-advisory .tk-dot,
  #signage-ticker.tk-statement .tk-dot { background:var(--tk-nws-yellow-tag-fg); }
  #signage-ticker.tk-watch .tk-item b,
  #signage-ticker.tk-advisory .tk-item b,
  #signage-ticker.tk-statement .tk-item b { color:var(--tk-nws-yellow-em); }
  #signage-ticker.tk-watch .tk-item .tk-sep,
  #signage-ticker.tk-advisory .tk-item .tk-sep,
  #signage-ticker.tk-statement .tk-item .tk-sep { color:var(--tk-nws-yellow-border); }
  #signage-ticker.tk-severe {
    background:var(--tk-nws-warning-bar); border-top-color:var(--tk-nws-warning-border); }
  #signage-ticker.tk-severe .tk-tag {
    color:var(--tk-nws-warning-tag-fg); background:var(--tk-nws-warning-tag); }
  #signage-ticker.tk-severe .tk-dot { background:var(--tk-nws-warning-tag-fg); }
  #signage-ticker.tk-severe .tk-item b { color:var(--tk-nws-warning-em); }
  #signage-ticker.tk-severe .tk-item .tk-sep { color:var(--tk-nws-warning-border); }
  #signage-ticker.tk-news {
    background:var(--tk-news-bar); border-top-color:var(--tk-news-border); }
  #signage-ticker.tk-news .tk-tag { color:var(--tk-news-tag-fg); background:var(--tk-news-tag); }
  #signage-ticker.tk-static .tk-item { padding-right:0; width:100%;
    overflow:hidden; text-overflow:ellipsis; padding-left:26px; }
  @media (prefers-reduced-motion: reduce) {
    #signage-ticker .tk-track { animation:none !important; transform:none !important; } }
CSS;
}

/** Inline style for admin swatch fallback when theme PNG is missing. */
function signage_theme_swatch_background_style(string $key): string
{
    $preset = signage_theme_preset($key);
    if ($preset === null) {
        return 'background:#0c1422';
    }

    return 'background:linear-gradient(145deg,' . $preset['lake-night'] . ' 0%,'
        . $preset['harbor'] . ' 55%,' . $preset['lake-night'] . ' 100%)';
}

/** Admin HTML: visual theme picker with wall + ticker preview samples. */
function admin_rotation_theme_picker(string $screenKey, string $savedTheme): void
{
    require_once __DIR__ . '/slides_lib.php';
    $esc = static function (?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };
    $presets = signage_theme_presets();
    $savedTheme = signage_normalize_theme_key($savedTheme);
    if ($savedTheme === '' || signage_theme_preset($savedTheme) === null) {
        $savedTheme = 'lake_night';
    }
    $nws = signage_ticker_nws_tokens();
    $name = 'SCREEN_OPTS[' . $screenKey . '][theme]';
    ?>
<div class="rotation-theme-pick" role="radiogroup" aria-label="Wall color scheme">
  <?php foreach ($presets as $tid => $tp):
      $bgUrl = slide_background_url($tid);
      $checked = $tid === $savedTheme;
  ?>
  <label title="<?= $esc($tp['label']) ?>">
    <input type="radio" name="<?= $esc($name) ?>" value="<?= $esc($tid) ?>" <?= $checked ? 'checked' : '' ?>>
    <div class="rotation-theme-swatch">
      <?php if ($bgUrl): ?>
      <img src="<?= $esc($bgUrl) ?>" alt="" loading="lazy">
      <?php else: ?>
      <span class="rotation-theme-fallback" style="<?= $esc(signage_theme_swatch_background_style($tid)) ?>"></span>
      <?php endif; ?>
      <span class="rotation-theme-label"><?= $esc($tp['label']) ?></span>
      <div class="rotation-theme-ticker-samples" aria-hidden="true">
        <span class="tt-bar" style="background:<?= $esc($tp['harbor']) ?>;border-color:<?= $esc($tp['beacon']) ?>" title="RSS / themed bar"></span>
        <span class="tt-bar tt-yellow" style="background:<?= $esc($nws['yellow-bar']) ?>;border-color:<?= $esc($nws['yellow-border']) ?>" title="Watch / advisory"></span>
        <span class="tt-bar tt-red" style="background:<?= $esc($nws['warning-bar']) ?>;border-color:<?= $esc($nws['warning-border']) ?>" title="Warning"></span>
      </div>
    </div>
  </label>
  <?php endforeach; ?>
</div>
    <?php
}

/** Merge theme (and other kiosk params) onto a board URL for rotation iframes. */
function signage_board_rotation_query(string $screen, string $themeKey, bool $includeTickerSafeBottom = true): string
{
    require_once __DIR__ . '/rotation_lib.php';
    $qs = 'noticker=1';
    if ($includeTickerSafeBottom && signage_ticker_enabled()) {
        $qs .= '&safebottom=' . (int)SIGNAGE_TICKER_H;
    }
    $screen = rotation_normalize_screen_key($screen);
    if ($screen !== '' && $screen !== 'main') {
        $qs .= '&screen=' . rawurlencode($screen);
    }
    $themeKey = signage_normalize_theme_key($themeKey);
    if ($themeKey !== '' && $themeKey !== 'lake_night') {
        $qs .= '&theme=' . rawurlencode($themeKey);
    }

    return $qs;
}
