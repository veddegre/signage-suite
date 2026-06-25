<?php
/**
 * Zabbix monitoring board — shared helpers for zabbix.php and admin.
 * Uses Zabbix 7.x JSON-RPC (api_jsonrpc.php) with an API token server-side.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

function zabbix_normalize_page_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function zabbix_default_page_title(): string
{
    return (string)cfg('zabbix.BOARD_TITLE', 'Zabbix');
}

function zabbix_default_page_sub(): string
{
    return (string)cfg('zabbix.BOARD_SUB', 'Monitoring');
}

/** @return list<int> */
function zabbix_severity_options(): array
{
    return [0, 1, 2, 3, 4, 5];
}

function zabbix_severity_label(int $severity): string
{
    return match ($severity) {
        5 => 'Disaster',
        4 => 'High',
        3 => 'Average',
        2 => 'Warning',
        1 => 'Information',
        default => 'Not classified',
    };
}

function zabbix_severity_color(int $severity): string
{
    return match ($severity) {
        5 => '#e45959',
        4 => '#e97659',
        3 => '#ffa059',
        2 => '#ffc859',
        1 => '#7499ff',
        default => '#97aab3',
    };
}

/** @return list<int> */
function zabbix_severities_from_min(int $minSeverity): array
{
    $min = max(0, min(5, $minSeverity));
    $out = [];
    for ($s = 5; $s >= $min; $s--) {
        $out[] = $s;
    }

    return $out;
}

/**
 * @param array<string,mixed> $raw
 * @return array<string,array<string,mixed>>
 */
function zabbix_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $page) {
        $key = zabbix_normalize_page_key(is_string($key) ? $key : (string)($page['_key'] ?? ''));
        if ($key === '' || !is_array($page)) {
            continue;
        }
        $norm = zabbix_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function zabbix_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $hostGroups = zabbix_host_groups_string($page['host_groups'] ?? '');
    $minSeverity = max(0, min(5, (int)($page['min_severity'] ?? 2)));
    $maxProblems = max(1, min(50, (int)($page['max_problems'] ?? 12)));
    $maxHosts = max(1, min(100, (int)($page['max_hosts'] ?? 24)));

    $out = [
        'host_groups' => $hostGroups,
        'min_severity' => $minSeverity,
        'max_problems' => $maxProblems,
        'max_hosts' => $maxHosts,
    ];
    if (!empty($page['hide_acknowledged'])) {
        $out['hide_acknowledged'] = true;
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = zabbix_default_page_title();
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    } elseif ($key === 'main') {
        $out['sub'] = zabbix_default_page_sub();
    }

    return $out;
}

/**
 * @param array<string,mixed>|null $rawConf
 * @return array<string,array<string,mixed>>
 */
function zabbix_pages_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['zabbix.PAGES']) && is_array($rawConf['zabbix.PAGES']) && $rawConf['zabbix.PAGES'] !== []) {
        require_once __DIR__ . '/users_lib.php';
        $pagesRaw = admin_filter_registry_for_display($rawConf['zabbix.PAGES']);
        if ($pagesRaw === []) {
            return [];
        }
        $pages = zabbix_normalize_pages_registry($pagesRaw);
        if ($pages !== []) {
            return $pages;
        }
    }

    require_once __DIR__ . '/users_lib.php';
    if (admin_display_filter_active()) {
        return [];
    }

    return [
        'main' => zabbix_normalize_page([
            'title' => zabbix_default_page_title(),
            'sub' => zabbix_default_page_sub(),
            'host_groups' => '',
            'min_severity' => 2,
        ], 'main') ?? [],
    ];
}

/** @return array<string,array<string,mixed>> */
function zabbix_admin_pages(?array $rawConf = null): array
{
    $pages = zabbix_pages_config($rawConf);
    if ($pages === []) {
        return [
            'main' => zabbix_normalize_page([
                'title' => zabbix_default_page_title(),
                'sub' => zabbix_default_page_sub(),
                'host_groups' => '',
                'min_severity' => 2,
            ], 'main') ?? [],
        ];
    }

    return $pages;
}

