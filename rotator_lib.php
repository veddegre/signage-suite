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
