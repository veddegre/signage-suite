#!/usr/bin/env php
<?php
/**
 * Board-assignment export: same board on two displays, skipped rows, shell query params.
 *
 * Usage: php scripts/test-rotation-board-export.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/rotation_lib.php';

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

expect(
    rotation_board_url_identity('weather.php') === rotation_board_url_identity('weather.php?noticker=1&settle=0&screen=soc1'),
    'shell query params do not make a different board'
);
expect(
    rotation_board_url_identity('video.php?v=soolocks') === rotation_board_url_identity('video.php?screen=main&v=soolocks'),
    'video id matches regardless of param order'
);
expect(
    rotation_board_url_identity('video.php?v=soolocks') !== rotation_board_url_identity('video.php?v=lantern'),
    'different videos stay distinct'
);
expect(
    rotation_board_url_identity('https://Example.com/cam') === 'https://example.com/cam',
    'absolute URLs keep host and path'
);

$catalog = [
    ['group' => 'Boards', 'label' => 'Weather', 'url' => 'weather.php'],
    ['group' => 'Boards', 'label' => 'Lake Michigan', 'url' => 'lake.php'],
    ['group' => 'Videos', 'label' => 'Soo Locks', 'url' => 'video.php?v=soolocks'],
    ['group' => 'Boards', 'label' => '=Formula', 'url' => 'joke.php'],
];
$pages = [
    'soc1' => [
        ['url' => 'weather.php', 'dwell' => 60],
        ['url' => 'lake.php', 'off' => true],
    ],
    'soc2' => [
        ['url' => 'weather.php?noticker=1&settle=1200'],
        ['url' => 'https://example.com/extra'],
    ],
    'soc3' => [
        ['url' => 'video.php?v=soolocks'],
    ],
    'soc4' => [],
];
$names = ['soc1' => 'SOC 1', 'soc2' => 'SOC 2', 'soc3' => 'SOC 3', 'soc4' => 'SOC 4'];
$table = rotation_board_assignment_table($catalog, $pages, $names);

expect($table['headers'] === ['Group', 'Board', 'URL', 'On screens', 'Screen count', 'SOC 1', 'SOC 2', 'SOC 3', 'SOC 4'], 'one column per display');

$byUrl = [];
foreach ($table['rows'] as $row) {
    $byUrl[$row[2]] = $row;
}
expect(($byUrl['weather.php'][4] ?? '') === '2', 'weather is on two screens');
expect(($byUrl['weather.php'][3] ?? '') === 'SOC 1; SOC 2', 'weather names both displays');
expect(($byUrl['weather.php'][5] ?? '') === 'yes' && ($byUrl['weather.php'][6] ?? '') === 'yes', 'weather cells are yes');
expect(($byUrl['lake.php'][4] ?? '') === '1', 'skipped lake still counts as assigned');
expect(str_contains((string)($byUrl['lake.php'][3] ?? ''), '(skipped)'), 'skipped lake is labeled');
expect(($byUrl['lake.php'][5] ?? '') === 'skipped' && ($byUrl['lake.php'][6] ?? '') === '', 'lake is only on SOC 1');
expect(($byUrl['video.php?v=soolocks'][7] ?? '') === 'yes', 'soo locks is on SOC 3');
expect(isset($byUrl['https://example.com/extra']), 'playlist-only URL is included');
expect($table['rows'][0][2] === 'weather.php', 'duplicates sort first');

$csv = '';
$fh = fopen('php://temp', 'w+');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, array_map('rotation_csv_cell', $table['headers']), ',', '"', '\\');
foreach ($table['rows'] as $row) {
    fputcsv($fh, array_map(static fn($cell): string => rotation_csv_cell((string)$cell), $row), ',', '"', '\\');
}
rewind($fh);
$csv = stream_get_contents($fh) ?: '';
fclose($fh);
expect(str_starts_with($csv, "\xEF\xBB\xBF"), 'csv has a UTF-8 BOM for spreadsheet apps');
expect(str_contains($csv, "'=Formula"), 'formula-like board names are neutralized');

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
