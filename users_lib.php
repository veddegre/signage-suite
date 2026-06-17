<?php
/**
 * Admin users — local accounts, roles, and screen-scoped access.
 *
 * Designed for future SSO: users have auth_provider + external_id; login flows
 * through admin_authenticate_local() today and can gain admin_authenticate_sso() later.
 */

require_once __DIR__ . '/config.php';

const USERS_FILE = __DIR__ . '/config/users.json';
const LEGACY_ADMIN_FILE = __DIR__ . '/config/admin.json';

/** Boards operators may open (content + rotation; not tools/users/security). */
const ADMIN_OPERATOR_BOARDS = [
    'rotation', 'slides', 'rotator', 'rss', 'web', 'video',
    'grafana', 'splunk', 'splunkdash', 'family', 'account',
];

/** Operators may edit board-level settings (paths, TTL) on these boards — not API secrets. */
const ADMIN_OPERATOR_SETTINGS_BOARDS = ['slides', 'rotator'];

function users_load_raw(): array
{
    if (!is_file(USERS_FILE)) {
        users_migrate_from_legacy();
    }
    if (!is_file(USERS_FILE)) {
        return ['users' => []];
    }
    $data = json_decode((string)file_get_contents(USERS_FILE), true);
    return is_array($data) ? $data : ['users' => []];
}

function users_file_update(callable $mutator): bool
{
    $result = signage_json_file_update(USERS_FILE, $mutator, [
        'default' => ['users' => []],
        'pretty' => true,
    ]);

    return (bool)($result['ok'] ?? false);
}

function users_save_raw(array $data): bool
{
    return users_file_update(static fn(): array => $data);
}

/** Import legacy single-hash admin.json into users.json once. */
function users_migrate_from_legacy(): bool
{
    if (is_file(USERS_FILE)) {
        return false;
    }
    if (!is_file(LEGACY_ADMIN_FILE)) {
        return false;
    }
    $legacy = json_decode((string)file_get_contents(LEGACY_ADMIN_FILE), true);
    $hash = is_array($legacy) ? (string)($legacy['hash'] ?? '') : '';
    if ($hash === '') {
        return false;
    }
    $data = [
        'users' => [[
            'id' => 'u_super',
            'username' => 'admin',
            'hash' => $hash,
            'role' => 'super',
            'screens' => [],
            'auth_provider' => 'local',
            'external_id' => null,
            'disabled' => false,
        ]],
    ];
    if (!users_save_raw($data)) {
        return false;
    }
    @unlink(LEGACY_ADMIN_FILE);
    return true;
}

function users_need_setup(): bool
{
    users_migrate_from_legacy();
    $data = users_load_raw();
    $users = $data['users'] ?? [];
    return !is_array($users) || $users === [];
}

function users_list(): array
{
    $data = users_load_raw();
    $users = $data['users'] ?? [];
    return is_array($users) ? $users : [];
}

/** @return array<string,array<string,mixed>> */
function users_by_id(): array
{
    $out = [];
    foreach (users_list() as $user) {
        if (!is_array($user) || empty($user['id'])) {
            continue;
        }
        $out[(string)$user['id']] = $user;
    }
    return $out;
}

function users_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function users_new_id(): string
{
    return 'u_' . substr(bin2hex(random_bytes(6)), 0, 10);
}

function users_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return $role === 'super' ? 'super' : 'operator';
}

/** @return list<string> */
/** Max displays per operator account (one operator ↔ one display). */
const USERS_OPERATOR_SCREEN_MAX = 1;

/** @return array<string,string> display key => operator user id */
function users_screen_assignments(): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $map = [];
    foreach (users_list() as $user) {
        if (!is_array($user) || users_normalize_role((string)($user['role'] ?? '')) === 'super') {
            continue;
        }
        $uid = (string)($user['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        foreach (users_normalize_screens($user['screens'] ?? []) as $sk) {
            $map[$sk] = $uid;
        }
    }
    return $map;
}

/** @return list<string> Displays assigned to an operator — cannot be deleted until unassigned. */
function users_protected_screen_keys(): array
{
    return array_keys(users_screen_assignments());
}

function users_screen_assigned_username(string $screen): ?string
{
    require_once __DIR__ . '/rotation_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    if ($screen === '') {
        return null;
    }
    $uid = users_screen_assignments()[$screen] ?? null;
    if ($uid === null) {
        return null;
    }
    $user = users_find_by_id($uid);
    return is_array($user) ? (string)($user['username'] ?? '') : null;
}

/** @return array<string,array{userId:string,username:string}> display key => operator */
function admin_screen_operator_map(): array
{
    $out = [];
    foreach (users_screen_assignments() as $sk => $uid) {
        $out[$sk] = [
            'userId' => $uid,
            'username' => admin_username_for_id($uid),
        ];
    }

    return $out;
}

/** @param array<string,mixed> $row */
function users_screens_from_row(array $row): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $single = rotation_normalize_screen_key((string)($row['screen'] ?? ''));
    if ($single !== '') {
        return [$single];
    }
    return users_normalize_screens($row['screens'] ?? []);
}

/** Single assigned display for the current operator; null for super or when unassigned. */
function admin_operator_screen_key(): ?string
{
    if (admin_is_super()) {
        return null;
    }
    $keys = admin_allowed_screen_keys();
    return $keys[0] ?? null;
}

function admin_operator_screen_locked(): bool
{
    return admin_operator_screen_key() !== null;
}

/** @return list<string> */
function users_normalize_screens($raw): array
{
    require_once __DIR__ . '/rotation_lib.php';
    $keys = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $k = rotation_normalize_screen_key((string)$item);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
    } elseif (is_string($raw) && trim($raw) !== '') {
        foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            $k = rotation_normalize_screen_key((string)$item);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
    }
    $list = array_keys($keys);
    sort($list);
    return $list;
}

/** Strip secrets; shape stored in session. @return array<string,mixed>|null */
function users_public_row(?array $user): ?array
{
    if (!is_array($user) || empty($user['id'])) {
        return null;
    }
    return [
        'id' => (string)$user['id'],
        'username' => (string)($user['username'] ?? ''),
        'role' => users_normalize_role((string)($user['role'] ?? 'operator')),
        'screens' => array_slice(users_normalize_screens($user['screens'] ?? []), 0, USERS_OPERATOR_SCREEN_MAX),
        'auth_provider' => (string)($user['auth_provider'] ?? 'local'),
        'disabled' => !empty($user['disabled']),
    ];
}

