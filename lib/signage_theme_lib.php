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

/** Curated wall themes (admin picker + new displays). */
function signage_curated_theme_keys(): array
{
    require_once __DIR__ . '/slides_lib.php';

    return slide_curated_theme_keys();
}

/** @return array<string,array<string,string>>|null */
function signage_theme_preset(string $key): ?array
{
    $key = signage_normalize_theme_key($key);
    if ($key === '') {
        return null;
    }
    $all = signage_theme_presets_all();

    return $all[$key] ?? null;
}

/**
 * All signage token sets (includes retired themes for saved slides/displays).
 *
 * @return array<string,array<string,string>>
 */
function signage_theme_presets_all(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    require_once __DIR__ . '/slides_lib.php';
    $status = signage_theme_status_tokens();
    $out = [];
    foreach (slide_theme_background_presets_all() as $key => $preset) {
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

/**
 * Curated schemes for admin pickers (see signage_theme_presets_all for legacy keys).
 *
 * @return array<string,array<string,string>>
 */
function signage_theme_presets(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $all = signage_theme_presets_all();
    $out = [];
    foreach (signage_curated_theme_keys() as $key) {
        if (isset($all[$key])) {
            $out[$key] = $all[$key];
        }
    }
    if (!isset($out['lake_night'])) {
        $out['lake_night'] = $all['lake_night'];
    }
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
    $tickerBar = trim((string)($preset['signage_ticker_bar'] ?? ''));

    return array_merge($status, [
        'label' => trim((string)($preset['label'] ?? $key)),
        'lake-night' => $lakeNight,
        'harbor' => $harbor,
        'hairline' => $hairline,
        'panel-dim' => $panelDim,
        'snow' => $snow,
        'mist' => $mist,
        'beacon' => $beacon,
        'ticker-bar' => $tickerBar !== '' ? $tickerBar : $harbor,
        'light' => $light ? '1' : '0',
    ]);
}

/** Sun arc guide lines — contrast with page/harbor on every palette. */
function signage_theme_sun_track_color(array $preset): string
{
    $light = ($preset['light'] ?? '0') === '1';
    $hairline = signage_theme_hex_rgb((string)($preset['hairline'] ?? '#26344d'));
    if ($light) {
        $body = signage_theme_hex_rgb((string)($preset['mist'] ?? '#526580'));
        if ($body === null || $hairline === null) {
            return '#526580';
        }

        return signage_theme_mix_rgb($body, $hairline, 0.35);
    }

    $snow = signage_theme_hex_rgb((string)($preset['snow'] ?? '#edf2fb'));
    if ($snow === null || $hairline === null) {
        return '#8aa0c0';
    }

    return signage_theme_mix_rgb($snow, $hairline, 0.4);
}

/** Weather sun arc — avoid flex/grid clipping and keep stroke inside padded viewBox. */
function signage_theme_sun_widget_css(): string
{
    return <<<'CSS'
  .sun{overflow:visible;flex-shrink:0;}
  .sun #sunDot{stroke:var(--sun-dot-ring);stroke-width:2;paint-order:stroke fill;}
CSS;
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

/** @return array<string,array{label:string,description:string,sans:string,display:string,serif:string,mono:string,google_url:string,display_scale:float,display_lh:float,bignum_scale:float}> */
function signage_font_packs_all(): array
{
    return [
        'signage' => [
            'label' => 'Signage Classic',
            'description' => 'IBM Plex Sans body with Big Shoulders display headings',
            'sans' => "'IBM Plex Sans', system-ui, sans-serif",
            'display' => "'Big Shoulders Display', system-ui, sans-serif",
            'serif' => "'IBM Plex Serif', Georgia, serif",
            'mono' => "'IBM Plex Mono', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Big+Shoulders+Display:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Serif:ital,wght@0,400;0,500;1,400&display=swap',
            'display_scale' => 1.0,
            'display_lh' => 1.06,
            'bignum_scale' => 1.0,
        ],
        'gvsu' => [
            'label' => 'GVSU Identity',
            'description' => 'Open Sans and EB Garamond per GVSU typography guidelines',
            'sans' => "'Open Sans', Arial, sans-serif",
            'display' => "'Open Sans', Arial, sans-serif",
            'serif' => "'EB Garamond', 'Times New Roman', serif",
            'mono' => "ui-monospace, 'Cascadia Code', 'Segoe UI Mono', monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Open+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap',
            'display_scale' => 0.88,
            'display_lh' => 1.14,
            'bignum_scale' => 0.84,
        ],
        'roboto' => [
            'label' => 'Roboto',
            'description' => 'Roboto UI sans with Roboto Slab headlines',
            'sans' => "'Roboto', Arial, sans-serif",
            'display' => "'Roboto Slab', Georgia, serif",
            'serif' => "'Roboto Slab', Georgia, serif",
            'mono' => "'Roboto Mono', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500&family=Roboto+Slab:wght@500;600;700&family=Roboto:ital,wght@0,400;0,500;0,700;1,400&display=swap',
            'display_scale' => 0.9,
            'display_lh' => 1.12,
            'bignum_scale' => 0.86,
        ],
        'courier' => [
            'label' => 'Courier',
            'description' => 'Courier Prime typewriter mono for headlines and body',
            'sans' => "'Courier Prime', 'Courier New', Courier, monospace",
            'display' => "'Courier Prime', 'Courier New', Courier, monospace",
            'serif' => "'Courier Prime', 'Courier New', Courier, monospace",
            'mono' => "'Courier Prime', 'Courier New', Courier, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Courier+Prime:ital,wght@0,400;0,700;1,400&display=swap',
            'display_scale' => 0.86,
            'display_lh' => 1.16,
            'bignum_scale' => 0.82,
        ],
        'lato' => [
            'label' => 'Lato',
            'description' => 'Lato sans with Lora serif accents',
            'sans' => "'Lato', Arial, sans-serif",
            'display' => "'Lato', Arial, sans-serif",
            'serif' => "'Lora', Georgia, serif",
            'mono' => "'Roboto Mono', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;1,400&family=Lora:ital,wght@0,400;0,600;1,400&family=Roboto+Mono:wght@400;500&display=swap',
            'display_scale' => 0.91,
            'display_lh' => 1.12,
            'bignum_scale' => 0.87,
        ],
        'inter' => [
            'label' => 'Inter',
            'description' => 'Inter UI sans with Merriweather serif accents',
            'sans' => "'Inter', system-ui, sans-serif",
            'display' => "'Inter', system-ui, sans-serif",
            'serif' => "'Merriweather', Georgia, serif",
            'mono' => "'JetBrains Mono', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;1,400&family=JetBrains+Mono:wght@400;500&family=Merriweather:ital,wght@0,400;0,700;1,400&display=swap',
            'display_scale' => 0.9,
            'display_lh' => 1.12,
            'bignum_scale' => 0.86,
        ],
        'source' => [
            'label' => 'Source',
            'description' => 'Adobe Source Sans 3 and Source Serif 4',
            'sans' => "'Source Sans 3', system-ui, sans-serif",
            'display' => "'Source Sans 3', system-ui, sans-serif",
            'serif' => "'Source Serif 4', Georgia, serif",
            'mono' => "'Source Code Pro', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=Source+Code+Pro:wght@400;500&family=Source+Sans+3:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Source+Serif+4:ital,wght@0,400;0,600;1,400&display=swap',
            'display_scale' => 0.91,
            'display_lh' => 1.12,
            'bignum_scale' => 0.87,
        ],
        'dm' => [
            'label' => 'DM Sans',
            'description' => 'DM Sans body with DM Serif Display headlines',
            'sans' => "'DM Sans', system-ui, sans-serif",
            'display' => "'DM Serif Display', Georgia, serif",
            'serif' => "'DM Serif Display', Georgia, serif",
            'mono' => "'DM Mono', ui-monospace, monospace",
            'google_url' => 'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=DM+Serif+Display:ital@0;1&display=swap',
            'display_scale' => 0.84,
            'display_lh' => 1.15,
            'bignum_scale' => 0.8,
        ],
    ];
}

/** Curated font packs for admin pickers (extend signage_font_packs_all to add more later). */
function signage_font_packs(): array
{
    $all = signage_font_packs_all();
    $out = [];
    foreach (['signage', 'gvsu', 'roboto', 'courier', 'lato', 'inter', 'source', 'dm'] as $key) {
        if (isset($all[$key])) {
            $out[$key] = $all[$key];
        }
    }

    return $out;
}

/** @return array{display_scale:float,display_lh:float,bignum_scale:float} */
function signage_font_pack_metrics(?string $fontPackKey = null): array
{
    if ($fontPackKey === null) {
        $fontPackKey = signage_active_font_pack_key();
    }
    $pack = signage_font_pack($fontPackKey) ?? signage_font_pack('signage') ?? [];
    $displayScale = (float)($pack['display_scale'] ?? 1.0);
    $bignumScale = (float)($pack['bignum_scale'] ?? $displayScale);

    return [
        'display_scale' => max(0.65, min(1.0, $displayScale)),
        'display_lh' => max(1.0, min(1.25, (float)($pack['display_lh'] ?? 1.1))),
        'bignum_scale' => max(0.65, min(1.0, $bignumScale)),
    ];
}

function signage_font_display_scale(?string $fontPackKey = null): float
{
    return signage_font_pack_metrics($fontPackKey)['display_scale'];
}

function signage_font_bignum_scale(?string $fontPackKey = null): float
{
    return signage_font_pack_metrics($fontPackKey)['bignum_scale'];
}

function signage_normalize_font_pack_key(string $raw): string
{
    return strtolower(preg_replace('/[^a-z0-9_]/', '', $raw) ?? '');
}

/** @return array{label:string,description:string,sans:string,display:string,serif:string,mono:string,google_url:string}|null */
function signage_font_pack(string $key): ?array
{
    $key = signage_normalize_font_pack_key($key);
    if ($key === '') {
        return null;
    }
    $all = signage_font_packs_all();

    return $all[$key] ?? null;
}

/** Saved font pack for a display (explicit font_pack, else legacy theme default). */
function signage_font_pack_for_screen(string $screen): string
{
    require_once __DIR__ . '/screen_scope_lib.php';
    $scr = rotation_screen_raw_entry($screen);
    if (is_array($scr)) {
        $raw = signage_normalize_font_pack_key(trim((string)($scr['font_pack'] ?? '')));
        if ($raw !== '' && signage_font_pack($raw) !== null) {
            return $raw;
        }
    }
    if (signage_theme_for_screen($screen) === 'gvsu_lakers') {
        return 'gvsu';
    }

    return 'signage';
}

/** Active font pack for this HTTP request (?font= wins, else display from ?screen=). */
function signage_active_font_pack_key(): string
{
    $q = signage_normalize_font_pack_key((string)($_GET['font'] ?? ''));
    if ($q !== '' && signage_font_pack($q) !== null) {
        return $q;
    }
    require_once __DIR__ . '/screen_scope_lib.php';

    return signage_font_pack_for_screen(signage_request_screen());
}

/**
 * Font stacks for a font pack key.
 *
 * @return array{sans:string,display:string,serif:string,mono:string}
 */
function signage_font_stacks(string $fontPackKey): array
{
    $pack = signage_font_pack($fontPackKey) ?? signage_font_pack('signage');
    if ($pack === null) {
        return [
            'sans' => 'system-ui, sans-serif',
            'display' => 'system-ui, sans-serif',
            'serif' => 'Georgia, serif',
            'mono' => 'ui-monospace, monospace',
        ];
    }

    return [
        'sans' => $pack['sans'],
        'display' => $pack['display'],
        'serif' => $pack['serif'],
        'mono' => $pack['mono'],
    ];
}

/**
 * @deprecated Use signage_font_stacks(signage_active_font_pack_key()) instead.
 * @return array{sans:string,display:string,serif:string,mono:string}
 */
function signage_theme_font_stacks(string $key): array
{
    if (signage_font_pack($key) !== null) {
        return signage_font_stacks($key);
    }

    return signage_font_stacks(signage_theme_for_screen($key === '' ? 'main' : $key));
}

/** Google Fonts stylesheet URL for a font pack (empty when not needed). */
function signage_font_pack_google_url(string $fontPackKey): string
{
    $pack = signage_font_pack($fontPackKey);

    return $pack['google_url'] ?? '';
}

/** @deprecated Use signage_font_pack_google_url() */
function signage_theme_fonts_google_url(string $key): string
{
    if (signage_font_pack($key) !== null) {
        return signage_font_pack_google_url($key);
    }

    return signage_font_pack_google_url(signage_font_pack_for_screen('main'));
}

/** Preconnect + stylesheet tags for board &lt;head&gt; (pass null for active font pack). */
function signage_theme_fonts_head_markup(?string $fontPackKey = null): string
{
    if ($fontPackKey === null) {
        $fontPackKey = signage_active_font_pack_key();
    } elseif (signage_font_pack($fontPackKey) === null) {
        $fontPackKey = signage_active_font_pack_key();
    }
    $url = signage_font_pack_google_url($fontPackKey);
    if ($url === '') {
        return '';
    }

    return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
        . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
        . '<link href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">' . "\n";
}

/**
 * Selectors that use display / headline fonts (shared by font-family + fit rules).
 *
 * @return list<string>
 */
function signage_font_display_selectors(): array
{
    return [
        '.board .head h1', '.board h1', '.board h2', '.board #clock', '.board .brand',
        '.board .bignum', '.board .word', '.board .hero-title', '.board .hero-id',
        '.board .hero-pct', '.board .tid', '.board .prio', '.board .pill',
        '.board .panel h2', '.board .panel .k', '.board .countdown .num',
        '.board .current .num', '.board .current .band', '.board .aqi-panel .num',
        '.board .aqi-panel .band', '.board .stat .val', '.board .fday .max',
        '.board .fday .aqi-num', '.board .verdict .t', '.board .row .val',
        '.board .row .side', '.board .row .when', '.board .row .cnt',
        '.board .hero .title', '.board .num', '.board .tev .t', '.board .day .n',
        '.board .chip .v', '.board .setup h2', '.board .empty h2', '.board .topbar h1',
        '.board .board-title', '.board .weather-temp', '.board .stat-row .stat-v',
        '.board .stat-solo', '.board .weather-meta .val',
    ];
}

/** @return list<string> */
function signage_font_bignum_selectors(): array
{
    return [
        '.board .bignum', '.board .weather-temp', '.board .aqi-panel .num',
        '.board .current .num', '.board .countdown .num', '.board .totals .big',
    ];
}

/** Loosen tight line-height:1 headlines so alternate font metrics do not clip. */
function signage_theme_font_fit_css(?string $fontPackKey = null): string
{
    $metrics = signage_font_pack_metrics($fontPackKey);
    $lh = $metrics['display_lh'];
    $pad = $metrics['display_scale'] < 0.98 ? '0.05em' : '0.03em';
    $display = signage_font_display_selectors();
    $bignum = signage_font_bignum_selectors();
    $displaySel = 'html body ' . implode(',html body ', $display);
    $bignumSel = 'html body ' . implode(',html body ', $bignum);

    return $displaySel . '{line-height:' . $lh . '!important;padding-block:' . $pad . '!important;'
        . 'margin-block:calc(-1 * ' . $pad . ')!important;overflow:visible!important;'
        . 'max-width:100%;overflow-wrap:anywhere;}'
        . $bignumSel . '{line-height:1.08!important;padding-bottom:0.04em!important;'
        . 'margin-bottom:-0.04em!important;overflow:visible!important;}';
}

/**
 * Map hardcoded board font-family rules to theme tokens so font packs apply without editing every board.
 */
function signage_theme_font_css(?string $fontPackKey = null): string
{
    if ($fontPackKey === null) {
        $fontPackKey = signage_active_font_pack_key();
    }
    $fonts = signage_font_stacks($fontPackKey);
    $display = signage_font_display_selectors();
    $serif = [
        '.board .hero-desc', '.board .def-text', '.board .phonetic', '.board .etym',
        '.board .quote', '.board .thought',
    ];
    $mono = [
        '.board code', '.board .mono', '.board td.mono', '.board .hero-host',
        '.board .row .code', '.board .row .id', '.board .row .port',
        '.board .prow .c', '.board .bar-wrap .hr', '.board .svcrow .ms',
        '.board .hero .tag',
    ];

    $rules = [
        'html,body,.board{font-family:' . $fonts['sans'] . '!important}',
        implode(',', $display) . '{font-family:' . $fonts['display'] . '!important}',
        implode(',', $serif) . '{font-family:' . $fonts['serif'] . '!important}',
        implode(',', $mono) . '{font-family:' . $fonts['mono'] . '!important}',
        '#signage-ticker{font-family:' . $fonts['sans'] . '!important}',
    ];

    return implode("\n", $rules) . "\n" . signage_theme_font_fit_css($fontPackKey);
}

/** CSS custom properties for native boards (echo inside &lt;style&gt;). */
function signage_theme_css_block(string $key, ?string $fontPackKey = null): string
{
    $preset = signage_theme_preset($key) ?? signage_theme_preset('lake_night');
    if ($preset === null) {
        return '';
    }

    $light = ($preset['light'] ?? '0') === '1';
    $dataAccent = (string)$preset['beacon'];
    if ($key === 'gvsu_lakers') {
        $dataAccent = '#DEC197';
    }
    $mapAccent = $key === 'gvsu_lakers' ? '#DEC197' : '#ffb347';
    if ($fontPackKey === null) {
        $fontPackKey = signage_active_font_pack_key();
    }
    $fonts = signage_font_stacks($fontPackKey);
    $fontMetrics = signage_font_pack_metrics($fontPackKey);
    $pairs = [
        '--signage-light' => $light ? '1' : '0',
        '--font-display-scale' => (string)$fontMetrics['display_scale'],
        '--font-display-lh' => (string)$fontMetrics['display_lh'],
        '--font-bignum-scale' => (string)$fontMetrics['bignum_scale'],
        '--font-sans' => $fonts['sans'],
        '--font-display' => $fonts['display'],
        '--font-serif' => $fonts['serif'],
        '--font-mono' => $fonts['mono'],
        '--lake-night' => $preset['lake-night'],
        '--night' => $preset['lake-night'],
        '--harbor' => $preset['harbor'],
        '--hairline' => $preset['hairline'],
        '--line' => $preset['hairline'],
        '--snow' => $preset['snow'],
        '--mist' => $preset['mist'],
        '--beacon' => $preset['beacon'],
        '--data-accent' => $dataAccent,
        '--ok' => $preset['ok'],
        '--bad' => $preset['bad'],
        '--warn' => $preset['warn'],
        '--up' => $preset['up'],
        '--down' => $preset['down'],
        '--gold' => $preset['gold'],
        '--panel-dim' => $preset['panel-dim'] ?? $preset['harbor'],
        '--inset-surface' => $preset['panel-dim'] ?? $preset['harbor'],
        '--tile-bg' => 'color-mix(in srgb, var(--panel-dim) 78%, var(--lake-night))',
        '--tile-border' => 'color-mix(in srgb, var(--hairline) 92%, transparent)',
        '--code-bg' => 'color-mix(in srgb, var(--panel-dim) 65%, var(--lake-night))',
        '--inset-label' => 'color-mix(in srgb, var(--snow) 62%, var(--mist))',
        '--inset-muted' => 'color-mix(in srgb, var(--snow) 78%, var(--mist))',
        '--map-text' => $preset['snow'],
        '--map-muted' => $preset['mist'],
        '--map-accent' => $mapAccent,
        '--map-panel' => 'color-mix(in srgb, var(--harbor) 94%, var(--lake-night))',
        '--map-border' => 'color-mix(in srgb, var(--hairline) 88%, transparent)',
        '--map-bg' => 'color-mix(in srgb, var(--lake-night) 90%, #000)',
        '--crit' => $preset['bad'],
        '--alert' => $preset['bad'],
        '--high' => $preset['warn'],
        '--med' => $preset['gold'],
        '--low' => $preset['ok'],
        '--sun-track' => signage_theme_sun_track_color($preset),
        '--sun-trail' => $preset['beacon'],
        '--sun-dot-ring' => $preset['snow'],
    ];
    if ($light) {
        $mistRgb = signage_theme_hex_rgb((string)($preset['mist'] ?? '#526580'));
        $harborRgb = signage_theme_hex_rgb((string)($preset['harbor'] ?? '#c8d4e8'));
        if ($mistRgb !== null && $harborRgb !== null) {
            $pairs['--logo-plate'] = signage_theme_mix_rgb($mistRgb, $harborRgb, 0.38);
        }
    }
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
  .weather-stat,.board .stat,.board .fday,.board .row,.board .hero.us,.board .root,.board .issue,.board .wan-speed,.board .side-block{
    background:var(--tile-bg); border-color:var(--tile-border);}
  .weather-stat{background:var(--tile-bg);
    border:1px solid var(--tile-border);}
  .board .inset-surface{background:color-mix(in srgb,var(--inset-surface,var(--panel-dim)) 88%, var(--harbor));
    border:1px solid color-mix(in srgb,var(--hairline) 90%, transparent);}
  .board code,.board .setup code,.board .setupmsg code,.board .notcfg code,.board .pollen-note code{
    background:var(--code-bg); color:var(--snow);}
  .board .host{background:var(--tile-bg); border-color:var(--tile-border);}
  .board .track,.board .bar .track,.board .lrow .track,.board .prow .track,.board .meter .track,.board .cloudbar .track{
    background:var(--tile-bg); border-color:var(--tile-border);}
CSS;
}

/** Map / treemap boards — alias legacy per-board vars to active theme tokens. */
function signage_theme_map_board_css(): string
{
    return <<<'CSS'
  .map-area,.main{
    --dshield-text:var(--map-text); --dshield-muted:var(--map-muted); --dshield-accent:var(--map-accent);
    --dshield-panel:var(--map-panel); --dshield-border:var(--map-border);
    --src-text:var(--map-text); --src-muted:var(--map-muted); --src-accent:var(--map-accent);
    --src-panel:var(--map-panel); --src-border:var(--map-border);
    --ports-text:var(--map-text); --ports-muted:var(--map-muted); --ports-accent:var(--map-accent);
    --ports-panel:var(--map-panel); --ports-border:var(--map-border);
    --l3-text:var(--map-text); --l3-muted:var(--map-muted); --l3-accent:var(--map-accent);
    --l3-panel:var(--map-panel); --l3-border:var(--map-border);
    --ioda-text:var(--map-text); --ioda-muted:var(--map-muted); --ioda-accent:var(--map-accent);
    --ioda-warn:var(--warn); --ioda-panel:var(--map-panel); --ioda-panel-border:var(--map-border);}
  .map-area,.ports-main,.main{background:var(--map-bg);}
  #heatMap.leaflet-container,#attackMap.leaflet-container,#attackMap .leaflet-container{
    background:var(--map-bg)!important;}
  .leaflet-control-attribution{background:color-mix(in srgb,var(--map-panel) 96%, transparent)!important;
    color:var(--map-muted)!important;}
  .leaflet-control-attribution a{color:var(--map-muted)!important;}
CSS;
}

/** CSS for clipping the top of a cross-origin iframe embed (Splunk publish, etc.). */
function signage_iframe_crop_css(int $boardH, int $cropTop, string $wrapClass = 'dash-wrap', bool $hideScrollbars = false): string
{
    $boardH = max(720, $boardH);
    $cropTop = max(0, min(400, $cropTop));
    $frameH = $boardH + $cropTop;
    $wrap = preg_replace('/[^a-z0-9_-]/', '', $wrapClass) ?: 'dash-wrap';
    $scrollbarGutter = $hideScrollbars ? 24 : 0;
    $iframeW = 1920 + $scrollbarGutter;
    $iframeH = $frameH + $scrollbarGutter;

    return <<<CSS
  .{$wrap} {
    position: relative;
    width: 1920px;
    height: {$boardH}px;
    overflow: hidden;
    background: var(--lake-night);
  }
  .{$wrap} iframe {
    position: absolute;
    left: 0;
    top: -{$cropTop}px;
    width: {$iframeW}px;
    height: {$iframeH}px;
    border: 0;
    display: block;
    pointer-events: none;
    background: var(--lake-night);
    overflow: hidden;
  }
CSS;
}

/** Themed border wrapper for full-page iframe embeds (Grafana, Splunk publish, Power BI, web). */
function signage_embed_frame_css(): string
{
    return <<<'CSS'
  .signage-embed-frame{
    width:100%; height:100%; box-sizing:border-box; overflow:hidden;
    border:3px solid var(--beacon); border-radius:14px;
    background:var(--harbor);
    box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--hairline) 65%, transparent),
               0 8px 28px color-mix(in srgb,var(--lake-night) 55%, transparent);}
  .signage-embed-frame iframe{
    width:100%; height:100%; border:0; display:block; pointer-events:none;
    background:var(--lake-night);}
CSS;
}

