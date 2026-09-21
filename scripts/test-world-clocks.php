<?php
/**
 * Smoke test — world clocks helpers (IANA zones, home highlight, day relative).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/clocks_lib.php';
require_once $root . '/schema.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL  $msg\n";
}

$_GET = ['clockfmt' => '12'];
$defaults = clocks_default_rows();
expect(count($defaults) === 8, 'eight built-in cities');
foreach ($defaults as $key => $row) {
    expect(clocks_timezone((string)$row['tz']) !== null, $key . ' timezone is valid');
}
expect(clocks_timezone('Not/AZone') === null, 'invalid timezone rejected');
expect(clocks_timezone('') === null, 'empty timezone rejected');

$cities = clocks_enabled_cities();
expect(count($cities) === 8, 'enabled defaults count 8');
$homes = array_values(array_filter($cities, static fn($c) => !empty($c['home'])));
expect(count($homes) === 1, 'exactly one home clock');
expect(($homes[0]['tz'] ?? '') === 'America/Detroit', 'home default is Detroit');

expect(clocks_grid_columns(1) === 1, '1 clock → 1 col');
expect(clocks_grid_columns(3) === 2, '3 clocks → 2 cols');
expect(clocks_grid_columns(6) === 3, '6 clocks → 3 cols');
expect(clocks_grid_columns(8) === 4, '8 clocks → 4 cols');
expect(clocks_grid_columns(12) === 4, '12 clocks → 4 cols');

$now = new DateTimeImmutable('2026-09-21 15:30:00', new DateTimeZone('America/Detroit'));
$snap = clocks_wall_snapshot($now, [
    ['key' => 'detroit', 'label' => 'Grand Rapids', 'tz' => 'America/Detroit', 'home' => true],
    ['key' => 'utc', 'label' => 'UTC', 'tz' => 'UTC', 'home' => false],
    ['key' => 'tokyo', 'label' => 'Tokyo', 'tz' => 'Asia/Tokyo', 'home' => false],
]);
expect(count($snap) === 3, 'snapshot has 3 cities');
$byKey = [];
foreach ($snap as $row) {
    $byKey[$row['key']] = $row;
}
expect(($byKey['detroit']['time'] ?? '') === '3:30:00 PM' || ($byKey['detroit']['time'] ?? '') === '3:30 PM',
    'Detroit 12h time (got ' . ($byKey['detroit']['time'] ?? '') . ')');
expect(($byKey['detroit']['night'] ?? true) === false, '15:30 Detroit is day');
expect(($byKey['detroit']['day_rel'] ?? '') === 'today', 'Detroit is today vs home');
expect(str_contains((string)($byKey['detroit']['offset'] ?? ''), '4'), 'Detroit offset mentions 4');
expect(($byKey['tokyo']['night'] ?? false) === true, 'Tokyo 04:30 is night');
expect(($byKey['tokyo']['day_rel'] ?? '') === 'tomorrow', 'Tokyo is tomorrow vs Detroit afternoon');
expect(($byKey['utc']['day_rel'] ?? '') === 'today', 'UTC still today at 19:30');

$_GET = ['clockfmt' => '24'];
$snap24 = clocks_wall_snapshot($now, [
    ['key' => 'detroit', 'label' => 'Grand Rapids', 'tz' => 'America/Detroit', 'home' => true],
], null);
expect(str_starts_with((string)($snap24[0]['time'] ?? ''), '15:30'), '24h time starts 15:30');

expect(clocks_is_night(5) === true, '05h is night');
expect(clocks_is_night(6) === false, '06h is day');
expect(clocks_is_night(19) === false, '19h is day');
expect(clocks_is_night(20) === true, '20h is night');

$dt = new DateTimeImmutable('2026-09-21 15:30:00', new DateTimeZone('America/Detroit'));
expect(clocks_offset_label($dt) === "UTC\u{2212}4" || clocks_offset_label($dt) === 'UTC−4',
    'EDT offset label (got ' . clocks_offset_label($dt) . ')');

$admin = clocks_admin_rows();
expect($admin !== [] && isset($admin[0]['_key'], $admin[0]['tz']), 'admin rows include key + tz');

$schema = admin_schema();
expect(isset($schema['clocks']['file']) && $schema['clocks']['file'] === 'clocks.php', 'schema clocks board');

if ($fail > 0) {
    fwrite(STDERR, "$fail world clocks test(s) failed\n");
    exit(1);
}
echo "world clocks tests OK\n";