function users_find_by_username(string $username): ?array
{
    $want = users_normalize_username($username);
    if ($want === '') {
        return null;
    }
    foreach (users_list() as $user) {
        if (!is_array($user)) {
            continue;
        }
        if (users_normalize_username((string)($user['username'] ?? '')) === $want) {
            return $user;
        }
    }
    return null;
}

function users_find_by_id(string $id): ?array
{
    return users_by_id()[$id] ?? null;
}

function users_find_by_external_id(string $externalId): ?array
{
    $externalId = trim($externalId);
    if ($externalId === '') {
        return null;
    }
    foreach (users_list() as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string)($user['external_id'] ?? '') === $externalId) {
            return $user;
        }
    }
    return null;
}

/** Link an existing user to an SSO subject on successful sign-in. */
function users_link_sso_account(string $userId, string $externalId): bool
{
    $externalId = trim($externalId);
    if ($externalId === '') {
        return false;
    }
    $found = false;
    $ok = users_file_update(function (array $data) use ($userId, $externalId, &$found): array|false {
        $users = $data['users'] ?? [];
        if (!is_array($users)) {
            return false;
        }
        foreach ($users as &$user) {
            if (!is_array($user) || (string)($user['id'] ?? '') !== $userId) {
                continue;
            }
            $user['external_id'] = $externalId;
            $user['auth_provider'] = 'sso';
            unset($user['hash']);
            $found = true;
            break;
        }
        unset($user);
        if (!$found) {
            return false;
        }
        $data['users'] = $users;

        return $data;
    });

    return $ok && $found;
}

/**
 * Match an OIDC login to a configured admin user and link external_id when needed.
 * @param array<string,mixed> $claims Verified ID token + optional userinfo claims.
 * @return array<string,mixed>|null Public user row on success.
 */
function admin_authenticate_sso(array $claims): ?array
{
    require_once __DIR__ . '/sso_lib.php';

    $sub = trim((string)($claims['sub'] ?? ''));
    if ($sub === '') {
        return null;
    }

    $user = users_find_by_external_id($sub);
    if ($user === null) {
        $username = sso_claim_username($claims);
        if ($username !== '') {
            $user = users_find_by_username($username);
        }
    }
    if ($user === null && sso_auto_link_email()) {
        $email = sso_claim_email($claims);
        if ($email !== '') {
            $local = users_normalize_username(strtok($email, '@') ?: '');
            if ($local !== '') {
                $user = users_find_by_username($local);
            }
        }
    }

    if ($user !== null && !empty($user['disabled'])) {
        return null;
    }
    if ($user === null) {
        $provisioned = users_provision_sso($claims);
        if ($provisioned !== null) {
            return users_public_row($provisioned);
        }
        return null;
    }

    if ((string)($user['external_id'] ?? '') !== $sub) {
        users_link_sso_account((string)$user['id'], $sub);
        $user = users_find_by_id((string)$user['id']);
        if ($user === null) {
            return null;
        }
    }

    return users_public_row($user);
}

/**
 * Create a new SSO user on first sign-in when JIT provisioning is enabled.
 * @param array<string,mixed> $claims
 * @return array<string,mixed>|null Full user row on success.
 */
function users_provision_sso(array $claims): ?array
{
    require_once __DIR__ . '/sso_lib.php';

    if (!sso_jit_enabled()) {
        return null;
    }

    $sub = trim((string)($claims['sub'] ?? ''));
    $username = sso_claim_username($claims);
    if ($sub === '' || $username === '') {
        return null;
    }
    if (!sso_jit_email_allowed($claims) || !sso_jit_groups_allowed($claims)) {
        return null;
    }
    if (users_find_by_external_id($sub) !== null || users_find_by_username($username) !== null) {
        return null;
    }

    $entry = [
        'id' => users_new_id(),
        'username' => $username,
        'role' => sso_jit_default_role(),
        'screens' => [],
        'auth_provider' => 'sso',
        'external_id' => $sub,
        'disabled' => false,
    ];

    $created = false;
    if (!users_file_update(function (array $data) use ($entry, $sub, $username, &$created): array|false {
        $users = $data['users'] ?? [];
        if (!is_array($users)) {
            $users = [];
        }
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if ((string)($user['external_id'] ?? '') === $sub
                || users_normalize_username((string)($user['username'] ?? '')) === $username) {
                return false;
            }
        }
        $users[] = $entry;
        $data['users'] = $users;
        $created = true;

        return $data;
    })) {
        return null;
    }
    if (!$created) {
        return null;
    }

    require_once __DIR__ . '/audit_lib.php';
    audit_log('sso.jit_provision', 'Created operator ' . $username, [
        'actor' => $username,
        'role' => 'operator',
        'external_id' => $sub,
    ]);

    return $entry;
}

/**
 * Local username/password login.
 * @return array<string,mixed>|null Public user row on success.
 */