/** Flex/grid shells — reduce bottom clipping in rotation iframes. */
function signage_theme_board_shell_css(): string
{
    return <<<'CSS'
  .board{min-height:0;}
  .board .head,.board .topbar,.board .hero{min-height:0;overflow:visible;}
  .board .list,.board .list-rows,.board .days,.board .recent,.board .side{
    min-height:0;}
  .board .list.scrollable,.board .list.clip{flex:1;min-height:0;overflow:hidden;}
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
        '--tk-bar-bg' => $preset['ticker-bar'] ?? $preset['harbor'],
        '--tk-bar-border' => $preset['beacon'],
        '--tk-tag-bg' => $preset['beacon'],
        '--tk-tag-fg' => $preset['lake-night'],
        '--tk-text' => $preset['snow'],
        '--tk-emphasis' => $preset['gold'],
        '--tk-sep' => $preset['beacon'],
        '--tk-news-bar' => $preset['ticker-bar'] ?? $preset['lake-night'],
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
    font-family:var(--font-sans,'IBM Plex Sans',sans-serif);
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
    if (!isset($presets[$savedTheme]) && ($legacy = signage_theme_preset($savedTheme)) !== null) {
        $legacy['label'] = ($legacy['label'] ?? $savedTheme) . ' (saved)';
        $presets = [$savedTheme => $legacy] + $presets;
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
      <span class="rotation-theme-label"><?= $esc(slide_curated_theme_label($tid, $tp)) ?></span>
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

/** Admin HTML: font pack picker with live type samples. */
function admin_rotation_font_picker(string $screenKey, string $savedFontPack): void
{
    $esc = static function (?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    };
    $packs = signage_font_packs();
    $savedFontPack = signage_normalize_font_pack_key($savedFontPack);
    if ($savedFontPack === '' || signage_font_pack($savedFontPack) === null) {
        $savedFontPack = 'signage';
    }
    if (!isset($packs[$savedFontPack]) && ($legacy = signage_font_pack($savedFontPack)) !== null) {
        $packs = [$savedFontPack => $legacy] + $packs;
    }
    $name = 'SCREEN_OPTS[' . $screenKey . '][font_pack]';
    $previewUrls = [];
    foreach ($packs as $pid => $pack) {
        $url = (string)($pack['google_url'] ?? '');
        if ($url !== '') {
            $previewUrls[$url] = true;
        }
    }
    foreach (array_keys($previewUrls) as $previewUrl): ?>
<link rel="stylesheet" href="<?= $esc($previewUrl) ?>">
    <?php endforeach; ?>
<div class="rotation-font-pick" role="radiogroup" aria-label="Wall typography">
  <?php foreach ($packs as $pid => $pack):
      $checked = $pid === $savedFontPack;
      $display = (string)($pack['display'] ?? $pack['sans']);
      $sans = (string)($pack['sans'] ?? 'system-ui,sans-serif');
      $serif = (string)($pack['serif'] ?? $sans);
  ?>
  <label title="<?= $esc((string)($pack['description'] ?? $pack['label'])) ?>">
    <input type="radio" name="<?= $esc($name) ?>" value="<?= $esc($pid) ?>" <?= $checked ? 'checked' : '' ?>>
    <div class="rotation-font-swatch">
      <span class="rotation-font-headline" style="font-family:<?= $esc($display) ?>">Weather 72°</span>
      <span class="rotation-font-body" style="font-family:<?= $esc($sans) ?>">Alerts, headlines, and board body copy.</span>
      <span class="rotation-font-serif" style="font-family:<?= $esc($serif) ?>">Serif accent · quotes &amp; definitions</span>
      <span class="rotation-font-label"><?= $esc((string)$pack['label']) ?></span>
    </div>
  </label>
  <?php endforeach; ?>
</div>
    <?php
}

/** Strip rotation/kiosk query params before re-appending a fresh set. */
function signage_board_url_strip_rotation_query(string $url): string
{
    $url = trim($url);
    if ($url === '' || !signage_board_url_is_local($url)) {
        return $url;
    }
    $qPos = strpos($url, '?');
    if ($qPos === false) {
        return $url;
    }
    $base = substr($url, 0, $qPos);
    $tail = substr($url, $qPos + 1);
    $frag = '';
    $hashPos = strpos($tail, '#');
    if ($hashPos !== false) {
        $frag = substr($tail, $hashPos);
        $tail = substr($tail, 0, $hashPos);
    }
    parse_str($tail, $params);
    if (!is_array($params)) {
        $params = [];
    }
    foreach (['noticker', 'theme', 'font', 'screen', 'safebottom', 'frameh', 'clock', 'settle', 'r'] as $key) {
        unset($params[$key]);
    }
    $query = http_build_query($params);

    return $base . ($query !== '' ? '?' . $query : '') . $frag;
}

/** Append display theme + kiosk params to a local board URL (admin preview, rotation links). */
function signage_board_url_with_rotation_query(
    string $url,
    ?string $screen = null,
    ?string $themeKey = null,
    ?bool $includeTickerSafeBottom = null,
    ?string $fontPackKey = null
): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!signage_board_url_is_local($url)) {
        return $url;
    }
    require_once __DIR__ . '/screen_scope_lib.php';
    if ($screen === null) {
        $screen = signage_preview_screen_key();
    }
    if ($themeKey === null) {
        $themeKey = signage_theme_for_screen($screen);
    }
    if ($fontPackKey === null) {
        $fontPackKey = signage_font_pack_for_screen($screen);
    }
    if ($includeTickerSafeBottom === null) {
        $includeTickerSafeBottom = false;
    }
    $url = signage_board_url_strip_rotation_query($url);
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . signage_board_rotation_query($screen, $themeKey, $includeTickerSafeBottom, $fontPackKey);
}

