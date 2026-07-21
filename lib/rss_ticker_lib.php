<?php
/**
 * RSS headline fetch for the bottom news ticker fallback (when no weather alerts).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/security_lib.php';

/** @return array{key:string,name:string,url:string}|null */
function rss_ticker_resolve_feed(string $feedKey): ?array
{
    require_once __DIR__ . '/users_lib.php';
    $key = admin_normalize_registry_key($feedKey);
    if ($key === null) {
        return null;
    }
    $registry = rss_feed_registry();
    $resolved = admin_registry_resolve_key($registry, $key);
    if ($resolved === null || !isset($registry[$resolved]) || !is_array($registry[$resolved])) {
        return null;
    }
    $feed = $registry[$resolved];
    if (!empty($feed['off'])) {
        return null;
    }
    $url = trim((string)($feed['url'] ?? ''));
    if ($url === '') {
        return null;
    }

    return [
        'key' => (string)$resolved,
        'name' => trim((string)($feed['name'] ?? $resolved)),
        'url' => $url,
    ];
}

function rss_ticker_clean_title(string $html): string
{
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    if ($t === '') {
        return '';
    }
    if (mb_strlen($t) > 240) {
        $t = mb_substr($t, 0, 237) . '…';
    }

    return $t;
}

/** @return list<array{title:string}> */
function rss_ticker_parse_feed(string $raw, int $maxItems): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NOCDATA);
    if ($xml === false) {
        return [];
    }

    $items = [];
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $it) {
            $title = rss_ticker_clean_title((string)$it->title);
            if ($title !== '') {
                $items[] = ['title' => $title];
            }
            if (count($items) >= $maxItems) {
                break;
            }
        }
    } elseif (isset($xml->entry)) {
        foreach ($xml->entry as $it) {
            $title = rss_ticker_clean_title((string)$it->title);
            if ($title !== '') {
                $items[] = ['title' => $title];
            }
            if (count($items) >= $maxItems) {
                break;
            }
        }
    }

    return $items;
}

/** @return list<array{title:string}> */
function rss_ticker_headlines(string $feedKey, int $maxItems = 12): array
{
    $feed = rss_ticker_resolve_feed($feedKey);
    if ($feed === null) {
        return [];
    }

    $maxItems = max(1, min(20, $maxItems));
    $ttl = max(60, min(3600, (int)cfg('rss.CACHE_TTL', 600)));
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $cacheFile = $dir . '/ticker_news_' . preg_replace('/[^a-z0-9_\-]/i', '_', $feed['key']) . '.dat';

    $raw = null;
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $raw = (string)file_get_contents($cacheFile);
    } else {
        $policy = signage_fetch_url_allowed($feed['url'], signage_allow_private_fetch());
        if (!$policy['ok']) {
            return is_file($cacheFile) ? rss_ticker_parse_feed((string)file_get_contents($cacheFile), $maxItems) : [];
        }
        $ch = curl_init($feed['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_USERAGENT => 'HomeSignage/1.0 (news ticker)',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body !== false && $code === 200) {
            @file_put_contents($cacheFile, $body, LOCK_EX);
            $raw = (string)$body;
        } elseif (is_file($cacheFile)) {
            $raw = (string)file_get_contents($cacheFile);
        }
    }

    if ($raw === null || $raw === '') {
        return [];
    }

    return rss_ticker_parse_feed($raw, $maxItems);
}