function admin_authenticate_local(string $username, string $password): ?array
{
    $user = users_find_by_username($username);
    if ($user === null || !empty($user['disabled'])) {
        return null;
    }
    if ((string)($user['auth_provider'] ?? 'local') !== 'local') {
        return null;
    }
    $hash = (string)($user['hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }
    return users_public_row($user);
}

/** @param array<string,mixed> $publicUser From users_public_row() */
function admin_login_user(array $publicUser): void
{
    session_regenerate_id(true);
    $_SESSION['admin_user'] = $publicUser;
    $_SESSION['auth'] = true;
    $_SESSION['last_active'] = time();
}

function admin_logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** @return array<string,mixed>|null */
function admin_current_user(): ?array
{
    $user = $_SESSION['admin_user'] ?? null;
    return is_array($user) ? $user : null;
}

function admin_is_authenticated(): bool
{
    return admin_current_user() !== null && !empty($_SESSION['auth']);
}

function admin_is_super(): bool
{
    $user = admin_current_user();
    return is_array($user) && ($user['role'] ?? '') === 'super';
}

function admin_can_tools(): bool
{
    return admin_is_super();
}

function admin_can_manage_users(): bool
{
    return admin_is_super();
}

function admin_username(): string
{
    $user = admin_current_user();
    return is_array($user) ? (string)($user['username'] ?? '') : '';
}

/** @return list<string> Screen keys this session may manage. Super = all displays; operators = one assigned display. */
function admin_allowed_screen_keys(): array
{
    require_once __DIR__ . '/rotation_lib.php';
    if (admin_is_super()) {
        return array_keys(rotation_screens());
    }
    $user = admin_current_user();
    if (!is_array($user)) {
        return [];
    }
    $allowed = rotation_screens();
    $out = [];
    foreach (users_normalize_screens($user['screens'] ?? []) as $key) {
        if (isset($allowed[$key])) {
            $out[] = $key;
            break;
        }
    }
    return $out;
}

function admin_can_screen(string $screen): bool
{
    require_once __DIR__ . '/rotation_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    if ($screen === '') {
        return false;
    }
    return in_array($screen, admin_allowed_screen_keys(), true);
}

/** @param array<string,mixed> $screens From rotation_screens() */
function admin_filter_screens(array $screens): array
{
    if (admin_is_super()) {
        return $screens;
    }
    $allowed = array_flip(admin_allowed_screen_keys());
    return array_intersect_key($screens, $allowed);
}

/** @param list<string> $screens */
function admin_filter_deploy_screens(array $screens): array
{
    $allowed = array_flip(admin_allowed_screen_keys());
    $out = [];
    foreach ($screens as $screen) {
        require_once __DIR__ . '/rotation_lib.php';
        $key = rotation_normalize_screen_key((string)$screen);
        if ($key !== '' && isset($allowed[$key])) {
            $out[$key] = true;
        }
    }
    return array_keys($out);
}

/** Default deploy targets for uploads (main for super, first assigned screen for operators). @return list<string> */
function admin_default_deploy_screens(): array
{
    $keys = admin_allowed_screen_keys();
    if ($keys === []) {
        return [];
    }
    if (in_array('main', $keys, true)) {
        return ['main'];
    }
    return [$keys[0]];
}

function admin_deploy_screens_session_key(string $board): string
{
    return 'admin_deploy_v2_' . preg_replace('/[^a-z]/', '', $board);
}

/** @return list<string> */
function admin_deploy_screens_from_post(array $post): array
{
    if (!isset($post['deploy_screens']) || !is_array($post['deploy_screens'])) {
        return [];
    }
    require_once __DIR__ . '/rotation_lib.php';
    $screens = [];
    foreach ($post['deploy_screens'] as $scr) {
        $sk = rotation_normalize_screen_key((string)$scr);
        if ($sk !== '') {
            $screens[$sk] = true;
        }
    }
    $list = array_keys($screens);
    sort($list);
    return $list;
}

/** @param list<string> $screens */
function admin_deploy_screens_remember(string $board, array $screens): void
{
    $key = admin_deploy_screens_session_key($board);
    $filtered = admin_filter_deploy_screens($screens);
    if ($filtered === []) {
        unset($_SESSION[$key]);
        return;
    }
    $_SESSION[$key] = $filtered;
}

/** @return list<string>|null */
function admin_deploy_screens_remembered(string $board): ?array
{
    $key = admin_deploy_screens_session_key($board);
    if (!array_key_exists($key, $_SESSION)) {
        return null;
    }
    $filtered = admin_filter_deploy_screens((array)$_SESSION[$key]);
    if ($filtered === []) {
        unset($_SESSION[$key]);
        return null;
    }
    return $filtered;
}

/** Resolve deploy targets from POST, remembered session, or defaults. @return list<string> */
function admin_deploy_screens_for_action(string $board, array $post): array
{
    if (isset($post['deploy_screens']) || !empty($post['deploy_screens_sent'])) {
        $screens = admin_deploy_screens_from_post($post);
        admin_deploy_screens_remember($board, $screens);
        return admin_filter_deploy_screens($screens);
    }
    $remembered = admin_deploy_screens_remembered($board);
    if ($remembered !== null) {
        return $remembered;
    }

    return [];
}

/** @param list<string> $defaultChecked @return list<string> */
function admin_deploy_picker_checked(string $board, array $defaultChecked): array
{
    $remembered = admin_deploy_screens_remembered($board);
    if ($remembered !== null) {
        return $remembered;
    }
    return admin_filter_deploy_screens($defaultChecked);
}

function admin_user_id(): ?string
{
    $user = admin_current_user();
    if (!is_array($user) || empty($user['id'])) {
        return null;
    }
    return (string)$user['id'];
}

/** @param array<string,mixed>|null $entry */
function admin_entry_owner(?array $entry): ?string
{
    if (!is_array($entry)) {
        return null;
    }
    $owner = trim((string)($entry['owner'] ?? ''));
    return $owner !== '' ? $owner : null;
}

/** @return list<string> */
function admin_normalize_shared(mixed $raw): array
{
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
    }
    $list = array_keys($ids);
    sort($list);
    return $list;
}

/** @param array<string,mixed>|null $entry @return list<string> */
function admin_entry_shared_users(?array $entry): array
{
    if (!is_array($entry)) {
        return [];
    }
    return admin_normalize_shared($entry['shared'] ?? []);
}

/** Legacy entries without owner are super-admin only unless shared. */
function admin_entry_visible(?array $entry): bool
{
    if (!is_array($entry)) {
        return false;
    }
    if (admin_is_super()) {
        return true;
    }
    $uid = admin_user_id();
    if ($uid === null) {
        return false;
    }

    return admin_entry_visible_for_user($entry, $uid);
}

/** Whether one config row is visible to a specific user (null user = show all). */
function admin_entry_visible_for_user(?array $entry, ?string $userId): bool
{
    if (!is_array($entry)) {
        return false;
    }
    if ($userId === null || $userId === '') {
        return true;
    }
    $owner = admin_entry_owner($entry);
    if ($owner !== null && $owner === $userId) {
        return true;
    }

    return in_array($userId, admin_entry_shared_users($entry), true);
}

function admin_can_edit_entry(?array $entry): bool
{
    return admin_entry_visible($entry);
}

/** True when the current user may delete or drop this deck/registry row (operators: own entries only). */
function admin_entry_owned_by_current_user(?array $entry): bool
{
    if (!is_array($entry)) {
        return false;
    }
    if (admin_is_super()) {
        return true;
    }
    $uid = admin_user_id();
    if ($uid === null) {
        return false;
    }

    return admin_entry_owner($entry) === $uid;
}

