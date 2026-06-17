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

/** Max upload size enforced by admin (bytes). */
function slide_upload_max_bytes(): int
{
    return 15 * 1024 * 1024;
}

function slide_upload_max_label(): string
{
    return '15 MB';
}

function slide_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Image exceeds PHP upload_max_filesize ('
            . ini_get('upload_max_filesize') . '). Slides allow up to '
            . slide_upload_max_label() . ' — raise upload_max_filesize and post_max_size on the server.',
        UPLOAD_ERR_FORM_SIZE => 'Image exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted — try again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing — check PHP upload_tmp_dir.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the upload — check disk permissions.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        default => 'Upload failed (error ' . $code . ') — try again.',
    };
}

/** Detect slide file extension from temp file MIME, with extension fallback. */
function slide_upload_extension(string $tmpPath, string $origName): ?string
{
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/webp' => 'webp',
        'image/x-webp' => 'webp',
    ];
    if ($tmpPath !== '' && is_file($tmpPath)) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        if (isset($extMap[$mime])) {
            return $extMap[$mime];
        }
    }
    return match (strtolower(pathinfo($origName, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        default => null,
    };
}

/**
 * Save one uploaded slide image into slides/.
 * @return array{ok:bool,name?:string,error?:string}
 */
function slide_save_upload(array $file, ?string $dir = null): array
{
    $dir = $dir ?? slides_dir();
    $max = slide_upload_max_bytes();
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => slide_upload_error_message($err)];
    }
    if (($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => 'Image must be under ' . slide_upload_max_label() . '.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create ' . $dir . ' — check permissions.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $orig = (string)($file['name'] ?? '');
    $ext = slide_upload_extension($tmp, $orig);
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, or WebP images are allowed.'];
    }
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($orig, PATHINFO_FILENAME));
    $base = trim($base, '-._');
    if ($base === '') {
        $base = 'slide';
    }
    $name = slide_unique_filename($base, $ext, $dir);
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not write to ' . $dir . ' — check permissions.'];
    }
    return ['ok' => true, 'name' => $name];
}

/**
 * Replace an existing slide file in place (keeps filename, deck, and rotation URLs).
 * @return array{ok:bool,file?:string,error?:string}
 */
function slide_replace_upload(string $file, array $upload, ?string $dir = null): array
{
    $dir = $dir ?? slides_dir();
    $safe = slide_safe_filename($file);
    if ($safe === null) {
        return ['ok' => false, 'error' => 'Invalid filename.'];
    }
    $path = $dir . '/' . $safe;
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Slide file not found.'];
    }

    $max = slide_upload_max_bytes();
    $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => slide_upload_error_message($err)];
    }
    if (($upload['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => 'Image must be under ' . slide_upload_max_label() . '.'];
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    $orig = (string)($upload['name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Upload did not arrive — try again.'];
    }
    if (slide_upload_extension($tmp, $orig) === null) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, or WebP images are allowed.'];
    }

    $data = @file_get_contents($tmp);
    if ($data === false || $data === '') {
        return ['ok' => false, 'error' => 'Could not read uploaded image.'];
    }
    if (@file_put_contents($path, $data) === false) {
        return ['ok' => false, 'error' => 'Could not replace ' . $safe . ' — check permissions.'];
    }

    return ['ok' => true, 'file' => $safe];
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

/** Filenames referenced in the saved slide deck. @return list<string> */
function slides_deck_files(?array $deck = null): array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        return [];
    }
    $files = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file !== null) {
            $files[] = $file;
        }
    }
    return $files;
}

/** Image files on disk that are not in the slide deck yet. @return list<string> */
function slides_orphan_files(?array $deck = null, ?string $dir = null): array
{
    $dir = $dir ?? slides_dir();
    $inDeck = array_flip(slides_deck_files($deck));
    return array_values(array_filter(slides_list_files($dir), static fn($f) => !isset($inDeck[$f])));
}

/** Admin/preview URL for a slide image file. */
function slide_thumb_url(string $file): ?string
{
    $safe = slide_safe_filename($file);
    if ($safe === null || !is_file(slides_dir() . '/' . $safe)) {
        return null;
    }
    $path = slides_dir() . '/' . $safe;
    $mtime = @filemtime($path);

    return 'slides.php?img=' . rawurlencode($safe) . ($mtime ? '&v=' . $mtime : '');
}

/** Single-slide preview URL (admin / library — shows file even if not on deck). */
function slide_preview_url(string $file): ?string
{
    $safe = slide_safe_filename($file);
    if ($safe === null) {
        return null;
    }
    return signage_board_preview_url('slides.php?slide=' . rawurlencode($safe) . '&preview=1');
}

/** Human label for a slide file (caption from deck, else filename). */
function slide_display_label(string $file, ?array $deck = null): string
{
    $slide = slide_deck_by_file($file, $deck);
    if (is_array($slide)) {
        $caption = trim((string)($slide['caption'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }
    }
    return $file;
}

/**
 * All slide files on disk with deck membership for the admin library grid.
 * @return list<array{file:string,in_deck:bool,label:string,caption:string,off:bool,thumb:?string,preview:?string}>
 */
function slides_library_entries(?array $deck = null, ?string $dir = null): array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        $deck = [];
    }
    $deckByFile = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file !== null) {
            $deckByFile[$file] = $slide;
        }
    }
    $out = [];
    foreach (slides_list_files($dir) as $file) {
        $entry = $deckByFile[$file] ?? null;
        $caption = is_array($entry) ? trim((string)($entry['caption'] ?? '')) : '';
        $out[] = [
            'file' => $file,
            'in_deck' => $entry !== null,
            'caption' => $caption,
            'label' => $caption !== '' ? $caption : $file,
            'off' => is_array($entry) && !empty($entry['off']),
            'thumb' => slide_thumb_url($file),
            'preview' => slide_preview_url($file),
        ];
    }
    usort($out, static function ($a, $b) {
        if ($a['in_deck'] !== $b['in_deck']) {
            return $a['in_deck'] ? 1 : -1;
        }
        return strcasecmp($a['label'], $b['label']);
    });
    return $out;
}

/** Remove one deck row by filename. @return list<array<string,mixed>> */
function slide_remove_from_deck(array $deck, string $file): array
{
    $want = slide_safe_filename($file);
    if ($want === null) {
        return $deck;
    }
    return array_values(array_filter($deck, function ($slide) use ($want) {
        if (!is_array($slide)) {
            return false;
        }
        return slide_safe_filename((string)($slide['file'] ?? '')) !== $want;
    }));
}

/**
 * Delete a slide file, remove it from the deck, and resync rotation on all displays.
 * @return array{ok:bool,file?:string,error?:string}
 */
