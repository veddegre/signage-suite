<?php
/**
 * Site-wide emergency override — forced ticker, announcement, or playlist on all displays.
 */

require_once dirname(__DIR__) . '/config.php';

const EMERGENCY_MODES = ['ticker', 'announce', 'playlist'];

/** @return array<string,mixed> */
function emergency_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }
    $raw = $rawConf['rotation.EMERGENCY'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    return emergency_normalize_config($raw);
}

/** @param array<string,mixed> $raw */
function emergency_normalize_config(array $raw): array
{
    $mode = strtolower(trim((string)($raw['mode'] ?? '')));
    if (!in_array($mode, EMERGENCY_MODES, true)) {
        $mode = '';
    }
    $pages = [];
    foreach ($raw['pages'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $dwell = max(5, min(86400, (int)($row['dwell'] ?? 60)));
        $pages[] = ['url' => $url, 'dwell' => $dwell];
    }

    return [
        'active' => !empty($raw['active']) && $mode !== '',
        'mode' => $mode,
        'ticker_label' => trim((string)($raw['ticker_label'] ?? 'Emergency')),
        'ticker_text' => trim((string)($raw['ticker_text'] ?? '')),
        'ticker_show_weather' => !empty($raw['ticker_show_weather']),
        'announce_title' => trim((string)($raw['announce_title'] ?? 'Important notice')),
        'announce_body' => trim((string)($raw['announce_body'] ?? '')),
        'announce_key' => emergency_normalize_announce_key((string)($raw['announce_key'] ?? '')),
        'pages' => $pages,
        'expire_minutes' => max(0, min(10080, (int)($raw['expire_minutes'] ?? 0))),
        'expires_at' => max(0, (int)($raw['expires_at'] ?? 0)),
        'ntfy_notify' => !empty($raw['ntfy_notify']),
        'ntfy_topic' => strtolower(trim((string)($raw['ntfy_topic'] ?? ''))),
        'activated_at' => (int)($raw['activated_at'] ?? 0),
        'activated_by' => trim((string)($raw['activated_by'] ?? '')),
    ];
}

function emergency_normalize_announce_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key;
}

function emergency_active(): bool
{
    emergency_maybe_auto_release();
    $cfg = emergency_config();

    return !empty($cfg['active']);
}

/** Release when expires_at has passed. Returns true if released. */
function emergency_maybe_auto_release(): bool
{
    $cfg = emergency_config();
    if (empty($cfg['active'])) {
        return false;
    }
    $expires = (int)($cfg['expires_at'] ?? 0);
    if ($expires <= 0 || time() < $expires) {
        return false;
    }
    $snapshot = $cfg;
    if (!emergency_release(false, 'auto-expired')) {
        return false;
    }
    require_once __DIR__ . '/audit_lib.php';
    audit_log('emergency_release', 'Emergency override auto-expired');
    emergency_notify('expired', $snapshot, 'Auto-expired after scheduled duration');

    return true;
}

function emergency_ticker_show_weather(): bool
{
    if (!emergency_ticker_forces_display()) {
        return false;
    }
    $cfg = emergency_config();

    return !empty($cfg['ticker_show_weather']);
}

function emergency_mode(): string
{
    $cfg = emergency_config();
    if (empty($cfg['active'])) {
        return '';
    }

    return (string)($cfg['mode'] ?? '');
}

function emergency_ticker_forces_display(): bool
{
    return emergency_active() && emergency_mode() === 'ticker';
}

/** @return array{event:string,severity:string,kind:string,headline:string}|null */
function emergency_ticker_alert(): ?array
{
    if (!emergency_ticker_forces_display()) {
        return null;
    }
    $cfg = emergency_config();
    $text = trim((string)($cfg['ticker_text'] ?? ''));
    if ($text === '') {
        $text = 'Emergency message — configure text in admin → Rotation → Emergency override.';
    }
    $label = trim((string)($cfg['ticker_label'] ?? 'Emergency'));
    if ($label === '') {
        $label = 'Emergency';
    }

    return [
        'event' => $label,
        'severity' => 'Extreme',
        'kind' => 'warning',
        'headline' => $text,
    ];
}

