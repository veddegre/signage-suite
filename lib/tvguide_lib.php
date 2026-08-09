<?php
/**
 * TV Guide board — Schedules Direct JSON API (prime-time grid).
 *
 * @see https://github.com/SchedulesDirect/JSON-Service/wiki/API-20141201
 */

require_once dirname(__DIR__) . '/config.php';

const TVGUIDE_SD_URL = 'https://json.schedulesdirect.org/20141201';

function tvguide_normalize_page_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));

    return $key !== '' ? $key : 'main';
}

function tvguide_default_page_title(): string
{
    return (string)cfg('tvguide.BOARD_TITLE', 'TV Guide');
}

function tvguide_default_page_sub(): string
{
    return (string)cfg('tvguide.BOARD_SUB', 'Prime time');
}

function tvguide_timezone(): string
{
    return (string)cfg('tvguide.TIMEZONE', 'America/Detroit');
}

function tvguide_cache_ttl(): int
{
    return max(300, (int)cfg('tvguide.CACHE_TTL', 3600));
}

function tvguide_lineup_id(): string
{
    return tvguide_normalize_lineup_id((string)cfg('tvguide.LINEUP', ''));
}

/** Web UI uses MI21553:X — JSON API uses USA-MI21553-X. */
function tvguide_normalize_lineup_id(string $raw): string
{
    $raw = trim(urldecode($raw));
    if ($raw === '') {
        return '';
    }
    $raw = str_replace(':', '-', $raw);
    if (preg_match('/^[A-Z]{3}-/i', $raw)) {
        return strtoupper($raw);
    }
    if (preg_match('/^([A-Z0-9]+)-([A-Z0-9]+)$/i', $raw, $m)) {
        return 'USA-' . strtoupper($m[1]) . '-' . strtoupper($m[2]);
    }

    return strtoupper($raw);
}

/** Pick the configured lineup, matching account list when possible. */
function tvguide_resolved_lineup_id(?string &$error = null): string
{
    $want = tvguide_lineup_id();
    if ($want === '') {
        return '';
    }

    $lineups = tvguide_sd_lineups($error);
    if ($lineups === []) {
        return $want;
    }

    foreach ($lineups as $row) {
        $id = trim((string)($row['lineup'] ?? ''));
        if ($id === $want) {
            return $id;
        }
    }

    $slug = preg_replace('/^[A-Z]{3}-/i', '', $want);
    foreach ($lineups as $row) {
        $id = trim((string)($row['lineup'] ?? ''));
        if ($id !== '' && str_ends_with(strtoupper($id), strtoupper($slug))) {
            return $id;
        }
    }

    $ids = array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['lineup'] ?? '')),
        $lineups
    )));
    $error = 'Lineup ' . $want . ' is not on your account'
        . ($ids !== [] ? ' — use ' . implode(' or ', $ids) . ' in admin' : '');

    return $want;
}

/** @return list<string> */
function tvguide_parse_station_ids($raw): array
{
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $out = [];
    foreach ($parts as $part) {
        $id = trim((string)$part);
        if ($id !== '' && preg_match('/^\d+$/', $id)) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

function tvguide_stations_string($raw): string
{
    $ids = tvguide_parse_station_ids($raw);

    return $ids !== [] ? implode(', ', $ids) : '';
}

/** @return array<string,mixed>|null */
function tvguide_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $stations = tvguide_stations_string($page['stations'] ?? '');

    $out = [
        'stations' => $stations,
    ];
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = tvguide_default_page_title();
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    } elseif ($key === 'main') {
        $out['sub'] = tvguide_default_page_sub();
    }

    require_once __DIR__ . '/users_lib.php';

    return admin_merge_entry_access_meta($out, $page);
}

/** @return array<string,array<string,mixed>> */
function tvguide_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $key => $page) {
        $key = tvguide_normalize_page_key(is_string($key) ? $key : (string)($page['_key'] ?? ''));
        if ($key === '' || !is_array($page)) {
            continue;
        }
        $norm = tvguide_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @param array<string,mixed>|null $rawConf @return array<string,array<string,mixed>> */
function tvguide_pages_registry(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['tvguide.PAGES']) && is_array($rawConf['tvguide.PAGES']) && $rawConf['tvguide.PAGES'] !== []) {
        $pages = tvguide_normalize_pages_registry($rawConf['tvguide.PAGES']);
        if ($pages !== []) {
            return $pages;
        }
    }

    return [
        'main' => tvguide_normalize_page([
            'title' => tvguide_default_page_title(),
            'sub' => tvguide_default_page_sub(),
            'stations' => '',
        ], 'main') ?? [],
    ];
}

