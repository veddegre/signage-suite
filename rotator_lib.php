<?php
/**
 * Photo rotator — shared helpers for rotator.php and admin upload/delete.
 */

require_once __DIR__ . '/config.php';

function rotator_photo_dir(): string
{
    $d = cfg('rotator.PHOTO_DIR', 'photos');
    if ($d === '' || $d === 'photos') {
        return __DIR__ . '/photos';
    }
    if ($d[0] !== '/') {
        return __DIR__ . '/' . trim($d, '/');
    }
    return $d;
}

function rotator_safe_filename(string $name): ?string
{
    $name = basename($name);
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(jpe?g|png)$/i', $name)) {
        return null;
    }
    return $name;
}

/** List image filenames in the photo directory. */
function rotator_list_photos(?string $dir = null): array
{
    $dir = $dir ?? rotator_photo_dir();
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir) as $f) {
        if (rotator_safe_filename($f)) {
            $out[] = $f;
        }
    }
    sort($out);
    return $out;
}

function rotator_unique_filename(string $base, string $ext, ?string $dir = null): string
{
    $dir = $dir ?? rotator_photo_dir();
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base);
    $base = trim($base, '-._');
    if ($base === '') {
        $base = 'photo';
    }
    $name = $base . '.' . $ext;
    if (!is_file($dir . '/' . $name)) {
        return $name;
    }
    return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
}

/** @return array{ok:bool,name?:string,error?:string} */
function rotator_save_upload(array $file, ?string $dir = null, int $maxBytes = 25_000_000): array
{
    $dir = $dir ?? rotator_photo_dir();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed — try again.'];
    }
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Image must be under ' . (int)($maxBytes / 1_000_000) . ' MB.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create ' . $dir . ' — check permissions.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extMap[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG or PNG images are allowed.'];
    }
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME));
    $base = trim($base, '-._');
    if ($base === '') {
        $base = 'photo';
    }
    $name = rotator_unique_filename($base, $extMap[$mime], $dir);
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not write to ' . $dir . ' — check permissions.'];
    }
    return ['ok' => true, 'name' => $name];
}

function rotator_delete_photo(string $filename, ?string $dir = null): bool
{
    $dir = $dir ?? rotator_photo_dir();
    $safe = rotator_safe_filename($filename);
    if ($safe === null) {
        return false;
    }
    $path = $dir . '/' . $safe;
    if (!is_file($path)) {
        return false;
    }
    return @unlink($path);
}

/** Expand a single or multi-part $_FILES entry into a list of upload arrays. */
function rotator_normalize_uploads(array $files): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $out[] = [
            'name'     => $name,
            'type'     => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function rotator_default_dwell(): int
{
    return max(1, (int)cfg('rotator.DEFAULT_DWELL', (int)cfg('rotator.INTERVAL_SEC', 18)));
}

/** @return 'individual'|'groups'|'legacy' */
function rotator_deploy_mode(): string
{
    $mode = strtolower(trim((string)cfg('rotator.DEPLOY_MODE', 'individual')));
    return in_array($mode, ['individual', 'groups', 'legacy'], true) ? $mode : 'individual';
}

function rotator_photo_dwell(array $photo, ?int $default = null): int
{
    $default = $default ?? rotator_default_dwell();
    $d = (int)($photo['dwell'] ?? 0);
    return $d > 0 ? $d : $default;
}

function rotator_normalize_group(string $group): string
{
    $group = trim($group);
    $group = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $group);
    return trim($group, '-._');
}

function rotator_rotation_url(string $file): string
{
    $safe = rotator_safe_filename($file);
    if ($safe === null) {
        return 'rotator.php';
    }
    return 'rotator.php?photo=' . rawurlencode($safe);
}

function rotator_group_url(string $group): string
{
    $group = rotator_normalize_group($group);
    if ($group === '') {
        return 'rotator.php';
    }
    return 'rotator.php?group=' . rawurlencode($group);
}

function rotator_is_legacy_url(string $url): bool
{
    return trim($url) === 'rotator.php';
}

