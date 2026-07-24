<?php
/**
 * TLS certificate expiry — probe configured hosts (HTTPS).
 */

require_once dirname(__DIR__) . '/config.php';

const CERTEXP_CACHE_DIR = SIGNAGE_ROOT . '/cache';

function certexp_cache_ttl(): int
{
    return max(300, (int)cfg('certexp.CACHE_TTL', 3600));
}

function certexp_user_agent(): string
{
    return trim((string)cfg('certexp.USER_AGENT', 'HomeSignage/CertExpBoard/1.0 (signage-suite)'));
}

function certexp_max_items(): int
{
    return max(4, min(16, (int)cfg('certexp.MAX_ITEMS', 10)));
}

function certexp_warn_days(): int
{
    return max(1, min(90, (int)cfg('certexp.WARN_DAYS', 30)));
}

function certexp_host_rows(): array
{
    $rows = cfg('certexp.HOSTS', []);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['off'])) {
            continue;
        }
        $host = trim((string)($row['host'] ?? ''));
        if ($host === '') {
            continue;
        }
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '') {
            $label = is_string($key) ? (string)$key : $host;
        }
        $port = (int)($row['port'] ?? 443);
        if ($port <= 0) {
            $port = 443;
        }
        $out[] = [
            'key' => is_string($key) ? (string)$key : $host,
            'label' => $label,
            'host' => $host,
            'port' => $port,
        ];
    }

    return $out;
}

function certexp_cache_file(string $host, int $port): string
{
    $safe = preg_replace('/[^a-z0-9.-]+/i', '_', $host) ?? 'host';

    return CERTEXP_CACHE_DIR . '/certexp_' . $safe . '_' . $port . '.json';
}

/** @return array<string,mixed> */
function certexp_probe(string $host, int $port = 443): array
{
    $host = trim($host);
    $port = max(1, min(65535, $port));
    $cacheFile = certexp_cache_file($host, $port);
    $ttl = certexp_cache_ttl();
    if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $result = [
        'host' => $host,
        'port' => $port,
        'ok' => false,
        'error' => '',
        'subject' => '',
        'issuer' => '',
        'expires_ts' => 0,
        'expires' => '',
        'days_left' => null,
    ];

    $ctx = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
        ],
    ]);
    $target = 'ssl://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $client = @stream_socket_client($target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if ($client === false) {
        $result['error'] = $errstr !== '' ? $errstr : ('connect failed (' . $errno . ')');
        @file_put_contents($cacheFile, json_encode($result), LOCK_EX);

        return $result;
    }
    $params = stream_context_get_params($client);
    fclose($client);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) {
        $result['error'] = 'no certificate';
        @file_put_contents($cacheFile, json_encode($result), LOCK_EX);

        return $result;
    }
    $parsed = openssl_x509_parse($cert);
    if (!is_array($parsed)) {
        $result['error'] = 'parse failed';
        @file_put_contents($cacheFile, json_encode($result), LOCK_EX);

        return $result;
    }
    $expiresTs = (int)($parsed['validTo_time_t'] ?? 0);
    $subject = trim((string)($parsed['subject']['CN'] ?? ''));
    if ($subject === '' && isset($parsed['subject']) && is_array($parsed['subject'])) {
        $subject = trim(implode(', ', array_map(
            static fn($k, $v) => is_string($v) ? (string)$v : '',
            array_keys($parsed['subject']),
            $parsed['subject']
        )));
    }
    $issuer = trim((string)($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? ''));
    $daysLeft = $expiresTs > 0 ? (int)floor(($expiresTs - time()) / 86400) : null;

    $result = [
        'host' => $host,
        'port' => $port,
        'ok' => true,
        'error' => '',
        'subject' => $subject,
        'issuer' => $issuer,
        'expires_ts' => $expiresTs,
        'expires' => $expiresTs > 0 ? date('Y-m-d', $expiresTs) : '',
        'days_left' => $daysLeft,
    ];
    @file_put_contents($cacheFile, json_encode($result), LOCK_EX);

    return $result;
}

/** @return array<string,mixed> */
function certexp_board_data(): array
{
    if (!is_dir(CERTEXP_CACHE_DIR)) {
        @mkdir(CERTEXP_CACHE_DIR, 0775, true);
    }
    $warn = certexp_warn_days();
    $max = certexp_max_items();
    $rows = [];
    foreach (certexp_host_rows() as $cfg) {
        $probe = certexp_probe((string)$cfg['host'], (int)$cfg['port']);
        $days = $probe['days_left'];
        $status = 'ok';
        if (!$probe['ok']) {
            $status = 'error';
        } elseif ($days !== null && $days < 0) {
            $status = 'expired';
        } elseif ($days !== null && $days <= $warn) {
            $status = 'warn';
        }
        $rows[] = array_merge($probe, [
            'label' => (string)$cfg['label'],
            'key' => (string)$cfg['key'],
            'status' => $status,
        ]);
    }
    usort($rows, static function (array $a, array $b): int {
        $rank = ['error' => 0, 'expired' => 1, 'warn' => 2, 'ok' => 3];
        $ra = $rank[$a['status'] ?? 'ok'] ?? 4;
        $rb = $rank[$b['status'] ?? 'ok'] ?? 4;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $da = $a['days_left'] ?? 99999;
        $db = $b['days_left'] ?? 99999;

        return $da <=> $db;
    });

    $expiring = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['status'], ['warn', 'expired', 'error'], true)));
    $hero = $expiring[0] ?? $rows[0] ?? null;
    $list = array_slice($expiring !== [] ? $expiring : $rows, 0, $max);
    if ($hero !== null) {
        $list = array_values(array_filter($list, static fn(array $r): bool => ($r['host'] ?? '') . ':' . ($r['port'] ?? '') !== ($hero['host'] ?? '') . ':' . ($hero['port'] ?? '')));
    }
    $list = array_slice($list, 0, max(0, $max - 1));

    return [
        'hero' => $hero,
        'list' => $list,
        'stats' => [
            'hosts' => count($rows),
            'expiring' => count($expiring),
            'warn_days' => $warn,
        ],
        'has_data' => $rows !== [],
    ];
}