/** True for relative .php board URLs (not external http(s) embeds). */
function signage_board_url_is_local(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $url)) {
        return false;
    }

    return (bool)preg_match('~\.php(?:[?#]|$)~i', $url);
}

/** Merge theme/font (and other kiosk params) onto a board URL for rotation iframes. */
function signage_board_rotation_query(
    string $screen,
    string $themeKey,
    bool $includeTickerSafeBottom = false,
    ?string $fontPackKey = null
): string {
    require_once __DIR__ . '/rotation_lib.php';
    $qs = 'noticker=1';
    // Legacy: ?safebottom= pins board height; rotation shell resizes iframes via --signage-ticker-inset instead.
    if ($includeTickerSafeBottom) {
        $qs .= '&safebottom=' . (int)SIGNAGE_TICKER_H;
    }
    $screen = rotation_normalize_screen_key($screen);
    if ($screen !== '') {
        $qs .= '&screen=' . rawurlencode($screen);
    }
    $themeKey = signage_normalize_theme_key($themeKey);
    if ($themeKey !== '' && signage_theme_preset($themeKey) !== null) {
        $qs .= '&theme=' . rawurlencode($themeKey);
    }
    if ($fontPackKey === null) {
        $fontPackKey = signage_font_pack_for_screen($screen);
    }
    $fontPackKey = signage_normalize_font_pack_key($fontPackKey);
    if ($fontPackKey !== '' && signage_font_pack($fontPackKey) !== null) {
        $qs .= '&font=' . rawurlencode($fontPackKey);
    }

    return $qs;
}
