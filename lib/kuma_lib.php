<?php
/**
 * Uptime Kuma board — shared helpers for kuma.php and admin.
 * Uses the status-page heartbeat API (public slug) and/or authenticated /api/monitors.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const KUMA_STATUS_UP = 1;
const KUMA_STATUS_DOWN = 0;
const KUMA_STATUS_PENDING = 2;
const KUMA_STATUS_MAINTENANCE = 3;

const KUMA_CACHE_DIR = SIGNAGE_ROOT . '/cache';

function kuma_normalize_page_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function kuma_default_title(): string
{
    return (string)cfg('kuma.BOARD_TITLE', 'Uptime Kuma');
}

function kuma_default_sub(): string
{
    return (string)cfg('kuma.BOARD_SUB', 'Monitoring');
}

function kuma_default_page_title(): string
{
    return kuma_default_title();
}

function kuma_default_page_sub(): string
{
    return kuma_default_sub();
}

function kuma_base_url(): string
{
    return rtrim(trim((string)cfg('kuma.KUMA_URL', '')), '/');
}

function kuma_api_key(): string
{
    return trim((string)cfg('kuma.KUMA_API_KEY', ''));
}

function kuma_status_slug(): string
{
    return trim((string)cfg('kuma.KUMA_STATUS_SLUG', ''));
}

/** @return list<string> */
function kuma_tags_from_string(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $tags = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $part = ltrim(trim((string)$part), '#');
        if ($part !== '') {
            $tags[] = strtolower($part);
        }
    }

    return $tags;
}

/** @return list<string> */
function kuma_tag_filter(): array
{
    return kuma_tags_from_string((string)cfg('kuma.KUMA_TAGS', ''));
}

function kuma_verify_tls(): bool
{
    return (bool)cfg('kuma.KUMA_VERIFY_TLS', false);
}

function kuma_cache_ttl(): int
{
    return max(15, (int)cfg('kuma.CACHE_TTL', 30));
}

function kuma_max_monitors(): int
{
    return max(4, min(60, (int)cfg('kuma.MAX_MONITORS', 24)));
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,array<string,mixed>>
 */
function kuma_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $page) {
        $key = kuma_normalize_page_key(is_string($key) ? $key : (string)($page['_key'] ?? ''));
        if ($key === '' || !is_array($page)) {
            continue;
        }
        $norm = kuma_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function kuma_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $statusSlug = trim((string)($page['status_slug'] ?? ''));
    $tags = trim((string)($page['tags'] ?? ''));
    $maxMonitors = (int)($page['max_monitors'] ?? 0);

    $out = [
        'status_slug' => $statusSlug,
        'tags' => $tags,
    ];
    if ($maxMonitors > 0) {
        $out['max_monitors'] = max(4, min(60, $maxMonitors));
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = kuma_default_page_title();
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    } elseif ($key === 'main') {
        $out['sub'] = kuma_default_page_sub();
    }

    require_once __DIR__ . '/users_lib.php';

    return admin_merge_entry_access_meta($out, $page);
}

/**
 * @param array<string,mixed>|null $rawConf
 * @return array<string,array<string,mixed>>
 */
/** Full Kuma page registry (no kiosk display filter). Used for hero strip resolution and admin pickers. */
function kuma_pages_registry(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['kuma.PAGES']) && is_array($rawConf['kuma.PAGES']) && $rawConf['kuma.PAGES'] !== []) {
        $pages = kuma_normalize_pages_registry($rawConf['kuma.PAGES']);
        if ($pages !== []) {
            return $pages;
        }
    }

    $legacySlug = trim((string)($rawConf['kuma.KUMA_STATUS_SLUG'] ?? ''));
    $legacyTags = trim((string)($rawConf['kuma.KUMA_TAGS'] ?? ''));
    if ($legacySlug !== '' || $legacyTags !== '') {
        return [
            'main' => kuma_normalize_page([
                'status_slug' => $legacySlug,
                'tags' => $legacyTags,
                'title' => kuma_default_page_title(),
                'sub' => kuma_default_page_sub(),
            ], 'main') ?? [],
        ];
    }

    return [
        'main' => kuma_normalize_page([
            'title' => kuma_default_page_title(),
            'sub' => kuma_default_page_sub(),
            'status_slug' => '',
            'tags' => '',
        ], 'main') ?? [],
    ];
}

