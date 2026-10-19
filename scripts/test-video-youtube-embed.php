#!/usr/bin/env php
<?php
/**
 * Offline checks for YouTube live embed URLs (Soo Locks / StreamTime path).
 *
 * Usage: php scripts/test-video-youtube-embed.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/lib/video_lib.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL  $msg\n";
}

$id = video_youtube_video_id('https://www.youtube.com/watch?v=TkY4BzCikQ4');
expect($id === 'TkY4BzCikQ4', 'watch URL parses StreamTime Soo Locks id');

expect(
    video_youtube_video_id('https://www.youtube.com/live/TkY4BzCikQ4') === 'TkY4BzCikQ4',
    '/live/ URL parses the same id'
);
expect(
    video_youtube_video_id('https://youtu.be/TkY4BzCikQ4') === 'TkY4BzCikQ4',
    'youtu.be URL parses the same id'
);
expect(
    video_youtube_video_id('https://www.youtube.com/embed/TkY4BzCikQ4') === 'TkY4BzCikQ4',
    'embed URL parses the same id'
);

$muted = video_youtube_embed_url('TkY4BzCikQ4', true, 'https://s.vdrs.fyi');
expect(str_contains($muted, 'https://www.youtube.com/embed/TkY4BzCikQ4?'), 'embed path uses the video id');
expect(str_contains($muted, 'enablejsapi=1'), 'embed includes enablejsapi for live-edge seek');
expect(str_contains($muted, 'mute=1'), 'muted embed includes mute=1 for nested autoplay');
expect(str_contains($muted, 'autoplay=1'), 'embed autoplays');
expect(str_contains($muted, 'origin='), 'embed includes origin when given');
expect(str_contains($muted, 'widget_referrer='), 'embed includes widget_referrer when given');
expect(str_contains($muted, 's.vdrs.fyi'), 'origin host is present in the query');

$loud = video_youtube_embed_url('TkY4BzCikQ4', false);
expect(!str_contains($loud, 'mute='), 'unmuted embed omits mute');
expect(str_contains($loud, 'enablejsapi=1'), 'unmuted embed still has enablejsapi');
expect(!str_contains($loud, 'origin='), 'origin omitted when not provided');

expect(
    video_entry_is_live(['youtube' => 'https://www.youtube.com/watch?v=TkY4BzCikQ4', 'live' => true]),
    'live flag marks a watch URL as embed-ready'
);
expect(
    video_entry_is_live(['youtube' => 'https://www.youtube.com/live/TkY4BzCikQ4']),
    '/live/ URL auto-detects as live'
);
expect(
    !video_entry_is_live(['youtube' => 'https://www.youtube.com/watch?v=TkY4BzCikQ4']),
    'watch URL without live flag is not treated as a live embed'
);

expect(video_youtube_live_head_seconds(10.0) === null, 'sub-30s duration has no live head');
expect(video_youtube_live_head_seconds(120.0, 0.0) === 90.0, 'short stream from t=0 aims just before duration');
expect(video_youtube_live_head_seconds(120.0, 100.0) === null, 'already near the short-stream head');
// 12h DVR parked at the rewind start (t=0) → jump to just before the window end.
expect(
    video_youtube_live_head_seconds(43200.0, 0.0) === 43170.0,
    '12h DVR from t=0 seeks to duration minus pad'
);
// Absolute timeline: currentTime is already elapsed-12h.
expect(
    video_youtube_live_head_seconds(200000.0, 200000.0 - 43200.0) === 199970.0,
    '12h behind on a long timeline jumps forward one window'
);
expect(
    video_youtube_live_head_seconds(200000.0, 199990.0) === null,
    'already at live does not seek'
);

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
