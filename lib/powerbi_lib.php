<?php
/**
 * Power BI dashboard registry + Azure AD service-principal embed tokens.
 */

const POWERBI_CACHE_DIR = SIGNAGE_ROOT . '/cache';
const POWERBI_AAD_SCOPE = 'https://analysis.windows.net/powerbi/api/.default';
const POWERBI_API_BASE = 'https://api.powerbi.com/v1.0/myorg';

/** @return array<string,array<string,mixed>> */
function powerbi_dashboard_registry(): array
{
    $dash = cfg('powerbi.DASHBOARDS', []);

    return is_array($dash) ? $dash : [];
}

/** @return array<string,array<string,mixed>> */
function powerbi_dashboards_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(powerbi_dashboard_registry());
}

function powerbi_normalize_key(string $key): string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);

    return $key !== '' ? $key : 'main';
}

function powerbi_page_url(string $key): string
{
    return 'powerbi.php?d=' . rawurlencode(powerbi_normalize_key($key));
}

function powerbi_preview_url(string $key): string
{
    return signage_board_preview_url(powerbi_page_url($key));
}

function powerbi_url_is_placeholder(string $url): bool
{
    $url = trim($url);

    return $url === '' || str_contains($url, 'REPLACE');
}

function powerbi_azure_tenant_id(): string
{
    return trim((string)cfg('powerbi.AZURE_TENANT_ID', ''));
}

function powerbi_azure_client_id(): string
{
    return trim((string)cfg('powerbi.AZURE_CLIENT_ID', ''));
}

function powerbi_azure_client_secret(): string
{
    return trim((string)cfg('powerbi.AZURE_CLIENT_SECRET', ''));
}

function powerbi_azure_configured(): bool
{
    return powerbi_azure_tenant_id() !== ''
        && powerbi_azure_client_id() !== ''
        && powerbi_azure_client_secret() !== '';
}

function powerbi_cache_path(string $key): string
{
    if (!is_dir(POWERBI_CACHE_DIR)) {
        @mkdir(POWERBI_CACHE_DIR, 0775, true);
    }

    return POWERBI_CACHE_DIR . '/powerbi_' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
}