function slide_delete_file(string $file): array
{
    require_once __DIR__ . '/users_lib.php';

    $safe = slide_safe_filename($file);
    if ($safe === null) {
        return ['ok' => false, 'error' => 'Invalid filename.'];
    }
    $fileFromEntry = static fn($e) => slide_safe_filename((string)($e['file'] ?? ''));

    if (!cfg_update(function (array $conf) use ($safe, $fileFromEntry): array {
        $deck = $conf['slides.SLIDES'] ?? [];
        if (!is_array($deck)) {
            $deck = [];
        }
        $deck = admin_remove_media_from_deck($deck, $safe, 'slide_safe_filename', $fileFromEntry);
        if ($deck === []) {
            unset($conf['slides.SLIDES']);
        } else {
            $conf['slides.SLIDES'] = $deck;
        }

        return $conf;
    })) {
        return ['ok' => false, 'error' => 'Could not update settings.json.'];
    }
    cfg_reload();

    $deck = cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        $deck = [];
    }

    $path = slides_dir() . '/' . $safe;
    if (!admin_media_deck_references_file($deck, $safe, 'slide_safe_filename', $fileFromEntry) && is_file($path)) {
        @unlink($path);
    }

    require_once __DIR__ . '/rotation_lib.php';
    $fullDeck = cfg('slides.SLIDES', []);
    if (!is_array($fullDeck)) {
        $fullDeck = [];
    }
    $scopeFiles = [$safe];
    foreach (admin_media_rotation_sync_screens() as $screen) {
        $sync = rotation_sync_slides($screen, $fullDeck, $scopeFiles);
        rotation_pages_write($sync['screen'], $sync['pages']);
    }
    cfg_reload();

    return ['ok' => true, 'file' => $safe];
}

/** Append a new slide entry to config deck (used by upload + creator). */
function slide_append_to_deck(string $filename, array $extra = []): bool
{
    require_once __DIR__ . '/users_lib.php';

    return cfg_update(function (array $conf) use ($filename, $extra): array {
        $deck = $conf['slides.SLIDES'] ?? [];
        if (!is_array($deck)) {
            $deck = [];
        }
        $row = admin_stamp_owner(array_merge(['file' => $filename, 'schedule' => 'always'], $extra), null);
        if (admin_operator_screen_locked()) {
            $sk = admin_operator_screen_key();
            if ($sk !== null && $sk !== '') {
                $row['screens'] = [$sk];
            }
        }
        $deck[] = $row;
        $conf['slides.SLIDES'] = $deck;

        return $conf;
    });
}

/**
 * Parse posted slide deck rows (SLIDES[] or SLIDES_JSON) for admin save.
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $existingSlides
 * @return list<array<string,mixed>>
 */
function slides_parse_post_rows(array $rows, array $existingSlides = []): array
{
    require_once __DIR__ . '/rotation_lib.php';
    require_once __DIR__ . '/users_lib.php';
    $allScreenKeys = array_keys(rotation_screens());
    sort($allScreenKeys);
    $outV = [];
    foreach ($rows as $row) {
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
            $screens = array_keys($screens);
            sort($screens);
            if ($screens === []) {
                $obj['screens'] = [];
            } elseif ($screens !== $allScreenKeys) {
                $obj['screens'] = $screens;
            }
        } elseif (!empty($row['_screens_form']) || !empty($row['_screens_all'])) {
            // User chose displays in admin — omitted screens / _screens_all means all displays.
        }
        if (admin_operator_screen_locked()) {
            $opScreen = (string)admin_operator_screen_key();
            if ($opScreen !== '' && (!isset($obj['screens']) || $obj['screens'] === [])) {
                $obj['screens'] = [$opScreen];
            }
        }
        if (($obj['file'] ?? '') !== '' || ($obj['caption'] ?? '') !== '' || ($obj['schedule'] ?? '') !== '') {
            $prev = admin_find_owned_list_entry($existingSlides, $obj);
            $outV[] = admin_finalize_entry($obj, $prev, $row);
        }
    }

    return $outV;
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

/** Validate PNG bytes are exactly 1920×1080. */
function slide_validate_png_bytes(string $raw): bool
{
    if ($raw === '') {
        return false;
    }
    $info = @getimagesizefromstring($raw);
    if (!$info || (int)($info[2] ?? 0) !== IMAGETYPE_PNG) {
        return false;
    }
    return (int)($info[0] ?? 0) === 1920 && (int)($info[1] ?? 0) === 1080;
}

/**
 * Read a rendered creator PNG from base64 POST data or a multipart upload.
 * @param array<string,mixed>|null $upload
 */
function slide_creator_read_png(?string $b64, ?array $upload): ?string
{
    if (is_string($b64) && $b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw !== false && slide_validate_png_bytes($raw)) {
            return $raw;
        }
    }
    if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp !== '' && is_uploaded_file($tmp)) {
            $raw = (string)file_get_contents($tmp);
            if (slide_validate_png_bytes($raw)) {
                return $raw;
            }
        }
    }
    return null;
}

function slide_creator_finish(string $filename): void
{
    header('Location: ?board=slides&tab=create&highlight=' . rawurlencode($filename));
    exit;
}

/**
 * Gradient / color theme backgrounds for the slide creator.
 */
