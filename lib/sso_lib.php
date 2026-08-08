<?php
/**
 * OpenID Connect SSO for admin login — works with Microsoft Entra ID and Authentik
 * (and any OIDC provider that supports authorization code + client secret).
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const SSO_DISCOVERY_CACHE_SEC = 3600;

function sso_enabled(): bool
{
    return (bool)cfg('security.SSO_ENABLED', false)
        && sso_client_id() !== ''
        && sso_client_secret() !== ''
        && sso_issuer_url() !== '';
}

function sso_provider(): string
{
    $p = strtolower(trim((string)cfg('security.SSO_PROVIDER', 'generic')));
    return in_array($p, ['entra', 'authentik', 'generic'], true) ? $p : 'generic';
}

function sso_issuer_url(): string
{
    return trim((string)cfg('security.SSO_ISSUER_URL', ''));
}

function sso_client_id(): string
{
    return trim((string)cfg('security.OIDC_CLIENT_ID', ''));
}

function sso_client_secret(): string
{
    return trim((string)cfg('security.OIDC_CLIENT_SECRET', ''));
}

function sso_scopes(): string
{
    $raw = trim((string)cfg('security.OIDC_SCOPES', ''));
    return $raw !== '' ? $raw : 'openid profile email';
}

function sso_username_claim(): string
{
    $raw = trim((string)cfg('security.SSO_USERNAME_CLAIM', ''));
    if ($raw !== '') {
        return $raw;
    }
    return sso_provider() === 'entra' ? 'preferred_username' : 'preferred_username';
}

function sso_allow_local_fallback(): bool
{
    return (bool)cfg('security.SSO_ALLOW_LOCAL_FALLBACK', true);
}

function sso_auto_link_email(): bool
{
    return (bool)cfg('security.SSO_AUTO_LINK_EMAIL', true);
}

function sso_jit_enabled(): bool
{
    return (bool)cfg('security.SSO_JIT_ENABLED', false);
}

/** JIT always provisions operators — never super. */
function sso_jit_default_role(): string
{
    return 'operator';
}

/** @return list<string> Lowercase domains; empty = allow all. */
function sso_jit_allowed_domains(): array
{
    $raw = strtolower(trim((string)cfg('security.SSO_JIT_ALLOWED_DOMAINS', '')));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $part = ltrim(trim($part), '@.');
        if ($part !== '') {
            $out[$part] = true;
        }
    }
    return array_keys($out);
}

