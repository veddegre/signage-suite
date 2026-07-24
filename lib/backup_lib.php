<?php
/**
 * Export / snapshot of signage configuration (settings, users, rotation playlists).
 */

const SIGNAGE_CONFIG_BACKUP_VERSION = 1;
const SIGNAGE_CONFIG_BACKUP_STORE_DIR = 'config/backups';
const SIGNAGE_CONFIG_BACKUP_KEEP_DEFAULT = 10;

/** @return list<string> Paths relative to SIGNAGE_ROOT */
function signage_config_backup_collect_files(bool $includeBak = true, bool $includeCookies = false): array
{
    $configDir = SIGNAGE_ROOT . '/config';
    $files = [];

    foreach (['settings.json', 'users.json', 'admin.json'] as $name) {
        $abs = $configDir . '/' . $name;
        if (is_file($abs)) {
            $files[] = 'config/' . $name;
        }
        if ($includeBak && is_file($abs . '.bak')) {
            $files[] = 'config/' . $name . '.bak';
        }
    }

    $pagesDir = $configDir . '/rotation/pages';
    if (is_dir($pagesDir)) {
        foreach (glob($pagesDir . '/*') ?: [] as $abs) {
            if (!is_file($abs)) {
                continue;
            }
            $base = basename($abs);
            if (!preg_match('/^[a-zA-Z0-9_.-]+\.json(\.bak)?$/', $base)) {
                continue;
            }
            $files[] = 'config/rotation/pages/' . $base;
        }
    }

    if ($includeCookies) {
        $cookieDir = $configDir . '/cookies';
        if (is_dir($cookieDir)) {
            foreach (glob($cookieDir . '/*') ?: [] as $abs) {
                if (!is_file($abs)) {
                    continue;
                }
                $base = basename($abs);
                if ($base === '' || $base[0] === '.') {
                    continue;
                }
                $files[] = 'config/cookies/' . $base;
            }
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/** @return array{file_count:int,bytes:int,rotation_playlists:int,has_users:bool,files:list<string>} */
function signage_config_backup_inventory(bool $includeBak = true, bool $includeCookies = false): array
{
    $files = signage_config_backup_collect_files($includeBak, $includeCookies);
    $bytes = 0;
    $playlists = 0;
    foreach ($files as $rel) {
        $abs = SIGNAGE_ROOT . '/' . $rel;
        if (!is_file($abs)) {
            continue;
        }
        $bytes += (int)@filesize($abs);
        if (preg_match('#^config/rotation/pages/[^/]+\.json$#', $rel)) {
            $playlists++;
        }
    }

    return [
        'file_count' => count($files),
        'bytes' => $bytes,
        'rotation_playlists' => $playlists,
        'has_users' => is_file(SIGNAGE_ROOT . '/config/users.json'),
        'files' => $files,
    ];
}

/**
 * @return array{ok:bool,path?:string,file_count?:int,bytes?:int,error?:string}
 */
function signage_config_backup_write_zip(string $destPath, bool $includeBak = true, bool $includeCookies = false): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'PHP zip extension missing — install php-zip.'];
    }

    $files = signage_config_backup_collect_files($includeBak, $includeCookies);
    if ($files === [] && !is_file(SIGNAGE_ROOT . '/config/settings.json')) {
        return ['ok' => false, 'error' => 'No configuration files found to export.'];
    }

    $manifest = [
        'signage_backup' => SIGNAGE_CONFIG_BACKUP_VERSION,
        'created_at' => gmdate('c'),
        'root_hint' => basename(SIGNAGE_ROOT),
        'include_bak' => $includeBak,
        'include_cookies' => $includeCookies,
        'files' => $files,
    ];

    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($manifestJson === false) {
        return ['ok' => false, 'error' => 'Could not build backup manifest.'];
    }

    $destDir = dirname($destPath);
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create backup directory.'];
    }

    $zip = new ZipArchive();
    $openFlags = is_file($destPath) ? ZipArchive::OVERWRITE : ZipArchive::CREATE;
    if ($zip->open($destPath, $openFlags) !== true) {
        return ['ok' => false, 'error' => 'Could not create zip archive.'];
    }

    $zip->addFromString('manifest.json', $manifestJson);
    foreach ($files as $rel) {
        $abs = SIGNAGE_ROOT . '/' . $rel;
        if (is_file($abs)) {
            $zip->addFile($abs, $rel);
        }
    }
    $zip->close();

    if (!is_file($destPath)) {
        return ['ok' => false, 'error' => 'Backup zip was not written.'];
    }

    return [
        'ok' => true,
        'path' => $destPath,
        'file_count' => count($files) + 1,
        'bytes' => (int)@filesize($destPath),
    ];
}