/** Resolve a page key against the full registry (hero strip, admin). */
function kuma_resolve_page_registry(?string $pageKey = null, ?array $rawConf = null): array
{
    $pages = kuma_pages_registry($rawConf);
    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => kuma_normalize_page_key((string)$k);
    $resolved = admin_registry_resolve_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        if (trim((string)($pageKey ?? '')) === '') {
            $first = array_key_first($pages);
            if ($first !== null && isset($pages[$first])) {
                return ['key' => (string)$first] + $pages[$first];
            }
        }

        return [
            'key' => kuma_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'status_slug' => '',
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

function kuma_pages_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['kuma.PAGES']) && is_array($rawConf['kuma.PAGES']) && $rawConf['kuma.PAGES'] !== []) {
        require_once __DIR__ . '/users_lib.php';
        $pagesRaw = admin_filter_registry_for_display($rawConf['kuma.PAGES']);
        if ($pagesRaw === []) {
            return [];
        }
        $pages = kuma_normalize_pages_registry($pagesRaw);
        if ($pages !== []) {
            return $pages;
        }
    }

    $legacySlug = trim((string)($rawConf['kuma.KUMA_STATUS_SLUG'] ?? ''));
    $legacyTags = trim((string)($rawConf['kuma.KUMA_TAGS'] ?? ''));
    if ($legacySlug !== '' || $legacyTags !== '') {
        require_once __DIR__ . '/users_lib.php';
        if (admin_display_filter_active()) {
            return [];
        }

        return [
            'main' => kuma_normalize_page([
                'status_slug' => $legacySlug,
                'tags' => $legacyTags,
                'title' => kuma_default_page_title(),
                'sub' => kuma_default_page_sub(),
            ], 'main') ?? [],
        ];
    }

    require_once __DIR__ . '/users_lib.php';
    if (admin_display_filter_active()) {
        return [];
    }

    return [
        'main' => kuma_normalize_page([
            'title' => kuma_default_page_title(),
            'sub' => kuma_default_page_sub(),
            'status_slug' => '',
            'tags' => '',
        ], 'main') ?? [],
    ];
}

/** @return array<string,array<string,mixed>> */
function kuma_admin_pages(?array $rawConf = null): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_registry_editor_pages(
        kuma_pages_registry($rawConf),
        static function (): array {
            return [
                'main' => kuma_normalize_page([
                    'title' => kuma_default_page_title(),
                    'sub' => kuma_default_page_sub(),
                    'status_slug' => '',
                    'tags' => '',
                ], 'main') ?? [],
            ];
        }
    );
}

