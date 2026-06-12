<?php
/**
 * Custom slides — shared helpers for slides.php and admin upload/scheduling.
 */

require_once __DIR__ . '/config.php';

function slides_dir(): string
{
    $d = cfg('slides.SLIDE_DIR', 'slides');
    if ($d === '' || $d === 'slides') {
        return __DIR__ . '/slides';
    }
    if ($d[0] !== '/') {
        return __DIR__ . '/' . trim($d, '/');
    }
    return $d;
}

function slide_safe_filename(string $name): ?string
{
    $name = basename($name);
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(jpe?g|png|webp)$/i', $name)) {
        return null;
    }
    return $name;
}

/** List image filenames present in the slide directory. */
function slides_list_files(?string $dir = null): array
{
    $dir = $dir ?? slides_dir();
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir) as $f) {
        if (slide_safe_filename($f)) {
            $out[] = $f;
        }
    }
    sort($out);
    return $out;
}

/** Append a new slide entry to config deck (used by upload + creator). */
function slide_append_to_deck(string $filename, array $extra = []): bool
{
    $conf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    $deck = $conf['slides.SLIDES'] ?? [];
    if (!is_array($deck)) {
        $deck = [];
    }
    $deck[] = array_merge(['file' => $filename, 'schedule' => 'always'], $extra);
    $conf['slides.SLIDES'] = $deck;
    if (!cfg_write($conf)) {
        return false;
    }
    cfg_reload();
    return true;
}

/** Unique filename inside the slide directory. */
function slide_unique_filename(string $base, string $ext, ?string $dir = null): string
{
    $dir = $dir ?? slides_dir();
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base);
    $base = trim($base, '-._');
    if ($base === '') {
        $base = 'slide';
    }
    $name = $base . '.' . $ext;
    if (!is_file($dir . '/' . $name)) {
        return $name;
    }
    return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
}

/**
 * Predefined slide backgrounds for the admin slide creator (rendered client-side).
 * Each preset: label, text colors, optional accent, and bg spec for canvas.
 */
function slide_background_presets(): array
{
    return [
        'lake_night' => [
            'label' => 'Lake Night',
            'title' => '#edf2fb',
            'subtitle' => '#ffb347',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 145, 'stops' => [
                [0, '#0c1422'], [0.55, '#141f33'], [1, '#0a1020'],
            ]],
            'thumb' => 'lake_night.png',
        ],
        'beacon_bar' => [
            'label' => 'Beacon Bar',
            'title' => '#edf2fb',
            'subtitle' => '#ffb347',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 90, 'stops' => [
                [0, '#0c1422'], [1, '#141f33'],
            ]],
            'accent' => ['type' => 'bar', 'color' => '#ffb347', 'width' => 14],
            'thumb' => 'beacon_bar.png',
        ],
        'harbor_glow' => [
            'label' => 'Harbor Glow',
            'title' => '#edf2fb',
            'subtitle' => '#ffb347',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'radial', 'cx' => 0.25, 'cy' => 0.15, 'r' => 1.05, 'stops' => [
                [0, '#1e3a5f'], [0.45, '#141f33'], [1, '#0c1422'],
            ]],
            'thumb' => 'harbor_glow.png',
        ],
        'celebration' => [
            'label' => 'Celebration',
            'title' => '#edf2fb',
            'subtitle' => '#ffb347',
            'body' => '#c8d4e8',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 125, 'stops' => [
                [0, '#3d1f4a'], [0.45, '#1a2540'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'glow', 'color' => '#ffb347', 'opacity' => 0.18],
            'thumb' => 'celebration.png',
        ],
        'frost' => [
            'label' => 'Frost (light)',
            'title' => '#0c1422',
            'subtitle' => '#b45309',
            'body' => '#26344d',
            'footer' => '#526580',
            'bg' => ['type' => 'linear', 'angle' => 180, 'stops' => [
                [0, '#edf2fb'], [1, '#c8d4e8'],
            ]],
            'thumb' => 'frost.png',
        ],
        'forest' => [
            'label' => 'Forest',
            'title' => '#edf2fb',
            'subtitle' => '#7dd3a8',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 160, 'stops' => [
                [0, '#0f1f18'], [0.5, '#0c1422'], [1, '#141f33'],
            ]],
            'thumb' => 'forest.png',
        ],
    ];
}