function tvguide_pages_config(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $rawConf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    }

    if (isset($rawConf['tvguide.PAGES']) && is_array($rawConf['tvguide.PAGES']) && $rawConf['tvguide.PAGES'] !== []) {
        require_once __DIR__ . '/users_lib.php';
        $pagesRaw = admin_filter_registry_for_display($rawConf['tvguide.PAGES']);
        if ($pagesRaw === []) {
            return [];
        }
        $pages = tvguide_normalize_pages_registry($pagesRaw);
        if ($pages !== []) {
            return $pages;
        }
    }

    require_once __DIR__ . '/users_lib.php';
    if (admin_display_filter_active()) {
        return [];
    }

    return [
        'main' => tvguide_normalize_page([
            'title' => tvguide_default_page_title(),
            'sub' => tvguide_default_page_sub(),
            'stations' => '',
        ], 'main') ?? [],
    ];
}

/** @return array<string,array<string,mixed>> */
function tvguide_admin_pages(?array $rawConf = null): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_registry_editor_pages(
        tvguide_pages_registry($rawConf),
        static function (): array {
            return [
                'main' => tvguide_normalize_page([
                    'title' => tvguide_default_page_title(),
                    'sub' => tvguide_default_page_sub(),
                    'stations' => '',
                ], 'main') ?? [],
            ];
        }
    );
}

/** @return array<string,mixed> */
function tvguide_resolve_page(?string $pageKey = null): array
{
    $pages = tvguide_pages_config();
    if ($pages === []) {
        return ['key' => 'main', 'title' => 'Not available', 'sub' => '', 'stations' => ''];
    }

    require_once __DIR__ . '/users_lib.php';
    $normalize = static fn($k) => tvguide_normalize_page_key((string)$k);
    $resolved = admin_resolve_display_registry_key($pages, (string)($pageKey ?? ''), $normalize);
    if ($resolved === null || !isset($pages[$resolved])) {
        return [
            'key' => tvguide_normalize_page_key((string)($pageKey ?? '')),
            'title' => 'Not available',
            'sub' => '',
            'stations' => '',
        ];
    }

    return ['key' => $resolved] + $pages[$resolved];
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function tvguide_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = tvguide_normalize_page_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        if (isset($row['stations']) && is_array($row['stations'])) {
            $row['stations'] = tvguide_stations_string($row['stations']);
        }
        $norm = tvguide_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,array<string,mixed>>|null */
function tvguide_pages_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return null;
    }

    return tvguide_normalize_pages_registry($parsed);
}

function tvguide_page_url(string $key): string
{
    $key = tvguide_normalize_page_key($key);

    return $key === 'main' ? 'tvguide.php' : ('tvguide.php?d=' . rawurlencode($key));
}

function tvguide_preview_url(?string $pageKey = null): string
{
    require_once __DIR__ . '/rotation_lib.php';
    $page = tvguide_resolve_page($pageKey);

    return signage_board_preview_url(tvguide_page_url((string)($page['key'] ?? 'main')));
}

function tvguide_page_label(string $pageKey): string
{
    $page = tvguide_resolve_page($pageKey);
    $title = trim((string)($page['title'] ?? $pageKey));

    return $title !== '' ? $title : $pageKey;
}

function tvguide_configured(): bool
{
    $user = trim((string)cfg('tvguide.SD_USERNAME', ''));
    $pass = trim((string)cfg('tvguide.SD_PASSWORD', ''));

    return $user !== '' && $pass !== '';
}

function tvguide_sd_username(): string
{
    return trim((string)cfg('tvguide.SD_USERNAME', ''));
}

function tvguide_sd_password_plain(): string
{
    return trim((string)cfg('tvguide.SD_PASSWORD', ''));
}

