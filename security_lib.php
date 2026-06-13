<?php
/**
 * Shared security helpers — outbound URL policy, admin setup, login limits.
 */

require_once __DIR__ . '/config.php';

const SIGNAGE_SETUP_KEY_FILE = __DIR__ . '/config/setup.key';
const SIGNAGE_LOGIN_ATTEMPTS_FILE = __DIR__ . '/cache/admin_login.json';

function signage_allow_private_fetch(): bool
{
    return (bool)cfg('security.ALLOW_PRIVATE_FETCH', false);
}

function signage_admin_idle_seconds(): int
{
    $mins = (int)cfg('security.ADMIN_IDLE_MINUTES', 480);
    if ($mins < 15) {
        $mins = 15;
    }
    if ($mins > 10080) {
        $mins = 10080;
    }
    return $mins * 60;
}

function signage_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** @return array{blocked:bool,reason:?string} */
function signage_ip_policy(string $ip, bool $allowPrivate): array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['blocked' => true, 'reason' => 'invalid IP'];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        if (!$allowPrivate) {
            return ['blocked' => true, 'reason' => 'private or reserved address'];
        }
    }
    $blockedExact = [
        '0.0.0.0', '127.0.0.1', '::1', '::',
        '169.254.169.254', 'fd00::', 'fe80::',
    ];
    foreach ($blockedExact as $bad) {
        if ($ip === $bad || str_starts_with($ip, rtrim($bad, ':'))) {
            return ['blocked' => true, 'reason' => 'blocked host'];
        }
    }
    if ($ip === '169.254.169.254') {
        return ['blocked' => true, 'reason' => 'cloud metadata'];
    }
    return ['blocked' => false, 'reason' => null];
}

/**
 * Whether the server may fetch this URL.
 * @return array{ok:bool,error:?string}
 */
function signage_fetch_url_allowed(string $url, ?bool $allowPrivate = null): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'error' => 'empty URL'];
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'error' => 'invalid URL'];
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'only http/https URLs are allowed'];
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '') {
        return ['ok' => false, 'error' => 'missing host'];
    }
    if (str_contains($host, '@') || str_contains($url, "\0")) {
        return ['ok' => false, 'error' => 'invalid host'];
    }
    if ($allowPrivate === null) {
        $allowPrivate = signage_allow_private_fetch();
    }

    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        if (!$allowPrivate) {
            return ['ok' => false, 'error' => 'localhost URLs are blocked'];
        }
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $ips = $resolved;
        }
    }
    foreach ($ips as $ip) {
        $pol = signage_ip_policy($ip, $allowPrivate);
        if ($pol['blocked']) {
            return ['ok' => false, 'error' => 'blocked destination (' . ($pol['reason'] ?? 'policy') . ')'];
        }
    }

    return ['ok' => true, 'error' => null];
}

/** @return array{ok:bool,error:?string} */
function signage_youtube_url_allowed(string $url): array
{
    $url = trim($url);
    if ($url === '' || str_contains($url, 'REPLACE_ME')) {
        return ['ok' => false, 'error' => 'invalid YouTube URL'];
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'error' => 'invalid URL'];
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'YouTube URLs must use http or https'];
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'www.youtu.be',
    ];
    $okHost = false;
    foreach ($allowed as $h) {
        if ($host === $h || str_ends_with($host, '.' . $h)) {
            $okHost = true;
            break;
        }
    }
    if (!$okHost) {
        return ['ok' => false, 'error' => 'host must be youtube.com or youtu.be'];
    }
    return signage_fetch_url_allowed($url, false);
}

function signage_admin_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function signage_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Create setup.key on first boot if admin password does not exist yet. */
function signage_setup_key_ready(): void
{
    if (is_file(__DIR__ . '/config/admin.json')) {
        return;
    }
    $dir = __DIR__ . '/config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_file(SIGNAGE_SETUP_KEY_FILE)) {
        return;
    }
    @file_put_contents(SIGNAGE_SETUP_KEY_FILE, bin2hex(random_bytes(16)), LOCK_EX);
    @chmod(SIGNAGE_SETUP_KEY_FILE, 0600);
}

function signage_setup_key_valid(string $provided): bool
{
    if (!is_file(SIGNAGE_SETUP_KEY_FILE)) {
        return false;
    }
    $expected = trim((string)file_get_contents(SIGNAGE_SETUP_KEY_FILE));
    return $expected !== '' && hash_equals($expected, trim($provided));
}

function signage_setup_key_consume(): void
{
    @unlink(SIGNAGE_SETUP_KEY_FILE);
}

/** @return array{ok:bool,wait:?int} */
function signage_login_allowed(): array
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ip = signage_client_ip();
    $now = time();
    $data = [];
    if (is_file(SIGNAGE_LOGIN_ATTEMPTS_FILE)) {
        $data = json_decode((string)file_get_contents(SIGNAGE_LOGIN_ATTEMPTS_FILE), true);
        if (!is_array($data)) {
            $data = [];
        }
    }
    $row = $data[$ip] ?? ['fails' => 0, 'locked_until' => 0];
    if (($row['locked_until'] ?? 0) > $now) {
        return ['ok' => false, 'wait' => (int)$row['locked_until'] - $now];
    }
    return ['ok' => true, 'wait' => null];
}

function signage_login_failed(): void
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ip = signage_client_ip();
    $data = [];
    if (is_file(SIGNAGE_LOGIN_ATTEMPTS_FILE)) {
        $data = json_decode((string)file_get_contents(SIGNAGE_LOGIN_ATTEMPTS_FILE), true);
        if (!is_array($data)) {
            $data = [];
        }
    }
    $row = $data[$ip] ?? ['fails' => 0, 'locked_until' => 0];
    $row['fails'] = (int)($row['fails'] ?? 0) + 1;
    if ($row['fails'] >= 8) {
        $row['locked_until'] = time() + 900;
        $row['fails'] = 0;
    }
    $data[$ip] = $row;
    @file_put_contents(SIGNAGE_LOGIN_ATTEMPTS_FILE, json_encode($data), LOCK_EX);
}

function signage_login_succeeded(): void
{
    if (!is_file(SIGNAGE_LOGIN_ATTEMPTS_FILE)) {
        return;
    }
    $ip = signage_client_ip();
    $data = json_decode((string)file_get_contents(SIGNAGE_LOGIN_ATTEMPTS_FILE), true);
    if (!is_array($data)) {
        return;
    }
    unset($data[$ip]);
    @file_put_contents(SIGNAGE_LOGIN_ATTEMPTS_FILE, json_encode($data), LOCK_EX);
}

function signage_admin_idle_check(): bool
{
    if (empty($_SESSION['auth'])) {
        return true;
    }
    $now = time();
    $last = (int)($_SESSION['last_active'] ?? $now);
    if (($now - $last) > signage_admin_idle_seconds()) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['last_active'] = $now;
    return true;
}
