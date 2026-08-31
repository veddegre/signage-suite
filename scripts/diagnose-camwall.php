#!/usr/bin/env php
<?php
/**
 * CLI: probe MDOT camwall tiles — registry, slot layout, snapshot fetch.
 *
 * Usage:
 *   php scripts/diagnose-camwall.php [screen] [--root=/path]
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
require_once $root . '/lib/camwall_lib.php';

$screen = trim((string)($opts['positional'][0] ?? 'main'));
if ($screen === '') {
    $screen = 'main';
}

$grid = camwall_grid_size();
$cols = (int)($grid['cols'] ?? CAMWALL_DEFAULT_COLS);
$rows = (int)($grid['rows'] ?? CAMWALL_DEFAULT_ROWS);
$capacity = (int)($grid['slots'] ?? ($cols * $rows));
$tiles = camwall_tiles_for_screen($screen);
$active = camwall_active_cameras_for_screen($screen);

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Screen: ' . $screen . "\n";
echo "Grid: {$cols}×{$rows} = {$capacity} slots\n";
echo 'Tiles shown: ' . count($tiles) . "\n";
echo 'Custom slot layout: ' . (camwall_screen_has_layout($screen) ? 'yes' : 'no (site default)') . "\n\n";

if ($tiles === []) {
    echo "No active cameras for this screen.\n";
    exit(1);
}

$fail = 0;
foreach ($tiles as $i => $tile) {
    if (!is_array($tile)) {
        continue;
    }
    $key = (string)($tile['key'] ?? '');
    $name = (string)($tile['name'] ?? $key);
    $url = (string)($tile['url'] ?? '');
    $route = (string)($tile['route'] ?? '');
    echo str_repeat('─', 72) . "\n";
    echo 'Slot ' . ($i + 1) . ": {$key} — {$name}";
    if ($route !== '') {
        echo " ({$route})";
    }
    echo "\n";
    echo "  url: {$url}\n";
    echo '  proxy: ' . camwall_image_proxy_url($key) . "\n";

    if ($url === '') {
        echo "  fetch: SKIP (empty URL)\n";
        $fail++;
        continue;
    }
    if (!camwall_allowed_image_host($url)) {
        echo "  fetch: BLOCKED (host not allowed)\n";
        $fail++;
        continue;
    }
    $body = webcam_http_get($url, 20, true);
    if ($body === null) {
        echo "  fetch: FAILED\n";
        $fail++;
        continue;
    }
    $len = strlen($body);
    $jpeg = str_starts_with($body, "\xff\xd8\xff");
    echo '  fetch: OK (' . $len . ' bytes' . ($jpeg ? ', jpeg' : '') . ")\n";
}

echo "\nRegistry total: " . count(camwall_registry()) . " cameras\n";
echo 'Active for screen: ' . count($active) . "\n";
exit($fail > 0 ? 1 : 0);