/** @return array<string,mixed>|null */
function powerbi_cache_read(string $key): ?array
{
    $file = powerbi_cache_path($key);
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/** @param array<string,mixed> $data */
function powerbi_cache_write(string $key, array $data): void
{
    @file_put_contents(powerbi_cache_path($key), json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function powerbi_cache_clear(string $key): void
{
    $file = powerbi_cache_path($key);
    if (is_file($file)) {
        @unlink($file);
    }
}

/** @var string|null */
$powerbiLastError = null;

function powerbi_last_error(): ?string
{
    return $GLOBALS['powerbiLastError'] ?? null;
}

function powerbi_set_error(?string $msg): void
{
    $GLOBALS['powerbiLastError'] = $msg;
}

/**
 * @return array{ok:bool,code:int,data:?array,error?:string}
 */
function powerbi_http_json(string $method, string $url, ?array $body, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'curl extension not available'];
    }

    $ch = curl_init($url);
    $hdrs = array_merge(['Accept: application/json', 'User-Agent: HomeSignage/PowerBI/1.0'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        $hdrs[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['ok' => false, 'code' => $code, 'data' => null, 'error' => $err !== '' ? $err : 'empty response'];
    }

    $data = json_decode($raw, true);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : []];
    }

    $msg = is_array($data) ? (string)($data['error']['message'] ?? $data['error_description'] ?? '') : '';
    if ($msg === '') {
        $msg = trim($raw);
        if (strlen($msg) > 240) {
            $msg = substr($msg, 0, 240) . '…';
        }
    }

    return ['ok' => false, 'code' => $code, 'data' => is_array($data) ? $data : null, 'error' => $msg !== '' ? $msg : 'HTTP ' . $code];
}

function powerbi_aad_token(): ?string
{
    if (!powerbi_azure_configured()) {
        powerbi_set_error('Azure AD credentials not configured');

        return null;
    }

    $cached = powerbi_cache_read('aad_token');
    if (is_array($cached) && !empty($cached['token']) && (int)($cached['expires_at'] ?? 0) > time()) {
        return (string)$cached['token'];
    }

    $tenant = powerbi_azure_tenant_id();
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    if (!function_exists('curl_init')) {
        powerbi_set_error('curl extension not available');

        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => powerbi_azure_client_id(),
            'client_secret' => powerbi_azure_client_secret(),
            'scope' => POWERBI_AAD_SCOPE,
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_HTTPHEADER => ['User-Agent: HomeSignage/PowerBI/1.0'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $code !== 200) {
        $detail = is_string($body) ? trim($body) : '';
        powerbi_set_error('Azure AD token failed (HTTP ' . $code . ')' . ($detail !== '' ? ': ' . $detail : ''));

        return null;
    }
    $d = json_decode($body, true);
    if (!is_array($d) || empty($d['access_token'])) {
        powerbi_set_error('Azure AD token response missing access_token');

        return null;
    }
    $expires = max(60, (int)($d['expires_in'] ?? 3600) - 120);
    powerbi_cache_write('aad_token', [
        'token' => (string)$d['access_token'],
        'expires_at' => time() + $expires,
    ]);
    powerbi_set_error(null);

    return (string)$d['access_token'];
}

/**
 * @return array{ok:bool,code:int,data:?array,error?:string}
 */
function powerbi_api(string $method, string $path, ?array $body = null): array
{
    $token = powerbi_aad_token();
    if ($token === null) {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => powerbi_last_error() ?? 'No Azure AD token'];
    }

    $path = '/' . ltrim($path, '/');
    $url = POWERBI_API_BASE . $path;

    return powerbi_http_json($method, $url, $body, ['Authorization: Bearer ' . $token]);
}

/**
 * Parse Power BI URLs into workspace/report/dashboard IDs.
 *
 * @return array{type:string,workspace_id:string,report_id:string,dashboard_id:string}|null
 */
function powerbi_parse_embed_url(string $url): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $path = (string)($parts['path'] ?? '');

    $workspaceId = trim((string)($query['groupId'] ?? $query['group_id'] ?? ''));
    $reportId = trim((string)($query['reportId'] ?? $query['report_id'] ?? ''));
    $dashboardId = trim((string)($query['dashboardId'] ?? $query['dashboard_id'] ?? ''));

    if (preg_match('#/groups/([0-9a-f\-]{36})/reports/([0-9a-f\-]{36})#i', $path, $m)) {
        $workspaceId = $workspaceId !== '' ? $workspaceId : $m[1];
        $reportId = $reportId !== '' ? $reportId : $m[2];
    } elseif (preg_match('#/groups/([0-9a-f\-]{36})/dashboards/([0-9a-f\-]{36})#i', $path, $m)) {
        $workspaceId = $workspaceId !== '' ? $workspaceId : $m[1];
        $dashboardId = $dashboardId !== '' ? $dashboardId : $m[2];
    } elseif (preg_match('#/reports/([0-9a-f\-]{36})#i', $path, $m)) {
        $reportId = $reportId !== '' ? $reportId : $m[1];
    }

    if ($reportId !== '') {
        return [
            'type' => 'report',
            'workspace_id' => $workspaceId,
            'report_id' => $reportId,
            'dashboard_id' => '',
        ];
    }
    if ($dashboardId !== '') {
        return [
            'type' => 'dashboard',
            'workspace_id' => $workspaceId,
            'report_id' => '',
            'dashboard_id' => $dashboardId,
        ];
    }

    return null;
}

/**
 * Resolve report/dashboard target from row fields + URL.
 *
 * @param array<string,mixed> $dash
 * @return array{type:string,workspace_id:string,report_id:string,dashboard_id:string}|null
 */
