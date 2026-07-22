<?php
/**
 * Persistent status strip for board.php — Kuma, Zabbix, announcements, ntfy.
 */

require_once dirname(__DIR__) . '/config.php';

/** @param array<string,mixed> $heroCfg from rotation_screen_settings()['hero_strip'] */
function hero_strip_render(array $heroCfg, string $screen = 'main'): array
{
    if (empty($heroCfg['enabled'])) {
        return ['enabled' => false, 'html' => '', 'height' => 0, 'class' => ''];
    }
    $height = max(60, min(240, (int)($heroCfg['height'] ?? 120)));
    $slots = hero_strip_normalize_slots($heroCfg);
    if ($slots === []) {
        return ['enabled' => true, 'html' => '', 'height' => $height, 'class' => 'empty', 'source' => ''];
    }

    $lines = [];
    foreach ($slots as $slot) {
        $source = (string)($slot['source'] ?? '');
        $key = trim((string)($slot['key'] ?? ''));
        $chunk = match ($source) {
            'kuma' => hero_strip_kuma_lines($key),
            'zabbix' => hero_strip_zabbix_lines($key),
            'announce' => hero_strip_announce_lines($key),
            'ntfy' => hero_strip_ntfy_lines($key),
            default => [],
        };
        foreach ($chunk as $line) {
            $lines[] = $line;
        }
    }
    if ($lines === []) {
        return ['enabled' => true, 'html' => '', 'height' => $height, 'class' => 'empty', 'source' => 'multi'];
    }

    $html = '';
    foreach ($lines as $line) {
        $cls = trim((string)($line['class'] ?? ''));
        $html .= '<span class="hero-strip-item' . ($cls !== '' ? ' ' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') : '') . '">'
            . htmlspecialchars((string)($line['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    return [
        'enabled' => true,
        'html' => $html,
        'height' => $height,
        'class' => count($slots) > 1 ? 'multi' : (string)($slots[0]['source'] ?? ''),
        'source' => count($slots) > 1 ? 'multi' : (string)($slots[0]['source'] ?? ''),
    ];
}

/** @param array<string,mixed> $heroCfg @return list<array{source:string,key:string}> */
function hero_strip_normalize_slots(array $heroCfg): array
{
    if (isset($heroCfg['slots']) && is_array($heroCfg['slots']) && $heroCfg['slots'] !== []) {
        require_once __DIR__ . '/rotation_lib.php';

        return rotation_hero_strip_slots_from_config($heroCfg['slots']);
    }
    $source = strtolower(trim((string)($heroCfg['source'] ?? '')));
    if (!in_array($source, ['kuma', 'zabbix', 'announce', 'ntfy'], true)) {
        return [];
    }

    return [['source' => $source, 'key' => trim((string)($heroCfg['key'] ?? ''))]];
}

/** @return array<string,list<array{value:string,label:string}>> */
function hero_strip_key_options(): array
{
    $out = [
        'kuma' => [['value' => '', 'label' => 'Default page']],
        'zabbix' => [['value' => '', 'label' => 'Default page']],
        'announce' => [['value' => '', 'label' => 'Default item']],
        'ntfy' => [['value' => '', 'label' => 'All recent alerts']],
    ];
    require_once __DIR__ . '/kuma_lib.php';
    require_once __DIR__ . '/users_lib.php';
    foreach (admin_filter_owned_map(kuma_admin_pages()) as $key => $page) {
        if (!is_array($page) || !empty($page['off'])) {
            continue;
        }
        $title = trim((string)($page['title'] ?? $key));
        $out['kuma'][] = ['value' => (string)$key, 'label' => $title . ' (' . $key . ')'];
    }
    require_once __DIR__ . '/zabbix_lib.php';
    foreach (admin_filter_owned_map(zabbix_admin_pages()) as $key => $page) {
        if (!is_array($page) || !empty($page['off'])) {
            continue;
        }
        $title = trim((string)($page['title'] ?? $key));
        $out['zabbix'][] = ['value' => (string)$key, 'label' => $title . ' (' . $key . ')'];
    }
    require_once __DIR__ . '/announce_lib.php';
    foreach (announce_items_registry() as $key => $item) {
        if (!is_array($item) || !empty($item['off'])) {
            continue;
        }
        $title = trim((string)($item['title'] ?? $key));
        $suffix = !empty($item['strip_only']) ? ' — strip only' : '';
        $out['announce'][] = ['value' => (string)$key, 'label' => $title . ' (' . $key . ')' . $suffix];
    }
    $out['announce'][] = ['value' => 'strip', 'label' => 'All active strip-only items'];
    require_once __DIR__ . '/ntfy_lib.php';
    $topic = ntfy_poll_topic();
    if ($topic !== '') {
        $out['ntfy'][] = ['value' => $topic, 'label' => 'Poll topic: ' . $topic];
    }

    return $out;
}

/** @return list<array{text:string,class?:string}> */
function hero_strip_kuma_lines(string $pageKey): array
{
    require_once __DIR__ . '/kuma_lib.php';
    $page = kuma_resolve_page_registry($pageKey !== '' ? $pageKey : null);
    if (empty($page['status_slug']) && !kuma_api_key_valid()) {
        return [['text' => 'Kuma strip — configure page slug or API key', 'class' => 'warn']];
    }
    $data = kuma_fetch_wall_data($page);
    $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
    $down = (int)($counts['down'] ?? 0);
    $up = (int)($counts['up'] ?? 0);
    $total = (int)($counts['total'] ?? 0);
    $lines = [
        ['text' => (string)($page['title'] ?? 'Uptime Kuma'), 'class' => 'label'],
        ['text' => $up . ' up · ' . $down . ' down · ' . $total . ' monitors', 'class' => $down > 0 ? 'bad' : 'ok'],
    ];
    if ($down > 0) {
        $monitors = is_array($data['monitors'] ?? null) ? $data['monitors'] : [];
        $names = [];
        foreach ($monitors as $mon) {
            if ((int)($mon['status'] ?? 0) === KUMA_STATUS_DOWN) {
                $names[] = (string)($mon['name'] ?? 'Down');
                if (count($names) >= 3) {
                    break;
                }
            }
        }
        if ($names !== []) {
            $lines[] = ['text' => 'Down: ' . implode(', ', $names), 'class' => 'bad'];
        }
    }

    return $lines;
}

/** @return list<array{text:string,class?:string}> */
function hero_strip_zabbix_lines(string $pageKey): array
{
    require_once __DIR__ . '/zabbix_lib.php';
    $page = zabbix_resolve_page_registry($pageKey !== '' ? $pageKey : null);
    if (!zabbix_configured()) {
        return [['text' => 'Zabbix strip — configure URL and token', 'class' => 'warn']];
    }
    $data = zabbix_fetch_wall_data($page);
    $problems = is_array($data['problems'] ?? null) ? $data['problems'] : [];
    $hosts = is_array($data['hosts'] ?? null) ? $data['hosts'] : [];
    $problemCount = count($problems);
    $hostCount = count($hosts);

    return [
        ['text' => (string)($page['title'] ?? 'Zabbix'), 'class' => 'label'],
        ['text' => $problemCount . ' problems · ' . $hostCount . ' hosts', 'class' => $problemCount > 0 ? 'bad' : 'ok'],
    ];
}

/** @return list<array{text:string,class?:string}> */
function hero_strip_announce_lines(string $itemKey): array
{
    require_once __DIR__ . '/announce_lib.php';
    if ($itemKey === '*' || $itemKey === 'strip') {
        return hero_strip_announce_strip_only_lines();
    }
    $item = announce_resolve_item($itemKey !== '' ? $itemKey : null);
    if (!announce_item_active($item)) {
        return [['text' => (string)($item['title'] ?? 'Announcement'), 'class' => 'muted']];
    }
    if (($item['mode'] ?? '') === 'countdown') {
        if (announce_parse_datetime((string)($item['countdown_until'] ?? '')) === null) {
            return [];
        }
        $cd = announce_countdown_parts($item);

        return [
            ['text' => (string)($item['title'] ?? 'Countdown'), 'class' => 'label'],
            ['text' => (string)$cd['label'], 'class' => !empty($cd['past']) ? 'warn' : 'ok'],
        ];
    }
    $body = trim((string)($item['body'] ?? ''));
    $text = (string)($item['title'] ?? 'Announcement');
    if ($body !== '') {
        $text .= ' — ' . (strlen($body) > 120 ? substr($body, 0, 117) . '…' : $body);
    }

    return [['text' => $text, 'class' => 'ok']];
}

/** @return list<array{text:string,class?:string}> */
function hero_strip_announce_strip_only_lines(): array
{
    require_once __DIR__ . '/announce_lib.php';
    $lines = [];
    foreach (announce_strip_only_items() as $item) {
        if (!announce_item_active($item)) {
            continue;
        }
        $chunk = hero_strip_announce_lines((string)($item['key'] ?? ''));
        foreach ($chunk as $line) {
            $lines[] = $line;
        }
        if (count($lines) >= 4) {
            break;
        }
    }
    if ($lines === []) {
        return [['text' => 'No active strip-only announcements', 'class' => 'muted']];
    }

    return $lines;
}

/** @return list<array{text:string,class?:string}> */
function hero_strip_ntfy_lines(string $topicKey = ''): array
{
    require_once __DIR__ . '/ntfy_lib.php';
    ntfy_poll_refresh_if_due();
    $recent = ntfy_recent_messages(5);
    $topicKey = trim($topicKey);
    if ($topicKey !== '') {
        $recent = array_values(array_filter($recent, static function ($msg) use ($topicKey) {
            if (!is_array($msg)) {
                return false;
            }
            $topic = trim((string)($msg['topic'] ?? ''));

            return $topic === '' || strcasecmp($topic, $topicKey) === 0;
        }));
    }
    if ($recent === []) {
        return [['text' => 'No recent ntfy alerts', 'class' => 'muted']];
    }
    $lines = [['text' => 'ntfy alerts', 'class' => 'label']];
    foreach ($recent as $msg) {
        $title = trim((string)($msg['title'] ?? $msg['message'] ?? ''));
        if ($title === '') {
            continue;
        }
        $prio = (int)($msg['priority'] ?? 3);
        $cls = $prio >= 4 ? 'bad' : ($prio <= 2 ? 'muted' : 'warn');
        $lines[] = ['text' => $title, 'class' => $cls];
        if (count($lines) >= 4) {
            break;
        }
    }

    return $lines;
}