function tvguide_sd_password_sha1(): string
{
    $pass = tvguide_sd_password_plain();
    if ($pass === '') {
        return '';
    }
    if (preg_match('/^[0-9a-f]{40}$/i', $pass)) {
        return strtolower($pass);
    }

    return sha1($pass);
}

function tvguide_cache_dir(): string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function tvguide_token_cache_file(): string
{
    return tvguide_cache_dir() . '/tvguide_sd_token.json';
}

function tvguide_sd_get_token(?string &$error = null, bool $forceRefresh = false): ?string
{
    $cacheFile = tvguide_token_cache_file();
    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['token']) && (int)($cached['expires'] ?? 0) > time() + 60) {
            return (string)$cached['token'];
        }
    }

    if (!tvguide_configured()) {
        $error = 'Schedules Direct username and password not configured';

        return null;
    }

    $resp = tvguide_sd_http('POST', '/token', [
        'username' => tvguide_sd_username(),
        'password' => tvguide_sd_password_sha1(),
    ]);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        $error = tvguide_sd_error_text($resp, 'Token request failed');

        return null;
    }
    $token = trim((string)($resp['body']['token'] ?? ''));
    if ($token === '') {
        $error = 'Schedules Direct did not return a token';

        return null;
    }

    @file_put_contents($cacheFile, json_encode([
        'token' => $token,
        'expires' => time() + 86400,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $token;
}

/**
 * @param array<string,mixed>|null $body
 * @param list<string> $headers
 * @return array{body:mixed,code:int,err:string,ms:int}
 */
function tvguide_sd_http(string $method, string $path, ?array $body = null, array $headers = [], int $timeout = 30): array
{
    $url = rtrim(TVGUIDE_SD_URL, '/') . $path;
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => $policy['error'] ?? 'blocked URL', 'ms' => 0];
    }

    $method = strtoupper($method);
    $reqHeaders = array_merge([
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
        'User-Agent: HomeSignage/TVGuide/1.0',
    ], $headers);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $trim = ltrim($raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($raw, true);
        }
    }

    return [
        'body' => $decoded !== null ? $decoded : $raw,
        'code' => $code,
        'err' => $err,
        'ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
}

/** @param array{body:mixed,code:int,err:string,ms:int} $resp */
function tvguide_sd_error_text(array $resp, string $fallback): string
{
    if ($resp['err'] !== '') {
        return $resp['err'];
    }
    if (is_array($resp['body'])) {
        foreach (['message', 'error', 'response'] as $k) {
            if (!empty($resp['body'][$k]) && is_string($resp['body'][$k])) {
                return (string)$resp['body'][$k];
            }
        }
    }

    return $fallback . ' (HTTP ' . (int)$resp['code'] . ')';
}

/**
 * @return array{body:mixed,code:int,err:string,ms:int}
 */
function tvguide_sd_api(string $method, string $path, ?array $body = null, int $timeout = 45, ?string &$error = null): array
{
    $token = tvguide_sd_get_token($error);
    if ($token === null) {
        return ['body' => false, 'code' => 0, 'err' => $error ?? 'auth failed', 'ms' => 0];
    }

    $resp = tvguide_sd_http($method, $path, $body, ['token: ' . $token], $timeout);
    if ($resp['code'] === 401 || $resp['code'] === 403) {
        $token = tvguide_sd_get_token($error, true);
        if ($token === null) {
            return $resp;
        }
        $resp = tvguide_sd_http($method, $path, $body, ['token: ' . $token], $timeout);
    }

    return $resp;
}

/** @return list<mixed> */
function tvguide_sd_rows(mixed $body, string $listKey = 'lineups'): array
{
    if (!is_array($body)) {
        return [];
    }
    if (isset($body[$listKey]) && is_array($body[$listKey])) {
        return array_values($body[$listKey]);
    }
    if (array_is_list($body)) {
        return $body;
    }

    return [];
}

function tvguide_map_row_channel(array $mapRow): string
{
    $channel = trim((string)($mapRow['channel'] ?? $mapRow['virtualChannel'] ?? ''));
    if ($channel !== '') {
        return $channel;
    }
    $major = (int)($mapRow['atscMajor'] ?? $mapRow['channelMajor'] ?? 0);
    $minor = (int)($mapRow['atscMinor'] ?? $mapRow['channelMinor'] ?? 0);
    if ($major > 0) {
        return $minor > 0 ? ($major . '.' . $minor) : (string)$major;
    }

    return '';
}

