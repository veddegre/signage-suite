<?php
/**
 * TeamDynamix (TDX) ticket board — shared helpers for tdx.php and admin.
 * Uses TDWebApi REST (Bearer JWT) with credentials server-side.
 *
 * @see https://demotemplate.teamdynamix.com/TDWebApi/swagger
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const TDX_OPEN_STATUS_CLASSES = [1, 2, 5];
const TDX_CLOSED_STATUS_CLASS = 3;
const TDX_CANCELLED_STATUS_CLASS = 4;

function tdx_normalize_page_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function tdx_default_page_title(): string
{
    return (string)cfg('tdx.BOARD_TITLE', 'TeamDynamix');
}

function tdx_default_page_sub(): string
{
    return (string)cfg('tdx.BOARD_SUB', 'Open tickets');
}

/** @return list<int> */
function tdx_parse_id_list($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $out = [];
    foreach ($parts as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

function tdx_ids_string($raw): string
{
    $ids = tdx_parse_id_list($raw);

    return $ids !== [] ? implode(', ', $ids) : '';
}

/** @return list<string> */
function tdx_parse_csv_strings($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

function tdx_csv_string($raw): string
{
    $parts = tdx_parse_csv_strings($raw);

    return $parts !== [] ? implode(', ', $parts) : '';
}

/** @return list<string> */
function tdx_parse_uid_list($raw): array
{
    $out = [];
    foreach (tdx_parse_csv_strings($raw) as $part) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $part)) {
            $out[] = strtolower($part);
        }
    }

    return array_values(array_unique($out));
}

function tdx_uids_string($raw): string
{
    $uids = tdx_parse_uid_list($raw);

    return $uids !== [] ? implode(', ', $uids) : '';
}

function tdx_users_string($raw): string
{
    return tdx_csv_string($raw);
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,array<string,mixed>>
 */
function tdx_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $page) {
        $key = tdx_normalize_page_key(is_string($key) ? $key : (string)($page['_key'] ?? ''));
        if ($key === '' || !is_array($page)) {
            continue;
        }
        $norm = tdx_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function tdx_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $appId = max(0, (int)($page['app_id'] ?? 0));
    $maxTickets = max(1, min(50, (int)($page['max_tickets'] ?? 20)));

    $groupRaw = $page['group_ids'] ?? $page['responsible_group_ids'] ?? '';

    $out = [
        'app_id' => $appId,
        'type_ids' => tdx_ids_string($page['type_ids'] ?? ''),
        'status_ids' => tdx_ids_string($page['status_ids'] ?? ''),
        'group_ids' => tdx_ids_string($groupRaw),
        'responsible_users' => tdx_users_string($page['responsible_users'] ?? ''),
        'responsible_uids' => tdx_uids_string($page['responsible_uids'] ?? ''),
        'priority_ids' => tdx_ids_string($page['priority_ids'] ?? ''),
        'max_tickets' => $maxTickets,
    ];
    if (!empty($page['include_closed'])) {
        $out['include_closed'] = true;
    }
    if (!empty($page['include_cancelled'])) {
        $out['include_cancelled'] = true;
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = tdx_default_page_title();
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    } elseif ($key === 'main') {
        $out['sub'] = tdx_default_page_sub();
    }

    require_once __DIR__ . '/users_lib.php';

    return admin_merge_entry_access_meta($out, $page);
}

/** @param array<string,mixed>|null $rawConf @return array<string,array<string,mixed>> */
function tdx_pages_registry(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['tdx.PAGES']) && is_array($rawConf['tdx.PAGES']) && $rawConf['tdx.PAGES'] !== []) {
        $pages = tdx_normalize_pages_registry($rawConf['tdx.PAGES']);
        if ($pages !== []) {
            return $pages;
        }
    }

    return [
        'main' => tdx_normalize_page([
            'title' => tdx_default_page_title(),
            'sub' => tdx_default_page_sub(),
            'app_id' => 0,
        ], 'main') ?? [],
    ];
}

