<?php
/**
 * Per-display overrides — weather location and sports teams for rotation screens.
 */

require_once __DIR__ . '/rotation_lib.php';

/** Kiosk / framed board screen key from ?screen= (empty → main). */
function signage_request_screen(): string
{
    return rotation_normalize_screen_key((string)($_GET['screen'] ?? ''));
}

/** @return array<string,mixed>|null Raw rotation.SCREENS entry. */
function rotation_screen_raw_entry(string $screen): ?array
{
    $screen = rotation_normalize_screen_key($screen);
    $screensCfg = cfg('rotation.SCREENS', ['main' => 'Main Display']);
    if (!is_array($screensCfg)) {
        return null;
    }
    foreach ($screensCfg as $k => $v) {
        if (rotation_normalize_screen_key((string)$k) !== $screen) {
            continue;
        }
        return is_array($v) ? $v : ['name' => (string)$v];
    }

    return null;
}

/** @return array{place:string,lat:float,lon:float} */
function rotation_global_location(): array
{
    return [
        'place' => trim((string)cfg('index.LOCATION', 'Allendale, Michigan')),
        'lat' => (float)cfg('index.LAT', 42.9720),
        'lon' => (float)cfg('index.LON', -85.9536),
    ];
}

/** Display location override with global Weather board fallback. @return array{place:string,lat:float,lon:float} */
function rotation_screen_location(string $screen): array
{
    $global = rotation_global_location();
    $scr = rotation_screen_raw_entry($screen);
    if (!is_array($scr)) {
        return $global;
    }
    $latRaw = trim((string)($scr['location_lat'] ?? ''));
    $lonRaw = trim((string)($scr['location_lon'] ?? ''));
    if ($latRaw === '' || $lonRaw === '') {
        return $global;
    }
    $lat = (float)$latRaw;
    $lon = (float)$lonRaw;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return $global;
    }
    $place = trim((string)($scr['location_place'] ?? ''));

    return [
        'place' => $place !== '' ? $place : $global['place'],
        'lat' => $lat,
        'lon' => $lon,
    ];
}

/** @return array{place:string,lat:string,lon:string} Stored override fields (may be empty). */
function rotation_screen_location_fields(string $screen): array
{
    $scr = rotation_screen_raw_entry($screen);
    if (!is_array($scr)) {
        return ['place' => '', 'lat' => '', 'lon' => ''];
    }

    return [
        'place' => trim((string)($scr['location_place'] ?? '')),
        'lat' => trim((string)($scr['location_lat'] ?? '')),
        'lon' => trim((string)($scr['location_lon'] ?? '')),
    ];
}

/** Apply location + sports fields from SCREEN_OPTS POST onto a screen registry entry. */
function rotation_apply_screen_scope_post_row(array $entry, array $row): array
{
    $place = trim((string)($row['location_place'] ?? ''));
    $lat = trim((string)($row['location_lat'] ?? ''));
    $lon = trim((string)($row['location_lon'] ?? ''));
    if ($place === '' && $lat === '' && $lon === '') {
        unset($entry['location_place'], $entry['location_lat'], $entry['location_lon']);
    } else {
        if ($place !== '') {
            $entry['location_place'] = $place;
        } else {
            unset($entry['location_place']);
        }
        if ($lat !== '' && $lon !== '' && (float)$lat >= -90 && (float)$lat <= 90 && (float)$lon >= -180 && (float)$lon <= 180) {
            $entry['location_lat'] = round((float)$lat, 6);
            $entry['location_lon'] = round((float)$lon, 6);
        } else {
            unset($entry['location_place'], $entry['location_lat'], $entry['location_lon']);
        }
    }

    require_once __DIR__ . '/sports_lib.php';
    $slots = $row['sports_team_slots'] ?? null;
    $keys = [];
    if (is_array($slots)) {
        $catalog = sports_team_catalog();
        foreach ($slots as $slot) {
            $key = trim((string)$slot);
            if ($key === '' || !isset($catalog[$key])) {
                continue;
            }
            $keys[$key] = true;
            if (count($keys) >= 4) {
                break;
            }
        }
    }
    $keys = array_keys($keys);
    if ($keys === []) {
        unset($entry['sports_teams']);
    } else {
        $entry['sports_teams'] = $keys;
    }

    $sportsTitle = trim((string)($row['sports_title'] ?? ''));
    if ($sportsTitle !== '') {
        $entry['sports_title'] = $sportsTitle;
    } else {
        unset($entry['sports_title']);
    }

    $sportsSubtitle = trim((string)($row['sports_subtitle'] ?? ''));
    if ($sportsSubtitle !== '') {
        $entry['sports_subtitle'] = $sportsSubtitle;
    } else {
        unset($entry['sports_subtitle']);
    }

    require_once __DIR__ . '/rss_ticker_lib.php';
    require_once __DIR__ . '/users_lib.php';
    $newsFeed = trim((string)($row['ticker_news_feed'] ?? ''));
    if ($newsFeed !== '' && rss_ticker_resolve_feed($newsFeed) !== null) {
        $resolved = admin_normalize_registry_key($newsFeed);
        $entry['ticker_news_feed'] = $resolved ?? $newsFeed;
    } else {
        unset($entry['ticker_news_feed']);
    }

    return $entry;
}