/** @return array{title:string,body:string,sub:string} */
function emergency_announce_payload(): array
{
    $cfg = emergency_config();

    return [
        'title' => (string)($cfg['announce_title'] ?? 'Important notice'),
        'body' => (string)($cfg['announce_body'] ?? ''),
        'sub' => 'Emergency',
    ];
}

function emergency_announce_page_url(): string
{
    $cfg = emergency_config();
    $key = (string)($cfg['announce_key'] ?? '');
    if ($key !== '') {
        require_once __DIR__ . '/announce_lib.php';

        return announce_page_url($key);
    }

    return 'emergency.php';
}

/** @return list<array{url:string,dwell:int}> */
function emergency_playlist_pages(): array
{
    $cfg = emergency_config();
    $pages = is_array($cfg['pages'] ?? null) ? $cfg['pages'] : [];
    if ($pages !== []) {
        return $pages;
    }

    return [['url' => emergency_announce_page_url(), 'dwell' => 3600]];
}

/** Data folded into rotation_config_revision() so kiosks reload on activate/release/edit. */
function emergency_revision_blob(): array
{
    $cfg = emergency_config();

    return [
        'active' => !empty($cfg['active']),
        'mode' => (string)($cfg['mode'] ?? ''),
        'ticker' => (string)($cfg['ticker_text'] ?? ''),
        'ticker_weather' => !empty($cfg['ticker_show_weather']),
        'announce' => sha1(json_encode([
            (string)($cfg['announce_title'] ?? ''),
            (string)($cfg['announce_body'] ?? ''),
            (string)($cfg['announce_key'] ?? ''),
        ])),
        'pages' => sha1(json_encode($cfg['pages'] ?? [])),
        'expires_at' => (int)($cfg['expires_at'] ?? 0),
        'activated_at' => (int)($cfg['activated_at'] ?? 0),
    ];
}

/**
 * Apply site-wide emergency override to board.php runtime payload.
 * @param array<string,mixed> $runtime
 * @return array<string,mixed>
 */
function emergency_apply_runtime(array $runtime, string $screen = 'main'): array
{
    if (!emergency_active()) {
        return $runtime;
    }
    $mode = emergency_mode();
    if ($mode === 'ticker') {
        $runtime['show_ticker'] = true;
        $runtime['emergency'] = [
            'mode' => 'ticker',
            'show_weather' => emergency_ticker_show_weather(),
        ];

        return $runtime;
    }
    if ($mode === 'announce') {
        $url = emergency_announce_page_url();
        $runtime['pages'] = rotation_pages_labeled([
            ['url' => $url, 'dwell' => 3600],
        ]);
        $runtime['shuffle'] = false;
        $runtime['weighted'] = false;
        $runtime['blank'] = false;
        $runtime['hero_strip'] = ['enabled' => false, 'slots' => [], 'height' => 0];
        $runtime['emergency'] = ['mode' => 'announce', 'url' => $url];

        return $runtime;
    }
    if ($mode === 'playlist') {
        require_once __DIR__ . '/rotation_lib.php';
        $pages = emergency_playlist_pages();
        $runtime['pages'] = rotation_pages_labeled($pages);
        $runtime['shuffle'] = false;
        $runtime['weighted'] = false;
        $runtime['blank'] = false;
        $runtime['hero_strip'] = ['enabled' => false, 'slots' => [], 'height' => 0];
        $runtime['emergency'] = ['mode' => 'playlist', 'count' => count($pages)];

        return $runtime;
    }

    return $runtime;
}

