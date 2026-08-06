#!/usr/bin/env php
<?php
/**
 * CLI: test Sports board data (ESPN API, per-display teams, cache).
 *
 * Usage:
 *   php scripts/diagnose-sports.php [--screen=main] [--root=/path/to/install]
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cli_lib.php';

$opts = signage_cli_parse_argv($argv);
$root = signage_cli_resolve_root($opts['root']);
if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', $root);
}
if (!defined('SIGNAGE_CLI')) {
    define('SIGNAGE_CLI', true);
}
require_once $root . '/config.php';
require_once $root . '/lib/sports_lib.php';
require_once $root . '/lib/screen_scope_lib.php';

$screen = 'main';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--screen=')) {
        $screen = rotation_normalize_screen_key(substr($arg, 9));
    }
}
if ($screen === '') {
    $screen = 'main';
}

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n\n";

if (!is_file(cfg_path())) {
    echo "settings.json not found.\n";
    exit(1);
}

$loc = rotation_screen_location($screen);
$labels = rotation_screen_sports_labels($screen);
$teamKeys = rotation_screen_sports_team_keys($screen);

echo "Display: {$screen}\n";
echo 'Location (air boards): ' . $loc['place'] . ' (' . $loc['lat'] . ', ' . $loc['lon'] . ")\n";
echo 'Sports title: ' . $labels['title'] . ' — ' . $labels['subtitle'] . "\n";
echo 'Team keys: ' . ($teamKeys === [] ? '(site default)' : implode(', ', $teamKeys)) . "\n\n";

$data = sports_board_data($screen);
$cards = is_array($data['cards'] ?? null) ? $data['cards'] : [];

echo 'has_data: ' . (!empty($data['has_data']) ? 'yes' : 'NO') . "\n";
echo 'teams rendered: ' . count($cards) . "\n";
echo 'any_live: ' . (!empty($data['any_live']) ? 'yes' : 'no') . "\n";
echo 'cache_age_sec: ' . (int)($data['cache_age'] ?? 0) . "\n";
if (!empty($GLOBALS['diag']) && is_array($GLOBALS['diag'])) {
    echo "fetch diagnostics:\n";
    foreach ($GLOBALS['diag'] as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
}
echo "\n";

if ($cards === []) {
    echo "No team cards — board will show the empty state.\n";
    exit(1);
}

foreach ($cards as $card) {
    if (!is_array($card)) {
        continue;
    }
    $name = (string)($card['name'] ?? '?');
    $mode = (string)($card['mode'] ?? 'off');
    $badge = (string)($card['badge'] ?? '');
    $headline = (string)($card['headline'] ?? '');
    $err = !empty($card['data_error']) ? ' [DATA ERROR]' : '';
    echo "- {$name} ({$mode}, {$badge}){$err}: {$headline}\n";
}

$skip = sports_skip_rotation($screen);
echo "\nseasonal auto-skip: " . ($skip ? 'YES (hidden from rotation when off-season)' : 'no') . "\n";

echo "\nDone.\n";