/** @return array<string,mixed> */
function zabbix_resolve_page(?string $pageKey = null): array
{
    $pages = zabbix_pages_config();
    if ($pages === []) {
        return ['key' => 'main', 'title' => 'Not available', 'sub' => '', 'host_groups' => ''];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => zabbix_normalize_page_key((string)$k);
    $resolved = admin_resolve_display_registry_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        return [
            'key' => zabbix_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'host_groups' => '',
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

/** @param mixed $raw */
function zabbix_host_groups_string($raw): string
{
    if (is_array($raw)) {
        $parts = [];
        foreach ($raw as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $parts[] = $item;
            }
        }

        return implode(', ', $parts);
    }

    return trim((string)$raw);
}

/** @return list<string> */
function zabbix_parse_host_groups(string $raw): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $name) {
        $name = trim((string)$name);
        if ($name !== '') {
            $out[] = $name;
        }
    }

    return $out;
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function zabbix_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = zabbix_normalize_page_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = zabbix_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/**
 * @return array<string,array<string,mixed>>|null
 */
function zabbix_pages_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return null;
    }
    if ($dec === []) {
        return [];
    }

    $isList = array_keys($dec) === range(0, count($dec) - 1);
    if ($isList) {
        $pages = [];
        foreach ($dec as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = zabbix_normalize_page_key((string)($row['_key'] ?? $row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $norm = zabbix_normalize_page($row, $key);
            if ($norm !== null) {
                $pages[$key] = $norm;
            }
        }

        return $pages;
    }

    return zabbix_normalize_pages_registry($dec);
}

function zabbix_page_url(string $key): string
{
    return 'zabbix.php?d=' . rawurlencode(zabbix_normalize_page_key($key));
}

function zabbix_configured(): bool
{
    $token = trim((string)cfg('zabbix.ZABBIX_TOKEN', ''));
    $url = trim((string)cfg('zabbix.ZABBIX_URL', ''));

    return $token !== ''
        && $token !== 'PUT-YOUR-ZABBIX-API-TOKEN-HERE'
        && $url !== '';
}

function zabbix_base_url(): string
{
    return rtrim((string)cfg('zabbix.ZABBIX_URL', 'https://zabbix.example.com'), '/');
}

function zabbix_api_url(): string
{
    return zabbix_base_url() . '/api_jsonrpc.php';
}

function zabbix_verify_tls(): bool
{
    return (bool)cfg('zabbix.ZABBIX_VERIFY_TLS', false);
}

function zabbix_cache_ttl(): int
{
    return max(30, (int)cfg('zabbix.CACHE_TTL', 60));
}

function zabbix_preview_url(?string $pageKey = null): string
{
    $key = zabbix_normalize_page_key($pageKey ?? '');
    if ($key === '') {
        $pages = zabbix_pages_config();
        $key = (string)(array_key_first($pages) ?: 'main');
    }

    return signage_board_preview_url(zabbix_page_url($key));
}

function zabbix_page_label(string $pageKey): string
{
    $pages = zabbix_pages_config();
    $key = zabbix_normalize_page_key($pageKey);
    $page = $pages[$key] ?? null;
    $title = is_array($page) ? trim((string)($page['title'] ?? '')) : '';

    return $title !== '' ? $title : $key;
}

function zabbix_format_age(int $clock): string
{
    $delta = max(0, time() - $clock);
    if ($delta < 60) {
        return $delta . 's';
    }
    if ($delta < 3600) {
        return (int)floor($delta / 60) . 'm';
    }
    if ($delta < 86400) {
        return (int)floor($delta / 3600) . 'h';
    }

    return (int)floor($delta / 86400) . 'd';
}

/**
 * @param list<string> $groupNames
 * @return list<string>
 */
function zabbix_resolve_group_ids(array $groupNames, ?string &$error = null): array
{
    if ($groupNames === []) {
        return [];
    }

    $result = zabbix_api_call('hostgroup.get', [
        'output' => ['groupid', 'name'],
        'filter' => ['name' => $groupNames],
    ], $error);
    if (!is_array($result)) {
        return [];
    }

    $ids = [];
    foreach ($result as $row) {
        if (is_array($row) && isset($row['groupid'])) {
            $ids[] = (string)$row['groupid'];
        }
    }

    return array_values(array_unique($ids));
}

function zabbix_api_call(string $method, array $params, ?string &$error = null): mixed
{
    if (!zabbix_configured()) {
        $error = 'Zabbix URL and API token not configured';

        return null;
    }

    $policy = signage_fetch_url_allowed(zabbix_api_url(), signage_allow_private_fetch());
    if (!$policy['ok']) {
        $error = $policy['error'] ?? 'blocked URL';

        return null;
    }

    static $reqId = 0;
    $reqId++;
    $token = trim((string)cfg('zabbix.ZABBIX_TOKEN', ''));
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => $reqId,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $error = 'failed to encode request';

        return null;
    }

    $headers = ['Content-Type: application/json-rpc', 'Accept: application/json'];
    if ($token !== '' && !in_array($method, ['apiinfo.version', 'user.login'], true)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init(zabbix_api_url());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => zabbix_verify_tls(),
        CURLOPT_SSL_VERIFYHOST => zabbix_verify_tls() ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        $error = $curlErr !== '' ? 'curl: ' . $curlErr : 'HTTP ' . $code;

        return null;
    }

    $j = json_decode($body, true);
    if (!is_array($j)) {
        $error = 'invalid JSON response';

        return null;
    }
    if (isset($j['error']) && is_array($j['error'])) {
        $msg = trim((string)($j['error']['data'] ?? $j['error']['message'] ?? 'API error'));
        $error = $msg !== '' ? $msg : 'Zabbix API error';

        return null;
    }

    return $j['result'] ?? null;
}