/** @param array<string,mixed> $entry */
function admin_stamp_owner(array $entry, ?array $prev = null): array
{
    if ($prev !== null) {
        $prevOwner = admin_entry_owner($prev);
        if ($prevOwner !== null && !array_key_exists('owner', $entry)) {
            $entry['owner'] = $prevOwner;
        }
        if (!array_key_exists('shared', $entry)) {
            $shared = admin_entry_shared_users($prev);
            if ($shared !== []) {
                $entry['shared'] = $shared;
            }
        }
    }
    if (admin_is_super()) {
        return $entry;
    }
    $uid = admin_user_id();
    if ($uid === null) {
        return $entry;
    }
    $prevOwner = admin_entry_owner($prev);
    if ($prev === null || $prevOwner === null) {
        $entry['owner'] = $uid;
    } elseif ($prevOwner === $uid) {
        $entry['owner'] = $uid;
    } elseif (!array_key_exists('owner', $entry)) {
        $entry['owner'] = $prevOwner;
    }
    return $entry;
}

/** @param array<string,mixed> $entry @param array<string,mixed> $postRow */
function admin_apply_sharing_from_post(array $entry, array $postRow): array
{
    if (!admin_is_super() || empty($postRow['_sharing_form'])) {
        return $entry;
    }
    $owner = trim((string)($postRow['owner'] ?? ''));
    if ($owner === '') {
        unset($entry['owner']);
    } else {
        $entry['owner'] = $owner;
    }
    $shared = [];
    if (isset($postRow['shared']) && is_array($postRow['shared'])) {
        foreach ($postRow['shared'] as $id) {
            $id = trim((string)$id);
            if ($id !== '' && $id !== ($entry['owner'] ?? '')) {
                $shared[$id] = true;
            }
        }
    }
    $sharedList = array_keys($shared);
    sort($sharedList);
    if ($sharedList === []) {
        unset($entry['shared']);
    } else {
        $entry['shared'] = $sharedList;
    }
    return $entry;
}

/** @param array<string,mixed> $entry @param array<string,mixed> $postRow */
function admin_finalize_entry(array $entry, ?array $prev, array $postRow): array
{
    $entry = admin_stamp_owner($entry, $prev);
    return admin_apply_sharing_from_post($entry, $postRow);
}

/** @return list<array{id:string,username:string}> */
function admin_sharing_user_options(): array
{
    $out = [];
    foreach (users_list() as $user) {
        if (!is_array($user) || !empty($user['disabled'])) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'username' => (string)($user['username'] ?? $id),
        ];
    }
    usort($out, static fn($a, $b) => strcasecmp($a['username'], $b['username']));
    return $out;
}

/** Preserve checkbox arrays (e.g. shared[]) when normalizing POST rows. */
function admin_normalize_form_row(array $row): array
{
    $out = [];
    foreach ($row as $k => $v) {
        if (is_array($v)) {
            $out[$k] = $v;
        } else {
            $out[$k] = trim((string)$v);
        }
    }
    return $out;
}

function admin_username_for_id(string $userId): string
{
    $user = users_find_by_id($userId);
    if (!is_array($user)) {
        return $userId;
    }
    return (string)($user['username'] ?? $userId);
}

/** @param array<string,mixed>|null $entry */
function admin_entry_access_summary(?array $entry): string
{
    if (!is_array($entry)) {
        return '';
    }
    $owner = admin_entry_owner($entry);
    $shared = admin_entry_shared_users($entry);
    if ($owner === null && $shared === []) {
        return 'Super only (no owner)';
    }
    $parts = [];
    if ($owner !== null) {
        $parts[] = admin_username_for_id($owner);
    }
    if ($shared !== []) {
        $names = array_map('admin_username_for_id', $shared);
        $parts[] = 'shared: ' . implode(', ', $names);
    }
    return implode(' · ', $parts);
}

/** Short label for table-row access popover trigger. */
function admin_entry_access_trigger_label(?array $entry): string
{
    if (!is_array($entry)) {
        return 'Super only';
    }
    $owner = admin_entry_owner($entry);
    $shared = admin_entry_shared_users($entry);
    if ($owner === null && $shared === []) {
        return 'Super only';
    }
    $label = $owner !== null ? admin_username_for_id($owner) : 'Super only';
    if ($shared === []) {
        return $label;
    }
    if (count($shared) === 1) {
        return $label . ' · ' . admin_username_for_id($shared[0]);
    }
    return $label . ' · +' . count($shared);
}

/** @param list<array{id:string,username:string}> $users */
function admin_entry_sharing_fields(string $prefix, ?array $entry, array $users, bool $popover = false): void
{
    $owner = admin_entry_owner($entry);
    $sharedSet = array_flip(admin_entry_shared_users($entry));
    if ($popover) {
        echo '<div class="entry-sharing-panel-head"><strong>Access</strong></div>';
    }
    echo '<label class="mini">Owner</label>';
    if ($popover) {
        echo '<div class="entry-sharing-owner-list" data-entry-sharing-owner-list>';
        echo '<label><input type="radio" name="' . h($prefix . '[owner]') . '" value="" data-entry-sharing-owner'
            . ($owner === null ? ' checked' : '') . '> Super only</label>';
        foreach ($users as $u) {
            echo '<label><input type="radio" name="' . h($prefix . '[owner]') . '" value="' . h($u['id']) . '"'
                . ' data-entry-sharing-owner'
                . ($owner === $u['id'] ? ' checked' : '') . '> ' . h($u['username']) . '</label>';
        }
        echo '</div>';
    } else {
        echo '<select name="' . h($prefix . '[owner]') . '" data-entry-sharing-owner>';
        echo '<option value="">Super only</option>';
        foreach ($users as $u) {
            echo '<option value="' . h($u['id']) . '"' . ($owner === $u['id'] ? ' selected' : '') . '>'
                . h($u['username']) . '</option>';
        }
        echo '</select>';
    }
    echo '<span class="mini entry-sharing-shared-label">Also shared with</span>';
    echo '<div class="entry-sharing-users-scroll entry-sharing-users" data-entry-sharing-shared-list>';
    foreach ($users as $u) {
        if ($owner !== null && $u['id'] === $owner) {
            continue;
        }
        echo '<label data-entry-sharing-user="' . h($u['id']) . '"><input type="checkbox" name="' . h($prefix . '[shared][]') . '" value="' . h($u['id']) . '"'
            . ' data-entry-sharing-shared'
            . (isset($sharedSet[$u['id']]) ? ' checked' : '') . '> ' . h($u['username']) . '</label>';
    }
    echo '</div>';
}

