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

/** @return array{name:string,shuffle:bool,show_ticker:bool,show_clock:bool,show_debug:bool,weighted:bool,fade_ms:int,settle_ms:int,hang_ms:int,schedule:array{enabled:bool,off:int,on:int},cec:array{enabled:bool,off:int,on:int,device:int}} */
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
    $weighted = !empty($scr['weighted']);
    $transition = rotation_screen_transition_from_scr($scr);

    return [
        'name' => $name !== '' ? $name : ($screen === 'main' ? 'Main Display' : $screen),
        'shuffle' => !empty($scr['shuffle']),
        'show_ticker' => $showTicker,
        'show_clock' => $showClock,
        'show_debug' => $showDebug,
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
    if (!$sched['enabled']) {
        return false;
    }

    return rotation_in_off_window($sched['off'], $sched['on']);
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
        'weighted' => !empty($row['weighted']),
        'fade_ms' => isset($row['fade_ms']) ? (string)(int)$row['fade_ms'] : '',
        'settle_ms' => isset($row['settle_ms']) ? (string)(int)$row['settle_ms'] : '',
        'hang_ms' => isset($row['hang_ms']) ? (string)(int)$row['hang_ms'] : '',
        'schedule_enabled' => $sched['enabled'],
        'cec_enabled' => $cec['enabled'],
        'cec_off' => (string)$sched['off'],
        'cec_on' => (string)$sched['on'],
    ];
}

