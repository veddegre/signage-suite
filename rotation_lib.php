<?php
/**
 * Rotation shell — helpers for admin playlist editor and board.php.
 */

require_once __DIR__ . '/config.php';

/** @return array<string, array{name:string,shuffle?:bool}|string> */
function rotation_screens(): array
{
    $screens = cfg('rotation.SCREENS', ['main' => 'Main Display']);
    if (!is_array($screens) || $screens === []) {
        return ['main' => 'Main Display'];
    }
    if (!isset($screens['main'])) {
        $screens = ['main' => 'Main Display'] + $screens;
    }
    return $screens;
}

/** @return list<array<string,mixed>> Saved pages for one screen only (no fallback). */
function rotation_screen_own_pages(string $screen = 'main'): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $pages = cfg("rotation.PAGES_$screen", null);
    return is_array($pages) ? $pages : [];
}

/** @return list<array<string,mixed>> Pages that will play on the wall (matches board.php). */
function rotation_screen_effective_pages(string $screen = 'main'): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
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
    return array_values(array_filter(
        rotation_screen_effective_pages($screen),
        static fn($p) => is_array($p)
            && trim((string)($p['url'] ?? '')) !== ''
            && (int)($p['dwell'] ?? 0) > 0
            && empty($p['off'])
    ));
}

/** Fingerprint of the saved rotation config for one screen — used by board.php polling. */
function rotation_config_revision(string $screen = 'main'): string
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $screensCfg = cfg('rotation.SCREENS', []);
    $scr = is_array($screensCfg) ? ($screensCfg[$screen] ?? null) : null;
    $mtime = is_file(cfg_path()) ? (int)filemtime(cfg_path()) : 0;
    $blob = json_encode([
        'mtime' => $mtime,
        'screen' => $screen,
        'pages' => rotation_screen_effective_pages($screen),
        'shuffle' => is_array($scr) && !empty($scr['shuffle']),
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
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $screensCfg = cfg('rotation.SCREENS', []);
    $scr = is_array($screensCfg) ? ($screensCfg[$screen] ?? null) : null;
    return [
        'screen' => $screen,
        'pages' => rotation_screen_active_pages($screen),
        'shuffle' => is_array($scr) && !empty($scr['shuffle']),
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
        ['url' => 'photo.php',   'dwell' => 60,  'from' => 14, 'to' => 23],
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

    static $boards = [
        'index.php' => 'Weather',
        'lake.php' => 'Lake Michigan',
        'photo.php' => 'Photo conditions',
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

/**
 * Quick-add presets for the rotation playlist editor.
 * @return list<array{label:string,url:string,dwell:int,group:string}>
 */
function rotation_quick_add_items(): array
{
    $items = [
        ['label' => 'Weather', 'url' => 'index.php', 'dwell' => 180, 'group' => 'Boards'],
        ['label' => 'Lake Michigan', 'url' => 'lake.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Photo conditions', 'url' => 'photo.php', 'dwell' => 60, 'group' => 'Boards'],
        ['label' => 'Family calendar', 'url' => 'family.php', 'dwell' => 90, 'group' => 'Boards'],
        ['label' => 'Traffic map', 'url' => 'traffic.php', 'dwell' => 90, 'group' => 'Boards'],
        ['label' => 'Homelab', 'url' => 'homelab.php', 'dwell' => 45, 'group' => 'Boards'],
        ['label' => 'Photo rotator', 'url' => 'rotator.php', 'dwell' => 300, 'group' => 'Media'],
        ['label' => 'Custom slides', 'url' => 'slides.php', 'dwell' => 45, 'group' => 'Media'],
    ];

    $feeds = cfg('rss.FEEDS', []);
    if (is_array($feeds)) {
        foreach ($feeds as $key => $feed) {
            if (!is_array($feed)) {
                continue;
            }
            $name = trim((string)($feed['name'] ?? $key));
            $dwell = (int)($feed['dwell'] ?? cfg('rss.DEFAULT_DWELL', 16));
            $stories = (int)($feed['stories'] ?? cfg('rss.DEFAULT_STORIES', 6));
            $items[] = [
                'label' => 'RSS — ' . $name,
                'url' => 'rss.php?feed=' . rawurlencode((string)$key),
                'dwell' => max(30, $dwell * max(1, $stories)),
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

/** @return array{pages:list<array<string,mixed>>,added:bool,updated:bool,screen:string} */
function rotation_sync_slides(string $screen = 'main'): array
{
    $slides = cfg('slides.SLIDES', []);
    $n = 0;
    if (is_array($slides)) {
        foreach ($slides as $s) {
            if (is_array($s) && empty($s['off'])) {
                $n++;
            }
        }
    }
    $per = max(1, (int)cfg('slides.DEFAULT_DWELL', 12));
    $dwell = max(45, $per * max(1, $n));
    return rotation_upsert_url($screen, 'slides.php', $dwell);
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
        $dwell = (int)($feed['dwell'] ?? cfg('rss.DEFAULT_DWELL', 16));
        $stories = (int)($feed['stories'] ?? cfg('rss.DEFAULT_STORIES', 6));
        $dwell = max(30, $dwell * max(1, $stories));
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
