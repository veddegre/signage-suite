<?php
/**
 * UniFi Network board — shared API helpers for unifi.php and admin.
 * Uses the local Integration v1 API (X-API-KEY) when configured, with optional
 * legacy controller login (username/password) as fallback.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const UNIFI_CACHE_DIR = SIGNAGE_ROOT . '/cache';

function unifi_default_title(): string
{
    return (string)cfg('unifi.BOARD_TITLE', 'UniFi');
}

function unifi_default_sub(): string
{
    return (string)cfg('unifi.BOARD_SUB', 'Network');
}

function unifi_base_url(): string
{
    return rtrim(trim((string)cfg('unifi.UNIFI_URL', '')), '/');
}

function unifi_api_key(): string
{
    return trim((string)cfg('unifi.UNIFI_API_KEY', ''));
}

function unifi_username(): string
{
    return trim((string)cfg('unifi.UNIFI_USERNAME', ''));
}

function unifi_password(): string
{
    return (string)cfg('unifi.UNIFI_PASSWORD', '');
}

function unifi_site_setting(): string
{
    $site = trim((string)cfg('unifi.UNIFI_SITE', 'default'));

    return $site !== '' ? $site : 'default';
}

function unifi_verify_tls(): bool
{
    return (bool)cfg('unifi.UNIFI_VERIFY_TLS', false);
}

function unifi_cache_ttl(): int
{
    return max(20, (int)cfg('unifi.CACHE_TTL', 45));
}

function unifi_max_devices(): int
{
    return max(4, min(48, (int)cfg('unifi.MAX_DEVICES', 24)));
}

function unifi_configured(): bool
{
    $url = unifi_base_url();
    if ($url === '' || str_contains($url, 'REPLACE')) {
        return false;
    }
    $key = unifi_api_key();
    if ($key !== '' && $key !== 'PUT-YOUR-UNIFI-API-KEY-HERE') {
        return true;
    }
    $user = unifi_username();
    $pass = unifi_password();

    return $user !== '' && $pass !== '' && $pass !== 'PUT-PASSWORD-HERE';
}

function unifi_uses_integration_api(): bool
{
    $key = unifi_api_key();

    return $key !== '' && $key !== 'PUT-YOUR-UNIFI-API-KEY-HERE';
}

function unifi_preview_url(): string
{
    return signage_board_preview_url('unifi.php');
}

/**
 * @return array{cookies:string,csrf:string}
 */
function unifi_parse_set_cookies(string $headers): array
{
    $pairs = [];
    $csrf = '';
    if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $headers, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $name = trim((string)$match[1]);
            $value = trim((string)$match[2]);
            if ($name === '' || $value === '') {
                continue;
            }
            $pairs[$name] = $value;
            if ($csrf === '' && preg_match('/csrf/i', $name)) {
                $csrf = $value;
            }
        }
    }
    $cookieParts = [];
    foreach ($pairs as $name => $value) {
        $cookieParts[] = $name . '=' . $value;
    }

    return [
        'cookies' => implode('; ', $cookieParts),
        'csrf' => $csrf,
    ];
}

/**
 * @return array{body:mixed,code:int,err:string,cookies:string,csrf:string}
 */
function unifi_http(
    string $url,
    string $method = 'GET',
    ?array $body = null,
    array $extraHeaders = [],
    string $cookieHeader = ''
): array {
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => $policy['error'] ?? 'blocked URL', 'cookies' => '', 'csrf' => ''];
    }

    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => unifi_verify_tls(),
        CURLOPT_SSL_VERIFYHOST => unifi_verify_tls() ? 2 : 0,
        CURLOPT_USERAGENT => 'HomeSignage/UniFi/1.0',
        CURLOPT_HEADER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
    }
    if ($cookieHeader !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookieHeader);
    }

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    $respHeaders = is_string($raw) ? substr($raw, 0, $headerSize) : '';
    $respBody = is_string($raw) ? substr($raw, $headerSize) : false;
    $parsed = unifi_parse_set_cookies($respHeaders);
    $csrf = $parsed['csrf'];
    if ($csrf === '' && preg_match('/^X-CSRF-Token:\s*(\S+)/mi', $respHeaders, $m)) {
        $csrf = trim((string)$m[1]);
    }

    return [
        'body' => $respBody,
        'code' => $code,
        'err' => (string)$err,
        'cookies' => $parsed['cookies'],
        'csrf' => $csrf,
    ];
}

