<?php
/**
 * Rotation shell — helpers for admin playlist editor and board.php.
 */

require_once __DIR__ . '/config.php';

function rotation_normalize_screen_key(string $key): string
{
    $key = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));
    return $key !== '' ? $key : 'main';
}

function rotation_global_fade_ms(): int
{
    return max(0, (int)cfg('rotation.FADE_MS', 800));
}

function rotation_global_settle_ms(): int
{
    return max(0, (int)cfg('rotation.SETTLE_MS', 1200));
}

function rotation_global_hang_ms(): int
{
    return max(0, (int)cfg('rotation.HANG_MS', 20000));
}

/** @param array<string,mixed>|null $scr @return array{fade_ms:int,settle_ms:int,hang_ms:int} */
function rotation_screen_transition_from_scr(?array $scr): array
{
    $fade = rotation_global_fade_ms();
    $settle = rotation_global_settle_ms();
    $hang = rotation_global_hang_ms();
    if ($scr !== null) {
        if (isset($scr['fade_ms']) && trim((string)$scr['fade_ms']) !== '') {
            $fade = max(0, (int)$scr['fade_ms']);
        }
        if (isset($scr['settle_ms']) && trim((string)$scr['settle_ms']) !== '') {
            $settle = max(0, (int)$scr['settle_ms']);
        }
        if (isset($scr['hang_ms']) && trim((string)$scr['hang_ms']) !== '') {
            $hang = max(0, (int)$scr['hang_ms']);
        }
    }

    return ['fade_ms' => $fade, 'settle_ms' => $settle, 'hang_ms' => $hang];
}

/** @return array{name:string,shuffle:bool,show_ticker:bool,show_clock:bool,show_debug:bool,keyboard_nav:bool,weighted:bool,fade_ms:int,settle_ms:int,hang_ms:int,schedule:array{enabled:bool,off:int,on:int},cec:array{enabled:bool,off:int,on:int,device:int}} */
function rotation_screen_settings(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $screensCfg = cfg('rotation.SCREENS', ['main' => 'Main Display']);
    $scr = null;
    if (is_array($screensCfg)) {
        foreach ($screensCfg as $k => $v) {
            if (rotation_normalize_screen_key((string)$k) === $screen) {
                $scr = $v;
                break;
            }
        }
    }
    $transition = rotation_screen_transition_from_scr(null);
    $defaults = [
        'name' => $screen === 'main' ? 'Main Display' : $screen,
        'shuffle' => false,
        'show_ticker' => true,
        'show_clock' => true,
        'show_debug' => false,
        'keyboard_nav' => false,
        'weighted' => false,
        'fade_ms' => $transition['fade_ms'],
        'settle_ms' => $transition['settle_ms'],
        'hang_ms' => $transition['hang_ms'],
        'schedule' => rotation_schedule_defaults(),
        'cec' => rotation_cec_defaults(),
    ];
    if ($scr === null) {
        return $defaults;
    }
    if (is_string($scr)) {
        return [
            'name' => $scr,
            'shuffle' => false,
            'show_ticker' => true,
            'show_clock' => true,
            'show_debug' => false,
            'keyboard_nav' => false,
            'weighted' => false,
            'fade_ms' => $transition['fade_ms'],
            'settle_ms' => $transition['settle_ms'],
            'hang_ms' => $transition['hang_ms'],
            'schedule' => rotation_schedule_defaults(),
            'cec' => rotation_cec_defaults(),
        ];
    }
    if (!is_array($scr)) {
        return $defaults;
    }
    $name = trim((string)($scr['name'] ?? ''));
    $showTicker = !array_key_exists('show_ticker', $scr) || !empty($scr['show_ticker']);
    $showClock = !array_key_exists('show_clock', $scr) || !empty($scr['show_clock']);
    $showDebug = !empty($scr['show_debug']);
    $keyboardNav = !empty($scr['keyboard_nav']);
    $weighted = !empty($scr['weighted']);
    $transition = rotation_screen_transition_from_scr($scr);

    return [
        'name' => $name !== '' ? $name : ($screen === 'main' ? 'Main Display' : $screen),
        'shuffle' => !empty($scr['shuffle']),
        'show_ticker' => $showTicker,
        'show_clock' => $showClock,
        'show_debug' => $showDebug,
        'keyboard_nav' => $keyboardNav,
        'weighted' => $weighted,
        'fade_ms' => $transition['fade_ms'],
        'settle_ms' => $transition['settle_ms'],
        'hang_ms' => $transition['hang_ms'],
        'schedule' => rotation_schedule_from_screen($scr),
        'cec' => rotation_cec_from_screen($scr),
    ];
}

/** Per-display ticker (global Alert Ticker setting must also be on). */
function rotation_screen_ticker_enabled(string $screen): bool
{
    if (!signage_ticker_enabled()) {
        return false;
    }

    return rotation_screen_settings($screen)['show_ticker'];
}

/** Per-display clock overlay when boards load in this screen's rotation. */
function rotation_screen_clock_enabled(string $screen): bool
{
    return rotation_screen_settings($screen)['show_clock'];
}

/** Whether this display should show a blank screen right now (schedule window, rotation timezone). */
function rotation_screen_blank_active(string $screen): bool
{
    $sched = rotation_screen_settings($screen)['schedule'];

    return rotation_blank_schedule_active($sched);
}

function rotation_admin_screen_row(string $key, $rv): array
{
    $key = rotation_normalize_screen_key($key);
    $row = is_array($rv) ? $rv : ['name' => (string)$rv];
    $sched = rotation_schedule_from_screen($row);
    $cec = rotation_cec_from_screen($row);
    return [
        '_key' => $key,
        'name' => (string)($row['name'] ?? ''),
        'shuffle' => !empty($row['shuffle']),
        'show_ticker' => !array_key_exists('show_ticker', $row) || !empty($row['show_ticker']),
        'show_clock' => !array_key_exists('show_clock', $row) || !empty($row['show_clock']),
        'show_debug' => !empty($row['show_debug']),
        'keyboard_nav' => !empty($row['keyboard_nav']),
        'weighted' => !empty($row['weighted']),
        'fade_ms' => isset($row['fade_ms']) ? (string)(int)$row['fade_ms'] : '',
        'settle_ms' => isset($row['settle_ms']) ? (string)(int)$row['settle_ms'] : '',
        'hang_ms' => isset($row['hang_ms']) ? (string)(int)$row['hang_ms'] : '',
        'schedule_enabled' => $sched['enabled'],
        'cec_enabled' => $cec['enabled'],
        'cec_off' => (string)$sched['off'],
        'cec_on' => (string)$sched['on'],
        'weekdays' => array_key_exists('weekdays', $sched) ? $sched['weekdays'] : null,
    ];
}

/** @return array{enabled:bool,off:int,on:int} */
function rotation_schedule_defaults(): array
{
    return ['enabled' => false, 'off' => 23, 'on' => 6];
}

/** @return list<array{short:string,full:string}> */
function rotation_weekday_options(): array
{
    return [
        ['short' => 'Mon', 'full' => 'Monday'],
        ['short' => 'Tue', 'full' => 'Tuesday'],
        ['short' => 'Wed', 'full' => 'Wednesday'],
        ['short' => 'Thu', 'full' => 'Thursday'],
        ['short' => 'Fri', 'full' => 'Friday'],
        ['short' => 'Sat', 'full' => 'Saturday'],
        ['short' => 'Sun', 'full' => 'Sunday'],
    ];
}

/** @return list<string> Full weekday names */
function rotation_parse_weekdays(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        foreach (rotation_weekday_options() as $opt) {
            $full = $opt['full'];
            if (strcasecmp($part, $full) === 0
                || strcasecmp($part, $opt['short']) === 0
                || (strlen($part) >= 3 && strncasecmp($part, $full, strlen($part)) === 0)) {
                $out[$full] = true;
                break;
            }
        }
    }

    return array_keys($out);
}

/** @param list<string> $raw @return list<string> */
function rotation_normalize_weekdays_list(array $raw): array
{
    $out = [];
    foreach ($raw as $item) {
        foreach (rotation_parse_weekdays((string)$item) as $day) {
            $out[$day] = true;
        }
    }
    $list = array_keys($out);
    usort($list, static function ($a, $b) {
        $order = array_column(rotation_weekday_options(), 'full');
        return array_search($a, $order, true) <=> array_search($b, $order, true);
    });

    return $list;
}

/**
 * Weekdays from admin POST. Null when the form did not include weekday controls.
 * @return list<string>|null
 */
function rotation_weekdays_from_post_row(array $row): ?array
{
    if (!array_key_exists('weekdays', $row)) {
        return null;
    }
    if (!is_array($row['weekdays'])) {
        return rotation_normalize_weekdays_list([trim((string)$row['weekdays'])]);
    }
    $days = [];
    foreach ($row['weekdays'] as $item) {
        foreach (rotation_parse_weekdays((string)$item) as $day) {
            $days[$day] = true;
        }
    }

    return array_keys($days);
}

/** @param list<string>|null $selected Full names; null = all days (legacy / unset) */
function rotation_admin_weekdays_html(string $namePrefix, ?array $selected): void
{
    $allSelected = $selected === null;
    $selSet = [];
    if (is_array($selected)) {
        foreach ($selected as $day) {
            $selSet[(string)$day] = true;
        }
    }
    echo '<div class="rotation-weekdays" title="Active on these days only; other days stay blank all day. All seven = every day.">';
    foreach (rotation_weekday_options() as $opt) {
        $full = $opt['full'];
        $checked = $allSelected || !empty($selSet[$full]);
        echo '<label class="rotation-weekday"><input type="checkbox" name="' . h($namePrefix . '[weekdays][]') . '" value="' . h($full) . '"'
            . ($checked ? ' checked' : '') . '> ' . h($opt['short']) . '</label>';
    }
    echo '</div>';
}

function rotation_now(): DateTimeImmutable
{
    try {
        return new DateTimeImmutable('now', new DateTimeZone(rotation_timezone()));
    } catch (Throwable $e) {
        return new DateTimeImmutable('now');
    }
}

