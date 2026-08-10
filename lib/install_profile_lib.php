<?php
/**
 * Install profile — home (full) vs work (no homelab / meal-planning boards).
 *
 * Set site.PROFILE in settings.json (admin → Site). Loaded from config.php.
 */

/** Boards omitted on the work profile (admin nav, quick-add, rotation, direct URLs). */
const SIGNAGE_WORK_DISABLED_BOARDS = [
    'homelab',
    'unifi',
    'kuma',
    'tailscale',
    'ntfy',
    'meals',
    'tvguide',
];

/** @return 'home'|'work' */
function signage_install_profile(): string
{
    static $profile = null;
    if ($profile !== null) {
        return $profile;
    }
    $raw = strtolower(trim((string)cfg('site.PROFILE', 'home')));
    $profile = $raw === 'work' ? 'work' : 'home';

    return $profile;
}

function signage_install_profile_is_work(): bool
{
    return signage_install_profile() === 'work';
}

/** Map public .php filename (no extension) to admin schema board key. */
function signage_profile_file_to_board_key(string $file): string
{
    $file = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $file) ?? '');

    return match ($file) {
        'weather' => 'index',
        'family' => 'calendar',
        default => $file,
    };
}

/** Whether an admin schema board key is available on this install. */
function signage_profile_board_enabled(string $board): bool
{
    $board = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $board) ?? '');
    if ($board === '' || $board === 'site') {
        return true;
    }
    if (signage_install_profile() !== 'work') {
        return true;
    }

    return !in_array($board, SIGNAGE_WORK_DISABLED_BOARDS, true);
}

/** Whether a rotation playlist URL may play on this install. */
function signage_profile_rotation_url_allowed(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (signage_install_profile() !== 'work') {
        return true;
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? $url);
    $file = strtolower(basename($path));
    $file = preg_replace('/\.php$/', '', $file) ?? '';
    if ($file === '') {
        return true;
    }
    $board = signage_profile_file_to_board_key($file);

    return signage_profile_board_enabled($board);
}

/** Hero strip sources disabled on work profile. */
function signage_profile_hero_strip_source_enabled(string $source): bool
{
    $source = strtolower(trim($source));
    if (signage_install_profile() !== 'work') {
        return true;
    }

    return !in_array($source, ['kuma', 'ntfy'], true);
}

/** Scripts that never run the wall-board profile gate. */
function signage_profile_gate_exempt_script(string $basename): bool
{
    static $exempt = [
        'admin.php', 'board.php', 'player.php', 'ticker.php', 'index.php',
        'ntfy_webhook.php', 'grafana-jwks.php', 'camwall_img.php', 'webcam_img.php',
        'webcam_hls.php', 'traffic_tiles.php', 'emergency.php',
    ];

    return in_array(strtolower($basename), $exempt, true);
}

/** Block direct requests to profile-disabled wall boards (404 plain text). */
function signage_board_profile_gate(): void
{
    if ((defined('SIGNAGE_CLI') && SIGNAGE_CLI) || PHP_SAPI === 'cli') {
        return;
    }
    if (defined('SIGNAGE_PROFILE_GATE_OK')) {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script === '' || signage_profile_gate_exempt_script($script)) {
        return;
    }
    if (!str_ends_with(strtolower($script), '.php')) {
        return;
    }

    $file = preg_replace('/\.php$/', '', $script) ?? '';
    $board = signage_profile_file_to_board_key($file);
    if (signage_profile_board_enabled($board)) {
        return;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Board not available on this install.';
    exit;
}