/** Super-admin: owner dropdown + shared-with checkboxes. */
function admin_entry_sharing_html(string $prefix, ?array $entry, bool $compact = false): void
{
    if (!admin_is_super()) {
        return;
    }
    $users = admin_sharing_user_options();
    if ($users === []) {
        return;
    }
    echo '<input type="hidden" name="' . h($prefix . '[_sharing_form]') . '" value="1">';
    if ($compact) {
        $label = admin_entry_access_trigger_label($entry);
        echo '<div class="entry-sharing entry-sharing--popover" data-entry-sharing>';
        echo '<button type="button" class="secondary entry-sharing-trigger" data-entry-sharing-trigger>'
            . h($label) . '</button>';
        echo '<div class="entry-sharing-menu" hidden data-entry-sharing-menu role="dialog" aria-label="Access">';
        admin_entry_sharing_fields($prefix, $entry, $users, true);
        echo '</div></div>';
    } else {
        echo '<div class="entry-sharing">';
        admin_entry_sharing_fields($prefix, $entry, $users, false);
        echo '</div>';
    }
}

/** True when POST likely hit PHP max_input_vars (large deck saves can truncate). */
function admin_post_input_vars_saturated(): bool
{
    $max = (int)ini_get('max_input_vars');
    if ($max <= 0) {
        return false;
    }

    return count($_POST, COUNT_RECURSIVE) >= $max - 16;
}

/** @param list<array<string,mixed>> $list */
function admin_filter_owned_list(array $list): array
{
    if (admin_is_super()) {
        return $list;
    }
    return array_values(array_filter($list, static fn($row) => is_array($row) && admin_entry_visible($row)));
}

/** @param array<string,array<string,mixed>|mixed> $map */
function admin_filter_owned_map(array $map): array
{
    if (admin_is_super()) {
        return $map;
    }
    $out = [];
    foreach ($map as $k => $entry) {
        if (is_array($entry) && admin_entry_visible($entry)) {
            $out[$k] = $entry;
        }
    }
    return $out;
}

/** @param list<array<string,mixed>> $existing @param list<array<string,mixed>> $posted */
function admin_merge_owned_list(array $existing, array $posted): array
{
    if (admin_is_super()) {
        return $posted;
    }
    $kept = array_values(array_filter($existing, static fn($e) => is_array($e) && !admin_entry_visible($e)));
    $postedFiles = [];
    foreach ($posted as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = trim((string)($row['file'] ?? ''));
        if ($file !== '') {
            $postedFiles[$file] = true;
        }
    }
    foreach ($existing as $entry) {
        if (!is_array($entry) || !admin_entry_visible($entry) || admin_entry_owned_by_current_user($entry)) {
            continue;
        }
        $file = trim((string)($entry['file'] ?? ''));
        if ($file !== '' && !isset($postedFiles[$file])) {
            $kept[] = $entry;
        }
    }
    $ownedOut = [];
    foreach ($posted as $row) {
        if (!is_array($row)) {
            continue;
        }
        $prev = admin_find_owned_list_entry($existing, $row);
        if ($prev !== null && !admin_entry_visible($prev)) {
            continue;
        }
        $ownedOut[] = admin_finalize_entry($row, $prev, $row);
    }
    return array_merge($kept, $ownedOut);
}

/** @param array<string,array<string,mixed>> $existing @param array<string,array<string,mixed>> $posted */
function admin_merge_owned_map(array $existing, array $posted): array
{
    if (admin_is_super()) {
        return $posted;
    }
    $out = [];
    foreach ($existing as $k => $row) {
        if (is_array($row) && !admin_entry_visible($row)) {
            $out[(string)$k] = $row;
        }
    }
    foreach ($posted as $k => $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string)$k;
        $prev = is_array($existing[$key] ?? null) ? $existing[$key] : null;
        if ($prev !== null && !admin_entry_visible($prev)) {
            continue;
        }
        $out[$key] = admin_finalize_entry($row, $prev, $row);
    }
    return $out;
}

/**
 * Keyed scalar rows (homelab services, family countdowns) stored as string or {value, owner}.
 * @param array<string,mixed> $existing
 * @param array<string,mixed> $posted
 */
function admin_merge_owned_scalar_map(array $existing, array $posted): array
{
    if (admin_is_super()) {
        return $posted;
    }
    $out = [];
    foreach ($existing as $k => $v) {
        if (is_array($v)) {
            if (!admin_entry_visible($v)) {
                $out[(string)$k] = $v;
            }
        } else {
            $out[(string)$k] = $v;
        }
    }
    foreach ($posted as $k => $v) {
        $key = trim((string)$k);
        if ($key === '') {
            continue;
        }
        $prev = $existing[$key] ?? null;
        if (is_array($prev) && !admin_entry_visible($prev)) {
            continue;
        }
        if (is_array($v)) {
            $val = trim((string)($v['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            $out[$key] = admin_finalize_entry($v, is_array($prev) ? $prev : null, $v);
        } else {
            $val = trim((string)$v);
            if ($val === '') {
                continue;
            }
            $out[$key] = admin_finalize_entry(['value' => $val], is_array($prev) ? $prev : null, []);
        }
    }
    return $out;
}

/** @param list<array<string,mixed>> $existing @param array<string,mixed> $row */
function admin_find_owned_list_entry(array $existing, array $row): ?array
{
    $file = trim((string)($row['file'] ?? ''));
    if ($file !== '') {
        foreach ($existing as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['file'] ?? '')) === $file) {
                return $entry;
            }
        }
    }
    $feedKey = trim((string)($row['key'] ?? ''));
    if ($feedKey !== '') {
        foreach ($existing as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['key'] ?? '')) === $feedKey) {
                return $entry;
            }
        }
    }
    return null;
}

/** Resolve homelab service URL or family countdown date from stored value. */
function admin_owned_scalar_value(mixed $stored): string
{
    if (is_array($stored)) {
        return trim((string)($stored['value'] ?? $stored['url'] ?? ''));
    }
    return trim((string)$stored);
}

