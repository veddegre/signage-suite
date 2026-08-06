<?php
/**
 * MDOT camera wall — multi-feed grid for commute corridor stills.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/webcam_lib.php';
require_once __DIR__ . '/camwall_metro_data.php';

const CAMWALL_DEFAULT_COLS = 3;
const CAMWALL_DEFAULT_ROWS = 4;

/** @return array<string,array<string,mixed>> Built-in Allendale ↔ Grand Rapids corridor set (3×4). */
function camwall_corridor_cameras(): array
{
    return [
        'i96-24th' => [
            'name' => 'E of 24th Ave',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_202.flv.jpg?item=1',
            'sort' => 1,
        ],
        'i96-68th' => [
            'name' => '68th Ave',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_201.flv.jpg?item=1',
            'sort' => 2,
        ],
        'i96-m11' => [
            'name' => 'M-11',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_203.flv.jpg?item=1',
            'sort' => 3,
        ],
        'i96-m37' => [
            'name' => 'M-37 / M-44',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_003.flv.jpg?item=1',
            'sort' => 4,
        ],
        'i96-i196' => [
            'name' => 'I-196 interchange',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_062.flv.jpg?item=1',
            'sort' => 5,
            'focus' => '50% 15%',
            'also_routes' => ['I-196'],
        ],
        'i196-zeeland' => [
            'name' => 'Zeeland Rest Area',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_209.flv.jpg?item=1',
            'sort' => 6,
        ],
        'i196-chicago' => [
            'name' => 'Chicago Dr',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_008.flv.jpg?item=1',
            'sort' => 7,
        ],
        'i196-8th' => [
            'name' => '8th Ave',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_092.flv.jpg?item=1',
            'sort' => 8,
        ],
        'us131-market' => [
            'name' => 'Market Ave',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_056.flv.jpg?item=1',
            'sort' => 9,
        ],
        'us131-leonard' => [
            'name' => 'Leonard St',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_047.flv.jpg?item=1',
            'sort' => 10,
        ],
        'us131-m11' => [
            'name' => 'M-11 (28th St)',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_061.flv.jpg?item=1',
            'sort' => 11,
        ],
        'us131-i96' => [
            'name' => 'I-96 interchange',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_053.flv.jpg?item=1',
            'sort' => 12,
        ],
    ];
}

/** Corridor preset plus Grand Rapids metro catalog (metro entries use sort 100+). */
function camwall_default_cameras(): array
{
    return array_merge(camwall_corridor_cameras(), camwall_metro_cameras());
}

function camwall_catalog_label(string $key, array $entry): string
{
    $name = trim((string)($entry['name'] ?? $key));
    $route = trim((string)($entry['route'] ?? ''));
    if ($route !== '') {
        return $route . ' · ' . $name;
    }

    return $name;
}

/** @return array<string,array<string,mixed>> Full camera catalog for admin pickers. */
function camwall_catalog(): array
{
    return camwall_registry();
}

/**
 * Cameras grouped by route badge for admin optgroups.
 *
 * @return array<string,list<array{key:string,label:string}>>
 */