/** @param array<string,mixed>|null $existing */
function emergency_config_from_post(array $post, ?array $existing = null): array
{
    $existing = emergency_normalize_config(is_array($existing) ? $existing : []);
    $mode = strtolower(trim((string)($post['EMERGENCY_MODE'] ?? $existing['mode'] ?? '')));
    if (!in_array($mode, EMERGENCY_MODES, true)) {
        $mode = (string)($existing['mode'] ?? '');
        if (!in_array($mode, EMERGENCY_MODES, true)) {
            $mode = 'ticker';
        }
    }
    $pages = emergency_pages_from_post($post['EMERGENCY_PAGES'] ?? []);
    if ($pages === [] && is_array($existing['pages'] ?? null)) {
        $pages = $existing['pages'];
    }

    $out = [
        'active' => !empty($existing['active']),
        'mode' => $mode,
        'ticker_label' => trim((string)($post['EMERGENCY_TICKER_LABEL'] ?? $existing['ticker_label'] ?? 'Emergency')),
        'ticker_text' => trim((string)($post['EMERGENCY_TICKER_TEXT'] ?? $existing['ticker_text'] ?? '')),
        'ticker_show_weather' => !empty($post['EMERGENCY_TICKER_SHOW_WEATHER']),
        'announce_title' => trim((string)($post['EMERGENCY_ANNOUNCE_TITLE'] ?? $existing['announce_title'] ?? 'Important notice')),
        'announce_body' => trim((string)($post['EMERGENCY_ANNOUNCE_BODY'] ?? $existing['announce_body'] ?? '')),
        'announce_key' => emergency_normalize_announce_key((string)($post['EMERGENCY_ANNOUNCE_KEY'] ?? $existing['announce_key'] ?? '')),
        'pages' => $pages,
        'expire_minutes' => max(0, min(10080, (int)($post['EMERGENCY_EXPIRE_MINUTES'] ?? $existing['expire_minutes'] ?? 0))),
        'expires_at' => (int)($existing['expires_at'] ?? 0),
        'ntfy_notify' => !empty($post['EMERGENCY_NTFY_NOTIFY']),
        'ntfy_topic' => strtolower(trim((string)($post['EMERGENCY_NTFY_TOPIC'] ?? $existing['ntfy_topic'] ?? ''))),
        'activated_at' => (int)($existing['activated_at'] ?? 0),
        'activated_by' => (string)($existing['activated_by'] ?? ''),
    ];

    if (empty($out['active'])) {
        unset($out['activated_at'], $out['activated_by'], $out['expires_at']);
    }

    return $out;
}

/** @return list<array{url:string,dwell:int}> */
function emergency_pages_from_post(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $out[] = [
            'url' => $url,
            'dwell' => max(5, min(86400, (int)($row['dwell'] ?? 60))),
        ];
    }

    return $out;
}

function emergency_validate_for_activate(array $cfg): ?string
{
    $mode = (string)($cfg['mode'] ?? '');
    if (!in_array($mode, EMERGENCY_MODES, true)) {
        return 'Choose an emergency mode.';
    }
    if ($mode === 'ticker' && trim((string)($cfg['ticker_text'] ?? '')) === '') {
        return 'Emergency ticker requires message text.';
    }
    if ($mode === 'announce') {
        $key = (string)($cfg['announce_key'] ?? '');
        if ($key === '' && trim((string)($cfg['announce_body'] ?? '')) === '') {
            return 'Emergency announcement needs a message body or an existing announcement key.';
        }
    }
    if ($mode === 'playlist' && ($cfg['pages'] ?? []) === []) {
        return 'Emergency playlist needs at least one page URL.';
    }

    return null;
}

/** @param array<string,mixed> $cfg Draft config (mode + content). */
function emergency_activate(array $cfg, ?string $username = null): bool
{
    $cfg = emergency_normalize_config($cfg);
    $err = emergency_validate_for_activate($cfg);
    if ($err !== null) {
        return false;
    }
    $cfg['active'] = true;
    $cfg['activated_at'] = time();
    $cfg['activated_by'] = trim((string)$username);
    $mins = (int)($cfg['expire_minutes'] ?? 0);
    $cfg['expires_at'] = $mins > 0 ? time() + ($mins * 60) : 0;

    if (!emergency_persist($cfg)) {
        return false;
    }
    emergency_notify('activated', $cfg);

    return true;
}