/** @return array<string,mixed>|null */
function unifi_decode_json(mixed $body): ?array
{
    if (!is_string($body) || $body === '') {
        return null;
    }
    $dec = json_decode($body, true);

    return is_array($dec) ? $dec : null;
}

/** @return list<array<string,mixed>> */
function unifi_extract_rows(?array $payload): array
{
    if ($payload === null) {
        return [];
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        return array_values(array_filter($payload['data'], 'is_array'));
    }
    if (array_is_list($payload)) {
        return array_values(array_filter($payload, 'is_array'));
    }

    return [];
}

function unifi_integration_url(string $path): string
{
    $path = ltrim($path, '/');

    return unifi_base_url() . '/proxy/network/integration/v1/' . $path;
}

function unifi_legacy_url(string $path): string
{
    $path = ltrim($path, '/');

    return unifi_base_url() . '/proxy/network/api/' . $path;
}

/** @return array<string,mixed>|null */
function unifi_integration_get(string $path, ?string &$error = null): ?array
{
    if (!unifi_uses_integration_api()) {
        $error = 'UniFi API key not configured';

        return null;
    }

    $url = unifi_integration_url($path);
    $resp = unifi_http($url, 'GET', null, ['X-API-KEY: ' . unifi_api_key()]);
    if ($resp['err'] !== '') {
        $error = 'curl: ' . $resp['err'];

        return null;
    }
    if ($resp['code'] < 200 || $resp['code'] >= 300) {
        $error = 'HTTP ' . $resp['code'];

        return null;
    }

    return unifi_decode_json($resp['body']);
}

/** @return array{cookie:string,csrf:string}|null */
function unifi_legacy_login(?string &$error = null): ?array
{
    $user = unifi_username();
    $pass = unifi_password();
    if ($user === '' || $pass === '' || $pass === 'PUT-PASSWORD-HERE') {
        $error = 'UniFi username/password not configured';

        return null;
    }

    $candidates = [
        unifi_base_url() . '/api/auth/login',
        unifi_legacy_url('login'),
    ];
    foreach ($candidates as $url) {
        $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
        if (!$policy['ok']) {
            $error = $policy['error'] ?? 'blocked URL';
            continue;
        }
        $resp = unifi_http($url, 'POST', [
            'username' => $user,
            'password' => $pass,
            'remember' => true,
        ]);
        if ($resp['err'] !== '') {
            $error = 'curl: ' . $resp['err'];
            continue;
        }
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $csrf = $resp['csrf'];
            $cookie = $resp['cookies'];
            $dec = unifi_decode_json($resp['body']);
            if ($csrf === '' && is_array($dec)) {
                $csrf = trim((string)($dec['csrf_token'] ?? $dec['csrfToken'] ?? ''));
            }
            if ($cookie === '' && is_array($dec) && !empty($dec['token'])) {
                $cookie = 'TOKEN=' . (string)$dec['token'];
            }
            if ($cookie === '' && is_array($dec) && !empty($dec['data'][0]['token'])) {
                $cookie = 'TOKEN=' . (string)$dec['data'][0]['token'];
            }
            if ($cookie !== '') {
                return ['cookie' => $cookie, 'csrf' => $csrf];
            }
            $error = 'Login succeeded but no session cookie was returned';
            continue;
        }
        $error = 'HTTP ' . $resp['code'];
        if (is_string($resp['body']) && $resp['body'] !== '') {
            $dec = unifi_decode_json($resp['body']);
            if (is_array($dec) && !empty($dec['message'])) {
                $error .= ' — ' . (string)$dec['message'];
            } elseif (is_array($dec) && !empty($dec['error'])) {
                $error .= ' — ' . (string)$dec['error'];
            }
        }
    }

    return null;
}