function tdx_resolve_page_registry(?string $pageKey = null, ?array $rawConf = null): array
{
    $pages = tdx_pages_registry($rawConf);
    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => tdx_normalize_page_key((string)$k);
    $resolved = admin_registry_resolve_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        if (trim((string)($pageKey ?? '')) === '') {
            $first = array_key_first($pages);
            if ($first !== null && isset($pages[$first])) {
                return ['key' => (string)$first] + $pages[$first];
            }
        }

        return [
            'key' => tdx_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'app_id' => 0,
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

function tdx_pages_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['tdx.PAGES']) && is_array($rawConf['tdx.PAGES']) && $rawConf['tdx.PAGES'] !== []) {
        require_once __DIR__ . '/users_lib.php';
        $pagesRaw = admin_filter_registry_for_display($rawConf['tdx.PAGES']);
        if ($pagesRaw === []) {
            return [];
        }
        $pages = tdx_normalize_pages_registry($pagesRaw);
        if ($pages !== []) {
            return $pages;
        }
    }

    require_once __DIR__ . '/users_lib.php';
    if (admin_display_filter_active()) {
        return [];
    }

    return [
        'main' => tdx_normalize_page([
            'title' => tdx_default_page_title(),
            'sub' => tdx_default_page_sub(),
            'app_id' => 0,
        ], 'main') ?? [],
    ];
}

/** @return array<string,array<string,mixed>> */
function tdx_admin_pages(?array $rawConf = null): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_registry_editor_pages(
        tdx_pages_registry($rawConf),
        static function (): array {
            return [
                'main' => tdx_normalize_page([
                    'title' => tdx_default_page_title(),
                    'sub' => tdx_default_page_sub(),
                    'app_id' => 0,
                ], 'main') ?? [],
            ];
        }
    );
}