function powerbi_dashboard_resource(array $dash): ?array
{
    $workspaceId = trim((string)($dash['workspace_id'] ?? ''));
    $reportId = trim((string)($dash['report_id'] ?? ''));
    $dashboardId = trim((string)($dash['dashboard_id'] ?? ''));

    $parsed = powerbi_parse_embed_url(trim((string)($dash['url'] ?? '')));
    if ($parsed !== null) {
        if ($workspaceId === '' && $parsed['workspace_id'] !== '') {
            $workspaceId = $parsed['workspace_id'];
        }
        if ($reportId === '' && $parsed['report_id'] !== '') {
            $reportId = $parsed['report_id'];
        }
        if ($dashboardId === '' && $parsed['dashboard_id'] !== '') {
            $dashboardId = $parsed['dashboard_id'];
        }
        if ($reportId === '' && $dashboardId === '') {
            return null;
        }

        return [
            'type' => $reportId !== '' ? 'report' : 'dashboard',
            'workspace_id' => $workspaceId,
            'report_id' => $reportId,
            'dashboard_id' => $dashboardId,
        ];
    }

    if ($reportId !== '') {
        return [
            'type' => 'report',
            'workspace_id' => $workspaceId,
            'report_id' => $reportId,
            'dashboard_id' => '',
        ];
    }
    if ($dashboardId !== '') {
        return [
            'type' => 'dashboard',
            'workspace_id' => $workspaceId,
            'report_id' => '',
            'dashboard_id' => $dashboardId,
        ];
    }

    return null;
}

/**
 * @param array<string,mixed> $dash
 * @return 'auto'|'publish'|'token'
 */
function powerbi_dashboard_mode_setting(array $dash): string
{
    $mode = strtolower(trim((string)($dash['mode'] ?? 'auto')));
    if (in_array($mode, ['publish', 'token'], true)) {
        return $mode;
    }

    return 'auto';
}

/**
 * Effective embed mode for one dashboard row.
 *
 * @param array<string,mixed> $dash
 * @return 'publish'|'token'|'unknown'
 */
function powerbi_dashboard_mode(array $dash): string
{
    $setting = powerbi_dashboard_mode_setting($dash);
    $url = trim((string)($dash['url'] ?? ''));
    $kind = powerbi_embed_kind($url);
    $resource = powerbi_dashboard_resource($dash);

    if ($setting === 'publish') {
        return $kind === 'publish' && !powerbi_url_is_placeholder($url) ? 'publish' : 'unknown';
    }
    if ($setting === 'token') {
        return $resource !== null && powerbi_azure_configured() ? 'token' : 'unknown';
    }

    if ($kind === 'publish' && !powerbi_url_is_placeholder($url)) {
        return 'publish';
    }
    if ($resource !== null && powerbi_azure_configured()) {
        return 'token';
    }

    return 'unknown';
}

/** Whether the dashboard row can render. */
function powerbi_dashboard_is_configured(array $dash): bool
{
    return powerbi_dashboard_mode($dash) !== 'unknown';
}

/**
 * Classify a Power BI link shape.
 *
 * @return 'publish'|'secure'|'unknown'
 */
function powerbi_embed_kind(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return 'unknown';
    }

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    if (!str_contains($host, 'powerbi.com') && !str_contains($host, 'powerbi.microsoft.com')) {
        return 'unknown';
    }

    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if (str_contains($path, '/view') || preg_match('/[?&]r=/', $url)) {
        return 'publish';
    }
    if (str_contains($path, 'reportembed')
        || str_contains($path, 'dashboardembed')
        || str_contains($path, '/reports/')
        || str_contains($path, '/dashboards/')) {
        return 'secure';
    }

    return 'unknown';
}

function powerbi_embed_kind_note(string $kind, string $mode = 'unknown'): string
{
    if ($mode === 'token') {
        return 'Private embed via Azure AD service principal + embed token (kiosk-safe).';
    }

    return match ($kind) {
        'publish' => 'Publish to web — no sign-in; suitable for unattended displays (data is public).',
        'secure' => 'Secure link — configure Azure AD below and set mode to Token (or Auto) for kiosk embed.',
        default => 'Set a publish URL or workspace/report IDs with Azure AD credentials.',
    };
}

function powerbi_iframe_src(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return explode('#', $url, 2)[0];
}