/** @param array{cookie:string,csrf:string} $session */
function unifi_legacy_get(string $path, array $session, ?string &$error = null): ?array
{
    $site = rawurlencode(unifi_site_setting());
    $url = unifi_legacy_url('s/' . $site . '/' . ltrim($path, '/'));
    $headers = [];
    if (($session['csrf'] ?? '') !== '') {
        $headers[] = 'X-CSRF-Token: ' . $session['csrf'];
    }
    $resp = unifi_http($url, 'GET', null, $headers, $session['cookie'] ?? '');
    if ($resp['err'] !== '') {
        $error = 'curl: ' . $resp['err'];

        return null;
    }
    if ($resp['code'] < 200 || $resp['code'] >= 300) {
        $error = 'HTTP ' . $resp['code'];

        return null;
    }

    return unifi_decode_json($resp['body']);
}

function unifi_resolve_site_id(?string &$error = null): ?string
{
    $want = strtolower(unifi_site_setting());
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $want)) {
        return $want;
    }

    if (unifi_uses_integration_api()) {
        $sites = unifi_integration_get('sites', $error);
        foreach (unifi_extract_rows($sites) as $site) {
            $id = trim((string)($site['id'] ?? ''));
            $name = strtolower(trim((string)($site['name'] ?? '')));
            $internal = strtolower(trim((string)($site['internalReference'] ?? '')));
            if ($id === '') {
                continue;
            }
            if ($want === strtolower($id) || $want === $name || $want === $internal || ($want === 'default' && ($name === 'default' || $internal === 'default'))) {
                return $id;
            }
        }
        if ($sites !== null) {
            $rows = unifi_extract_rows($sites);
            if (count($rows) === 1) {
                return trim((string)($rows[0]['id'] ?? '')) ?: null;
            }
        }
        $error = $error ?: 'UniFi site not found (check UNIFI_SITE)';

        return null;
    }

    return unifi_site_setting();
}

function unifi_device_kind(string $type, array $device = []): string
{
    $type = strtolower($type);
    if (str_contains($type, 'uap') || str_contains($type, 'ap') || !empty($device['features']['accessPoint'])) {
        return 'ap';
    }
    if (str_contains($type, 'usw') || str_contains($type, 'switch') || !empty($device['features']['switching'])) {
        return 'switch';
    }
    if (preg_match('/ugw|udm|uxg|gateway|router/', $type) || !empty($device['features']['gateway'])) {
        return 'gateway';
    }

    return 'device';
}

function unifi_kind_label(string $kind): string
{
    return match ($kind) {
        'ap' => 'AP',
        'switch' => 'Switch',
        'gateway' => 'Gateway',
        default => 'Device',
    };
}

function unifi_state_online(array $device): bool
{
    $state = strtolower(trim((string)($device['state'] ?? $device['status'] ?? '')));
    if ($state === '1' || $state === 'connected' || $state === 'online') {
        return true;
    }
    if (in_array($state, ['0', 'disconnected', 'offline', 'not_adopted', 'pending'], true)) {
        return false;
    }
    if (isset($device['adopted']) && !$device['adopted']) {
        return false;
    }

    return (bool)($device['adopted'] ?? true);
}