/** @return array<string,mixed> */
function tdx_resolve_page(?string $pageKey = null): array
{
    $pages = tdx_pages_config();
    if ($pages === []) {
        return ['key' => 'main', 'title' => 'Not available', 'sub' => '', 'app_id' => 0];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => tdx_normalize_page_key((string)$k);
    $resolved = admin_resolve_display_registry_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        return [
            'key' => tdx_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'app_id' => 0,
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function tdx_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = tdx_normalize_page_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = tdx_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,array<string,mixed>>|null */
function tdx_pages_from_json_string(string $raw): ?array
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
            $key = tdx_normalize_page_key((string)($row['_key'] ?? $row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $norm = tdx_normalize_page($row, $key);
            if ($norm !== null) {
                $pages[$key] = $norm;
            }
        }

        return $pages;
    }

    return tdx_normalize_pages_registry($dec);
}

function tdx_page_url(string $key): string
{
    return 'tdx.php?d=' . rawurlencode(tdx_normalize_page_key($key));
}

function tdx_base_url(): string
{
    $url = trim((string)cfg('tdx.TDX_BASE_URL', ''));
    $url = rtrim($url, '/');
    if ($url !== '' && !preg_match('#/TDWebApi$#i', $url)) {
        $url .= '/TDWebApi';
    }

    return $url;
}

function tdx_auth_mode(): string
{
    $mode = strtolower(trim((string)cfg('tdx.TDX_AUTH_MODE', 'admin')));

    return $mode === 'user' ? 'user' : 'admin';
}

function tdx_verify_tls(): bool
{
    return (bool)cfg('tdx.TDX_VERIFY_TLS', false);
}

function tdx_cache_ttl(): int
{
    return max(30, (int)cfg('tdx.CACHE_TTL', 60));
}

function tdx_metadata_cache_ttl(): int
{
    return max(300, (int)cfg('tdx.METADATA_CACHE_TTL', 3600));
}

function tdx_configured(): bool
{
    $base = tdx_base_url();
    if ($base === '' || str_contains($base, 'REPLACE')) {
        return false;
    }
    if (tdx_auth_mode() === 'user') {
        $user = trim((string)cfg('tdx.TDX_USERNAME', ''));
        $pass = trim((string)cfg('tdx.TDX_PASSWORD', ''));

        return $user !== '' && $pass !== '';
    }

    $beid = trim((string)cfg('tdx.TDX_BEID', ''));
    $key = trim((string)cfg('tdx.TDX_WEB_SERVICES_KEY', ''));

    return $beid !== '' && $key !== '' && $beid !== 'PUT-YOUR-BEID-HERE';
}

function tdx_preview_url(?string $pageKey = null): string
{
    $key = tdx_normalize_page_key($pageKey ?? '');
    if ($key === '') {
        $pages = tdx_pages_config();
        $key = (string)(array_key_first($pages) ?: 'main');
    }

    return signage_board_preview_url(tdx_page_url($key));
}

function tdx_page_label(string $pageKey): string
{
    $pages = tdx_pages_config();
    $key = tdx_normalize_page_key($pageKey);
    $page = $pages[$key] ?? null;
    $title = is_array($page) ? trim((string)($page['title'] ?? '')) : '';

    return $title !== '' ? $title : $key;
}

function tdx_api_url(string $path): string
{
    $path = '/' . ltrim($path, '/');

    return tdx_base_url() . '/api' . $path;
}

function tdx_token_cache_file(): string
{
    $cacheDir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    return $cacheDir . '/tdx_token_' . md5(tdx_base_url() . '|' . tdx_auth_mode()) . '.json';
}

function tdx_jwt_exp(string $token): ?int
{
    $parts = explode('.', $token);
    if (count($parts) < 2) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
    if (!is_array($payload)) {
        return null;
    }
    $exp = (int)($payload['exp'] ?? 0);

    return $exp > 0 ? $exp : null;
}

function tdx_get_token(?string &$error = null, bool $forceRefresh = false): ?string
{
    $cacheFile = tdx_token_cache_file();
    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['token'] ?? '') !== '') {
            $exp = (int)($cached['exp'] ?? 0);
            if ($exp === 0) {
                $exp = tdx_jwt_exp((string)$cached['token']) ?? 0;
            }
            if ($exp > time() + 120) {
                return (string)$cached['token'];
            }
        }
    }

    if (!tdx_configured()) {
        $error = 'TeamDynamix not configured';

        return null;
    }

    if (tdx_auth_mode() === 'user') {
        $body = [
            'username' => trim((string)cfg('tdx.TDX_USERNAME', '')),
            'password' => trim((string)cfg('tdx.TDX_PASSWORD', '')),
        ];
        $url = tdx_api_url('/auth');
    } else {
        $body = [
            'BEID' => trim((string)cfg('tdx.TDX_BEID', '')),
            'WebServicesKey' => trim((string)cfg('tdx.TDX_WEB_SERVICES_KEY', '')),
        ];
        $url = tdx_api_url('/auth/loginadmin');
    }

    $resp = tdx_http('POST', $url, $body, [], 20);
    if ($resp['code'] !== 200 || !is_string($resp['body']) || trim($resp['body']) === '') {
        $error = $resp['err'] !== '' ? $resp['err'] : ('Auth failed HTTP ' . $resp['code']);
        if (is_string($resp['body']) && trim($resp['body']) !== '') {
            $error .= ' — ' . trim($resp['body']);
        }

        return null;
    }

    $token = trim((string)$resp['body']);
    $exp = tdx_jwt_exp($token) ?? (time() + 86400);
    @file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => $exp], JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $token;
}

/**
 * @param array<string,mixed>|null $body
 * @param list<string> $headers
 * @return array{body:mixed,code:int,err:string,ms:int}
 */
