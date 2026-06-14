<?php
/**
 * Detroit sports board — ESPN site API helpers.
 * Unofficial public endpoints; cached server-side in sports.php.
 */

/** @return list<array<string,mixed>> */
function sports_default_teams(): array
{
    return [
        ['key' => 'lions',  'name' => 'Lions',     'abbrev' => 'DET', 'sport' => 'football',   'league' => 'nfl', 'team_id' => '8', 'accent' => '#0076B6', 'label' => 'NFL'],
        ['key' => 'tigers', 'name' => 'Tigers',    'abbrev' => 'DET', 'sport' => 'baseball',   'league' => 'mlb', 'team_id' => '6', 'accent' => '#0C2340', 'label' => 'MLB'],
        ['key' => 'pistons','name' => 'Pistons',   'abbrev' => 'DET', 'sport' => 'basketball', 'league' => 'nba', 'team_id' => '8', 'accent' => '#C8102E', 'label' => 'NBA'],
        ['key' => 'wings',  'name' => 'Red Wings', 'abbrev' => 'DET', 'sport' => 'hockey',     'league' => 'nhl', 'team_id' => '5', 'accent' => '#CE1126', 'label' => 'NHL'],
    ];
}

/** Approximate in-season months (1–12) for each league path. Includes preseason/playoffs. */
function sports_league_season_months(string $league): array
{
    return match ($league) {
        'nfl' => [8, 9, 10, 11, 12, 1, 2],           // Aug – Super Bowl
        'mlb' => [3, 4, 5, 6, 7, 8, 9, 10],           // Mar – World Series
        'nba', 'nhl' => [10, 11, 12, 1, 2, 3, 4, 5],   // Jun off unless a finals game is active
        default => range(1, 12),
    };
}

function sports_league_opens_label(string $league): string
{
    return match ($league) {
        'nfl' => 'Opens August',
        'mlb' => 'Opens late March',
        'nba' => 'Opens October',
        'nhl' => 'Opens October',
        default => 'Season TBD',
    };
}

function sports_league_in_season(string $league, ?DateTimeInterface $when = null): bool
{
    $when ??= new DateTimeImmutable('now');
    $month = (int)$when->format('n');
    return in_array($month, sports_league_season_months($league), true);
}

function sports_espn_url(string $sport, string $league, string ...$parts): string
{
    $path = implode('/', array_map('rawurlencode', array_merge(['sports', $sport, $league], $parts)));
    return 'https://site.api.espn.com/apis/site/v2/' . $path;
}

function sports_cached_json(string $url, string $cacheKey, int $ttl): ?array
{
    $dir = defined('SPORTS_CACHE_DIR') ? SPORTS_CACHE_DIR : (__DIR__ . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $f = $dir . '/' . $cacheKey . '.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            return $d;
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'HomeSignage/SportsBoard/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d)) {
            @file_put_contents($f, $body, LOCK_EX);
            return $d;
        }
    }
    $GLOBALS['diag'][$cacheKey] = $err !== '' ? "curl: $err" : "HTTP $code";
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/** @return array<string,array> scoreboard events keyed by team id */
function sports_scoreboard_by_team(string $sport, string $league, int $ttl): array
{
    $url = sports_espn_url($sport, $league, 'scoreboard');
    $data = sports_cached_json($url, "espn_sb_{$league}", $ttl);
    $out = [];
    foreach ($data['events'] ?? [] as $event) {
        if (!is_array($event)) {
            continue;
        }
        foreach ($event['competitions'][0]['competitors'] ?? [] as $comp) {
            $tid = (string)($comp['team']['id'] ?? '');
            if ($tid !== '') {
                $out[$tid] = $event;
            }
        }
    }
    return $out;
}

/** @param array<string,mixed> $teamCfg */
function sports_fetch_team_profile(array $teamCfg, int $ttl): ?array
{
    $sport = (string)$teamCfg['sport'];
    $league = (string)$teamCfg['league'];
    $teamId = (string)$teamCfg['team_id'];
    $url = sports_espn_url($sport, $league, 'teams', $teamId);
    return sports_cached_json($url, "espn_team_{$league}_{$teamId}", $ttl);
}

/** @param array<string,mixed> $teamCfg @return list<array> */
function sports_fetch_schedule_events(array $teamCfg, int $ttl): array
{
    $sport = (string)$teamCfg['sport'];
    $league = (string)$teamCfg['league'];
    $teamId = (string)$teamCfg['team_id'];
    $url = sports_espn_url($sport, $league, 'teams', $teamId, 'schedule');
    $data = sports_cached_json($url, "espn_sched_{$league}_{$teamId}", $ttl);
    $events = $data['events'] ?? [];
    return is_array($events) ? $events : [];
}

