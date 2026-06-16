<?php
/**
 * Video board — shared helpers for video.php and admin fetch / yt-dlp upkeep.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

function video_dir(): string
{
    $d = cfg('video.VIDEO_DIR', __DIR__ . '/videos');
    if ($d === '' || $d === './videos' || $d === 'videos') {
        return __DIR__ . '/videos';
    }
    if ($d[0] !== '/') {
        return __DIR__ . '/' . trim($d, '/');
    }
    return $d;
}

function video_registry(): array
{
    return cfg('video.VIDEOS', [
        'drone'   => ['title' => 'Grand Haven Drone Reel', 'youtube' => 'https://www.youtube.com/watch?v=REPLACE_ME'],
        'ambient' => ['title' => '', 'youtube' => 'https://www.youtube.com/watch?v=REPLACE_ME_TOO'],
    ]);
}

function video_registry_for_display(): array
{
    require_once __DIR__ . '/users_lib.php';

    return admin_filter_registry_for_display(video_registry());
}

function video_max_height(): int
{
    $h = (int)cfg('video.MAX_HEIGHT', 1080);
    return $h > 0 ? $h : 1080;
}

function video_path(string $key, array $v, ?string $dir = null): ?string
{
    $dir = $dir ?? video_dir();
    if (isset($v['file'])) {
        $p = $dir . '/' . basename($v['file']);
        return is_file($p) ? $p : null;
    }
    foreach (['mp4', 'webm', 'mkv'] as $ext) {
        $p = "$dir/$key.$ext";
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

function video_duration(string $path): ?float
{
    $out = @shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($path) . ' 2>/dev/null');
    $d = is_string($out) ? (float)trim($out) : 0;
    return $d > 0 ? $d : null;
}

/** Max local video upload size enforced by admin (bytes). Matches setup-server.sh upload_max_filesize. */
function video_upload_max_bytes(): int
{
    return 20 * 1024 * 1024;
}

function video_upload_max_label(): string
{
    return '20 MB';
}

function video_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Video exceeds PHP upload_max_filesize ('
            . ini_get('upload_max_filesize') . '). Videos allow up to '
            . video_upload_max_label() . ' — raise upload_max_filesize and post_max_size on the server.',
        UPLOAD_ERR_FORM_SIZE => 'Video exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'Upload was interrupted — try again.',
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory missing — check PHP upload_tmp_dir.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the upload — check disk permissions.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
        default => 'Upload failed (error ' . $code . ') — try again.',
    };
}

function video_upload_extension(string $tmpPath, string $origName): ?string
{
    $extMap = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/x-matroska' => 'mkv',
    ];
    if ($tmpPath !== '' && is_file($tmpPath)) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        if (isset($extMap[$mime])) {
            return $extMap[$mime];
        }
        if ($mime === 'application/octet-stream') {
            $guess = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($guess, ['mp4', 'webm', 'mkv'], true)) {
                return $guess;
            }
        }
    }

    return match (strtolower(pathinfo($origName, PATHINFO_EXTENSION))) {
        'mp4' => 'mp4',
        'webm' => 'webm',
        'mkv' => 'mkv',
        default => null,
    };
}

function video_unique_filename(string $base, string $ext, ?string $dir = null): string
{
    $dir = $dir ?? video_dir();
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base);
    $base = trim($base, '-._');
    if ($base === '') {
        $base = 'video';
    }
    $name = $base . '.' . $ext;
    if (!is_file($dir . '/' . $name)) {
        return $name;
    }

    return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
}

/**
 * Save one uploaded video file into videos/.
 * @return array{ok:bool,name?:string,error?:string}
 */
function video_save_upload(array $file, ?string $dir = null, ?string $preferBase = null): array
{
    $dir = $dir ?? video_dir();
    $max = video_upload_max_bytes();
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => video_upload_error_message($err)];
    }
    if (($file['size'] ?? 0) > $max) {
        return ['ok' => false, 'error' => 'Video must be under ' . video_upload_max_label() . '.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Could not create ' . $dir . ' — check permissions.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $orig = (string)($file['name'] ?? 'video.mp4');
    $ext = video_upload_extension($tmp, $orig);
    if ($ext === null) {
        return ['ok' => false, 'error' => 'Only MP4, WebM, or MKV videos are allowed.'];
    }
    $base = $preferBase !== null && $preferBase !== ''
        ? $preferBase
        : pathinfo($orig, PATHINFO_FILENAME);
    $name = video_unique_filename((string)$base, $ext, $dir);
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not write to ' . $dir . ' — check permissions.'];
    }

    return ['ok' => true, 'name' => $name];
}

/**
 * Register or update a playlist row for an uploaded file.
 * @return array{ok:bool,key?:string,error?:string}
 */