/** @return list<array<string,mixed>> */
function tvguide_sd_lineups(?string &$error = null, bool $forceRefresh = false): array
{
    $cacheFile = tvguide_cache_dir() . '/tvguide_sd_lineups.json';
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $resp = tvguide_sd_api('GET', '/lineups', null, 30, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        $error = tvguide_sd_error_text($resp, 'Could not load lineups');
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        return [];
    }

    $out = [];
    foreach (tvguide_sd_rows($resp['body'], 'lineups') as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lineup = trim((string)($row['lineup'] ?? ''));
        if ($lineup === '') {
            continue;
        }
        $out[] = [
            'lineup' => $lineup,
            'name' => trim((string)($row['name'] ?? $lineup)),
            'location' => trim((string)($row['location'] ?? '')),
            'transport' => trim((string)($row['transport'] ?? '')),
        ];
    }
    @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $out;
}

/**
 * @return array{map:array<string,array<string,mixed>>,channels:list<array<string,mixed>>}
 */
function tvguide_sd_lineup_channels(string $lineup, ?string &$error = null, bool $forceRefresh = false): array
{
    $lineup = tvguide_normalize_lineup_id($lineup);
    if ($lineup === '') {
        $error = 'Lineup not set';

        return ['map' => [], 'channels' => []];
    }

    $cacheFile = tvguide_cache_dir() . '/tvguide_lineup_' . md5($lineup) . '.json';
    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['channels']) && is_array($cached['channels'])) {
            return [
                'map' => is_array($cached['map'] ?? null) ? $cached['map'] : [],
                'channels' => $cached['channels'],
            ];
        }
    }

    $resp = tvguide_sd_api('GET', '/lineups/' . rawurlencode($lineup), null, 45, $error);
    if ($resp['code'] === 404) {
        $error = 'Lineup not on your account — add it at schedulesdirect.org first, then paste the Lineup ID here';
    }
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        $error = $error ?? tvguide_sd_error_text($resp, 'Could not load lineup channels');
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['channels'])) {
                return [
                    'map' => is_array($cached['map'] ?? null) ? $cached['map'] : [],
                    'channels' => (array)$cached['channels'],
                ];
            }
        }

        return ['map' => [], 'channels' => []];
    }

    $stations = [];
    foreach ((array)($resp['body']['stations'] ?? []) as $st) {
        if (!is_array($st)) {
            continue;
        }
        $sid = trim((string)($st['stationID'] ?? ''));
        if ($sid !== '') {
            $stations[$sid] = $st;
        }
    }

    $channels = [];
    foreach ((array)($resp['body']['map'] ?? []) as $mapRow) {
        if (!is_array($mapRow)) {
            continue;
        }
        $sid = trim((string)($mapRow['stationID'] ?? ''));
        if ($sid === '' || !isset($stations[$sid])) {
            continue;
        }
        $st = $stations[$sid];
        $channelNum = tvguide_map_row_channel($mapRow);
        $callsign = trim((string)($st['callsign'] ?? ''));
        $name = trim((string)($st['name'] ?? $callsign));
        $affiliate = trim((string)($st['affiliate'] ?? ''));
        $entry = [
            'station_id' => $sid,
            'channel' => $channelNum,
            'callsign' => $callsign,
            'name' => $name,
            'affiliate' => $affiliate,
            'logo' => trim((string)($st['logo']['URL'] ?? '')),
        ];
        $entry['label'] = tvguide_channel_admin_label($entry);
        $channels[] = $entry;
    }

    $channels = tvguide_sort_channels($channels);

    $map = [];
    foreach ($channels as $ch) {
        $map[(string)$ch['station_id']] = $ch;
    }

    $payload = ['map' => $map, 'channels' => $channels];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $payload;
}

/** @return list<array<string,mixed>> */
function tvguide_lineup_channels_for_admin(?string &$error = null): array
{
    $lineup = tvguide_resolved_lineup_id($error);
    if ($lineup === '') {
        $error = 'Set a lineup under Board settings';

        return [];
    }

    return tvguide_sd_lineup_channels($lineup, $error)['channels'];
}

