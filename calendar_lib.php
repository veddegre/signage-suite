<?php
/**
 * Calendar board — shared feed palette and settings migration from legacy family.* keys.
 */

/** Theme-complementary palette for calendar feeds on the dark navy wall. */
function calendar_palette(): array
{
    return [
        ['key' => 'beacon',  'label' => 'Amber',   'hex' => '#ffb347'],
        ['key' => 'sky',     'label' => 'Sky',     'hex' => '#7ec8ff'],
        ['key' => 'seafoam', 'label' => 'Seafoam', 'hex' => '#6ee7c8'],
        ['key' => 'sage',    'label' => 'Sage',    'hex' => '#9dffb0'],
        ['key' => 'coral',   'label' => 'Coral',   'hex' => '#ff9d9d'],
        ['key' => 'lilac',   'label' => 'Lilac',   'hex' => '#c4a8ff'],
        ['key' => 'gold',    'label' => 'Gold',    'hex' => '#ffd089'],
        ['key' => 'rose',    'label' => 'Rose',    'hex' => '#ff8fc7'],
    ];
}

function calendar_color_hex(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '#ffb347';
    }
    if ($stored[0] === '#') {
        return $stored;
    }
    foreach (calendar_palette() as $p) {
        if ($p['key'] === $stored) {
            return $p['hex'];
        }
    }
    return '#ffb347';
}

/** @param array<string,mixed> $feed */
function calendar_feed_meta(array $feed, int $index = 0): array
{
    $palette = calendar_palette();
    $key = trim((string)($feed['key'] ?? $feed['name'] ?? ''));
    if ($key === '') {
        $key = 'Cal ' . ($index + 1);
    }
    $colorKey = trim((string)($feed['color'] ?? ''));
    if ($colorKey === '' || ($colorKey[0] !== '#' && !calendar_palette_has_key($colorKey))) {
        $colorKey = $palette[$index % count($palette)]['key'];
    }
    return [
        'key' => $key,
        'color' => $colorKey,
        'hex' => calendar_color_hex($colorKey),
    ];
}

function calendar_palette_has_key(string $key): bool
{
    foreach (calendar_palette() as $p) {
        if ($p['key'] === $key) {
            return true;
        }
    }
    return false;
}

/** @return list<array{key:string,hex:string}> */
function calendar_legend(array $feeds): array
{
    $out = [];
    $seen = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = calendar_feed_meta($feed, $i++);
        $id = strtolower($meta['key']);
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = ['key' => $meta['key'], 'hex' => $meta['hex']];
    }
    return $out;
}

/**
 * Migrate family.php board + family.* settings to calendar.php / calendar.*.
 * @return array{conf:array<string,mixed>,changed:bool}
 */
function calendar_migrate_from_family(array $conf): array
{
    $changed = false;
    foreach ($conf as $key => $val) {
        if (!is_string($key) || !str_starts_with($key, 'family.')) {
            continue;
        }
        $newKey = 'calendar.' . substr($key, 7);
        if (!array_key_exists($newKey, $conf)) {
            $conf[$newKey] = $val;
            $changed = true;
        }
        unset($conf[$key]);
        $changed = true;
    }
    foreach ($conf as $key => $val) {
        if (!is_string($key) || !str_starts_with($key, 'rotation.PAGES')) {
            continue;
        }
        if (!is_array($val)) {
            continue;
        }
        foreach ($val as $i => $page) {
            if (!is_array($page)) {
                continue;
            }
            $url = trim((string)($page['url'] ?? ''));
            if ($url === 'family.php' || str_starts_with($url, 'family.php?')) {
                $conf[$key][$i]['url'] = preg_replace('/^family\.php/', 'calendar.php', $url) ?? 'calendar.php';
                $changed = true;
            }
        }
    }
    return ['conf' => $conf, 'changed' => $changed];
}
