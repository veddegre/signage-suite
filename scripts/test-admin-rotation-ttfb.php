#!/usr/bin/env php
<?php
/**
 * Guards for Rotation admin TTFB: calendar fetch must be skippable, playlist
 * reads memoize, and quick-add must not probe video duration.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/calendar_lib.php';
require_once $root . '/lib/rotation_lib.php';
require_once $root . '/lib/rotation_pages_store_lib.php';
require_once $root . '/lib/video_lib.php';

$fail = 0;
function expect(bool $ok, string $msg) : void
{
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL  $msg\n";
}

expect(calendar_allow_network_fetch() === true, 'network fetch on by default');
calendar_allow_network_fetch(false);
expect(calendar_allow_network_fetch() === false, 'network fetch can be disabled');
calendar_allow_network_fetch(true);
expect(calendar_allow_network_fetch() === true, 'network fetch can be re-enabled');

$src = (string)file_get_contents($root . '/lib/rotation_lib.php');
expect(
    str_contains($src, 'video_entry_is_live($v)')
        && !preg_match('/rotation_quick_add_items\(\).*video_entry_status/s', $src),
    'quick-add does not call video_entry_status (ffprobe)'
);

$admin = (string)file_get_contents($root . '/admin.php');
expect(str_contains($admin, 'calendar_allow_network_fetch(false)'), 'rotation admin disables ICS network fetch');
expect(str_contains($admin, 'admin_release_session()'), 'admin releases session lock before heavy work');

$cal = (string)file_get_contents($root . '/boards/media/calendar.php');
expect(str_contains($cal, 'calendar_allow_network_fetch()'), 'cached_get honors cache-only mode');

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
