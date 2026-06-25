<?php
/**
 * L3 attack map — Cloudflare Radar volumetric flows.
 */

require_once __DIR__ . '/attackmap_lib.php';

function l3map_configured(): bool
{
    return radarmap_board_cf_token('l3map') !== '';
}

function l3map_date_range(): string
{
    return radarmap_board_date_range('l3map');
}

/** @return list<array<string,mixed>> */
function l3map_fetch_flows(): array
{
    if (!l3map_configured()) {
        return [];
    }
    return radarmap_parse_flows(radarmap_fetch_raw_pairs('layer3', 'l3map'));
}
