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
    'rotation', 'slides', 'rotator', 'video', 'rss',
    'splunk', 'grafana', 'splunkdash', 'web', 'family', 'homelab',
    'account',
];

/** Operators may edit board-level settings (paths, TTL) on these boards — not API secrets. */
const ADMIN_OPERATOR_SETTINGS_BOARDS = ['slides', 'rotator', 'video', 'rss'];

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

function users_save_raw(array $data): bool
{
    if (!is_dir(__DIR__ . '/config')) {
        @mkdir(__DIR__ . '/config', 0775, true);
    }
    return @file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
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
        'screens' => users_normalize_screens($user['screens'] ?? []),
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

/**
 * Local username/password login (SSO will add a parallel entry point later).
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

/** @return list<string> Screen keys this session may manage. Super = all displays. */
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
    $owner = admin_entry_owner($entry);
    if ($owner !== null && $owner === $uid) {
        return true;
    }
    return in_array($uid, admin_entry_shared_users($entry), true);
}

function admin_can_edit_entry(?array $entry): bool
{
    return admin_entry_visible($entry);
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

/** Super-admin: owner dropdown + shared-with checkboxes. */
function admin_entry_sharing_html(string $prefix, ?array $entry): void
{
    if (!admin_is_super()) {
        return;
    }
    $users = admin_sharing_user_options();
    if ($users === []) {
        return;
    }
    $owner = admin_entry_owner($entry);
    $sharedSet = array_flip(admin_entry_shared_users($entry));
    echo '<div class="entry-sharing">';
    echo '<input type="hidden" name="' . h($prefix . '[_sharing_form]') . '" value="1">';
    echo '<label class="mini">Owner</label>';
    echo '<select name="' . h($prefix . '[owner]') . '">';
    echo '<option value="">(none — super only)</option>';
    foreach ($users as $u) {
        echo '<option value="' . h($u['id']) . '"' . ($owner === $u['id'] ? ' selected' : '') . '>'
            . h($u['username']) . '</option>';
    }
    echo '</select>';
    echo '<span class="mini entry-sharing-shared-label">Also shared with</span>';
    echo '<div class="slide-screen-checks entry-sharing-users">';
    foreach ($users as $u) {
        if ($owner !== null && $u['id'] === $owner) {
            continue;
        }
        echo '<label><input type="checkbox" name="' . h($prefix . '[shared][]') . '" value="' . h($u['id']) . '"'
            . (isset($sharedSet[$u['id']]) ? ' checked' : '') . '> ' . h($u['username']) . '</label>';
    }
    echo '</div></div>';
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
 * @param list<array<string,mixed>> $deck
 */
function admin_filter_library_entries(array $entries, array $deck, callable $fileFromEntry): array
{
    if (admin_is_super()) {
        return $entries;
    }
    $ownedFiles = [];
    foreach ($deck as $entry) {
        if (!is_array($entry) || !admin_entry_visible($entry)) {
            continue;
        }
        $file = $fileFromEntry($entry);
        if ($file !== null && $file !== '') {
            $ownedFiles[$file] = true;
        }
    }
    return array_values(array_filter($entries, static function ($item) use ($ownedFiles) {
        $file = (string)($item['file'] ?? '');
        return $file !== '' && !empty($ownedFiles[$file]);
    }));
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
        return false;
    }
    foreach ($deck as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if ($fileFromEntry($entry) !== $want) {
            continue;
        }
        return admin_can_edit_entry($entry);
    }
    return false;
}

function admin_can_board(string $board): bool
{
    $board = preg_replace('/[^a-z0-9_\-]/i', '', $board);
    if ($board === 'account') {
        return admin_is_authenticated();
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
    if ($board === 'users' && !admin_can_manage_users()) {
        $board = admin_default_board();
        $flash = 'That section is restricted to super admins.';
        $flashOk = false;
        return;
    }
    if ($board !== 'tools' && $board !== 'users' && !admin_can_board($board)) {
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
    $data = users_load_raw();
    $users = $data['users'] ?? [];
    if (!is_array($users)) {
        return ['ok' => false, 'error' => 'No users configured.'];
    }
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    $found = false;
    foreach ($users as &$user) {
        if (!is_array($user) || (string)($user['id'] ?? '') !== $userId) {
            continue;
        }
        if ((string)($user['auth_provider'] ?? 'local') !== 'local') {
            return ['ok' => false, 'error' => 'SSO accounts cannot set a local password here.'];
        }
        if ($requireCurrent) {
            $hash = (string)($user['hash'] ?? '');
            if ($hash === '' || !password_verify($currentOrNew, $hash)) {
                return ['ok' => false, 'error' => 'Current password is wrong.'];
            }
        }
        $user['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $found = true;
        break;
    }
    unset($user);
    if (!$found) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    $data['users'] = $users;
    if (!users_save_raw($data)) {
        return ['ok' => false, 'error' => 'Could not write users file.'];
    }
    return ['ok' => true];
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
        $authProvider = is_array($prev) ? (string)($prev['auth_provider'] ?? 'local') : 'local';
        if ($authProvider !== 'local' && $authProvider !== 'sso') {
            $authProvider = 'local';
        }

        $entry = [
            'id' => $id,
            'username' => $username,
            'role' => $role,
            'screens' => $role === 'super' ? [] : users_normalize_screens($row['screens'] ?? []),
            'auth_provider' => $authProvider,
            'external_id' => is_array($prev) ? ($prev['external_id'] ?? null) : null,
            'disabled' => !empty($row['disabled']),
        ];

        $newPassword = (string)($row['new_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                return ['ok' => false, 'error' => 'Passwords must be at least 8 characters.'];
            }
            $entry['hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        } elseif (is_array($prev) && !empty($prev['hash'])) {
            $entry['hash'] = (string)$prev['hash'];
        } else {
            return ['ok' => false, 'error' => 'Set a password for new user ' . $username . '.'];
        }

        $out[] = $entry;
    }

    if ($out === []) {
        return ['ok' => false, 'error' => 'At least one user is required.'];
    }
    if ($superCount < 1) {
        return ['ok' => false, 'error' => 'At least one super admin is required.'];
    }

    if (!users_save_raw(['users' => $out])) {
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
            'screens' => users_normalize_screens($user['screens'] ?? []),
            'disabled' => !empty($user['disabled']),
            'auth_provider' => (string)($user['auth_provider'] ?? 'local'),
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
