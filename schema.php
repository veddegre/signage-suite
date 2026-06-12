<?php
/**
 * ADMIN SCHEMA — describes every editable setting per board.
 * admin.php renders forms from this; boards never read it.
 *
 * Field types:
 *   text | password | number | bool | select (options) | textarea
 *   rows   — repeatable row editor. 'columns' define the cells.
 *            'keyed' => true  → saved as a map, first column is the key
 *            'scalar' => true → keyed map of key => single value (2 columns)
 *            otherwise        → saved as a list of objects
 *   json   — raw JSON textarea with validation (for complex structures)
 */

/** One pages-editor field per configured screen. */
function rotation_page_fields(): array
{
    $screens = cfg('rotation.SCREENS', ['main' => 'Main Display']);
    if (!is_array($screens) || $screens === []) $screens = ['main' => 'Main Display'];
    if (!isset($screens['main'])) $screens = ['main' => 'Main Display'] + $screens;
    $cols = [['key' => 'url', 'label' => 'URL', 'wide' => true, 'placeholder' => 'rss.php?feed=ars'],
             ['key' => 'dwell', 'label' => 'Dwell (s)', 'cast' => 'int'],
             ['key' => 'from', 'label' => 'From hr', 'cast' => 'int'],
             ['key' => 'to', 'label' => 'To hr', 'cast' => 'int'],
             ['key' => 'off', 'label' => 'Skip', 'type' => 'check']];
    $out = [];
    foreach ($screens as $key => $scr) {
        $name = is_array($scr) ? ($scr['name'] ?? $key) : $scr;   // tolerate the older key=>name shape
        $out[] = ['key' => 'PAGES_' . $key, 'label' => 'Pages — ' . $name . ' (?screen=' . $key . ')',
                  'type' => 'rows', 'columns' => $cols,
                  'help' => $key === 'main'
                      ? 'Relative board URLs in display order. From/To hours (0-23) optional; blank = always. Overnight (22 to 6) works.'
                      : 'Leave empty to mirror the main screen.'];
    }
    return $out;
}