function slide_theme_background_presets(): array
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
            'light' => true,
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
        'sky_glow' => [
            'label' => 'Sky',
            'title' => '#edf2fb',
            'subtitle' => '#7ec8ff',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'radial', 'cx' => 0.72, 'cy' => 0.2, 'r' => 1.1, 'stops' => [
                [0, '#1a4a6e'], [0.4, '#141f33'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'glow', 'color' => '#7ec8ff', 'opacity' => 0.16],
            'thumb' => 'sky_glow.png',
        ],
        'seafoam' => [
            'label' => 'Seafoam',
            'title' => '#edf2fb',
            'subtitle' => '#6ee7c8',
            'body' => '#8aa0c0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 135, 'stops' => [
                [0, '#0c1f22'], [0.55, '#0f2830'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'bar', 'color' => '#6ee7c8', 'width' => 10],
            'thumb' => 'seafoam.png',
        ],
        'coral' => [
            'label' => 'Coral',
            'title' => '#edf2fb',
            'subtitle' => '#ff9d9d',
            'body' => '#c8b0b0',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 150, 'stops' => [
                [0, '#2a1520'], [0.5, '#1a1a30'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'glow', 'color' => '#ff9d9d', 'opacity' => 0.14],
            'thumb' => 'coral.png',
        ],
        'lilac' => [
            'label' => 'Lilac',
            'title' => '#edf2fb',
            'subtitle' => '#c4a8ff',
            'body' => '#a8a0c8',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'radial', 'cx' => 0.15, 'cy' => 0.85, 'r' => 1.0, 'stops' => [
                [0, '#2a1f4a'], [0.5, '#141f33'], [1, '#0c1422'],
            ]],
            'thumb' => 'lilac.png',
        ],
        'golden_hour' => [
            'label' => 'Golden Hour',
            'title' => '#edf2fb',
            'subtitle' => '#ffd089',
            'body' => '#c8b898',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 175, 'stops' => [
                [0, '#3d2818'], [0.45, '#1f2538'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'glow', 'color' => '#ffd089', 'opacity' => 0.2],
            'thumb' => 'golden_hour.png',
        ],
        'rose' => [
            'label' => 'Rose',
            'title' => '#edf2fb',
            'subtitle' => '#ff8fc7',
            'body' => '#c8a0b8',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'linear', 'angle' => 120, 'stops' => [
                [0, '#2a1028'], [0.55, '#1a1a35'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'bar', 'color' => '#ff8fc7', 'width' => 10],
            'thumb' => 'rose.png',
        ],
        'slate' => [
            'label' => 'Slate',
            'title' => '#edf2fb',
            'subtitle' => '#a8b8d0',
            'body' => '#8aa0c0',
            'footer' => '#6a7890',
            'bg' => ['type' => 'linear', 'angle' => 180, 'stops' => [
                [0, '#1a2230'], [0.5, '#141c28'], [1, '#0c1422'],
            ]],
            'thumb' => 'slate.png',
        ],
        'ember' => [
            'label' => 'Ember',
            'title' => '#edf2fb',
            'subtitle' => '#ff7a45',
            'body' => '#c8a090',
            'footer' => '#8aa0c0',
            'bg' => ['type' => 'radial', 'cx' => 0.5, 'cy' => 1.05, 'r' => 1.15, 'stops' => [
                [0, '#4a1808'], [0.35, '#1f1520'], [1, '#0c1422'],
            ]],
            'accent' => ['type' => 'glow', 'color' => '#ff7a45', 'opacity' => 0.22],
            'thumb' => 'ember.png',
        ],
    ];
}

/** Default vignette overlay so photo backgrounds stay readable with text on top. */
function slide_photo_overlay_vignette(float $top = 0.74, float $mid = 0.38, float $bottom = 0.8): array
{
    return [
        'gradient' => ['type' => 'linear', 'angle' => 180, 'stops' => [
            [0, 'rgba(12,20,34,' . $top . ')'],
            [0.36, 'rgba(12,20,34,' . $mid . ')'],
            [0.64, 'rgba(12,20,34,' . $mid . ')'],
            [1, 'rgba(12,20,34,' . $bottom . ')'],
        ]],
    ];
}

/**
 * Photo scene backgrounds (Unsplash + Pexels — see slide_backgrounds/photos/CREDITS.md).
 * Rendered client-side: cover-fit photo + dim vignette, then text.
 */
function slide_photo_background_presets(): array
{
    $textDark = [
        'title' => '#edf2fb',
        'subtitle' => '#ffb347',
        'body' => '#c8d4e8',
        'footer' => '#8aa0c0',
    ];

    return [
        'photo_lake_dusk' => array_merge($textDark, [
            'label' => 'Lake at dusk',
            'kind' => 'photo',
            'photo' => 'photos/lake_dusk.jpg',
            'overlay' => slide_photo_overlay_vignette(),
        ]),
        'photo_misty_forest' => array_merge($textDark, [
            'label' => 'Misty forest',
            'kind' => 'photo',
            'photo' => 'photos/misty_forest.jpg',
            'overlay' => slide_photo_overlay_vignette(0.78, 0.42, 0.84),
        ]),
        'photo_ocean_sunset' => array_merge($textDark, [
            'label' => 'Ocean sunset',
            'kind' => 'photo',
            'photo' => 'photos/ocean_sunset.jpg',
            'overlay' => slide_photo_overlay_vignette(0.7, 0.32, 0.76),
        ]),
        'photo_city_night' => array_merge($textDark, [
            'label' => 'City at night',
            'kind' => 'photo',
            'photo' => 'photos/city_night.jpg',
            'overlay' => slide_photo_overlay_vignette(0.8, 0.45, 0.88),
        ]),
        'photo_cozy_home' => array_merge($textDark, [
            'label' => 'Warm living room',
            'kind' => 'photo',
            'photo' => 'photos/cozy_home.jpg',
            'overlay' => slide_photo_overlay_vignette(0.66, 0.34, 0.74),
            'subtitle' => '#ffd089',
        ]),
        'photo_fall_road' => array_merge($textDark, [
            'label' => 'Fall road',
            'kind' => 'photo',
            'photo' => 'photos/fall_road.jpg',
            'overlay' => slide_photo_overlay_vignette(0.68, 0.34, 0.72),
            'subtitle' => '#ffd089',
        ]),
        'photo_fall_forest' => array_merge($textDark, [
            'label' => 'Fall forest',
            'kind' => 'photo',
            'photo' => 'photos/fall_forest.jpg',
            'overlay' => slide_photo_overlay_vignette(0.66, 0.32, 0.7),
            'subtitle' => '#ffd089',
        ]),
        'photo_birthday' => array_merge($textDark, [
            'label' => 'Birthday balloons',
            'kind' => 'photo',
            'photo' => 'photos/birthday.jpg',
            'overlay' => slide_photo_overlay_vignette(0.72, 0.38, 0.78),
            'subtitle' => '#ff9d9d',
        ]),
        'photo_reminder' => array_merge($textDark, [
            'label' => 'Reminder',
            'kind' => 'photo',
            'photo' => 'photos/reminder.jpg',
            'overlay' => slide_photo_overlay_vignette(0.7, 0.36, 0.76),
            'subtitle' => '#ffd089',
        ]),
        'photo_winter_trees' => array_merge($textDark, [
            'label' => 'Winter evergreens',
            'kind' => 'photo',
            'photo' => 'photos/winter_trees.jpg',
            'overlay' => slide_photo_overlay_vignette(0.8, 0.45, 0.86),
            'subtitle' => '#ffd089',
        ]),
        'photo_romantic_dinner' => array_merge($textDark, [
            'label' => 'Candlelit dinner',
            'kind' => 'photo',
            'photo' => 'photos/romantic_dinner.jpg',
            'overlay' => slide_photo_overlay_vignette(0.82, 0.5, 0.86),
            'subtitle' => '#ffd089',
        ]),
        'photo_baseball' => array_merge($textDark, [
            'label' => 'Baseball field',
            'kind' => 'photo',
            'photo' => 'photos/baseball.jpg',
            'overlay' => slide_photo_overlay_vignette(0.7, 0.36, 0.76),
            'subtitle' => '#7ec8ff',
        ]),
        'photo_bowling' => array_merge($textDark, [
            'label' => 'Bowling alley',
            'kind' => 'photo',
            'photo' => 'photos/bowling.jpg',
            'overlay' => slide_photo_overlay_vignette(0.82, 0.5, 0.88),
            'subtitle' => '#ff9d9d',
        ]),
        'photo_stadium' => array_merge($textDark, [
            'label' => 'Stadium crowd',
            'kind' => 'photo',
            'photo' => 'photos/stadium.jpg',
            'overlay' => slide_photo_overlay_vignette(0.8, 0.48, 0.86),
            'subtitle' => '#7ec8ff',
        ]),
        'photo_mountain_sun' => array_merge($textDark, [
            'label' => 'Mountain sunrise',
            'kind' => 'photo',
            'photo' => 'photos/mountain_sun.jpg',
            'overlay' => slide_photo_overlay_vignette(0.66, 0.3, 0.74),
        ]),
    ];
}

/** All slide creator backgrounds (themes + photo scenes). */
function slide_background_presets(): array
{
    return slide_theme_background_presets() + slide_photo_background_presets();
}

function slide_background_is_photo(array $preset): bool
{
    return !empty($preset['photo']) || (($preset['kind'] ?? '') === 'photo');
}

/**
 * Occasion starters for the admin slide creator — prefills text, background, and alignment.
 * Bracketed tokens like [Name] are meant to be replaced before saving.
 */
function slide_creator_templates(): array
{
    return [
        'birthday' => [
            'label' => 'Birthday',
            'bg' => 'photo_birthday',
            'align' => 'center',
            'title' => 'Happy Birthday, [Name]!',
            'subtitle' => '[Month Day]',
            'body' => "Wishing you a wonderful day filled with happiness, laughter, and celebration.\nEnjoy your special day!",
            'footer' => 'Love, the family',
            'filename' => 'birthday',
        ],
        'welcome_home' => [
            'label' => 'Welcome home',
            'bg' => 'photo_cozy_home',
            'align' => 'center',
            'title' => 'Welcome Home, [Name]!',
            'subtitle' => 'We missed you',
            'body' => "So glad you're back.\nDrop your bags — there's food in the kitchen.",
            'footer' => 'Love, the family',
            'filename' => 'welcome-home',
        ],
        'congrats' => [
            'label' => 'Congratulations',
            'bg' => 'photo_mountain_sun',
            'align' => 'center',
            'title' => 'Congratulations, [Name]!',
            'subtitle' => '[Achievement]',
            'body' => "We're so proud of you.\nCelebrate tonight — you earned it.",
            'footer' => '',
            'filename' => 'congratulations',
        ],
        'party' => [
            'label' => 'Party invite',
            'bg' => 'photo_birthday',
            'align' => 'center',
            'title' => "You're Invited!",
            'subtitle' => '[Occasion] · [Month Day]',
            'body' => "[Time] at [Place]\nRSVP to [Contact]",
            'footer' => 'Hope to see you there',
            'filename' => 'party-invite',
        ],
        'holiday' => [
            'label' => 'Holiday',
            'bg' => 'photo_winter_trees',
            'align' => 'center',
            'title' => 'Happy Holidays',
            'subtitle' => 'From our family to yours',
            'body' => "Wishing you warmth, rest, and time together\nthis season.",
            'footer' => 'Love, the family',
            'filename' => 'holiday',
        ],
        'fall' => [
            'label' => 'Fall',
            'bg' => 'photo_fall_road',
            'align' => 'center',
            'title' => '[Headline]',
            'subtitle' => 'Autumn',
            'body' => "[Details about your fall plans or message]",
            'footer' => '',
            'filename' => 'fall',
        ],
        'thank_you' => [
            'label' => 'Thank you',
            'bg' => 'photo_fall_forest',
            'align' => 'center',
            'title' => 'Thank You, [Name]',
            'subtitle' => 'We appreciate you',
            'body' => "Your kindness meant the world to us.",
            'footer' => 'With gratitude',
            'filename' => 'thank-you',
        ],
        'graduation' => [
            'label' => 'Graduation',
            'bg' => 'photo_mountain_sun',
            'align' => 'center',
            'title' => 'Congratulations, [Name]!',
            'subtitle' => 'Class of [Year]',
            'body' => "We are so proud of everything you've accomplished.\nCelebration at [Time].",
            'footer' => 'Love, the family',
            'filename' => 'graduation',
        ],
        'baseball' => [
            'label' => 'Baseball / Softball',
            'bg' => 'photo_baseball',
            'align' => 'center',
            'title' => 'Game Day!',
            'subtitle' => '[Team] vs [Opponent]',
            'body' => "[Time] at [Field]\nSnacks in the cooler — play ball!",
            'footer' => 'Go [Team]!',
            'filename' => 'baseball',
        ],
        'bowling' => [
            'label' => 'Bowling',
            'bg' => 'photo_bowling',
            'align' => 'center',
            'title' => "Let's Bowl!",
            'subtitle' => '[Date] · [Time]',
            'body' => "[Location or league name]\n[Details — shoes, teams, etc.]",
            'footer' => 'See you at the lanes',
            'filename' => 'bowling',
        ],
        'game_day' => [
            'label' => 'Game day',
            'bg' => 'photo_stadium',
            'align' => 'center',
            'title' => 'Game Day!',
            'subtitle' => '[Team] vs [Opponent]',
            'body' => "Kickoff at [Time] — snacks in the den.\nWear your colors!",
            'footer' => 'Go [Team]!',
            'filename' => 'game-day',
        ],
        'reminder' => [
            'label' => 'Reminder',
            'bg' => 'photo_reminder',
            'align' => 'left',
            'title' => '[Headline]',
            'subtitle' => '[When]',
            'body' => "[Details]\n[Location or contact if needed]",
            'footer' => '',
            'filename' => 'reminder',
        ],
    ];
}

function slide_backgrounds_dir(): string
{
    return __DIR__ . '/slide_backgrounds';
}

/** Web path to a preset image (theme PNG or photo JPEG). */
function slide_background_url(string $presetId): ?string
{
    $presets = slide_background_presets();
    if (!isset($presets[$presetId])) {
        return null;
    }
    $preset = $presets[$presetId];
    if (!empty($preset['photo'])) {
        $file = slide_backgrounds_dir() . '/' . $preset['photo'];
        if (!is_file($file)) {
            return null;
        }
        $v = substr(hash_file('sha256', $file), 0, 12);

        return 'slide_backgrounds/' . $preset['photo'] . '?v=' . $v;
    }
    if (!isset($preset['thumb'])) {
        return null;
    }
    $file = slide_backgrounds_dir() . '/' . $preset['thumb'];
    if (!is_file($file)) {
        return null;
    }
    $v = substr(hash_file('sha256', $file), 0, 12);

    return 'slide_backgrounds/' . $preset['thumb'] . '?v=' . $v;
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

/** Remote URLs for bundled slide photo backgrounds (see photos/CREDITS.md). */
function slide_photo_download_urls(): array
{
    $unsplash = '?auto=format&fit=crop&w=1920&h=1080&q=82';
    $pexels   = '?auto=compress&cs=tinysrgb&w=1920&h=1080&fit=crop';

    return [
        'photos/lake_dusk.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1511497584788-876760111969' . $unsplash,
            'min_bytes' => 400000,
            'sha256' => 'e84d01f2898c128802ebf3de48570ce8ea5b55020f8fd471ed81b3abdf89c761',
        ],
        'photos/misty_forest.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e' . $unsplash,
            'min_bytes' => 500000,
            'sha256' => 'f4e50e9494913632704a09d3714ef7edc6fa85101667958495970ea2aa4ed28f',
        ],
        'photos/ocean_sunset.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e' . $unsplash,
            'min_bytes' => 200000,
            'sha256' => 'e24cf96e32527ad68aedb9a3a60bc80c6a65276cd64088d67ed20b481663e4e6',
        ],
        'photos/city_night.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1514565131-fce0801e5785' . $unsplash,
            'min_bytes' => 350000,
            'sha256' => 'cd79cc3d69a651809b270aa79f8d63abed09f7c1ad0577bb02503518324957e5',
        ],
        'photos/winter_trees.jpg' => [
            'url' => 'https://images.pexels.com/photos/1179229/pexels-photo-1179229.jpeg' . $pexels,
            'min_bytes' => 500000,
            'sha256' => '71395ac9e1d1c891cf4d92613cd5a6bef50786abd66a46e0fbb23126910ccf88',
        ],
        'photos/cozy_home.jpg' => [
            'url' => 'https://images.pexels.com/photos/276551/pexels-photo-276551.jpeg' . $pexels,
            'min_bytes' => 300000,
            'sha256' => 'adec24f44f9e446b947f195aef0d7ece09649084e61f9d95bbcb4fe7375dfb5f',
        ],
        'photos/fall_road.jpg' => [
            'url' => 'https://images.pexels.com/photos/1563356/pexels-photo-1563356.jpeg' . $pexels,
            'min_bytes' => 500000,
            'sha256' => 'f7e8b84a5c0a387cd272fa087eeaba9ca82027479a9edcc2ec82a168b1424821',
        ],
        'photos/fall_forest.jpg' => [
            'url' => 'https://images.pexels.com/photos/33109/fall-autumn-red-season.jpg' . $pexels,
            'min_bytes' => 500000,
            'sha256' => '9282ac25e3e22a453904631e46cb2f601d2068b70e97b9b7438a0f1bf0df2c38',
        ],
        'photos/birthday.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1530103862676-de8c9debad1d' . $unsplash,
            'min_bytes' => 150000,
            'sha256' => '14a33f932510fa9b7ca2f3db44b41e486608bc1c7472f8a943edf5630ff974b9',
        ],
        'photos/reminder.jpg' => [
            'url' => 'https://images.pexels.com/photos/6357/coffee-cup-desk-pen.jpg' . $pexels,
            'min_bytes' => 100000,
            'sha256' => 'c24000a36e8f47e60693e4e7bd9821cbfd4d53a88a0409f687ab2a2cdbef1ba8',
        ],
        'photos/romantic_dinner.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0' . $unsplash,
            'min_bytes' => 300000,
            'sha256' => 'd06207c9428fb009e20bd437408f70b22d96951a8136f92c24dbb6a4d14c712b',
        ],
        'photos/baseball.jpg' => [
            'url' => 'https://images.pexels.com/photos/37913315/pexels-photo-37913315.jpeg' . $pexels,
            'min_bytes' => 200000,
            'sha256' => '9d1d3fae050baf06470f49a4226037b4d7f685f046f81730e79cdb42b0de7eff',
        ],
        'photos/bowling.jpg' => [
            'url' => 'https://images.unsplash.com/photo-1680479611062-1fc82f9fb95b' . $unsplash,
            'min_bytes' => 350000,
            'sha256' => '6db4b5d50f8d5d51609d72a5a406fb26b94c04b5335f0360d9f83937e4ba2c09',
        ],
        'photos/stadium.jpg' => [
            'url' => 'https://images.pexels.com/photos/1884574/pexels-photo-1884574.jpeg' . $pexels,
            'min_bytes' => 300000,
            'sha256' => '388a71e081f1cc4f118a6b869cf50e7b7cace8e9fb9a43be3ce40a7540bd35e9',
        ],
        'photos/mountain_sun.jpg' => [
            'url' => 'https://images.pexels.com/photos/417173/pexels-photo-417173.jpeg' . $pexels,
            'min_bytes' => 250000,
            'sha256' => '189df31adbe7d34618d1ccf3b44f36fa425cb2200180158490d1f294af073f09',
        ],
    ];
}