/** @return list<string> Required group/role names (any match); empty = no filter. */
function sso_jit_required_groups(): array
{
    $raw = trim((string)cfg('security.SSO_JIT_REQUIRE_GROUPS', ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/** @param array<string,mixed> $claims */
function sso_jit_email_allowed(array $claims): bool
{
    $allowed = sso_jit_allowed_domains();
    if ($allowed === []) {
        return true;
    }
    $email = sso_claim_email($claims);
    if ($email === '') {
        return false;
    }
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
    return $domain !== '' && in_array($domain, $allowed, true);
}

/** @param array<string,mixed> $claims */
function sso_jit_groups_allowed(array $claims): bool
{
    $required = sso_jit_required_groups();
    if ($required === []) {
        return true;
    }
    $have = [];
    foreach (['groups', 'roles'] as $key) {
        $val = $claims[$key] ?? null;
        if (!is_array($val)) {
            continue;
        }
        foreach ($val as $item) {
            $have[strtolower((string)$item)] = true;
        }
    }
    foreach ($required as $group) {
        if (isset($have[strtolower($group)])) {
            return true;
        }
    }
    return false;
}

/**
 * Why JIT provisioning was denied (for error messages).
 * @param array<string,mixed> $claims
 */
function sso_jit_denial_reason(array $claims): ?string
{
    if (!sso_jit_enabled()) {
        return null;
    }
    require_once __DIR__ . '/users_lib.php';
    if (sso_claim_username($claims) === '') {
        return 'SSO token did not include a usable username or email claim.';
    }
    if (!sso_jit_email_allowed($claims)) {
        $domains = implode(', ', sso_jit_allowed_domains());
        return 'Your email domain is not allowed for automatic signup'
            . ($domains !== '' ? ' (allowed: ' . $domains . ').' : '.');
    }
    if (!sso_jit_groups_allowed($claims)) {
        return 'Your account is not in a required SSO group for automatic signup.';
    }
    if (users_find_by_username(sso_claim_username($claims)) !== null) {
        return 'An account with that username already exists — ask a super admin to assign SSO or use a different login.';
    }
    return 'Automatic signup could not complete.';
}

function signage_admin_base_url(): string
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $secure ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin.php'))), '/');
    if ($dir === '' || $dir === '.') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir;
}

function sso_redirect_uri(): string
{
    return signage_admin_base_url() . '/admin.php?sso=callback';
}

function sso_discovery_url(string $issuer): string
{
    $issuer = rtrim(trim($issuer), '/');
    return $issuer . '/.well-known/openid-configuration';
}

/** @return array<string,mixed>|null */
function sso_discovery(): ?array
{
    $issuer = sso_issuer_url();
    if ($issuer === '') {
        return null;
    }

    $cacheKey = md5($issuer);
    $cacheFile = SIGNAGE_ROOT . '/cache/sso_discovery_' . $cacheKey . '.json';
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < SSO_DISCOVERY_CACHE_SEC) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $url = sso_discovery_url($issuer);
    $resp = sso_http_json('GET', $url);
    if (!$resp['ok'] || !is_array($resp['json'])) {
        return null;
    }

    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($cacheFile, json_encode($resp['json'], JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $resp['json'];
}

/**
 * HTTP helper — allows the configured issuer host even on private LAN (Authentik).
 * @return array{ok:bool,http:int,json:?array,body:string,error:?string}
 */
function sso_http_json(string $method, string $url, array $opts = []): array
{
    $method = strtoupper($method);
    $issuer = sso_issuer_url();
    $allowed = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$allowed['ok']) {
        $issuerHost = strtolower((string)(parse_url($issuer, PHP_URL_HOST) ?? ''));
        $urlHost = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($issuerHost === '' || $urlHost !== $issuerHost) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => $allowed['error'] ?? 'blocked URL'];
        }
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => 'curl extension required'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => 'curl init failed'];
    }

    $headers = ['Accept: application/json'];
    if (!empty($opts['headers']) && is_array($opts['headers'])) {
        foreach ($opts['headers'] as $h) {
            $headers[] = (string)$h;
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($method === 'POST') {
        if (!empty($opts['form']) && is_array($opts['form'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } elseif (!empty($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opts['body']);
        }
    }

    $body = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'http' => $http, 'json' => null, 'body' => '', 'error' => $err];
    }

    $json = json_decode($body, true);
    return [
        'ok' => $http >= 200 && $http < 300,
        'http' => $http,
        'json' => is_array($json) ? $json : null,
        'body' => $body,
        'error' => ($http >= 200 && $http < 300) ? null : ('HTTP ' . $http),
    ];
}

function sso_b64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($data, true);
    return is_string($out) ? $out : '';
}

/** @return array<string,mixed>|null */
function sso_jwks(): ?array
{
    $discovery = sso_discovery();
    if ($discovery === null || empty($discovery['jwks_uri'])) {
        return null;
    }
    $resp = sso_http_json('GET', (string)$discovery['jwks_uri']);
    return ($resp['ok'] && is_array($resp['json'])) ? $resp['json'] : null;
}

/** @return array{pem:string,key:OpenSSLAsymmetricKey}|null */
function sso_jwk_to_pem(array $jwk): ?array
{
    $kty = (string)($jwk['kty'] ?? '');
    if ($kty === 'RSA') {
        return sso_jwk_rsa_to_pem($jwk);
    }
    if ($kty === 'EC') {
        return sso_jwk_ec_to_pem($jwk);
    }

    return null;
}

