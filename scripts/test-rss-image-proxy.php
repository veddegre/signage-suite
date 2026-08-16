#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/lib/rss_image_lib.php';

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

expect(rss_image_proxy_url('') === '', 'empty url stays empty');
expect(rss_image_proxy_url('/local.jpg') === '', 'non-http url is not proxied');

$url = 'https://example.com/photo.jpg';
$proxied = rss_image_proxy_url($url);
expect(str_starts_with($proxied, 'rss_img.php?i='), 'http url becomes rss_img.php');
$id = rss_image_id($url);
expect(rss_image_lookup($id) === $url, 'remembered url can be looked up');
expect(rss_image_lookup('deadbeef') === null, 'short id rejected');

$tiny = imagecreatetruecolor(32, 32);
ob_start();
imagejpeg($tiny, null, 80);
$jpeg = (string)ob_get_clean();
$out = rss_image_downscale($jpeg);
expect(is_string($out) && $out !== '', 'downscale returns jpeg bytes');

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
