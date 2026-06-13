<?php
/**
 * Family board — shared calendar feed palette and helpers.
 */

/** Theme-complementary palette for calendar feeds on the dark navy wall. */
function family_calendar_palette(): array
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

function family_calendar_color_hex(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '#ffb347';
    }
    if ($stored[0] === '#') {
        return $stored;
    }
    foreach (family_calendar_palette() as $p) {
        if ($p['key'] === $stored) {
            return $p['hex'];
        }
    }
    return '#ffb347';
}

/** @param array<string,mixed> $feed */
function family_feed_meta(array $feed, int $index = 0): array
{
    $palette = family_calendar_palette();
    $key = trim((string)($feed['key'] ?? $feed['name'] ?? ''));
    if ($key === '') {
        $key = 'Cal ' . ($index + 1);
    }
    $colorKey = trim((string)($feed['color'] ?? ''));
    if ($colorKey === '' || ($colorKey[0] !== '#' && !family_palette_has_key($colorKey))) {
        $colorKey = $palette[$index % count($palette)]['key'];
    }
    return [
        'key' => $key,
        'color' => $colorKey,
        'hex' => family_calendar_color_hex($colorKey),
    ];
}

function family_palette_has_key(string $key): bool
{
    foreach (family_calendar_palette() as $p) {
        if ($p['key'] === $key) {
            return true;
        }
    }
    return false;
}

/** @return list<array{key:string,hex:string}> */
function family_calendar_legend(array $feeds): array
{
    $out = [];
    $seen = [];
    $i = 0;
    foreach ($feeds as $feed) {
        if (!is_array($feed)) {
            continue;
        }
        $meta = family_feed_meta($feed, $i++);
        $id = strtolower($meta['key']);
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = ['key' => $meta['key'], 'hex' => $meta['hex']];
    }
    return $out;
}
