<?php
/**
 * Splunk published Dashboard Studio embeds — admin registry helpers.
 */

require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/users_lib.php';

/** @param array<string,mixed> $raw @return array<string,array<string,mixed>> */
function splunkdash_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $k => $page) {
        if (!is_array($page)) {
            continue;
        }
        $key = splunkdash_normalize_key((string)$k);
        $norm = splunkdash_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function splunkdash_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $url = trim((string)($page['url'] ?? ''));
    $reloadRaw = trim((string)($page['reload'] ?? ''));
    $reload = $reloadRaw === '' ? null : max(0, (int)$reloadRaw);

    $out = [];
    if ($url !== '') {
        $out['url'] = $url;
    }
    if ($reload !== null) {
        $out['reload'] = $reload;
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = 'Splunk dashboard';
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    return admin_merge_entry_access_meta($out, $page);
}

/** @param array<string,mixed>|null $rawConf @return array<string,array<string,mixed>> */
function splunkdash_admin_pages(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $pages = splunkdash_normalize_pages_registry(splunkdash_dashboard_registry());
    } else {
        $raw = is_array($rawConf['splunkdash.DASHBOARDS'] ?? null) ? $rawConf['splunkdash.DASHBOARDS'] : [];
        $pages = splunkdash_normalize_pages_registry($raw);
    }

    return admin_registry_editor_pages(
        $pages,
        static function (): array {
            $main = splunkdash_normalize_page(['title' => 'Splunk dashboard', 'url' => ''], 'main');
            return $main !== null ? ['main' => $main] : [];
        }
    );
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function splunkdash_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = splunkdash_normalize_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = splunkdash_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,array<string,mixed>>|null */
function splunkdash_pages_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return null;
    }
    if ($dec === []) {
        return [];
    }

    return splunkdash_normalize_pages_registry($dec);
}