function camwall_catalog_groups(): array
{
    $groups = [];
    foreach (camwall_catalog() as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $routes = [trim((string)($entry['route'] ?? '')) ?: 'Other'];
        foreach ((array)($entry['also_routes'] ?? []) as $alsoRoute) {
            $alsoRoute = trim((string)$alsoRoute);
            if ($alsoRoute !== '' && !in_array($alsoRoute, $routes, true)) {
                $routes[] = $alsoRoute;
            }
        }
        $item = [
            'key' => (string)$key,
            'label' => camwall_catalog_label((string)$key, $entry),
        ];
        foreach ($routes as $route) {
            $groups[$route][] = $item;
        }
    }
    ksort($groups);
    foreach ($groups as $route => $items) {
        usort($items, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        $groups[$route] = $items;
    }

    return $groups;
}

function camwall_slot_label(int $index, int $cols): string
{
    $cols = max(1, $cols);
    $row = intdiv($index, $cols) + 1;
    $col = ($index % $cols) + 1;

    return 'Row ' . $row . ', col ' . $col;
}

/** @return list<string> Per-slot camera keys; empty string = blank tile. */
function camwall_screen_slot_keys(string $screen): array
{
    require_once __DIR__ . '/screen_scope_lib.php';
    $scr = rotation_screen_raw_entry($screen);
    if (!is_array($scr) || !isset($scr['camwall_slots']) || !is_array($scr['camwall_slots'])) {
        return [];
    }
    $catalog = camwall_catalog();
    $slots = camwall_grid_size()['slots'];
    $out = [];
    foreach ($scr['camwall_slots'] as $raw) {
        if (count($out) >= $slots) {
            break;
        }
        $key = camwall_normalize_key((string)$raw);
        $out[] = ($key !== '' && isset($catalog[$key])) ? $key : '';
    }

    return $out;
}

function camwall_screen_has_layout(string $screen): bool
{
    foreach (camwall_screen_slot_keys($screen) as $key) {
        if ($key !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @return array{title:string,subtitle:string}
 */
function camwall_screen_labels(string $screen): array
{
    require_once __DIR__ . '/screen_scope_lib.php';
    $scr = rotation_screen_raw_entry($screen);
    $title = is_array($scr) ? trim((string)($scr['camwall_title'] ?? '')) : '';
    if ($title === '') {
        $title = (string)cfg('camwall.TITLE', 'MDOT Cams');
    }
    $subtitle = is_array($scr) ? trim((string)($scr['camwall_subtitle'] ?? '')) : '';
    if ($subtitle === '') {
        $subtitle = (string)cfg('camwall.SUBTITLE', 'Allendale ↔ Grand Rapids · I-96 · I-196 · US-131');
    }

    return ['title' => $title, 'subtitle' => $subtitle];
}

/**
 * Resolved grid tiles for one display — preserves empty slots when a per-display layout is set.
 *
 * @return list<array{key:string,name:string,route:string,url:string,sort:int,focus:string}|null>
 */
function camwall_tiles_for_screen(string $screen): array
{
    $grid = camwall_grid_size();
    $slots = (int)$grid['slots'];
    $registry = camwall_catalog();

    if (!camwall_screen_has_layout($screen)) {
        $rows = camwall_active_cameras();
        $tiles = [];
        foreach ($rows as $row) {
            $tiles[] = $row;
        }
        while (count($tiles) < $slots) {
            $tiles[] = null;
        }

        return array_slice($tiles, 0, $slots);
    }

    $layout = camwall_screen_slot_keys($screen);
    $tiles = [];
    for ($i = 0; $i < $slots; $i++) {
        $key = camwall_normalize_key((string)($layout[$i] ?? ''));
        if ($key === '' || !isset($registry[$key]) || !is_array($registry[$key])) {
            $tiles[] = null;
            continue;
        }
        $entry = $registry[$key];
        $tiles[] = [
            'key' => $key,
            'name' => (string)($entry['name'] ?? $key),
            'route' => (string)($entry['route'] ?? ''),
            'url' => (string)($entry['url'] ?? ''),
            'sort' => (int)($entry['sort'] ?? 0),
            'focus' => camwall_normalize_focus((string)($entry['focus'] ?? '')),
        ];
    }

    return $tiles;
}

/** @return list<array{key:string,name:string,route:string,url:string,sort:int,focus:string}> */
function camwall_active_cameras_for_screen(string $screen): array
{
    $out = [];
    foreach (camwall_tiles_for_screen($screen) as $tile) {
        if (is_array($tile)) {
            $out[] = $tile;
        }
    }

    return $out;
}

function camwall_normalize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_-]/', '', $key));
}

function camwall_allowed_image_host(string $url): bool
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    return preg_match('#(^|\.)micamerasimages\.net$#', $host) === 1
        || preg_match('#(^|\.)state\.mi\.us$#', $host) === 1;
}

function camwall_normalize_focus(string $raw, ?string $fallback = null): string
{
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        $raw = strtolower(trim((string)$fallback));
    }
    $presets = [
        'top' => 'center top',
        'center' => 'center center',
        'bottom' => 'center bottom',
    ];
    if (isset($presets[$raw])) {
        return $presets[$raw];
    }
    if ($raw !== '' && preg_match('/^[a-z0-9.%\s-]+$/i', $raw) === 1) {
        return $raw;
    }

    return 'center center';
}