/** @return array{pem:string,key:OpenSSLAsymmetricKey}|null */
function sso_jwk_rsa_to_pem(array $jwk): ?array
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        return null;
    }
    $modulus = sso_b64url_decode((string)$jwk['n']);
    $exponent = sso_b64url_decode((string)$jwk['e']);
    if ($modulus === '' || $exponent === '') {
        return null;
    }

    $rsaPublicKey = sso_encode_integer($modulus) . sso_encode_integer($exponent);
    $rsaPublicKey = "\x30" . sso_encode_length(strlen($rsaPublicKey)) . $rsaPublicKey;

    $rsaOid = hex2bin('300d06092a864886f70d0101010500');
    if ($rsaOid === false) {
        return null;
    }
    $bitString = "\x03" . sso_encode_length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
    $seq = $rsaOid . $bitString;
    $der = "\x30" . sso_encode_length(strlen($seq)) . $seq;

    $pem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return null;
    }
    return ['pem' => $pem, 'key' => $key];
}

/** @return array{pem:string,key:OpenSSLAsymmetricKey}|null */
function sso_jwk_ec_to_pem(array $jwk): ?array
{
    if (($jwk['kty'] ?? '') !== 'EC') {
        return null;
    }
    $curves = [
        'P-256' => '06082a8648ce3d030107',
        'P-384' => '06052b81040022',
        'P-521' => '06052b81040023',
    ];
    $crv = (string)($jwk['crv'] ?? '');
    if (!isset($curves[$crv])) {
        return null;
    }
    $x = sso_b64url_decode((string)($jwk['x'] ?? ''));
    $y = sso_b64url_decode((string)($jwk['y'] ?? ''));
    if ($x === '' || $y === '') {
        return null;
    }
    $point = "\x04" . $x . $y;
    $ecOid = hex2bin('06072a8648ce3d020106');
    $curveOid = hex2bin($curves[$crv]);
    if ($ecOid === false || $curveOid === false) {
        return null;
    }
    $algoSeq = $ecOid . $curveOid;
    $algoSeq = "\x30" . sso_encode_length(strlen($algoSeq)) . $algoSeq;
    $bitString = "\x03" . sso_encode_length(strlen($point) + 1) . "\x00" . $point;
    $seq = $algoSeq . $bitString;
    $der = "\x30" . sso_encode_length(strlen($seq)) . $seq;
    $pem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    if ($key === false) {
        return null;
    }

    return ['pem' => $pem, 'key' => $key];
}

function sso_jwt_openssl_algo(string $alg): ?int
{
    return match (strtoupper(trim($alg))) {
        'RS256', 'ES256' => OPENSSL_ALGO_SHA256,
        'RS384', 'ES384' => OPENSSL_ALGO_SHA384,
        'RS512', 'ES512' => OPENSSL_ALGO_SHA512,
        default => null,
    };
}

function sso_jwt_verify_hs256(string $signed, string $signature): bool
{
    $secret = sso_client_secret();
    if ($secret === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $signed, $secret, true);

    return hash_equals($expected, $signature);
}

/** @param list<array<string,mixed>> $keys */
function sso_jwt_verify_asymmetric(string $signed, string $signature, string $alg, string $kid, array $keys): bool
{
    $opensslAlgo = sso_jwt_openssl_algo($alg);
    if ($opensslAlgo === null) {
        return false;
    }

    $candidates = [];
    foreach ($keys as $jwk) {
        if (!is_array($jwk)) {
            continue;
        }
        if ($kid !== '' && (string)($jwk['kid'] ?? '') !== $kid) {
            continue;
        }
        $candidates[] = $jwk;
    }
    if ($candidates === [] && $kid !== '') {
        $candidates = array_values(array_filter($keys, 'is_array'));
    }

    foreach ($candidates as $jwk) {
        $pub = sso_jwk_to_pem($jwk);
        if ($pub === null) {
            continue;
        }
        if (openssl_verify($signed, $signature, $pub['key'], $opensslAlgo) === 1) {
            return true;
        }
    }

    return false;
}

function sso_encode_length(int $length): string
{
    if ($length <= 0x7F) {
        return chr($length);
    }
    $temp = ltrim(pack('N', $length), "\x00");
    return chr(0x80 | strlen($temp)) . $temp;
}