function tdx_http(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 30): array
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => $policy['error'] ?? 'blocked URL', 'ms' => 0];
    }

    $method = strtoupper($method);
    $reqHeaders = array_merge(['Accept: application/json', 'Content-Type: application/json; charset=utf-8'], $headers);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_SSL_VERIFYPEER => tdx_verify_tls(),
        CURLOPT_SSL_VERIFYHOST => tdx_verify_tls() ? 2 : 0,
        CURLOPT_USERAGENT => 'HomeSignage/TDXBoard/1.0',
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '' && str_starts_with(trim($raw), '{')) {
        $decoded = json_decode($raw, true);
    } elseif (is_string($raw) && $raw !== '' && str_starts_with(trim($raw), '[')) {
        $decoded = json_decode($raw, true);
    }

    return [
        'body' => $decoded !== null ? $decoded : $raw,
        'code' => $code,
        'err' => $err,
        'ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

/**
 * @return array{body:mixed,code:int,err:string,ms:int}
 */
function tdx_api(string $method, string $path, ?array $body = null, int $timeout = 30, ?string &$error = null): array
{
    $token = tdx_get_token($error);
    if ($token === null) {
        return ['body' => false, 'code' => 0, 'err' => $error ?? 'auth failed', 'ms' => 0];
    }

    $resp = tdx_http($method, tdx_api_url($path), $body, ['Authorization: Bearer ' . $token], $timeout);
    if ($resp['code'] === 401) {
        $token = tdx_get_token($error, true);
        if ($token === null) {
            return $resp;
        }
        $resp = tdx_http($method, tdx_api_url($path), $body, ['Authorization: Bearer ' . $token], $timeout);
    }
    if ($resp['code'] === 429) {
        $error = 'Rate limited (429) — retry later';
    }

    return $resp;
}

/** @return list<array<string,mixed>> */
function tdx_default_open_status_ids(int $appId, ?string &$error = null): array
{
    $resp = tdx_api('GET', '/' . $appId . '/tickets/statuses', null, 20, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        return [];
    }
    $ids = [];
    foreach ($resp['body'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $class = (int)($row['StatusClass'] ?? $row['statusClass'] ?? 0);
        if (in_array($class, TDX_OPEN_STATUS_CLASSES, true) && (int)($row['ID'] ?? $row['Id'] ?? 0) > 0) {
            $ids[] = (int)($row['ID'] ?? $row['Id']);
        }
    }

    return array_values(array_unique($ids));
}

/** @return list<int> */
function tdx_status_ids_for_page(array $page, ?string &$error = null): array
{
    $explicit = tdx_parse_id_list($page['status_ids'] ?? '');
    if ($explicit !== []) {
        return $explicit;
    }

    $appId = max(0, (int)($page['app_id'] ?? 0));
    if ($appId <= 0) {
        return [];
    }

    $resp = tdx_api('GET', '/' . $appId . '/tickets/statuses', null, 20, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        return tdx_default_open_status_ids($appId, $error);
    }

    $classes = TDX_OPEN_STATUS_CLASSES;
    if (!empty($page['include_closed'])) {
        $classes[] = TDX_CLOSED_STATUS_CLASS;
    }
    if (!empty($page['include_cancelled'])) {
        $classes[] = TDX_CANCELLED_STATUS_CLASS;
    }

    $ids = [];
    foreach ($resp['body'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $class = (int)($row['StatusClass'] ?? $row['statusClass'] ?? 0);
        $id = (int)($row['ID'] ?? $row['Id'] ?? 0);
        if ($id > 0 && in_array($class, $classes, true)) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/** @return list<string> */
function tdx_lookup_person_uid(string $search, ?string &$error = null): array
{
    $search = trim($search);
    if ($search === '') {
        return [];
    }

    $cacheDir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/tdx_person_' . md5(strtolower($search)) . '.json';
    $ttl = tdx_metadata_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['uids']) && is_array($cached['uids'])) {
            return array_values(array_filter(array_map('strval', $cached['uids'])));
        }
    }

    $path = '/people/lookup?searchText=' . rawurlencode($search) . '&maxResults=5';
    $resp = tdx_api('GET', $path, null, 20, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        return [];
    }

    $uids = [];
    foreach ($resp['body'] as $person) {
        if (!is_array($person)) {
            continue;
        }
        $uid = trim((string)($person['UID'] ?? $person['Uid'] ?? ''));
        if ($uid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $uid)) {
            $uids[] = strtolower($uid);
        }
    }
    $uids = array_values(array_unique($uids));
    if ($uids !== []) {
        @file_put_contents($cacheFile, json_encode(['uids' => $uids], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $uids;
}

/** @return list<string> */
function tdx_responsible_uids_for_page(array $page, ?string &$error = null): array
{
    $uids = tdx_parse_uid_list($page['responsible_uids'] ?? '');
    $userSearches = tdx_parse_csv_strings($page['responsible_users'] ?? '');
    foreach ($userSearches as $search) {
        $found = tdx_lookup_person_uid($search, $error);
        if ($found === []) {
            if ($error === null || $error === '') {
                $error = 'Person not found: ' . $search;
            }

            continue;
        }
        $uids[] = $found[0];
    }
    $uids = array_values(array_unique($uids));
    if ($userSearches !== [] && $uids === []) {
        $error = $error ?? ('Could not resolve responsible user(s): ' . implode(', ', $userSearches));
    }

    return $uids;
}

/** @return array<string,mixed> */
function tdx_build_search_body(array $page, ?string &$error = null): array
{
    $max = max(1, min(50, (int)($page['max_tickets'] ?? 20)));
    $body = ['MaxResults' => $max];

    $statusIds = tdx_status_ids_for_page($page, $error);
    if ($statusIds !== []) {
        $body['StatusIDs'] = $statusIds;
    }

    $typeIds = tdx_parse_id_list($page['type_ids'] ?? '');
    if ($typeIds !== []) {
        $body['TypeIDs'] = $typeIds;
    }

    $groupIds = tdx_parse_id_list($page['group_ids'] ?? '');
    if ($groupIds !== []) {
        $body['ResponsibilityGroupIDs'] = array_map('strval', $groupIds);
    }

    $responsibleUids = tdx_responsible_uids_for_page($page, $error);
    if ($responsibleUids !== []) {
        $body['ResponsibilityUids'] = $responsibleUids;
    }

    $priorityIds = tdx_parse_id_list($page['priority_ids'] ?? '');
    if ($priorityIds !== []) {
        $body['PriorityIDs'] = $priorityIds;
    }

    return $body;
}

function tdx_format_age(?string $iso): string
{
    $iso = trim((string)$iso);
    if ($iso === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Exception) {
        return '—';
    }
    $delta = max(0, time() - $dt->getTimestamp());
    if ($delta < 60) {
        return $delta . 's';
    }
    if ($delta < 3600) {
        return (int)floor($delta / 60) . 'm';
    }
    if ($delta < 86400) {
        return (int)floor($delta / 3600) . 'h';
    }

    return (int)floor($delta / 86400) . 'd';
}

function tdx_priority_color(int $priorityId): string
{
    return match ($priorityId) {
        1 => '#97aab3',
        2 => '#7499ff',
        3 => '#ffc859',
        4 => '#e97659',
        5 => '#e45959',
        default => '#8aa0c0',
    };
}

/** @param array<string,mixed> $ticket */
function tdx_ticket_row(array $ticket): array
{
    $id = (int)($ticket['ID'] ?? $ticket['Id'] ?? 0);
    $priorityId = (int)($ticket['PriorityID'] ?? $ticket['PriorityId'] ?? 0);

    return [
        'id' => $id,
        'title' => trim((string)($ticket['Title'] ?? $ticket['title'] ?? 'Ticket')),
        'status' => trim((string)($ticket['StatusName'] ?? $ticket['statusName'] ?? '')),
        'priority' => trim((string)($ticket['PriorityName'] ?? $ticket['priorityName'] ?? '')),
        'priority_id' => $priorityId,
        'type' => trim((string)($ticket['TypeName'] ?? $ticket['typeName'] ?? '')),
        'group' => trim((string)($ticket['ResponsibleGroupName'] ?? $ticket['responsibleGroupName'] ?? '')),
        'responsible' => trim((string)($ticket['ResponsibleFullName'] ?? $ticket['responsibleFullName'] ?? '')),
        'created' => trim((string)($ticket['CreatedDate'] ?? $ticket['createdDate'] ?? '')),
        'modified' => trim((string)($ticket['ModifiedDate'] ?? $ticket['modifiedDate'] ?? '')),
        'overdue' => !empty($ticket['IsOverdue'] ?? $ticket['isOverdue'] ?? false),
        'sla_violation' => !empty($ticket['SlaViolation'] ?? $ticket['slaViolation'] ?? false),
    ];
}

/**
 * @param array<string,mixed> $page
 * @return list<array<string,mixed>>
 */
function tdx_search_tickets(array $page, ?string &$error = null): array
{
    $appId = max(0, (int)($page['app_id'] ?? 0));
    if ($appId <= 0) {
        $error = 'App ID not configured for this page';

        return [];
    }

    $body = tdx_build_search_body($page, $error);
    $wantsResponsible = tdx_parse_csv_strings($page['responsible_users'] ?? '') !== []
        || tdx_parse_uid_list($page['responsible_uids'] ?? '') !== [];
    if ($wantsResponsible && !isset($body['ResponsibilityUids'])) {
        $error = $error ?? 'Responsible user filter could not be applied';

        return [];
    }

    $resp = tdx_api('POST', '/' . $appId . '/tickets/search', $body, 45, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        $error = $error ?: ('Ticket search failed HTTP ' . $resp['code']);
        if (is_string($resp['body']) && trim($resp['body']) !== '') {
            $error .= ' — ' . trim($resp['body']);
        }

        return [];
    }

    $rows = [];
    foreach ($resp['body'] as $ticket) {
        if (!is_array($ticket)) {
            continue;
        }
        $rows[] = tdx_ticket_row($ticket);
    }

    usort($rows, static function (array $a, array $b): int {
        $pa = (int)($a['priority_id'] ?? 0);
        $pb = (int)($b['priority_id'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }

        return strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? ''));
    });

    return $rows;
}

/** @param array<string,mixed> $empty @return array<string,mixed> */
function tdx_stale_wall_data(array $empty, string $cacheFile, ?string $error): array
{
    if (!is_file($cacheFile)) {
        $empty['error'] = $error;

        return $empty;
    }
    $stale = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($stale)) {
        $empty['error'] = $error;

        return $empty;
    }
    $stale['ok'] = true;
    $stale['error'] = $error !== null && $error !== ''
        ? $error . ' — showing cached data'
        : 'TeamDynamix unreachable — showing cached data';

    return $stale;
}

/**
 * @param array<string,mixed> $page
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   app_id:int,
 *   app_label:string,
 *   tickets:list<array<string,mixed>>,
 *   counts:array<string,int>
 * }
 */
function tdx_fetch_wall_data(array $page): array
{
    $empty = [
        'ok' => false,
        'error' => null,
        'app_id' => 0,
        'app_label' => '',
        'tickets' => [],
        'counts' => [],
    ];

    if (!tdx_configured()) {
        $empty['error'] = 'TeamDynamix not configured';

        return $empty;
    }

    $appId = max(0, (int)($page['app_id'] ?? 0));
    if ($appId <= 0) {
        $empty['error'] = 'Set an application ID on this page';

        return $empty;
    }

    $cacheDir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheKey = 'tdx_wall_' . md5(json_encode([
        $appId,
        $page['type_ids'] ?? '',
        $page['status_ids'] ?? '',
        $page['group_ids'] ?? '',
        $page['responsible_users'] ?? '',
        $page['responsible_uids'] ?? '',
        $page['priority_ids'] ?? '',
        $page['max_tickets'] ?? 20,
        !empty($page['include_closed']),
        !empty($page['include_cancelled']),
    ]));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    $ttl = tdx_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $error = null;
    $tickets = tdx_search_tickets($page, $error);
    if ($tickets === [] && $error !== null && $error !== 'App ID not configured for this page') {
        $empty['error'] = $error;
        $empty['app_id'] = $appId;

        return tdx_stale_wall_data($empty, $cacheFile, $error);
    }

    $counts = ['total' => count($tickets), 'overdue' => 0, 'sla' => 0];
    $byPriority = [];
    foreach ($tickets as $row) {
        if (!empty($row['overdue'])) {
            $counts['overdue']++;
        }
        if (!empty($row['sla_violation'])) {
            $counts['sla']++;
        }
        $p = (string)($row['priority'] ?? 'Unknown');
        if ($p === '') {
            $p = 'Unknown';
        }
        $byPriority[$p] = (int)($byPriority[$p] ?? 0) + 1;
    }
    $counts['by_priority'] = $byPriority;

    $meta = tdx_metadata_cached($appId);
    $appLabel = (string)($meta['app_name'] ?? '');
    if ($appLabel === '') {
        $appLabel = 'App ' . $appId;
    }

    $out = [
        'ok' => true,
        'error' => null,
        'app_id' => $appId,
        'app_label' => $appLabel,
        'tickets' => $tickets,
        'counts' => $counts,
    ];
    @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $out;
}

function tdx_metadata_cache_file(): string
{
    $cacheDir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    return $cacheDir . '/tdx_metadata.json';
}

/**
 * @return array{
 *   applications:list<array<string,mixed>>,
 *   types:list<array<string,mixed>>,
 *   statuses:list<array<string,mixed>>,
 *   groups:list<array<string,mixed>>,
 *   priorities:list<array<string,mixed>>,
 *   app_name:?string
 * }
 */
function tdx_fetch_metadata(int $appId = 0, ?string &$error = null, bool $forceRefresh = false): array
{
    $empty = [
        'applications' => [],
        'types' => [],
        'statuses' => [],
        'groups' => [],
        'priorities' => [],
        'app_name' => null,
    ];

    if (!tdx_configured()) {
        $error = 'TeamDynamix not configured';

        return $empty;
    }

    $cacheFile = tdx_metadata_cache_file();
    $ttl = tdx_metadata_cache_ttl();
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            if ($appId <= 0 || (int)($cached['app_id'] ?? 0) === $appId || !isset($cached['app_id'])) {
                return $cached + $empty;
            }
        }
    }

    $appsResp = tdx_api('GET', '/applications', null, 25, $error);
    $applications = [];
    $appName = null;
    if ($appsResp['code'] === 200 && is_array($appsResp['body'])) {
        foreach ($appsResp['body'] as $app) {
            if (!is_array($app)) {
                continue;
            }
            $id = (int)($app['AppID'] ?? $app['ID'] ?? $app['Id'] ?? 0);
            $name = trim((string)($app['Name'] ?? $app['name'] ?? ''));
            if ($id <= 0) {
                continue;
            }
            $applications[] = ['id' => $id, 'name' => $name !== '' ? $name : ('App ' . $id)];
            if ($appId > 0 && $id === $appId) {
                $appName = $name !== '' ? $name : ('App ' . $id);
            }
        }
    }

    $types = [];
    $statuses = [];
    $priorities = [];
    if ($appId > 0) {
        $typeResp = tdx_api('GET', '/' . $appId . '/tickets/types?isActive=true', null, 25, $error);
        if ($typeResp['code'] === 200 && is_array($typeResp['body'])) {
            foreach ($typeResp['body'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['ID'] ?? $row['Id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $types[] = [
                    'id' => $id,
                    'name' => trim((string)($row['Name'] ?? $row['name'] ?? ('Type ' . $id))),
                ];
            }
        }

        $statusResp = tdx_api('GET', '/' . $appId . '/tickets/statuses', null, 25, $error);
        if ($statusResp['code'] === 200 && is_array($statusResp['body'])) {
            foreach ($statusResp['body'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['ID'] ?? $row['Id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $statuses[] = [
                    'id' => $id,
                    'name' => trim((string)($row['Name'] ?? $row['name'] ?? ('Status ' . $id))),
                    'class' => (int)($row['StatusClass'] ?? $row['statusClass'] ?? 0),
                ];
            }
        }

        $prioResp = tdx_api('GET', '/' . $appId . '/tickets/priorities', null, 25, $error);
        if ($prioResp['code'] === 200 && is_array($prioResp['body'])) {
            foreach ($prioResp['body'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['ID'] ?? $row['Id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $priorities[] = [
                    'id' => $id,
                    'name' => trim((string)($row['Name'] ?? $row['name'] ?? ('Priority ' . $id))),
                ];
            }
        }
    }

    $groups = [];
    $groupResp = tdx_api('POST', '/groups/search', ['search' => ['NameLike' => '', 'IsActive' => true]], 45, $error);
    if ($groupResp['code'] === 200 && is_array($groupResp['body'])) {
        foreach ($groupResp['body'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['ID'] ?? $row['Id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $groups[] = [
                'id' => $id,
                'name' => trim((string)($row['Name'] ?? $row['name'] ?? ('Group ' . $id))),
            ];
        }
    }

    $out = [
        'applications' => $applications,
        'types' => $types,
        'statuses' => $statuses,
        'groups' => $groups,
        'priorities' => $priorities,
        'app_id' => $appId,
        'app_name' => $appName,
        'fetched_at' => time(),
    ];
    @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $out;
}

/** @return array<string,mixed> */
function tdx_metadata_cached(int $appId = 0): array
{
    $cacheFile = tdx_metadata_cache_file();
    if (!is_file($cacheFile)) {
        return tdx_fetch_metadata($appId);
    }
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($cached)) {
        return tdx_fetch_metadata($appId);
    }
    $ttl = tdx_metadata_cache_ttl();
    if ((time() - filemtime($cacheFile)) >= $ttl) {
        return tdx_fetch_metadata($appId);
    }

    return $cached;
}

/** @return array{ok:bool,error:?string,detail:?string,ms:?int} */
function tdx_test_connection(?int $appId = null): array
{
    if (!tdx_configured()) {
        return ['ok' => false, 'error' => 'Not configured', 'detail' => 'Set base URL and credentials in Board settings', 'ms' => null];
    }

    $error = null;
    $t0 = hrtime(true);
    $token = tdx_get_token($error, true);
    if ($token === null) {
        return ['ok' => false, 'error' => 'Authentication failed', 'detail' => $error, 'ms' => null];
    }

    $appsResp = tdx_api('GET', '/applications', null, 20, $error);
    $ms = (int)round((hrtime(true) - $t0) / 1_000_000);
    if ($appsResp['code'] !== 200) {
        return [
            'ok' => false,
            'error' => 'API call failed',
            'detail' => $error ?: ('HTTP ' . $appsResp['code']),
            'ms' => $ms,
        ];
    }

    $count = is_array($appsResp['body']) ? count($appsResp['body']) : 0;
    $detail = $count . ' application(s) visible';
    if ($appId !== null && $appId > 0) {
        $searchPage = ['app_id' => $appId, 'max_tickets' => 1];
        $tickets = tdx_search_tickets($searchPage, $error);
        if ($error !== null && $tickets === []) {
            return ['ok' => false, 'error' => 'Ticket search failed', 'detail' => $error, 'ms' => $ms];
        }
        $detail .= ' · sample search returned ' . count($tickets) . ' ticket(s) for app ' . $appId;
    }

    return ['ok' => true, 'error' => null, 'detail' => $detail, 'ms' => $ms];
}
