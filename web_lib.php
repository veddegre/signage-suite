<?php
/**
 * Website iframe board — shared helpers for web.php, admin, and rotation.
 */

require_once __DIR__ . '/config.php';

function web_normalize_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function web_allowed_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));

    return in_array($scheme, ['http', 'https'], true);
}

/** @return array<string,array{title?:string,url:string,reload?:int}> */
function web_sites_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }
    $sites = $rawConf['web.SITES'] ?? [];
    if (!is_array($sites) || $sites === []) {
        return [];
    }

    $out = [];
    foreach ($sites as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rawKey = is_string($key) ? $key : (string)$key;
        $rowKey = trim((string)($row['_key'] ?? ''));
        $key = web_normalize_key($rowKey !== '' ? $rowKey : $rawKey);
        if ($key === '') {
            continue;
        }
        $url = trim((string)($row['url'] ?? ''));
        if (!web_allowed_url($url)) {
            continue;
        }
        $entry = ['url' => $url];
        $title = trim((string)($row['title'] ?? ''));
        if ($title !== '') {
            $entry['title'] = $title;
        }
        if (isset($row['reload']) && trim((string)$row['reload']) !== '') {
            $entry['reload'] = max(0, (int)$row['reload']);
        }
        $out[$key] = $entry;
    }

    return $out;
}

/** @return array{key:string,title:string,url:string,reload:int} */
function web_resolve_site(?string $siteKey = null): array
{
    $sites = web_sites_config();
    if ($sites === []) {
        return [
            'key' => 'main',
            'title' => 'Website',
            'url' => '',
            'reload' => web_default_reload(),
        ];
    }

    $key = web_normalize_key($siteKey ?? '');
    if ($key === '' || !isset($sites[$key])) {
        $key = (string)(array_key_first($sites) ?? 'main');
    }
    $site = $sites[$key];
    $title = trim((string)($site['title'] ?? ''));
    if ($title === '') {
        $title = ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    return [
        'key' => $key,
        'title' => $title,
        'url' => (string)$site['url'],
        'reload' => max(0, (int)($site['reload'] ?? web_default_reload())),
    ];
}

function web_default_reload(): int
{
    return max(0, (int)cfg('web.DEFAULT_RELOAD', 0));
}

function web_page_url(string $key): string
{
    return 'web.php?d=' . rawurlencode(web_normalize_key($key));
}

function web_preview_url(?string $key = null): string
{
    $sites = web_sites_config();
    if ($key === null || $key === '') {
        $key = (string)(array_key_first($sites) ?? 'main');
    }

    return signage_board_preview_url(web_page_url($key));
}

function web_site_label(string $key): string
{
    $sites = web_sites_config();
    $key = web_normalize_key($key);
    if (!isset($sites[$key])) {
        return $key;
    }
    $title = trim((string)($sites[$key]['title'] ?? ''));

    return $title !== '' ? $title : $key;
}