function rotator_rotation_parse_file(string $url): ?string
{
    $url = trim($url);
    if (strtok($url, '?') !== 'rotator.php') {
        return null;
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    if (!isset($params['photo'])) {
        return null;
    }
    return rotator_safe_filename((string)$params['photo']);
}

function rotator_rotation_parse_group(string $url): ?string
{
    $url = trim($url);
    if (strtok($url, '?') !== 'rotator.php') {
        return null;
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $params);
    if (!isset($params['group'])) {
        return null;
    }
    $group = rotator_normalize_group((string)$params['group']);
    return $group !== '' ? $group : null;
}

/** @return list<array<string,mixed>> */
function rotator_deck(?array $deck = null): array
{
    $deck = is_array($deck) ? $deck : cfg('rotator.PHOTOS', []);
    return is_array($deck) ? $deck : [];
}

/** @return list<string> */
function rotator_deck_files(?array $deck = null): array
{
    $files = [];
    foreach (rotator_deck($deck) as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $file = rotator_safe_filename((string)($photo['file'] ?? ''));
        if ($file !== null) {
            $files[] = $file;
        }
    }
    return $files;
}

/** @return array<string,mixed>|null */
function rotator_deck_by_file(string $file, ?array $deck = null): ?array
{
    $want = rotator_safe_filename($file);
    if ($want === null) {
        return null;
    }
    foreach (rotator_deck($deck) as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        if (rotator_safe_filename((string)($photo['file'] ?? '')) === $want) {
            return $photo;
        }
    }
    return null;
}

/** @return list<string> Empty = no restriction (all deploy targets). */
function rotator_target_screens(array $photo): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $raw = $photo['screens'] ?? null;
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

function rotator_on_screen(array $photo, string $screen): bool
{
    if (!array_key_exists('screens', $photo)) {
        return true;
    }
    $targets = rotator_target_screens($photo);
    if ($targets === []) {
        return false;
    }
    require_once __DIR__ . '/rotation_lib.php';
    return in_array(rotation_normalize_screen_key($screen), $targets, true);
}

function rotator_photo_enabled(array $photo, ?string $dir = null): bool
{
    if (!is_array($photo) || !empty($photo['off'])) {
        return false;
    }
    $dir = $dir ?? rotator_photo_dir();
    $file = rotator_safe_filename((string)($photo['file'] ?? ''));
    return $file !== null && is_file($dir . '/' . $file);
}

function rotator_thumb_url(string $file): ?string
{
    $safe = rotator_safe_filename($file);
    if ($safe === null || !is_file(rotator_photo_dir() . '/' . $safe)) {
        return null;
    }
    $mtime = @filemtime(rotator_photo_dir() . '/' . $safe);
    return 'rotator.php?img=' . rawurlencode($safe) . ($mtime ? '&v=' . $mtime : '');
}

function rotator_preview_url(string $file): ?string
{
    $safe = rotator_safe_filename($file);
    if ($safe === null) {
        return null;
    }
    return signage_board_preview_url('rotator.php?photo=' . rawurlencode($safe) . '&preview=1');
}

function rotator_display_label(string $file, ?array $deck = null): string
{
    $photo = rotator_deck_by_file($file, $deck);
    if (is_array($photo)) {
        $caption = trim((string)($photo['caption'] ?? ''));
        if ($caption !== '') {
            return $caption;
        }
    }
    return $file;
}

/**
 * @return list<array{file:string,in_deck:bool,label:string,caption:string,off:bool,group:string,thumb:?string,preview:?string}>
 */
function rotator_library_entries(?array $deck = null, ?string $dir = null): array
{
    $deck = rotator_deck($deck);
    $dir = $dir ?? rotator_photo_dir();
    $deckByFile = [];
    foreach ($deck as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $file = rotator_safe_filename((string)($photo['file'] ?? ''));
        if ($file !== null) {
            $deckByFile[$file] = $photo;
        }
    }
    $out = [];
    foreach (rotator_list_photos($dir) as $file) {
        $entry = $deckByFile[$file] ?? null;
        $caption = is_array($entry) ? trim((string)($entry['caption'] ?? '')) : '';
        $out[] = [
            'file' => $file,
            'in_deck' => $entry !== null,
            'caption' => $caption,
            'label' => $caption !== '' ? $caption : $file,
            'off' => is_array($entry) && !empty($entry['off']),
            'group' => is_array($entry) ? trim((string)($entry['group'] ?? '')) : '',
            'thumb' => rotator_thumb_url($file),
            'preview' => rotator_preview_url($file),
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

/** @return list<string> */
function rotator_orphan_files(?array $deck = null, ?string $dir = null): array
{
    $dir = $dir ?? rotator_photo_dir();
    $inDeck = array_flip(rotator_deck_files($deck));
    return array_values(array_filter(rotator_list_photos($dir), static fn($f) => !isset($inDeck[$f])));
}

/** @return list<array<string,mixed>> */
function rotator_remove_from_deck(array $deck, string $file): array
{
    $want = rotator_safe_filename($file);
    if ($want === null) {
        return $deck;
    }
    return array_values(array_filter($deck, static function ($photo) use ($want) {
        if (!is_array($photo)) {
            return false;
        }
        return rotator_safe_filename((string)($photo['file'] ?? '')) !== $want;
    }));
}

/**
 * Parse posted photo deck rows (PHOTOS[] or PHOTOS_JSON) for admin save.
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $existingPhotos
 * @param list<string> $allScreenKeys
 * @return list<array<string,mixed>>
 */
function rotator_parse_post_rows(array $rows, array $existingPhotos, array $allScreenKeys): array
{
    require_once __DIR__ . '/rotation_lib.php';
    require_once __DIR__ . '/users_lib.php';
    $outV = [];
    foreach ($rows as $row) {
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
                $obj['screens'] = admin_operator_screen_locked()
                    ? [admin_operator_screen_key()]
                    : [];
            }
        } elseif ($screens !== $allScreenKeys) {
            $obj['screens'] = $screens;
        }
        if (admin_operator_screen_locked()) {
            $opScreen = (string)admin_operator_screen_key();
            if ($opScreen !== '' && (!isset($obj['screens']) || $obj['screens'] === [])) {
                $obj['screens'] = [$opScreen];
            }
        }
        if (($obj['file'] ?? '') !== '' || ($obj['caption'] ?? '') !== '') {
            $prev = admin_find_owned_list_entry($existingPhotos, $obj);
            $outV[] = admin_finalize_entry($obj, $prev, $row);
        }
    }

    return $outV;
}

function rotator_append_to_deck(string $filename, array $extra = []): bool
{
    require_once __DIR__ . '/users_lib.php';
    $safe = rotator_safe_filename($filename);
    if ($safe === null) {
        return false;
    }

    return cfg_update(function (array $conf) use ($safe, $extra): array {
        $deck = $conf['rotator.PHOTOS'] ?? [];
        if (!is_array($deck)) {
            $deck = [];
        }
        foreach ($deck as $photo) {
            if (is_array($photo) && rotator_safe_filename((string)($photo['file'] ?? '')) === $safe) {
                return $conf;
            }
        }
        $row = admin_stamp_owner(['file' => $safe] + $extra, null);
        if (admin_operator_screen_locked()) {
            $sk = admin_operator_screen_key();
            if ($sk !== null && $sk !== '') {
                $row['screens'] = [$sk];
            }
        }
        $deck[] = $row;
        $conf['rotator.PHOTOS'] = $deck;

        return $conf;
    });
}

/**
 * Delete a photo file, remove from deck, and resync rotation on all displays.
 * @return array{ok:bool,file?:string,error?:string}
 */
function rotator_delete_file(string $file): array
{
    require_once __DIR__ . '/users_lib.php';

    $safe = rotator_safe_filename($file);
    if ($safe === null) {
        return ['ok' => false, 'error' => 'Invalid filename.'];
    }
    $fileFromEntry = static fn($e) => rotator_safe_filename((string)($e['file'] ?? ''));

    if (!cfg_update(function (array $conf) use ($safe, $fileFromEntry): array {
        $deck = $conf['rotator.PHOTOS'] ?? [];
        if (!is_array($deck)) {
            $deck = [];
        }
        $deck = admin_remove_media_from_deck($deck, $safe, 'rotator_safe_filename', $fileFromEntry);
        if ($deck === []) {
            unset($conf['rotator.PHOTOS']);
        } else {
            $conf['rotator.PHOTOS'] = $deck;
        }

        return $conf;
    })) {
        return ['ok' => false, 'error' => 'Could not update settings.json.'];
    }
    cfg_reload();

    $deck = cfg('rotator.PHOTOS', []);
    if (!is_array($deck)) {
        $deck = [];
    }

    $path = rotator_photo_dir() . '/' . $safe;
    if (!admin_media_deck_references_file($deck, $safe, 'rotator_safe_filename', $fileFromEntry) && is_file($path)) {
        @unlink($path);
    }

    require_once __DIR__ . '/rotation_lib.php';
    $syncDeck = admin_media_deploy_deck($deck);
    foreach (admin_media_rotation_sync_screens() as $screen) {
        $sync = rotation_sync_photos($screen, $syncDeck);
        rotation_pages_write($sync['screen'], $sync['pages']);
    }
    cfg_reload();

    return ['ok' => true, 'file' => $safe];
}

/** Import existing disk files into the deck when PHOTOS was never configured. */
function rotator_migrate_deck_from_files(): bool
{
    $files = rotator_list_photos();
    if ($files === []) {
        return false;
    }

    return cfg_update(function (array $conf) use ($files): array|false {
        if (array_key_exists('rotator.PHOTOS', $conf)) {
            return false;
        }
        $deck = [];
        foreach ($files as $file) {
            $deck[] = ['file' => $file];
        }
        $conf['rotator.PHOTOS'] = $deck;

        return $conf;
    });
}

/**
 * Enabled on-disk photos for one display, in deck order.
 * @return list<array<string,mixed>>
 */
function rotator_photos_for_screen(?string $screen, ?array $deck = null, ?string $dir = null): array
{
    $deck = rotator_deck($deck);
    $dir = $dir ?? rotator_photo_dir();
    require_once __DIR__ . '/rotation_lib.php';
    $screenKey = $screen !== null ? rotation_normalize_screen_key($screen) : null;
    $out = [];
    foreach ($deck as $photo) {
        if (!rotator_photo_enabled($photo, $dir)) {
            continue;
        }
        if ($screenKey !== null && $screenKey !== '' && !rotator_on_screen($photo, $screenKey)) {
            continue;
        }
        $photo['file'] = rotator_safe_filename((string)($photo['file'] ?? ''));
        $out[] = $photo;
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function rotator_photos_in_group(string $group, ?string $screen = null, ?array $deck = null): array
{
    $group = rotator_normalize_group($group);
    if ($group === '') {
        return [];
    }
    return array_values(array_filter(
        rotator_photos_for_screen($screen, $deck),
        static fn($photo) => rotator_normalize_group((string)($photo['group'] ?? '')) === $group
    ));
}

/** @param list<array<string,mixed>> $photos */
function rotator_group_dwell(array $photos, ?int $default = null): int
{
    $default = $default ?? rotator_default_dwell();
    $sum = 0;
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        $sum += rotator_photo_dwell($photo, $default);
    }
    return max($default, $sum);
}

/**
 * Expected rotation entries for one display (based on deploy mode).
 * @return list<array{url:string,dwell:int,file?:string,group?:string}>
 */
function rotator_rotation_pages(?array $deck = null, ?string $screen = null): array
{
    $mode = rotator_deploy_mode();
    $default = rotator_default_dwell();
    $enabled = rotator_photos_for_screen($screen, $deck);
    if ($enabled === []) {
        return [];
    }

    if ($mode === 'legacy') {
        return [['url' => 'rotator.php', 'dwell' => rotator_group_dwell($enabled, $default)]];
    }

    if ($mode === 'groups') {
        $byGroup = [];
        $ungrouped = [];
        foreach ($enabled as $photo) {
            $group = rotator_normalize_group((string)($photo['group'] ?? ''));
            if ($group === '') {
                $ungrouped[] = $photo;
            } else {
                $byGroup[$group][] = $photo;
            }
        }
        $out = [];
        foreach ($byGroup as $group => $photos) {
            $out[] = [
                'url' => rotator_group_url($group),
                'dwell' => rotator_group_dwell($photos, $default),
                'group' => $group,
            ];
        }
        foreach ($ungrouped as $photo) {
            $file = (string)$photo['file'];
            $out[] = [
                'url' => rotator_rotation_url($file),
                'dwell' => rotator_photo_dwell($photo, $default),
                'file' => $file,
            ];
        }
        return $out;
    }

    $out = [];
    foreach ($enabled as $photo) {
        $file = (string)$photo['file'];
        $out[] = [
            'url' => rotator_rotation_url($file),
            'dwell' => rotator_photo_dwell($photo, $default),
            'file' => $file,
        ];
    }
    return $out;
}

/**
 * @return array{total:int,enabled:int,on_disk:int,playlist_entries:int}
 */
function rotator_deck_stats(?array $deck = null): array
{
    $deck = rotator_deck($deck);
    $dir = rotator_photo_dir();
    $total = count($deck);
    $enabled = 0;
    $onDisk = 0;
    foreach ($deck as $photo) {
        if (!is_array($photo)) {
            continue;
        }
        if (empty($photo['off'])) {
            $enabled++;
        }
        $file = rotator_safe_filename((string)($photo['file'] ?? ''));
        if ($file !== null && is_file($dir . '/' . $file)) {
            $onDisk++;
        }
    }
    return [
        'total' => $total,
        'enabled' => $enabled,
        'on_disk' => $onDisk,
        'playlist_entries' => count(rotator_rotation_pages($deck, 'main')),
    ];
}