function powerbi_resource_path(array $resource, string $suffix = ''): string
{
    $ws = trim((string)($resource['workspace_id'] ?? ''));
    $prefix = $ws !== '' ? '/groups/' . rawurlencode($ws) : '';

    if (($resource['type'] ?? '') === 'dashboard') {
        $id = trim((string)($resource['dashboard_id'] ?? ''));

        return $prefix . '/dashboards/' . rawurlencode($id) . $suffix;
    }

    $id = trim((string)($resource['report_id'] ?? ''));

    return $prefix . '/reports/' . rawurlencode($id) . $suffix;
}

/**
 * @param array<string,mixed> $dash
 * @return list<array<string,mixed>>
 */
function powerbi_rls_identities(array $dash, ?string $datasetId): array
{
    $username = trim((string)($dash['rls_username'] ?? ''));
    if ($username === '' || $datasetId === null || $datasetId === '') {
        return [];
    }
    $rolesRaw = trim((string)($dash['rls_roles'] ?? ''));
    $roles = [];
    if ($rolesRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $rolesRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $role) {
            $role = trim($role);
            if ($role !== '') {
                $roles[] = $role;
            }
        }
    }

    return [[
        'username' => $username,
        'roles' => $roles,
        'datasets' => [$datasetId],
    ]];
}

/**
 * @param array<string,mixed> $resource
 * @param array<string,mixed> $dash
 * @return array{ok:bool,embedUrl?:string,accessToken?:string,expiration?:string,expiresIn?:int,type?:string,id?:string,error?:string}
 */
function powerbi_generate_embed_token(array $resource, array $dash, string $cacheKey): array
{
    if (!powerbi_azure_configured()) {
        return ['ok' => false, 'error' => 'Azure AD not configured (tenant, client ID, secret)'];
    }

    $cached = powerbi_cache_read('embed_' . $cacheKey);
    if (is_array($cached)
        && !empty($cached['accessToken'])
        && !empty($cached['embedUrl'])
        && (int)($cached['expires_at'] ?? 0) > time() + 60) {
        return [
            'ok' => true,
            'embedUrl' => (string)$cached['embedUrl'],
            'accessToken' => (string)$cached['accessToken'],
            'expiration' => (string)($cached['expiration'] ?? ''),
            'expiresIn' => max(0, (int)($cached['expires_at'] ?? 0) - time()),
            'type' => (string)($cached['type'] ?? $resource['type']),
            'id' => (string)($cached['id'] ?? ''),
        ];
    }

    $metaPath = powerbi_resource_path($resource);
    $meta = powerbi_api('GET', $metaPath);
    if (!$meta['ok'] || !is_array($meta['data'])) {
        powerbi_set_error($meta['error'] ?? 'Could not load Power BI resource metadata');

        return ['ok' => false, 'error' => $meta['error'] ?? 'Could not load Power BI resource metadata'];
    }

    $embedUrl = trim((string)($meta['data']['embedUrl'] ?? ''));
    $datasetId = trim((string)($meta['data']['datasetId'] ?? ''));
    if ($embedUrl === '') {
        return ['ok' => false, 'error' => 'Power BI metadata missing embedUrl'];
    }

    $tokenBody = [
        'accessLevel' => 'View',
        'allowSaveAs' => false,
    ];
    $identities = powerbi_rls_identities($dash, $datasetId !== '' ? $datasetId : null);
    if ($identities !== []) {
        $tokenBody['identities'] = $identities;
    }

    $tokenResp = powerbi_api('POST', $metaPath . '/GenerateToken', $tokenBody);
    if (!$tokenResp['ok'] || !is_array($tokenResp['data'])) {
        powerbi_set_error($tokenResp['error'] ?? 'GenerateToken failed');

        return ['ok' => false, 'error' => $tokenResp['error'] ?? 'GenerateToken failed'];
    }

    $accessToken = trim((string)($tokenResp['data']['token'] ?? ''));
    $expiration = trim((string)($tokenResp['data']['expiration'] ?? ''));
    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'GenerateToken returned empty token'];
    }

    $expiresAt = time() + 3300;
    if ($expiration !== '') {
        $ts = strtotime($expiration);
        if ($ts !== false) {
            $expiresAt = max(time() + 120, $ts - 120);
        }
    }

    $type = (string)($resource['type'] ?? 'report');
    $id = $type === 'dashboard'
        ? trim((string)($resource['dashboard_id'] ?? ''))
        : trim((string)($resource['report_id'] ?? ''));

    powerbi_cache_write('embed_' . $cacheKey, [
        'embedUrl' => $embedUrl,
        'accessToken' => $accessToken,
        'expiration' => $expiration,
        'expires_at' => $expiresAt,
        'type' => $type,
        'id' => $id,
    ]);
    powerbi_set_error(null);

    return [
        'ok' => true,
        'embedUrl' => $embedUrl,
        'accessToken' => $accessToken,
        'expiration' => $expiration,
        'expiresIn' => max(0, $expiresAt - time()),
        'type' => $type,
        'id' => $id,
    ];
}