function tvguide_parse_time(string $raw, int $defaultHour, int $defaultMinute = 0): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [$defaultHour, $defaultMinute];
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
        return [max(0, min(23, (int)$m[1])), max(0, min(59, (int)$m[2]))];
    }
    if (preg_match('/^(\d{1,2})$/', $raw, $m)) {
        return [max(0, min(23, (int)$m[1])), 0];
    }

    return [$defaultHour, $defaultMinute];
}

/** @return array{start:DateTimeImmutable,end:DateTimeImmutable,date:DateTimeImmutable,hours:list<int>,label:string} */
function tvguide_prime_window(?DateTimeZone $tz = null): array
{
    $tz ??= new DateTimeZone(tvguide_timezone());
    $now = new DateTimeImmutable('now', $tz);
    [$startH, $startM] = tvguide_parse_time((string)cfg('tvguide.PRIME_START', '19:00'), 19, 0);
    [$endH, $endM] = tvguide_parse_time((string)cfg('tvguide.PRIME_END', '23:00'), 23, 0);

    $date = $now->setTime(0, 0);
    $primeStart = $date->setTime($startH, $startM);
    $primeEnd = $date->setTime($endH, $endM);
    if ($primeEnd <= $primeStart) {
        $primeEnd = $primeEnd->modify('+1 day');
    }

    if ($now >= $primeEnd) {
        $date = $date->modify('+1 day');
        $primeStart = $date->setTime($startH, $startM);
        $primeEnd = $date->setTime($endH, $endM);
        if ($primeEnd <= $primeStart) {
            $primeEnd = $primeEnd->modify('+1 day');
        }
    }

    $hours = [];
    $cursor = $primeStart;
    while ($cursor < $primeEnd) {
        $hours[] = (int)$cursor->format('G');
        $cursor = $cursor->modify('+1 hour');
    }
    if ($hours === []) {
        $hours[] = $startH;
    }

    $label = $primeStart->format('g') . ($primeStart->format('i') !== '00' ? ':' . $primeStart->format('i') : '')
        . $primeStart->format('a') . '–'
        . $primeEnd->format('g') . ($primeEnd->format('i') !== '00' ? ':' . $primeEnd->format('i') : '')
        . $primeEnd->format('a');

    return [
        'start' => $primeStart,
        'end' => $primeEnd,
        'date' => $date,
        'hours' => $hours,
        'label' => $label,
    ];
}

function tvguide_program_title(array $program): string
{
    foreach ((array)($program['titles'] ?? []) as $ttl) {
        if (!is_array($ttl)) {
            continue;
        }
        $t = trim((string)($ttl['title120'] ?? $ttl['title60'] ?? $ttl['title'] ?? ''));
        if ($t !== '') {
            return $t;
        }
    }

    return 'Unknown';
}

function tvguide_program_subtitle(array $program): string
{
    $ep = trim((string)($program['episodeTitle150'] ?? $program['episodeTitle'] ?? ''));
    if ($ep !== '') {
        return $ep;
    }
    if (!empty($program['eventDetails']['gameDate'])) {
        return trim((string)$program['eventDetails']['gameDate']);
    }

    return '';
}

/**
 * @param list<string> $stationIds
 * @return array<string,list<array<string,mixed>>>
 */
function tvguide_fetch_schedules_for_date(array $stationIds, string $dateYmd, ?string &$error = null): array
{
    if ($stationIds === []) {
        return [];
    }

    $query = [[
        'stationID' => $stationIds[0],
        'date' => [$dateYmd],
    ]];
    if (count($stationIds) > 1) {
        $query = array_map(static fn(string $sid): array => [
            'stationID' => $sid,
            'date' => [$dateYmd],
        ], $stationIds);
    }

    $resp = tvguide_sd_api('POST', '/schedules', $query, 60, $error);
    if ($resp['code'] !== 200 || !is_array($resp['body'])) {
        $error = tvguide_sd_error_text($resp, 'Schedule request failed');

        return [];
    }

    $out = [];
    foreach ($resp['body'] as $block) {
        if (!is_array($block)) {
            continue;
        }
        $sid = trim((string)($block['stationID'] ?? ''));
        if ($sid === '') {
            continue;
        }
        $out[$sid] = is_array($block['programs'] ?? null) ? $block['programs'] : [];
    }

    return $out;
}