function video_register_upload(string $key, string $filename, array $extra = []): array
{
    require_once __DIR__ . '/users_lib.php';

    $safeKey = video_normalize_key($key);
    $filename = basename($filename);
    if ($safeKey === null || $filename === '' || !preg_match('/\.(mp4|webm|mkv)$/i', $filename)) {
        return ['ok' => false, 'error' => 'Invalid video key or filename.'];
    }

    $registry = video_registry();
    $prev = is_array($registry[$safeKey] ?? null) ? $registry[$safeKey] : null;
    if ($prev !== null && !admin_is_super() && !admin_entry_visible($prev)) {
        return ['ok' => false, 'error' => 'You do not have permission to update that video.'];
    }

    $oldFiles = $prev !== null ? video_entry_disk_files($safeKey, $prev) : [];

    $row = ['file' => $filename];
    $title = trim((string)($extra['title'] ?? ''));
    if ($title !== '') {
        $row['title'] = $title;
    }
    if ($prev !== null && isset($prev['youtube']) && trim((string)$prev['youtube']) !== '') {
        $row['youtube'] = (string)$prev['youtube'];
    }

    if (!cfg_update(function (array $conf) use ($safeKey, $row, $prev): array|false {
        $registry = $conf['video.VIDEOS'] ?? [];
        if (!is_array($registry)) {
            $registry = [];
        }
        $existing = is_array($registry[$safeKey] ?? null) ? $registry[$safeKey] : null;
        if ($existing !== null && !admin_is_super() && !admin_entry_visible($existing)) {
            return false;
        }
        $registry[$safeKey] = admin_stamp_owner($row, $existing ?? $prev);
        $conf['video.VIDEOS'] = $registry;

        return $conf;
    })) {
        return ['ok' => false, 'error' => 'Could not update settings.json.'];
    }
    cfg_reload();

    $registry = video_registry();
    foreach ($oldFiles as $basename) {
        if ($basename === $filename) {
            continue;
        }
        if (!video_registry_references_file($registry, $basename)) {
            $path = video_dir() . '/' . $basename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    return ['ok' => true, 'key' => $safeKey];
}

function video_ytdlp_local_bin_path(): string
{
    return __DIR__ . '/bin/yt-dlp';
}

function video_ytdlp_python3(): ?string
{
    $py = trim((string)@shell_exec('command -v python3 2>/dev/null'));
    return $py !== '' ? $py : null;
}

/** pip/pipx launchers are ~200 B; the GitHub release is a multi-MB standalone script. */
function video_ytdlp_local_bin_is_stub(): bool
{
    $local = video_ytdlp_local_bin_path();
    if (!is_file($local)) {
        return false;
    }
    $size = filesize($local);
    return $size !== false && $size < 32768;
}

/** Local bin/yt-dlp is a Python script; run via python3 (works on noexec webroots). */
function video_ytdlp_local_bin_usable(): bool
{
    $local = video_ytdlp_local_bin_path();
    if (!is_file($local) || !is_readable($local) || video_ytdlp_python3() === null) {
        return false;
    }
    return !video_ytdlp_local_bin_is_stub();
}

/** Shell prefix to invoke yt-dlp (direct exec, or python3 for local bin/). */
function video_ytdlp_shell(string $bin): string
{
    if ($bin === video_ytdlp_local_bin_path()) {
        $py = video_ytdlp_python3();
        if ($py !== null) {
            return escapeshellarg($py) . ' ' . escapeshellarg($bin);
        }
    }
    return escapeshellarg($bin);
}

/** @return list<string> */
function video_ytdlp_candidates(): array
{
    $out = [];
    $local = video_ytdlp_local_bin_path();
    if (is_file($local)) {
        $out[] = $local;
    }
    foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp'] as $p) {
        if (is_file($p)) {
            $out[] = $p;
        }
    }
    $which = trim((string)@shell_exec('command -v yt-dlp 2>/dev/null'));
    if ($which !== '') {
        $out[] = $which;
    }
    $unique = [];
    foreach ($out as $p) {
        if ($p !== '' && !in_array($p, $unique, true)) {
            $unique[] = $p;
        }
    }
    return $unique;
}

function video_ytdlp_bin(): ?string
{
    if (video_ytdlp_local_bin_usable()) {
        return video_ytdlp_local_bin_path();
    }
    foreach (video_ytdlp_candidates() as $p) {
        if ($p === video_ytdlp_local_bin_path()) {
            continue;
        }
        if (is_file($p) && is_executable($p)) {
            return $p;
        }
    }
    return null;
}

/** Configured cookies path (absolute), whether or not the file exists yet. */
function video_ytdlp_cookies_configured_path(): string
{
    $raw = trim((string)cfg('video.YTDLP_COOKIES_FILE', ''));
    if ($raw === '') {
        $raw = 'config/cookies/youtube.txt';
    }
    if ($raw[0] !== '/') {
        $raw = __DIR__ . '/' . ltrim($raw, '/');
    }
    return $raw;
}

/** Resolved path to optional Netscape-format YouTube cookies, or null if unset/missing. */
function video_ytdlp_cookies_path(): ?string
{
    $raw = video_ytdlp_cookies_configured_path();
    return is_file($raw) && is_readable($raw) ? $raw : null;
}

function video_ytdlp_cookies_upload_allowed(string $path): bool
{
    $base = realpath(__DIR__ . '/config/cookies');
    if ($base === false) {
        $base = __DIR__ . '/config/cookies';
    }
    $dir = dirname($path);
    $realDir = realpath($dir);
    if ($realDir !== false) {
        return str_starts_with($realDir, $base);
    }
    $normDir = str_replace('\\', '/', $dir);
    $normBase = str_replace('\\', '/', $base);
    return str_starts_with($normDir, $normBase);
}

/** @return string|null Error message, or null if content looks valid. */
function video_ytdlp_validate_cookies_content(string $content): ?string
{
    if ($content === '') {
        return 'File is empty.';
    }
    if (strlen($content) > 256 * 1024) {
        return 'File is too large (max 256 KB).';
    }
    if (str_contains($content, "\0")) {
        return 'Invalid file content.';
    }
    $hasCookieLine = false;
    $hasYoutube = false;
    foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue;
        }
        $hasCookieLine = true;
        if (str_contains($parts[0], 'youtube.com')) {
            $hasYoutube = true;
        }
    }
    if (!$hasCookieLine) {
        return 'Not a Netscape cookies.txt file (expected tab-separated lines).';
    }
    if (!$hasYoutube) {
        return 'No .youtube.com cookies found — export from youtube.com while signed in.';
    }
    return null;
}

/**
 * Save an uploaded Netscape cookies.txt for YouTube.
 * @param array<string,mixed> $file $_FILES entry
 * @return array{ok:bool,message:string,bytes:int}
 */