function sso_encode_integer(string $bytes): string
{
    if ($bytes === '') {
        return "\x02\x00";
    }
    if (ord($bytes[0]) > 0x7F) {
        $bytes = "\x00" . $bytes;
    }
    return "\x02" . sso_encode_length(strlen($bytes)) . $bytes;
}

/**
 * Verify OIDC id_token and return claims.
 * @return array{ok:bool,claims:?array,error:?string}
 */
function sso_verify_id_token(string $jwt, string $nonce): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return ['ok' => false, 'claims' => null, 'error' => 'Malformed ID token'];
    }

    $header = json_decode(sso_b64url_decode($parts[0]), true);
    $payload = json_decode(sso_b64url_decode($parts[1]), true);
    if (!is_array($header) || !is_array($payload)) {
        return ['ok' => false, 'claims' => null, 'error' => 'Invalid ID token encoding'];
    }

    $kid = (string)($header['kid'] ?? '');
    $alg = strtoupper(trim((string)($header['alg'] ?? '')));
    if ($alg === '') {
        return ['ok' => false, 'claims' => null, 'error' => 'Missing JWT algorithm in ID token header'];
    }

    $signed = $parts[0] . '.' . $parts[1];
    $sig = sso_b64url_decode($parts[2]);
    if ($sig === '') {
        return ['ok' => false, 'claims' => null, 'error' => 'Invalid ID token signature encoding'];
    }

    $signatureOk = false;
    if ($alg === 'HS256') {
        $signatureOk = sso_jwt_verify_hs256($signed, $sig);
    } else {
        $jwks = sso_jwks();
        if ($jwks === null || !is_array($jwks['keys'] ?? null)) {
            return ['ok' => false, 'claims' => null, 'error' => 'Could not load JWKS for ' . $alg . ' ID token'];
        }
        if (sso_jwt_openssl_algo($alg) === null) {
            return ['ok' => false, 'claims' => null, 'error' => 'Unsupported JWT algorithm: ' . $alg];
        }
        $signatureOk = sso_jwt_verify_asymmetric($signed, $sig, $alg, $kid, $jwks['keys']);
    }

    if (!$signatureOk) {
        $hint = $alg === 'HS256'
            ? ' (HS256 — confirm OIDC client secret matches Authentik exactly)'
            : '';
        return ['ok' => false, 'claims' => null, 'error' => 'Invalid ID token signature' . $hint];
    }

    $now = time();
    if (isset($payload['exp']) && (int)$payload['exp'] < ($now - 60)) {
        return ['ok' => false, 'claims' => null, 'error' => 'ID token expired'];
    }
    if (isset($payload['nbf']) && (int)$payload['nbf'] > ($now + 60)) {
        return ['ok' => false, 'claims' => null, 'error' => 'ID token not yet valid'];
    }

    $issuer = rtrim(sso_issuer_url(), '/');
    $tokenIss = rtrim((string)($payload['iss'] ?? ''), '/');
    if ($tokenIss === '' || !hash_equals($issuer, $tokenIss)) {
        return ['ok' => false, 'claims' => null, 'error' => 'ID token issuer mismatch'];
    }

    $aud = $payload['aud'] ?? '';
    $clientId = sso_client_id();
    $audOk = false;
    if (is_string($aud)) {
        $audOk = hash_equals($clientId, $aud);
    } elseif (is_array($aud)) {
        foreach ($aud as $a) {
            if (hash_equals($clientId, (string)$a)) {
                $audOk = true;
                break;
            }
        }
    }
    if (!$audOk) {
        return ['ok' => false, 'claims' => null, 'error' => 'ID token audience mismatch'];
    }

    if ($nonce !== '' && !hash_equals($nonce, (string)($payload['nonce'] ?? ''))) {
        return ['ok' => false, 'claims' => null, 'error' => 'ID token nonce mismatch'];
    }

    return ['ok' => true, 'claims' => $payload, 'error' => null];
}

