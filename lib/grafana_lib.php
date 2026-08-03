<?php
/**
 * Grafana dashboard embed helpers — JWT auth for self-hosted and Grafana Cloud.
 */

/** @return array<string,array<string,mixed>> */
function grafana_dashboard_registry(): array
{
    $dash = cfg('grafana.DASHBOARDS', []);
    if (!is_array($dash)) {
        return [];
    }

    return grafana_normalize_pages_registry($dash);
}

/** @return array<string,array<string,mixed>> */
function grafana_dashboards_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(grafana_dashboard_registry());
}

/** @param array<string,mixed> $raw @return array<string,array<string,mixed>> */
function grafana_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $k => $page) {
        if (!is_array($page)) {
            continue;
        }
        $key = grafana_normalize_key((string)$k);
        $norm = grafana_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function grafana_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $url = trim((string)($page['url'] ?? ''));
    if ($url !== '') {
        $url = grafana_normalize_dashboard_url($url);
    }
    $jwtAuth = strtolower(trim((string)($page['jwt_auth'] ?? 'auto')));
    if (!in_array($jwtAuth, ['auto', 'on', 'off'], true)) {
        $jwtAuth = 'auto';
    }
    $jwtEmail = trim((string)($page['jwt_email'] ?? ''));
    $refresh = trim((string)($page['refresh'] ?? ''));
    $params = grafana_clean_extra_params(trim((string)($page['params'] ?? '')));

    $out = [];
    if ($url !== '') {
        $out['url'] = $url;
    }
    if ($jwtAuth !== 'auto') {
        $out['jwt_auth'] = $jwtAuth;
    }
    if ($jwtEmail !== '') {
        $out['jwt_email'] = $jwtEmail;
    }
    if ($refresh !== '') {
        $out['refresh'] = $refresh;
    }
    if ($params !== '') {
        $out['params'] = $params;
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = 'Grafana';
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    }

    require_once __DIR__ . '/users_lib.php';

    return admin_merge_entry_access_meta($out, $page);
}

/** @param array<string,mixed>|null $rawConf @return array<string,array<string,mixed>> */
function grafana_admin_pages(?array $rawConf = null): array
{
    require_once __DIR__ . '/users_lib.php';
    if ($rawConf === null) {
        $pages = grafana_dashboard_registry();
    } else {
        $raw = is_array($rawConf['grafana.DASHBOARDS'] ?? null) ? $rawConf['grafana.DASHBOARDS'] : [];
        $pages = grafana_normalize_pages_registry($raw);
    }

    return admin_registry_editor_pages(
        $pages,
        static function (): array {
            return [
                'main' => grafana_normalize_page(['title' => 'Grafana', 'url' => ''], 'main') ?? [],
            ];
        }
    );
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function grafana_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = grafana_normalize_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = grafana_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,array<string,mixed>>|null */
function grafana_pages_from_json_string(string $raw): ?array
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

    return grafana_normalize_pages_registry($dec);
}

function grafana_normalize_key(string $key): string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);

    return $key !== '' ? $key : 'main';
}

function grafana_page_url(string $key): string
{
    return 'grafana.php?d=' . rawurlencode(grafana_normalize_key($key));
}

function grafana_preview_url(string $key): string
{
    return signage_board_preview_url(grafana_page_url($key));
}

/**
 * Resolve one dashboard for the wall or admin preview.
 * Logged-in admin preview checks visibility against the full registry (super admin
 * sees ownerless rows; operators see owned/shared entries). Kiosk rotation uses
 * display-scoped registry (screen assignment).
 *
 * @return array<string,mixed>|null
 */