function video_ytdlp_save_cookies_upload(array $file): array
{
    $fail = static function (string $message): array {
        return ['ok' => false, 'message' => $message, 'bytes' => 0];
    };

    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return $fail('Choose a cookies.txt file to upload.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        return $fail('Upload failed (error ' . $err . ').');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $fail('Invalid upload.');
    }

    $target = video_ytdlp_cookies_configured_path();
    if (!video_ytdlp_cookies_upload_allowed($target)) {
        return $fail('Configured cookies path is outside config/cookies/ — upload blocked.');
    }

    $content = @file_get_contents($tmp);
    if (!is_string($content)) {
        return $fail('Could not read uploaded file.');
    }
    $validation = video_ytdlp_validate_cookies_content($content);
    if ($validation !== null) {
        return $fail($validation);
    }

    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return $fail('Could not create cookies directory.');
    }
    if (!is_writable($dir)) {
        return $fail('Cookies directory is not writable.');
    }

    $staging = $target . '.new';
    if (@file_put_contents($staging, $content, LOCK_EX) === false) {
        @unlink($staging);
        return $fail('Could not write cookies file.');
    }
    @chmod($staging, 0640);
    if (!@rename($staging, $target)) {
        @unlink($staging);
        return $fail('Could not replace cookies file.');
    }
    @chmod($target, 0640);

    $bytes = filesize($target);
    return [
        'ok' => true,
        'message' => 'Uploaded cookies to ' . basename($target)
            . ' (' . number_format($bytes !== false ? (int)$bytes : 0) . ' bytes).',
        'bytes' => $bytes !== false ? (int)$bytes : 0,
    ];
}

function video_deno_local_bin_path(): string
{
    return __DIR__ . '/bin/deno';
}

/** Find deno/node for www-data (Apache PATH often omits /usr/local/bin). */
function video_ytdlp_find_executable(string $name): ?string
{
    if ($name === 'deno') {
        $local = video_deno_local_bin_path();
        $localVer = is_executable($local) ? video_ytdlp_deno_version($local) : null;

        $systemPath = null;
        $systemVer = null;
        foreach (['/usr/local/bin/deno', '/usr/bin/deno'] as $p) {
            if (!is_executable($p)) {
                continue;
            }
            $v = video_ytdlp_deno_version($p);
            if ($v !== null) {
                $systemPath = $p;
                $systemVer = $v;
                break;
            }
        }

        // Prefer admin-managed bin/deno when it is at least as new as the system copy.
        if ($localVer !== null && video_ytdlp_deno_ok($local)) {
            if ($systemVer === null || version_compare($localVer, $systemVer, '>=')) {
                return $local;
            }
        }
        if ($systemPath !== null) {
            return $systemPath;
        }
        if ($localVer !== null) {
            return $local;
        }
        return null;
    }
    $which = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    if ($which !== '' && is_executable($which)) {
        return $which;
    }
    foreach (['/usr/local/bin/' . $name, '/usr/bin/' . $name] as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }
    return null;
}

/** @return array{name:string,path:string}|null */
function video_ytdlp_js_runtime(): ?array
{
    $runtime = (string)cfg('video.YTDLP_JS_RUNTIME', 'auto');
    if ($runtime === 'none') {
        return null;
    }
    $candidates = $runtime === 'auto' ? ['deno', 'node'] : [$runtime];
    foreach ($candidates as $name) {
        if (!in_array($name, ['deno', 'node'], true)) {
            continue;
        }
        $path = video_ytdlp_find_executable($name);
        if ($path !== null) {
            return ['name' => $name, 'path' => $path];
        }
    }
    return null;
}

/** Writable cache for yt-dlp remote components (EJS challenge scripts). */
function video_ytdlp_cache_dir(): ?string
{
    $dir = __DIR__ . '/cache/yt-dlp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    return is_writable($dir) ? $dir : null;
}

/** Minimum deno for yt-dlp EJS (https://github.com/yt-dlp/yt-dlp/wiki/EJS). */
function video_ytdlp_deno_min_version(): string
{
    return '2.3.0';
}

function video_ytdlp_deno_version(?string $path = null): ?string
{
    if ($path === null) {
        $path = video_ytdlp_find_executable('deno');
    }
    if ($path === null) {
        return null;
    }
    $out = trim((string)@shell_exec(escapeshellarg($path) . ' --version 2>/dev/null'));
    if ($out === '') {
        return null;
    }
    if (preg_match('/\b(\d+\.\d+\.\d+)\b/', $out, $m)) {
        return $m[1];
    }
    return $out;
}

function video_ytdlp_deno_ok(?string $path = null): bool
{
    $v = video_ytdlp_deno_version($path);
    return $v !== null && version_compare($v, video_ytdlp_deno_min_version(), '>=');
}

/** Env vars so deno/yt-dlp caches work when PHP runs as www-data. */
function video_ytdlp_exec_env(): string
{
    $cache = video_ytdlp_cache_dir();
    if ($cache === null) {
        return '';
    }
    $denoDir = $cache . '/deno';
    if (!is_dir($denoDir)) {
        @mkdir($denoDir, 0775, true);
    }
    $parts = [
        'DENO_DIR=' . escapeshellarg($denoDir),
        'XDG_CACHE_HOME=' . escapeshellarg($cache),
    ];
    return implode(' ', $parts) . ' ';
}

/** Extra yt-dlp CLI flags for YouTube (cookies + JS runtime). */
function video_ytdlp_extra_args(): string
{
    $args = '';
    $cookies = video_ytdlp_cookies_path();
    if ($cookies !== null) {
        $args .= ' --cookies ' . escapeshellarg($cookies);
    }
    $cache = video_ytdlp_cache_dir();
    if ($cache !== null) {
        $args .= ' --cache-dir ' . escapeshellarg($cache);
    }
    $rt = video_ytdlp_js_runtime();
    if ($rt !== null) {
        $args .= ' --js-runtimes ' . escapeshellarg($rt['name'] . ':' . $rt['path']);
        // YouTube n/signature challenges (2025+).
        $args .= ' --remote-components ejs:github';
        if ($rt['name'] === 'deno') {
            // Fallback if GitHub EJS assets are unreachable from the server network.
            $args .= ' --remote-components ejs:npm';
        }
    }
    return $args;
}