function admin_filter_owned_scalar_map(array $map): array
{
    if (admin_is_super()) {
        return $map;
    }
    $out = [];
    foreach ($map as $k => $v) {
        if (is_array($v) && admin_entry_visible($v)) {
            $out[(string)$k] = $v;
        }
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $entries
 * @param list<array<string,mixed>> $deck Full deck (not ownership-filtered)
 */
function admin_filter_library_entries(array $entries, array $deck, callable $fileFromEntry): array
{
    if (admin_is_super()) {
        return $entries;
    }
    $ownedFiles = [];
    $hiddenFiles = [];
    foreach ($deck as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $file = $fileFromEntry($entry);
        if ($file === null || $file === '') {
            continue;
        }
        if (admin_entry_visible($entry)) {
            $ownedFiles[$file] = true;
        } else {
            $hiddenFiles[$file] = true;
        }
    }
    return array_values(array_filter($entries, static function ($item) use ($ownedFiles, $hiddenFiles) {
        $file = (string)($item['file'] ?? '');
        if ($file === '' || !empty($hiddenFiles[$file])) {
            return false;
        }
        if (!empty($ownedFiles[$file])) {
            return true;
        }
        return empty($item['in_deck']);
    }));
}

/** @param list<array<string,mixed>> $deck */
function admin_media_deploy_deck(array $deck): array
{
    return admin_is_super() ? $deck : admin_filter_owned_list($deck);
}

function admin_can_board_settings(string $board): bool
{
    if (admin_is_super()) {
        return true;
    }
    return in_array($board, ADMIN_OPERATOR_SETTINGS_BOARDS, true);
}

/** @param array<string,mixed>|null $deck */
function admin_can_delete_deck_file(?array $deck, string $file, callable $safeName, callable $fileFromEntry): bool
{
    if (admin_is_super()) {
        return true;
    }
    $want = $safeName($file);
    if ($want === null || $want === '') {
        return false;
    }
    if (!is_array($deck)) {
        $deck = [];
    }
    $visibleEntry = null;
    $hiddenMatch = false;
    foreach ($deck as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ($fileFromEntry($entry) !== $want) {
            continue;
        }
        if (admin_entry_visible($entry)) {
            $visibleEntry = $entry;
        } else {
            $hiddenMatch = true;
        }
    }
    if ($visibleEntry !== null) {
        return admin_entry_owned_by_current_user($visibleEntry);
    }
    if ($hiddenMatch) {
        return false;
    }
    return admin_is_super();
}

function admin_normalize_registry_key(string $key): ?string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);

    return $key !== '' ? $key : null;
}

function admin_preview_session_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!function_exists('signage_session_start')) {
        require_once __DIR__ . '/security_lib.php';
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        signage_session_start();
    }
    $ready = admin_is_authenticated();

    return $ready;
}

/** True when an operator is previewing a board from admin (?noticker=1). */
function admin_preview_filter_active(): bool
{
    return isset($_GET['noticker']) && admin_preview_session_ready() && !admin_is_super();
}

/**
 * Operator user id for signage board display scoping.
 * Logged-in operators use their account; kiosk rotation passes ?screen= for assigned displays.
 */
function admin_display_scope_user_id(): ?string
{
    static $resolved = false;
    static $uid = null;
    if ($resolved) {
        return $uid;
    }
    $resolved = true;

    if (admin_preview_session_ready() && !admin_is_super()) {
        $uid = admin_user_id();
        if ($uid !== null && $uid !== '') {
            return $uid;
        }
    }

    if (!function_exists('rotation_normalize_screen_key')) {
        require_once __DIR__ . '/rotation_lib.php';
    }
    $screen = rotation_normalize_screen_key((string)($_GET['screen'] ?? ''));
    if ($screen !== '' && $screen !== 'main') {
        $uid = users_screen_assignments()[$screen] ?? null;
    }

    return $uid !== '' ? $uid : null;
}

function admin_display_filter_active(): bool
{
    return admin_display_scope_user_id() !== null;
}

/** @param list<array<string,mixed>> $list */
function admin_filter_list_for_scope(array $list, ?string $userId): array
{
    if ($userId === null || $userId === '') {
        return $list;
    }

    return array_values(array_filter(
        $list,
        static fn($row) => is_array($row) && admin_entry_visible_for_user($row, $userId)
    ));
}

/** @param array<string,array<string,mixed>|mixed> $map */
function admin_filter_map_for_scope(array $map, ?string $userId): array
{
    if ($userId === null || $userId === '') {
        return $map;
    }
    $out = [];
    foreach ($map as $k => $entry) {
        if (is_array($entry) && admin_entry_visible_for_user($entry, $userId)) {
            $out[$k] = $entry;
        }
    }

    return $out;
}

/** @param array<string,mixed> $map */
function admin_filter_scalar_map_for_display(array $map): array
{
    $uid = admin_display_scope_user_id();
    if ($uid === null) {
        return $map;
    }
    $out = [];
    foreach ($map as $k => $v) {
        if (is_array($v) && admin_entry_visible_for_user($v, $uid)) {
            $out[(string)$k] = $v;
        }
    }

    return $out;
}

/** @param array<string,array<string,mixed>|mixed> $map */
function admin_filter_registry_for_display(array $map, ?callable $normalize = null): array
{
    return admin_filter_map_for_scope($map, admin_display_scope_user_id());
}

/** @param list<array<string,mixed>> $list */
function admin_filter_list_for_display(array $list): array
{
    return admin_filter_list_for_scope($list, admin_display_scope_user_id());
}

/**
 * Resolve a registry key for signage board display.
 * Operators in admin preview cannot fall back to another user's entry.
 * @param array<string,array<string,mixed>|mixed> $map
 */
function admin_resolve_display_registry_key(array $map, string $requested, ?callable $normalize = null): ?string
{
    $normalize ??= static fn($k) => admin_normalize_registry_key((string)$k);
    $resolved = admin_registry_resolve_key($map, $requested, $normalize);
    if ($resolved !== null) {
        return $resolved;
    }
    if ($map === []) {
        return null;
    }
    $want = $normalize($requested);
    if ($want !== null && $want !== '') {
        return null;
    }
    $first = array_key_first($map);

    return $first !== null ? (string)$first : null;
}

/**
 * @param array<string,array<string,mixed>|mixed> $registry
 * @return array<string,mixed>|null
 */
function admin_registry_find_entry(array $registry, string $key, ?callable $normalize = null): ?array
{
    $normalize ??= static fn($k) => admin_normalize_registry_key((string)$k);
    if (isset($registry[$key]) && is_array($registry[$key])) {
        return $registry[$key];
    }
    $want = $normalize($key);
    if ($want === null || $want === '') {
        return null;
    }
    if (isset($registry[$want]) && is_array($registry[$want])) {
        return $registry[$want];
    }
    foreach ($registry as $k => $v) {
        if (is_array($v) && $normalize((string)$k) === $want) {
            return $v;
        }
    }

    return null;
}

/**
 * @param array<string,array<string,mixed>|mixed> $registry
 */
function admin_registry_resolve_key(array $registry, string $key, ?callable $normalize = null): ?string
{
    $normalize ??= static fn($k) => admin_normalize_registry_key((string)$k);
    if (isset($registry[$key])) {
        return $key;
    }
    $want = $normalize($key);
    if ($want === null || $want === '') {
        return null;
    }
    if (isset($registry[$want])) {
        return $want;
    }
    foreach ($registry as $k => $_) {
        if ($normalize((string)$k) === $want) {
            return (string)$k;
        }
    }

    return null;
}