/** @param list<string> $weekdays Full weekday names */
function rotation_today_in_weekdays(array $weekdays, ?DateTimeInterface $now = null): bool
{
    if ($weekdays === []) {
        return false;
    }
    $now = $now ?? rotation_now();
    $today = $now->format('l');
    foreach ($weekdays as $day) {
        if (strcasecmp($today, (string)$day) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * True when the blank schedule says the display should be dark now.
 * @param array{enabled?:bool,off?:int,on?:int,weekdays?:list<string>} $sched
 */
function rotation_blank_schedule_active(array $sched, ?DateTimeInterface $now = null): bool
{
    $now = $now ?? rotation_now();
    if (array_key_exists('weekdays', $sched)) {
        $weekdays = is_array($sched['weekdays']) ? $sched['weekdays'] : [];
        if ($weekdays === [] || !rotation_today_in_weekdays($weekdays, $now)) {
            return true;
        }
    }
    if (empty($sched['enabled'])) {
        return false;
    }

    return rotation_in_off_window(
        (int)($sched['off'] ?? 23),
        (int)($sched['on'] ?? 6),
        (int)$now->format('G')
    );
}

/** CEC standby follows blank weekdays and optional overnight hours. */
function rotation_cec_standby_active(array $cec, array $sched, ?DateTimeInterface $now = null): bool
{
    if (empty($cec['enabled'])) {
        return false;
    }
    $probe = [
        'enabled' => !empty($sched['enabled']),
        'off' => $cec['off'],
        'on' => $cec['on'],
    ];
    if (array_key_exists('weekdays', $sched)) {
        $probe['weekdays'] = $sched['weekdays'];
    }

    return rotation_blank_schedule_active($probe, $now);
}

/** @param array<string,mixed> $scr @return array{off:int,on:int} */
function rotation_screen_off_hours(array $scr): array
{
    $off = 23;
    $on = 6;
    foreach (['schedule', 'cec'] as $blockKey) {
        $block = is_array($scr[$blockKey] ?? null) ? $scr[$blockKey] : [];
        if (isset($block['off'])) {
            $off = max(0, min(23, (int)$block['off']));
        }
        if (isset($block['on'])) {
            $on = max(0, min(23, (int)$block['on']));
        }
    }
    if (isset($scr['cec_off']) && trim((string)$scr['cec_off']) !== '') {
        $off = max(0, min(23, (int)$scr['cec_off']));
    }
    if (isset($scr['cec_on']) && trim((string)$scr['cec_on']) !== '') {
        $on = max(0, min(23, (int)$scr['cec_on']));
    }

    return ['off' => $off, 'on' => $on];
}

/** @param array<string,mixed> $scr @return array{enabled:bool,off:int,on:int} */
function rotation_schedule_from_screen(array $scr): array
{
    $sched = rotation_schedule_defaults();
    $hours = rotation_screen_off_hours($scr);
    $sched['off'] = $hours['off'];
    $sched['on'] = $hours['on'];

    if (array_key_exists('schedule', $scr) && is_array($scr['schedule'])) {
        $block = $scr['schedule'];
        $sched['enabled'] = !empty($block['enabled']);
        if (isset($block['off'])) {
            $sched['off'] = max(0, min(23, (int)$block['off']));
        }
        if (isset($block['on'])) {
            $sched['on'] = max(0, min(23, (int)$block['on']));
        }
        if (array_key_exists('weekdays', $block)) {
            $sched['weekdays'] = rotation_normalize_weekdays_list(
                is_array($block['weekdays']) ? $block['weekdays'] : []
            );
        }

        return $sched;
    }

    if (!empty($scr['schedule_enabled'])) {
        $sched['enabled'] = true;

        return $sched;
    }

    // Legacy configs only had CEC — blank the screen on the same hours (works without HDMI-CEC).
    $cec = rotation_cec_from_screen($scr);
    if ($cec['enabled']) {
        $sched['enabled'] = true;
        $sched['off'] = $cec['off'];
        $sched['on'] = $cec['on'];
    }

    return $sched;
}

/** @param array<string,mixed> $row @return array{off:int,on:int} */
function rotation_blank_hours_from_post_row(array $row): array
{
    $off = trim((string)($row['cec_off'] ?? ''));
    $on = trim((string)($row['cec_on'] ?? ''));

    return [
        'off' => $off !== '' ? max(0, min(23, (int)$off)) : 23,
        'on' => $on !== '' ? max(0, min(23, (int)$on)) : 6,
    ];
}

/**
 * Merge admin POST fields for one display onto a screen registry entry.
 * @param array<string,mixed> $entry
 * @param array<string,mixed> $row SCREENS[] or SCREEN_OPTS[] row
 */
function rotation_apply_screen_post_row(array $entry, array $row, bool $includeIdentity = false): array
{
    if ($includeIdentity) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name !== '') {
            $entry['name'] = $name;
        }
        if (isset($row['keyboard_nav'])) {
            $entry['keyboard_nav'] = true;
        } else {
            unset($entry['keyboard_nav']);
        }
        $entry['show_clock'] = isset($row['show_clock']);
    }
    if ($includeIdentity || !empty($row['_screen_opts_form'])) {
        if (isset($row['shuffle'])) {
            $entry['shuffle'] = true;
        } else {
            unset($entry['shuffle']);
        }
        if (isset($row['weighted'])) {
            $entry['weighted'] = true;
        } else {
            unset($entry['weighted']);
        }
    }

    $entry['show_ticker'] = isset($row['show_ticker']);
    $entry['show_debug'] = isset($row['show_debug']);

    foreach (['fade_ms', 'settle_ms', 'hang_ms'] as $transKey) {
        $tv = trim((string)($row[$transKey] ?? ''));
        if ($tv === '') {
            unset($entry[$transKey]);
        } else {
            $entry[$transKey] = max(0, (int)$tv);
        }
    }

    $hours = rotation_blank_hours_from_post_row($row);
    $schedule = [
        'enabled' => isset($row['schedule_enabled']),
        'off' => $hours['off'],
        'on' => $hours['on'],
    ];
    $weekdaysPosted = rotation_weekdays_from_post_row($row);
    if ($weekdaysPosted !== null) {
        $normalized = rotation_normalize_weekdays_list($weekdaysPosted);
        if (count($normalized) !== 7) {
            $schedule['weekdays'] = $normalized;
        }
    } elseif (isset($entry['schedule']) && is_array($entry['schedule']) && array_key_exists('weekdays', $entry['schedule'])) {
        $schedule['weekdays'] = $entry['schedule']['weekdays'];
    }
    $entry['schedule'] = $schedule;

    if (isset($row['cec_enabled'])) {
        $entry['cec'] = [
            'enabled' => true,
            'off' => $hours['off'],
            'on' => $hours['on'],
            'device' => max(0, min(15, (int)($entry['cec']['device'] ?? 0))),
        ];
    } else {
        unset($entry['cec']);
    }

    unset($entry['schedule_enabled'], $entry['cec_enabled'], $entry['cec_off'], $entry['cec_on'], $entry['_screen_opts_form']);

    return $entry;
}

/** @return array{enabled:bool,off:int,on:int,device:int} */
function rotation_cec_defaults(): array
{
    return ['enabled' => false, 'off' => 23, 'on' => 6, 'device' => 0];
}

/** @param array<string,mixed> $scr @return array{enabled:bool,off:int,on:int,device:int} */
function rotation_cec_from_screen(array $scr): array
{
    $cec = rotation_cec_defaults();
    $hours = rotation_screen_off_hours($scr);
    $cec['off'] = $hours['off'];
    $cec['on'] = $hours['on'];
    $block = is_array($scr['cec'] ?? null) ? $scr['cec'] : [];
    if (!empty($block['enabled']) || !empty($scr['cec_enabled'])) {
        $cec['enabled'] = true;
    }
    if (isset($block['device'])) {
        $cec['device'] = max(0, min(15, (int)$block['device']));
    }

    return $cec;
}

function rotation_timezone(): string
{
    $tz = trim((string)cfg('rotation.TIMEZONE', cfg('index.TIMEZONE', 'America/Detroit')));
    return $tz !== '' ? $tz : 'America/Detroit';
}

/** Current hour (0–23) in the rotation timezone. */
function rotation_current_hour(): int
{
    $tz = rotation_timezone();
    try {
        $prevTz = date_default_timezone_get();
        date_default_timezone_set($tz);
        $hour = (int)date('G');
        date_default_timezone_set($prevTz);

        return $hour;
    } catch (Throwable $e) {
        return (int)date('G');
    }
}

/** Whether a playlist row is inside its optional from/to hour window (matches board.php). */
function rotation_page_in_window(array $page, ?int $hour = null): bool
{
    $from = $page['from'] ?? null;
    $to = $page['to'] ?? null;
    if ($from === null || $to === null || $from === '' || $to === '') {
        return true;
    }
    $hour = $hour ?? rotation_current_hour();
    $from = (int)$from;
    $to = (int)$to;
    if ($from <= $to) {
        return $hour >= $from && $hour < $to;
    }

    return $hour >= $from || $hour < $to;
}

/** Inside the configured off window (supports overnight, e.g. off 23 / on 6). */
function rotation_in_off_window(int $offHour, int $onHour, ?int $hour = null): bool
{
    if ($offHour === $onHour) {
        return false;
    }
    $hour = $hour ?? rotation_current_hour();
    if ($offHour < $onHour) {
        return $hour >= $offHour && $hour < $onHour;
    }

    return $hour >= $offHour || $hour < $onHour;
}

/** @deprecated Use rotation_in_off_window() */
function rotation_cec_should_standby(int $offHour, int $onHour, ?int $hour = null): bool
{
    return rotation_in_off_window($offHour, $onHour, $hour);
}

function rotation_cec_revision(string $screen = 'main'): string
{
    $screen = rotation_normalize_screen_key($screen);
    $cec = rotation_screen_settings($screen)['cec'];
    $mtime = is_file(cfg_path()) ? (int)filemtime(cfg_path()) : 0;
    $blob = json_encode([
        'mtime' => $mtime,
        'screen' => $screen,
        'timezone' => rotation_timezone(),
        'cec' => $cec,
    ], JSON_UNESCAPED_SLASHES);
    return substr(sha1($blob ?: ''), 0, 12);
}

/** @return array<string,mixed> */
function rotation_cec_api_payload(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $tz = rotation_timezone();
    $hour = rotation_current_hour();
    $cec = rotation_screen_settings($screen)['cec'];
    $sched = rotation_screen_settings($screen)['schedule'];
    $standby = rotation_cec_standby_active($cec, $sched);
    return [
        'screen' => $screen,
        'timezone' => $tz,
        'hour' => $hour,
        'cec' => [
            'enabled' => $cec['enabled'],
            'off' => $cec['off'],
            'on' => $cec['on'],
            'device' => $cec['device'],
            'standby' => $standby,
        ],
        'revision' => rotation_cec_revision($screen),
    ];
}

/** @return array<string, array{name:string,shuffle?:bool,cec?:array<string,mixed>}> */
function rotation_screens(): array
{
    $screens = cfg('rotation.SCREENS', ['main' => 'Main Display']);
    if (!is_array($screens) || $screens === []) {
        return ['main' => ['name' => 'Main Display', 'shuffle' => false]];
    }
    $out = [];
    foreach ($screens as $k => $scr) {
        $nk = rotation_normalize_screen_key((string)$k);
        if ($nk === '') {
            continue;
        }
        if (is_string($scr)) {
            $out[$nk] = ['name' => $scr, 'shuffle' => false];
        } elseif (is_array($scr)) {
            $name = trim((string)($scr['name'] ?? ''));
            $entry = ['name' => $name !== '' ? $name : ($nk === 'main' ? 'Main Display' : $nk)];
            if (!empty($scr['shuffle'])) {
                $entry['shuffle'] = true;
            }
            $sched = rotation_schedule_from_screen($scr);
            if ($sched['enabled']) {
                $entry['schedule'] = [
                    'enabled' => true,
                    'off' => $sched['off'],
                    'on' => $sched['on'],
                ];
                if (array_key_exists('weekdays', $sched)) {
                    $entry['schedule']['weekdays'] = $sched['weekdays'];
                }
            }
            $cec = rotation_cec_from_screen($scr);
            if ($cec['enabled']) {
                $entry['cec'] = [
                    'enabled' => true,
                    'off' => $cec['off'],
                    'on' => $cec['on'],
                    'device' => $cec['device'],
                ];
            }
            $out[$nk] = $entry;
        }
    }
    if (!isset($out['main'])) {
        $out = ['main' => ['name' => 'Main Display', 'shuffle' => false]] + $out;
    }
    return $out;
}

/** @return list<array<string,mixed>> Saved pages for one screen only (no fallback). */
function rotation_screen_own_pages(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $key = 'rotation.PAGES_' . $screen;
    if (!isset($GLOBALS['__cfg_cache']) || $GLOBALS['__cfg_cache'] === null) {
        cfg('_', null);
    }
    $conf = $GLOBALS['__cfg_cache'] ?? [];
    if (!array_key_exists($key, $conf) || !is_array($conf[$key])) {
        return [];
    }

    return $conf[$key];
}

/**
 * Playlist rows to use when syncing photos or slides onto a display.
 * When a screen has no saved playlist yet (mirrors main or uses starter pages),
 * start from what actually plays there so sync does not drop inherited entries.
 *
 * @return list<array<string,mixed>>
 */
function rotation_sync_source_pages(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $own = rotation_screen_own_pages($screen);
    if ($own !== []) {
        return $own;
    }

    return rotation_screen_effective_pages($screen);
}

/**
 * Apply posted playlist rows for one screen onto settings (rotation board save).
 * @param array<string,mixed> $conf
 * @param array<string,mixed> $rawRows raw $_POST rows keyed by index
 * @return array<string,mixed>
 */
function rotation_merge_pages_from_post(array $conf, string $screen, array $rawRows): array
{
    require_once __DIR__ . '/users_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    if ($screen === '') {
        return $conf;
    }
    $cfgKey = 'rotation.PAGES_' . $screen;
    $existing = is_array($conf[$cfgKey] ?? null) ? $conf[$cfgKey] : [];
    $parsed = rotation_parse_pages_rows($rawRows);
    if (admin_is_super()) {
        if ($parsed === []) {
            unset($conf[$cfgKey]);
        } else {
            $conf[$cfgKey] = $parsed;
        }

        return $conf;
    }
    $out = [];
    foreach ($parsed as $row) {
        $url = trim((string)($row['url'] ?? ''));
        $postRow = null;
        foreach ($rawRows as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if (trim((string)($raw['url'] ?? '')) === $url) {
                $postRow = admin_normalize_form_row($raw);
                break;
            }
        }
        $prev = null;
        foreach ($existing as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['url'] ?? '')) === $url) {
                $prev = $entry;
                break;
            }
        }
        $out[] = admin_finalize_entry($row, $prev, $postRow ?? $row);
    }
    if ($out === []) {
        unset($conf[$cfgKey]);
    } else {
        $conf[$cfgKey] = $out;
    }

    return $conf;
}