/** @return array{deno:bool,deno_ok:bool,deno_version:?string,node:bool,deno_path:?string,node_path:?string,cookies:bool,cookies_bytes:int,cookies_path:?string} */
function video_ytdlp_support_status(): array
{
    $configured = trim((string)cfg('video.YTDLP_COOKIES_FILE', ''));
    if ($configured === '') {
        $configured = 'config/cookies/youtube.txt';
    }
    $denoPath = video_ytdlp_find_executable('deno');
    $nodePath = video_ytdlp_find_executable('node');
    $cookiesPath = video_ytdlp_cookies_path();
    $cookiesBytes = 0;
    if ($cookiesPath !== null) {
        $sz = filesize($cookiesPath);
        $cookiesBytes = $sz !== false ? (int)$sz : 0;
    }
    return [
        'deno' => $denoPath !== null,
        'deno_ok' => video_ytdlp_deno_ok($denoPath),
        'deno_version' => video_ytdlp_deno_version($denoPath),
        'node' => $nodePath !== null,
        'deno_path' => $denoPath,
        'node_path' => $nodePath,
        'cookies' => $cookiesPath !== null,
        'cookies_bytes' => $cookiesBytes,
        'cookies_path' => $cookiesPath ?? $configured,
    ];
}

function video_ytdlp_version(?string $bin = null): ?string
{
    $bin = $bin ?? video_ytdlp_bin();
    if ($bin === null) {
        return null;
    }
    $out = @shell_exec(video_ytdlp_shell($bin) . ' --version 2>/dev/null');
    $v = is_string($out) ? trim($out) : '';
    return $v !== '' ? $v : null;
}

function video_github_asset_url_allowed(string $url): bool
{
    return str_starts_with($url, 'https://github.com/yt-dlp/yt-dlp/releases/download/')
        || str_starts_with($url, 'https://github.com/denoland/deno/releases/download/');
}

function video_github_release_url_allowed(string $url): bool
{
    return video_github_asset_url_allowed($url);
}

/**
 * Fetch a pinned GitHub release asset (follows redirects to githubusercontent.com).
 * @return array{data:?string,http:int,error:?string}
 */
function video_http_get_github_release(string $url, int $timeout = 120): array
{
    if (!video_github_asset_url_allowed($url)) {
        return ['data' => null, 'http' => 0, 'error' => 'untrusted URL'];
    }
    $policy = signage_fetch_url_allowed($url, false);
    if (!$policy['ok']) {
        return ['data' => null, 'http' => 0, 'error' => $policy['error'] ?? 'blocked'];
    }

    if (function_exists('curl_init')) {
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['User-Agent: HomeSignage/1.0'],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($err !== '') {
            return ['data' => null, 'http' => $http, 'error' => $err];
        }
        if (!is_string($body) || $body === '') {
            return ['data' => null, 'http' => $http, 'error' => 'empty response'];
        }
        return ['data' => $body, 'http' => $http, 'error' => null];
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'follow_location' => 1,
        'max_redirects' => 5,
        'header' => "User-Agent: HomeSignage/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $http = 0;
    $headers = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : null;
    if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $http = (int)$m[1];
    }
    if (!is_string($body) || $body === '') {
        return ['data' => null, 'http' => $http, 'error' => 'empty response'];
    }
    return ['data' => $body, 'http' => $http, 'error' => null];
}

/** @return array{version:?string,checked_at:int,error:?string} */
function video_ytdlp_latest_release(bool $force = false): array
{
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/ytdlp-latest.json';
    $ttl = 6 * 3600;

    if (!$force && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)
            && !empty($cached['version'])
            && (time() - (int)($cached['checked_at'] ?? 0)) < $ttl) {
            return [
                'version' => (string)$cached['version'],
                'checked_at' => (int)$cached['checked_at'],
                'error' => $cached['error'] ?? null,
            ];
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: HomeSignage/1.0\r\n",
        ],
    ]);
    $raw = @file_get_contents('https://api.github.com/repos/yt-dlp/yt-dlp/releases/latest', false, $ctx);
    $payload = [
        'version' => null,
        'checked_at' => time(),
        'error' => null,
        'yt_dlp_url' => null,
        'sha256' => null,
    ];

    if ($raw === false) {
        $payload['error'] = 'Could not reach GitHub for the latest yt-dlp release.';
    } else {
        $j = json_decode($raw, true);
        $tag = is_array($j) ? ltrim((string)($j['tag_name'] ?? ''), 'v') : '';
        if ($tag === '') {
            $payload['error'] = 'GitHub response did not include a release version.';
        } else {
            $payload['version'] = $tag;
            $sumsUrl = null;
            $binUrl = null;
            foreach ((array)($j['assets'] ?? []) as $asset) {
                $name = (string)($asset['name'] ?? '');
                $url = (string)($asset['browser_download_url'] ?? '');
                if ($name === 'yt-dlp' && $url !== '') {
                    $binUrl = $url;
                }
                if ($name === 'SHA2-256SUMS' && $url !== '') {
                    $sumsUrl = $url;
                }
            }
            $payload['yt_dlp_url'] = $binUrl;
            if ($sumsUrl !== null && $binUrl !== null) {
                $sumsResp = video_http_get_github_release($sumsUrl, 30);
                $sumsRaw = $sumsResp['data'];
                if (is_string($sumsRaw)) {
                    foreach (preg_split('/\r\n|\n|\r/', $sumsRaw) as $line) {
                        if (!preg_match('/^([a-f0-9]{64})\s+\*?yt-dlp\s*$/i', trim($line), $m)) {
                            continue;
                        }
                        $payload['sha256'] = strtolower($m[1]);
                        break;
                    }
                }
            }
        }
    }

    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $payload;
}

function video_ytdlp_is_outdated(?string $local = null, ?string $latest = null): ?bool
{
    $local = $local ?? video_ytdlp_version();
    if ($local === null) {
        return null;
    }
    if ($latest === null) {
        $latest = video_ytdlp_latest_release()['version'] ?? null;
    }
    if ($latest === null) {
        return null;
    }
    return version_compare($local, $latest, '<');
}

function video_deno_linux_zip_name(): ?string
{
    $arch = trim((string)@shell_exec('uname -m 2>/dev/null'));
    return match ($arch) {
        'x86_64', 'amd64' => 'deno-x86_64-unknown-linux-gnu.zip',
        'aarch64', 'arm64' => 'deno-aarch64-unknown-linux-gnu.zip',
        default => null,
    };
}

