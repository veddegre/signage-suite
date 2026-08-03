<?php
/**
 * Calendar ICS_FEEDS — unique feed ids + duplicate legend labels.
 * Run: php scripts/test-calendar-feed-save.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/users_lib.php';
require_once __DIR__ . '/../lib/calendar_lib.php';

$_SESSION['auth'] = true;
$_SESSION['admin_user'] = [
    'id' => 'infra-test-user',
    'username' => 'infra',
    'role' => 'infra',
    'screens' => ['main'],
    'auth_provider' => 'local',
    'disabled' => false,
];

$fail = 0;

function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        echo "FAIL: $msg\n";
        $fail++;
    } else {
        echo "OK: $msg\n";
    }
}

$columns = [
    ['key' => 'key', 'label' => 'Legend'],
    ['key' => 'color', 'label' => 'Color', 'type' => 'palette'],
    ['key' => 'source', 'label' => 'Source', 'type' => 'select', 'options' => ['ical', 'webdav']],
    ['key' => 'url', 'label' => 'URL', 'wide' => true],
];

function parse_ics_feed_row(array $row, array $existing): ?array
{
    $row = admin_normalize_form_row($row);
    $obj = [];
    $any = false;
    foreach ($row as $k => $v) {
        if ($k === 'id' || $k === '_sharing_form' || $k === 'owner' || $k === 'shared' || is_array($v)) {
            if ($k === 'id' && trim((string)$v) !== '') {
                $obj['id'] = trim((string)$v);
            }
            continue;
        }
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        $any = true;
        $obj[$k] = $v;
    }
    if (!$any) {
        return null;
    }
    $prev = admin_find_owned_list_entry($existing, $obj);

    return admin_finalize_entry($obj, $prev, $row);
}

// Two feeds with same legend — both should persist with different ids
$existing = [
    ['id' => 'cal_aaaaaaaa', 'key' => 'Greg', 'color' => 'beacon', 'url' => 'https://example.com/greg1.ics', 'owner' => 'super'],
];
$row1 = parse_ics_feed_row([
    'key' => 'Greg',
    'color' => 'coral',
    'source' => 'ical',
    'url' => 'https://example.com/greg2.ics',
], $existing);
assert_true(is_array($row1), 'second Greg row parses');
$merged = admin_merge_owned_list($existing, [$row1]);
$final = calendar_finalize_feed_list($merged);
assert_true(count($final) === 2, 'two Greg feeds kept in config');
$ids = array_map(static fn($f) => $f['id'] ?? '', $final);
assert_true(count(array_unique($ids)) === 2, 'each feed has a unique id');
$visible = admin_filter_owned_list($final);
assert_true(count($visible) === 1, 'infra sees only owned feed');
assert_true(($visible[0]['key'] ?? '') === 'Greg', 'legend preserved');

$filter = calendar_filter_feeds_by_keys($final, [$ids[1]]);
assert_true(count($filter) === 1 && calendar_feed_effective_id($filter[0], 0) === $ids[1], 'filter by feed id');

$legacyPick = calendar_resolve_feed_ref('Greg', $final);
assert_true($legacyPick === null, 'ambiguous legacy legend does not resolve to one id');

$single = [['id' => 'cal_bbbbbbbb', 'key' => 'Dad', 'url' => 'https://example.com/dad.ics']];
assert_true(calendar_resolve_feed_ref('Dad', $single) === 'cal_bbbbbbbb', 'unique legacy legend resolves');

exit($fail > 0 ? 1 : 0);