function slide_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'signage-suite/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        return $body;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 120, 'header' => "User-Agent: signage-suite/1.0\r\n"],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

/**
 * Download any missing slide photo backgrounds into slide_backgrounds/photos/.
 * Idempotent — skips files already on disk. Returns count of newly fetched files.
 */
function slide_background_ensure_photos(?string $dir = null): int
{
    $dir = $dir ?? slide_backgrounds_dir();
    $photosDir = $dir . '/photos';
    if (!is_dir($photosDir) && !@mkdir($photosDir, 0775, true)) {
        return 0;
    }
    $fetched = 0;
    foreach (slide_photo_download_urls() as $rel => $spec) {
        if (is_string($spec)) {
            $spec = ['url' => $spec, 'min_bytes' => 10000];
        }
        $path = $dir . '/' . $rel;
        $minBytes = (int)($spec['min_bytes'] ?? 10000);
        $wantHash = (string)($spec['sha256'] ?? '');
        if (is_file($path)) {
            $size = filesize($path);
            $hashOk = $wantHash === '' || hash_file('sha256', $path) === $wantHash;
            if ($size >= $minBytes && $hashOk) {
                continue;
            }
        }
        $body = slide_http_get((string)($spec['url'] ?? ''));
        if ($body === null || strlen($body) < $minBytes) {
            continue;
        }
        if ($wantHash !== '' && hash('sha256', $body) !== $wantHash) {
            continue;
        }
        if (@file_put_contents($path, $body) !== false) {
            $fetched++;
        }
    }
    return $fetched;
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
    foreach (slide_theme_background_presets() as $id => $preset) {
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

/** @return array{0:int,1:int}|null month and day */
function slide_parse_mmdd(string $raw): ?array
{
    if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($raw), $m)) {
        return null;
    }
    $month = (int)$m[1];
    $day = (int)$m[2];
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return null;
    }
    return [$month, $day];
}