/** ESPN score fields are sometimes strings, sometimes {displayValue,value}. */
function sports_score_str(mixed $score): ?string
{
    if (is_array($score)) {
        $v = $score['displayValue'] ?? $score['value'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return trim((string)$v);
    }
    $s = trim((string)$score);
    return $s !== '' ? $s : null;
}

/** @param array<string,mixed> $event @param array<string,mixed> $teamCfg */
function sports_parse_event(array $event, array $teamCfg): ?array
{
    $comp = $event['competitions'][0] ?? null;
    if (!is_array($comp)) {
        return null;
    }
    $teamId = (string)$teamCfg['team_id'];
    $us = null;
    $them = null;
    foreach ($comp['competitors'] ?? [] as $c) {
        if (!is_array($c)) {
            continue;
        }
        if ((string)($c['team']['id'] ?? '') === $teamId) {
            $us = $c;
        } else {
            $them = $c;
        }
    }
    if ($us === null || $them === null) {
        return null;
    }

    $status = $comp['status']['type'] ?? [];
    $state = (string)($status['state'] ?? 'pre');
    $usScore = sports_score_str($us['score'] ?? null);
    $themScore = sports_score_str($them['score'] ?? null);
    $won = null;
    if ($state === 'post' && $usScore !== null && $themScore !== null) {
        $won = (int)$usScore > (int)$themScore;
    } elseif ($state === 'post' && !empty($us['winner'])) {
        $won = true;
    } elseif ($state === 'post' && !empty($them['winner'])) {
        $won = false;
    }

    $home = ($us['homeAway'] ?? '') === 'home';
    $oppAbbr = (string)($them['team']['abbreviation'] ?? '?');
    $oppName = (string)($them['team']['displayName'] ?? $oppAbbr);

    return [
        'state' => $state,
        'status' => (string)($status['shortDetail'] ?? $status['description'] ?? ''),
        'date' => (string)($event['date'] ?? ''),
        'home' => $home,
        'opponent_abbr' => $oppAbbr,
        'opponent_name' => $oppName,
        'matchup' => ($home ? 'vs ' : '@ ') . $oppAbbr,
        'us_score' => $usScore,
        'them_score' => $themScore,
        'won' => $won,
        'short_name' => (string)($event['shortName'] ?? $event['name'] ?? ''),
    ];
}

/** Pick the best event to show: live → today → next → last. */
function sports_pick_event(array $events, array $teamCfg, ?array $scoreboardEvent, DateTimeInterface $now): ?array
{
    $today = $now->format('Y-m-d');
    $teamId = (string)$teamCfg['team_id'];
    $nowTs = $now->getTimestamp();

    if ($scoreboardEvent !== null) {
        $parsed = sports_parse_event($scoreboardEvent, $teamCfg);
        if ($parsed !== null && in_array($parsed['state'], ['in', 'post'], true)) {
            return $parsed;
        }
        if ($parsed !== null && $parsed['state'] === 'pre') {
            $gameDay = substr($parsed['date'], 0, 10);
            if ($gameDay === $today) {
                return $parsed;
            }
        }
    }

    $live = null;
    $next = null;
    $last = null;
    $nextTs = PHP_INT_MAX;
    $lastTs = 0;

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $parsed = sports_parse_event($event, $teamCfg);
        if ($parsed === null) {
            continue;
        }
        $ts = strtotime($parsed['date']) ?: 0;
        if ($parsed['state'] === 'in') {
            $live = $parsed;
        } elseif ($parsed['state'] === 'post' && $ts >= $lastTs) {
            $last = $parsed;
            $lastTs = $ts;
        } elseif ($parsed['state'] === 'pre' && $ts >= $nowTs && $ts < $nextTs) {
            $next = $parsed;
            $nextTs = $ts;
        }
    }

    if ($live !== null) {
        return $live;
    }
    if ($next !== null) {
        return $next;
    }
    if ($last !== null) {
        return $last;
    }

    return null;
}

function sports_format_game_time(string $iso, DateTimeZone $tz): string
{
    if ($iso === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        $local = $dt->setTimezone($tz);
        $now = new DateTimeImmutable('now', $tz);
        if ($local->format('Y-m-d') === $now->format('Y-m-d')) {
            return $local->format('g:i A');
        }
        if ($local->format('Y') === $now->format('Y')) {
            return $local->format('D g:i A');
        }
        return $local->format('M j · g:i A');
    } catch (Exception) {
        return '—';
    }
}