function grafana_resolve_dashboard(?string $pageKey = null): ?array
{
    require_once __DIR__ . '/users_lib.php';
    $registry = grafana_dashboard_registry();
    $normalize = static fn($k) => grafana_normalize_key((string)$k);
    $requested = (string)($pageKey ?? '');

    if (admin_preview_session_ready()) {
        $resolved = admin_registry_resolve_key($registry, $requested, $normalize);
        if ($resolved === null || !isset($registry[$resolved])) {
            if (trim($requested) !== '') {
                return null;
            }
            foreach ($registry as $k => $entry) {
                if (is_array($entry) && admin_entry_visible($entry)) {
                    return ['key' => (string)$k] + $entry;
                }
            }

            return null;
        }
        $entry = $registry[$resolved];
        if (!is_array($entry) || !admin_entry_visible($entry)) {
            return null;
        }

        return ['key' => $resolved] + $entry;
    }

    $pages = admin_filter_registry_for_display($registry);
    $resolved = admin_resolve_display_registry_key($pages, $requested, $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        return null;
    }

    return ['key' => $resolved] + $pages[$resolved];
}

/** @return 'hs256'|'rs256' */
function grafana_jwt_algorithm(): string
{
    $alg = strtolower(trim((string)cfg('grafana.JWT_ALG', 'hs256')));

    return $alg === 'rs256' ? 'rs256' : 'hs256';
}

function grafana_jwt_secret(): string
{
    return trim((string)cfg('grafana.JWT_SECRET', ''));
}

function grafana_jwt_private_key_pem(): string
{
    return trim((string)cfg('grafana.JWT_PRIVATE_KEY', ''));
}

function grafana_jwt_signing_ready(): bool
{
    if (grafana_jwt_algorithm() === 'rs256') {
        if (grafana_jwt_private_key_pem() === '') {
            return false;
        }
        $key = openssl_pkey_get_private(grafana_jwt_private_key_pem());

        return $key !== false;
    }

    return grafana_jwt_secret() !== '';
}

function grafana_jwt_enabled(): bool
{
    return (bool)cfg('grafana.JWT_ENABLED', false) && grafana_jwt_signing_ready();
}

function grafana_jwt_kid(): string
{
    $kid = trim((string)cfg('grafana.JWT_KID', ''));

    return $kid !== '' ? $kid : 'signage';
}

function grafana_jwt_ttl(): int
{
    $ttl = (int)cfg('grafana.JWT_TTL', 3600);

    return max(300, min(86400, $ttl));
}

function grafana_jwt_issuer(): string
{
    return trim((string)cfg('grafana.JWT_ISSUER', ''));
}

/** Login identity Grafana should use (must exist or auto_sign_up must be enabled). */
function grafana_jwt_login_email(array $dash = []): string
{
    $row = trim((string)($dash['jwt_email'] ?? ''));
    if ($row !== '') {
        return $row;
    }

    return trim((string)cfg('grafana.JWT_LOGIN_EMAIL', ''));
}

function grafana_jwt_configured(): bool
{
    return grafana_jwt_enabled() && grafana_jwt_login_email() !== '';
}

/** IT-provided auth_token appended to every dashboard embed (not signed on this server). */
function grafana_static_auth_token(): string
{
    $raw = trim((string)cfg('grafana.AUTH_TOKEN', ''));
    if ($raw === '') {
        return '';
    }
    if (str_starts_with(strtolower($raw), 'auth_token=')) {
        $raw = substr($raw, 11);
    }

    return trim($raw);
}

function grafana_static_auth_configured(): bool
{
    return grafana_static_auth_token() !== '';
}

function grafana_embed_query_keys_to_strip(): array
{
    return ['auth_token', 'kiosk', 'theme', 'refresh'];
}

/** Kiosk query value appended to every embed URL (hides Grafana app chrome on the wall). */
function grafana_kiosk_mode(): string
{
    $mode = trim((string)cfg('grafana.GRAFANA_KIOSK', 'true'));
    if ($mode === '') {
        return 'true';
    }

    return $mode;
}

/**
 * Merge signage embed params onto a dashboard URL (always includes kiosk).
 *
 * @param array<string,mixed> $dash
 */