function slide_mmdd_int(int $month, int $day): int
{
    return $month * 100 + $day;
}

function slide_today_mmdd_int(DateTimeInterface $now): int
{
    return slide_mmdd_int((int)$now->format('n'), (int)$now->format('j'));
}

/** @return list<string> Full weekday names */
function slide_parse_weekdays(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $full = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        foreach ($full as $name) {
            if (strcasecmp($part, $name) === 0
                || (strlen($part) >= 3 && strncasecmp($part, $name, strlen($part)) === 0)) {
                $out[] = $name;
                break;
            }
        }
    }
    return array_values(array_unique($out));
}

function slide_weekday_active(array $slide, DateTimeInterface $now): bool
{
    $days = slide_parse_weekdays((string)($slide['weekdays'] ?? ''));
    if ($days === []) {
        $wd = (string)($slide['weekday'] ?? '');
        return $wd !== '' && strcasecmp($now->format('l'), $wd) === 0;
    }
    $today = $now->format('l');
    foreach ($days as $d) {
        if (strcasecmp($today, $d) === 0) {
            return true;
        }
    }
    return false;
}

function slide_yearly_range_active(string $startRaw, string $endRaw, DateTimeInterface $now): bool
{
    $start = slide_parse_mmdd($startRaw);
    $end = slide_parse_mmdd($endRaw);
    if ($start === null || $end === null) {
        return false;
    }
    $today = slide_today_mmdd_int($now);
    $s = slide_mmdd_int($start[0], $start[1]);
    $e = slide_mmdd_int($end[0], $end[1]);
    if ($s <= $e) {
        return $today >= $s && $today <= $e;
    }
    return $today >= $s || $today <= $e;
}