/**
 * Attach host names to problem rows (Zabbix 7.0+ removed selectHosts from problem.get).
 *
 * @param list<array<string,mixed>> $problems
 * @return list<array<string,mixed>>
 */
function zabbix_attach_problem_hosts(array $problems, ?string &$error = null): array
{
    $eventIds = [];
    foreach ($problems as $problem) {
        if (!is_array($problem)) {
            continue;
        }
        $eid = trim((string)($problem['eventid'] ?? ''));
        if ($eid !== '') {
            $eventIds[$eid] = true;
        }
    }
    if ($eventIds === []) {
        return $problems;
    }

    $events = zabbix_api_call('event.get', [
        'output' => ['eventid'],
        'eventids' => array_keys($eventIds),
        'selectHosts' => ['name'],
    ], $error);
    if (!is_array($events)) {
        return $problems;
    }

    $hostsByEvent = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eid = (string)($event['eventid'] ?? '');
        if ($eid === '') {
            continue;
        }
        $hostsByEvent[$eid] = is_array($event['hosts'] ?? null) ? $event['hosts'] : [];
    }

    $out = [];
    foreach ($problems as $problem) {
        if (!is_array($problem)) {
            continue;
        }
        $eid = (string)($problem['eventid'] ?? '');
        $problem['hosts'] = $hostsByEvent[$eid] ?? [];

        $out[] = $problem;
    }

    return $out;
}

/** @param list<array<string,mixed>> $problems */
function zabbix_sort_problems(array $problems): array
{
    usort($problems, static function (array $a, array $b): int {
        $sev = (int)($b['severity'] ?? 0) <=> (int)($a['severity'] ?? 0);
        if ($sev !== 0) {
            return $sev;
        }
        $clock = (int)($b['clock'] ?? 0) <=> (int)($a['clock'] ?? 0);
        if ($clock !== 0) {
            return $clock;
        }

        return (int)($b['eventid'] ?? 0) <=> (int)($a['eventid'] ?? 0);
    });

    return $problems;
}

/**
 * Fetch problems + hosts for one page config (cached).
 *
 * @param array<string,mixed> $page
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   group_names:list<string>,
 *   group_ids:list<string>,
 *   problems:list<array<string,mixed>>,
 *   hosts:list<array<string,mixed>>,
 *   counts:array<int,int>
 * }
 */