/**
 * @param list<string> $programIds
 * @return array<string,array<string,mixed>>
 */
function tvguide_fetch_programs(array $programIds, ?string &$error = null): array
{
    $programIds = array_values(array_unique(array_filter($programIds, static fn($id) => is_string($id) && $id !== '')));
    if ($programIds === []) {
        return [];
    }

    $out = [];
    $chunks = array_chunk($programIds, 500);
    foreach ($chunks as $chunk) {
        $resp = tvguide_sd_api('POST', '/programs', $chunk, 60, $error);
        if ($resp['code'] !== 200 || !is_array($resp['body'])) {
            $error = tvguide_sd_error_text($resp, 'Program request failed');

            return $out;
        }
        foreach ($resp['body'] as $pgm) {
            if (!is_array($pgm)) {
                continue;
            }
            $pid = trim((string)($pgm['programID'] ?? ''));
            if ($pid !== '') {
                $out[$pid] = $pgm;
            }
        }
    }

    return $out;
}

/**
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   date_label:string,
 *   prime_label:string,
 *   hours:list<int>,
 *   rows:list<array<string,mixed>>,
 *   cache_age:int
 * }
 */
function tvguide_fetch_grid_data(array $page): array
{
    $empty = [
        'ok' => false,
        'error' => null,
        'date_label' => '',
        'prime_label' => '',
        'hours' => [],
        'rows' => [],
        'cache_age' => 0,
    ];

    if (!tvguide_configured()) {
        $empty['error'] = 'Schedules Direct not configured';

        return $empty;
    }

    $lineup = tvguide_resolved_lineup_id($error);
    if ($lineup === '') {
        $empty['error'] = 'Set a lineup in admin';

        return $empty;
    }

    $stationIds = tvguide_parse_station_ids($page['stations'] ?? '');
    if ($stationIds === []) {
        $empty['error'] = 'Select channels for this page';

        return $empty;
    }

    $tz = new DateTimeZone(tvguide_timezone());
    $window = tvguide_prime_window($tz);
    $dateYmd = $window['date']->format('Y-m-d');
    $cacheKey = 'tvguide_grid_' . md5(json_encode([
        $lineup,
        $page['key'] ?? '',
        $stationIds,
        $dateYmd,
        (string)cfg('tvguide.PRIME_START', '19:00'),
        (string)cfg('tvguide.PRIME_END', '23:00'),
        tvguide_timezone(),
    ]));
    $cacheFile = tvguide_cache_dir() . '/' . $cacheKey . '.json';
    $ttl = tvguide_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['cache_age'] = time() - filemtime($cacheFile);

            return $cached;
        }
    }

    $error = null;
    $lineupData = tvguide_sd_lineup_channels($lineup, $error);
    $channelMap = $lineupData['map'];

    $schedules = tvguide_fetch_schedules_for_date($stationIds, $dateYmd, $error);
    if ($schedules === [] && $error !== null) {
        $empty['error'] = $error;

        return tvguide_stale_grid_data($empty, $cacheFile, $error);
    }

    $programIds = [];
    foreach ($schedules as $programs) {
        foreach ($programs as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $pid = trim((string)($slot['programID'] ?? ''));
            if ($pid !== '') {
                $programIds[] = $pid;
            }
        }
    }
    $programs = tvguide_fetch_programs($programIds, $error);

    $primeStart = $window['start'];
    $primeEnd = $window['end'];
    $hours = $window['hours'];
    $rows = [];

    foreach ($stationIds as $sid) {
        if (!isset($channelMap[$sid])) {
            continue;
        }
        $ch = $channelMap[$sid];
        $cells = [];
        foreach ($hours as $hour) {
            $cells[(string)$hour] = null;
        }

        foreach ((array)($schedules[$sid] ?? []) as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $pid = trim((string)($slot['programID'] ?? ''));
            $air = trim((string)($slot['airDateTime'] ?? ''));
            $duration = max(0, (int)($slot['duration'] ?? 0));
            if ($air === '' || $duration <= 0) {
                continue;
            }
            try {
                $start = new DateTimeImmutable($air);
                $start = $start->setTimezone($tz);
            } catch (Exception) {
                continue;
            }
            $end = $start->modify('+' . $duration . ' seconds');
            if ($end <= $primeStart || $start >= $primeEnd) {
                continue;
            }

            $pgm = $programs[$pid] ?? [];
            $title = tvguide_program_title($pgm);
            $subtitle = tvguide_program_subtitle($pgm);
            $hourKey = (string)(int)$start->format('G');
            if (!array_key_exists($hourKey, $cells)) {
                continue;
            }
            if ($cells[$hourKey] !== null) {
                continue;
            }
            $cells[$hourKey] = [
                'title' => $title,
                'subtitle' => $subtitle,
                'start' => $start->format('g:i'),
                'end' => $end->format('g:i'),
                'duration_min' => (int)round($duration / 60),
            ];
        }

        $rows[] = [
            'station_id' => $sid,
            'channel' => (string)($ch['channel'] ?? ''),
            'callsign' => (string)($ch['callsign'] ?? ''),
            'name' => (string)($ch['name'] ?? ''),
            'affiliate' => (string)($ch['affiliate'] ?? ''),
            'label' => (string)($ch['label'] ?? $sid),
            'logo' => (string)($ch['logo'] ?? ''),
            'cells' => $cells,
        ];
    }

    $rows = tvguide_sort_grid_rows($rows);

    $payload = [
        'ok' => $rows !== [],
        'error' => $rows !== [] ? null : 'No listings in prime time for selected channels',
        'date_label' => $window['date']->format('l, M j'),
        'prime_label' => $window['label'],
        'hours' => $hours,
        'rows' => $rows,
        'cache_age' => 0,
    ];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $payload;
}