/** Optional hour_from / hour_to (0–23). Empty = all day. Overnight windows supported. */
function slide_time_window_active(array $slide, DateTimeInterface $now): bool
{
    $hasFrom = isset($slide['hour_from']) && $slide['hour_from'] !== '' && $slide['hour_from'] !== null;
    $hasTo = isset($slide['hour_to']) && $slide['hour_to'] !== '' && $slide['hour_to'] !== null;
    if (!$hasFrom && !$hasTo) {
        return true;
    }
    $from = $hasFrom ? max(0, min(23, (int)$slide['hour_from'])) : 0;
    $to = $hasTo ? max(0, min(23, (int)$slide['hour_to'])) : 23;
    $h = (int)$now->format('G');
    if ($from <= $to) {
        return $h >= $from && $h <= $to;
    }
    return $h >= $from || $h <= $to;
}

/**
 * Whether a slide entry should show right now (date schedule only — not priority).
 * schedule: always | once | range | yearly | yearly_range | monthly | weekly | off
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
        return slide_time_window_active($slide, $now);
    }

    $dateOk = true;

    if ($sched === 'once') {
        $d = (string)($slide['date_start'] ?? '');
        $dateOk = $d !== '' && $now->format('Y-m-d') === $d;
    } elseif ($sched === 'range') {
        $start = (string)($slide['date_start'] ?? '');
        $end   = (string)($slide['date_end'] ?? '');
        if ($start === '' || $end === '') {
            $dateOk = false;
        } else {
            $today = $now->format('Y-m-d');
            $dateOk = $today >= $start && $today <= $end;
        }
    } elseif ($sched === 'yearly') {
        $md = slide_parse_mmdd((string)($slide['month_day'] ?? ''));
        $dateOk = $md !== null
            && slide_today_mmdd_int($now) === slide_mmdd_int($md[0], $md[1]);
    } elseif ($sched === 'yearly_range') {
        $dateOk = slide_yearly_range_active(
            (string)($slide['month_day'] ?? ''),
            (string)($slide['month_day_end'] ?? ''),
            $now
        );
    } elseif ($sched === 'monthly') {
        $dom = (int)($slide['day_of_month'] ?? 0);
        $dateOk = $dom >= 1 && $dom <= 31 && (int)$now->format('j') === $dom;
    } elseif ($sched === 'weekly') {
        $dateOk = slide_weekday_active($slide, $now);
    } else {
        $dateOk = true;
    }

    return $dateOk && slide_time_window_active($slide, $now);
}

function slide_is_priority(array $slide): bool
{
    return !empty($slide['priority']);
}

function slide_dwell(array $slide, int $default = 12): int
{
    $d = (int)($slide['dwell'] ?? 0);
    return $d > 0 ? $d : $default;
}

function slides_default_dwell(): int
{
    return max(1, (int)cfg('slides.DEFAULT_DWELL', 12));
}

/** Human-readable schedule line for admin cards. */
function slide_schedule_summary(array $slide): string
{
    if (!empty($slide['off'])) {
        return 'Disabled';
    }
    $sched = strtolower(trim((string)($slide['schedule'] ?? 'always')));
    if ($sched === '') {
        $sched = 'always';
    }
    $hour = '';
    if (($slide['hour_from'] ?? '') !== '' || ($slide['hour_to'] ?? '') !== '') {
        $hour = ' · ' . ($slide['hour_from'] ?? '0') . '–' . ($slide['hour_to'] ?? '23') . 'h';
    }
    return match ($sched) {
        'once' => 'Once · ' . trim((string)($slide['date_start'] ?? 'date?')) . $hour,
        'range' => trim((string)($slide['date_start'] ?? '?')) . ' → ' . trim((string)($slide['date_end'] ?? '?')) . $hour,
        'yearly' => 'Yearly · ' . trim((string)($slide['month_day'] ?? 'MM-DD')) . $hour,
        'yearly_range' => trim((string)($slide['month_day'] ?? 'MM-DD')) . ' → ' . trim((string)($slide['month_day_end'] ?? 'MM-DD')) . $hour,
        'monthly' => 'Monthly · day ' . trim((string)($slide['day_of_month'] ?? '?')) . $hour,
        'weekly' => 'Weekly · ' . trim((string)($slide['weekdays'] ?? 'days?')) . $hour,
        default => 'Always' . $hour,
    };
}

/**
 * Deck counters for admin deploy panel.
 * @return array{total:int,enabled:int,on_disk:int,active_now:int,playlist_entries:int}
 */
function slides_deck_stats(?array $deck = null): array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        $deck = [];
    }
    $dir = slides_dir();
    $tz = new DateTimeZone(slides_timezone());
    $now = new DateTime('now', $tz);
    $total = $enabled = $onDisk = $activeNow = 0;

    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $total++;
        if (!empty($slide['off'])) {
            continue;
        }
        $enabled++;
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file === null || !is_file($dir . '/' . $file)) {
            continue;
        }
        $onDisk++;
        if (slide_schedule_active($slide, $now)) {
            $activeNow++;
        }
    }

    return [
        'total' => $total,
        'enabled' => $enabled,
        'on_disk' => $onDisk,
        'active_now' => $activeNow,
        'playlist_entries' => $onDisk,
    ];
}

/** Rotation URL for one slide image (one playlist entry per slide). */
function slide_rotation_url(string $file): string
{
    $safe = slide_safe_filename($file);
    if ($safe === null) {
        return 'slides.php';
    }
    return 'slides.php?slide=' . rawurlencode($safe);
}

