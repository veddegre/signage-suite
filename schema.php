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

require_once __DIR__ . '/config.php';

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
             ['key' => 'weight', 'label' => 'Weight', 'cast' => 'int'],
             ['key' => 'off', 'label' => 'Skip', 'type' => 'check']];
    $out = [];
    foreach ($screens as $key => $scr) {
        $name = is_array($scr) ? ($scr['name'] ?? $key) : $scr;   // tolerate the older key=>name shape
        $out[] = ['key' => 'PAGES_' . $key, 'label' => 'Pages — ' . $name . ' (?screen=' . $key . ')',
                  'type' => 'rows', 'columns' => $cols,
                  'help' => $key === 'main'
                      ? 'Edited on this page as a drag-and-drop playlist. Relative board URLs in display order.'
                      : 'Leave empty to mirror the main screen.'];
    }
    return $out;
}

function admin_schema(): array
{
    $tz  = ['key' => 'TIMEZONE', 'label' => 'Timezone', 'type' => 'text', 'help' => 'PHP timezone, e.g. America/Detroit'];
    $ttl = fn($h = '') => ['key' => 'CACHE_TTL', 'label' => 'Cache TTL (seconds)', 'type' => 'number', 'help' => $h ?: 'How long API responses are cached'];

    return [
        'security' => ['title' => 'Security', 'fields' => [
            ['key' => 'ALLOW_PRIVATE_FETCH', 'label' => 'Allow private URL fetches', 'type' => 'bool', 'default' => false,
             'help' => 'Lets RSS/ICS boards fetch http(s) URLs on private LAN IPs. Leave off on public servers.'],
            ['key' => 'ADMIN_IDLE_MINUTES', 'label' => 'Admin idle timeout (minutes)', 'type' => 'number', 'default' => 480,
             'help' => 'Auto-logout after inactivity (minimum 15). Default 480 = 8 hours.'],
            ['key' => 'SSO_ENABLED', 'label' => 'Enable SSO login', 'type' => 'bool', 'default' => false,
             'help' => 'OpenID Connect sign-in for admin. Local login can remain available below.'],
            ['key' => 'SSO_PROVIDER', 'label' => 'SSO provider', 'type' => 'select',
             'options' => ['generic', 'entra', 'authentik'], 'default' => 'generic',
             'help' => 'Preset for setup hints — both Entra and Authentik use standard OIDC under the hood.'],
            ['key' => 'SSO_ISSUER_URL', 'label' => 'OIDC issuer URL', 'type' => 'text',
             'help' => 'Entra: https://login.microsoftonline.com/<tenant-id>/v2.0 · Authentik: provider issuer URL (match trailing slash exactly).'],
            ['key' => 'OIDC_CLIENT_ID', 'label' => 'OIDC client ID', 'type' => 'text'],
            ['key' => 'OIDC_CLIENT_SECRET', 'label' => 'OIDC client secret', 'type' => 'password'],
            ['key' => 'OIDC_SCOPES', 'label' => 'OIDC scopes', 'type' => 'text', 'default' => 'openid profile email',
             'help' => 'Space-separated. Default works for Entra and Authentik.'],
            ['key' => 'SSO_USERNAME_CLAIM', 'label' => 'Username claim', 'type' => 'text', 'default' => 'preferred_username',
             'help' => 'JWT claim matched to admin username. Falls back to email / upn (Entra).'],
            ['key' => 'SSO_AUTO_LINK_EMAIL', 'label' => 'Auto-link by email', 'type' => 'bool', 'default' => true,
             'help' => 'On first SSO sign-in, match existing users when the email local-part equals their username.'],
            ['key' => 'SSO_ALLOW_LOCAL_FALLBACK', 'label' => 'Allow local password login', 'type' => 'bool', 'default' => true,
             'help' => 'When SSO is enabled, still show username/password on the login page.'],
            ['key' => 'SSO_JIT_ENABLED', 'label' => 'SSO just-in-time provisioning', 'type' => 'bool', 'default' => false,
             'help' => 'Create operator accounts automatically on first SSO sign-in (no pre-registration). Never creates super admins.'],
            ['key' => 'SSO_JIT_ALLOWED_DOMAINS', 'label' => 'JIT allowed email domains', 'type' => 'text',
             'help' => 'Comma-separated domains (e.g. contoso.com). Blank = any domain. Requires email claim in token.'],
            ['key' => 'SSO_JIT_REQUIRE_GROUPS', 'label' => 'JIT required groups/roles', 'type' => 'text',
             'help' => 'Comma-separated; user must have any listed group or app role in the token (Entra: add groups/roles to token). Blank = no group filter.'],
            ['key' => 'AUDIT_ENABLED', 'label' => 'Enable audit log', 'type' => 'bool', 'default' => true,
             'help' => 'Record admin logins, saves, and user changes. Super admins view under Audit.'],
            ['key' => 'AUDIT_MAX_ENTRIES', 'label' => 'Audit log max entries', 'type' => 'number', 'default' => 2000,
             'help' => 'Oldest entries are dropped when the log exceeds this size (100–20000).'],
        ]],
        'rotation' => ['title' => 'Rotation', 'file' => 'board.php', 'fields' => array_merge([
            ['key' => 'SCREENS', 'label' => 'Screens', 'type' => 'rows', 'keyed' => true,
             'columns' => [['key' => '_key', 'label' => 'Key', 'help' => 'used in ?screen='],
                           ['key' => 'name', 'label' => 'Display name'],
                           ['key' => 'show_ticker', 'label' => 'Weather ticker', 'type' => 'check'],
                           ['key' => 'show_clock', 'label' => 'Clock', 'type' => 'check'],
                           ['key' => 'show_debug', 'label' => 'Debug', 'type' => 'check'],
                           ['key' => 'fade_ms', 'label' => 'Crossfade (ms)'],
                           ['key' => 'settle_ms', 'label' => 'Settle (ms)'],
                           ['key' => 'hang_ms', 'label' => 'Hang (ms)'],
                           ['key' => 'weighted', 'label' => 'Weighted', 'type' => 'check'],
                           ['key' => 'shuffle', 'label' => 'Shuffle', 'type' => 'check'],
                           ['key' => 'schedule_enabled', 'label' => 'Blank', 'type' => 'check'],
                           ['key' => 'cec_off', 'label' => 'Off hr'],
                           ['key' => 'cec_on', 'label' => 'On hr'],
                           ['key' => 'cec_enabled', 'label' => 'CEC', 'type' => 'check']],
             'help' => 'One row per physical display. Each kiosk points at board.php?screen=<key> '
                     . '(plain board.php = the "main" screen). Save after adding a screen and its '
                     . 'page list appears below. A screen with no pages of its own falls back to main. '
                     . 'Blank shows a dark screen during Off→On hours (rotation timezone). CEC is optional HDMI standby on Pi kiosks.'],
        ], rotation_page_fields(), [
            $tz,
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
        'webcam' => ['title' => 'Webcam', 'file' => 'webcam.php', 'fields' => [
            ['key' => 'TITLE', 'label' => 'Overlay title', 'type' => 'text'],
            ['key' => 'EMBED_URL', 'label' => 'Embed URL', 'type' => 'text',
             'help' => 'iframe src URL — for Surf Grand Haven use the EarthCam share link, not surfgrandhaven.com'],
            ['key' => 'ATTRIBUTION', 'label' => 'Attribution line', 'type' => 'text',
             'help' => 'Small credit bottom-right; leave blank to hide'],
            ['key' => 'SHOW_OVERLAY', 'label' => 'Show title + clock overlay', 'type' => 'bool', 'default' => true],
            ['key' => 'RELOAD_SEC', 'label' => 'Iframe reload (seconds)', 'type' => 'number',
             'help' => 'Backstop against stalled streams in long kiosk sessions — 0 disables (default 3600)'],
            $tz,
        ]],
        'photo' => ['title' => 'Photo Conditions', 'file' => 'photo.php', 'fields' => [
            ['key' => 'OWM_API_KEY', 'label' => 'OpenWeatherMap API key', 'type' => 'password'],
            ['key' => 'PLACE', 'label' => 'Place name', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            $tz, $ttl(),
        ]],
        'air' => ['title' => 'Air & Pollen', 'file' => 'air.php', 'fields' => [
            ['key' => 'TITLE', 'label' => 'Board title', 'type' => 'text'],
            ['key' => 'PLACE', 'label' => 'Place name', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Latitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'LON', 'label' => 'Longitude', 'type' => 'number', 'step' => 'any'],
            ['key' => 'GOOGLE_POLLEN_API_KEY', 'label' => 'Google Pollen API key', 'type' => 'password',
             'help' => 'Required for US pollen — Open-Meteo pollen is Europe-only. Enable Pollen API in Google Cloud; free tier is 5,000 calls/month.'],
            ['key' => 'RELOAD_SEC', 'label' => 'Page reload (seconds)', 'type' => 'number',
             'help' => 'Direct view only — rotation iframe reloads with the shell'],
            $tz, $ttl('Open-Meteo + Google Pollen responses — default 900s (15 min)'),
        ]],
        'sports' => ['title' => 'Detroit Sports', 'file' => 'sports.php', 'fields' => [
            ['key' => 'TITLE', 'label' => 'Board title', 'type' => 'text'],
            ['key' => 'SUBTITLE', 'label' => 'Subtitle', 'type' => 'text',
             'help' => 'Shown beside the title — default lists all four teams'],
            ['key' => 'RELOAD_SEC', 'label' => 'Live-game reload (seconds)', 'type' => 'number',
             'help' => 'Direct view only — auto-refresh while a game is live (rotation reloads with the shell)'],
            $tz, $ttl('ESPN responses — default 300s; live games refresh sooner'),
        ]],
        'signaltrace' => ['title' => 'SignalTrace', 'file' => 'signaltrace.php', 'fields' => [
            ['key' => 'ST_BASE_URL', 'label' => 'SignalTrace base URL', 'type' => 'text'],
            ['key' => 'ST_EXPORT_TOKEN', 'label' => 'Export API token', 'type' => 'password',
             'help' => 'EXPORT_API_TOKEN from includes/config.local.php — used server-side only'],
            ['key' => 'WINDOW_HOURS', 'label' => 'Window (hours)', 'type' => 'number'],
            ['key' => 'IGNORE_IPS', 'label' => 'Ignore IPs in feed', 'type' => 'text',
             'help' => 'Comma-separated — hide your signage/homelab monitor IP from Recent Activity (e.g. 192.168.1.50)'],
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
             'columns' => [
                 ['key' => '_key', 'label' => 'Name'],
                 ['key' => '_value', 'label' => 'URL'],
             ],
             'help' => 'HTTP(S) endpoints to ping for up/down and response time. Leave empty for none. '
                     . 'Do not use SignalTrace honeypot/token URLs — checks register as hits. '
                     . 'User-Agent is HomeSignage/ServiceCheck/1.0 (add a skip pattern in SignalTrace if needed).'],
            ['key' => 'LATENCY_TARGET', 'label' => 'WAN latency target', 'type' => 'text'],
            $tz, $ttl(),
        ]],
        'rotator' => ['title' => 'Photo Rotator', 'file' => 'rotator.php', 'fields' => [
            ['key' => 'PHOTO_DIR', 'label' => 'Photo directory', 'type' => 'text',
             'help' => 'Default ./photos — must be writable by the web server. Upload in admin or copy files manually.'],
            ['key' => 'BRAND', 'label' => 'Brand wordmark', 'type' => 'text'],
            ['key' => 'DEFAULT_DWELL', 'label' => 'Default seconds per photo', 'type' => 'number'],
            ['key' => 'INTERVAL_SEC', 'label' => 'Seconds per photo (slideshow)', 'type' => 'number',
             'help' => 'Timing inside combined rotator.php and group pages'],
            ['key' => 'DEPLOY_MODE', 'label' => 'Rotation deploy mode', 'type' => 'select',
             'options' => ['individual', 'groups', 'legacy'], 'default' => 'individual',
             'help' => 'individual = one playlist entry per photo; groups = bundle by group name; legacy = single rotator.php'],
            ['key' => 'SHUFFLE', 'label' => 'Shuffle order', 'type' => 'bool', 'default' => true],
            ['key' => 'SHOW_EXIF', 'label' => 'Show EXIF caption', 'type' => 'bool', 'default' => true],
            ['key' => 'SHOW_CLOCK', 'label' => 'Show clock overlay', 'type' => 'bool', 'default' => true],
            ['key' => 'PHOTOS', 'label' => 'Photo deck', 'type' => 'rows',
             'columns' => [
                 ['key' => 'file', 'label' => 'File', 'wide' => true, 'placeholder' => 'name.jpg'],
                 ['key' => 'caption', 'label' => 'Label', 'wide' => true],
                 ['key' => 'dwell', 'label' => 'Dwell (s)', 'cast' => 'int'],
                 ['key' => 'group', 'label' => 'Group', 'placeholder' => 'travel'],
             ]],
            $tz,
        ]],
        'slides' => ['title' => 'Custom Slides', 'file' => 'slides.php', 'fields' => [
            ['key' => 'SLIDE_DIR', 'label' => 'Slide directory', 'type' => 'text',
             'help' => 'Default ./slides — must be writable by the web server'],
            ['key' => 'DEFAULT_DWELL', 'label' => 'Default seconds per slide', 'type' => 'number'],
            ['key' => 'SHUFFLE', 'label' => 'Shuffle active slides', 'type' => 'bool', 'default' => false],
            ['key' => 'FIT', 'label' => 'Image fit', 'type' => 'select', 'options' => ['contain', 'cover']],
            ['key' => 'SHOW_CLOCK', 'label' => 'Show clock overlay', 'type' => 'bool', 'default' => true,
             'help' => 'Fixed top-right on each slide; uses the timezone below'],
            ['key' => 'SLIDES', 'label' => 'Slide deck', 'type' => 'rows',
             'columns' => [
                 ['key' => 'file', 'label' => 'File', 'wide' => true, 'placeholder' => 'name.jpg'],
                 ['key' => 'caption', 'label' => 'Caption', 'wide' => true],
                 ['key' => 'dwell', 'label' => 'Dwell (s)', 'cast' => 'int'],
                 ['key' => 'schedule', 'label' => 'Schedule', 'type' => 'select',
                  'options' => ['always', 'once', 'range', 'yearly', 'yearly_range', 'monthly', 'weekly']],
                 ['key' => 'date_start', 'label' => 'From', 'placeholder' => 'YYYY-MM-DD or once'],
                 ['key' => 'date_end', 'label' => 'To', 'placeholder' => 'YYYY-MM-DD'],
                 ['key' => 'month_day', 'label' => 'MM-DD', 'placeholder' => 'MM-DD start'],
                 ['key' => 'month_day_end', 'label' => 'MM-DD end', 'placeholder' => 'yearly range'],
                 ['key' => 'day_of_month', 'label' => 'Day', 'placeholder' => '1-31', 'cast' => 'int'],
                 ['key' => 'weekday', 'label' => 'Weekday', 'type' => 'select',
                  'options' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']],
                 ['key' => 'weekdays', 'label' => 'Days', 'placeholder' => 'Mon,Wed,Fri'],
                 ['key' => 'hour_from', 'label' => 'Hr from', 'placeholder' => '0-23', 'cast' => 'int'],
                 ['key' => 'hour_to', 'label' => 'Hr to', 'placeholder' => '0-23', 'cast' => 'int'],
                 ['key' => 'priority', 'label' => 'Priority', 'type' => 'check'],
                 ['key' => 'off', 'label' => 'Off', 'type' => 'check'],
             ],
             'help' => 'Use cards below — schedule fields appear based on type.'],
            $tz,
        ]],
        'family' => ['title' => 'Family Board', 'file' => 'family.php', 'fields' => [
            ['key' => 'ICS_FEEDS', 'label' => 'Calendar feeds', 'type' => 'rows',
             'columns' => [
                 ['key' => 'key', 'label' => 'Key', 'placeholder' => 'Dad'],
                 ['key' => 'color', 'label' => 'Color', 'type' => 'palette'],
                 ['key' => 'source', 'label' => 'Source', 'type' => 'select',
                  'options' => ['ical', 'webdav']],
                 ['key' => 'url', 'label' => 'URL', 'wide' => true],
                 ['key' => 'user', 'label' => 'User', 'placeholder' => 'email for CalDAV'],
                 ['key' => 'password', 'label' => 'Password', 'type' => 'password'],
             ],
             'help' => 'Key is the legend label on the wall (e.g. Dad, Mom). Pick a theme color per feed. '
                     . 'ical: secret iCal URL. webdav: CalDAV (Fastmail, Nextcloud, …) with app password. '
                     . 'LAN/private hosts need Security → Allow private URL fetches.'],
            ['key' => 'TRASH_WEEKDAY', 'label' => 'Trash day', 'type' => 'select',
             'options' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
             'help' => 'Leave as (default) to hide — e.g. apartment living'],
            ['key' => 'RECYCLE_ANCHOR', 'label' => 'Recycle anchor date', 'type' => 'text',
             'help' => 'Only used when trash day is set. Any past pickup date (YYYY-MM-DD); empty disables biweekly recycle'],
            ['key' => 'COUNTDOWNS', 'label' => 'Countdowns', 'type' => 'rows', 'keyed' => true, 'scalar' => true,
             'columns' => [
                 ['key' => '_key', 'label' => 'Label'],
                 ['key' => '_value', 'label' => 'Date (YYYY-MM-DD)'],
             ]],
            $tz, $ttl(),
        ]],
        'rss' => ['title' => 'RSS Stories', 'file' => 'rss.php', 'fields' => [
            ['key' => 'FEEDS', 'label' => 'Feeds', 'type' => 'rows', 'keyed' => true,
             'columns' => [
                 ['key' => '_key', 'label' => 'Key', 'help' => 'used in ?feed='],
                 ['key' => 'name', 'label' => 'Display name'],
                 ['key' => 'url', 'label' => 'Feed URL', 'wide' => true],
                 ['key' => 'stories', 'label' => '# stories', 'cast' => 'int'],
                 ['key' => 'dwell', 'label' => 'Secs/story', 'cast' => 'int'],
             ]],
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
             'help' => 'Managed on this page via the drag-and-drop playlist below. Each video needs a unique key. '
                     . 'Use Admin → Rotation to order all boards on the wall, or check “Add playlist to main rotation” when saving.'],
            ['key' => 'VIDEO_DIR', 'label' => 'Video directory', 'type' => 'text'],
            ['key' => 'MUTED', 'label' => 'Mute all videos', 'type' => 'bool', 'default' => true,
             'help' => 'Uncheck to play sound. Kiosks need setup-kiosk.sh (includes autoplay-policy for unmuted video).'],
            ['key' => 'FIT', 'label' => 'Fit', 'type' => 'select', 'options' => ['cover', 'contain']],
            ['key' => 'SHOW_CLOCK', 'label' => 'Show clock', 'type' => 'bool', 'default' => true],
            ['key' => 'MAX_HEIGHT', 'label' => 'Max download height', 'type' => 'number'],
            ['key' => 'YTDLP_COOKIES_FILE', 'label' => 'YouTube cookies file', 'type' => 'text',
             'help' => 'Netscape cookies.txt path (default config/cookies/youtube.txt). Export from a logged-in browser — see README.'],
            ['key' => 'YTDLP_JS_RUNTIME', 'label' => 'JS runtime', 'type' => 'select',
             'options' => ['auto', 'deno', 'node', 'none'], 'default' => 'auto',
             'help' => 'YouTube needs a JS runtime (deno recommended; setup-server.sh installs it)'],
            $tz,
        ]],
        'grafana' => ['title' => 'Grafana', 'file' => 'grafana.php', 'fields' => [
            ['key' => 'DASHBOARDS', 'label' => 'Dashboards', 'type' => 'rows', 'keyed' => true,
             'columns' => [
                 ['key' => '_key', 'label' => 'Key', 'help' => 'used in ?d='],
                 ['key' => 'title', 'label' => 'Title'],
                 ['key' => 'url', 'label' => 'Dashboard URL', 'wide' => true],
                 ['key' => 'refresh', 'label' => 'Refresh', 'placeholder' => '30s'],
                 ['key' => 'params', 'label' => 'Extra params'],
             ]],
            ['key' => 'GRAFANA_THEME', 'label' => 'Theme', 'type' => 'select', 'options' => ['dark', 'light']],
            $tz,
        ]],
        'splunk' => ['title' => 'Splunk Panels', 'file' => 'splunk.php', 'fields' => [
            ['key' => 'SPLUNK_BASE', 'label' => 'Splunk management URL', 'type' => 'text', 'help' => 'Port 8089, not Splunk Web'],
            ['key' => 'SPLUNK_TOKEN', 'label' => 'Auth token', 'type' => 'password'],
            ['key' => 'SPLUNK_VERIFY_TLS', 'label' => 'Verify TLS', 'type' => 'bool', 'default' => false],
            ['key' => 'BOARD_TITLE', 'label' => 'Default page title', 'type' => 'text', 'help' => 'Used for the main page when no per-page title is set'],
            ['key' => 'BOARD_SUB', 'label' => 'Default page subtitle', 'type' => 'text'],
            $tz, $ttl(),
        ]],
        'splunkdash' => ['title' => 'Splunk Published', 'file' => 'splunkdash.php', 'fields' => [
            ['key' => 'DASHBOARDS', 'label' => 'Published dashboards', 'type' => 'rows', 'keyed' => true,
             'columns' => [
                 ['key' => '_key', 'label' => 'Key', 'help' => 'used in ?d='],
                 ['key' => 'title', 'label' => 'Title'],
                 ['key' => 'url', 'label' => 'Published URL', 'wide' => true],
                 ['key' => 'reload', 'label' => 'Reload (s)', 'cast' => 'int'],
             ]],
            ['key' => 'DEFAULT_RELOAD', 'label' => 'Default iframe reload (s)', 'type' => 'number', 'help' => '0 disables'],
            $tz,
        ]],
        'web' => ['title' => 'Websites', 'file' => 'web.php', 'fields' => [
            ['key' => 'SITES', 'label' => 'Sites', 'type' => 'rows', 'keyed' => true,
             'columns' => [
                 ['key' => '_key', 'label' => 'Key', 'help' => 'used in ?d='],
                 ['key' => 'title', 'label' => 'Title'],
                 ['key' => 'url', 'label' => 'URL', 'wide' => true, 'help' => 'https://… — must allow iframe embed'],
                 ['key' => 'reload', 'label' => 'Reload (s)', 'cast' => 'int', 'help' => '0 = no iframe refresh backstop'],
             ]],
            ['key' => 'DEFAULT_RELOAD', 'label' => 'Default reload (s)', 'type' => 'number', 'help' => '0 disables; used when a site leaves reload blank'],
            ['key' => 'SHOW_CLOCK', 'label' => 'Show clock overlay', 'type' => 'bool', 'default' => true,
             'help' => 'Fixed top-right over the iframe; uses the timezone below'],
            $tz,
        ]],
        'traffic' => ['title' => 'Traffic Map', 'file' => 'traffic.php', 'fields' => [
            ['key' => 'TOMTOM_API_KEY', 'label' => 'TomTom API key', 'type' => 'password',
             'help' => 'Enable Traffic Flow API on the key; no domain whitelist (server-side tiles)'],
            ['key' => 'TOMTOM_API', 'label' => 'Tile API', 'type' => 'select',
             'options' => ['auto', 'legacy', 'orbis'], 'default' => 'auto',
             'help' => 'Auto tries Orbis (new keys) then legacy v4. Use legacy only for older TomTom accounts.'],
            ['key' => 'TITLE', 'label' => 'Board title', 'type' => 'text'],
            ['key' => 'SUBTITLE', 'label' => 'Subtitle', 'type' => 'text'],
            ['key' => 'LAT', 'label' => 'Map center latitude', 'type' => 'number', 'step' => 'any',
             'help' => 'Default frames I-96 with weight toward Grand Rapids'],
            ['key' => 'LON', 'label' => 'Map center longitude', 'type' => 'number', 'step' => 'any',
             'help' => 'More negative = west; default -85.78 centers east of midpoint (GR side)'],
            ['key' => 'ZOOM', 'label' => 'Zoom level', 'type' => 'number', 'help' => '11 = full corridor; 12 = tighter on GR'],
            ['key' => 'FLOW_STYLE', 'label' => 'Flow style', 'type' => 'select',
             'options' => ['relative0-dark', 'relative0', 'relative', 'absolute'],
             'help' => 'relative0-dark matches the dark basemap best'],
            ['key' => 'RELOAD_SEC', 'label' => 'Refresh interval (seconds)', 'type' => 'number'],
            $tz,
        ]],
        'ticker' => ['title' => 'Alert Ticker', 'file' => 'ticker.php', 'fields' => [
            ['key' => 'TICKER_ENABLED', 'label' => 'Enable weather alert ticker', 'type' => 'bool', 'default' => true,
             'help' => 'Master switch — off hides the ticker everywhere. Per-display on/off is under Rotation → Displays'],
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
