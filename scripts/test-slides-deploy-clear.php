<?php
/**
 * Regression: Deploy must clear playlist slide rows when the deck targets none.
 *
 * Usage: php scripts/test-slides-deploy-clear.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/lib/slides_lib.php';
require_once $root . '/lib/rotation_lib.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if ($ok) {
        echo "OK  $msg\n";
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
}

$deck = [
    [
        'file' => 'keep-on-main.png',
        'dwell' => 12,
        'screens' => ['main'],
    ],
    [
        'file' => 'was-on-other.png',
        'dwell' => 12,
        'screens' => [], // hidden everywhere
    ],
];

// Simulate a playlist that still has both slides (stale after untargeting).
$stalePages = [
    ['url' => 'weather.php', 'dwell' => 30],
    ['url' => 'slides.php?slide=keep-on-main.png', 'dwell' => 12],
    ['url' => 'slides.php?slide=was-on-other.png', 'dwell' => 12],
    ['url' => 'calendar.php', 'dwell' => 30],
];

// Patch: call the clear path logic by temporarily stubbing source pages via
// a direct unit of the clear branch (expected=[]).
$manageAll = true;
$scopeSet = null;
$expected = slides_rotation_pages_for_scope($deck, 'edingco', null, false);
expect($expected === [], 'edingco expected empty (no targets)');

$filtered = [];
$cleared = 0;
foreach ($stalePages as $page) {
    $url = trim((string)($page['url'] ?? ''));
    if (rotation_is_slide_url($url) || rotation_is_legacy_slides_url($url)) {
        $cleared++;
        continue;
    }
    $filtered[] = $page;
}
expect($cleared === 2, 'would clear 2 stale slide rows');
expect(count($filtered) === 2, 'non-slide rows preserved');
expect(($filtered[0]['url'] ?? '') === 'weather.php', 'weather kept');
expect(($filtered[1]['url'] ?? '') === 'calendar.php', 'calendar kept');

// Targeting helpers (no on-disk file required)
expect(slide_on_screen($deck[0], 'main'), 'keep-on-main targets main');
expect(!slide_on_screen($deck[0], 'edingco'), 'keep-on-main does not target edingco');
expect(!slide_on_screen($deck[1], 'main'), 'hidden slide targets nobody');
expect(!slide_on_screen($deck[1], 'edingco'), 'hidden slide targets nobody on edingco');

// Flash message for empty-target-only deploy
$msg = slides_deploy_flash_message([
    'slide_count' => 0,
    'screens' => [],
    'skipped' => [],
    'empty_target' => ['veddersg'],
    'cleared' => 0,
    'on_disk' => 10,
    'no_scope' => false,
]);
expect(str_contains($msg, 'No slides target'), 'empty-target flash explains assignment needed: ' . $msg);

$msg2 = slides_deploy_flash_message([
    'slide_count' => 0,
    'screens' => ['edingco'],
    'skipped' => [],
    'empty_target' => [],
    'cleared' => 2,
    'added' => 0,
    'updated' => 2,
    'on_disk' => 10,
    'no_scope' => false,
]);
expect(str_contains($msg2, 'cleared 2'), 'clear flash mentions cleared count: ' . $msg2);

if ($fail > 0) {
    echo "\n$fail failure(s)\n";
    exit(1);
}
echo "\nAll checks passed.\n";
exit(0);