/** Parse slide filename from a rotation URL, or null if not a slide entry. */
function slide_rotation_parse_file(string $url): ?string
{
    $url = trim($url);
    if (strtok($url, '?') !== 'slides.php') {
        return null;
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    if (!isset($params['slide'])) {
        return null;
    }
    return slide_safe_filename((string)$params['slide']);
}

/** @return array<string,mixed>|null */
function slide_deck_by_file(string $file, ?array $deck = null): ?array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        return null;
    }
    $want = slide_safe_filename($file);
    if ($want === null) {
        return null;
    }
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        if (slide_safe_filename((string)($slide['file'] ?? '')) === $want) {
            return $slide;
        }
    }
    return null;
}

/** @return list<string> Empty = no restriction (all deploy targets). */
function slide_target_screens(array $slide): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $raw = $slide['screens'] ?? null;
    if ($raw === null || $raw === '' || $raw === []) {
        return [];
    }
    $keys = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $k = rotation_normalize_screen_key((string)$item);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
    } else {
        foreach (preg_split('/\s*,\s*/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            $k = rotation_normalize_screen_key((string)$item);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
    }
    return array_keys($keys);
}

function slide_on_screen(array $slide, string $screen): bool
{
    if (!array_key_exists('screens', $slide)) {
        return true;
    }
    $targets = slide_target_screens($slide);
    if ($targets === []) {
        return false;
    }
    return in_array(rotation_normalize_screen_key($screen), $targets, true);
}

/** True when every enabled on-disk slide is explicitly hidden from all displays (screens: []). */
function slides_deck_untargeted_misconfig(?array $deck = null): bool
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck) || $deck === []) {
        return false;
    }
    $dir = slides_dir();
    $enabledOnDisk = 0;
    $reachable = 0;
    foreach ($deck as $slide) {
        if (!is_array($slide) || !empty($slide['off'])) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file === null || !is_file($dir . '/' . $file)) {
            continue;
        }
        $enabledOnDisk++;
        if (!array_key_exists('screens', $slide) || slide_target_screens($slide) !== []) {
            $reachable++;
        }
    }

    return $enabledOnDisk > 0 && $reachable === 0;
}

/**
 * Remove screens: [] (hidden on all displays) so slides play everywhere again.
 * @param list<array<string,mixed>> $deck
 * @return list<array<string,mixed>>
 */
function slides_repair_deck_screen_targets(array $deck): array
{
    $out = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        if (array_key_exists('screens', $slide) && slide_target_screens($slide) === []) {
            unset($slide['screens']);
        }
        $out[] = $slide;
    }

    return $out;
}

/**
 * Drop unknown display keys; clear targeting when nothing valid remains (→ all displays).
 * @param list<array<string,mixed>> $deck
 * @return list<array<string,mixed>>
 */
function slides_repair_deck_stale_screen_keys(array $deck): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $allKeys = array_keys(rotation_screens());
    $known = array_flip($allKeys);
    if ($known === []) {
        return $deck;
    }
    sort($allKeys);
    $out = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        if (!array_key_exists('screens', $slide)) {
            $out[] = $slide;
            continue;
        }
        $targets = [];
        foreach (slide_target_screens($slide) as $t) {
            if (isset($known[$t])) {
                $targets[$t] = true;
            }
        }
        $targets = array_keys($targets);
        sort($targets);
        if ($targets === [] || $targets === $allKeys) {
            unset($slide['screens']);
        } else {
            $slide['screens'] = $targets;
        }
        $out[] = $slide;
    }

    return $out;
}

/**
 * Enabled on-disk slides exist but none are assigned to any configured display.
 * @param list<array<string,mixed>> $deck
 */
function slides_deck_targets_no_configured_screens(array $deck): bool
{
    require_once __DIR__ . '/rotation_lib.php';
    if (slides_rotation_pages($deck, null) === []) {
        return false;
    }
    foreach (array_keys(rotation_screens()) as $key) {
        if (slides_rotation_pages($deck, (string)$key) !== []) {
            return false;
        }
    }

    return true;
}

/**
 * Apply all deck targeting repairs (hidden slides, stale keys, whole-deck misconfig).
 * @param list<array<string,mixed>> $deck
 * @return list<array<string,mixed>>
 */
function slides_repair_deck(array $deck): array
{
    $deck = slides_repair_deck_screen_targets($deck);
    $deck = slides_repair_deck_stale_screen_keys($deck);

    return slides_repair_deck_untargeted($deck);
}

/**
 * Rotation rows to deploy for one display (respects scope; recovery when targeting is broken).
 * @param list<string>|null $scopeFiles
 * @return list<array{url:string,dwell:int,file:string}>
 */
function slides_deploy_expected_pages(?array $deck, ?string $screen, ?array $scopeFiles): array
{
    $pages = slides_rotation_pages_for_scope($deck, $screen, $scopeFiles);
    if ($pages !== []) {
        return $pages;
    }
    if ($scopeFiles !== null && $scopeFiles === []) {
        return [];
    }
    $deck = is_array($deck) ? $deck : [];
    if (!slides_deck_targets_no_configured_screens($deck)) {
        return [];
    }

    return slides_rotation_pages_for_scope($deck, null, $scopeFiles);
}

/**
 * Normalize a display target list for storage (null = omit key → all displays).
 * @param list<string> $targets
 * @param list<string> $allKeys
 * @return list<string>|null null means all displays (omit screens key)
 */
function slides_normalize_stored_screen_targets(array $targets, array $allKeys): ?array
{
    require_once __DIR__ . '/rotation_lib.php';
    $targets = array_values(array_unique(array_filter(array_map(
        static fn($k) => rotation_normalize_screen_key((string)$k),
        $targets
    ))));
    sort($targets);
    $allKeys = array_values(array_unique(array_map(
        static fn($k) => rotation_normalize_screen_key((string)$k),
        $allKeys
    )));
    sort($allKeys);
    if ($targets === []) {
        return [];
    }
    if ($targets === $allKeys) {
        return null;
    }

    return $targets;
}

/** @param array<string,mixed> $slide */
function slides_untarget_screen_in_slide(array $slide, string $screen, array $allKeys): array
{
    $screen = rotation_normalize_screen_key($screen);
    if (!array_key_exists('screens', $slide)) {
        $remain = array_values(array_filter($allKeys, static fn($k) => $k !== $screen));
    } else {
        $remain = array_values(array_filter(
            slide_target_screens($slide),
            static fn($k) => $k !== $screen
        ));
    }
    $stored = slides_normalize_stored_screen_targets($remain, $allKeys);
    if ($stored === null) {
        unset($slide['screens']);
    } elseif ($stored === []) {
        $slide['screens'] = [];
    } else {
        $slide['screens'] = $stored;
    }

    return $slide;
}