function signage_config_backup_download_filename(): string
{
    return 'signage-config-' . gmdate('Y-m-d-His') . 'Z.zip';
}

/** Stream a zip download and exit. */
function signage_config_backup_send_download(bool $includeBak = true, bool $includeCookies = false): array
{
    $tmp = SIGNAGE_ROOT . '/cache/signage-export-' . bin2hex(random_bytes(8)) . '.zip';
    $result = signage_config_backup_write_zip($tmp, $includeBak, $includeCookies);
    if (!$result['ok']) {
        return $result;
    }

    $name = signage_config_backup_download_filename();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string)($result['bytes'] ?? filesize($tmp)));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function signage_config_backup_store_dir(): string
{
    return SIGNAGE_ROOT . '/' . SIGNAGE_CONFIG_BACKUP_STORE_DIR;
}

/**
 * Write a timestamped zip under config/backups/ and prune old files.
 *
 * @return array{ok:bool,path?:string,relative?:string,file_count?:int,bytes?:int,pruned?:int,error?:string}
 */
function signage_config_backup_store_on_disk(int $keep = SIGNAGE_CONFIG_BACKUP_KEEP_DEFAULT, bool $includeBak = true, bool $includeCookies = false): array
{
    $dir = signage_config_backup_store_dir();
    $name = signage_config_backup_download_filename();
    $dest = $dir . '/' . $name;
    $result = signage_config_backup_write_zip($dest, $includeBak, $includeCookies);
    if (!$result['ok']) {
        return $result;
    }

    $pruned = signage_config_backup_prune_store($keep);

    return [
        'ok' => true,
        'path' => $dest,
        'relative' => SIGNAGE_CONFIG_BACKUP_STORE_DIR . '/' . $name,
        'file_count' => $result['file_count'] ?? 0,
        'bytes' => $result['bytes'] ?? 0,
        'pruned' => $pruned,
    ];
}

/** @return list<array{name:string,relative:string,bytes:int,mtime:int}> */
function signage_config_backup_list_store(int $limit = 20): array
{
    $dir = signage_config_backup_store_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $rows = [];
    foreach (glob($dir . '/signage-config-*.zip') ?: [] as $abs) {
        if (!is_file($abs)) {
            continue;
        }
        $rows[] = [
            'name' => basename($abs),
            'relative' => SIGNAGE_CONFIG_BACKUP_STORE_DIR . '/' . basename($abs),
            'bytes' => (int)@filesize($abs),
            'mtime' => (int)@filemtime($abs),
        ];
    }
    usort($rows, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);

    return array_slice($rows, 0, max(1, $limit));
}

function signage_config_backup_prune_store(int $keep = SIGNAGE_CONFIG_BACKUP_KEEP_DEFAULT): int
{
    $keep = max(1, $keep);
    $all = signage_config_backup_list_store(500);
    $pruned = 0;
    foreach (array_slice($all, $keep) as $row) {
        $abs = SIGNAGE_ROOT . '/' . $row['relative'];
        if (@unlink($abs)) {
            $pruned++;
        }
    }

    return $pruned;
}

function signage_config_backup_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 2) . ' MB';
}
