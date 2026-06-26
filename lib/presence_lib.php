<?php
/**
 * Sign presence and play stats — ephemeral state written by board.php heartbeats.
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/json_store_lib.php';
require_once __DIR__ . '/rotation_lib.php';

const SIGNAGE_PRESENCE_STALE_SEC = 120;
const SIGNAGE_PRESENCE_MAX_PAGE_STATS = 40;
const SIGNAGE_PLAY_LOG_MAX = 300;

function signage_presence_path(): string
{
    $dir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . '/presence.json';
}

/** @return array<string, array<string, mixed>> */
function signage_presence_read_all(): array
{
    $f = signage_presence_path();
    if (!is_file($f)) {
        return [];
    }
    $j = json_decode((string)file_get_contents($f), true);

    return is_array($j) ? $j : [];
}

function signage_presence_write_all(array $data): bool
{
    $result = signage_json_file_update(signage_presence_path(), static fn(): array => $data, [
        'default' => [],
        'ensure_dir' => true,
    ]);

    return (bool)($result['ok'] ?? false);
}

function signage_presence_stats_day(): string
{
    try {
        $tz = rotation_timezone();
        $prev = date_default_timezone_get();
        date_default_timezone_set($tz);
        $day = date('Y-m-d');
        date_default_timezone_set($prev);

        return $day;
    } catch (Throwable $e) {
        return date('Y-m-d');
    }
}

function signage_presence_online(?array $entry): bool
{
    if (!$entry || empty($entry['last_seen'])) {
        return false;
    }

    return (time() - (int)$entry['last_seen']) <= SIGNAGE_PRESENCE_STALE_SEC;
}

function signage_presence_format_ago(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $sec = time() - $ts;
    if ($sec < 45) {
        return 'just now';
    }
    if ($sec < 90) {
        return '1 min ago';
    }
    if ($sec < 3600) {
        return (int)floor($sec / 60) . ' min ago';
    }
    if ($sec < 86400) {
        $h = (int)floor($sec / 3600);

        return $h . ' hr' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = (int)floor($sec / 86400);

    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

function signage_presence_format_time(int $ts): string
{
    try {
        $tz = rotation_timezone();
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone($tz));

        return $dt->format('g:i A');
    } catch (Throwable $e) {
        return date('g:i A', $ts);
    }
}

function signage_presence_format_datetime(int $ts): string
{
    try {
        $tz = rotation_timezone();
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone($tz));

        return $dt->format('M j, g:i A');
    } catch (Throwable $e) {
        return date('M j, g:i A', $ts);
    }
}

/** @param array<string, int> $stats */
function signage_presence_trim_page_stats(array $stats): array
{
    if (count($stats) <= SIGNAGE_PRESENCE_MAX_PAGE_STATS) {
        return $stats;
    }
    arsort($stats);

    return array_slice($stats, 0, SIGNAGE_PRESENCE_MAX_PAGE_STATS, true);
}

/** @return array{count:int,last_ts:?int,last_time:string,last_ago:string}|null */
function signage_presence_page_summary(?array $entry, string $url): ?array
{
    $url = trim($url);
    if ($entry === null || $url === '') {
        return null;
    }

    $statsDay = (string)($entry['stats_day'] ?? '');
    $today = signage_presence_stats_day();
    $count = 0;
    if ($statsDay === $today && is_array($entry['page_stats'] ?? null)) {
        $count = (int)($entry['page_stats'][$url] ?? 0);
    }

    $lastTs = 0;
    if (is_array($entry['page_last'] ?? null)) {
        $lastTs = (int)($entry['page_last'][$url] ?? 0);
    }

    if ($count === 0 && $lastTs === 0) {
        return null;
    }

    return [
        'count' => $count,
        'last_ts' => $lastTs > 0 ? $lastTs : null,
        'last_time' => $lastTs > 0 ? signage_presence_format_time($lastTs) : '',
        'last_ago' => $lastTs > 0 ? signage_presence_format_ago($lastTs) : '',
    ];
}