/**
 * Stop slides in the deck from targeting one display (does not delete slide files).
 * @param list<array<string,mixed>> $deck
 * @return list<array<string,mixed>>
 */
function slides_untarget_screen_in_deck(array $deck, string $screen): array
{
    require_once __DIR__ . '/rotation_lib.php';
    require_once __DIR__ . '/users_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $allKeys = array_keys(rotation_screens());
    $out = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        if (!admin_is_super() && !admin_entry_visible($slide)) {
            $out[] = $slide;
            continue;
        }
        $out[] = slides_untarget_screen_in_slide($slide, $screen, $allKeys);
    }

    return $out;
}

/**
 * Remove custom slides from one display's rotation playlist and deck targeting.
 * @return array{ok:bool,screen:string,removed_count:int,deck_updated:bool,error?:string}
 */
function slides_remove_from_display(string $screen): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    if ($screen === '') {
        return ['ok' => false, 'screen' => '', 'removed_count' => 0, 'deck_updated' => false, 'error' => 'Invalid display.'];
    }
    $rm = rotation_remove_all_slides($screen);
    $deck = cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        $deck = [];
    }
    $newDeck = slides_untarget_screen_in_deck($deck, $screen);
    $deckUpdated = $newDeck !== $deck;
    if (!$rm['removed'] && !$deckUpdated) {
        return [
            'ok' => false,
            'screen' => $screen,
            'removed_count' => 0,
            'deck_updated' => false,
            'error' => 'Custom slides were not on that display.',
        ];
    }
    $pageWrites = [];
    if ($rm['removed']) {
        $pageWrites[$screen] = $rm['pages'];
    }
    if ($pageWrites !== [] || $deckUpdated) {
        if (!rotation_pages_write_batch($pageWrites, $deckUpdated ? $newDeck : null)) {
            return [
                'ok' => false,
                'screen' => $screen,
                'removed_count' => (int)$rm['removed_count'],
                'deck_updated' => false,
                'error' => 'Could not update settings.',
            ];
        }
        cfg_reload();
    }

    return [
        'ok' => true,
        'screen' => $screen,
        'removed_count' => (int)$rm['removed_count'],
        'deck_updated' => $deckUpdated,
    ];
}

/**
 * Clear accidental screens: [] so slides play on all displays again.
 * @return list<array<string,mixed>>
 */
function slides_repair_deck_untargeted(?array $deck = null): array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck) || !slides_deck_untargeted_misconfig($deck)) {
        return is_array($deck) ? $deck : [];
    }
    $out = [];
    foreach ($deck as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        if (array_key_exists('screens', $slide) && slide_target_screens($slide) === []) {
            unset($slide['screens']);
        }
        $out[] = $slide;
    }

    return $out;
}

/** Files the current user may push to rotation. null = super admin (manage entire slide block). */
function slides_deploy_scope_files(?array $deck = null): ?array
{
    require_once __DIR__ . '/users_lib.php';
    if (admin_is_super()) {
        return null;
    }
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        return [];
    }
    $files = [];
    foreach (admin_filter_owned_list($deck) as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file !== null) {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * Rotation rows for one display, optionally limited to slides the current user may deploy.
 * @param list<string>|null $scopeFiles from slides_deploy_scope_files(); null = all slides in deck
 * @return list<array{url:string,dwell:int,file:string}>
 */
function slides_rotation_pages_for_scope(?array $deck, ?string $screen, ?array $scopeFiles): array
{
    $pages = slides_effective_rotation_pages($deck, $screen);
    if ($scopeFiles === null) {
        return $pages;
    }
    $scopeSet = array_flip($scopeFiles);

    return array_values(array_filter($pages, static function ($row) use ($scopeSet) {
        return isset($scopeSet[(string)($row['file'] ?? '')]);
    }));
}

/** Display keys that have at least one enabled slide in the deck targeting them. @return list<string> */
function slides_screens_in_deck(?array $deck = null): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        return [];
    }
    $out = [];
    foreach (array_keys(rotation_screens()) as $key) {
        if (slides_rotation_pages($deck, (string)$key) !== []) {
            $out[] = (string)$key;
        }
    }
    sort($out);

    return $out;
}

/** @param list<array<string,mixed>> $deck */
function slides_persist_deck(array $deck): bool
{
    return cfg_update(function (array $conf) use ($deck): array {
        if ($deck === []) {
            unset($conf['slides.SLIDES']);
        } else {
            $conf['slides.SLIDES'] = $deck;
        }

        return $conf;
    });
}

/**
 * Enabled on-disk slides as rotation page rows, in deck order.
 * @return list<array{url:string,dwell:int,file:string}>
 */
function slides_rotation_pages(?array $deck = null, ?string $screen = null): array
{
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        return [];
    }
    $dir = slides_dir();
    $default = slides_default_dwell();
    $screenKey = $screen !== null ? rotation_normalize_screen_key($screen) : null;
    $out = [];
    foreach ($deck as $slide) {
        if (!is_array($slide) || !empty($slide['off'])) {
            continue;
        }
        if ($screenKey !== null && $screenKey !== '' && !slide_on_screen($slide, $screenKey)) {
            continue;
        }
        $file = slide_safe_filename((string)($slide['file'] ?? ''));
        if ($file === null || !is_file($dir . '/' . $file)) {
            continue;
        }
        $out[] = [
            'url' => slide_rotation_url($file),
            'dwell' => slide_dwell($slide, $default),
            'file' => $file,
        ];
    }
    return $out;
}

/**
 * Rotation rows for one display; recovers when the whole deck was accidentally untargeted.
 * @return list<array{url:string,dwell:int,file:string}>
 */
function slides_effective_rotation_pages(?array $deck = null, ?string $screen = null): array
{
    $pages = slides_rotation_pages($deck, $screen);
    if ($pages !== [] || $screen === null || $screen === '') {
        return $pages;
    }
    if (!slides_deck_untargeted_misconfig($deck)) {
        return [];
    }

    return slides_rotation_pages($deck, null);
}

/** Active slides that exist on disk, in configured order. */
function slides_active_entries(?array $entries = null, ?string $dir = null): array
{
    static $cache = [];

    $dir = $dir ?? slides_dir();
    $entries = $entries ?? cfg('slides.SLIDES', []);
    if (!is_array($entries)) {
        return [];
    }
    $cacheKey = md5($dir . "\0" . json_encode($entries, JSON_UNESCAPED_SLASHES));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $tz  = new DateTimeZone(slides_timezone());
    $now = new DateTime('now', $tz);
    $candidates = [];

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
        $candidates[] = $slide;
    }

    $priority = array_values(array_filter($candidates, 'slide_is_priority'));
    $cache[$cacheKey] = $priority !== [] ? $priority : $candidates;

    return $cache[$cacheKey];
}