/** @return list<array<string,mixed>> Pages that will play on the wall (matches board.php). */
function rotation_screen_effective_pages(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $own = rotation_screen_own_pages($screen);
    if ($own !== []) {
        return $own;
    }
    if ($screen !== 'main') {
        return rotation_screen_effective_pages('main');
    }
    $legacy = cfg('rotation.PAGES', []);
    if (is_array($legacy) && $legacy !== []) {
        return $legacy;
    }
    return rotation_starter_pages();
}

/** @return list<array<string,mixed>> */
function rotation_screen_pages(string $screen = 'main'): array
{
    return rotation_screen_effective_pages($screen);
}

function rotation_screen_preview_url(string $screen = 'main'): string
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '' || $screen === 'main') {
        return 'player.php';
    }
    return 'player.php?screen=' . rawurlencode($screen);
}

function rotation_screen_kiosk_url(string $screen = 'main'): string
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '' || $screen === 'main') {
        return 'board.php';
    }
    return 'board.php?screen=' . rawurlencode($screen);
}

/** @return list<array<string,mixed>> Active pages for the rotation shell (url set, dwell > 0, not skipped). */
function rotation_screen_active_pages(string $screen = 'main'): array
{
    static $cache = [];

    require_once __DIR__ . '/slides_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    if (array_key_exists($screen, $cache)) {
        return $cache[$screen];
    }
    $scopeUid = null;
    if ($screen !== 'main') {
        require_once __DIR__ . '/users_lib.php';
        $scopeUid = users_screen_assignments()[$screen] ?? null;
    }

    $slideDeck = cfg('slides.SLIDES', []);
    if (!is_array($slideDeck)) {
        $slideDeck = [];
    }
    if ($scopeUid !== null) {
        $slideDeck = admin_filter_list_for_scope($slideDeck, $scopeUid);
    }
    $activeSlides = slides_active_entries($slideDeck);
    $activeFiles = [];
    foreach ($activeSlides as $slide) {
        if (!slide_on_screen($slide, $screen)) {
            continue;
        }
        $activeFiles[(string)$slide['file']] = true;
    }

    $effective = rotation_screen_effective_pages($screen);
    $hasSlideEntries = false;
    foreach ($effective as $p) {
        if (!is_array($p)) {
            continue;
        }
        if (rotation_is_slide_url(trim((string)($p['url'] ?? '')))) {
            $hasSlideEntries = true;
            break;
        }
    }

    $cache[$screen] = array_values(array_filter(
        $effective,
        static function ($p) use ($activeFiles, $hasSlideEntries) {
            if (!is_array($p)
                || trim((string)($p['url'] ?? '')) === ''
                || rotation_page_dwell($p) <= 0
                || !empty($p['off'])) {
                return false;
            }
            $url = trim((string)($p['url'] ?? ''));
            if ($hasSlideEntries && rotation_is_legacy_slides_url($url)) {
                return false;
            }
            $file = slide_rotation_parse_file($url);
            if ($file === null) {
                return true;
            }
            return isset($activeFiles[$file]);
        }
    ));

    return $cache[$screen];
}

/** @return list<array<string,mixed>> */
function rotation_pages_labeled(array $pages): array
{
    return array_values(array_map(static function ($p) {
        if (!is_array($p)) {
            return $p;
        }
        $url = trim((string)($p['url'] ?? ''));

        return $p + ['label' => rotation_page_label($url)];
    }, $pages));
}

/** Fingerprint of the saved rotation config for one screen — used by board.php polling. */
function rotation_config_revision(string $screen = 'main'): string
{
    $screen = rotation_normalize_screen_key($screen);
    $settings = rotation_screen_settings($screen);
    $mtime = is_file(cfg_path()) ? (int)filemtime(cfg_path()) : 0;
    $blob = json_encode([
        'mtime' => $mtime,
        'screen' => $screen,
        'pages' => rotation_screen_effective_pages($screen),
        'shuffle' => $settings['shuffle'],
        'show_ticker' => $settings['show_ticker'],
        'show_clock' => $settings['show_clock'],
        'show_debug' => $settings['show_debug'],
        'keyboard_nav' => $settings['keyboard_nav'],
        'weighted' => $settings['weighted'],
        'schedule' => $settings['schedule'],
        'cec' => $settings['cec'],
        'fade_ms' => $settings['fade_ms'],
        'settle_ms' => $settings['settle_ms'],
        'hang_ms' => $settings['hang_ms'],
    ], JSON_UNESCAPED_SLASHES);
    return substr(sha1($blob ?: ''), 0, 12);
}

/**
 * Runtime payload for board.php render + ?api=1 polling.
 * @return array<string,mixed>
 */
function rotation_screen_runtime(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $settings = rotation_screen_settings($screen);
    $blank = rotation_screen_blank_active($screen);

    return [
        'screen' => $screen,
        'timezone' => rotation_timezone(),
        'pages' => rotation_pages_labeled(rotation_screen_active_pages($screen)),
        'shuffle' => $settings['shuffle'],
        'weighted' => $settings['weighted'],
        'show_ticker' => $settings['show_ticker'],
        'show_clock' => $settings['show_clock'],
        'show_debug' => $settings['show_debug'],
        'keyboard_nav' => $settings['keyboard_nav'],
        'schedule' => $settings['schedule'],
        'blank' => $blank,
        'fade_ms' => $settings['fade_ms'],
        'settle_ms' => $settings['settle_ms'],
        'hang_ms' => $settings['hang_ms'],
        'revision' => rotation_config_revision($screen),
    ];
}

/** Default playlist when nothing is configured yet (matches board.php fallback). */
function rotation_starter_pages(): array
{
    return [
        ['url' => 'index.php',   'dwell' => 180],
        ['url' => 'lake.php',    'dwell' => 60,  'from' => 7,  'to' => 22],
        ['url' => 'webcam.php',  'dwell' => 120, 'from' => 7,  'to' => 22],
        ['url' => 'photo.php',   'dwell' => 60,  'from' => 14, 'to' => 23],
        ['url' => 'air.php',     'dwell' => 60,  'from' => 6,  'to' => 22],
        ['url' => 'sports.php',  'dwell' => 75,  'from' => 8,  'to' => 23],
        ['url' => 'family.php',  'dwell' => 90,  'from' => 6,  'to' => 21],
        ['url' => 'homelab.php', 'dwell' => 45],
        ['url' => 'traffic.php', 'dwell' => 90,  'from' => 6,  'to' => 20],
    ];
}

