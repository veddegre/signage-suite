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

/** @return array{name:string,shuffle:bool,cec:array{enabled:bool,off:int,on:int,device:int}} */
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
    $defaults = [
        'name' => $screen === 'main' ? 'Main Display' : $screen,
        'shuffle' => false,
        'cec' => rotation_cec_defaults(),
    ];
    if ($scr === null) {
        return $defaults;
    }
    if (is_string($scr)) {
        return ['name' => $scr, 'shuffle' => false, 'cec' => rotation_cec_defaults()];
    }
    if (!is_array($scr)) {
        return $defaults;
    }
    $name = trim((string)($scr['name'] ?? ''));
    return [
        'name' => $name !== '' ? $name : ($screen === 'main' ? 'Main Display' : $screen),
        'shuffle' => !empty($scr['shuffle']),
        'cec' => rotation_cec_from_screen($scr),
    ];
}

function rotation_admin_screen_row(string $key, $rv): array
{
    $key = rotation_normalize_screen_key($key);
    $row = is_array($rv) ? $rv : ['name' => (string)$rv];
    $cec = rotation_cec_from_screen($row);
    return [
        '_key' => $key,
        'name' => (string)($row['name'] ?? ''),
        'shuffle' => !empty($row['shuffle']),
        'cec_enabled' => $cec['enabled'],
        'cec_off' => (string)$cec['off'],
        'cec_on' => (string)$cec['on'],
    ];
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
    $block = is_array($scr['cec'] ?? null) ? $scr['cec'] : [];
    if (!empty($block['enabled']) || !empty($scr['cec_enabled'])) {
        $cec['enabled'] = true;
    }
    if (isset($block['off']) || isset($scr['cec_off'])) {
        $cec['off'] = max(0, min(23, (int)($block['off'] ?? $scr['cec_off'] ?? $cec['off'])));
    }
    if (isset($block['on']) || isset($scr['cec_on'])) {
        $cec['on'] = max(0, min(23, (int)($block['on'] ?? $scr['cec_on'] ?? $cec['on'])));
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

/** TV standby during the configured off window (supports overnight, e.g. off 23 / on 6). */
function rotation_cec_should_standby(int $offHour, int $onHour, ?int $hour = null): bool
{
    if ($offHour === $onHour) {
        return false;
    }
    $hour = $hour ?? (int)date('G');
    if ($offHour < $onHour) {
        return $hour >= $offHour && $hour < $onHour;
    }
    return $hour >= $offHour || $hour < $onHour;
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
    try {
        $prevTz = date_default_timezone_get();
        date_default_timezone_set($tz);
        $hour = (int)date('G');
        date_default_timezone_set($prevTz);
    } catch (Throwable $e) {
        $hour = (int)date('G');
        $tz = 'America/Detroit';
    }
    $cec = rotation_screen_settings($screen)['cec'];
    $standby = $cec['enabled'] && rotation_cec_should_standby($cec['off'], $cec['on'], $hour);
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
        'cec' => $settings['cec'],
        'fade_ms' => (int)cfg('rotation.FADE_MS', 800),
        'settle_ms' => (int)cfg('rotation.SETTLE_MS', 1200),
        'hang_ms' => (int)cfg('rotation.HANG_MS', 20000),
    ], JSON_UNESCAPED_SLASHES);
    return substr(sha1($blob ?: ''), 0, 12);
}

/**
 * Runtime payload for board.php render + ?api=1 polling.
 * @return array{screen:string,pages:list<array<string,mixed>>,shuffle:bool,fade_ms:int,settle_ms:int,hang_ms:int,revision:string}
 */