function signage_presence_page_proof_label(?array $entry, string $url): string
{
    $s = signage_presence_page_summary($entry, $url);
    if ($s === null) {
        return '';
    }

    $parts = [];
    if ($s['count'] > 0) {
        $parts[] = '×' . $s['count'] . ' today';
    }
    if ($s['last_time'] !== '') {
        $parts[] = 'last ' . $s['last_time'];
    } elseif ($s['last_ago'] !== '') {
        $parts[] = 'last ' . $s['last_ago'];
    }

    return implode(' · ', $parts);
}

/** @param array<string, array<string, mixed>> $all */
function signage_presence_play_log_merged(array $all, int $limit = 50): array
{
    $events = [];
    $screens = rotation_screens();

    foreach ($all as $screenKey => $entry) {
        if (!is_array($entry) || !is_array($entry['play_log'] ?? null)) {
            continue;
        }
        $screenKey = rotation_normalize_screen_key((string)$screenKey);
        $screenName = rotation_screen_display_name($screenKey, $screens);
        foreach ($entry['play_log'] as $ev) {
            if (!is_array($ev) || empty($ev['ts'])) {
                continue;
            }
            $url = (string)($ev['url'] ?? '');
            $events[] = [
                'ts' => (int)$ev['ts'],
                'time' => signage_presence_format_datetime((int)$ev['ts']),
                'screen' => $screenKey,
                'screen_name' => $screenName,
                'url' => $url,
                'label' => (string)($ev['label'] ?? '') ?: rotation_page_label($url),
            ];
        }
    }

    usort($events, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);

    return array_slice($events, 0, max(1, $limit));
}

function signage_presence_touch(string $screen, array $payload): void
{
    $screen = rotation_normalize_screen_key($screen);
    $now = time();
    $today = signage_presence_stats_day();

    $isBlank = !empty($payload['blank']);
    $status = trim((string)($payload['status'] ?? ''));
    $pageUrl = $isBlank ? '' : trim((string)($payload['page_url'] ?? ''));
    $pageLabel = trim((string)($payload['page_label'] ?? ''));
    if ($pageLabel === '' && $pageUrl !== '') {
        $pageLabel = rotation_page_label($pageUrl);
    }

    signage_json_file_update(signage_presence_path(), function (array $all) use (
        $screen,
        $now,
        $today,
        $isBlank,
        $status,
        $pageUrl,
        $pageLabel,
        $payload
    ): array {
        $prev = is_array($all[$screen] ?? null) ? $all[$screen] : [];

        $playsToday = (int)($prev['plays_today'] ?? 0);
        $playsTotal = (int)($prev['plays_total'] ?? 0);
        $pageStats = is_array($prev['page_stats'] ?? null) ? $prev['page_stats'] : [];
        $statsDay = (string)($prev['stats_day'] ?? '');
        $playLog = is_array($prev['play_log'] ?? null) ? $prev['play_log'] : [];
        $pageLast = is_array($prev['page_last'] ?? null) ? $prev['page_last'] : [];

        if ($statsDay !== $today) {
            $playsToday = 0;
            $pageStats = [];
            $statsDay = $today;
        }

        $lastPlayUrl = (string)($prev['last_play_url'] ?? '');
        if (!$isBlank && $pageUrl !== '' && $status === 'on screen' && $pageUrl !== $lastPlayUrl) {
            $playsToday++;
            $playsTotal++;
            $pageStats[$pageUrl] = (int)($pageStats[$pageUrl] ?? 0) + 1;
            $pageStats = signage_presence_trim_page_stats($pageStats);
            $lastPlayUrl = $pageUrl;
            $pageLast[$pageUrl] = $now;
            array_unshift($playLog, [
                'ts' => $now,
                'url' => $pageUrl,
                'label' => $pageLabel,
            ]);
            if (count($playLog) > SIGNAGE_PLAY_LOG_MAX) {
                $playLog = array_slice($playLog, 0, SIGNAGE_PLAY_LOG_MAX);
            }
        }
        if ($isBlank) {
            $lastPlayUrl = '';
        }

        $all[$screen] = [
            'screen' => $screen,
            'last_seen' => $now,
            'blank' => $isBlank,
            'page_url' => $pageUrl,
            'page_label' => $pageLabel,
            'page_index' => (int)($payload['page_index'] ?? -1),
            'page_total' => (int)($payload['page_total'] ?? 0),
            'status' => $status,
            'revision' => trim((string)($payload['revision'] ?? '')),
            'plays_today' => $playsToday,
            'plays_total' => $playsTotal,
            'stats_day' => $statsDay,
            'page_stats' => $pageStats,
            'page_last' => $pageLast,
            'play_log' => $playLog,
            'last_play_url' => $lastPlayUrl,
            'first_seen' => (int)($prev['first_seen'] ?? $now),
        ];

        return $all;
    }, [
        'default' => [],
        'ensure_dir' => true,
        'lock_wait_sec' => 3.0,
    ]);
}