/** Parse posted rotation page rows from admin forms. @return list<array<string,mixed>> */
function rotation_parse_pages_rows(array $rows): array
{
    $outV = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = array_map(fn($v) => trim((string)$v), $row);
        $url = trim((string)($row['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $obj = ['url' => $url];
        $dwell = (int)($row['dwell'] ?? 0);
        $obj['dwell'] = $dwell > 0 ? $dwell : 60;
        foreach (['from', 'to'] as $col) {
            $v = $row[$col] ?? '';
            if ($v !== '') {
                $obj[$col] = (int)$v;
            }
        }
        $wRaw = trim((string)($row['weight'] ?? ''));
        if ($wRaw !== '') {
            $w = max(1, min(20, (int)$wRaw));
            if ($w > 1) {
                $obj['weight'] = $w;
            }
        }
        if (($row['off'] ?? '') !== '') {
            $obj['off'] = true;
        }
        $outV[] = $obj;
    }
    return $outV;
}

/** Dwell seconds for one playlist row — missing/zero defaults to 60. */
function rotation_page_dwell(array $page): int
{
    $dwell = (int)($page['dwell'] ?? 0);

    return $dwell > 0 ? $dwell : 60;
}

/**
 * Carry playlist metadata when slide/photo deploy sync rebuilds rows.
 * @param array<string,mixed> $page
 * @param array<string,mixed> $prev
 * @return array<string,mixed>
 */
function rotation_merge_page_meta(array $page, array $prev): array
{
    foreach (['from', 'to'] as $col) {
        if (array_key_exists($col, $prev) && $prev[$col] !== '' && $prev[$col] !== null) {
            $page[$col] = (int)$prev[$col];
        }
    }
    if (!empty($prev['off'])) {
        $page['off'] = true;
    }
    $wRaw = trim((string)($prev['weight'] ?? ''));
    if ($wRaw !== '') {
        $w = max(1, min(20, (int)$wRaw));
        if ($w > 1) {
            $page['weight'] = $w;
        }
    }

    return $page;
}

/** Admin tooltip: per-page Weight field (1–20). */
function rotation_weight_tooltip(): string
{
    return 'How often this page is picked when Weighted is on for the display. '
        . 'Blank or 1 = normal. 2–20 = more often (weight 3 is ~3× as likely as weight 1). '
        . 'Ignored when Weighted is off. Hour windows and Skip still apply.';
}

/** Admin tooltip: display-level Weighted rotation mode. */
function rotation_weighted_mode_tooltip(): string
{
    return 'Pick the next page at random using each entry\'s Weight (1–20, default 1). '
        . 'Unlike Shuffle, a page can repeat before every page has played. '
        . 'Weighted overrides Shuffle when both are checked.';
}

function rotation_page_label(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return 'New page';
    }

    if (preg_match('/^video\.php\?v=([^&]+)/', $url, $m)) {
        require_once __DIR__ . '/video_lib.php';
        $key = urldecode($m[1]);
        $v = video_registry()[$key] ?? null;
        $title = is_array($v) ? trim((string)($v['title'] ?? '')) : '';
        return 'Video — ' . ($title !== '' ? $title : $key);
    }

    if (preg_match('/^rss\.php\?feed=([^&]+)/', $url, $m)) {
        $key = urldecode($m[1]);
        $feeds = cfg('rss.FEEDS', []);
        $name = is_array($feeds[$key] ?? null) ? trim((string)($feeds[$key]['name'] ?? '')) : '';
        return 'RSS — ' . ($name !== '' ? $name : $key);
    }

    if (preg_match('/^grafana\.php\?d=([^&]+)/', $url, $m)) {
        return 'Grafana — ' . urldecode($m[1]);
    }

    if (preg_match('/^splunkdash\.php\?d=([^&]+)/', $url, $m)) {
        $key = urldecode($m[1]);
        $dashboards = cfg('splunkdash.DASHBOARDS', []);
        $d = is_array($dashboards[$key] ?? null) ? $dashboards[$key] : null;
        $title = is_array($d) ? trim((string)($d['title'] ?? '')) : '';

        return 'Splunk published — ' . ($title !== '' ? $title : $key);
    }

    if (preg_match('/^splunk\.php(?:\?d=([^&]+))?/', $url, $m)) {
        require_once __DIR__ . '/splunk_lib.php';
        $key = isset($m[1]) ? urldecode($m[1]) : (string)(array_key_first(splunk_pages_config()) ?: 'main');

        return 'Splunk — ' . splunk_page_label($key);
    }

    if (preg_match('/^web\.php(?:\?d=([^&]+))?/', $url, $m)) {
        require_once __DIR__ . '/web_lib.php';
        $key = isset($m[1]) ? urldecode($m[1]) : (string)(array_key_first(web_sites_config()) ?: 'main');

        return 'Web — ' . web_site_label($key);
    }

    require_once __DIR__ . '/slides_lib.php';
    $slideFile = slide_rotation_parse_file($url);
    if ($slideFile !== null) {
        $slide = slide_deck_by_file($slideFile);
        $caption = is_array($slide) ? trim((string)($slide['caption'] ?? '')) : '';
        return 'Slide — ' . ($caption !== '' ? $caption : $slideFile);
    }

    require_once __DIR__ . '/rotator_lib.php';
    $photoFile = rotator_rotation_parse_file($url);
    if ($photoFile !== null) {
        $photo = rotator_deck_by_file($photoFile);
        $caption = is_array($photo) ? trim((string)($photo['caption'] ?? '')) : '';
        return 'Photo — ' . ($caption !== '' ? $caption : $photoFile);
    }
    $photoGroup = rotator_rotation_parse_group($url);
    if ($photoGroup !== null) {
        return 'Photos — group ' . $photoGroup;
    }
    if (rotator_is_legacy_url($url)) {
        return 'Photo rotator (all photos)';
    }

    static $boards = [
        'index.php' => 'Weather',
        'lake.php' => 'Lake Michigan',
        'webcam.php' => 'Grand Haven webcam',
        'photo.php' => 'Photo conditions',
        'air.php' => 'Air & pollen',
        'sports.php' => 'Detroit sports',
        'family.php' => 'Calendar',
        'traffic.php' => 'Traffic map',
        'homelab.php' => 'Homelab status',
        'signaltrace.php' => 'SignalTrace',
        'rotator.php' => 'Photo rotator',
        'slides.php' => 'Custom slides',
        'rss.php' => 'RSS stories',
        'video.php' => 'Video board',
        'splunk.php' => 'Splunk panels',
        'splunkdash.php' => 'Splunk dashboard',
        'web.php' => 'Website',
    ];

    $base = strtok($url, '?') ?: $url;
    return $boards[$base] ?? $base;
}

/** Board URL for one RSS feed (`rss.php?feed=KEY`). */
function rss_feed_url(string $feedKey): string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $feedKey);

    return 'rss.php?feed=' . rawurlencode($key);
}

/** Admin preview URL for one RSS feed (no ticker, safe bottom inset). */
function rss_preview_url(string $feedKey): string
{
    return signage_board_preview_url(rss_feed_url($feedKey));
}

/** @return array<string,array<string,mixed>> */
function rss_feed_registry(): array
{
    $feeds = cfg('rss.FEEDS', []);
    return is_array($feeds) ? $feeds : [];
}

/** @return array<string,array<string,mixed>> */
function rss_feeds_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(rss_feed_registry());
}

/** @return array<string,array<string,mixed>> */
function grafana_dashboard_registry(): array
{
    $dash = cfg('grafana.DASHBOARDS', []);
    return is_array($dash) ? $dash : [];
}

/** @return array<string,array<string,mixed>> */
function grafana_dashboards_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(grafana_dashboard_registry());
}

/** @return array<string,array<string,mixed>> */
function splunkdash_dashboard_registry(): array
{
    $dash = cfg('splunkdash.DASHBOARDS', []);
    return is_array($dash) ? $dash : [];
}

/** @return array<string,array<string,mixed>> */
function splunkdash_dashboards_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(splunkdash_dashboard_registry());
}

/**
 * Delete an RSS feed entry and drop it from rotation on allowed screens.
 * @return array{ok:bool,key?:string,error?:string}
 */
function rss_delete_feed(string $key): array
{
    require_once __DIR__ . '/users_lib.php';

    $safe = admin_normalize_registry_key($key);
    if ($safe === null) {
        return ['ok' => false, 'error' => 'Invalid feed key.'];
    }

    $registry = rss_feed_registry();
    if (admin_registry_find_entry($registry, $safe) === null) {
        return ['ok' => false, 'error' => 'Feed not found.'];
    }

    if (!cfg_update(function (array $conf) use ($safe): array {
        $registry = $conf['rss.FEEDS'] ?? [];
        if (!is_array($registry)) {
            $registry = [];
        }
        $registry = admin_remove_registry_entry($registry, $safe);
        if ($registry === []) {
            unset($conf['rss.FEEDS']);
        } else {
            $conf['rss.FEEDS'] = $registry;
        }

        return $conf;
    })) {
        return ['ok' => false, 'error' => 'Could not update settings.json.'];
    }
    cfg_reload();

    $cacheFile = __DIR__ . '/cache/rss_' . $safe . '.dat';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }

    $rotationUrl = rss_feed_url($safe);
    foreach (admin_media_rotation_sync_screens() as $screen) {
        $sync = rotation_remove_url($screen, $rotationUrl);
        if ($sync['removed']) {
            rotation_pages_write($sync['screen'], $sync['pages']);
        }
    }
    cfg_reload();

    return ['ok' => true, 'key' => $safe];
}

function grafana_normalize_key(string $key): string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);

    return $key !== '' ? $key : 'main';
}

function grafana_page_url(string $key): string
{
    return 'grafana.php?d=' . rawurlencode(grafana_normalize_key($key));
}

function grafana_preview_url(string $key): string
{
    return signage_board_preview_url(grafana_page_url($key));
}

function splunkdash_normalize_key(string $key): string
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);

    return $key !== '' ? $key : 'main';
}

function splunkdash_page_url(string $key): string
{
    return 'splunkdash.php?d=' . rawurlencode(splunkdash_normalize_key($key));
}

function splunkdash_preview_url(string $key): string
{
    return signage_board_preview_url(splunkdash_page_url($key));
}