function rotation_screen_runtime(string $screen = 'main'): array
{
    $screen = rotation_normalize_screen_key($screen);
    $settings = rotation_screen_settings($screen);
    return [
        'screen' => $screen,
        'pages' => rotation_screen_active_pages($screen),
        'shuffle' => $settings['shuffle'],
        'fade_ms' => (int)cfg('rotation.FADE_MS', 800),
        'settle_ms' => (int)cfg('rotation.SETTLE_MS', 1200),
        'hang_ms' => (int)cfg('rotation.HANG_MS', 20000),
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

    require_once __DIR__ . '/slides_lib.php';
    $slideFile = slide_rotation_parse_file($url);
    if ($slideFile !== null) {
        $slide = slide_deck_by_file($slideFile);
        $caption = is_array($slide) ? trim((string)($slide['caption'] ?? '')) : '';
        return 'Slide — ' . ($caption !== '' ? $caption : $slideFile);
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
    ];

    $base = strtok($url, '?') ?: $url;
    return $boards[$base] ?? $base;
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
                'label' => 'RSS — ' . $name,
                'url' => 'rss.php?feed=' . rawurlencode((string)$key),
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
                'url' => 'grafana.php?d=' . rawurlencode((string)$key),
                'dwell' => 60,
                'group' => 'Dashboards',
            ];
        }
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
    $conf = is_file(cfg_path()) ? (json_decode((string)file_get_contents(cfg_path()), true) ?: []) : [];
    $conf['rotation.PAGES_' . preg_replace('/[^a-z0-9_\-]/i', '', $screen)] = $pages;
    return cfg_write($conf);
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
 * Human page count — a contiguous slide block counts as one board.
 * @return array{total:int,slide_entries:int,slide_blocks:int}
 */
function rotation_playlist_counts(array $pageRows): array
{
    $total = 0;
    $slideEntries = 0;
    $slideBlocks = 0;
    $inSlideBlock = false;

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
            continue;
        }
        $inSlideBlock = false;
        $total++;
    }

    return ['total' => $total, 'slide_entries' => $slideEntries, 'slide_blocks' => $slideBlocks];
}

/**
 * Split saved playlist rows into normal pages and contiguous slide blocks.
 * @return list<array{type:string,items?:list<array<string,mixed>>,row?:array<string,mixed>,url?:string,index?:int}>
 */
function rotation_playlist_segments(array $pageRows): array
{
    $segments = [];
    $slideBuf = [];

    foreach ($pageRows as $idx => $prow) {
        if (!is_array($prow)) {
            continue;
        }
        $purl = trim((string)($prow['url'] ?? ''));
        if ($purl === '') {
            if ($slideBuf !== []) {
                $segments[] = ['type' => 'slides', 'items' => $slideBuf];
                $slideBuf = [];
            }
            $segments[] = ['type' => 'page', 'index' => $idx, 'row' => $prow, 'url' => ''];
            continue;
        }
        $isSlide = rotation_is_legacy_slides_url($purl) || rotation_is_slide_url($purl);
        if ($isSlide) {
            $slideBuf[] = ['index' => $idx, 'row' => $prow, 'url' => $purl];
            continue;
        }
        if ($slideBuf !== []) {
            $segments[] = ['type' => 'slides', 'items' => $slideBuf];
            $slideBuf = [];
        }
        $segments[] = ['type' => 'page', 'index' => $idx, 'row' => $prow, 'url' => $purl];
    }
    if ($slideBuf !== []) {
        $segments[] = ['type' => 'slides', 'items' => $slideBuf];
    }

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

    foreach ($pages as $ep) {
        if (!is_array($ep) || empty($ep['url']) || !empty($ep['off'])) {
            continue;
        }
        $url = trim((string)$ep['url']);
        if (rotation_is_legacy_slides_url($url) || rotation_is_slide_url($url)) {
            $slideRun[] = $ep;
            continue;
        }
        if ($slideRun !== []) {
            $lines[] = rotation_format_slide_run_line($slideRun);
            $slideRun = [];
        }
        $dwell = (int)($ep['dwell'] ?? 0);
        $lines[] = [
            'label' => rotation_page_label($url),
            'detail' => $url . ($dwell > 0 ? ' · ' . $dwell . 's' : ''),
        ];
    }
    if ($slideRun !== []) {
        $lines[] = rotation_format_slide_run_line($slideRun);
    }

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
        $url = 'rss.php?feed=' . rawurlencode((string)$key);
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
