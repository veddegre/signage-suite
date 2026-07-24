<?php
/**
 * Admin audit log — append-only JSON in cache/ (super admins view under Audit).
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/users_lib.php';

const AUDIT_FILE = SIGNAGE_ROOT . '/cache/admin_audit.json';

function audit_enabled(): bool
{
    return (bool)cfg('security.AUDIT_ENABLED', true);
}

function audit_max_entries(): int
{
    $n = (int)cfg('security.AUDIT_MAX_ENTRIES', 2000);
    if ($n < 100) {
        $n = 100;
    }
    if ($n > 20000) {
        $n = 20000;
    }
    return $n;
}

/** @return list<array<string,mixed>> Newest first. */
function audit_load(): array
{
    if (!is_file(AUDIT_FILE)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(AUDIT_FILE), true);
    return is_array($data) ? $data : [];
}

function audit_save(array $entries): bool
{
    $result = signage_json_file_update(AUDIT_FILE, static fn(): array => array_values($entries), [
        'default' => [],
        'ensure_dir' => true,
    ]);

    return (bool)($result['ok'] ?? false);
}

/**
 * Record an admin action.
 * @param array<string,mixed> $extra Optional keys merged into the entry (no secrets).
 */
function audit_log(string $action, string $summary = '', array $extra = []): void
{
    if (!audit_enabled()) {
        return;
    }

    $user = admin_current_user();
    $entry = [
        'ts' => time(),
        'action' => $action,
        'summary' => $summary,
        'user_id' => is_array($user) ? (string)($user['id'] ?? '') : '',
        'username' => is_array($user) ? (string)($user['username'] ?? '') : (string)($extra['actor'] ?? ''),
        'ip' => signage_client_ip(),
    ];
    unset($extra['actor']);
    foreach ($extra as $k => $v) {
        if (is_string($k) && $k !== '') {
            $entry[$k] = $v;
        }
    }

    audit_file_update(function (array $rows) use ($entry): array {
        array_unshift($rows, $entry);

        return array_slice($rows, 0, audit_max_entries());
    });
}

function audit_file_update(callable $mutator): bool
{
    $result = signage_json_file_update(AUDIT_FILE, function (array $rows) use ($mutator): array|false {
        $updated = $mutator($rows);

        return is_array($updated) ? array_values($updated) : $updated;
    }, [
        'default' => [],
        'ensure_dir' => true,
    ]);

    return (bool)($result['ok'] ?? false);
}

/** @return list<array<string,mixed>> */
function audit_recent(int $limit = 250): array
{
    $limit = max(1, min(1000, $limit));
    return array_slice(audit_load(), 0, $limit);
}

function audit_action_label(string $action): string
{
    return match ($action) {
        'auth.login' => 'Login',
        'auth.login_failed' => 'Login failed',
        'auth.sso_login' => 'SSO login',
        'auth.sso_failed' => 'SSO failed',
        'auth.logout' => 'Logout',
        'auth.setup' => 'Initial setup',
        'auth.password_change' => 'Password changed',
        'users.save' => 'Users saved',
        'board.save' => 'Board saved',
        'cache.clear' => 'Cache cleared',
        'config.backup.store' => 'Config backup stored',
        'config.backup.export' => 'Config backup downloaded',
        'sso.jit_provision' => 'SSO user provisioned',
        'media.slide_upload' => 'Slide uploaded',
        'media.slide_delete' => 'Slide deleted',
        'media.photo_upload' => 'Photo uploaded',
        'media.photo_delete' => 'Photo deleted',
        'media.video_fetch' => 'Videos fetched',
        'emergency_activate' => 'Emergency activated',
        'emergency_release' => 'Emergency released',
        default => $action,
    };
}

function admin_can_audit(): bool
{
    if (!audit_enabled()) {
        return false;
    }
    if (admin_is_super()) {
        return true;
    }

    return admin_is_screen_operator();
}

/** @return list<array<string,mixed>> */
function audit_recent_for_user(int $limit = 250): array
{
    $rows = audit_recent($limit);
    if (admin_is_super()) {
        return $rows;
    }
    $uid = admin_user_id();
    if ($uid === null) {
        return [];
    }

    return array_values(array_filter(
        $rows,
        static fn($row) => is_array($row) && (string)($row['user_id'] ?? '') === $uid
    ));
}

/** Skip audit log when clearing API cache from Tools or board save. */
function audit_cache_preserve_basename(string $basename): bool
{
    return $basename === basename(AUDIT_FILE);
}
