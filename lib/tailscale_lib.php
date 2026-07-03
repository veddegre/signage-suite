<?php
/**
 * Tailscale board — shared helpers for tailscale.php and admin.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/security_lib.php';

const TAILSCALE_CACHE_DIR = SIGNAGE_ROOT . '/cache';

function tailscale_tailnet(): string
{
    return trim((string)cfg('tailscale.TAILNET', ''));
}

function tailscale_api_key(): string
{
    return trim((string)cfg('tailscale.TAILSCALE_API_KEY', ''));
}

function tailscale_cache_ttl(): int
{
    return max(30, (int)cfg('tailscale.CACHE_TTL', 60));
}

function tailscale_max_devices(): int
{
    return max(4, min(80, (int)cfg('tailscale.MAX_DEVICES', 32)));
}

function tailscale_configured(): bool
{
    $key = tailscale_api_key();
    $net = tailscale_tailnet();

    return $key !== ''
        && $key !== 'PUT-TAILSCALE-API-KEY-HERE'
        && $net !== ''
        && !str_contains($net, 'REPLACE');
}

/** @return array{body:mixed,code:int,err:string} */
function tailscale_api_get(string $path): array
{
    $key = tailscale_api_key();
    if ($key === '') {
        return ['body' => false, 'code' => 0, 'err' => 'API key missing'];
    }
    $url = 'https://api.tailscale.com' . $path;
    $policy = signage_fetch_url_allowed($url, true);
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => $policy['error'] ?? 'blocked URL'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERPWD => $key . ':',
        CURLOPT_USERAGENT => 'HomeSignage/TailscaleBoard/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'code' => $code, 'err' => $err];
}

/** @return array{ok:bool,error:string,devices:list<array<string,mixed>>,counts:array<string,int>} */
function tailscale_fetch_devices(): array
{
    if (!tailscale_configured()) {
        return ['ok' => false, 'error' => 'not configured', 'devices' => [], 'counts' => []];
    }
    if (!is_dir(TAILSCALE_CACHE_DIR)) {
        @mkdir(TAILSCALE_CACHE_DIR, 0775, true);
    }
    $cacheFile = TAILSCALE_CACHE_DIR . '/tailscale_devices.json';
    $ttl = tailscale_cache_ttl();
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $tailnet = rawurlencode(tailscale_tailnet());
    $res = tailscale_api_get('/api/v2/tailnet/' . $tailnet . '/devices');
    if ($res['body'] === false || $res['code'] < 200 || $res['code'] >= 300) {
        $err = $res['err'] !== '' ? $res['err'] : ('HTTP ' . $res['code']);
        if (is_file($cacheFile)) {
            $stale = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($stale)) {
                $stale['error'] = $err;
                $stale['stale'] = true;

                return $stale;
            }
        }

        return ['ok' => false, 'error' => $err, 'devices' => [], 'counts' => []];
    }
    $data = json_decode((string)$res['body'], true);
    $list = [];
    if (isset($data['devices']) && is_array($data['devices'])) {
        $list = $data['devices'];
    } elseif (array_is_list($data)) {
        $list = $data;
    }

    $devices = [];
    foreach ($list as $dev) {
        if (!is_array($dev)) {
            continue;
        }
        $name = trim((string)($dev['name'] ?? $dev['hostname'] ?? ''));
        if ($name === '') {
            $name = trim((string)($dev['nodeId'] ?? 'Device'));
        }
        $online = !empty($dev['online']);
        $tags = [];
        if (is_array($dev['tags'] ?? null)) {
            foreach ($dev['tags'] as $tag) {
                $tags[] = ltrim(trim((string)$tag), 'tag:');
            }
        }
        $lastSeen = trim((string)($dev['lastSeen'] ?? ''));
        $os = trim((string)($dev['os'] ?? $dev['clientVersion'] ?? ''));
        $devices[] = [
            'name' => $name,
            'online' => $online,
            'tags' => $tags,
            'last_seen' => $lastSeen,
            'os' => $os,
            'addresses' => is_array($dev['addresses'] ?? null) ? $dev['addresses'] : [],
        ];
    }
    usort($devices, static function ($a, $b) {
        if (($a['online'] ?? false) !== ($b['online'] ?? false)) {
            return ($a['online'] ?? false) ? -1 : 1;
        }

        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $max = tailscale_max_devices();
    if (count($devices) > $max) {
        $devices = array_slice($devices, 0, $max);
    }
    $online = count(array_filter($devices, static fn($d) => !empty($d['online'])));
    $out = [
        'ok' => true,
        'error' => '',
        'devices' => $devices,
        'counts' => [
            'total' => count($devices),
            'online' => $online,
            'offline' => count($devices) - $online,
        ],
        'fetched_at' => time(),
    ];
    @file_put_contents($cacheFile, json_encode($out), LOCK_EX);

    return $out;
}

function tailscale_preview_url(): string
{
    return signage_board_preview_url('tailscale.php');
}
