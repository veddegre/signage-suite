<?php
/**
 * CARTO raster tiles used by weather radar, traffic, and the world-map boards.
 * Raster basemaps require a free API key or they render an "API key required" watermark.
 * https://carto.com/basemaps/apikey/
 */

/** @return list<string> */
function carto_basemap_styles(): array
{
    return [
        'dark_all',
        'dark_nolabels',
        'dark_only_labels',
        'light_all',
        'rastertiles/voyager',
        'voyager',
    ];
}

function carto_basemap_api_key(): string
{
    return trim((string)cfg('site.CARTO_API_KEY', ''));
}

function carto_basemap_configured(): bool
{
    return carto_basemap_api_key() !== '';
}

/** Leaflet tile URL for a CARTO raster style. Appends ?key= when configured. */
function carto_basemap_tile_url(string $style): string
{
    $style = trim($style, '/');
    if (!in_array($style, carto_basemap_styles(), true)) {
        $style = 'dark_all';
    }
    $url = 'https://{s}.basemaps.cartocdn.com/' . $style . '/{z}/{x}/{y}{r}.png';
    $key = carto_basemap_api_key();
    if ($key !== '') {
        $url .= '?key=' . rawurlencode($key);
    }

    return $url;
}
