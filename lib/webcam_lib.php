<?php
/**
 * Webcam board — embed URL validation, probe cache, rotation seasonal skip.
 */

require_once __DIR__ . '/../config.php';

const WEBCAM_PROBE_TTL_SEC = 1800;
const WEBCAM_ONLINE_MAX_AGE_MIN = 60;
const WEBCAM_SKIP_MIN_AGE_MIN = 1440;

function webcam_embed_url(): ?string
{
    $url = trim((string)cfg('webcam.EMBED_URL', ''));
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    if (trim((string)($parts['host'] ?? '')) === '') {
        return null;
    }

    return $url;
}

function webcam_status_cache_path(): string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/webcam_embed_status.json';
}

/** @return array{last_ok:?int,last_fail:?int,last_probe:?int} */
function webcam_status_read_cache(): array
{
    $f = webcam_status_cache_path();
    if (!is_file($f)) {
        return ['last_ok' => null, 'last_fail' => null, 'last_probe' => null];
    }
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) {
        return ['last_ok' => null, 'last_fail' => null, 'last_probe' => null];
    }

    return [
        'last_ok' => isset($j['last_ok']) ? (int)$j['last_ok'] : null,
        'last_fail' => isset($j['last_fail']) ? (int)$j['last_fail'] : null,
        'last_probe' => isset($j['last_probe']) ? (int)$j['last_probe'] : null,
    ];
}

/** @param array{last_ok:?int,last_fail:?int,last_probe:?int} $data */
function webcam_status_write_cache(array $data): void
{
    @file_put_contents(webcam_status_cache_path(), json_encode([
        'last_ok' => $data['last_ok'],
        'last_fail' => $data['last_fail'],
        'last_probe' => $data['last_probe'],
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function webcam_probe_embed(?string $url = null): bool
{
    $url = $url ?? webcam_embed_url();
    if ($url === null) {
        return false;
    }
    if (!function_exists('curl_init')) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HomeSignage/1.0',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);

    return $err === '' && $code >= 200 && $code < 400;
}

/**
 * @return array{online:bool,skip_rotation:bool,embed_configured:bool}
 */
function webcam_embed_status(): array
{
    static $mem = null;
    if ($mem !== null) {
        return $mem;
    }

    $url = webcam_embed_url();
    if ($url === null) {
        return $mem = [
            'online' => false,
            'skip_rotation' => true,
            'embed_configured' => false,
        ];
    }

    $cache = webcam_status_read_cache();
    $now = time();
    $needsProbe = ($cache['last_probe'] ?? null) === null
        || ($now - (int)$cache['last_probe']) >= WEBCAM_PROBE_TTL_SEC;

    if ($needsProbe) {
        $ok = webcam_probe_embed($url);
        if ($ok) {
            $cache['last_ok'] = $now;
        } else {
            $cache['last_fail'] = $now;
        }
        $cache['last_probe'] = $now;
        webcam_status_write_cache($cache);
    }

    $lastOk = $cache['last_ok'];
    $lastFail = $cache['last_fail'];
    $okAgeMin = $lastOk ? (int)round(($now - $lastOk) / 60) : null;
    $online = $okAgeMin !== null && $okAgeMin < WEBCAM_ONLINE_MAX_AGE_MIN;
    $skipRotation = !$online && (
        ($lastOk === null && $lastFail !== null && ($now - $lastFail) / 60 >= WEBCAM_SKIP_MIN_AGE_MIN)
        || ($okAgeMin !== null && $okAgeMin >= WEBCAM_SKIP_MIN_AGE_MIN)
    );

    return $mem = [
        'online' => $online,
        'skip_rotation' => $skipRotation,
        'embed_configured' => true,
    ];
}

function webcam_skip_rotation(): bool
{
    return webcam_embed_status()['skip_rotation'];
}

/** Whether a rotation playlist URL targets webcam.php. */
function rotation_page_url_is_webcam(string $url): bool
{
    $url = trim($url);
    if ($url === '' || strcasecmp($url, 'webcam.php') === 0) {
        return true;
    }
    if (preg_match('~^webcam\.php(?:[?#]|$)~i', $url) === 1) {
        return true;
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

    return preg_match('~(?:^|/)webcam\.php$~i', $path) === 1;
}