function unifi_format_uptime(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    if ($seconds < 3600) {
        return (int)floor($seconds / 60) . 'm';
    }
    if ($seconds < 86400) {
        return (int)floor($seconds / 3600) . 'h';
    }

    return (int)floor($seconds / 86400) . 'd';
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function unifi_normalize_integration_device(array $raw): array
{
    $type = strtolower((string)($raw['model'] ?? $raw['type'] ?? ''));
    $kind = unifi_device_kind($type, $raw);
    $uptime = (int)($raw['uptimeSec'] ?? $raw['uptime'] ?? 0);

    return [
        'name' => trim((string)($raw['name'] ?? $raw['model'] ?? 'Device')),
        'model' => trim((string)($raw['model'] ?? '')),
        'ip' => trim((string)($raw['ipAddress'] ?? $raw['ip'] ?? '')),
        'kind' => $kind,
        'kind_label' => unifi_kind_label($kind),
        'online' => unifi_state_online($raw),
        'clients' => max(0, (int)($raw['clientCount'] ?? $raw['numSta'] ?? 0)),
        'uptime' => $uptime,
        'uptime_label' => unifi_format_uptime($uptime),
        'version' => trim((string)($raw['firmwareVersion'] ?? $raw['version'] ?? '')),
    ];
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function unifi_normalize_legacy_device(array $raw): array
{
    $type = strtolower((string)($raw['type'] ?? $raw['model'] ?? ''));
    $kind = unifi_device_kind($type, $raw);
    $uptime = (int)($raw['uptime'] ?? 0);

    return [
        'name' => trim((string)($raw['name'] ?? $raw['hostname'] ?? $raw['model'] ?? 'Device')),
        'model' => trim((string)($raw['model'] ?? '')),
        'ip' => trim((string)($raw['ip'] ?? '')),
        'kind' => $kind,
        'kind_label' => unifi_kind_label($kind),
        'online' => unifi_state_online($raw),
        'clients' => max(0, (int)($raw['num_sta'] ?? 0)),
        'uptime' => $uptime,
        'uptime_label' => unifi_format_uptime($uptime),
        'version' => trim((string)($raw['version'] ?? '')),
    ];
}

/** @return array{ok:bool,error:?string,site:string,health:array<string,mixed>,devices:list<array<string,mixed>>,clients:array<string,int>,pending:int,api:string} */
function unifi_fetch_live(?string &$error = null): array
{
    $empty = [
        'ok' => false,
        'error' => null,
        'site' => unifi_site_setting(),
        'health' => ['wan' => 'unknown', 'wlan' => 'unknown', 'lan' => 'unknown'],
        'devices' => [],
        'clients' => ['total' => 0, 'wireless' => 0, 'wired' => 0, 'guest' => 0],
        'pending' => 0,
        'api' => '',
    ];

    if (!unifi_configured()) {
        $empty['error'] = 'UniFi not configured';

        return $empty;
    }

    $siteId = unifi_resolve_site_id($error);
    if ($siteId === null || $siteId === '') {
        $empty['error'] = $error ?: 'Could not resolve UniFi site';

        return $empty;
    }

    $health = ['wan' => 'unknown', 'wlan' => 'unknown', 'lan' => 'unknown'];
    $devices = [];
    $clients = ['total' => 0, 'wireless' => 0, 'wired' => 0, 'guest' => 0];
    $pending = 0;
    $siteLabel = unifi_site_setting();
    $api = '';

    if (unifi_uses_integration_api()) {
        $api = 'integration';
        $sites = unifi_integration_get('sites', $error);
        foreach (unifi_extract_rows($sites) as $site) {
            if ((string)($site['id'] ?? '') === $siteId) {
                $siteLabel = trim((string)($site['name'] ?? $siteLabel));
                break;
            }
        }

        $limit = unifi_max_devices();
        $devicePayload = unifi_integration_get('sites/' . rawurlencode($siteId) . '/devices?limit=' . $limit, $error);
        foreach (unifi_extract_rows($devicePayload) as $row) {
            $devices[] = unifi_normalize_integration_device($row);
        }

        $clientPayload = unifi_integration_get('sites/' . rawurlencode($siteId) . '/clients?limit=500', $error);
        foreach (unifi_extract_rows($clientPayload) as $row) {
            $clients['total']++;
            $isGuest = !empty($row['isGuest']) || !empty($row['guest']);
            $isWireless = !empty($row['isWireless']) || !empty($row['wireless']) || (($row['type'] ?? '') === 'WIRELESS');
            if ($isGuest) {
                $clients['guest']++;
            } elseif ($isWireless) {
                $clients['wireless']++;
            } else {
                $clients['wired']++;
            }
        }

        $pendingPayload = unifi_integration_get('pending-devices?limit=50', $error);
        $pending = count(unifi_extract_rows($pendingPayload));

        $offline = count(array_filter($devices, static fn($d) => empty($d['online'])));
        $health['wan'] = $offline > 0 ? 'warning' : 'ok';
        $health['wlan'] = $clients['wireless'] > 0 ? 'ok' : 'unknown';
        $health['lan'] = $clients['wired'] > 0 ? 'ok' : 'unknown';
    } else {
        $api = 'legacy';
        $session = unifi_legacy_login($error);
        if ($session === null) {
            $empty['error'] = $error ?: 'UniFi login failed';

            return $empty;
        }

        $healthPayload = unifi_legacy_get('stat/health', $session, $error);
        foreach (unifi_extract_rows($healthPayload) as $row) {
            $sub = strtolower((string)($row['subsystem'] ?? ''));
            $status = strtolower((string)($row['status'] ?? 'unknown'));
            if (isset($health[$sub])) {
                $health[$sub] = $status;
            }
            if ($sub === 'wlan') {
                $clients['wireless'] = max($clients['wireless'], (int)($row['num_user'] ?? 0));
                $clients['guest'] = max($clients['guest'], (int)($row['num_guest'] ?? 0));
            }
        }

        $devicePayload = unifi_legacy_get('stat/device', $session, $error);
        foreach (unifi_extract_rows($devicePayload) as $row) {
            $devices[] = unifi_normalize_legacy_device($row);
        }

        $staPayload = unifi_legacy_get('stat/sta', $session, $error);
        $clients['total'] = count(unifi_extract_rows($staPayload));
        if ($clients['wireless'] === 0) {
            $clients['wireless'] = $clients['total'];
        }

        $pendingPayload = unifi_legacy_get('stat/device-basic', $session, $error);
        foreach (unifi_extract_rows($pendingPayload) as $row) {
            if (empty($row['adopted'])) {
                $pending++;
            }
        }
    }

    usort($devices, static function (array $a, array $b): int {
        if (($a['online'] ?? false) !== ($b['online'] ?? false)) {
            return ($a['online'] ?? false) ? 1 : -1;
        }
        $rank = ['gateway' => 0, 'switch' => 1, 'ap' => 2, 'device' => 3];

        return ($rank[$a['kind'] ?? 'device'] ?? 9) <=> ($rank[$b['kind'] ?? 'device'] ?? 9)
            ?: strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $devices = array_slice($devices, 0, unifi_max_devices());

    $online = count(array_filter($devices, static fn($d) => !empty($d['online'])));
    $offline = count($devices) - $online;

    return [
        'ok' => true,
        'error' => null,
        'site' => $siteLabel,
        'health' => $health,
        'devices' => $devices,
        'clients' => $clients,
        'pending' => $pending,
        'api' => $api,
        'counts' => [
            'devices' => count($devices),
            'online' => $online,
            'offline' => $offline,
        ],
    ];
}

/** @return array<string,mixed> */
function unifi_fetch_wall_data(): array
{
    if (!is_dir(UNIFI_CACHE_DIR)) {
        @mkdir(UNIFI_CACHE_DIR, 0775, true);
    }
    $cacheFile = UNIFI_CACHE_DIR . '/unifi_wall.json';
    $ttl = unifi_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $error = null;
    $live = unifi_fetch_live($error);
    if (!$live['ok']) {
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $cached['stale'] = true;
                $cached['error'] = $live['error'] ?? $error;

                return $cached;
            }
        }
        $live['error'] = $live['error'] ?? $error;

        return $live;
    }

    @file_put_contents($cacheFile, json_encode($live, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $live;
}

function unifi_health_label(string $status): string
{
    return match (strtolower($status)) {
        'ok' => 'OK',
        'warning', 'warn' => 'Warning',
        'error', 'failed' => 'Issue',
        default => 'Unknown',
    };
}

function unifi_health_class(string $status): string
{
    return match (strtolower($status)) {
        'ok' => 'ok',
        'warning', 'warn' => 'warn',
        'error', 'failed' => 'bad',
        default => 'unknown',
    };
}