/** @return array<string,mixed> */
function tvguide_stale_grid_data(array $empty, string $cacheFile, ?string $error): array
{
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['rows'])) {
            $cached['ok'] = true;
            $cached['error'] = ($error ?? 'upstream error') . ' — showing cached listings';
            $cached['cache_age'] = time() - filemtime($cacheFile);

            return $cached;
        }
    }
    $empty['error'] = $error;

    return $empty;
}

/** @return array{ok:bool,error?:string,detail?:string,ms?:int,lineups?:int} */
function tvguide_test_connection(bool $refreshLineups = true): array
{
    if (!tvguide_configured()) {
        return ['ok' => false, 'error' => 'Set Schedules Direct username and password'];
    }

    $error = null;
    $t0 = microtime(true);
    $token = tvguide_sd_get_token($error, true);
    if ($token === null) {
        return ['ok' => false, 'error' => $error ?? 'Authentication failed'];
    }

    $lineups = tvguide_sd_lineups($lineupErr, $refreshLineups);
    $lineupCount = count($lineups);
    $detail = $lineupCount . ' lineup(s) on account';
    if ($lineupCount > 0) {
        $names = [];
        foreach ($lineups as $row) {
            $id = trim((string)($row['lineup'] ?? ''));
            if ($id === '') {
                continue;
            }
            $label = trim((string)($row['name'] ?? ''));
            $names[] = $label !== '' ? ($id . ' — ' . $label) : $id;
        }
        if ($names !== []) {
            $detail .= ': ' . implode('; ', $names);
        }
    } else {
        $detail .= ' — add a lineup at schedulesdirect.org (Lineups → Add), then Save here';
    }

    $configured = tvguide_lineup_id();
    $onAccountIds = array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['lineup'] ?? '')),
        $lineups
    )));
    $lineup = $configured;
    $channelCount = 0;
    $channelErr = null;

    if ($lineup !== '') {
        if ($lineupCount > 0 && !in_array($lineup, $onAccountIds, true)) {
            $detail .= ' · Board settings use ' . $lineup . ', which is not on your account — change Lineup ID to one of the IDs above (e.g. USA-OTA-49401 for locals)';
        } else {
            $channels = tvguide_sd_lineup_channels($lineup, $channelErr, $refreshLineups);
            $channelCount = count($channels['channels']);
            $detail .= ' · ' . $channelCount . ' channel(s) in ' . $lineup;
            if ($channelCount === 0 && $channelErr !== null) {
                $detail .= ' (' . $channelErr . ')';
            }
        }
    } elseif ($lineupCount > 0) {
        $detail .= ' · set Lineup ID under Board settings (use an ID from the list above)';
    }

    return [
        'ok' => true,
        'detail' => $detail,
        'ms' => (int)round((microtime(true) - $t0) * 1000),
        'lineups' => $lineupCount,
        'channels' => $channelCount,
    ];
}

