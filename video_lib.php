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

/** @return list<string> */
function video_ytdlp_candidates(): array
{
    $out = [];
    $local = __DIR__ . '/bin/yt-dlp';
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
    foreach (video_ytdlp_candidates() as $p) {
        if (is_file($p) && is_executable($p)) {
            return $p;
        }
    }
    return null;
}

/** Resolved path to optional Netscape-format YouTube cookies, or null if unset/missing. */
function video_ytdlp_cookies_path(): ?string
{
    $raw = trim((string)cfg('video.YTDLP_COOKIES_FILE', ''));
    if ($raw === '') {
        $raw = 'config/cookies/youtube.txt';
    }
    if ($raw[0] !== '/') {
        $raw = __DIR__ . '/' . ltrim($raw, '/');
    }
    return is_file($raw) && is_readable($raw) ? $raw : null;
}

/** Extra yt-dlp CLI flags for YouTube (cookies + JS runtime). */
function video_ytdlp_extra_args(): string
{
    $args = '';
    $cookies = video_ytdlp_cookies_path();
    if ($cookies !== null) {
        $args .= ' --cookies ' . escapeshellarg($cookies);
    }
    $runtime = (string)cfg('video.YTDLP_JS_RUNTIME', 'auto');
    if ($runtime === 'none') {
        return $args;
    }
    if ($runtime === 'auto') {
        if (trim((string)@shell_exec('command -v deno 2>/dev/null')) !== '') {
            $args .= ' --js-runtimes deno';
        } elseif (trim((string)@shell_exec('command -v node 2>/dev/null')) !== '') {
            $args .= ' --js-runtimes node';
        }
        return $args;
    }
    if (in_array($runtime, ['deno', 'node'], true)
        && trim((string)@shell_exec('command -v ' . escapeshellarg($runtime) . ' 2>/dev/null')) !== '') {
        $args .= ' --js-runtimes ' . $runtime;
    }
    return $args;
}

/** @return array{deno:bool,node:bool,cookies:bool,cookies_path:?string} */
function video_ytdlp_support_status(): array
{
    $configured = trim((string)cfg('video.YTDLP_COOKIES_FILE', ''));
    if ($configured === '') {
        $configured = 'config/cookies/youtube.txt';
    }
    return [
        'deno' => trim((string)@shell_exec('command -v deno 2>/dev/null')) !== '',
        'node' => trim((string)@shell_exec('command -v node 2>/dev/null')) !== '',
        'cookies' => video_ytdlp_cookies_path() !== null,
        'cookies_path' => video_ytdlp_cookies_path() ?? $configured,
    ];
}

function video_ytdlp_version(?string $bin = null): ?string
{
    $bin = $bin ?? video_ytdlp_bin();
    if ($bin === null) {
        return null;
    }
    $out = @shell_exec(escapeshellarg($bin) . ' --version 2>/dev/null');
    $v = is_string($out) ? trim($out) : '';
    return $v !== '' ? $v : null;
}

function video_github_release_url_allowed(string $url): bool
{
    return str_starts_with($url, 'https://github.com/yt-dlp/yt-dlp/releases/download/');
}

/**
 * Fetch a pinned yt-dlp GitHub release asset (follows redirects to githubusercontent.com).
 * @return array{data:?string,http:int,error:?string}
 */
function video_http_get_github_release(string $url, int $timeout = 120): array
{
    if (!video_github_release_url_allowed($url)) {
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
        $cmd = escapeshellarg($bin)
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
        'latest' => $latest,
        'outdated' => video_ytdlp_is_outdated($installed, $latest),
        'checked_at' => $latestInfo['checked_at'] ?? null,
        'latest_error' => $latestInfo['error'] ?? null,
    ];
}
