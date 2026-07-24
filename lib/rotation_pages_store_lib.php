<?php
/**
 * Per-display rotation playlists — config/rotation/pages/<screen>.json
 * (avoids rewriting settings.json when operators save different displays).
 */

require_once __DIR__ . '/json_store_lib.php';
require_once dirname(__DIR__) . '/config.php';

function rotation_pages_store_normalize_screen(string $screen): string
{
    $screen = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $screen) ?? '');

    return $screen !== '' ? $screen : 'main';
}

function rotation_pages_store_dir(): string
{
    return SIGNAGE_ROOT . '/config/rotation/pages';
}

function rotation_pages_store_path(string $screen): ?string
{
    $screen = rotation_pages_store_normalize_screen($screen);
    if ($screen === '') {
        return null;
    }

    return rotation_pages_store_dir() . '/' . $screen . '.json';
}

/** @param list<array<string,mixed>> $pages */
function rotation_pages_store_apply_url_fixes(array $pages): array
{
    foreach ($pages as $i => $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if ($url === 'family.php' || str_starts_with($url, 'family.php?')) {
            $pages[$i]['url'] = preg_replace('/^family\.php/', 'calendar.php', $url) ?? 'calendar.php';
        }
    }

    return $pages;
}

/** @return list<array<string,mixed>> */
function rotation_pages_store_read_file(string $screen): array
{
    $path = rotation_pages_store_path($screen);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }
    $pages = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $pages[] = $row;
        }
    }

    return rotation_pages_store_apply_url_fixes($pages);
}

/**
 * @param list<array<string,mixed>> $pages
 */
function rotation_pages_store_write_file(string $screen, array $pages): bool
{
    $path = rotation_pages_store_path($screen);
    if ($path === null) {
        return false;
    }
    $pages = rotation_pages_store_apply_url_fixes($pages);
    if ($pages === []) {
        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    $result = signage_json_file_update($path, static fn(): array => $pages, [
        'default' => [],
        'pretty' => true,
        'ensure_dir' => true,
    ]);

    return (bool)($result['ok'] ?? false);
}

/** Move rotation.PAGES_* keys out of settings.json into per-screen files (once). */
function rotation_pages_store_migrate_all_from_settings(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    cfg('_', null);
    $conf = $GLOBALS['__cfg_cache'] ?? [];
    if (!is_array($conf)) {
        return;
    }

    $toWrite = [];
    foreach ($conf as $key => $val) {
        if (!is_string($key) || !preg_match('/^rotation\.PAGES_(.+)$/', $key, $m)) {
            continue;
        }
        if (!is_array($val)) {
            continue;
        }
        $screen = rotation_pages_store_normalize_screen($m[1]);
        if ($screen === '') {
            continue;
        }
        $path = rotation_pages_store_path($screen);
        if ($path !== null && is_file($path)) {
            continue;
        }
        $toWrite[$screen] = $val;
    }

    if ($toWrite === []) {
        return;
    }

    foreach ($toWrite as $screen => $pages) {
        rotation_pages_store_write_file($screen, $pages);
    }

    cfg_update(static function (array $c) use ($toWrite): array {
        foreach (array_keys($toWrite) as $screen) {
            unset($c['rotation.PAGES_' . $screen]);
        }

        return $c;
    });
    cfg_reload();
}

/** @return list<array<string,mixed>> */
function rotation_pages_store_read(string $screen): array
{
    $screen = rotation_pages_store_normalize_screen($screen);
    $path = rotation_pages_store_path($screen);
    if ($path !== null && is_file($path)) {
        return rotation_pages_store_read_file($screen);
    }
    $key = 'rotation.PAGES_' . $screen;
    cfg('_', null);
    $conf = $GLOBALS['__cfg_cache'] ?? [];
    if (!is_array($conf) || !array_key_exists($key, $conf) || !is_array($conf[$key])) {
        return [];
    }

    return rotation_pages_store_apply_url_fixes($conf[$key]);
}
