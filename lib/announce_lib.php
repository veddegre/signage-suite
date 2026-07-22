<?php
/**
 * Announcements & countdown board — shared helpers for announce.php and admin.
 */

require_once dirname(__DIR__) . '/config.php';

function announce_normalize_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function announce_default_title(): string
{
    return (string)cfg('announce.BOARD_TITLE', 'Announcement');
}

function announce_default_sub(): string
{
    return (string)cfg('announce.BOARD_SUB', '');
}

/** @return array<string,array<string,mixed>> */
function announce_items_registry(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }
    $items = $rawConf['announce.ITEMS'] ?? [];
    if (!is_array($items) || $items === []) {
        return [];
    }
    require_once __DIR__ . '/users_lib.php';
    $filtered = admin_filter_registry_for_display($items);
    $out = [];
    foreach ($filtered as $key => $row) {
        $key = announce_normalize_key(is_string($key) ? $key : (string)($row['_key'] ?? ''));
        if ($key === '' || !is_array($row)) {
            continue;
        }
        $norm = announce_normalize_item($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function announce_normalize_item(array $row, string $key): ?array
{
    $title = trim((string)($row['title'] ?? ''));
    $body = trim((string)($row['body'] ?? ''));
    $mode = strtolower(trim((string)($row['mode'] ?? 'announcement')));
    if (!in_array($mode, ['announcement', 'countdown'], true)) {
        $mode = 'announcement';
    }
    $countdownUntil = trim((string)($row['countdown_until'] ?? ''));
    $startAt = trim((string)($row['start_at'] ?? ''));
    $endAt = trim((string)($row['end_at'] ?? ''));

    $out = [
        'mode' => $mode,
        'body' => $body,
    ];
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = announce_default_title();
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($countdownUntil !== '') {
        $out['countdown_until'] = $countdownUntil;
    }
    if ($startAt !== '') {
        $out['start_at'] = $startAt;
    }
    if ($endAt !== '') {
        $out['end_at'] = $endAt;
    }
    if (!empty($row['off'])) {
        $out['off'] = true;
    }
    if (!empty($row['strip_only'])) {
        $out['strip_only'] = true;
    }

    require_once __DIR__ . '/users_lib.php';

    return admin_merge_entry_access_meta($out, $row);
}

/** @return array<string,mixed> */
function announce_resolve_item(?string $itemKey = null): array
{
    $items = announce_items_registry();
    if ($items === []) {
        return ['key' => 'main', 'title' => 'Not available', 'body' => '', 'mode' => 'announcement'];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => announce_normalize_key((string)$k);
    $resolved = admin_resolve_display_registry_key($items, (string)($itemKey ?? ''), $normalize);
    if ($resolved === null || !isset($items[$resolved])) {
        return [
            'key' => announce_normalize_key((string)($itemKey ?? '')),
            'title' => 'Not available',
            'body' => '',
            'mode' => 'announcement',
        ];
    }

    return ['key' => $resolved] + $items[$resolved];
}

function announce_page_url(string $key): string
{
    return 'announce.php?d=' . rawurlencode(announce_normalize_key($key));
}

function announce_preview_url(?string $itemKey = null): string
{
    $key = announce_normalize_key($itemKey ?? '');
    if ($key === '') {
        $items = announce_items_registry();
        $key = (string)(array_key_first($items) ?: 'main');
    }

    return signage_board_preview_url(announce_page_url($key));
}

function announce_item_label(string $itemKey): string
{
    $items = announce_items_registry();
    $key = announce_normalize_key($itemKey);
    $item = $items[$key] ?? null;
    $title = is_array($item) ? trim((string)($item['title'] ?? '')) : '';

    return $title !== '' ? $title : $key;
}

/** @return list<array<string,mixed>> Active strip-only items for hero strip auto mode. */
function announce_strip_only_items(): array
{
    $out = [];
    foreach (announce_items_registry() as $key => $item) {
        if (!is_array($item) || empty($item['strip_only'])) {
            continue;
        }
        $out[] = ['key' => $key] + $item;
    }

    return $out;
}

function announce_timezone(): string
{
    $tz = trim((string)cfg('announce.TIMEZONE', cfg('rotation.TIMEZONE', 'America/Detroit')));

    return $tz !== '' ? $tz : 'America/Detroit';
}

/** @return DateTimeImmutable|null */
function announce_parse_datetime(string $raw, ?string $tz = null): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $tzObj = new DateTimeZone($tz ?? announce_timezone());
    $formats = ['Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw, $tzObj);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    try {
        return new DateTimeImmutable($raw, $tzObj);
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string,mixed> $item */
function announce_item_active(array $item, ?DateTimeImmutable $now = null): bool
{
    if (!empty($item['off'])) {
        return false;
    }
    $tz = announce_timezone();
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($tz));
    $start = announce_parse_datetime((string)($item['start_at'] ?? ''), $tz);
    if ($start !== null && $now < $start) {
        return false;
    }
    $end = announce_parse_datetime((string)($item['end_at'] ?? ''), $tz);
    if ($end !== null && $now > $end) {
        return false;
    }
    if (($item['mode'] ?? 'announcement') === 'countdown') {
        $target = announce_parse_datetime((string)($item['countdown_until'] ?? ''), $tz);
        if ($target === null) {
            return false;
        }
        if ($now > $target) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string,mixed> $item
 * @return array{days:int,hours:int,minutes:int,seconds:int,total:int,past:bool,label:string}
 */
function announce_countdown_parts(array $item, ?DateTimeImmutable $now = null): array
{
    $tz = announce_timezone();
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone($tz));
    $target = announce_parse_datetime((string)($item['countdown_until'] ?? ''), $tz);
    if ($target === null) {
        return ['days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0, 'total' => 0, 'past' => true, 'label' => 'Set countdown date'];
    }
    $total = $target->getTimestamp() - $now->getTimestamp();
    if ($total <= 0) {
        return ['days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0, 'total' => 0, 'past' => true, 'label' => 'Time reached'];
    }
    $days = intdiv($total, 86400);
    $hours = intdiv($total % 86400, 3600);
    $minutes = intdiv($total % 3600, 60);
    $seconds = $total % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . 'h';
    }
    $parts[] = $minutes . 'm';
    if ($days === 0) {
        $parts[] = $seconds . 's';
    }

    return [
        'days' => $days,
        'hours' => $hours,
        'minutes' => $minutes,
        'seconds' => $seconds,
        'total' => $total,
        'past' => false,
        'label' => implode(' ', $parts),
    ];
}

/** True when a rotation URL targets a countdown item with no target date configured. */
function announce_skip_rotation(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^announce\.php(?:\?|$)~i', $url)) {
        return false;
    }
    if (!preg_match('/[?&]d=([^&#]+)/i', $url, $m)) {
        return false;
    }
    $item = announce_resolve_item(rawurldecode($m[1]));
    if (($item['mode'] ?? '') !== 'countdown') {
        return false;
    }

    return announce_parse_datetime((string)($item['countdown_until'] ?? '')) === null;
}

/** @param array<string,mixed> $item */
function announce_wall_payload(array $item): array
{
    $mode = (string)($item['mode'] ?? 'announcement');
    $countdownReady = $mode !== 'countdown'
        || announce_parse_datetime((string)($item['countdown_until'] ?? '')) !== null;
    $active = $countdownReady && announce_item_active($item);
    $payload = [
        'ok' => $active,
        'active' => $active,
        'mode' => $mode,
        'title' => (string)($item['title'] ?? announce_default_title()),
        'body' => (string)($item['body'] ?? ''),
        'sub' => announce_default_sub(),
    ];
    if ($mode === 'countdown') {
        $payload['countdown'] = announce_countdown_parts($item);
        $target = announce_parse_datetime((string)($item['countdown_until'] ?? ''));
        $payload['countdown_until'] = $target ? $target->format(DateTimeInterface::ATOM) : '';
    }

    return $payload;
}