function zabbix_fetch_wall_data(array $page): array
{
    $empty = [
        'ok' => false,
        'error' => null,
        'group_names' => [],
        'group_ids' => [],
        'problems' => [],
        'hosts' => [],
        'counts' => [],
    ];

    if (!zabbix_configured()) {
        $empty['error'] = 'Zabbix not configured';

        return $empty;
    }

    $groupNames = zabbix_parse_host_groups((string)($page['host_groups'] ?? ''));
    if ($groupNames === []) {
        $empty['error'] = 'No host groups configured for this page';

        return $empty;
    }

    $minSeverity = max(0, min(5, (int)($page['min_severity'] ?? 2)));
    $maxProblems = max(1, min(50, (int)($page['max_problems'] ?? 12)));
    $maxHosts = max(1, min(100, (int)($page['max_hosts'] ?? 24)));
    $hideAck = !empty($page['hide_acknowledged']);

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheKey = 'zabbix_' . md5(json_encode([
        $groupNames,
        $minSeverity,
        $maxProblems,
        $maxHosts,
        $hideAck,
    ]));
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    $ttl = zabbix_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $error = null;
    $groupIds = zabbix_resolve_group_ids($groupNames, $error);
    if ($groupIds === []) {
        $empty['error'] = $error ?: 'Host group(s) not found: ' . implode(', ', $groupNames);
        $empty['group_names'] = $groupNames;

        return $empty;
    }

    $problemParams = [
        'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged', 'opdata'],
        'groupids' => $groupIds,
        'severities' => zabbix_severities_from_min($minSeverity),
        'recent' => true,
        'limit' => $maxProblems,
        'suppressed' => false,
    ];
    if ($hideAck) {
        $problemParams['acknowledged'] = false;
    }

    $problems = zabbix_api_call('problem.get', $problemParams, $error);
    if (!is_array($problems)) {
        $empty['error'] = $error ?: 'problem.get failed';
        $empty['group_names'] = $groupNames;
        $empty['group_ids'] = $groupIds;

        return $empty;
    }
    $problems = zabbix_attach_problem_hosts($problems, $error);
    $problems = zabbix_sort_problems($problems);

    $hosts = zabbix_api_call('host.get', [
        'output' => ['hostid', 'name', 'status'],
        'groupids' => $groupIds,
        'sortfield' => 'name',
        'limit' => $maxHosts,
    ], $error);
    if (!is_array($hosts)) {
        $empty['error'] = $error ?: 'host.get failed';
        $empty['group_names'] = $groupNames;
        $empty['group_ids'] = $groupIds;
        $empty['problems'] = $problems;

        return $empty;
    }

    $problemHosts = [];
    foreach ($problems as $problem) {
        if (!is_array($problem)) {
            continue;
        }
        foreach ((array)($problem['hosts'] ?? []) as $hostRow) {
            if (is_array($hostRow) && ($hostRow['name'] ?? '') !== '') {
                $problemHosts[(string)$hostRow['name']] = true;
            }
        }
    }

    $hostRows = [];
    foreach ($hosts as $host) {
        if (!is_array($host)) {
            continue;
        }
        $name = (string)($host['name'] ?? '');
        $disabled = (string)($host['status'] ?? '0') === '1';
        $hasProblem = !$disabled && $name !== '' && isset($problemHosts[$name]);
        $hostRows[] = [
            'name' => $name,
            'disabled' => $disabled,
            'problem' => $hasProblem,
        ];
    }

    $counts = [];
    foreach (zabbix_severity_options() as $sev) {
        $counts[$sev] = 0;
    }
    foreach ($problems as $problem) {
        if (!is_array($problem)) {
            continue;
        }
        $sev = max(0, min(5, (int)($problem['severity'] ?? 0)));
        $counts[$sev] = ($counts[$sev] ?? 0) + 1;
    }

    $out = [
        'ok' => true,
        'error' => null,
        'group_names' => $groupNames,
        'group_ids' => $groupIds,
        'problems' => $problems,
        'hosts' => $hostRows,
        'counts' => $counts,
    ];

    @file_put_contents($cacheFile, json_encode($out), LOCK_EX);

    return $out;
}