/** Begin OIDC authorization redirect. */
function sso_start_login(): void
{
    if (!sso_enabled()) {
        header('Location: admin.php');
        exit;
    }

    $discovery = sso_discovery();
    if ($discovery === null || empty($discovery['authorization_endpoint'])) {
        $_SESSION['sso_error'] = 'Could not load OpenID discovery document — check Issuer URL.';
        header('Location: admin.php');
        exit;
    }

    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['sso_state'] = $state;
    $_SESSION['sso_nonce'] = $nonce;

    $params = [
        'client_id' => sso_client_id(),
        'response_type' => 'code',
        'scope' => sso_scopes(),
        'redirect_uri' => sso_redirect_uri(),
        'state' => $state,
        'nonce' => $nonce,
        'response_mode' => 'query',
    ];

    $url = (string)$discovery['authorization_endpoint'];
    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    header('Location: ' . $url);
    exit;
}

/**
 * Handle OIDC callback (?sso=callback).
 * @return array{ok:bool,error:?string,user:?array}
 */
function sso_handle_callback(): array
{
    if (!sso_enabled()) {
        return ['ok' => false, 'error' => 'SSO is not configured.', 'user' => null];
    }

    $err = (string)($_GET['error'] ?? '');
    if ($err !== '') {
        $desc = (string)($_GET['error_description'] ?? $err);
        return ['ok' => false, 'error' => 'SSO provider error: ' . $desc, 'user' => null];
    }

    $state = (string)($_GET['state'] ?? '');
    $expected = (string)($_SESSION['sso_state'] ?? '');
    unset($_SESSION['sso_state']);
    if ($expected === '' || !hash_equals($expected, $state)) {
        return ['ok' => false, 'error' => 'Invalid SSO state — try again.', 'user' => null];
    }

    $code = (string)($_GET['code'] ?? '');
    if ($code === '') {
        return ['ok' => false, 'error' => 'Missing authorization code.', 'user' => null];
    }

    $discovery = sso_discovery();
    if ($discovery === null || empty($discovery['token_endpoint'])) {
        return ['ok' => false, 'error' => 'Could not load token endpoint.', 'user' => null];
    }

    $tokenResp = sso_http_json('POST', (string)$discovery['token_endpoint'], [
        'form' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => sso_redirect_uri(),
            'client_id' => sso_client_id(),
            'client_secret' => sso_client_secret(),
        ],
    ]);

    if (!$tokenResp['ok'] || !is_array($tokenResp['json'])) {
        $detail = is_array($tokenResp['json']) ? (string)($tokenResp['json']['error_description'] ?? $tokenResp['json']['error'] ?? '') : '';
        return ['ok' => false, 'error' => 'Token exchange failed' . ($detail !== '' ? ': ' . $detail : '.'), 'user' => null];
    }

    $idToken = (string)($tokenResp['json']['id_token'] ?? '');
    if ($idToken === '') {
        return ['ok' => false, 'error' => 'No ID token in response.', 'user' => null];
    }

    $nonce = (string)($_SESSION['sso_nonce'] ?? '');
    unset($_SESSION['sso_nonce']);

    $verified = sso_verify_id_token($idToken, $nonce);
    if (!$verified['ok'] || !is_array($verified['claims'])) {
        return ['ok' => false, 'error' => $verified['error'] ?? 'ID token verification failed.', 'user' => null];
    }

    $claims = $verified['claims'];

    // Optional userinfo enrichment (Authentik / Entra may omit preferred_username in id_token).
    $accessToken = (string)($tokenResp['json']['access_token'] ?? '');
    if ($accessToken !== '' && !empty($discovery['userinfo_endpoint'])) {
        $ui = sso_http_json('GET', (string)$discovery['userinfo_endpoint'], [
            'headers' => ['Authorization: Bearer ' . $accessToken],
        ]);
        if ($ui['ok'] && is_array($ui['json'])) {
            $claims = array_merge($claims, $ui['json']);
        }
    }

    require_once __DIR__ . '/users_lib.php';
    $user = admin_authenticate_sso($claims);
    if ($user === null) {
        $reason = sso_jit_denial_reason($claims);
        if ($reason !== null) {
            return ['ok' => false, 'error' => $reason, 'user' => null];
        }
        return ['ok' => false, 'error' => 'No matching admin account — ask a super admin to create one first.', 'user' => null];
    }

    return ['ok' => true, 'error' => null, 'user' => $user];
}

