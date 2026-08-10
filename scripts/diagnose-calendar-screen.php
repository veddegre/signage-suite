#!/usr/bin/env php
<?php
/**
 * Show which calendar feeds a display will use on calendar.php / glance.php.
 *
 * Usage: php scripts/diagnose-calendar-screen.php --screen=veddersg
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
require_once $root . '/lib/users_lib.php';
require_once $root . '/lib/rotation_lib.php';
require_once $root . '/lib/screen_scope_lib.php';
require_once $root . '/lib/calendar_lib.php';

$screen = 'main';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--screen=')) {
        $screen = rotation_normalize_screen_key(substr($arg, 9));
    }
}
if ($screen === '') {
    $screen = 'main';
}

$_GET['screen'] = $screen;

$raw = cfg('calendar.ICS_FEEDS', []);
if (!is_array($raw)) {
    $raw = [];
}
$picked = rotation_screen_calendar_feed_keys($screen);
$scopeUid = admin_display_scope_user_id();
$assignedUid = users_screen_assignments()[$screen] ?? null;
$wall = calendar_feeds_for_signage($screen);
$publicKeys = calendar_public_feed_keys();

echo "Display: {$screen}\n";
echo 'Assigned operator id: ' . ($assignedUid !== null && $assignedUid !== '' ? $assignedUid : '(none)') . "\n";
echo 'Kiosk scope user id: ' . ($scopeUid !== null && $scopeUid !== '' ? $scopeUid : '(none — uses public allowlist when no picks)') . "\n";
echo 'Public wall feed keys: ' . ($publicKeys !== [] ? implode(', ', $publicKeys) : '(none configured)') . "\n";
echo 'Per-display picks: ' . ($picked !== [] ? implode(', ', $picked) : '(none — site/public/owner scope)') . "\n";
echo 'Feeds on wall: ' . count($wall) . "\n\n";

if ($picked === [] && $publicKeys === [] && ($scopeUid === null || $scopeUid === '')) {
    echo "Note: With no kiosk picks and no Calendar → Signage wall feeds checked,\n";
    echo "      calendar.php / glance.php show no feeds on this display.\n";
    echo "      Fix: Rotation → Kiosk settings → check feeds → Save (Kiosk settings tab).\n\n";
}

$i = 0;
foreach ($raw as $feed) {
    if (!is_array($feed)) {
        continue;
    }
    $meta = calendar_feed_meta($feed, $i++);
    $id = (string)($meta['id'] ?? '');
    $owner = admin_entry_owner($feed);
    $ownerLabel = $owner !== null ? admin_username_for_id($owner) . " ({$owner})" : '(super only)';
    $onWall = false;
    foreach ($wall as $wf) {
        if (!is_array($wf)) {
            continue;
        }
        if (calendar_feed_effective_id($wf, 0) === $id || calendar_feed_id($wf) === $id) {
            $onWall = true;
            break;
        }
    }
    $pickedMark = in_array($id, $picked, true) ? 'yes' : 'no';
    echo sprintf(
        "  [%s] %s  id=%s  owner=%s  kiosk pick=%s\n",
        $onWall ? 'ON WALL' : '      ',
        (string)($meta['key'] ?? ''),
        $id,
        $ownerLabel,
        $pickedMark
    );
}

echo "\nPreview: " . signage_rotation_page_preview_url('calendar.php', $screen) . "\n";