/** Total rotation dwell for one RSS feed pass (per-story seconds × story count). */
function rotation_rss_feed_dwell(array $feed): int
{
    $perStory = (int)($feed['dwell'] ?? cfg('rss.DEFAULT_DWELL', 16));
    $stories = (int)($feed['stories'] ?? cfg('rss.DEFAULT_STORIES', 6));

    return max(30, $perStory * max(1, $stories));
}

/**
 * Quick-add presets for the rotation playlist editor.
 * @return list<array{label:string,url:string,dwell:int,group:string}>
 */
function rotation_quick_add_items(): array
{
    $items = [
        ['label' => 'Weather', 'url' => 'index.php', 'dwell' => 180, 'group' => 'Boards'],
        ['label' => 'Lake Michigan', 'url' => 'lake.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Grand Haven webcam', 'url' => 'webcam.php', 'dwell' => 120, 'group' => 'Boards'],
        ['label' => 'Photo conditions', 'url' => 'photo.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Air & pollen', 'url' => 'air.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Detroit sports', 'url' => 'sports.php', 'dwell' => 75, 'group' => 'Boards'],
        ['label' => 'Calendar', 'url' => 'family.php', 'dwell' => 90, 'group' => 'Boards'],
        ['label' => 'Traffic map', 'url' => 'traffic.php', 'dwell' => 90, 'group' => 'Boards'],
        ['label' => 'Homelab', 'url' => 'homelab.php', 'dwell' => 45, 'group' => 'Boards'],
        ['label' => 'SignalTrace', 'url' => 'signaltrace.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Photo rotator', 'url' => 'rotator.php', 'dwell' => 300, 'group' => 'Media'],
    ];

    $feeds = cfg('rss.FEEDS', []);
    if (is_array($feeds)) {
        foreach ($feeds as $key => $feed) {
            if (!is_array($feed)) {
                continue;
            }
            if (!rotation_quick_add_entry_allowed($feed)) {
                continue;
            }
            $items[] = [
                'label' => 'RSS — ' . trim((string)($feed['name'] ?? $key)),
                'url' => rss_feed_url((string)$key),
                'dwell' => rotation_rss_feed_dwell($feed),
                'group' => 'RSS feeds',
            ];
        }
    }

    require_once __DIR__ . '/video_lib.php';
    foreach (video_registry() as $key => $v) {
        if (!is_array($v)) {
            continue;
        }
        if (!rotation_quick_add_entry_allowed($v)) {
            continue;
        }
        $title = trim((string)($v['title'] ?? ''));
        $st = video_entry_status($key, $v);
        $items[] = [
            'label' => 'Video — ' . ($title !== '' ? $title : $key),
            'url' => video_rotation_url($key),
            'dwell' => max(15, (int)($st['rotation_dwell'] ?? 60)),
            'group' => 'Videos',
        ];
    }

    $dashboards = cfg('grafana.DASHBOARDS', []);
    if (is_array($dashboards)) {
        foreach ($dashboards as $key => $d) {
            if (!is_array($d)) {
                continue;
            }
            if (!rotation_quick_add_entry_allowed($d)) {
                continue;
            }
            $title = trim((string)($d['title'] ?? $key));
            $items[] = [
                'label' => 'Grafana — ' . $title,
                'url' => grafana_page_url((string)$key),
                'dwell' => 60,
                'group' => 'Dashboards',
            ];
        }
    }

    $dashboards = cfg('splunkdash.DASHBOARDS', []);
    if (is_array($dashboards)) {
        foreach ($dashboards as $key => $d) {
            if (!is_array($d)) {
                continue;
            }
            if (!rotation_quick_add_entry_allowed($d)) {
                continue;
            }
            $url = trim((string)($d['url'] ?? ''));
            if ($url === '' || str_contains($url, 'REPLACE')) {
                continue;
            }
            $title = trim((string)($d['title'] ?? $key));
            $items[] = [
                'label' => 'Splunk published — ' . $title,
                'url' => splunkdash_page_url((string)$key),
                'dwell' => 60,
                'group' => 'Dashboards',
            ];
        }
    }

    require_once __DIR__ . '/splunk_lib.php';
    foreach (splunk_pages_config() as $key => $page) {
        if (!is_array($page)) {
            continue;
        }
        if (!rotation_quick_add_entry_allowed($page)) {
            continue;
        }
        $title = trim((string)($page['title'] ?? $key));
        $items[] = [
            'label' => 'Splunk — ' . $title,
            'url' => splunk_page_url((string)$key),
            'dwell' => 60,
            'group' => 'Dashboards',
        ];
    }

    require_once __DIR__ . '/web_lib.php';
    foreach (web_sites_config() as $key => $site) {
        if (!is_array($site)) {
            continue;
        }
        if (!rotation_quick_add_entry_allowed($site)) {
            continue;
        }
        $title = trim((string)($site['title'] ?? $key));
        $items[] = [
            'label' => 'Web — ' . $title,
            'url' => web_page_url((string)$key),
            'dwell' => 60,
            'group' => 'Dashboards',
        ];
    }

    return $items;
}

/** @param array<string,mixed> $entry */
function rotation_quick_add_entry_allowed(array $entry): bool
{
    if (!function_exists('admin_entry_visible')) {
        require_once __DIR__ . '/users_lib.php';
    }
    if (function_exists('admin_is_super') && admin_is_super()) {
        return true;
    }

    return admin_entry_visible($entry);
}

function rotation_screen_display_name(string $screenKey, array $screens): string
{
    $scr = $screens[$screenKey] ?? null;
    if (is_array($scr)) {
        return (string)($scr['name'] ?? $screenKey);
    }
    return is_string($scr) ? $scr : $screenKey;
}

function rotation_pages_write(string $screen, array $pages): bool
{
    $key = 'rotation.PAGES_' . preg_replace('/[^a-z0-9_\-]/i', '', $screen);

    return cfg_update(function (array $conf) use ($key, $pages): array {
        $conf[$key] = $pages;

        return $conf;
    });
}

/**
 * Write several display playlists (and optionally the slide deck) in one settings pass.
 * @param array<string,list<array<string,mixed>>> $screenPages screen key => playlist rows
 * @param list<array<string,mixed>>|null $slideDeck when set, replaces slides.SLIDES
 */
function rotation_pages_write_batch(array $screenPages, ?array $slideDeck = null): bool
{
    if ($screenPages === [] && $slideDeck === null) {
        return true;
    }

    return cfg_update(function (array $conf) use ($screenPages, $slideDeck): array {
        if ($slideDeck !== null) {
            if ($slideDeck === []) {
                unset($conf['slides.SLIDES']);
            } else {
                $conf['slides.SLIDES'] = $slideDeck;
            }
        }
        foreach ($screenPages as $screen => $pages) {
            $key = 'rotation.PAGES_' . preg_replace('/[^a-z0-9_\-]/i', '', (string)$screen);
            $conf[$key] = $pages;
        }

        return $conf;
    });
}

/**
 * @return array{pages:list<array<string,mixed>>,added:bool,updated:bool,screen:string}
 */
function rotation_upsert_url(string $screen, string $url, int $dwell): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $url = trim($url);
    $pages = rotation_sync_source_pages($screen);
    $added = false;
    $updated = false;
    foreach ($pages as &$page) {
        if (!is_array($page)) {
            continue;
        }
        if (trim((string)($page['url'] ?? '')) !== $url) {
            continue;
        }
        if ((int)($page['dwell'] ?? 0) !== $dwell) {
            $page['dwell'] = $dwell;
            $updated = true;
        }
        unset($page);
        return ['pages' => $pages, 'added' => false, 'updated' => $updated, 'screen' => $screen];
    }
    unset($page);
    $pages[] = ['url' => $url, 'dwell' => $dwell];
    return ['pages' => $pages, 'added' => true, 'updated' => false, 'screen' => $screen];
}

function rotation_is_legacy_slides_url(string $url): bool
{
    return trim($url) === 'slides.php';
}

function rotation_is_slide_url(string $url): bool
{
    require_once __DIR__ . '/slides_lib.php';
    return slide_rotation_parse_file($url) !== null;
}

function rotation_is_legacy_rotator_url(string $url): bool
{
    require_once __DIR__ . '/rotator_lib.php';
    return rotator_is_legacy_url($url);
}

function rotation_is_photo_url(string $url): bool
{
    require_once __DIR__ . '/rotator_lib.php';
    return rotator_rotation_parse_file($url) !== null;
}

function rotation_is_rotator_group_url(string $url): bool
{
    require_once __DIR__ . '/rotator_lib.php';
    return rotator_rotation_parse_group($url) !== null;
}

function rotation_is_any_rotator_url(string $url): bool
{
    return rotation_is_legacy_rotator_url($url)
        || rotation_is_photo_url($url)
        || rotation_is_rotator_group_url($url);
}

/** @return list<array<string,mixed>> */
function rotation_strip_photo_pages(array $pages): array
{
    return array_values(array_filter($pages, static function ($page) {
        if (!is_array($page)) {
            return false;
        }
        $url = trim((string)($page['url'] ?? ''));
        return !rotation_is_any_rotator_url($url);
    }));
}

/** @return list<array<string,mixed>> */
function rotation_strip_slide_pages(array $pages): array
{
    return array_values(array_filter($pages, static function ($page) {
        if (!is_array($page)) {
            return false;
        }
        $url = trim((string)($page['url'] ?? ''));
        return !rotation_is_legacy_slides_url($url) && !rotation_is_slide_url($url);
    }));
}