/** @return array{version:?string,checked_at:int,error:?string,zip_url:?string} */
function video_deno_latest_release(bool $force = false): array
{
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/deno-latest.json';
    $ttl = 6 * 3600;
    $zipName = video_deno_linux_zip_name();

    if (!$force && is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)
            && !empty($cached['version'])
            && (time() - (int)($cached['checked_at'] ?? 0)) < $ttl) {
            return [
                'version' => (string)$cached['version'],
                'checked_at' => (int)$cached['checked_at'],
                'error' => $cached['error'] ?? null,
                'zip_url' => $cached['zip_url'] ?? null,
            ];
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: HomeSignage/1.0\r\n",
        ],
    ]);
    $raw = @file_get_contents('https://api.github.com/repos/denoland/deno/releases/latest', false, $ctx);
    $payload = [
        'version' => null,
        'checked_at' => time(),
        'error' => null,
        'zip_url' => null,
    ];

    if ($raw === false) {
        $payload['error'] = 'Could not reach GitHub for the latest deno release.';
    } else {
        $j = json_decode($raw, true);
        $tag = is_array($j) ? ltrim((string)($j['tag_name'] ?? ''), 'v') : '';
        if ($tag === '') {
            $payload['error'] = 'GitHub response did not include a release version.';
        } else {
            $payload['version'] = $tag;
            if ($zipName !== null) {
                foreach ((array)($j['assets'] ?? []) as $asset) {
                    if ((string)($asset['name'] ?? '') === $zipName) {
                        $payload['zip_url'] = (string)($asset['browser_download_url'] ?? '');
                        break;
                    }
                }
            }
            if ($payload['zip_url'] === null && $zipName !== null) {
                $payload['error'] = "Release $tag has no $zipName asset for this CPU.";
            }
        }
    }

    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $payload;
}

function video_deno_is_outdated(?string $local = null, ?string $latest = null): ?bool
{
    $path = video_ytdlp_find_executable('deno');
    $local = $local ?? video_ytdlp_deno_version($path);
    if ($local === null) {
        return null;
    }
    if ($latest === null) {
        $latest = video_deno_latest_release()['version'] ?? null;
    }
    if ($latest === null) {
        return null;
    }
    return version_compare($local, $latest, '<');
}

/**
 * Download deno release zip into bin/deno (works as www-data when system deno is absent).
 * @return array{ok:bool,message:string,lines:list<string>,version:?string}
 */