/** @param array<string,mixed> $row @param array<string,mixed>|null $fallback */
function camwall_normalize_entry(array $row, ?array $fallback = null): ?array
{
    $url = webcam_validate_url((string)($row['url'] ?? ($fallback['url'] ?? '')));
    if ($url === null || !camwall_allowed_image_host($url)) {
        return null;
    }
    $name = trim((string)($row['name'] ?? ($fallback['name'] ?? '')));
    if ($name === '') {
        $name = 'Camera';
    }
    $route = trim((string)($row['route'] ?? ($fallback['route'] ?? '')));
    $sort = (int)($row['sort'] ?? ($fallback['sort'] ?? 0));
    $focus = camwall_normalize_focus(
        (string)($row['focus'] ?? ''),
        isset($fallback['focus']) ? (string)$fallback['focus'] : null
    );

    return [
        'name' => $name,
        'route' => $route,
        'url' => $url,
        'sort' => $sort,
        'focus' => $focus,
    ];
}

/** @return array<string,array<string,mixed>> */
function camwall_registry(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $out = camwall_default_cameras();
    $saved = cfg('camwall.CAMS', []);
    if (is_array($saved)) {
        foreach ($saved as $k => $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = camwall_normalize_key((string)($row['_key'] ?? $k));
            if ($key === '') {
                continue;
            }
            if (!empty($row['off'])) {
                unset($out[$key]);
                continue;
            }
            $entry = camwall_normalize_entry($row, is_array($out[$key] ?? null) ? $out[$key] : null);
            if ($entry !== null) {
                $out[$key] = $entry;
            }
        }
    }

    foreach ($out as $key => $entry) {
        if (!is_array($entry) || trim((string)($entry['url'] ?? '')) === '') {
            unset($out[$key]);
        }
    }

    return $cache = $out;
}

/** @return list<array{key:string,name:string,route:string,url:string,sort:int}> */
function camwall_active_cameras(): array
{
    $registry = camwall_registry();
    $rows = [];
    foreach ($registry as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $rows[] = [
            'key' => (string)$key,
            'name' => (string)($entry['name'] ?? $key),
            'route' => (string)($entry['route'] ?? ''),
            'url' => (string)$entry['url'],
            'sort' => (int)($entry['sort'] ?? 0),
            'focus' => camwall_normalize_focus((string)($entry['focus'] ?? '')),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $sort = $a['sort'] <=> $b['sort'];
        if ($sort !== 0) {
            return $sort;
        }

        return strcmp($a['key'], $b['key']);
    });

    $seenUrls = [];
    $unique = [];
    foreach ($rows as $row) {
        $url = $row['url'];
        if ($url === '' || isset($seenUrls[$url])) {
            continue;
        }
        $seenUrls[$url] = true;
        $unique[] = $row;
    }
    $rows = $unique;

    $cols = max(1, min(6, (int)cfg('camwall.COLS', CAMWALL_DEFAULT_COLS)));
    $rowsMax = max(1, min(6, (int)cfg('camwall.ROWS', CAMWALL_DEFAULT_ROWS)));
    $limit = $cols * $rowsMax;

    return array_slice($rows, 0, $limit);
}

function camwall_grid_size(): array
{
    $cols = max(1, min(6, (int)cfg('camwall.COLS', CAMWALL_DEFAULT_COLS)));
    $rows = max(1, min(6, (int)cfg('camwall.ROWS', CAMWALL_DEFAULT_ROWS)));

    return ['cols' => $cols, 'rows' => $rows, 'slots' => $cols * $rows];
}

function camwall_resolve_camera(string $camKey): ?array
{
    $key = camwall_normalize_key($camKey);
    if ($key === '') {
        return null;
    }
    $entry = camwall_registry()[$key] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    return [
        'key' => $key,
        'name' => (string)($entry['name'] ?? $key),
        'route' => (string)($entry['route'] ?? ''),
        'url' => (string)$entry['url'],
    ];
}

function camwall_image_proxy_url(string $key): string
{
    return 'camwall_img.php?cam=' . rawurlencode(camwall_normalize_key($key));
}

function camwall_stream_image(string $camKey): void
{
    $cam = camwall_resolve_camera($camKey);
    if ($cam === null) {
        http_response_code(404);
        exit;
    }

    $remote = (string)$cam['url'];
    if (!camwall_allowed_image_host($remote)) {
        http_response_code(403);
        exit;
    }

    $body = webcam_http_get($remote, 20, true);
    if ($body === null) {
        http_response_code(502);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store, max-age=0');
    header('Content-Length: ' . (string)strlen($body));
    echo $body;
    exit;
}