function slide_backgrounds_dir(): string
{
    return __DIR__ . '/slide_backgrounds';
}

/** Web path to a preset thumbnail / full background PNG. */
function slide_background_url(string $presetId): ?string
{
    $presets = slide_background_presets();
    if (!isset($presets[$presetId]['thumb'])) {
        return null;
    }
    $file = slide_backgrounds_dir() . '/' . $presets[$presetId]['thumb'];
    return is_file($file) ? 'slide_backgrounds/' . $presets[$presetId]['thumb'] : null;
}

function slide_hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

function slide_color_lerp(array $a, array $b, float $t): array
{
    return [
        (int)round($a[0] + ($b[0] - $a[0]) * $t),
        (int)round($a[1] + ($b[1] - $a[1]) * $t),
        (int)round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

function slide_color_at_stops(array $stops, float $t): array
{
    $t = max(0.0, min(1.0, $t));
    $prev = null;
    foreach ($stops as $stop) {
        [$pos, $hex] = $stop;
        $pos = (float)$pos;
        $rgb = slide_hex_rgb((string)$hex);
        if ($prev === null) {
            if ($t <= $pos) {
                return $rgb;
            }
            $prev = [$pos, $rgb];
            continue;
        }
        if ($t <= $pos) {
            $span = $pos - $prev[0];
            $local = $span > 0 ? ($t - $prev[0]) / $span : 0;
            return slide_color_lerp($prev[1], $rgb, $local);
        }
        $prev = [$pos, $rgb];
    }
    return $prev[1] ?? [0, 0, 0];
}

/** Render preset background to a GD image resource (1920×1080). */
function slide_background_gd_image(array $preset, int $w = 1920, int $h = 1080)
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $im = imagecreatetruecolor($w, $h);
    if (!$im) {
        return null;
    }
    $bg = $preset['bg'] ?? [];
    $type = $bg['type'] ?? 'linear';
    $stops = $bg['stops'] ?? [[0, '#000000'], [1, '#000000']];

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($type === 'radial') {
                $cx = ($bg['cx'] ?? 0.5) * $w;
                $cy = ($bg['cy'] ?? 0.5) * $h;
                $maxR = ($bg['r'] ?? 1.0) * max($w, $h);
                $dist = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2);
                $t = $maxR > 0 ? min(1.0, $dist / $maxR) : 0;
            } else {
                $angle = deg2rad($bg['angle'] ?? 0);
                $nx = cos($angle);
                $ny = sin($angle);
                $cx = $w / 2;
                $cy = $h / 2;
                $dx = $x - $cx;
                $dy = $y - $cy;
                $proj = ($dx * $nx + $dy * $ny) / max($w, $h);
                $t = max(0.0, min(1.0, $proj + 0.5));
            }
            [$r, $g, $b] = slide_color_at_stops($stops, $t);
            imagesetpixel($im, $x, $y, imagecolorallocate($im, $r, $g, $b));
        }
    }

    $accent = $preset['accent'] ?? null;
    if (is_array($accent) && ($accent['type'] ?? '') === 'bar') {
        [$r, $g, $b] = slide_hex_rgb((string)($accent['color'] ?? '#ffb347'));
        $bar = imagecolorallocate($im, $r, $g, $b);
        imagefilledrectangle($im, 0, 0, (int)($accent['width'] ?? 12), $h, $bar);
    } elseif (is_array($accent) && ($accent['type'] ?? '') === 'glow') {
        [$r, $g, $b] = slide_hex_rgb((string)($accent['color'] ?? '#ffb347'));
        $opacity = (float)($accent['opacity'] ?? 0.18);
        $gx = (int)round($w * 0.85);
        $gy = (int)round($h * 0.1);
        $radius = (int)round($w * 0.45);
        for ($y = max(0, $gy - $radius); $y < min($h, $gy + $radius); $y++) {
            for ($x = max(0, $gx - $radius); $x < min($w, $gx + $radius); $x++) {
                $dist = sqrt(($x - $gx) ** 2 + ($y - $gy) ** 2);
                if ($dist > $radius) {
                    continue;
                }
                $fade = (1 - $dist / $radius) * $opacity;
                if ($fade <= 0) {
                    continue;
                }
                $base = imagecolorat($im, $x, $y);
                $br = ($base >> 16) & 0xFF;
                $bgc = ($base >> 8) & 0xFF;
                $bb = $base & 0xFF;
                $nr = (int)min(255, $br + ($r - $br) * $fade);
                $ng = (int)min(255, $bgc + ($g - $bgc) * $fade);
                $nb = (int)min(255, $bb + ($b - $bb) * $fade);
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $nr, $ng, $nb));
            }
        }
    }

    return $im;
}