/** @return array<string,mixed> */
function kuma_resolve_page(?string $pageKey = null): array
{
    $pages = kuma_pages_config();
    if ($pages === []) {
        return ['key' => 'main', 'title' => 'Not available', 'sub' => '', 'status_slug' => ''];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => kuma_normalize_page_key((string)$k);
    $resolved = admin_resolve_display_registry_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        return [
            'key' => kuma_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'status_slug' => '',
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function kuma_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = kuma_normalize_page_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = kuma_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/**
 * @return array<string,array<string,mixed>>|null
 */
function kuma_pages_from_json_string(string $raw): ?array
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

    if (array_is_list($dec)) {
        $pages = [];
        foreach ($dec as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = kuma_normalize_page_key((string)($row['_key'] ?? $row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $norm = kuma_normalize_page($row, $key);
            if ($norm !== null) {
                $pages[$key] = $norm;
            }
        }

        return $pages;
    }

    return kuma_normalize_pages_registry($dec);
}

function kuma_page_url(string $key): string
{
    return 'kuma.php?d=' . rawurlencode(kuma_normalize_page_key($key));
}

function kuma_api_key_valid(): bool
{
    $key = kuma_api_key();

    return $key !== '' && $key !== 'PUT-KUMA-API-KEY-HERE';
}

/** @param array<string,mixed> $page */
function kuma_page_has_source(array $page): bool
{
    if (trim((string)($page['status_slug'] ?? '')) !== '') {
        return true;
    }

    return kuma_api_key_valid();
}

function kuma_configured(): bool
{
    $url = kuma_base_url();
    if ($url === '' || str_contains($url, 'REPLACE')) {
        return false;
    }
    if (kuma_api_key_valid()) {
        return true;
    }
    if (kuma_status_slug() !== '') {
        return true;
    }
    foreach (kuma_pages_config() as $page) {
        if (trim((string)($page['status_slug'] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function kuma_preview_url(?string $pageKey = null): string
{
    $key = kuma_normalize_page_key($pageKey ?? '');
    if ($key === '') {
        $pages = kuma_pages_config();
        $key = (string)(array_key_first($pages) ?: 'main');
    }

    return signage_board_preview_url(kuma_page_url($key));
}

function kuma_page_label(string $pageKey): string
{
    $pages = kuma_pages_config();
    $key = kuma_normalize_page_key($pageKey);
    $page = $pages[$key] ?? null;
    $title = is_array($page) ? trim((string)($page['title'] ?? '')) : '';

    return $title !== '' ? $title : $key;
}

function kuma_status_label(int $status): string
{
    return match ($status) {
        KUMA_STATUS_UP => 'Up',
        KUMA_STATUS_DOWN => 'Down',
        KUMA_STATUS_PENDING => 'Pending',
        KUMA_STATUS_MAINTENANCE => 'Maintenance',
        default => 'Unknown',
    };
}

function kuma_status_color(int $status): string
{
    return match ($status) {
        KUMA_STATUS_UP => '#59db8f',
        KUMA_STATUS_DOWN => '#e45959',
        KUMA_STATUS_PENDING => '#ffc859',
        KUMA_STATUS_MAINTENANCE => '#7499ff',
        default => '#8aa0c0',
    };
}

/**
 * @param list<string> $headers
 * @return array{body:mixed,code:int,err:string,ms:int}
 */
function kuma_http(string $url, array $headers = [], int $timeout = 12): array
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => $policy['error'] ?? 'blocked URL', 'ms' => 0];
    }

    $ch = curl_init($url);
    $t0 = microtime(true);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => kuma_verify_tls(),
        CURLOPT_SSL_VERIFYHOST => kuma_verify_tls() ? 2 : 0,
        CURLOPT_USERAGENT => 'HomeSignage/KumaBoard/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'body' => $body,
        'code' => $code,
        'err' => $err,
        'ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

/** @return array<string,mixed>|null */
function kuma_json_get(string $path, array $headers = []): ?array
{
    $url = kuma_base_url() . $path;
    $res = kuma_http($url, $headers);
    if ($res['body'] === false || $res['code'] < 200 || $res['code'] >= 300) {
        return null;
    }
    $data = json_decode((string)$res['body'], true);

    return is_array($data) ? $data : null;
}

/** @return list<string> */
function kuma_monitor_tag_names(array $monitor): array
{
    $names = [];
    $tags = $monitor['tags'] ?? [];
    if (!is_array($tags)) {
        return [];
    }
    foreach ($tags as $tag) {
        if (is_string($tag)) {
            $names[] = strtolower(ltrim(trim($tag), '#'));
            continue;
        }
        if (!is_array($tag)) {
            continue;
        }
        $name = trim((string)($tag['name'] ?? $tag['tag_name'] ?? ''));
        if ($name === '' && isset($tag['tag']) && is_array($tag['tag'])) {
            $name = trim((string)($tag['tag']['name'] ?? ''));
        }
        if ($name !== '') {
            $names[] = strtolower(ltrim($name, '#'));
        }
    }

    return $names;
}

function kuma_monitor_passes_tag_filter(array $monitor, array $filterTags): bool
{
    if ($filterTags === []) {
        return true;
    }
    $have = array_flip(kuma_monitor_tag_names($monitor));
    foreach ($filterTags as $t) {
        if (isset($have[$t])) {
            return true;
        }
    }

    return false;
}

/** @param array<string,mixed> $beat */
function kuma_parse_heartbeat(array $beat): array
{
    return [
        'status' => (int)($beat['status'] ?? KUMA_STATUS_DOWN),
        'ping' => isset($beat['ping']) && is_numeric($beat['ping']) ? (int)round((float)$beat['ping']) : null,
        'msg' => trim((string)($beat['msg'] ?? '')),
        'time' => trim((string)($beat['time'] ?? $beat['created_date'] ?? '')),
    ];
}

/** @param array<int|string,mixed> $heartbeatList */
function kuma_latest_heartbeat_for_id(array $heartbeatList, int $id): ?array
{
    $key = (string)$id;
    $beats = $heartbeatList[$key] ?? $heartbeatList[$id] ?? null;
    if (!is_array($beats) || $beats === []) {
        return null;
    }
    $last = $beats[array_key_last($beats)];
    if (!is_array($last)) {
        return null;
    }

    return kuma_parse_heartbeat($last);
}

/** @param array<string,mixed> $monitor @param array<int|string,mixed> $heartbeatList @param array<int|string,mixed> $uptimeList */
function kuma_normalize_monitor_row(array $monitor, array $heartbeatList, array $uptimeList): ?array
{
    $id = (int)($monitor['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $name = trim((string)($monitor['name'] ?? ''));
    if ($name === '') {
        $name = 'Monitor ' . $id;
    }
    $beat = kuma_latest_heartbeat_for_id($heartbeatList, $id);
    $status = $beat['status'] ?? (int)($monitor['status'] ?? KUMA_STATUS_PENDING);
    if (!empty($monitor['maintenance']) || !empty($monitor['isUnderMaintenance'])) {
        $status = KUMA_STATUS_MAINTENANCE;
    }
    $uptime24 = null;
    $upt = $uptimeList[(string)$id] ?? $uptimeList[$id] ?? null;
    if (is_array($upt)) {
        $uptime24 = isset($upt['24']) ? round((float)$upt['24'], 2) : null;
    }
    $target = trim((string)($monitor['url'] ?? $monitor['hostname'] ?? ''));
    if ($target === '' && isset($monitor['friendly_name'])) {
        $target = trim((string)$monitor['friendly_name']);
    }

    return [
        'id' => $id,
        'name' => $name,
        'type' => trim((string)($monitor['type'] ?? '')),
        'target' => $target,
        'status' => $status,
        'status_label' => kuma_status_label($status),
        'status_color' => kuma_status_color($status),
        'ping' => $beat['ping'] ?? null,
        'msg' => $beat['msg'] ?? '',
        'uptime_24h' => $uptime24,
        'tags' => kuma_monitor_tag_names($monitor),
        'active' => !array_key_exists('active', $monitor) || !empty($monitor['active']),
    ];
}

/** @return array{monitors:list<array<string,mixed>>,error:string} */
function kuma_fetch_from_status_slug(string $slug, array $filterTags): array
{
    $slug = rawurlencode(trim($slug));
    if ($slug === '') {
        return ['monitors' => [], 'error' => 'Status page slug is not configured.'];
    }

    $heartbeat = kuma_json_get('/api/status-page/heartbeat/' . $slug);
    if ($heartbeat === null) {
        return ['monitors' => [], 'error' => 'Could not load status page heartbeat — check KUMA_URL and slug.'];
    }

    $page = kuma_json_get('/api/status-page/' . $slug);
    $heartbeatList = is_array($heartbeat['heartbeatList'] ?? null) ? $heartbeat['heartbeatList'] : [];
    $uptimeList = is_array($heartbeat['uptimeList'] ?? null) ? $heartbeat['uptimeList'] : [];

    $monitors = [];
    $groups = is_array($page['publicGroupList'] ?? null) ? $page['publicGroupList'] : [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group['monitorList'] ?? [] as $monitor) {
            if (!is_array($monitor)) {
                continue;
            }
            if (!kuma_monitor_passes_tag_filter($monitor, $filterTags)) {
                continue;
            }
            $row = kuma_normalize_monitor_row($monitor, $heartbeatList, $uptimeList);
            if ($row !== null) {
                $monitors[$row['id']] = $row;
            }
        }
    }

    if ($monitors === [] && is_array($heartbeatList) && $heartbeatList !== []) {
        foreach (array_keys($heartbeatList) as $idKey) {
            $id = (int)$idKey;
            if ($id <= 0) {
                continue;
            }
            $beat = kuma_latest_heartbeat_for_id($heartbeatList, $id);
            if ($beat === null) {
                continue;
            }
            $monitors[$id] = [
                'id' => $id,
                'name' => 'Monitor ' . $id,
                'type' => '',
                'target' => '',
                'status' => $beat['status'],
                'status_label' => kuma_status_label($beat['status']),
                'status_color' => kuma_status_color($beat['status']),
                'ping' => $beat['ping'],
                'msg' => $beat['msg'],
                'uptime_24h' => null,
                'tags' => [],
                'active' => true,
            ];
        }
    }

    return ['monitors' => array_values($monitors), 'error' => ''];
}

/** @return array{monitors:list<array<string,mixed>>,error:string} */
function kuma_fetch_from_api(array $filterTags, string $statusSlug = ''): array
{
    if (!kuma_api_key_valid()) {
        return ['monitors' => [], 'error' => 'API key is not configured.'];
    }

    $key = kuma_api_key();
    $data = kuma_json_get('/api/monitors', ['Authorization: Bearer ' . $key]);
    if ($data === null) {
        return ['monitors' => [], 'error' => 'Could not load monitors — check KUMA_URL and API key (Settings → API Keys).'];
    }

    $list = [];
    if (isset($data['monitorList']) && is_array($data['monitorList'])) {
        $list = $data['monitorList'];
    } elseif (isset($data['monitors']) && is_array($data['monitors'])) {
        $list = $data['monitors'];
    } elseif (array_is_list($data)) {
        $list = $data;
    } elseif (!empty($data['ok']) && isset($data['data']) && is_array($data['data'])) {
        $list = $data['data'];
    }

    $slug = trim($statusSlug);
    $heartbeatList = [];
    $uptimeList = [];
    if ($slug !== '') {
        $heartbeat = kuma_json_get('/api/status-page/heartbeat/' . rawurlencode($slug));
        if (is_array($heartbeat)) {
            $heartbeatList = is_array($heartbeat['heartbeatList'] ?? null) ? $heartbeat['heartbeatList'] : [];
            $uptimeList = is_array($heartbeat['uptimeList'] ?? null) ? $heartbeat['uptimeList'] : [];
        }
    }

    $monitors = [];
    foreach ($list as $monitor) {
        if (!is_array($monitor)) {
            continue;
        }
        if (!kuma_monitor_passes_tag_filter($monitor, $filterTags)) {
            continue;
        }
        if (array_key_exists('active', $monitor) && empty($monitor['active'])) {
            continue;
        }
        $row = kuma_normalize_monitor_row($monitor, $heartbeatList, $uptimeList);
        if ($row === null) {
            continue;
        }
        if ($heartbeatList === [] && isset($monitor['status'])) {
            $row['status'] = (int)$monitor['status'];
            $row['status_label'] = kuma_status_label($row['status']);
            $row['status_color'] = kuma_status_color($row['status']);
        }
        if ($heartbeatList === [] && isset($monitor['ping']) && is_numeric($monitor['ping'])) {
            $row['ping'] = (int)round((float)$monitor['ping']);
        }
        $monitors[$row['id']] = $row;
    }

    return ['monitors' => array_values($monitors), 'error' => ''];
}

/** @param array<string,mixed> $page */
function kuma_fetch_wall_data(array $page): array
{
    $pageKey = kuma_normalize_page_key((string)($page['key'] ?? 'main'));
    $empty = ['ok' => false, 'error' => 'not configured', 'monitors' => [], 'counts' => []];

    if (!kuma_configured()) {
        return $empty;
    }
    if (!kuma_page_has_source($page)) {
        return ['ok' => false, 'error' => 'Set a status page slug or API key for this page.', 'monitors' => [], 'counts' => []];
    }
    if (!empty($page['off'])) {
        return ['ok' => false, 'error' => 'This page is marked Off wall in admin.', 'monitors' => [], 'counts' => []];
    }

    if (!is_dir(KUMA_CACHE_DIR)) {
        @mkdir(KUMA_CACHE_DIR, 0775, true);
    }
    $cacheFile = KUMA_CACHE_DIR . '/kuma_wall_' . $pageKey . '.json';
    $ttl = kuma_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $filterTags = kuma_tags_from_string((string)($page['tags'] ?? ''));
    $slug = trim((string)($page['status_slug'] ?? ''));
    $error = '';
    $monitors = [];

    if (kuma_api_key_valid()) {
        $api = kuma_fetch_from_api($filterTags, $slug);
        $monitors = $api['monitors'];
        $error = $api['error'];
    }

    if ($monitors === [] && $slug !== '') {
        $sp = kuma_fetch_from_status_slug($slug, $filterTags);
        $monitors = $sp['monitors'];
        if ($error === '' || $monitors !== []) {
            $error = $sp['error'];
        }
    } elseif ($monitors === [] && $error === '') {
        $error = 'Set a status page slug or API key for this page.';
    }

    usort($monitors, static function ($a, $b) {
        $sa = (int)($a['status'] ?? KUMA_STATUS_PENDING);
        $sb = (int)($b['status'] ?? KUMA_STATUS_PENDING);
        if ($sa !== $sb) {
            if ($sa === KUMA_STATUS_DOWN) {
                return -1;
            }
            if ($sb === KUMA_STATUS_DOWN) {
                return 1;
            }
            if ($sa === KUMA_STATUS_PENDING) {
                return -1;
            }
            if ($sb === KUMA_STATUS_PENDING) {
                return 1;
            }
        }

        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $max = max(4, min(60, (int)($page['max_monitors'] ?? kuma_max_monitors())));
    if (count($monitors) > $max) {
        $monitors = array_slice($monitors, 0, $max);
    }

    $up = 0;
    $down = 0;
    $pending = 0;
    $maint = 0;
    foreach ($monitors as $m) {
        match ((int)($m['status'] ?? KUMA_STATUS_PENDING)) {
            KUMA_STATUS_UP => $up++,
            KUMA_STATUS_DOWN => $down++,
            KUMA_STATUS_MAINTENANCE => $maint++,
            default => $pending++,
        };
    }

    $out = [
        'ok' => $error === '' || $monitors !== [],
        'error' => $error,
        'monitors' => $monitors,
        'counts' => [
            'total' => count($monitors),
            'up' => $up,
            'down' => $down,
            'pending' => $pending,
            'maintenance' => $maint,
        ],
        'fetched_at' => time(),
    ];

    if ($out['ok']) {
        @file_put_contents($cacheFile, json_encode($out), LOCK_EX);
    } elseif (is_file($cacheFile)) {
        $stale = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($stale)) {
            $stale['error'] = $error;
            $stale['stale'] = true;

            return $stale;
        }
    }

    return $out;
}
