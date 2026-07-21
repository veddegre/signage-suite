<?php
/**
 * Headline lists for glance.php — RSS feeds or HTML page scrape (with RSS autodiscover).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/rss_ticker_lib.php';

/** @return list<array{title:string}> */
function glance_headlines_from_feed_key(string $feedKey, int $maxItems): array
{
    $feedKey = trim($feedKey);
    if ($feedKey === '') {
        return [];
    }

    return rss_ticker_headlines($feedKey, $maxItems);
}

function glance_headlines_absolute_url(string $href, string $base): string
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $href;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    if (str_starts_with($href, '//')) {
        return $parts['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $path = $parts['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';

    return $origin . $dir . $href;
}

function glance_headlines_discover_feed_url(string $html, string $pageUrl): ?string
{
    if (preg_match_all('/<link\b[^>]*>/i', $html, $links)) {
        foreach ($links[0] as $tag) {
            if (!preg_match('/\brel=["\']([^"\']*)["\']/i', $tag, $rel) || !preg_match('/alternate/i', $rel[1])) {
                continue;
            }
            if (!preg_match('/\btype=["\']([^"\']+)["\']/i', $tag, $type)
                || !preg_match('/application\/(rss|atom)\+xml/i', $type[1])) {
                continue;
            }
            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) {
                continue;
            }
            $url = glance_headlines_absolute_url(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $pageUrl);
            if ($url !== '') {
                return $url;
            }
        }
    }

    return null;
}

/** @return list<string> */
function glance_headlines_unique_titles(array $titles, int $maxItems): array
{
    $out = [];
    $seen = [];
    foreach ($titles as $title) {
        $title = trim(preg_replace('/\s+/u', ' ', (string)$title) ?? '');
        if ($title === '' || mb_strlen($title) < 12) {
            continue;
        }
        $key = mb_strtolower($title);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = mb_strlen($title) > 140 ? mb_substr($title, 0, 137) . '…' : $title;
        if (count($out) >= $maxItems) {
            break;
        }
    }

    return $out;
}

/** @return list<array{title:string}> */
function glance_headlines_from_html(string $html, int $maxItems): array
{
    $titles = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (@$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $xp = new DOMXPath($dom);
        $queries = [
            '//*[contains(concat(" ", normalize-space(@class), " "), " preview-title ")]//a',
            '//*[contains(concat(" ", normalize-space(@class), " "), " preview-title ")]',
            '//article//h2//a',
            '//article//h3//a',
            '//article//h2',
            '//article//h3',
            '//*[contains(concat(" ", normalize-space(@class), " "), " headline ")]//a',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-title ")]//a',
            '//*[contains(concat(" ", normalize-space(@class), " "), " story-title ")]//a',
            '//*[contains(concat(" ", normalize-space(@class), " "), " card-title ")]//a',
            '//main//h2//a',
            '//main//h3//a',
        ];
        foreach ($queries as $q) {
            $nodes = $xp->query($q);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $titles[] = trim($node->textContent ?? '');
            }
        }
    }

    $items = [];
    foreach (glance_headlines_unique_titles($titles, $maxItems) as $title) {
        $items[] = ['title' => $title];
    }

    return $items;
}

function glance_headlines_fetch_raw(string $url, string $cacheKey, int $ttl): ?string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $cacheFile = $dir . '/' . $cacheKey . '.dat';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return (string)file_get_contents($cacheFile);
    }

    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return is_file($cacheFile) ? (string)file_get_contents($cacheFile) : null;
    }
    if (!function_exists('curl_init')) {
        return is_file($cacheFile) ? (string)file_get_contents($cacheFile) : null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_USERAGENT => 'HomeSignage/Glance/1.0',
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        @file_put_contents($cacheFile, $body, LOCK_EX);

        return (string)$body;
    }

    return is_file($cacheFile) ? (string)file_get_contents($cacheFile) : null;
}

/** @return list<array{title:string}> */
function glance_headlines_from_rss_url(string $url, int $maxItems, int $ttl): array
{
    $url = trim($url);
    if ($url === '') {
        return [];
    }
    $cacheKey = 'glance_rss_' . substr(sha1($url), 0, 16);
    $raw = glance_headlines_fetch_raw($url, $cacheKey, $ttl);
    if ($raw === null || $raw === '') {
        return [];
    }

    return rss_ticker_parse_feed($raw, $maxItems);
}

/**
 * Fetch headlines from a web page — tries RSS/Atom autodiscover, then HTML scrape.
 *
 * @return list<array{title:string}>
 */
function glance_headlines_from_page(string $pageUrl, int $maxItems, int $ttl): array
{
    $pageUrl = trim($pageUrl);
    if ($pageUrl === '') {
        return [];
    }
    $maxItems = max(1, min(12, $maxItems));
    $cacheKey = 'glance_page_' . substr(sha1($pageUrl . '|' . $maxItems), 0, 16);
    $cacheFile = SIGNAGE_ROOT . '/cache/' . $cacheKey . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $html = glance_headlines_fetch_raw($pageUrl, $cacheKey . '_html', $ttl);
    if ($html === null || $html === '') {
        return is_file($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
    }

    $items = [];
    $feedUrl = glance_headlines_discover_feed_url($html, $pageUrl);
    if ($feedUrl !== null) {
        $items = glance_headlines_from_rss_url($feedUrl, $maxItems, $ttl);
    }
    if ($items === []) {
        $items = glance_headlines_from_html($html, $maxItems);
    }

    if ($items !== []) {
        @file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return $items;
}

/**
 * @return array{items:list<array{title:string}>,source:string}
 */
function glance_headlines_panel(string $mode, string $pageUrl, string $feedKey, int $maxItems, int $ttl): array
{
    $maxItems = max(1, min(12, $maxItems));
    if ($mode === 'page') {
        $pageUrl = trim($pageUrl);
        if ($pageUrl !== '') {
            $items = glance_headlines_from_page($pageUrl, $maxItems, $ttl);
            if ($items !== []) {
                return ['items' => $items, 'source' => 'page'];
            }
        }
        if (trim($feedKey) !== '') {
            return ['items' => glance_headlines_from_feed_key($feedKey, $maxItems), 'source' => 'rss'];
        }

        return ['items' => [], 'source' => ''];
    }

    return ['items' => glance_headlines_from_feed_key($feedKey, $maxItems), 'source' => 'rss'];
}