/** @param array<string,mixed> $teamCfg @param array<string,array> $scoreboardsByLeague */
function sports_build_team_card(array $teamCfg, array $scoreboardsByLeague, int $ttl, DateTimeZone $tz): array
{
    $league = (string)$teamCfg['league'];
    $teamId = (string)$teamCfg['team_id'];
    $now = new DateTimeImmutable('now', $tz);
    $calendarSeason = sports_league_in_season($league, $now);

    $profile = sports_fetch_team_profile($teamCfg, $ttl);
    $teamNode = is_array($profile['team'] ?? null) ? $profile['team'] : [];
    $record = (string)($teamNode['record']['items'][0]['summary'] ?? '');
    $standing = (string)($teamNode['standingSummary'] ?? '');

    $schedule = sports_fetch_schedule_events($teamCfg, $ttl);
    $sb = $scoreboardsByLeague[$league][$teamId] ?? null;
    $game = sports_pick_event($schedule, $teamCfg, $sb, $now);

    if ($game === null && !empty($teamNode['nextEvent'][0]) && is_array($teamNode['nextEvent'][0])) {
        $fallback = sports_parse_event($teamNode['nextEvent'][0], $teamCfg);
        if ($fallback !== null) {
            $fbTs = strtotime($fallback['date']) ?: 0;
            $fbDays = $fbTs > 0 ? (int)round(($fbTs - $now->getTimestamp()) / 86400) : 999;
            if ($fallback['state'] === 'pre' && $fbDays >= 0) {
                $game = $fallback;
            } elseif ($fallback['state'] === 'post' && $fbDays >= -14) {
                $game = $fallback;
            }
        }
    }

    if ($game !== null) {
        $gameTs = strtotime($game['date']) ?: 0;
        $daysAway = $gameTs > 0 ? (int)round(($gameTs - $now->getTimestamp()) / 86400) : 999;
        if ($game['state'] === 'post' && $daysAway < -14) {
            $game = null;
        }
    }

    $activeSeason = $calendarSeason;
    if ($game !== null) {
        $gameTs = strtotime($game['date']) ?: 0;
        $daysAway = $gameTs > 0 ? (int)round(($gameTs - $now->getTimestamp()) / 86400) : 999;
        if ($game['state'] === 'in' || ($game['state'] === 'pre' && $daysAway <= 21)) {
            $activeSeason = true;
        }
        if ($game['state'] === 'post' && $daysAway >= -7) {
            $activeSeason = true;
        }
    }

    $mode = 'off';
    $headline = sports_league_opens_label($league);
    $detail = $record !== '' ? $record . ($standing !== '' ? ' · ' . $standing : '') : ($standing !== '' ? $standing : '');

    if ($game !== null) {
        if ($game['state'] === 'in') {
            $mode = 'live';
            $headline = ($game['us_score'] ?? '0') . ' – ' . ($game['them_score'] ?? '0');
            $detail = $game['status'] !== '' ? $game['status'] : 'Live';
        } elseif ($game['state'] === 'post') {
            $mode = 'final';
            $headline = ($game['us_score'] ?? '—') . ' – ' . ($game['them_score'] ?? '—');
            $result = $game['won'] === true ? 'Win' : ($game['won'] === false ? 'Loss' : 'Final');
            $detail = $result . ' · ' . $game['matchup'];
            if ($game['status'] !== '' && stripos($game['status'], 'final') === false) {
                $detail = $result . ' · ' . $game['status'];
            }
        } else {
            $mode = 'next';
            $headline = $game['matchup'];
            $detail = sports_format_game_time($game['date'], $tz);
            if ($record !== '') {
                $detail .= ' · ' . $record;
            }
        }
    } elseif ($record !== '') {
        $headline = $record;
        $detail = $standing !== '' ? $standing : sports_league_opens_label($league);
    }

    $badge = match (true) {
        $mode === 'live' => 'Live',
        $mode === 'final' => 'Final',
        $mode === 'next' => 'Up next',
        !$activeSeason => 'Off season',
        default => 'In season',
    };

    return [
        'key' => (string)$teamCfg['key'],
        'name' => (string)$teamCfg['name'],
        'league' => (string)($teamCfg['label'] ?? strtoupper($league)),
        'accent' => (string)($teamCfg['accent'] ?? '#ffb347'),
        'badge' => $badge,
        'mode' => $mode,
        'active_season' => $activeSeason,
        'headline' => $headline,
        'detail' => $detail,
        'record' => $record,
        'standing' => $standing,
        'game' => $game,
    ];
}

/** @param list<array<string,mixed>> $teams */
function sports_board_summary(array $cards): array
{
    $live = [];
    $active = [];
    foreach ($cards as $c) {
        if (($c['mode'] ?? '') === 'live') {
            $live[] = $c['name'];
        }
        if (!empty($c['active_season'])) {
            $active[] = $c['name'];
        }
    }
    if ($live !== []) {
        return ['Live now', implode(' · ', $live) . ' on the air', '#ff5d5d'];
    }
    if ($active !== []) {
        return ['In season', implode(', ', $active) . ' — check matchups below', 'var(--beacon)'];
    }
    return ['Off season', 'All four teams between seasons — records shown where available', 'var(--mist)'];
}
