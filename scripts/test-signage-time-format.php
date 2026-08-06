<?php
/**
 * Smoke test — 12/24h clock format resolution and helpers.
 */
require_once dirname(__DIR__) . '/config.php';

$_GET = ['clockfmt' => '24'];
assert(signage_clock_24h() === true, 'clockfmt=24');
assert(signage_time_format_php() === 'H:i', '24h php format');

$_GET = ['clockfmt' => '12'];
assert(signage_clock_24h() === false, 'clockfmt=12');
assert(signage_time_format_php() === 'g:i A', '12h php format');

unset($_GET['clockfmt']);
$ts = strtotime('2026-08-06 15:30:00');
assert(signage_format_time($ts) === date('g:i A', $ts), 'default 12h format time');

echo "signage time format tests OK\n";