/** @return array{enabled:bool,off:int,on:int} */
function rotation_schedule_defaults(): array
{
    return ['enabled' => false, 'off' => 23, 'on' => 6];
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
    $block = is_array($scr['schedule'] ?? null) ? $scr['schedule'] : [];
    if (!empty($block['enabled']) || !empty($scr['schedule_enabled'])) {
        $sched['enabled'] = true;
    } elseif (!array_key_exists('schedule', $scr) && !array_key_exists('schedule_enabled', $scr)) {
        // Legacy configs only had CEC — blank the screen on the same hours (works without HDMI-CEC).
        $cec = rotation_cec_from_screen($scr);
        if ($cec['enabled']) {
            $sched['enabled'] = true;
            $sched['off'] = $cec['off'];
            $sched['on'] = $cec['on'];
        }
    }

    return $sched;
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
    $standby = $cec['enabled'] && rotation_in_off_window($cec['off'], $cec['on'], $hour);
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
    $pages = cfg("rotation.PAGES_$screen", null);
    return is_array($pages) ? $pages : [];
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
    require_once __DIR__ . '/slides_lib.php';
    $activeSlides = slides_active_entries();
    $activeFiles = [];
    foreach ($activeSlides as $slide) {
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

    return array_values(array_filter(
        $effective,
        static function ($p) use ($activeFiles, $hasSlideEntries) {
            if (!is_array($p)
                || trim((string)($p['url'] ?? '')) === ''
                || (int)($p['dwell'] ?? 0) <= 0
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
        $obj = [];
        $any = false;
        foreach (['url', 'dwell', 'from', 'to'] as $col) {
            $v = $row[$col] ?? '';
            if ($v === '') {
                continue;
            }
            $any = true;
            $obj[$col] = in_array($col, ['dwell', 'from', 'to'], true) ? (int)$v : $v;
        }
        $wRaw = trim((string)($row['weight'] ?? ''));
        if ($wRaw !== '') {
            $w = max(1, min(20, (int)$wRaw));
            if ($w > 1) {
                $obj['weight'] = $w;
                $any = true;
            }
        }
        if (($row['off'] ?? '') !== '') {
            $obj['off'] = true;
        }
        if ($any) {
            $outV[] = $obj;
        }
    }
    return $outV;
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
        'family.php' => 'Family calendar',
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
        ['label' => 'Family calendar', 'url' => 'family.php', 'dwell' => 90, 'group' => 'Boards'],
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
 * @return array{pages:list<array<string,mixed>>,added:bool,updated:bool,screen:string}
 */
function rotation_upsert_url(string $screen, string $url, int $dwell): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $url = trim($url);
    $pages = rotation_screen_pages($screen);
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
    $pages = rotation_screen_pages($screen);
    $expected = rotator_rotation_pages($deck, $screen);
    $expectedByUrl = [];
    foreach ($expected as $row) {
        $expectedByUrl[$row['url']] = (int)$row['dwell'];
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
            $oldPhotoUrls[$url] = (int)($page['dwell'] ?? 0);
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
        if (!array_key_exists($url, $oldPhotoUrls)) {
            $added++;
        } elseif ($oldPhotoUrls[$url] !== $dwell) {
            $updated++;
        }
        $photoPages[] = ['url' => $url, 'dwell' => $dwell];
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
    $pages = rotation_screen_pages($screen);
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
    $stats = rotator_deck_stats($deck);
    $expected = (int)$stats['playlist_entries'];
    $out = [];

    foreach (rotation_screens() as $key => $scr) {
        $own = rotation_screen_own_pages($key);
        $mirrorsMain = ($key !== 'main' && $own === []);
        $sync = rotator_rotation_sync_info($key, $deck);

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
            'expected' => $expected,
            'dwell_mismatch' => (int)$sync['dwell_mismatch'],
        ];
    }

    return $out;
}

/**
 * Sync photo rotation entries onto selected screens.
 * @param list<string> $screens
 * @return array{added:int,updated:int,screens:list<string>,photo_count:int}
 */
function rotator_deploy_to_screens(array $screens, ?array $deck = null): array
{
    require_once __DIR__ . '/rotator_lib.php';
    $added = 0;
    $updated = 0;
    $photoCount = 0;
    $done = [];
    foreach ($screens as $screen) {
        $screen = rotation_normalize_screen_key((string)$screen);
        if ($screen === '' || isset($done[$screen])) {
            continue;
        }
        $done[$screen] = true;
        $sync = rotation_sync_photos($screen, $deck);
        rotation_pages_write($sync['screen'], $sync['pages']);
        $added += (int)$sync['added'];
        $updated += (int)$sync['updated'];
        $photoCount = max($photoCount, (int)$sync['photo_count']);
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'screens' => array_keys($done),
        'photo_count' => $photoCount,
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
function rotation_sync_slides(string $screen = 'main', ?array $deck = null): array
{
    require_once __DIR__ . '/slides_lib.php';
    $screen = rotation_normalize_screen_key($screen);
    $pages = rotation_screen_pages($screen);
    $expected = slides_rotation_pages($deck, $screen);
    $expectedByUrl = [];
    foreach ($expected as $row) {
        $expectedByUrl[$row['url']] = (int)$row['dwell'];
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
            if ($insertAt === null) {
                $insertAt = count($filtered);
            }
            $removedLegacy = true;
            continue;
        }
        if (rotation_is_slide_url($url)) {
            if ($insertAt === null) {
                $insertAt = count($filtered);
            }
            $oldSlideUrls[$url] = (int)($page['dwell'] ?? 0);
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
        if (!array_key_exists($url, $oldSlideUrls)) {
            $added++;
        } elseif ($oldSlideUrls[$url] !== $dwell) {
            $updated++;
        }
        $slidePages[] = ['url' => $url, 'dwell' => $dwell];
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
    $stats = slides_deck_stats($deck);
    $expected = (int)$stats['playlist_entries'];
    $out = [];

    foreach (rotation_screens() as $key => $scr) {
        $own = rotation_screen_own_pages($key);
        $mirrorsMain = ($key !== 'main' && $own === []);
        $sync = slides_rotation_sync_info($key, $deck);

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
            'expected' => $expected,
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
    $pages = rotation_screen_pages($screen);
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
 * @return array{added:int,updated:int,screens:list<string>,slide_count:int}
 */
function slides_deploy_to_screens(array $screens, ?array $deck = null): array
{
    require_once __DIR__ . '/slides_lib.php';
    $added = 0;
    $updated = 0;
    $slideCount = 0;
    $done = [];
    foreach ($screens as $screen) {
        $screen = rotation_normalize_screen_key((string)$screen);
        if ($screen === '' || isset($done[$screen])) {
            continue;
        }
        $done[$screen] = true;
        $sync = rotation_sync_slides($screen, $deck);
        rotation_pages_write($sync['screen'], $sync['pages']);
        $added += (int)$sync['added'];
        $updated += (int)$sync['updated'];
        $slideCount = max($slideCount, (int)$sync['slide_count']);
    }

    return [
        'added' => $added,
        'updated' => $updated,
        'screens' => array_keys($done),
        'slide_count' => $slideCount,
    ];
}

function slides_deploy_flash_message(array $result): string
{
    $slideCount = (int)($result['slide_count'] ?? 0);
    $screenCount = count($result['screens'] ?? []);
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
    if ($parts === []) {
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
    $pages = rotation_screen_pages($screen);
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