/**
 * Embed payload for board render + ?api=1 polling.
 *
 * @param array<string,mixed> $dash
 * @return array<string,mixed>
 */
function powerbi_embed_payload(string $registryKey, array $dash): array
{
    $mode = powerbi_dashboard_mode($dash);
    if ($mode === 'publish') {
        return [
            'ok' => true,
            'mode' => 'publish',
            'embedUrl' => powerbi_iframe_src((string)($dash['url'] ?? '')),
        ];
    }
    if ($mode !== 'token') {
        return [
            'ok' => false,
            'mode' => 'unknown',
            'error' => powerbi_azure_configured()
                ? 'Set workspace/report IDs or a reportEmbed URL, and mode Token or Auto.'
                : 'Configure Azure AD tenant, client ID, and secret for private reports.',
        ];
    }

    $resource = powerbi_dashboard_resource($dash);
    if ($resource === null) {
        return ['ok' => false, 'mode' => 'token', 'error' => 'Could not resolve workspace/report IDs'];
    }

    $token = powerbi_generate_embed_token($resource, $dash, powerbi_normalize_key($registryKey));
    if (!$token['ok']) {
        return [
            'ok' => false,
            'mode' => 'token',
            'error' => $token['error'] ?? 'Embed token failed',
        ];
    }

    return [
        'ok' => true,
        'mode' => 'token',
        'type' => $token['type'] ?? $resource['type'],
        'id' => $token['id'] ?? '',
        'embedUrl' => $token['embedUrl'] ?? '',
        'accessToken' => $token['accessToken'] ?? '',
        'expiration' => $token['expiration'] ?? '',
        'expiresIn' => (int)($token['expiresIn'] ?? 0),
    ];
}

/**
 * @return array{ok:bool,error?:string,detail?:string,workspace?:string}
 */
function powerbi_test_azure_connection(): array
{
    powerbi_cache_clear('aad_token');
    $token = powerbi_aad_token();
    if ($token === null) {
        return ['ok' => false, 'error' => powerbi_last_error() ?? 'Azure AD authentication failed'];
    }

    $resp = powerbi_api('GET', '/groups?$top=1');
    if (!$resp['ok']) {
        return [
            'ok' => false,
            'error' => 'Azure AD OK but Power BI API failed',
            'detail' => $resp['error'] ?? ('HTTP ' . (int)$resp['code']),
        ];
    }

    return ['ok' => true, 'detail' => 'Azure AD + Power BI API reachable'];
}

/**
 * @return array{ok:bool,error?:string,detail?:string}
 */
function powerbi_test_dashboard(string $registryKey): array
{
    $registry = powerbi_dashboard_registry();
    $key = powerbi_normalize_key($registryKey);
    $dash = $registry[$key] ?? null;
    if (!is_array($dash)) {
        return ['ok' => false, 'error' => 'Dashboard key not found: ' . $key];
    }

    powerbi_cache_clear('embed_' . $key);
    $payload = powerbi_embed_payload($key, $dash);
    if (empty($payload['ok'])) {
        return ['ok' => false, 'error' => (string)($payload['error'] ?? 'Embed failed')];
    }

    if (($payload['mode'] ?? '') === 'publish') {
        return ['ok' => true, 'detail' => 'Publish-to-web URL configured'];
    }

    return [
        'ok' => true,
        'detail' => 'Embed token OK — ' . (string)($payload['type'] ?? 'report')
            . ', expires in ~' . (int)($payload['expiresIn'] ?? 0) . 's',
    ];
}
