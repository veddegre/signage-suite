<?php
/**
 * Grafana dashboard embed helpers — JWT auth for self-hosted and Grafana Cloud.
 */

/** @return array<string,array<string,mixed>> */
function grafana_dashboard_registry(): array
{
    $dash = cfg('grafana.DASHBOARDS', []);

    return is_array($dash) ? $dash : [];
}

/** @return array<string,array<string,mixed>> */
function grafana_dashboards_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(grafana_dashboard_registry());
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

    $theme = (string)cfg('grafana.GRAFANA_THEME', 'dark');
    $qs = 'kiosk&theme=' . rawurlencode($theme);
    if (!empty($dash['refresh'])) {
        $qs .= '&refresh=' . rawurlencode((string)$dash['refresh']);
    }
    if (!empty($dash['params'])) {
        $qs .= '&' . ltrim((string)$dash['params'], '&?');
    }

    $authMode = 'none';
    if (grafana_dashboard_uses_jwt($dash)) {
        $token = grafana_jwt_create($dash);
        if ($token === null) {
            return ['ok' => false, 'error' => 'JWT enabled but signing key or login email missing'];
        }
        $qs .= '&auth_token=' . rawurlencode($token);
        $authMode = 'jwt';
    }

    $src = $url . (str_contains($url, '?') ? '&' : '?') . $qs;

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

    if (($built['auth'] ?? '') !== 'jwt') {
        $note = 'Dashboard URL built without JWT';
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