function admin_schema(): array
{
    $tz  = ['key' => 'TIMEZONE', 'label' => 'Timezone', 'type' => 'text', 'help' => 'PHP timezone, e.g. America/Detroit'];
    $ttl = fn($h = '') => ['key' => 'CACHE_TTL', 'label' => 'Cache TTL (seconds)', 'type' => 'number', 'help' => $h ?: 'How long API responses are cached'];

    return [
        'rotation' => ['title' => 'Rotation', 'file' => 'board.php', 'fields' => array_merge([
            ['key' => 'SCREENS', 'label' => 'Screens', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?screen='],
                           ['key' => 'name', 'label' => 'Display name'],
                           ['key' => 'shuffle', 'label' => 'Shuffle', 'type' => 'check']],
             'help' => 'One row per physical display. Each kiosk points at board.php?screen=<key> '
                     . '(plain board.php = the "main" screen). Save after adding a screen and its '
                     . 'page list appears below. A screen with no pages of its own falls back to main.'],
        ], rotation_page_fields(), [
            ['key' => 'FADE_MS', 'label' => 'Crossfade (ms)', 'type' => 'number'],
            ['key' => 'SETTLE_MS', 'label' => 'Settle after load (ms)', 'type' => 'number',
             'help' => 'Lets fonts/maps finish before reveal'],
            ['key' => 'HANG_MS', 'label' => 'Hung-page timeout (ms)', 'type' => 'number'],
        ])],
        'index' => ['title' => 'Weather', 'file' => 'index.php', 'fields' => [
            ['key' => 'OWM_API_KEY', 'label' => 'OpenWeatherMap API key', 'type' => 'password'],
            ['key' => 'LOCATION', 'label' => 'Location name', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'UNITS', 'label' => 'Units', 'type' => 'select', 'options' => ['imperial', 'metric']],
            ['key' => 'RADAR_URL', 'label' => 'Fallback radar GIF URL', 'type' => 'text',
             'help' => 'NWS RIDGE loop used if RainViewer fails'],
            $tz, $ttl(),
        ]],
        'lake' => ['title' => 'Lake Michigan', 'file' => 'lake.php', 'fields' => [
            ['key' => 'NDBC_STATION', 'label' => 'NDBC station ID', 'type' => 'text', 'help' => 'e.g. 45161 (Muskegon nearshore)'],
            ['key' => 'STATION_NAME', 'label' => 'Station display name', 'type' => 'text'],
            ['key' => 'BEACH_NAME', 'label' => 'Beach name', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'NWS_UA', 'label' => 'NWS User-Agent', 'type' => 'text', 'help' => 'Include a contact email — NWS asks for it'],
            $tz, $ttl(),
        ]],
        'photo' => ['title' => 'Photo Conditions', 'file' => 'photo.php', 'fields' => [
            ['key' => 'OWM_API_KEY', 'label' => 'OpenWeatherMap API key', 'type' => 'password'],
            ['key' => 'PLACE', 'label' => 'Place name', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            $tz, $ttl(),
        ]],
        'signaltrace' => ['title' => 'SignalTrace', 'file' => 'signaltrace.php', 'fields' => [
            ['key' => 'ST_BASE_URL', 'label' => 'SignalTrace base URL', 'type' => 'text'],
            ['key' => 'ST_EXPORT_TOKEN', 'label' => 'Export API token', 'type' => 'password',
             'help' => 'EXPORT_API_TOKEN from includes/config.local.php — used server-side only'],
            ['key' => 'WINDOW_HOURS', 'label' => 'Window (hours)', 'type' => 'number'],
            $tz, $ttl(),
        ]],
        'homelab' => ['title' => 'Homelab Ops', 'file' => 'homelab.php', 'fields' => [
            ['key' => 'PVE_HOST', 'label' => 'Proxmox host', 'type' => 'text', 'help' => 'https://host:8006'],
            ['key' => 'PVE_TOKEN_ID', 'label' => 'Proxmox token ID', 'type' => 'text', 'help' => 'user@realm!tokenid'],
            ['key' => 'PVE_TOKEN_SECRET', 'label' => 'Proxmox token secret', 'type' => 'password'],
            ['key' => 'PVE_VERIFY_TLS', 'label' => 'Verify Proxmox TLS', 'type' => 'bool', 'default' => false, 'help' => 'Off for self-signed certs'],
            ['key' => 'ADGUARD_URL', 'label' => 'AdGuard Home URL', 'type' => 'text'],
            ['key' => 'ADGUARD_USER', 'label' => 'AdGuard user', 'type' => 'text'],
            ['key' => 'ADGUARD_PASS', 'label' => 'AdGuard password', 'type' => 'password'],
            ['key' => 'SERVICES', 'label' => 'Service checks', 'type' => 'rows', 'keyed' => true, 'scalar' => true,
             'columns' => [['key' => '_key', 'label' => 'Name'], ['key' => '_value', 'label' => 'URL']]],
            ['key' => 'LATENCY_TARGET', 'label' => 'WAN latency target', 'type' => 'text'],
            $tz, $ttl(),
        ]],
        'rotator' => ['title' => 'Photo Rotator', 'file' => 'rotator.php', 'fields' => [
            ['key' => 'PHOTO_DIR', 'label' => 'Photo directory', 'type' => 'text', 'help' => 'Server path of JPG/PNG files'],
            ['key' => 'BRAND', 'label' => 'Brand wordmark', 'type' => 'text'],
            ['key' => 'INTERVAL_SEC', 'label' => 'Seconds per photo', 'type' => 'number'],
            ['key' => 'SHUFFLE', 'label' => 'Shuffle order', 'type' => 'bool', 'default' => true],
            ['key' => 'SHOW_EXIF', 'label' => 'Show EXIF caption', 'type' => 'bool', 'default' => true],
            $tz,
        ]],
        'family' => ['title' => 'Family Board', 'file' => 'family.php', 'fields' => [
            ['key' => 'ICS_FEEDS', 'label' => 'Calendar feeds (ICS)', 'type' => 'rows',
             'columns' => [['key' => 'name', 'label' => 'Name'],
                           ['key' => 'url', 'label' => 'Secret iCal URL', 'wide' => true],
                           ['key' => 'color', 'label' => 'Color', 'placeholder' => '#ffb347']]],
            ['key' => 'TRASH_WEEKDAY', 'label' => 'Trash day', 'type' => 'select',
             'options' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']],
            ['key' => 'RECYCLE_ANCHOR', 'label' => 'Recycle anchor date', 'type' => 'text',
             'help' => 'Any past recycling-pickup date (YYYY-MM-DD); empty disables the biweekly cadence'],
            ['key' => 'COUNTDOWNS', 'label' => 'Countdowns', 'type' => 'rows', 'keyed' => true, 'scalar' => true,
             'columns' => [['key' => '_key', 'label' => 'Label'], ['key' => '_value', 'label' => 'Date (YYYY-MM-DD)']]],
            $tz, $ttl(),
        ]],
        'rss' => ['title' => 'RSS Stories', 'file' => 'rss.php', 'fields' => [
            ['key' => 'FEEDS', 'label' => 'Feeds', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?feed='],
                           ['key' => 'name', 'label' => 'Display name'],
                           ['key' => 'url', 'label' => 'Feed URL', 'wide' => true],
                           ['key' => 'stories', 'label' => '# stories', 'cast' => 'int'],
                           ['key' => 'dwell', 'label' => 'Secs/story', 'cast' => 'int']]],
            ['key' => 'DEFAULT_STORIES', 'label' => 'Default stories per cycle', 'type' => 'number'],
            ['key' => 'DEFAULT_DWELL', 'label' => 'Default seconds per story', 'type' => 'number'],
            ['key' => 'SYNOPSIS_CHARS', 'label' => 'Synopsis length (chars)', 'type' => 'number'],
            $tz, $ttl(),
        ]],
        'video' => ['title' => 'Video Board', 'file' => 'video.php', 'fields' => [
            ['key' => 'VIDEOS', 'label' => 'Videos', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?v='],
                           ['key' => 'title', 'label' => 'Title (blank to hide)'],
                           ['key' => 'youtube', 'label' => 'YouTube URL', 'wide' => true],
                           ['key' => 'file', 'label' => 'or local file']],
             'help' => 'After changing URLs run: php video.php fetch'],
            ['key' => 'VIDEO_DIR', 'label' => 'Video directory', 'type' => 'text'],
            ['key' => 'MUTED', 'label' => 'Muted', 'type' => 'bool', 'default' => true],
            ['key' => 'FIT', 'label' => 'Fit', 'type' => 'select', 'options' => ['cover', 'contain']],
            ['key' => 'SHOW_CLOCK', 'label' => 'Show clock', 'type' => 'bool', 'default' => true],
            ['key' => 'MAX_HEIGHT', 'label' => 'Max download height', 'type' => 'number'],
            $tz,
        ]],
        'grafana' => ['title' => 'Grafana', 'file' => 'grafana.php', 'fields' => [
            ['key' => 'DASHBOARDS', 'label' => 'Dashboards', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?d='],
                           ['key' => 'title', 'label' => 'Title'],
                           ['key' => 'url', 'label' => 'Dashboard URL', 'wide' => true],
                           ['key' => 'refresh', 'label' => 'Refresh', 'placeholder' => '30s'],
                           ['key' => 'params', 'label' => 'Extra params']]],
            ['key' => 'GRAFANA_THEME', 'label' => 'Theme', 'type' => 'select', 'options' => ['dark', 'light']],
            $tz,
        ]],
        'splunk' => ['title' => 'Splunk Panels', 'file' => 'splunk.php', 'fields' => [
            ['key' => 'SPLUNK_BASE', 'label' => 'Splunk management URL', 'type' => 'text', 'help' => 'Port 8089, not Splunk Web'],
            ['key' => 'SPLUNK_TOKEN', 'label' => 'Auth token', 'type' => 'password'],
            ['key' => 'SPLUNK_VERIFY_TLS', 'label' => 'Verify TLS', 'type' => 'bool', 'default' => false],
            ['key' => 'BOARD_TITLE', 'label' => 'Board title', 'type' => 'text'],
            ['key' => 'BOARD_SUB', 'label' => 'Board subtitle', 'type' => 'text'],
            ['key' => 'PANELS', 'label' => 'Panels (JSON)', 'type' => 'json',
             'help' => 'List of panels. Types: single (field), list (label/value), trend (value). '
                     . 'Optional: earliest, latest, unit, wide. See README for examples.'],
            $tz, $ttl(),
        ]],
        'splunkdash' => ['title' => 'Splunk Published', 'file' => 'splunkdash.php', 'fields' => [
            ['key' => 'DASHBOARDS', 'label' => 'Published dashboards', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?d='],
                           ['key' => 'title', 'label' => 'Title'],
                           ['key' => 'url', 'label' => 'Published URL', 'wide' => true],
                           ['key' => 'reload', 'label' => 'Reload (s)', 'cast' => 'int']]],
            ['key' => 'DEFAULT_RELOAD', 'label' => 'Default iframe reload (s)', 'type' => 'number', 'help' => '0 disables'],
            $tz,
        ]],
        'ticker' => ['title' => 'Alert Ticker', 'file' => 'ticker.php', 'fields' => [
            ['key' => 'TICKER_LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'TICKER_LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'TICKER_UA', 'label' => 'NWS User-Agent', 'type' => 'text'],
            ['key' => 'TICKER_TTL', 'label' => 'NWS poll interval (s)', 'type' => 'number'],
            ['key' => 'TICKER_MODE', 'label' => 'Mode', 'type' => 'select', 'options' => ['scroll', 'static']],
            ['key' => 'TICKER_MIN_SEVERITY', 'label' => 'Minimum severity', 'type' => 'select',
             'options' => ['Minor', 'Moderate', 'Severe']],
            ['key' => 'TICKER_DEMO', 'label' => 'Demo mode (sample alerts)', 'type' => 'bool', 'default' => false,
             'help' => 'Preview the ticker layout — turn off for production'],
        ]],
    ];
}
