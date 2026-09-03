<?php
/**
 * Smoke test — CARTO raster tile URLs and API-key query param.
 */
require_once dirname(__DIR__) . '/config.php';

cfg('_', null);
$conf = $GLOBALS['__cfg_cache'] ?? [];
if (!is_array($conf)) {
    $conf = [];
}
unset($conf['site.CARTO_API_KEY']);
$GLOBALS['__cfg_cache'] = $conf;

assert(carto_basemap_configured() === false, 'empty key is not configured');
assert(
    carto_basemap_tile_url('dark_all') === 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    'dark_all without key'
);
assert(
    carto_basemap_tile_url('rastertiles/voyager') === 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
    'voyager without key'
);
assert(
    carto_basemap_tile_url('../evil') === carto_basemap_tile_url('dark_all'),
    'unknown style falls back to dark_all'
);

$GLOBALS['__cfg_cache']['site.CARTO_API_KEY'] = 'abc+def';
assert(carto_basemap_configured() === true, 'non-empty key is configured');
$withKey = carto_basemap_tile_url('dark_nolabels');
assert(str_contains($withKey, 'dark_nolabels/{z}/{x}/{y}{r}.png?key='), 'appends key query');
assert(str_contains($withKey, rawurlencode('abc+def')), 'key is url-encoded');
assert(!str_contains($withKey, 'abc+def'), 'raw + is not left unencoded');

echo "carto basemap tests OK\n";