/** @param array<string,array<string,mixed>|mixed>|null $registry */
function admin_can_delete_registry_entry(?array $registry, string $key, ?callable $normalize = null): bool
{
    if (admin_is_super()) {
        return true;
    }
    if (!is_array($registry)) {
        return false;
    }
    $entry = admin_registry_find_entry($registry, $key, $normalize);
    if ($entry === null) {
        return false;
    }

    return admin_can_edit_entry($entry);
}

/**
 * Remove one keyed registry row. Operators only drop entries they own or that are shared with them.
 * @param array<string,array<string,mixed>|mixed> $registry
 * @return array<string,array<string,mixed>|mixed>
 */
function admin_remove_registry_entry(array $registry, string $key, ?callable $normalize = null): array
{
    $normalize ??= static fn($k) => admin_normalize_registry_key((string)$k);
    $resolved = admin_registry_resolve_key($registry, $key, $normalize);
    if ($resolved === null) {
        return $registry;
    }
    if (admin_is_super()) {
        unset($registry[$resolved]);

        return $registry;
    }
    $entry = $registry[$resolved];
    if (is_array($entry) && admin_entry_visible($entry)) {
        unset($registry[$resolved]);
    }

    return $registry;
}

/** @param array<string,array<string,mixed>|mixed>|null $registry */
function admin_can_delete_video_entry(?array $registry, string $key): bool
{
    return admin_can_delete_registry_entry($registry, $key);
}

/**
 * Remove one video registry row. Operators only drop entries they own or that are shared with them.
 * @param array<string,array<string,mixed>|mixed> $registry
 * @return array<string,array<string,mixed>|mixed>
 */
function admin_remove_video_from_registry(array $registry, string $key): array
{
    return admin_remove_registry_entry($registry, $key);
}

/**
 * Remove deck rows for a file. Operators only drop entries they own or that are shared with them.
 * @param list<array<string,mixed>> $deck
 * @return list<array<string,mixed>>
 */
function admin_remove_media_from_deck(array $deck, string $file, callable $safeName, callable $fileFromEntry): array
{
    $want = $safeName($file);
    if ($want === null || $want === '') {
        return $deck;
    }
    return array_values(array_filter($deck, static function ($entry) use ($want, $fileFromEntry) {
        if (!is_array($entry) || $fileFromEntry($entry) !== $want) {
            return true;
        }
        if (admin_is_super()) {
            return false;
        }

        return !admin_entry_visible($entry);
    }));
}

