<?php
/**
 * Splunk published Dashboard Studio embeds — admin registry helpers.
 */

require_once __DIR__ . '/rotation_lib.php';
require_once __DIR__ . '/users_lib.php';

/** @param array<string,mixed> $raw @return array<string,array<string,mixed>> */
function splunkdash_normalize_pages_registry(array $raw): array
{
    $out = [];
    foreach ($raw as $k => $page) {
        if (!is_array($page)) {
            continue;
        }
        $key = splunkdash_normalize_key((string)$k);
        $norm = splunkdash_normalize_page($page, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,mixed>|null */
function splunkdash_normalize_page(array $page, string $key): ?array
{
    $title = trim((string)($page['title'] ?? ''));
    $sub = trim((string)($page['sub'] ?? ''));
    $url = trim((string)($page['url'] ?? ''));
    $reloadRaw = trim((string)($page['reload'] ?? ''));
    $reload = $reloadRaw === '' ? null : max(0, (int)$reloadRaw);
    $cropRaw = trim((string)($page['crop_top'] ?? ''));
    if ($cropRaw !== '') {
        $cropTop = max(0, min(400, (int)$cropRaw));
    } else {
        $cropTop = null;
    }

    $out = [];
    if ($url !== '') {
        $out['url'] = $url;
    }
    if ($reload !== null) {
        $out['reload'] = $reload;
    }
    if ($cropTop !== null) {
        $out['crop_top'] = $cropTop;
    }
    if (!empty($page['show_chrome'])) {
        $out['show_chrome'] = true;
    }
    if (!empty($page['show_scrollbars'])) {
        $out['show_scrollbars'] = true;
    }
    if (!empty($page['off'])) {
        $out['off'] = true;
    }
    if ($title !== '') {
        $out['title'] = $title;
    } elseif ($key === 'main') {
        $out['title'] = 'Splunk dashboard';
    } else {
        $out['title'] = ucfirst(str_replace(['_', '-'], ' ', $key));
    }
    if ($sub !== '') {
        $out['sub'] = $sub;
    }

    return admin_merge_entry_access_meta($out, $page);
}

/** @param array<string,mixed>|null $rawConf @return array<string,array<string,mixed>> */
function splunkdash_admin_pages(?array $rawConf = null): array
{
    if ($rawConf === null) {
        $pages = splunkdash_normalize_pages_registry(splunkdash_dashboard_registry());
    } else {
        $raw = is_array($rawConf['splunkdash.DASHBOARDS'] ?? null) ? $rawConf['splunkdash.DASHBOARDS'] : [];
        $pages = splunkdash_normalize_pages_registry($raw);
    }

    return admin_registry_editor_pages(
        $pages,
        static function (): array {
            $main = splunkdash_normalize_page(['title' => 'Splunk dashboard', 'url' => ''], 'main');
            return $main !== null ? ['main' => $main] : [];
        }
    );
}

/**
 * @param array<string|int,mixed> $pagesPost
 * @return array<string,array<string,mixed>>
 */
function splunkdash_pages_from_post(array $pagesPost): array
{
    $out = [];
    foreach ($pagesPost as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = splunkdash_normalize_key((string)($row['_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $norm = splunkdash_normalize_page($row, $key);
        if ($norm !== null) {
            $out[$key] = $norm;
        }
    }

    return $out;
}

/** @return array<string,array<string,mixed>>|null */
function splunkdash_pages_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return null;
    }
    if ($dec === []) {
        return [];
    }

    return splunkdash_normalize_pages_registry($dec);
}

/** @param array<string, scalar|null> $add */
function splunkdash_merge_query_params(string $url, array $add): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $params = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $params);
    }
    foreach ($add as $key => $value) {
        if (!array_key_exists((string)$key, $params)) {
            $params[(string)$key] = $value;
        }
    }

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= (string)($parts['path'] ?? '');
    if ($params !== []) {
        $rebuilt .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

/** Splunk iframe URL with kiosk-friendly chrome hidden (Splunk-side title bar). */
function splunkdash_embed_url(string $url, array $dash = []): string
{
    $url = trim($url);
    if ($url === '' || str_contains($url, 'REPLACE')) {
        return $url;
    }
    if (!empty($dash['show_chrome'])) {
        return $url;
    }
    if (!(bool)cfg('splunkdash.HIDE_CHROME', true)) {
        return $url;
    }

    return splunkdash_merge_query_params($url, [
        'hideChrome' => 'true',
        'hideTitle' => 'true',
        'hideSplunkBar' => 'true',
        'hideAppBar' => 'true',
        'hideFooter' => 'true',
    ]);
}

/** Default pixels to crop when hiding Splunk chrome (published Dashboard Studio ignores URL params). */
function splunkdash_default_crop_top_px(): int
{
    $configured = max(0, min(400, (int)cfg('splunkdash.DEFAULT_CROP_TOP', 0)));
    if ($configured > 0) {
        return $configured;
    }
    if ((bool)cfg('splunkdash.HIDE_CHROME', true)) {
        return 72;
    }

    return 0;
}

/** Viewport overlay height — hides Splunk Dashboard Studio title row (often position:fixed in embed). */
function splunkdash_title_mask_px(array $dash = []): int
{
    if (!empty($dash['show_chrome'])) {
        return 0;
    }
    if (!(bool)cfg('splunkdash.HIDE_CHROME', true)) {
        return 0;
    }

    return max(0, min(160, (int)cfg('splunkdash.TITLE_MASK', 92)));
}

/** Nudge iframe down so Dashboard Studio panel titles are not clipped after crop. */
function splunkdash_embed_shift_down_px(): int
{
    return max(0, min(80, (int)cfg('splunkdash.EMBED_SHIFT_DOWN', 16)));
}

/** Clip Splunk footer / bottom chrome and hide iframe scrollbars. */
function splunkdash_crop_bottom_px(): int
{
    return max(0, min(120, (int)cfg('splunkdash.CROP_BOTTOM', 40)));
}

/** Width of the right-edge cover that hides iframe scrollbars. */
function splunkdash_scrollbar_gutter_px(): int
{
    return max(0, min(120, (int)cfg('splunkdash.SCROLLBAR_GUTTER', 28)));
}

/** @param array<string,mixed> $dash */
function splunkdash_crop_top_px(array $dash): int
{
    if (array_key_exists('crop_top', $dash)) {
        return max(0, min(400, (int)$dash['crop_top']));
    }

    return splunkdash_default_crop_top_px();
}

/** @param array<string,mixed> $dash */
function splunkdash_hide_scrollbars(array $dash = []): bool
{
    if (!empty($dash['show_scrollbars'])) {
        return false;
    }

    return (bool)cfg('splunkdash.HIDE_SCROLLBARS', true);
}