/** @param array<string,mixed> $claims */
function sso_claim_username(array $claims): string
{
    require_once __DIR__ . '/users_lib.php';
    $claim = sso_username_claim();
    $candidates = [$claim];
    if ($claim !== 'preferred_username') {
        $candidates[] = 'preferred_username';
    }
    if ($claim !== 'email') {
        $candidates[] = 'email';
    }
    if (sso_provider() === 'entra') {
        $candidates[] = 'upn';
    }

    foreach ($candidates as $key) {
        $val = trim((string)($claims[$key] ?? ''));
        if ($val === '') {
            continue;
        }
        if ($key === 'email' || str_contains($val, '@')) {
            $val = strtolower(strtok($val, '@') ?: $val);
        }
        return users_normalize_username($val);
    }
    return '';
}

/** @param array<string,mixed> $claims */
function sso_claim_email(array $claims): string
{
    foreach (['email', 'preferred_username', 'upn'] as $key) {
        $val = strtolower(trim((string)($claims[$key] ?? '')));
        if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL)) {
            return $val;
        }
    }
    return '';
}

function sso_provider_label(): string
{
    return match (sso_provider()) {
        'entra' => 'Microsoft Entra ID',
        'authentik' => 'Authentik',
        default => 'SSO',
    };
}

function sso_login_button_label(): string
{
    return 'Sign in with ' . sso_provider_label();
}

/** Setup hints for Security board (super admin). */
function sso_admin_setup_html(): string
{
    $redirect = h(sso_redirect_uri());
    $provider = h(sso_provider_label());
    $issuerHelp = match (sso_provider()) {
        'entra' => 'Entra issuer: <code>https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0</code> '
            . '(App registration → Overview → Directory (tenant) ID). Register the redirect URI below as a Web platform.',
        'authentik' => 'Authentik: create an OAuth2/OpenID Provider + Application. Issuer URL is shown on the provider '
            . '(often <code>https://auth.example.com/application/o/&lt;slug&gt;/</code> — trailing slash must match exactly). '
            . 'Register the <strong>redirect URI</strong> below on the provider. Set the Application <strong>Launch URL</strong> to '
            . '<code>' . h(signage_admin_base_url() . '/admin.php?sso=start') . '</code> (not <code>index.php</code> — that URL is legacy weather only). '
            . 'Without a <strong>Signing key</strong> on the provider, Authentik signs ID tokens with <strong>HS256</strong> '
            . '(client secret). Select an RSA certificate under Protocol settings for <strong>RS256</strong> + JWKS verification.',
        default => 'Generic OIDC: paste the issuer URL from your provider; discovery is loaded automatically.',
    };

    return '<div class="upload-box sso-setup-box" id="security-sso-setup" style="margin-top:14px;margin-bottom:6px">'
        . '<h3>SSO setup (' . $provider . ')</h3>'
        . '<div class="help" style="margin-bottom:10px">' . $issuerHelp . '</div>'
        . '<div class="help"><strong>Redirect URI</strong> (register this on the OAuth provider):<br>'
        . '<code style="word-break:break-all">' . $redirect . '</code></div>'
        . '<div class="help" style="margin-top:8px"><strong>Authentik launch URL</strong> (Application → Launch URL — when opening the app from Authentik):<br>'
        . '<code style="word-break:break-all">' . h(signage_admin_base_url() . '/admin.php?sso=start') . '</code></div>'
        . '<div class="help" style="margin-top:8px">Create users under <strong>Users</strong> first — SSO matches by linked account '
        . '(external ID) or username claim. Enable <strong>Auto-link by email</strong> to match existing local usernames to the email claim on first sign-in.</div>'
        . '<div class="help" style="margin-top:8px"><strong>JIT provisioning:</strong> enable under Security to auto-create <em>operator</em> accounts on first SSO sign-in. '
        . 'A rotation display key is created from the username and assigned automatically (empty playlist until they add boards under Rotation). '
        . 'Restrict with allowed email domains and/or required groups (Entra app roles or <code>groups</code> claim; Authentik <code>groups</code> in userinfo).</div>'
        . '</div>';
}