/** Write missing 1920×1080 PNGs into slide_backgrounds/. */
function slide_background_ensure_assets(): void
{
    if (!function_exists('imagepng')) {
        return;
    }
    $dir = slide_backgrounds_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return;
    }
    foreach (slide_background_presets() as $id => $preset) {
        $name = $preset['thumb'] ?? ($id . '.png');
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            continue;
        }
        $im = slide_background_gd_image($preset);
        if (!$im) {
            continue;
        }
        imagepng($im, $path, 6);
    }
}

function slides_timezone(): string
{
    return cfg('slides.TIMEZONE', 'America/Detroit');
}

/**
 * Whether a slide entry should show right now.
 * schedule: always | range (date_start–date_end) | yearly (month_day MM-DD) | weekly (weekday)
 */
function slide_schedule_active(array $slide, ?DateTimeInterface $now = null): bool
{
    if (!empty($slide['off'])) {
        return false;
    }

    $tz  = new DateTimeZone(slides_timezone());
    $now = $now ?? new DateTime('now', $tz);

    $sched = strtolower((string)($slide['schedule'] ?? 'always'));
    if ($sched === '' || $sched === 'always') {
        return true;
    }

    if ($sched === 'range') {
        $start = (string)($slide['date_start'] ?? '');
        $end   = (string)($slide['date_end'] ?? '');
        if ($start === '' || $end === '') {
            return false;
        }
        $today = $now->format('Y-m-d');
        return $today >= $start && $today <= $end;
    }

    if ($sched === 'yearly') {
        $md = (string)($slide['month_day'] ?? '');
        if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $md, $m)) {
            return false;
        }
        $want = sprintf('%02d-%02d', (int)$m[1], (int)$m[2]);
        return $now->format('m-d') === $want;
    }

    if ($sched === 'weekly') {
        $wd = (string)($slide['weekday'] ?? '');
        return $wd !== '' && strcasecmp($now->format('l'), $wd) === 0;
    }

    return true;
}

function slide_dwell(array $slide, int $default = 12): int
{
    $d = (int)($slide['dwell'] ?? 0);
    return $d > 0 ? $d : $default;
}

/** Active slides that exist on disk, in configured order. */
function slides_active_entries(?array $entries = null, ?string $dir = null): array
{
    $dir = $dir ?? slides_dir();
    $entries = $entries ?? cfg('slides.SLIDES', []);
    if (!is_array($entries)) {
        return [];
    }

    $tz  = new DateTimeZone(slides_timezone());
    $now = new DateTime('now', $tz);
    $out = [];

    foreach ($entries as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file === null || !is_file($dir . '/' . $file)) {
            continue;
        }
        if (!slide_schedule_active($slide, $now)) {
            continue;
        }
        $slide['file'] = $file;
        $out[] = $slide;
    }

    return $out;
}