function video_deno_install_bin(array $lines = []): array
{
    $zipName = video_deno_linux_zip_name();
    if ($zipName === null) {
        return [
            'ok' => false,
            'message' => 'Unsupported CPU for admin deno install — use setup-server.sh from SSH.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }
    if (!class_exists('ZipArchive')) {
        return [
            'ok' => false,
            'message' => 'PHP zip extension missing — install php-zip or upgrade deno via SSH.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    $binDir = __DIR__ . '/bin';
    if (!is_dir($binDir) && !@mkdir($binDir, 0775, true)) {
        return [
            'ok' => false,
            'message' => 'Could not create bin/ — check webroot permissions.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    $meta = video_deno_latest_release(true);
    $url = (string)($meta['zip_url'] ?? '');
    if ($url === '' || !str_starts_with($url, 'https://github.com/denoland/deno/releases/download/')) {
        return [
            'ok' => false,
            'message' => (string)($meta['error'] ?? 'Could not resolve a deno download URL from GitHub.'),
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    $target = video_deno_local_bin_path();
    $tmpZip = $binDir . '/deno.zip.new';
    $extractDir = $binDir . '/deno-extract.new';
    $tmpBin = $target . '.new';

    $dl = video_http_get_github_release($url, 180);
    $data = $dl['data'];
    if (!is_string($data) || $data === '') {
        $detail = $dl['error'] ?? 'unknown error';
        if (($dl['http'] ?? 0) > 0) {
            $detail = 'HTTP ' . (int)$dl['http'] . ($detail !== '' ? " — $detail" : '');
        }
        $lines[] = "Download failed: $detail";
        return [
            'ok' => false,
            'message' => 'Could not download the deno release zip.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    if (@file_put_contents($tmpZip, $data, LOCK_EX) === false) {
        @unlink($tmpZip);
        return [
            'ok' => false,
            'message' => 'Could not write deno zip — check bin/ permissions.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        return [
            'ok' => false,
            'message' => 'Downloaded deno zip could not be opened.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }
    if (is_dir($extractDir)) {
        video_deno_rmtree($extractDir);
    }
    @mkdir($extractDir, 0775, true);
    if ($zip->extractTo($extractDir) !== true) {
        $zip->close();
        @unlink($tmpZip);
        video_deno_rmtree($extractDir);
        return [
            'ok' => false,
            'message' => 'Could not extract deno zip.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }
    $zip->close();
    @unlink($tmpZip);

    $extracted = $extractDir . '/deno';
    if (!is_file($extracted)) {
        video_deno_rmtree($extractDir);
        return [
            'ok' => false,
            'message' => 'Extracted zip did not contain a deno binary.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }
    if (!@rename($extracted, $tmpBin)) {
        video_deno_rmtree($extractDir);
        return [
            'ok' => false,
            'message' => 'Could not stage bin/deno.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }
    video_deno_rmtree($extractDir);
    @chmod($tmpBin, 0755);
    if (!@rename($tmpBin, $target)) {
        @unlink($tmpBin);
        return [
            'ok' => false,
            'message' => 'Could not replace bin/deno.',
            'lines' => $lines,
            'version' => video_ytdlp_deno_version(),
        ];
    }

    $ver = video_ytdlp_deno_version($target);
    video_deno_latest_release(true);
    $lines[] = 'Installed bin/deno' . ($ver ? " ($ver)" : '') . '.';
    return [
        'ok' => true,
        'message' => 'Updated local bin/deno' . ($ver ? " to $ver" : '') . '.',
        'lines' => $lines,
        'version' => $ver,
    ];
}

function video_deno_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            video_deno_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @return array{ok:bool,message:string,lines:list<string>,version:?string} */
function video_deno_update(): array
{
    $result = video_deno_install_bin([]);
    if (!$result['ok']) {
        return $result;
    }

    $path = video_ytdlp_find_executable('deno');
    $localPath = video_deno_local_bin_path();
    if ($path === $localPath) {
        return $result;
    }

    $localVer = video_ytdlp_deno_version($localPath);
    $systemVer = video_ytdlp_deno_version($path);
    $result['message'] = 'Installed bin/deno ' . ($localVer ?? '')
        . '. Still using system deno ' . ($systemVer ?? '') . ' at ' . $path
        . ' (same or newer). Remove the system copy or upgrade via SSH to use bin/deno.';
    $result['version'] = video_ytdlp_deno_version($path);
    return $result;
}

/** @return array{installed:?string,path:?string,latest:?string,outdated:?bool,checked_at:?int,latest_error:?string,system:bool} */
function video_deno_status(bool $refreshLatest = false): array
{
    $path = video_ytdlp_find_executable('deno');
    $installed = video_ytdlp_deno_version($path);
    $latestInfo = video_deno_latest_release($refreshLatest);
    $latest = $latestInfo['version'] ?? null;
    $localPath = video_deno_local_bin_path();

    return [
        'installed' => $installed,
        'path' => $path,
        'latest' => $latest,
        'outdated' => video_deno_is_outdated($installed, $latest),
        'checked_at' => $latestInfo['checked_at'] ?? null,
        'latest_error' => $latestInfo['error'] ?? null,
        'system' => $path !== null && $path !== $localPath,
    ];
}

/** pipx only works when run as root — www-data cannot write /var/www/.local */
function video_ytdlp_pipx_usable(): bool
{
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return false;
    }
    return trim((string)@shell_exec('command -v pipx 2>/dev/null')) !== '';
}

/**
 * Download verified yt-dlp release into bin/yt-dlp (works as www-data).
 * @return array{ok:bool,message:string,lines:list<string>,version:?string}
 */
function video_ytdlp_install_bin(array $lines = []): array
{
    $binDir = __DIR__ . '/bin';
    if (!is_dir($binDir) && !@mkdir($binDir, 0775, true)) {
        return [
            'ok' => false,
            'message' => 'Could not create bin/ — check webroot permissions.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }

    $target = $binDir . '/yt-dlp';
    $tmp = $target . '.new';
    $meta = video_ytdlp_latest_release(true);
    $url = (string)($meta['yt_dlp_url'] ?? '');
    $expectSha = strtolower((string)($meta['sha256'] ?? ''));
    if ($url === '' || !str_starts_with($url, 'https://github.com/yt-dlp/yt-dlp/releases/download/')) {
        return [
            'ok' => false,
            'message' => 'Could not resolve a trusted yt-dlp download URL from GitHub.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    if ($expectSha === '') {
        return [
            'ok' => false,
            'message' => 'Could not load SHA2-256SUMS for yt-dlp — update aborted.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    $dl = video_http_get_github_release($url, 120);
    $data = $dl['data'];
    if (!is_string($data) || $data === '') {
        $detail = $dl['error'] ?? 'unknown error';
        if (($dl['http'] ?? 0) > 0) {
            $detail = 'HTTP ' . (int)$dl['http'] . ($detail !== '' ? " — $detail" : '');
        }
        $lines[] = "Download failed: $detail";
        return [
            'ok' => false,
            'message' => 'Could not download the yt-dlp release binary.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    $gotSha = hash('sha256', $data);
    if (!hash_equals($expectSha, $gotSha)) {
        return [
            'ok' => false,
            'message' => 'Downloaded yt-dlp failed SHA-256 verification — not installed.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
        @unlink($tmp);
        return [
            'ok' => false,
            'message' => 'Could not write bin/yt-dlp — check directory permissions.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    @chmod($tmp, 0755);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return [
            'ok' => false,
            'message' => 'Downloaded yt-dlp but could not replace bin/yt-dlp.',
            'lines' => $lines,
            'version' => video_ytdlp_version(),
        ];
    }
    @chmod($target, 0755);

    $ver = video_ytdlp_version($target);
    video_ytdlp_latest_release(true);
    $lines[] = 'Installed bin/yt-dlp' . ($ver ? " ($ver)" : '') . '.';
    return [
        'ok' => true,
        'message' => 'Updated local bin/yt-dlp' . ($ver ? " to $ver" : '') . '.',
        'lines' => $lines,
        'version' => $ver,
    ];
}

/** @return array{ok:bool,message:string,lines:list<string>,version:?string} */
function video_ytdlp_update(): array
{
    $lines = [];

    // Admin runs as www-data — pipx needs root and writes under $HOME/.local.
    if (video_ytdlp_pipx_usable()) {
        $pipx = trim((string)@shell_exec('command -v pipx 2>/dev/null'));
        $cmd = escapeshellarg($pipx) . ' upgrade yt-dlp --force 2>&1';
        $out = [];
        $rc = 0;
        exec($cmd, $out, $rc);
        $lines = array_merge($lines, $out);
        if ($rc === 0) {
            $ver = video_ytdlp_version();
            video_ytdlp_latest_release(true);
            return [
                'ok' => true,
                'message' => 'Updated yt-dlp via pipx' . ($ver ? " to $ver" : '') . '.',
                'lines' => $lines,
                'version' => $ver,
            ];
        }
        $lines[] = 'pipx upgrade failed (exit ' . $rc . ') — trying bin/yt-dlp download.';
    }

    return video_ytdlp_install_bin($lines);
}

/** @return array<string,mixed> */
function video_entry_status(string $key, array $v, ?string $dir = null): array
{
    $path = video_path($key, $v, $dir);
    $dur = $path ? video_duration($path) : null;
    $youtube = isset($v['youtube']) ? trim((string)$v['youtube']) : '';
    return [
        'key' => $key,
        'title' => $v['title'] ?? '',
        'file' => $path ? basename($path) : null,
        'duration_sec' => $dur,
        'duration_label' => $dur ? gmdate('i:s', (int)$dur) : null,
        'rotation_dwell' => $dur ? (int)ceil($dur) : null,
        'youtube' => $youtube !== '' ? $youtube : null,
        'fetchable' => $youtube !== '' && !str_contains($youtube, 'REPLACE_ME'),
        'local_only' => !isset($v['youtube']) || $youtube === '' || str_contains($youtube, 'REPLACE_ME'),
    ];
}

/** @return array{ok:bool,lines:list<string>,entries:list<array<string,mixed>>} */
function video_fetch_entries(?callable $onLine = null, ?array $onlyKeys = null): array
{
    $dir = video_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'lines' => ['Could not create ' . $dir], 'entries' => []];
    }

    $registry = video_registry();
    if (function_exists('admin_entry_visible')) {
        $registry = array_filter(
            $registry,
            static fn($v) => is_array($v) && admin_entry_visible($v)
        );
    }
    if ($onlyKeys !== null) {
        $filtered = [];
        foreach ($onlyKeys as $key) {
            if (isset($registry[$key])) {
                $filtered[$key] = $registry[$key];
            }
        }
        if ($filtered === []) {
            return ['ok' => false, 'lines' => ['Unknown or invalid video key.'], 'entries' => []];
        }
        $registry = $filtered;
    }

    $bin = video_ytdlp_bin();
    if ($bin === null) {
        return [
            'ok' => false,
            'lines' => ['yt-dlp not found — install it on the server or use Update yt-dlp below.'],
            'entries' => [],
        ];
    }

    $maxH = video_max_height();
    $fmt = sprintf(
        'bv*[ext=mp4][height<=%d]+ba[ext=m4a]/b[ext=mp4][height<=%d]/b',
        $maxH,
        $maxH
    );

    $lines = [];
    $emit = function (string $line) use (&$lines, $onLine): void {
        $lines[] = $line;
        if ($onLine) {
            $onLine($line);
        }
    };

    $entries = [];
    foreach ($registry as $key => $v) {
        if (!isset($v['youtube'])) {
            $st = video_entry_status($key, $v, $dir);
            $entries[] = $st;
            if ($st['file']) {
                $emit("[{$key}] local file {$st['file']}");
            } else {
                $emit("[{$key}] no local file yet");
            }
            continue;
        }
        if (str_contains((string)$v['youtube'], 'REPLACE_ME')) {
            $emit("[{$key}] skipped — set a real YouTube URL first");
            $entries[] = video_entry_status($key, $v, $dir);
            continue;
        }

        $ytCheck = signage_youtube_url_allowed((string)$v['youtube']);
        if (!$ytCheck['ok']) {
            $emit("[{$key}] blocked — " . ($ytCheck['error'] ?? 'invalid YouTube URL'));
            $entries[] = video_entry_status($key, $v, $dir);
            $ok = false;
            continue;
        }

        $emit("[{$key}] fetching {$v['youtube']}");
        $out = $dir . "/$key.%(ext)s";
        $cmd = video_ytdlp_exec_env() . video_ytdlp_shell($bin)
            . video_ytdlp_extra_args()
            . ' -f ' . escapeshellarg($fmt)
            . ' --merge-output-format mp4 --no-progress --force-overwrites'
            . ' -o ' . escapeshellarg($out)
            . ' ' . escapeshellarg((string)$v['youtube'])
            . ' 2>&1';

        $procOut = [];
        $rc = 0;
        exec($cmd, $procOut, $rc);
        foreach ($procOut as $line) {
            $emit($line);
        }
        if ($rc !== 0) {
            $emit("[{$key}] yt-dlp failed (exit $rc)");
            $blob = implode("\n", $procOut);
            if ($rc === 126 && (str_contains($blob, 'Permission denied') || str_contains($blob, 'cannot execute'))) {
                $emit("[{$key}] hint: webroots are often mounted noexec — deploy latest video_lib.php (runs bin/yt-dlp via python3), or: sudo -u www-data python3 bin/yt-dlp --version");
            }
            if (str_contains($blob, 'ModuleNotFoundError') || str_contains($blob, 'No module named \'yt_dlp\'')) {
                $emit("[{$key}] hint: bin/yt-dlp is a pip/pipx stub (~200 B) — use Admin → Update yt-dlp or curl the GitHub release into bin/yt-dlp");
            }
            if (str_contains($blob, 'Signature solving failed') || str_contains($blob, 'n challenge solving failed')
                || str_contains($blob, 'Only images are available')) {
                $emit("[{$key}] hint: need deno " . video_ytdlp_deno_min_version() . '+, fresh cookies, and writable cache/yt-dlp — or scp the .mp4 and use local file');
            }
            if (str_contains($blob, 'Sign in to confirm') || str_contains($blob, 'not a bot')) {
                $emit("[{$key}] hint: export YouTube cookies to config/cookies/youtube.txt (see README Video Board)");
            }
        }

        $st = video_entry_status($key, $v, $dir);
        $entries[] = $st;
        if ($st['duration_label'] && $st['rotation_dwell']) {
            $emit("[{$key}] {$st['file']} — duration {$st['duration_label']} → rotation dwell: {$st['rotation_dwell']} s");
        } elseif ($st['file']) {
            $emit("[{$key}] {$st['file']} — install ffmpeg/ffprobe for duration readout");
        }
    }

    $ok = true;
    foreach ($entries as $st) {
        if ($st['fetchable'] && !$st['file']) {
            $ok = false;
        }
    }

    return ['ok' => $ok, 'lines' => $lines, 'entries' => $entries];
}

/** @return array{ok:bool,lines:list<string>,entries:list<array<string,mixed>>} */
function video_fetch_one(string $key, ?callable $onLine = null): array
{
    $key = preg_replace('/[^a-z0-9_\-]/i', '', $key);
    if ($key === '') {
        return ['ok' => false, 'lines' => ['Invalid video key.'], 'entries' => []];
    }
    return video_fetch_entries($onLine, [$key]);
}

/** @return array{ok:bool,lines:list<string>,entries:list<array<string,mixed>>} */
function video_fetch_all(?callable $onLine = null): array
{
    return video_fetch_entries($onLine, null);
}

/** @return array{installed:?string,path:?string,latest:?string,outdated:?bool,checked_at:?int,latest_error:?string} */
function video_ytdlp_status(bool $refreshLatest = false): array
{
    $bin = video_ytdlp_bin();
    $installed = video_ytdlp_version($bin);
    $latestInfo = video_ytdlp_latest_release($refreshLatest);
    $latest = $latestInfo['version'] ?? null;

    return [
        'installed' => $installed,
        'path' => $bin,
        'stub' => video_ytdlp_local_bin_is_stub(),
        'latest' => $latest,
        'outdated' => video_ytdlp_is_outdated($installed, $latest),
        'checked_at' => $latestInfo['checked_at'] ?? null,
        'latest_error' => $latestInfo['error'] ?? null,
    ];
}

function video_rotation_url(string $key): string
{
    return 'video.php?v=' . rawurlencode($key);
}

/** @return list<array<string,mixed>> */
function video_rotation_screen_pages(string $screen = 'main'): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $pages = cfg("rotation.PAGES_$screen", null);
    if (!is_array($pages) || $pages === []) {
        $pages = cfg('rotation.PAGES_main', cfg('rotation.PAGES', []));
    }
    return is_array($pages) ? $pages : [];
}

function video_in_rotation(string $key, string $screen = 'main'): bool
{
    $want = video_rotation_url($key);
    foreach (video_rotation_screen_pages($screen) as $page) {
        if (!is_array($page)) {
            continue;
        }
        if (trim((string)($page['url'] ?? '')) === $want) {
            return true;
        }
    }
    return false;
}

/**
 * Add or update rotation entries for every video in the registry (playlist order).
 * @return array{pages:list<array<string,mixed>>,added:list<string>,updated:list<string>,screen:string}
 */
function video_sync_rotation(string $screen = 'main'): array
{
    $screen = preg_replace('/[^a-z0-9_\-]/i', '', $screen);
    if ($screen === '') {
        $screen = 'main';
    }
    $pages = video_rotation_screen_pages($screen);
    $added = [];
    $updated = [];
    foreach (video_registry() as $key => $v) {
        if (!is_array($v)) {
            continue;
        }
        if (function_exists('admin_entry_visible') && !admin_entry_visible($v)) {
            continue;
        }
        $st = video_entry_status($key, $v);
        $url = video_rotation_url($key);
        $dwell = max(15, (int)($st['rotation_dwell'] ?? 60));
        $found = false;
        foreach ($pages as &$page) {
            if (!is_array($page)) {
                continue;
            }
            if (trim((string)($page['url'] ?? '')) !== $url) {
                continue;
            }
            if ((int)($page['dwell'] ?? 0) !== $dwell) {
                $page['dwell'] = $dwell;
                $updated[] = $key;
            }
            $found = true;
            break;
        }
        unset($page);
        if (!$found) {
            $pages[] = ['url' => $url, 'dwell' => $dwell];
            $added[] = $key;
        }
    }
    return ['pages' => $pages, 'added' => $added, 'updated' => $updated, 'screen' => $screen];
}

function video_rotation_pages_write(string $screen, array $pages): bool
{
    $key = 'rotation.PAGES_' . preg_replace('/[^a-z0-9_\-]/i', '', $screen);

    return cfg_update(function (array $conf) use ($key, $pages): array {
        $conf[$key] = $pages;

        return $conf;
    });
}

function video_normalize_key(string $key): ?string
{
    require_once __DIR__ . '/users_lib.php';

    return admin_normalize_registry_key($key);
}

/** @return list<string> Basenames of on-disk files tied to one registry entry. */
function video_entry_disk_files(string $key, array $v, ?string $dir = null): array
{
    $path = video_path($key, $v, $dir);
    if ($path === null) {
        return [];
    }

    return [basename($path)];
}

/** @param array<string,array<string,mixed>|mixed> $registry */
function video_registry_references_file(array $registry, string $basename): bool
{
    $basename = basename($basename);
    if ($basename === '') {
        return false;
    }
    foreach ($registry as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        if (isset($v['file']) && basename((string)$v['file']) === $basename) {
            return true;
        }
        $entryKey = video_normalize_key((string)$k);
        if ($entryKey === null) {
            continue;
        }
        foreach (['mp4', 'webm', 'mkv'] as $ext) {
            if ($basename === $entryKey . '.' . $ext) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Delete a video registry entry, remove its file when unreferenced, and drop rotation URLs.
 * @return array{ok:bool,key?:string,error?:string}
 */
function video_delete_entry(string $key): array
{
    require_once __DIR__ . '/users_lib.php';
    require_once __DIR__ . '/rotation_lib.php';

    $safe = video_normalize_key($key);
    if ($safe === null) {
        return ['ok' => false, 'error' => 'Invalid video key.'];
    }

    $registry = video_registry();
    $entry = is_array($registry[$safe] ?? null) ? $registry[$safe] : null;
    if ($entry === null) {
        return ['ok' => false, 'error' => 'Video not found.'];
    }

    $dir = video_dir();
    $diskFiles = video_entry_disk_files($safe, $entry, $dir);

    if (!cfg_update(function (array $conf) use ($safe): array {
        $registry = $conf['video.VIDEOS'] ?? [];
        if (!is_array($registry)) {
            $registry = [];
        }
        $registry = admin_remove_video_from_registry($registry, $safe);
        if ($registry === []) {
            unset($conf['video.VIDEOS']);
        } else {
            $conf['video.VIDEOS'] = $registry;
        }

        return $conf;
    })) {
        return ['ok' => false, 'error' => 'Could not update settings.json.'];
    }
    cfg_reload();

    $registry = video_registry();
    foreach ($diskFiles as $basename) {
        if (!video_registry_references_file($registry, $basename)) {
            $path = $dir . '/' . $basename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    $rotationUrl = video_rotation_url($safe);
    foreach (admin_media_rotation_sync_screens() as $screen) {
        $sync = rotation_remove_url($screen, $rotationUrl);
        if ($sync['removed']) {
            rotation_pages_write($sync['screen'], $sync['pages']);
        }
    }
    cfg_reload();

    return ['ok' => true, 'key' => $safe];
}