function grafana_dashboard_embed_url(string $url, array $dash): string
{
    $url = grafana_normalize_dashboard_url(trim($url));
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $params = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $params);
        if (!is_array($params)) {
            $params = [];
        }
    }
    $params = grafana_strip_embed_query_params($params);

    $extra = grafana_clean_extra_params((string)($dash['params'] ?? ''));
    if ($extra !== '') {
        parse_str($extra, $extraParams);
        if (is_array($extraParams)) {
            foreach ($extraParams as $k => $v) {
                if ($k !== '') {
                    $params[$k] = $v;
                }
            }
        }
    }

    $params['kiosk'] = grafana_kiosk_mode();
    $params['theme'] = (string)cfg('grafana.GRAFANA_THEME', 'dark');
    if (!empty($dash['refresh'])) {
        $params['refresh'] = (string)$dash['refresh'];
    }

    $auth = grafana_dashboard_auth($dash);
    if ($auth !== null) {
        $params['auth_token'] = $auth['token'];
    }

    $base = grafana_rebuild_url_with_query($url, []);

    return grafana_rebuild_url_with_query($base, $params);
}

/** @param array<string, scalar|null> $params */
function grafana_strip_embed_query_params(array $params): array
{
    foreach (grafana_embed_query_keys_to_strip() as $key) {
        unset($params[$key]);
    }

    return $params;
}

function grafana_rebuild_url_with_query(string $url, array $params): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= (string)($parts['path'] ?? '');
    if ($params !== []) {
        $rebuilt .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

/** Remove embed params signage re-appends (auth_token, kiosk, theme, refresh). */
function grafana_normalize_dashboard_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['query'])) {
        return $url;
    }

    parse_str((string)$parts['query'], $params);

    return grafana_rebuild_url_with_query($url, grafana_strip_embed_query_params($params));
}

function grafana_clean_extra_params(string $params): string
{
    $params = trim($params);
    if ($params === '') {
        return '';
    }

    parse_str(ltrim($params, '&?'), $parsed);
    if (!is_array($parsed) || $parsed === []) {
        return '';
    }

    $clean = grafana_strip_embed_query_params($parsed);
    if ($clean === []) {
        return '';
    }

    return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
}

/** @deprecated Use grafana_normalize_dashboard_url() */
function grafana_strip_auth_token_from_url(string $url): string
{
    return grafana_normalize_dashboard_url($url);
}

/**
 * Resolve embed auth: global static token wins over per-request JWT signing.
 *
 * @param array<string,mixed> $dash
 * @return array{token:string,mode:'static'|'jwt'}|null
 */
function grafana_dashboard_auth(array $dash): ?array
{
    $static = grafana_static_auth_token();
    if ($static !== '') {
        return ['token' => $static, 'mode' => 'static'];
    }

    if (!grafana_dashboard_uses_jwt($dash)) {
        return null;
    }

    $token = grafana_jwt_create($dash);
    if ($token === null) {
        return null;
    }

    return ['token' => $token, 'mode' => 'jwt'];
}

function grafana_url_is_cloud(string $url): bool
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

    return str_ends_with($host, '.grafana.net');
}

function grafana_url_is_public_dashboard(string $url): bool
{
    $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));

    return str_contains($path, '/public-dashboards/');
}

/**
 * @param array<string,mixed> $dash
 */
function grafana_dashboard_uses_jwt(array $dash): bool
{
    if (!grafana_jwt_configured()) {
        return false;
    }

    $mode = strtolower(trim((string)($dash['jwt_auth'] ?? 'auto')));
    if ($mode === 'off') {
        return false;
    }
    if ($mode === 'on') {
        return true;
    }

    $url = trim((string)($dash['url'] ?? ''));

    return !grafana_url_is_public_dashboard($url);
}

/** Public URL for grafana-jwks.php (RS256 / Grafana Cloud). */
function grafana_jwks_public_url(): string
{
    $configured = trim((string)cfg('grafana.JWKS_PUBLIC_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)$_SERVER['HTTP_HOST'];
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

        return $scheme . '://' . $host . ($dir !== '' && $dir !== '.' ? $dir : '') . '/grafana-jwks.php';
    }

    return 'https://your-signage-host/grafana-jwks.php';
}

function grafana_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function grafana_b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }

    $raw = base64_decode(strtr($data, '-_', '+/'), true);

    return is_string($raw) ? $raw : '';
}