function emergency_release(bool $notify = true, string $reason = 'released'): bool
{
    $cfg = emergency_config();
    if (empty($cfg['active'])) {
        return true;
    }
    $snapshot = $cfg;
    $cfg['active'] = false;
    unset($cfg['activated_at'], $cfg['activated_by'], $cfg['expires_at']);
    if (!emergency_persist($cfg)) {
        return false;
    }
    if ($notify) {
        $event = $reason === 'auto-expired' ? 'expired' : 'released';
        $detail = $reason === 'auto-expired' ? 'Auto-expired after scheduled duration' : '';
        emergency_notify($event, $snapshot, $detail);
    }

    return true;
}

/** @param array<string,mixed> $cfg Snapshot (active or just-released). */
function emergency_notify(string $event, array $cfg, string $detail = ''): void
{
    if (empty($cfg['ntfy_notify'])) {
        return;
    }
    require_once __DIR__ . '/ntfy_lib.php';
    $topic = trim((string)($cfg['ntfy_topic'] ?? ''));
    if ($topic === '') {
        $topic = ntfy_poll_topic();
    }
    if ($topic === '') {
        return;
    }
    $title = match ($event) {
        'activated' => 'Emergency override activated',
        'released' => 'Emergency override released',
        'expired' => 'Emergency override expired',
        default => 'Emergency override',
    };
    if ($detail === '') {
        $detail = emergency_status_label_for_cfg($cfg);
        $by = trim((string)($cfg['activated_by'] ?? ''));
        if ($by !== '' && $event === 'activated') {
            $detail .= ' — by ' . $by;
        }
    }
    ntfy_publish($topic, $title, $detail, $event === 'activated' ? 5 : 3);
}

/** @param array<string,mixed> $cfg */
function emergency_persist(array $cfg): bool
{
    $cfg = emergency_normalize_config($cfg);
    if (empty($cfg['active'])) {
        $cfg['active'] = false;
    }

    return cfg_update(function (array $conf) use ($cfg): array {
        if ($cfg['active'] || ($cfg['ticker_text'] ?? '') !== '' || ($cfg['pages'] ?? []) !== []
            || ($cfg['announce_body'] ?? '') !== '') {
            $conf['rotation.EMERGENCY'] = $cfg;
        } else {
            unset($conf['rotation.EMERGENCY']);
        }

        return $conf;
    });
}

function emergency_blocks_operator_rotation(): bool
{
    if (!emergency_active()) {
        return false;
    }
    if (!function_exists('admin_is_super')) {
        require_once __DIR__ . '/users_lib.php';
    }

    return function_exists('admin_is_super') && !admin_is_super();
}

function emergency_status_label(): string
{
    return emergency_status_label_for_cfg(emergency_config());
}

/** @param array<string,mixed> $cfg */
function emergency_status_label_for_cfg(array $cfg): string
{
    if (empty($cfg['active'])) {
        return 'Inactive';
    }
    $mode = (string)($cfg['mode'] ?? '');

    return match ($mode) {
        'ticker' => 'Active — forced ticker on all displays',
        'announce' => 'Active — emergency announcement on all displays',
        'playlist' => 'Active — emergency playlist on all displays',
        default => 'Active',
    };
}

/** @return array{active:bool,label:string,by:string,activated_at:int,expires_at:int,mode:string} */
function emergency_status_detail(): array
{
    $cfg = emergency_config();

    return [
        'active' => !empty($cfg['active']),
        'label' => emergency_status_label_for_cfg($cfg),
        'by' => trim((string)($cfg['activated_by'] ?? '')),
        'activated_at' => (int)($cfg['activated_at'] ?? 0),
        'expires_at' => (int)($cfg['expires_at'] ?? 0),
        'mode' => (string)($cfg['mode'] ?? ''),
    ];
}
