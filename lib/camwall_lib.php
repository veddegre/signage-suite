<?php
/**
 * MDOT camera wall — multi-feed grid for commute corridor stills.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/webcam_lib.php';

const CAMWALL_DEFAULT_COLS = 3;
const CAMWALL_DEFAULT_ROWS = 4;

/** @return array<string,array<string,mixed>> Built-in Allendale ↔ Grand Rapids corridor set (3×4). */
function camwall_default_cameras(): array
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
        ],
        'i96-leonard' => [
            'name' => 'Leonard St',
            'route' => 'I-96',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_069.flv.jpg?item=1',
            'sort' => 6,
        ],
        'i196-zeeland' => [
            'name' => 'Zeeland Rest Area',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_209.flv.jpg?item=1',
            'sort' => 7,
        ],
        'i196-chicago' => [
            'name' => 'Chicago Dr',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_008.flv.jpg?item=1',
            'sort' => 8,
        ],
        'i196-i96' => [
            'name' => 'I-96 interchange',
            'route' => 'I-196',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_062.flv.jpg?item=1',
            'sort' => 9,
        ],
        'us131-m89' => [
            'name' => 'M-89',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/internet_cam_210.flv.jpg?item=1',
            'sort' => 10,
        ],
        'us131-market' => [
            'name' => 'Market Ave',
            'route' => 'US-131',
            'url' => 'https://micamerasimages.net/thumbs/grand_cam_056.flv.jpg?item=1',
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

    return [
        'name' => $name,
        'route' => $route,
        'url' => $url,
        'sort' => $sort,
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
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $sort = $a['sort'] <=> $b['sort'];
        if ($sort !== 0) {
            return $sort;
        }

        return strcmp($a['key'], $b['key']);
    });

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