/** @return list<string> Selected catalog keys, or empty = use site default teams. */
function rotation_screen_sports_team_keys(string $screen): array
{
    $scr = rotation_screen_raw_entry($screen);
    if (!is_array($scr) || !isset($scr['sports_teams']) || !is_array($scr['sports_teams'])) {
        return [];
    }
    require_once __DIR__ . '/sports_lib.php';
    $catalog = sports_team_catalog();
    $out = [];
    foreach ($scr['sports_teams'] as $key) {
        $key = trim((string)$key);
        if ($key !== '' && isset($catalog[$key])) {
            $out[] = $key;
        }
        if (count($out) >= 4) {
            break;
        }
    }

    return $out;
}

/** @return array{title:string,subtitle:string} */
function rotation_screen_sports_labels(string $screen): array
{
    require_once __DIR__ . '/sports_lib.php';
    $scr = rotation_screen_raw_entry($screen);
    $title = is_array($scr) ? trim((string)($scr['sports_title'] ?? '')) : '';
    if ($title === '') {
        $title = (string)cfg('sports.TITLE', 'Sports');
    }
    $subtitle = is_array($scr) ? trim((string)($scr['sports_subtitle'] ?? '')) : '';
    if ($subtitle === '') {
        $teams = sports_teams_for_screen($screen);
        $names = array_map(static fn(array $t): string => (string)($t['name'] ?? ''), $teams);
        $names = array_values(array_filter($names, static fn(string $n): bool => $n !== ''));
        $subtitle = $names !== [] ? implode(' · ', $names) : (string)cfg('sports.SUBTITLE', '');
    }

    return ['title' => $title, 'subtitle' => $subtitle];
}

/** Configured RSS feed key for news ticker fallback, or empty when off. */
function rotation_screen_ticker_news_feed(string $screen): string
{
    $scr = rotation_screen_raw_entry($screen);
    if (!is_array($scr)) {
        return '';
    }
    $key = trim((string)($scr['ticker_news_feed'] ?? ''));
    if ($key === '') {
        return '';
    }
    require_once __DIR__ . '/rss_ticker_lib.php';
    $feed = rss_ticker_resolve_feed($key);

    return $feed !== null ? (string)$feed['key'] : '';
}

/** Load ticker constants — per-display lat/lon when set, otherwise global Weather / ticker settings. */
function signage_ticker_bootstrap(?string $screen = null): void
{
    if (!defined('TICKER_UA')) {
        define('TICKER_UA', (string)cfg('ticker.TICKER_UA', 'HomeSignage/1.0 (contact: you@example.com)'));
        define('TICKER_TTL', max(30, (int)cfg('ticker.TICKER_TTL', 300)));
        define('TICKER_MODE', (string)cfg('ticker.TICKER_MODE', 'scroll'));
        define('TICKER_MIN_SEVERITY', (string)cfg('ticker.TICKER_MIN_SEVERITY', 'Minor'));
        define('TICKER_DEMO', (bool)cfg('ticker.TICKER_DEMO', false));
    }
    if (!defined('TICKER_NEWS_FEED')) {
        if ($screen === null) {
            $screen = signage_request_screen();
        }
        define('TICKER_NEWS_FEED', rotation_screen_ticker_news_feed($screen));
    }
    if (defined('TICKER_LAT') && defined('TICKER_LON')) {
        return;
    }
    if ($screen === null) {
        $screen = signage_request_screen();
    }
    $loc = rotation_screen_location($screen);
    if (!defined('TICKER_LAT')) {
        define('TICKER_LAT', $loc['lat']);
    }
    if (!defined('TICKER_LON')) {
        define('TICKER_LON', $loc['lon']);
    }
}

/** @deprecated Use signage_ticker_bootstrap() */
function signage_prime_ticker_location(string $screen): void
{
    signage_ticker_bootstrap($screen);
}

/** ticker.php poll URL for a rotation display (includes ?screen= for per-display location). */
function signage_ticker_api_url(?string $screen = null): string
{
    if ($screen === null) {
        $screen = signage_request_screen();
    } else {
        $screen = rotation_normalize_screen_key($screen);
    }

    return 'ticker.php?api=1&screen=' . rawurlencode($screen);
}