/**
 * JWKS document for Grafana Cloud (RS256 public key only).
 *
 * @return array<string,mixed>|null
 */
function grafana_jwks_document(): ?array
{
    if (grafana_jwt_algorithm() !== 'rs256') {
        return null;
    }

    $pem = grafana_jwt_private_key_pem();
    if ($pem === '') {
        return null;
    }

    $key = openssl_pkey_get_private($pem);
    if ($key === false) {
        return null;
    }

    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
        return null;
    }

    $rsa = $details['rsa'] ?? null;
    if (!is_array($rsa) || !isset($rsa['n'], $rsa['e'])) {
        return null;
    }

    return [
        'keys' => [[
            'kty' => 'RSA',
            'kid' => grafana_jwt_kid(),
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => grafana_b64url_encode((string)$rsa['n']),
            'e' => grafana_b64url_encode((string)$rsa['e']),
        ]],
    ];
}

/**
 * Sign a short-lived JWT for Grafana auth.jwt url_login.
 *
 * @param array<string,mixed> $dash
 */
function grafana_jwt_create(array $dash = []): ?string
{
    if (!grafana_jwt_configured()) {
        return null;
    }

    $email = grafana_jwt_login_email($dash);
    if ($email === '') {
        return null;
    }

    $now = time();
    $payload = [
        'sub' => $email,
        'email' => $email,
        'iat' => $now,
        'exp' => $now + grafana_jwt_ttl(),
    ];
    $iss = grafana_jwt_issuer();
    if ($iss !== '') {
        $payload['iss'] = $iss;
    }

    $alg = grafana_jwt_algorithm() === 'rs256' ? 'RS256' : 'HS256';
    $header = [
        'alg' => $alg,
        'typ' => 'JWT',
        'kid' => grafana_jwt_kid(),
    ];

    $segments = grafana_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.'
        . grafana_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

    if ($alg === 'RS256') {
        $key = openssl_pkey_get_private(grafana_jwt_private_key_pem());
        if ($key === false) {
            return null;
        }
        $sig = '';
        if (!openssl_sign($segments, $sig, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $segments . '.' . grafana_b64url_encode($sig);
    }

    $sig = hash_hmac('sha256', $segments, grafana_jwt_secret(), true);

    return $segments . '.' . grafana_b64url_encode($sig);
}

/**
 * Build kiosk iframe URL for one dashboard row.
 *
 * @param array<string,mixed> $dash
 */
function grafana_dashboard_iframe_src(string $registryKey, array $dash): array
{
    $url = trim((string)($dash['url'] ?? ''));
    if ($url === '' || str_contains($url, 'REPLACE')) {
        return ['ok' => false, 'error' => 'Dashboard URL not configured'];
    }

    if (grafana_dashboard_uses_jwt($dash) && grafana_dashboard_auth($dash) === null) {
        return ['ok' => false, 'error' => 'JWT enabled but signing key or login email missing'];
    }

    $src = grafana_dashboard_embed_url($url, $dash);
    $auth = grafana_dashboard_auth($dash);
    $authMode = 'none';
    if ($auth !== null) {
        $authMode = $auth['mode'];
    }

    return [
        'ok' => true,
        'src' => $src,
        'auth' => $authMode,
        'expiresIn' => $authMode === 'jwt' ? grafana_jwt_ttl() : 0,
        'cloud' => grafana_url_is_cloud($url),
        'public' => grafana_url_is_public_dashboard($url),
    ];
}

/**
 * JSON payload for grafana.php?api=1 — fresh iframe src (new JWT when enabled).
 *
 * @param array<string,mixed> $dash
 * @return array<string,mixed>
 */
function grafana_embed_api_payload(string $registryKey, array $dash): array
{
    $built = grafana_dashboard_iframe_src($registryKey, $dash);
    if (empty($built['ok'])) {
        return [
            'ok' => false,
            'error' => (string)($built['error'] ?? 'Embed URL failed'),
        ];
    }

    return [
        'ok' => true,
        'src' => (string)$built['src'],
        'auth' => (string)($built['auth'] ?? 'none'),
        'expiresIn' => (int)($built['expiresIn'] ?? 0),
    ];
}

/**
 * @return array{ok:bool,error?:string,detail?:string,token_preview?:string}
 */
function grafana_test_jwt(): array
{
    if (!grafana_jwt_enabled()) {
        return ['ok' => false, 'error' => 'JWT auth is not enabled or signing key missing'];
    }
    $email = grafana_jwt_login_email();
    if ($email === '') {
        return ['ok' => false, 'error' => 'JWT login email is not set'];
    }

    $token = grafana_jwt_create();
    if ($token === null) {
        return ['ok' => false, 'error' => 'Could not sign JWT'];
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['ok' => false, 'error' => 'Signed token is malformed'];
    }

    $payload = json_decode(grafana_b64url_decode($parts[1]), true);
    $exp = is_array($payload) ? (int)($payload['exp'] ?? 0) : 0;
    $alg = strtoupper(grafana_jwt_algorithm() === 'rs256' ? 'RS256' : 'HS256');
    $detail = 'JWT signed for ' . $email . ' (' . $alg . ', kid=' . grafana_jwt_kid()
        . ', exp in ' . max(0, $exp - time()) . 's).';
    if ($alg === 'RS256') {
        $jwks = grafana_jwks_document();
        $detail .= $jwks !== null
            ? ' JWKS ready at ' . grafana_jwks_public_url()
            : ' JWKS document could not be built from private key.';
    } else {
        $detail .= ' Use with self-hosted auth.jwt + jwk_set_file.';
    }

    return [
        'ok' => true,
        'detail' => $detail,
        'token_preview' => substr($token, 0, 24) . '…',
    ];
}

/**
 * Optional HTTP probe: request dashboard URL with JWT and detect login redirect.
 *
 * @param array<string,mixed> $dash
 * @return array{ok:bool,error?:string,detail?:string}
 */
function grafana_test_dashboard_embed(string $registryKey, array $dash): array
{
    $built = grafana_dashboard_iframe_src($registryKey, $dash);
    if (empty($built['ok'])) {
        return ['ok' => false, 'error' => (string)($built['error'] ?? 'Bad dashboard row')];
    }

    if (($built['auth'] ?? '') === 'static') {
        return [
            'ok' => true,
            'detail' => 'Dashboard URL built with global embed auth token (not signed on this server).',
        ];
    }

    if (($built['auth'] ?? '') !== 'jwt') {
        $note = 'Dashboard URL built without embed auth';
        if (!empty($built['public'])) {
            $note .= ' (public dashboard URL — expected)';
        }

        return ['ok' => true, 'detail' => $note];
    }

    if (!empty($built['cloud'])) {
        return [
            'ok' => true,
            'detail' => 'JWT URL built for Grafana Cloud. Embed must be enabled by Grafana Labs support '
                . '(frame-ancestors + jwk_set_url → ' . grafana_jwks_public_url() . ').',
        ];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => true, 'detail' => 'JWT URL built; install curl to probe Grafana HTTP response'];
    }

    $src = (string)$built['src'];
    $ch = curl_init($src);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_HTTPHEADER => ['User-Agent: HomeSignage/GrafanaTest/1.0'],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($code >= 200 && $code < 400 && ($location === '' || !str_contains(strtolower($location), 'login'))) {
        return ['ok' => true, 'detail' => 'Grafana HTTP ' . $code . ' — no login redirect detected'];
    }
    if ($location !== '' && str_contains(strtolower($location), 'login')) {
        return [
            'ok' => false,
            'error' => 'Grafana redirected to login',
            'detail' => 'Check auth.jwt, JWK kid/secret, login email, and allow_embedding',
        ];
    }

    return [
        'ok' => $code > 0,
        'detail' => 'Grafana HTTP ' . ($code > 0 ? (string)$code : 'no response')
            . ($location !== '' ? ' → ' . $location : ''),
    ];
}