/** @return array<string, mixed> */
function signage_presence_dashboard(): array
{
    $screens = rotation_screens();
    $all = signage_presence_read_all();
    $out = [];

    foreach ($screens as $key => $meta) {
        $key = rotation_normalize_screen_key((string)$key);
        $entry = is_array($all[$key] ?? null) ? $all[$key] : null;
        $online = signage_presence_online($entry);

        $topToday = [];
        if ($entry && is_array($entry['page_stats'] ?? null)) {
            $stats = $entry['page_stats'];
            arsort($stats);
            $n = 0;
            foreach ($stats as $url => $cnt) {
                if ($n >= 5) {
                    break;
                }
                $topToday[] = [
                    'url' => (string)$url,
                    'label' => rotation_page_label((string)$url),
                    'count' => (int)$cnt,
                ];
                $n++;
            }
        }

        $nowLabel = '';
        $nowUrl = '';
        $nowStatus = '';
        if ($entry) {
            if (!empty($entry['blank'])) {
                $nowLabel = 'Blank (scheduled off)';
                $nowStatus = 'blank';
            } elseif ($online && (string)($entry['page_url'] ?? '') !== '') {
                $nowUrl = (string)$entry['page_url'];
                $nowLabel = (string)($entry['page_label'] ?? '') ?: rotation_page_label($nowUrl);
                $nowStatus = (string)($entry['status'] ?? '');
            } elseif ($online && (int)($entry['page_total'] ?? 0) === 0) {
                $nowLabel = 'No pages in rotation';
                $nowStatus = 'empty';
            } elseif ($online) {
                $nowStatus = (string)($entry['status'] ?? '');
            }
        }

        $out[] = [
            'screen' => $key,
            'name' => rotation_screen_display_name($key, $screens),
            'online' => $online,
            'last_seen' => $entry['last_seen'] ?? null,
            'last_seen_ago' => signage_presence_format_ago($entry['last_seen'] ?? null),
            'blank' => !empty($entry['blank']),
            'now' => [
                'label' => $nowLabel,
                'url' => $nowUrl,
                'status' => $nowStatus,
                'index' => (int)($entry['page_index'] ?? -1),
                'total' => (int)($entry['page_total'] ?? 0),
            ],
            'plays_today' => (int)($entry['plays_today'] ?? 0),
            'plays_total' => (int)($entry['plays_total'] ?? 0),
            'top_today' => $topToday,
        ];
    }

    return [
        'screens' => $out,
        'play_log' => signage_presence_play_log_merged($all, 60),
        'stale_sec' => SIGNAGE_PRESENCE_STALE_SEC,
        'stats_day' => signage_presence_stats_day(),
        'generated' => time(),
    ];
}