/** @param list<array<string,mixed>> $pages */
function rotation_playlist_slide_count(array $pages): int
{
    $count = 0;
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if (rotation_is_legacy_slides_url($url) || rotation_is_slide_url($url)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Human page count — contiguous slide or photo blocks count as one board.
 * @return array{total:int,slide_entries:int,slide_blocks:int,photo_entries:int,photo_blocks:int}
 */
function rotation_playlist_counts(array $pageRows): array
{
    $total = 0;
    $slideEntries = 0;
    $slideBlocks = 0;
    $photoEntries = 0;
    $photoBlocks = 0;
    $inSlideBlock = false;
    $inPhotoBlock = false;

    foreach ($pageRows as $prow) {
        if (!is_array($prow)) {
            continue;
        }
        $url = trim((string)($prow['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (rotation_is_legacy_slides_url($url) || rotation_is_slide_url($url)) {
            $slideEntries++;
            if (!$inSlideBlock) {
                $slideBlocks++;
                $total++;
                $inSlideBlock = true;
            }
            $inPhotoBlock = false;
            continue;
        }
        if (rotation_is_photo_url($url)) {
            $photoEntries++;
            if (!$inPhotoBlock) {
                $photoBlocks++;
                $total++;
                $inPhotoBlock = true;
            }
            $inSlideBlock = false;
            continue;
        }
        $inSlideBlock = false;
        $inPhotoBlock = false;
        $total++;
    }

    return [
        'total' => $total,
        'slide_entries' => $slideEntries,
        'slide_blocks' => $slideBlocks,
        'photo_entries' => $photoEntries,
        'photo_blocks' => $photoBlocks,
    ];
}

/**
 * Split saved playlist rows into normal pages and contiguous slide blocks.
 * @return list<array{type:string,items?:list<array<string,mixed>>,row?:array<string,mixed>,url?:string,index?:int}>
 */
function rotation_playlist_segments(array $pageRows): array
{
    $segments = [];
    $slideBuf = [];
    $photoBuf = [];

    $flushSlides = static function () use (&$segments, &$slideBuf): void {
        if ($slideBuf !== []) {
            $segments[] = ['type' => 'slides', 'items' => $slideBuf];
            $slideBuf = [];
        }
    };
    $flushPhotos = static function () use (&$segments, &$photoBuf): void {
        if ($photoBuf !== []) {
            $segments[] = ['type' => 'photos', 'items' => $photoBuf];
            $photoBuf = [];
        }
    };

    foreach ($pageRows as $idx => $prow) {
        if (!is_array($prow)) {
            continue;
        }
        $purl = trim((string)($prow['url'] ?? ''));
        if ($purl === '') {
            $flushSlides();
            $flushPhotos();
            $segments[] = ['type' => 'page', 'index' => $idx, 'row' => $prow, 'url' => ''];
            continue;
        }
        $isSlide = rotation_is_legacy_slides_url($purl) || rotation_is_slide_url($purl);
        if ($isSlide) {
            $flushPhotos();
            $slideBuf[] = ['index' => $idx, 'row' => $prow, 'url' => $purl];
            continue;
        }
        if (rotation_is_photo_url($purl)) {
            $flushSlides();
            $photoBuf[] = ['index' => $idx, 'row' => $prow, 'url' => $purl];
            continue;
        }
        $flushSlides();
        $flushPhotos();
        $segments[] = ['type' => 'page', 'index' => $idx, 'row' => $prow, 'url' => $purl];
    }
    $flushSlides();
    $flushPhotos();

    return $segments;
}

/**
 * Collapse slide runs for the “on the wall” summary list.
 * @return list<array{label:string,detail:string}>
 */
function rotation_effective_playlist_lines(array $pages): array
{
    $lines = [];
    $slideRun = [];
    $photoRun = [];

    $flushSlides = static function () use (&$lines, &$slideRun): void {
        if ($slideRun !== []) {
            $lines[] = rotation_format_slide_run_line($slideRun);
            $slideRun = [];
        }
    };
    $flushPhotos = static function () use (&$lines, &$photoRun): void {
        if ($photoRun !== []) {
            $lines[] = rotation_format_photo_run_line($photoRun);
            $photoRun = [];
        }
    };

    foreach ($pages as $ep) {
        if (!is_array($ep) || empty($ep['url']) || !empty($ep['off'])) {
            continue;
        }
        $url = trim((string)$ep['url']);
        if (rotation_is_legacy_slides_url($url) || rotation_is_slide_url($url)) {
            $flushPhotos();
            $slideRun[] = $ep;
            continue;
        }
        if (rotation_is_photo_url($url)) {
            $flushSlides();
            $photoRun[] = $ep;
            continue;
        }
        $flushSlides();
        $flushPhotos();
        $dwell = (int)($ep['dwell'] ?? 0);
        $lines[] = [
            'label' => rotation_page_label($url),
            'detail' => $url . ($dwell > 0 ? ' · ' . $dwell . 's' : ''),
        ];
    }
    $flushSlides();
    $flushPhotos();

    return $lines;
}

/** @param list<array<string,mixed>> $run @return array{label:string,detail:string} */
function rotation_format_slide_run_line(array $run): array
{
    $count = count($run);
    $dwells = array_values(array_filter(array_map(static fn($p) => (int)($p['dwell'] ?? 0), $run)));
    $dwellLabel = '';
    if ($dwells !== []) {
        $min = min($dwells);
        $max = max($dwells);
        $dwellLabel = $min === $max ? (' · ' . $min . 's each') : (' · ' . $min . '–' . $max . 's');
    }
    if ($count === 1 && rotation_is_legacy_slides_url(trim((string)($run[0]['url'] ?? '')))) {
        return [
            'label' => 'Custom slides (legacy)',
            'detail' => 'slides.php — deploy from Custom Slides to split into per-slide entries' . $dwellLabel,
        ];
    }

    return [
        'label' => 'Custom slides (' . $count . ')',
        'detail' => $count . ' slide' . ($count === 1 ? '' : 's') . $dwellLabel,
    ];
}

/** @param list<array<string,mixed>> $run @return array{label:string,detail:string} */
function rotation_format_photo_run_line(array $run): array
{
    $count = count($run);
    $dwells = array_values(array_filter(array_map(static fn($p) => (int)($p['dwell'] ?? 0), $run)));
    $dwellLabel = '';
    if ($dwells !== []) {
        $min = min($dwells);
        $max = max($dwells);
        $dwellLabel = $min === $max ? (' · ' . $min . 's each') : (' · ' . $min . '–' . $max . 's');
    }

    return [
        'label' => 'Photos (' . $count . ')',
        'detail' => $count . ' photo' . ($count === 1 ? '' : 's') . $dwellLabel,
    ];
}

/** @return array{pages:list<array<string,mixed>>,added:int,updated:int,removed_legacy:bool,photo_count:int,screen:string} */
function rotation_sync_photos(string $screen = 'main', ?array $deck = null): array
{
    require_once __DIR__ . '/rotator_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $pages = rotation_sync_source_pages($screen);
    $expected = rotator_rotation_pages($deck, $screen);
    $expectedByUrl = [];
    foreach ($expected as $row) {
        $expectedByUrl[$row['url']] = (int)$row['dwell'];
    }

    if ($expected === []) {
        return [
            'pages' => $pages,
            'added' => 0,
            'updated' => 0,
            'removed_legacy' => false,
            'photo_count' => 0,
            'screen' => $screen,
        ];
    }

    $insertAt = null;
    $filtered = [];
    $oldPhotoUrls = [];
    $removedLegacy = false;

    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if (rotation_is_any_rotator_url($url)) {
            if ($insertAt === null) {
                $insertAt = count($filtered);
            }
            if (rotation_is_legacy_rotator_url($url)) {
                $removedLegacy = true;
            }
            $oldPhotoUrls[$url] = $page;
            continue;
        }
        $filtered[] = $page;
    }
    if ($insertAt === null) {
        $insertAt = count($filtered);
    }

    $photoPages = [];
    $added = 0;
    $updated = 0;
    foreach ($expectedByUrl as $url => $dwell) {
        $prev = $oldPhotoUrls[$url] ?? null;
        if (!is_array($prev)) {
            $added++;
        } elseif ((int)($prev['dwell'] ?? 0) !== $dwell) {
            $updated++;
        }
        $row = ['url' => $url, 'dwell' => $dwell];
        if (is_array($prev)) {
            $row = rotation_merge_page_meta($row, $prev);
        }
        $photoPages[] = $row;
    }
    foreach (array_keys($oldPhotoUrls) as $oldUrl) {
        if (!array_key_exists($oldUrl, $expectedByUrl)) {
            $updated++;
        }
    }
    if ($removedLegacy && $expected !== []) {
        $updated = max($updated, 1);
    }

    array_splice($filtered, $insertAt, 0, $photoPages);

    return [
        'pages' => $filtered,
        'added' => $added,
        'updated' => $updated,
        'removed_legacy' => $removedLegacy,
        'photo_count' => count($photoPages),
        'screen' => $screen,
    ];
}

/** @return array{pages:list<array<string,mixed>>,removed:bool,removed_count:int,screen:string} */
function rotation_remove_all_photos(string $screen): array
{
    $screen = rotation_normalize_screen_key($screen);
    $pages = rotation_screen_own_pages($screen);
    $stripped = rotation_strip_photo_pages($pages);
    return [
        'pages' => $stripped,
        'removed' => count($stripped) < count($pages),
        'removed_count' => count($pages) - count($stripped),
        'screen' => $screen,
    ];
}

/** @return array{expected:int,synced:int,first_index:?int,last_index:?int,dwell_mismatch:int,on_playlist:bool,partial:bool} */
function rotator_rotation_sync_info(string $screen = 'main', ?array $deck = null): array
{
    require_once __DIR__ . '/rotator_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $expected = rotator_rotation_pages($deck, $screen);
    $own = rotation_screen_own_pages($screen);
    $byUrl = [];
    foreach ($own as $i => $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if (rotation_is_legacy_rotator_url($url)) {
            $byUrl['__legacy__'] = ['index' => $i + 1, 'dwell' => (int)($page['dwell'] ?? 0), 'off' => !empty($page['off'])];
            continue;
        }
        if (!rotation_is_any_rotator_url($url)) {
            continue;
        }
        $byUrl[$url] = [
            'index' => $i + 1,
            'dwell' => (int)($page['dwell'] ?? 0),
            'off' => !empty($page['off']),
        ];
    }

    $synced = 0;
    $dwellMismatch = 0;
    $indices = [];
    foreach ($expected as $exp) {
        $url = $exp['url'];
        if (!isset($byUrl[$url]) || !empty($byUrl[$url]['off'])) {
            continue;
        }
        $synced++;
        $indices[] = (int)$byUrl[$url]['index'];
        if ((int)$byUrl[$url]['dwell'] !== (int)$exp['dwell']) {
            $dwellMismatch++;
        }
    }

    $expectedCount = count($expected);
    return [
        'expected' => $expectedCount,
        'synced' => $synced,
        'first_index' => $indices !== [] ? min($indices) : null,
        'last_index' => $indices !== [] ? max($indices) : null,
        'dwell_mismatch' => $dwellMismatch,
        'on_playlist' => $expectedCount > 0 && $synced === $expectedCount && !isset($byUrl['__legacy__']),
        'partial' => $synced > 0 && ($synced < $expectedCount || isset($byUrl['__legacy__'])),
    ];
}

/**
 * Per-display deploy status for the photo rotator admin panel.
 * @return array<string,array<string,mixed>>
 */
function rotator_deploy_status(?array $deck = null): array
{
    require_once __DIR__ . '/rotator_lib.php';
    $deck = rotator_deck($deck);
    $out = [];

    foreach (rotation_screens() as $key => $scr) {
        $own = rotation_screen_own_pages($key);
        $mirrorsMain = ($key !== 'main' && $own === []);
        $sync = rotator_rotation_sync_info($key, $deck);
        $deckTargeted = count(rotator_rotation_pages($deck, $key));

        $wallPhotos = 0;
        $wallPos = null;
        $pos = 0;
        foreach (rotation_screen_active_pages($key) as $page) {
            if (!is_array($page) || empty($page['url'])) {
                continue;
            }
            $pos++;
            $url = trim((string)$page['url']);
            if (rotation_is_any_rotator_url($url)) {
                if ($wallPos === null) {
                    $wallPos = $pos;
                }
                if (rotation_is_photo_url($url)) {
                    $wallPhotos++;
                } else {
                    $wallPhotos = max($wallPhotos, 1);
                }
            }
        }

        $out[$key] = [
            'name' => (string)($scr['name'] ?? $key),
            'mirrors_main' => $mirrorsMain,
            'on_playlist' => $sync['on_playlist'],
            'partial' => $sync['partial'],
            'on_wall' => $wallPhotos > 0,
            'sync' => $sync,
            'wall' => $wallPhotos > 0 ? ['position' => $wallPos, 'photo_count' => $wallPhotos] : null,
            'deck_targeted' => $deckTargeted,
            'expected' => (int)$sync['expected'],
            'dwell_mismatch' => (int)$sync['dwell_mismatch'],
        ];
    }

    return $out;
}

/**
 * Sync photo rotation entries onto selected screens.
 * @param list<string> $screens
 * @return array{added:int,updated:int,screens:list<string>,photo_count:int,skipped:list<string>}
 */
function rotator_deploy_to_screens(array $screens, ?array $deck = null): array
{
    require_once __DIR__ . '/rotator_lib.php';
    $added = 0;
    $updated = 0;
    $photoCount = 0;
    $skipped = [];
    $done = [];
    $deployed = [];
    $pageWrites = [];
    foreach ($screens as $screen) {
        $screen = rotation_normalize_screen_key((string)$screen);
        if ($screen === '' || isset($done[$screen])) {
            continue;
        }
        $done[$screen] = true;
        $expected = rotator_rotation_pages($deck, $screen);
        if ($expected === []) {
            $skipped[] = $screen;
            continue;
        }
        $before = rotation_sync_source_pages($screen);
        $slidesBefore = rotation_playlist_slide_count($before);
        $sync = rotation_sync_photos($screen, $deck);
        $slidesAfter = rotation_playlist_slide_count($sync['pages']);
        if ($slidesBefore > 0 && $slidesAfter < $slidesBefore) {
            $skipped[] = $screen;
            continue;
        }
        $deployed[] = $screen;
        $pageWrites[$sync['screen']] = $sync['pages'];
        $added += (int)$sync['added'];
        $updated += (int)$sync['updated'];
        $photoCount = max($photoCount, (int)$sync['photo_count']);
    }
    if ($pageWrites !== []) {
        rotation_pages_write_batch($pageWrites);
        cfg_reload();
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'screens' => $deployed,
        'photo_count' => $photoCount,
        'skipped' => $skipped,
    ];
}

function rotator_deploy_flash_message(array $result): string
{
    $photoCount = (int)($result['photo_count'] ?? 0);
    $screenCount = count($result['screens'] ?? []);
    $parts = [];
    if ($photoCount > 0 && $screenCount > 0) {
        $parts[] = 'synced ' . $photoCount . ' photo entr' . ($photoCount === 1 ? 'y' : 'ies')
            . ' to ' . $screenCount . ' display' . ($screenCount === 1 ? '' : 's');
    }
    if (($result['added'] ?? 0) > 0) {
        $parts[] = (int)$result['added'] . ' new photo entr' . ((int)$result['added'] === 1 ? 'y' : 'ies');
    }
    if (($result['updated'] ?? 0) > 0) {
        $parts[] = (int)$result['updated'] . ' dwell/order update' . ((int)$result['updated'] === 1 ? '' : 's');
    }
    if ($parts === []) {
        return 'Photos already synced on selected displays.';
    }
    return 'Photo rotator ' . implode('; ', $parts) . '.';
}

/** @return array{pages:list<array<string,mixed>>,added:int,updated:int,removed_legacy:bool,slide_count:int,screen:string} */
function rotation_sync_slides(string $screen = 'main', ?array $deck = null, ?array $scopeFiles = null, bool $allowRecovery = false): array
{
    require_once __DIR__ . '/slides_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $pages = rotation_sync_source_pages($screen);
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($deck)) {
        $deck = [];
    }
    $manageAllSlides = ($scopeFiles === null);
    $scopeSet = $manageAllSlides ? null : array_flip($scopeFiles);
    $expected = slides_rotation_pages_for_scope($deck, $screen, $scopeFiles, $allowRecovery);
    $expectedByUrl = [];
    foreach ($expected as $row) {
        $expectedByUrl[$row['url']] = (int)$row['dwell'];
    }

    if ($expected === []) {
        return [
            'pages' => $pages,
            'added' => 0,
            'updated' => 0,
            'removed_legacy' => false,
            'slide_count' => 0,
            'screen' => $screen,
        ];
    }

    $insertAt = null;
    $filtered = [];
    $oldSlideUrls = [];
    $removedLegacy = false;

    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if (rotation_is_legacy_slides_url($url)) {
            if (!$manageAllSlides) {
                $filtered[] = $page;
                continue;
            }
            if ($insertAt === null) {
                $insertAt = count($filtered);
            }
            $removedLegacy = true;
            continue;
        }
        if (rotation_is_slide_url($url)) {
            $file = slide_rotation_parse_file($url);
            if (!$manageAllSlides && $file !== null && !isset($scopeSet[$file])) {
                $filtered[] = $page;
                continue;
            }
            if ($insertAt === null) {
                $insertAt = count($filtered);
            }
            $oldSlideUrls[$url] = $page;
            continue;
        }
        $filtered[] = $page;
    }
    if ($insertAt === null) {
        $insertAt = count($filtered);
    }

    $slidePages = [];
    $added = 0;
    $updated = 0;
    foreach ($expectedByUrl as $url => $dwell) {
        $prev = $oldSlideUrls[$url] ?? null;
        if (!is_array($prev)) {
            $added++;
        } elseif ((int)($prev['dwell'] ?? 0) !== $dwell) {
            $updated++;
        }
        $row = ['url' => $url, 'dwell' => $dwell];
        if (is_array($prev)) {
            $row = rotation_merge_page_meta($row, $prev);
        }
        $slidePages[] = $row;
    }
    foreach (array_keys($oldSlideUrls) as $oldUrl) {
        if (!array_key_exists($oldUrl, $expectedByUrl)) {
            $updated++;
        }
    }
    if ($removedLegacy && $expected !== []) {
        $updated = max($updated, 1);
    }

    array_splice($filtered, $insertAt, 0, $slidePages);

    return [
        'pages' => $filtered,
        'added' => $added,
        'updated' => $updated,
        'removed_legacy' => $removedLegacy,
        'slide_count' => count($slidePages),
        'screen' => $screen,
    ];
}

/** @deprecated Use slide_rotation_url() per slide. Kept for legacy config checks. */
function slides_rotation_url(): string
{
    return 'slides.php';
}

function slides_in_rotation(string $screen = 'main'): bool
{
    return slides_rotation_sync_info($screen)['on_playlist'];
}

/** @return array{expected:int,synced:int,first_index:?int,last_index:?int,dwell_mismatch:int,on_playlist:bool,partial:bool} */
function slides_rotation_sync_info(string $screen = 'main', ?array $deck = null): array
{
    require_once __DIR__ . '/slides_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $expected = slides_rotation_pages($deck, $screen);
    $own = rotation_screen_own_pages($screen);
    $byUrl = [];
    foreach ($own as $i => $page) {
        if (!is_array($page)) {
            continue;
        }
        $url = trim((string)($page['url'] ?? ''));
        if (rotation_is_legacy_slides_url($url)) {
            $byUrl['__legacy__'] = ['index' => $i + 1, 'dwell' => (int)($page['dwell'] ?? 0), 'off' => !empty($page['off'])];
            continue;
        }
        $file = slide_rotation_parse_file($url);
        if ($file === null) {
            continue;
        }
        $byUrl[$url] = [
            'index' => $i + 1,
            'dwell' => (int)($page['dwell'] ?? 0),
            'off' => !empty($page['off']),
            'file' => $file,
        ];
    }

    $synced = 0;
    $dwellMismatch = 0;
    $indices = [];
    foreach ($expected as $exp) {
        $url = $exp['url'];
        if (!isset($byUrl[$url]) || !empty($byUrl[$url]['off'])) {
            continue;
        }
        $synced++;
        $indices[] = (int)$byUrl[$url]['index'];
        if ((int)$byUrl[$url]['dwell'] !== (int)$exp['dwell']) {
            $dwellMismatch++;
        }
    }

    $expectedCount = count($expected);
    return [
        'expected' => $expectedCount,
        'synced' => $synced,
        'first_index' => $indices !== [] ? min($indices) : null,
        'last_index' => $indices !== [] ? max($indices) : null,
        'dwell_mismatch' => $dwellMismatch,
        'on_playlist' => $expectedCount > 0 && $synced === $expectedCount && !isset($byUrl['__legacy__']),
        'partial' => $synced > 0 && ($synced < $expectedCount || isset($byUrl['__legacy__'])),
    ];
}

/** @return array{index:int,dwell:int,from:mixed,to:mixed,off:bool}|null */
function slides_rotation_entry(string $screen = 'main'): ?array
{
    $info = slides_rotation_sync_info($screen);
    if ($info['synced'] === 0 && !$info['partial']) {
        return null;
    }
    return [
        'index' => (int)($info['first_index'] ?? 1),
        'last_index' => (int)($info['last_index'] ?? $info['first_index'] ?? 1),
        'count' => (int)$info['synced'],
        'expected' => (int)$info['expected'],
        'dwell_mismatch' => (int)$info['dwell_mismatch'],
        'off' => false,
        'from' => null,
        'to' => null,
    ];
}

/**
 * Per-display deploy status for the slides admin panel.
 * @return array<string,array<string,mixed>>
 */
function slides_deploy_status(?array $deck = null): array
{
    require_once __DIR__ . '/slides_lib.php';
    $deck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    $out = [];

    foreach (rotation_screens() as $key => $scr) {
        $own = rotation_screen_own_pages($key);
        $mirrorsMain = ($key !== 'main' && $own === []);
        $sync = slides_rotation_sync_info($key, $deck);
        $deckTargeted = count(slides_rotation_pages($deck, $key));
        $playlistSlides = rotation_playlist_slide_count($own);

        $wallSlides = 0;
        $wallPos = null;
        $pos = 0;
        foreach (rotation_screen_active_pages($key) as $page) {
            if (!is_array($page) || empty($page['url'])) {
                continue;
            }
            $pos++;
            $url = trim((string)$page['url']);
            if (rotation_is_slide_url($url) || rotation_is_legacy_slides_url($url)) {
                if ($wallPos === null) {
                    $wallPos = $pos;
                }
                $wallSlides++;
            }
        }

        $out[$key] = [
            'name' => (string)($scr['name'] ?? $key),
            'mirrors_main' => $mirrorsMain,
            'on_playlist' => $sync['on_playlist'],
            'partial' => $sync['partial'],
            'on_wall' => $wallSlides > 0,
            'entry' => slides_rotation_entry($key),
            'sync' => $sync,
            'wall' => $wallSlides > 0 ? ['position' => $wallPos, 'slide_count' => $wallSlides] : null,
            'deck_targeted' => $deckTargeted,
            'playlist_slides' => $playlistSlides,
            'stale_on_playlist' => $playlistSlides > (int)$sync['expected']
                && ($playlistSlides > $deckTargeted || ($deckTargeted === 0 && $playlistSlides > 0)),
            'expected' => (int)$sync['expected'],
            'dwell_mismatch' => (int)$sync['dwell_mismatch'],
        ];
    }

    return $out;
}

/**
 * @return array{pages:list<array<string,mixed>>,removed:bool,removed_count:int,screen:string}
 */
function rotation_remove_all_slides(string $screen): array
{
    $screen = rotation_normalize_screen_key($screen);
    $pages = rotation_screen_own_pages($screen);
    $stripped = rotation_strip_slide_pages($pages);
    return [
        'pages' => $stripped,
        'removed' => count($stripped) < count($pages),
        'removed_count' => count($pages) - count($stripped),
        'screen' => $screen,
    ];
}

/**
 * @return array{pages:list<array<string,mixed>>,removed:bool,screen:string}
 */
function rotation_remove_url(string $screen, string $url): array
{
    $screen = rotation_normalize_screen_key($screen);
    $url = trim($url);
    $pages = rotation_screen_own_pages($screen);
    $removed = false;
    $pages = array_values(array_filter($pages, function ($page) use ($url, &$removed) {
        if (!is_array($page)) {
            return false;
        }
        if (trim((string)($page['url'] ?? '')) === $url) {
            $removed = true;
            return false;
        }
        return true;
    }));

    return ['pages' => $pages, 'removed' => $removed, 'screen' => $screen];
}

/**
 * Sync one rotation entry per enabled slide onto selected screens.
 * @param list<string> $screens
 * @return array{added:int,updated:int,screens:list<string>,slide_count:int,skipped:list<string>,repaired:bool}
 */
function slides_deploy_to_screens(array $screens, ?array $deck = null): array
{
    require_once __DIR__ . '/slides_lib.php';
    $fullDeck = is_array($deck) ? $deck : cfg('slides.SLIDES', []);
    if (!is_array($fullDeck)) {
        $fullDeck = [];
    }
    $scopeFiles = slides_deploy_scope_files($fullDeck);
    $recoveryDeploy = slides_deck_targets_no_configured_screens($fullDeck)
        || slides_deck_untargeted_misconfig($fullDeck);
    $repairedDeck = slides_repair_deck($fullDeck, $recoveryDeploy);
    $deckRepaired = $repairedDeck !== $fullDeck;
    if ($deckRepaired) {
        $fullDeck = $repairedDeck;
    }
    $onDisk = count(slides_rotation_pages($fullDeck, null));
    $added = 0;
    $updated = 0;
    $slideCount = 0;
    $skipped = [];
    $done = [];
    $deployed = [];
    $pageWrites = [];
    if ($scopeFiles !== null && $scopeFiles === []) {
        foreach ($screens as $screen) {
            $screen = rotation_normalize_screen_key((string)$screen);
            if ($screen !== '') {
                $skipped[] = $screen;
            }
        }

        return [
            'added' => 0,
            'updated' => 0,
            'screens' => [],
            'slide_count' => 0,
            'skipped' => array_values(array_unique($skipped)),
            'repaired' => $deckRepaired,
            'recovery_deploy' => false,
            'on_disk' => $onDisk,
            'no_scope' => true,
        ];
    }
    foreach ($screens as $screen) {
        $screen = rotation_normalize_screen_key((string)$screen);
        if ($screen === '' || isset($done[$screen])) {
            continue;
        }
        $done[$screen] = true;
        $expected = slides_deploy_expected_pages($fullDeck, $screen, $scopeFiles);
        if ($expected === []) {
            $skipped[] = $screen;
            continue;
        }
        $syncScope = ($recoveryDeploy && $scopeFiles === null) ? null : $scopeFiles;
        $sync = rotation_sync_slides($screen, $fullDeck, $syncScope, $recoveryDeploy);
        $deployed[] = $screen;
        $pageWrites[$sync['screen']] = $sync['pages'];
        $added += (int)$sync['added'];
        $updated += (int)$sync['updated'];
        $slideCount = max($slideCount, (int)$sync['slide_count']);
    }
    if ($pageWrites !== [] || $deckRepaired) {
        rotation_pages_write_batch($pageWrites, $deckRepaired ? $fullDeck : null);
        cfg_reload();
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'screens' => $deployed,
        'slide_count' => $slideCount,
        'skipped' => $skipped,
        'repaired' => $deckRepaired,
        'recovery_deploy' => $recoveryDeploy && $deployed !== [],
        'on_disk' => $onDisk,
        'no_scope' => false,
    ];
}

function slides_deploy_flash_message(array $result): string
{
    $slideCount = (int)($result['slide_count'] ?? 0);
    $screenCount = count($result['screens'] ?? []);
    $skipped = $result['skipped'] ?? [];
    $skippedCount = is_array($skipped) ? count($skipped) : 0;
    $onDisk = (int)($result['on_disk'] ?? 0);
    if (!empty($result['no_scope'])) {
        return 'No slides in the deck are yours to deploy. Ask a super admin to share slides with you or deploy as super admin.';
    }
    $parts = [];
    if ($slideCount > 0 && $screenCount > 0) {
        $parts[] = 'synced ' . $slideCount . ' slide' . ($slideCount === 1 ? '' : 's')
            . ' to ' . $screenCount . ' display' . ($screenCount === 1 ? '' : 's');
    }
    if (($result['added'] ?? 0) > 0) {
        $parts[] = (int)$result['added'] . ' new slide entr' . ((int)$result['added'] === 1 ? 'y' : 'ies');
    }
    if (($result['updated'] ?? 0) > 0) {
        $parts[] = (int)$result['updated'] . ' dwell/order update' . ((int)$result['updated'] === 1 ? '' : 's');
    }
    if (!empty($result['recovery_deploy'])) {
        $parts[] = 'used recovery deploy (deck slides were not assigned to any display — targeting reset in settings)';
    }
    if ($skippedCount > 0) {
        $screenMap = rotation_screens();
        $labels = [];
        foreach ($skipped as $sk) {
            $labels[] = rotation_screen_display_name((string)$sk, $screenMap);
        }
        $labelText = $labels !== [] ? implode(', ', $labels) : (string)$skippedCount . ' display' . ($skippedCount === 1 ? '' : 's');
        if ($onDisk === 0) {
            $parts[] = 'skipped ' . $labelText . ' (deck has no enabled slide files on disk — check File missing on Deck)';
        } else {
            $parts[] = 'skipped ' . $labelText . ' (no deployable slides for ' . ($skippedCount === 1 ? 'that display' : 'those displays') . ')';
        }
    }
    if (!empty($result['repaired'])) {
        $parts[] = 'repaired display targeting in the deck';
    }
    if ($parts === []) {
        if ($skippedCount > 0) {
            if ($onDisk === 0) {
                return 'Deploy skipped — the deck lists no slide image files on the server (or every slide is disabled). Check the Deck tab for File missing warnings.';
            }
            $screenMap = rotation_screens();
            $labels = [];
            foreach ($skipped as $sk) {
                $labels[] = rotation_screen_display_name((string)$sk, $screenMap);
            }
            $which = $labels !== [] ? implode(', ', $labels) : 'the selected display(s)';
            return 'Deploy skipped for ' . $which . ' — ' . $onDisk . ' slide file'
                . ($onDisk === 1 ? '' : 's') . ' in deck but none could be pushed. Try All displays (deck) on the Deck tab, Save, then Deploy.';
        }
        return 'Slides already synced on selected displays.';
    }
    return 'Custom slides ' . implode('; ', $parts) . '.';
}

/**
 * Add or update rotation entries for every RSS feed.
 * @return array{pages:list<array<string,mixed>>,added:list<string>,updated:list<string>,screen:string}
 */
function rotation_sync_rss(string $screen = 'main'): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $pages = rotation_screen_own_pages($screen);
    if ($pages === [] && $screen === 'main') {
        $pages = rotation_screen_effective_pages('main');
    }
    $added = [];
    $updated = [];
    $feeds = cfg('rss.FEEDS', []);
    if (!is_array($feeds)) {
        return ['pages' => $pages, 'added' => [], 'updated' => [], 'screen' => $screen];
    }
    foreach ($feeds as $key => $feed) {
        if (!is_array($feed)) {
            continue;
        }
        if (function_exists('admin_entry_visible') && !admin_entry_visible($feed)) {
            continue;
        }
        $url = rss_feed_url((string)$key);
        $dwell = rotation_rss_feed_dwell($feed);
        $found = false;
        foreach ($pages as &$page) {
            if (!is_array($page)) {
                continue;
            }
            if (trim((string)($page['url'] ?? '')) !== $url) {
                continue;
            }
            if ((int)($page['dwell'] ?? 0) !== $dwell) {
                $page['dwell'] = $dwell;
                $updated[] = (string)$key;
            }
            $found = true;
            break;
        }
        unset($page);
        if (!$found) {
            $pages[] = ['url' => $url, 'dwell' => $dwell];
            $added[] = (string)$key;
        }
    }
    return ['pages' => $pages, 'added' => $added, 'updated' => $updated, 'screen' => $screen];
}