/** @param list<array<string,mixed>> $deck */
function admin_media_deck_references_file(array $deck, string $file, callable $safeName, callable $fileFromEntry): bool
{
    $want = $safeName($file);
    if ($want === null || $want === '') {
        return false;
    }
    foreach ($deck as $entry) {
        if (is_array($entry) && $fileFromEntry($entry) === $want) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function admin_media_rotation_sync_screens(): array
{
    require_once __DIR__ . '/rotation_lib.php';
    if (admin_is_super()) {
        return array_keys(rotation_screens());
    }

    return admin_filter_deploy_screens(admin_allowed_screen_keys());
}

function admin_can_board(string $board): bool
{
    $board = preg_replace('/[^a-z0-9_\-]/i', '', $board);
    if ($board === 'account') {
        return admin_is_authenticated();
    }
    if ($board === 'status') {
        return admin_is_authenticated() && admin_can_board('rotation');
    }
    if ($board === 'tools' || $board === 'users') {
        return admin_is_super();
    }
    if (admin_is_super()) {
        return true;
    }
    return in_array($board, ADMIN_OPERATOR_BOARDS, true);
}

function admin_default_board(): string
{
    if (admin_is_super()) {
        return 'security';
    }
    return 'rotation';
}

/** Redirect operators away from forbidden boards (sets $board by reference). */
function admin_enforce_board_access(string &$board, ?string &$flash, ?bool &$flashOk): void
{
    if (!admin_is_authenticated()) {
        return;
    }
    if ($board === 'tools' && !admin_can_tools()) {
        $board = admin_default_board();
        $flash = 'That section is restricted to super admins.';
        $flashOk = false;
        return;
    }
    if ($board === 'audit' && !admin_can_audit()) {
        $board = admin_default_board();
        $flash = 'That section is restricted to super admins.';
        $flashOk = false;
        return;
    }
    if ($board === 'users' && !admin_can_manage_users()) {
        $board = admin_default_board();
        $flash = 'That section is restricted to super admins.';
        $flashOk = false;
        return;
    }
    if ($board !== 'tools' && $board !== 'users' && $board !== 'audit' && !admin_can_board($board)) {
        $board = admin_default_board();
        $flash = 'You do not have access to that section.';
        $flashOk = false;
    }
}

/** @return array<string,mixed> */
function admin_filter_presence_dashboard(array $dashboard): array
{
    if (admin_is_super()) {
        return $dashboard;
    }
    $allowed = array_flip(admin_allowed_screen_keys());
    if (isset($dashboard['screens']) && is_array($dashboard['screens'])) {
        $dashboard['screens'] = array_intersect_key($dashboard['screens'], $allowed);
    }
    if (isset($dashboard['play_log']) && is_array($dashboard['play_log'])) {
        $dashboard['play_log'] = array_values(array_filter(
            $dashboard['play_log'],
            static fn($row) => is_array($row) && isset($allowed[(string)($row['screen'] ?? '')])
        ));
    }
    return $dashboard;
}

/** First-time setup: create the super admin account. */
function users_create_super(string $username, string $password): bool
{
    if (!users_need_setup()) {
        return false;
    }
    $username = users_normalize_username($username);
    if ($username === '' || strlen($password) < 8) {
        return false;
    }
    $data = [
        'users' => [[
            'id' => users_new_id(),
            'username' => $username,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'super',
            'screens' => [],
            'auth_provider' => 'local',
            'external_id' => null,
            'disabled' => false,
        ]],
    ];
    return users_save_raw($data);
}

function admin_change_own_password(string $currentPassword, string $newPassword): array
{
    $user = admin_current_user();
    if (!is_array($user)) {
        return ['ok' => false, 'error' => 'Not logged in.'];
    }
    return users_change_password((string)$user['id'], $currentPassword, $newPassword, true);
}

/** @return array{ok:bool,error?:string} */
function users_change_password(string $userId, string $currentOrNew, string $newPassword, bool $requireCurrent = false): array
{
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $result = ['ok' => false, 'error' => 'User not found.'];
    $saved = users_file_update(function (array $data) use ($userId, $currentOrNew, $newPassword, $requireCurrent, &$result): array|false {
        $users = $data['users'] ?? [];
        if (!is_array($users)) {
            $result = ['ok' => false, 'error' => 'No users configured.'];

            return false;
        }
        $found = false;
        foreach ($users as &$user) {
            if (!is_array($user) || (string)($user['id'] ?? '') !== $userId) {
                continue;
            }
            if ((string)($user['auth_provider'] ?? 'local') !== 'local') {
                $result = ['ok' => false, 'error' => 'SSO accounts cannot set a local password here.'];

                return false;
            }
            if ($requireCurrent) {
                $hash = (string)($user['hash'] ?? '');
                if ($hash === '' || !password_verify($currentOrNew, $hash)) {
                    $result = ['ok' => false, 'error' => 'Current password is wrong.'];

                    return false;
                }
            }
            $user['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $found = true;
            break;
        }
        unset($user);
        if (!$found) {
            $result = ['ok' => false, 'error' => 'User not found.'];

            return false;
        }
        $data['users'] = $users;
        $result = ['ok' => true];

        return $data;
    });

    return $saved ? $result : ['ok' => false, 'error' => 'Could not write users file.'];
}

/**
 * Save users from admin POST rows (super only).
 * @param list<array<string,mixed>> $rows
 * @return array{ok:bool,error?:string,count?:int}
 */
function users_save_from_post(array $rows): array
{
    if (!admin_can_manage_users()) {
        return ['ok' => false, 'error' => 'Permission denied.'];
    }

    $existing = users_by_id();
    $out = [];
    $usernames = [];
    $superCount = 0;
    $screenOwners = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            $id = users_new_id();
        }
        $username = users_normalize_username((string)($row['username'] ?? ''));
        if ($username === '') {
            continue;
        }
        if (isset($usernames[$username])) {
            return ['ok' => false, 'error' => 'Duplicate username: ' . $username];
        }
        $usernames[$username] = true;

        $role = users_normalize_role((string)($row['role'] ?? 'operator'));
        if ($role === 'super') {
            $superCount++;
        }

        $prev = $existing[$id] ?? null;
        $authProvider = strtolower(trim((string)($row['auth_provider'] ?? '')));
        if ($authProvider !== 'sso' && $authProvider !== 'local') {
            $authProvider = is_array($prev) ? (string)($prev['auth_provider'] ?? 'local') : 'local';
        }

        $entry = [
            'id' => $id,
            'username' => $username,
            'role' => $role,
            'screens' => $role === 'super' ? [] : users_screens_from_row($row),
            'auth_provider' => $authProvider,
            'external_id' => is_array($prev) ? ($prev['external_id'] ?? null) : null,
            'disabled' => !empty($row['disabled']),
        ];

        if ($role === 'operator') {
            $screens = $entry['screens'];
            if (count($screens) > USERS_OPERATOR_SCREEN_MAX) {
                return ['ok' => false, 'error' => 'Operator ' . $username . ' may have only one display.'];
            }
            if (count($screens) === 1) {
                $sk = $screens[0];
                if (isset($screenOwners[$sk])) {
                    return [
                        'ok' => false,
                        'error' => 'Display "' . $sk . '" is already assigned to ' . $screenOwners[$sk] . '.',
                    ];
                }
                $screenOwners[$sk] = $username;
            }
        }

        $newPassword = (string)($row['new_password'] ?? '');
        if ($authProvider === 'sso') {
            // SSO accounts authenticate via OIDC — no local password.
        } elseif ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                return ['ok' => false, 'error' => 'Passwords must be at least 8 characters.'];
            }
            $entry['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        } elseif (is_array($prev) && !empty($prev['hash'])) {
            $entry['hash'] = (string)$prev['hash'];
        } else {
            return ['ok' => false, 'error' => 'Set a password for new local user ' . $username . '.'];
        }

        $out[] = $entry;
    }

    if ($out === []) {
        return ['ok' => false, 'error' => 'At least one user is required.'];
    }
    if ($superCount < 1) {
        return ['ok' => false, 'error' => 'At least one super admin is required.'];
    }

    $saveError = null;
    if (!users_file_update(function (array $data) use ($out, &$saveError): array|false {
        $data['users'] = $out;

        return $data;
    })) {
        if (signage_json_last_error() === 'lock_timeout') {
            return ['ok' => false, 'error' => 'Another user save is in progress — wait a moment and try again.'];
        }

        return ['ok' => false, 'error' => 'Could not write users file.'];
    }

    $current = admin_current_user();
    if (is_array($current)) {
        $updated = users_find_by_id((string)$current['id']);
        if ($updated !== null) {
            admin_login_user(users_public_row($updated));
        }
    }

    return ['ok' => true, 'count' => count($out)];
}

/** @return list<array<string,mixed>> For admin UI */
function users_admin_rows(): array
{
    $rows = [];
    foreach (users_list() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $rows[] = [
            'id' => (string)($user['id'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'role' => users_normalize_role((string)($user['role'] ?? 'operator')),
            'screens' => array_slice(users_normalize_screens($user['screens'] ?? []), 0, USERS_OPERATOR_SCREEN_MAX),
            'disabled' => !empty($user['disabled']),
            'auth_provider' => (string)($user['auth_provider'] ?? 'local'),
            'external_id' => (string)($user['external_id'] ?? ''),
        ];
    }
    usort($rows, static fn($a, $b) => strcasecmp((string)$a['username'], (string)$b['username']));
    return $rows;
}

/** @param array<string,array<string,mixed>> $status */
function admin_filter_deploy_status(array $status): array
{
    if (admin_is_super()) {
        return $status;
    }
    $allowed = array_flip(admin_allowed_screen_keys());
    return array_intersect_key($status, $allowed);
}

/** Filter nav group keys to boards the current user may open. @return array<string,list<string>> */
function admin_filter_nav_groups(array $navGroups, array $schema): array
{
    $out = [];
    foreach ($navGroups as $label => $keys) {
        $filtered = [];
        foreach ($keys as $key) {
            if (!isset($schema[$key])) {
                continue;
            }
            if (admin_can_board($key)) {
                $filtered[] = $key;
            }
        }
        if ($filtered !== []) {
            $out[$label] = $filtered;
        }
    }
    return $out;
}