function tvguide_reload_sec(): int
{
    return max(300, (int)cfg('tvguide.RELOAD_SEC', 3600));
}

/** affiliate | callsign | broadcast | none */
function tvguide_channel_label_mode(): string
{
    $mode = strtolower(trim((string)cfg('tvguide.CHANNEL_LABEL', 'affiliate')));

    return in_array($mode, ['affiliate', 'callsign', 'broadcast', 'none'], true) ? $mode : 'affiliate';
}

function tvguide_callsign_short(string $callsign): string
{
    $callsign = strtoupper(trim($callsign));
    if ($callsign === '') {
        return '';
    }

    return (string)preg_replace('/(-DT|DT)$/', '', $callsign);
}

function tvguide_affiliate_sort_rank(string $affiliate): int
{
    static $order = [
        'NBC' => 1, 'CBS' => 2, 'ABC' => 3, 'FOX' => 4, 'PBS' => 5, 'CW' => 6, 'ION' => 7, 'MNT' => 8,
    ];
    $key = strtoupper(trim($affiliate));

    return $order[$key] ?? 50;
}

function tvguide_channel_admin_label(array $ch): string
{
    $affiliate = trim((string)($ch['affiliate'] ?? ''));
    $callsign = tvguide_callsign_short((string)($ch['callsign'] ?? ''));
    $broadcast = trim((string)($ch['channel'] ?? ''));
    $parts = [];
    if ($affiliate !== '') {
        $parts[] = strtoupper($affiliate);
    }
    if ($callsign !== '') {
        $parts[] = $callsign;
    }
    if ($broadcast !== '') {
        $parts[] = $broadcast;
    }

    return $parts !== [] ? implode(' · ', $parts) : (string)($ch['station_id'] ?? '');
}

/** Badge in the channel column on the wall (network name, not 8.1-style numbers). */
function tvguide_row_channel_badge(array $row): string
{
    switch (tvguide_channel_label_mode()) {
        case 'none':
            return '';
        case 'broadcast':
            return trim((string)($row['channel'] ?? ''));
        case 'callsign':
            return tvguide_callsign_short((string)($row['callsign'] ?? ''));
        case 'affiliate':
        default:
            $affiliate = trim((string)($row['affiliate'] ?? ''));
            if ($affiliate !== '') {
                return strtoupper($affiliate);
            }

            return tvguide_callsign_short((string)($row['callsign'] ?? ''));
    }
}

function tvguide_row_channel_subtitle(array $row): string
{
    $mode = tvguide_channel_label_mode();
    $callsign = tvguide_callsign_short((string)($row['callsign'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));

    if ($mode === 'affiliate' && $callsign !== '') {
        return $callsign;
    }
    if ($mode !== 'none' && $name !== '' && tvguide_callsign_short($name) !== $callsign) {
        return $name;
    }

    return '';
}

/** @param list<array<string,mixed>> $channels */
function tvguide_sort_channels(array $channels): array
{
    usort($channels, static function (array $a, array $b): int {
        $ra = tvguide_affiliate_sort_rank((string)($a['affiliate'] ?? ''));
        $rb = tvguide_affiliate_sort_rank((string)($b['affiliate'] ?? ''));
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return strcasecmp(
            tvguide_callsign_short((string)($a['callsign'] ?? '')),
            tvguide_callsign_short((string)($b['callsign'] ?? ''))
        );
    });

    return $channels;
}

/** @param list<array<string,mixed>> $rows */
function tvguide_sort_grid_rows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $ra = tvguide_affiliate_sort_rank((string)($a['affiliate'] ?? ''));
        $rb = tvguide_affiliate_sort_rank((string)($b['affiliate'] ?? ''));
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return strcasecmp(
            tvguide_callsign_short((string)($a['callsign'] ?? '')),
            tvguide_callsign_short((string)($b['callsign'] ?? ''))
        );
    });

    return $rows;
}
