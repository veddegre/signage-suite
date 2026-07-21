<?php
/**
 * Detroit sports board — ESPN site API helpers.
 * Unofficial public endpoints; cached server-side in sports.php.
 */

/** @return list<array<string,mixed>> */
function sports_default_teams(): array
{
    return [
        ['key' => 'lions',  'name' => 'Lions',     'abbrev' => 'DET', 'sport' => 'football',   'league' => 'nfl', 'team_id' => '8', 'accent' => '#0076B6', 'label' => 'NFL', 'icon' => 'football'],
        ['key' => 'tigers', 'name' => 'Tigers',    'abbrev' => 'DET', 'sport' => 'baseball',   'league' => 'mlb', 'team_id' => '6', 'accent' => '#FA4616', 'label' => 'MLB', 'icon' => 'baseball'],
        ['key' => 'pistons','name' => 'Pistons',   'abbrev' => 'DET', 'sport' => 'basketball', 'league' => 'nba', 'team_id' => '8', 'accent' => '#C8102E', 'label' => 'NBA', 'icon' => 'basketball'],
        ['key' => 'wings',  'name' => 'Red Wings', 'abbrev' => 'DET', 'sport' => 'hockey',     'league' => 'nhl', 'team_id' => '5', 'accent' => '#FFFFFF', 'label' => 'NHL', 'icon' => 'hockey'],
    ];
}

/** @return list<array{sport:string,league:string,label:string,icon:string,months:list<int>,opens:string}> */
function sports_league_definitions(): array
{
    return [
        ['sport' => 'football', 'league' => 'nfl', 'label' => 'NFL', 'icon' => 'football', 'months' => [8, 9, 10, 11, 12, 1, 2], 'opens' => 'Opens late August'],
        ['sport' => 'football', 'league' => 'college-football', 'label' => 'NCAAF', 'icon' => 'football', 'months' => [8, 9, 10, 11, 12, 1], 'opens' => 'Opens late August'],
        ['sport' => 'baseball', 'league' => 'mlb', 'label' => 'MLB', 'icon' => 'baseball', 'months' => [3, 4, 5, 6, 7, 8, 9, 10], 'opens' => 'Opens late March'],
        ['sport' => 'basketball', 'league' => 'nba', 'label' => 'NBA', 'icon' => 'basketball', 'months' => [10, 11, 12, 1, 2, 3, 4, 5, 6], 'opens' => 'Opens October'],
        ['sport' => 'basketball', 'league' => 'wnba', 'label' => 'WNBA', 'icon' => 'basketball', 'months' => [5, 6, 7, 8, 9, 10], 'opens' => 'Opens May'],
        ['sport' => 'basketball', 'league' => 'mens-college-basketball', 'label' => 'NCAAM', 'icon' => 'basketball', 'months' => [11, 12, 1, 2, 3, 4], 'opens' => 'Opens November'],
        ['sport' => 'basketball', 'league' => 'womens-college-basketball', 'label' => 'NCAAW', 'icon' => 'basketball', 'months' => [11, 12, 1, 2, 3, 4], 'opens' => 'Opens November'],
        ['sport' => 'hockey', 'league' => 'nhl', 'label' => 'NHL', 'icon' => 'hockey', 'months' => [10, 11, 12, 1, 2, 3, 4, 5, 6], 'opens' => 'Opens October'],
        ['sport' => 'soccer', 'league' => 'usa.1', 'label' => 'MLS', 'icon' => 'soccer', 'months' => [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'opens' => 'Opens February'],
    ];
}

/** @return array<string,list<array{key:string,label:string}>> Grouped for admin selects. */
function sports_team_catalog_groups(): array
{
    $groups = [];
    foreach (sports_league_definitions() as $def) {
        $groups[(string)$def['label']] = [];
    }
    foreach (sports_team_catalog() as $key => $team) {
        $label = (string)($team['label'] ?? strtoupper((string)($team['league'] ?? '')));
        if (!isset($groups[$label])) {
            $groups[$label] = [];
        }
        $name = trim((string)($team['name'] ?? $key));
        $abbr = trim((string)($team['abbrev'] ?? ''));
        $groups[$label][] = [
            'key' => (string)$key,
            'label' => $abbr !== '' ? ($name . ' (' . $abbr . ')') : $name,
        ];
    }
    foreach ($groups as $label => $items) {
        usort($items, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        $groups[$label] = $items;
    }

    return $groups;
}

/** @return array<string,array<string,mixed>> Catalog key => team config (cached ESPN + legacy aliases). */
function sports_team_catalog(bool $refresh = false): array
{
    $cacheFile = SIGNAGE_ROOT . '/cache/sports_team_catalog.json';
    $ttl = 86400 * 7;
    if (!$refresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
    }

    $catalog = [];
    foreach (sports_default_teams() as $team) {
        $catalog[(string)$team['key']] = $team;
    }

    foreach (sports_league_definitions() as $def) {
        $sport = (string)$def['sport'];
        $league = (string)$def['league'];
        $label = (string)$def['label'];
        $icon = (string)$def['icon'];
        $url = sports_espn_url($sport, $league, 'teams');
        $data = sports_cached_json($url, 'espn_catalog_' . $league, 3600);
        $rows = $data['sports'][0]['leagues'][0]['teams'] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $wrap) {
            if (!is_array($wrap)) {
                continue;
            }
            $team = is_array($wrap['team'] ?? null) ? $wrap['team'] : $wrap;
            $teamId = trim((string)($team['id'] ?? ''));
            if ($teamId === '') {
                continue;
            }
            $slug = trim((string)($team['slug'] ?? ''));
            $abbrev = strtoupper(trim((string)($team['abbreviation'] ?? '')));
            $display = trim((string)($team['displayName'] ?? $team['name'] ?? $abbrev));
            if ($display === '') {
                continue;
            }
            $key = $league . '_' . ($slug !== '' ? $slug : strtolower($abbrev));
            $color = trim((string)($team['color'] ?? ''));
            $accent = $color !== '' ? ('#' . ltrim($color, '#')) : '#ffb347';
            $shortName = trim((string)($team['shortDisplayName'] ?? $team['name'] ?? $display));
            $catalog[$key] = [
                'key' => $key,
                'name' => $shortName !== '' ? $shortName : $display,
                'abbrev' => $abbrev !== '' ? $abbrev : strtoupper(substr($key, 0, 3)),
                'sport' => $sport,
                'league' => $league,
                'team_id' => $teamId,
                'accent' => $accent,
                'label' => $label,
                'icon' => $icon,
            ];
        }
    }

    if ($catalog !== []) {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($catalog, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $catalog;
}

/** @param list<string> $keys @return list<array<string,mixed>> */
function sports_teams_from_keys(array $keys): array
{
    $catalog = sports_team_catalog();
    $out = [];
    foreach ($keys as $key) {
        $key = trim((string)$key);
        if ($key === '' || !isset($catalog[$key])) {
            continue;
        }
        $out[] = $catalog[$key];
        if (count($out) >= 4) {
            break;
        }
    }

    return $out;
}

/** Teams for a rotation display — site default when no override. @return list<array<string,mixed>> */
function sports_teams_for_screen(string $screen): array
{
    require_once __DIR__ . '/screen_scope_lib.php';
    $keys = rotation_screen_sports_team_keys($screen);
    if ($keys === []) {
        return sports_default_teams();
    }
    $teams = sports_teams_from_keys($keys);

    return $teams !== [] ? $teams : sports_default_teams();
}

/** Approximate in-season months (1–12) for each league path. Includes preseason/playoffs. */
function sports_league_season_months(string $league): array
{
    foreach (sports_league_definitions() as $def) {
        if ((string)$def['league'] === $league) {
            return $def['months'];
        }
    }

    return range(1, 12);
}

function sports_league_opens_label(string $league): string
{
    foreach (sports_league_definitions() as $def) {
        if ((string)$def['league'] === $league) {
            return (string)$def['opens'];
        }
    }

    return 'Season TBD';
}

/** Human label for a future game when the team is still off-season. */
function sports_future_game_label(array $game): string
{
    $type = strtolower((string)($game['season_type'] ?? ''));
    if (str_contains($type, 'preseason') || str_contains($type, 'pre-season')) {
        return 'Preseason';
    }
    if (str_contains($type, 'regular')) {
        return 'Season opener';
    }
    return 'Next game';
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
    $dir = defined('SPORTS_CACHE_DIR') ? SPORTS_CACHE_DIR : (SIGNAGE_ROOT . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $f = $dir . '/' . $cacheKey . '.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            sports_note_cache_age($cacheKey, (int)filemtime($f));
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
    if ($body !== false && $code === 200) {
        $d = json_decode($body, true);
        if (is_array($d)) {
            @file_put_contents($f, $body, LOCK_EX);
            sports_note_cache_age($cacheKey, time());
            return $d;
        }
    }
    $GLOBALS['diag'][$cacheKey] = $err !== '' ? "curl: $err" : "HTTP $code";
    if (is_file($f)) {
        sports_note_cache_age($cacheKey, (int)filemtime($f));
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

function sports_note_cache_age(string $cacheKey, int $mtime): void
{
    if (!isset($GLOBALS['sports_cache_ages']) || !is_array($GLOBALS['sports_cache_ages'])) {
        $GLOBALS['sports_cache_ages'] = [];
    }
    $age = max(0, time() - $mtime);
    $prev = (int)($GLOBALS['sports_cache_ages']['_max'] ?? 0);
    if ($age > $prev) {
        $GLOBALS['sports_cache_ages']['_max'] = $age;
    }
    $GLOBALS['sports_cache_ages'][$cacheKey] = $age;
}

/** Oldest cache entry age in seconds (0 = fresh). */
function sports_cache_max_age(): int
{
    return (int)($GLOBALS['sports_cache_ages']['_max'] ?? 0);
}

function sports_format_cache_age(int $seconds): string
{
    if ($seconds <= 0) {
        return 'just now';
    }
    if ($seconds < 60) {
        return $seconds . 's ago';
    }
    if ($seconds < 3600) {
        return (int)round($seconds / 60) . 'm ago';
    }

    return (int)round($seconds / 3600) . 'h ago';
}

/** @param list<array<string,mixed>> $teams @return array<string,array<string,array>> */
function sports_scoreboards_for_teams(array $teams, int $ttl): array
{
    $leagues = [];
    foreach ($teams as $teamCfg) {
        if (!is_array($teamCfg)) {
            continue;
        }
        $league = (string)($teamCfg['league'] ?? '');
        $sport = (string)($teamCfg['sport'] ?? '');
        if ($league !== '' && $sport !== '') {
            $leagues[$league] = $sport;
        }
    }
    $out = [];
    foreach ($leagues as $league => $sport) {
        $out[$league] = sports_scoreboard_by_team($sport, $league, $ttl);
    }

    return $out;
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

/**
 * Where-to-watch labels from ESPN competition.broadcasts (national TV preferred, then home/away regional).
 * @return list<string>
 */
function sports_broadcast_labels(?array $comp, ?bool $teamIsHome = null, int $limit = 2): array
{
    if (!is_array($comp) || $limit < 1) {
        return [];
    }
    $nationalTv = [];
    $nationalOther = [];
    $homeTv = [];
    $homeOther = [];
    $awayTv = [];
    $awayOther = [];
    foreach ($comp['broadcasts'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['media']['shortName'] ?? ''));
        if ($name === '') {
            continue;
        }
        $market = strtolower(trim((string)($row['market']['type'] ?? '')));
        $type = strtolower(trim((string)($row['type']['shortName'] ?? '')));
        $isTv = $type === 'tv';
        if ($market === 'national') {
            if ($isTv) {
                $nationalTv[] = $name;
            } else {
                $nationalOther[] = $name;
            }
        } elseif ($market === 'home') {
            if ($isTv) {
                $homeTv[] = $name;
            } else {
                $homeOther[] = $name;
            }
        } elseif ($market === 'away') {
            if ($isTv) {
                $awayTv[] = $name;
            } else {
                $awayOther[] = $name;
            }
        }
    }
    foreach ($comp['geoBroadcasts'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['media']['shortName'] ?? ''));
        if ($name === '') {
            continue;
        }
        $market = strtolower(trim((string)($row['market']['type'] ?? '')));
        if ($market === 'home') {
            $homeTv[] = $name;
        } elseif ($market === 'away') {
            $awayTv[] = $name;
        } else {
            $nationalTv[] = $name;
        }
    }

    $pick = static function (array ...$groups): array {
        foreach ($groups as $group) {
            if ($group !== []) {
                return array_values(array_unique($group));
            }
        }

        return [];
    };

    $ordered = $pick(
        $nationalTv,
        $teamIsHome === true ? $homeTv : [],
        $teamIsHome === false ? $awayTv : [],
        $homeTv,
        $awayTv,
        $nationalOther,
        $teamIsHome === true ? $homeOther : [],
        $teamIsHome === false ? $awayOther : [],
        $homeOther,
        $awayOther,
    );

    return array_slice($ordered, 0, $limit);
}

function sports_broadcast_line(?array $comp, ?bool $teamIsHome = null, int $limit = 2): string
{
    return implode(' · ', sports_broadcast_labels($comp, $teamIsHome, $limit));
}

function sports_append_game_broadcast(string $detail, array $game): string
{
    $watch = trim((string)($game['broadcast'] ?? ''));
    if ($watch === '') {
        return $detail;
    }

    return $detail !== '' ? ($detail . ' · ' . $watch) : $watch;
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
    $seasonType = (string)($event['seasonType']['name'] ?? $comp['seasonType']['name'] ?? '');
    $broadcast = sports_broadcast_line($comp, $home);
    $oppTeamNode = is_array($them['team'] ?? null) ? $them['team'] : [];
    $clock = trim((string)($status['shortDetail'] ?? ''));
    if ($clock === '') {
        $clock = trim((string)($status['detail'] ?? ''));
    }

    return [
        'state' => $state,
        'status' => (string)($status['shortDetail'] ?? $status['description'] ?? ''),
        'clock' => $clock,
        'date' => (string)($event['date'] ?? ''),
        'home' => $home,
        'opponent_abbr' => $oppAbbr,
        'opponent_name' => $oppName,
        'opponent_logo' => sports_team_logo_url($oppTeamNode),
        'matchup' => ($home ? 'vs ' : '@ ') . $oppAbbr,
        'us_score' => $usScore,
        'them_score' => $themScore,
        'won' => $won,
        'short_name' => (string)($event['shortName'] ?? $event['name'] ?? ''),
        'season_type' => $seasonType,
        'broadcast' => $broadcast,
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
        $today = $now->setTime(0, 0);
        $gameDay = $local->setTime(0, 0);
        $daysAway = (int)$today->diff($gameDay)->format('%r%a');

        if ($daysAway === 0) {
            return 'Today · ' . $local->format('g:i A');
        }
        if ($daysAway === 1) {
            return 'Tomorrow · ' . $local->format('g:i A');
        }
        // Weekday-only labels read like “this Sun” when the game is weeks/months out.
        if ($daysAway > 6) {
            if ($local->format('Y') === $now->format('Y')) {
                return $local->format('M j · g:i A');
            }
            return $local->format('M j, Y · g:i A');
        }
        return $local->format('D · g:i A');
    } catch (Exception) {
        return '—';
    }
}

/** Prefer scoreboard/dark logos — readable on navy signage backgrounds. */
function sports_team_logo_url(array $teamNode): ?string
{
    $logos = $teamNode['logos'] ?? [];
    if (!is_array($logos)) {
        return null;
    }
    $preferred = [];
    $fallback = null;
    foreach ($logos as $logo) {
        if (!is_array($logo)) {
            continue;
        }
        $href = trim((string)($logo['href'] ?? ''));
        if ($href === '') {
            continue;
        }
        if ($fallback === null) {
            $fallback = $href;
        }
        if (str_contains($href, 'scoreboard') || str_contains($href, '500-dark')) {
            $preferred[] = $href;
        }
    }
    return $preferred[0] ?? $fallback;
}

function sports_standings_line(string $record, string $standing): string
{
    if ($record !== '' && $standing !== '') {
        return $record . ' · ' . $standing;
    }
    return $record !== '' ? $record : $standing;
}

/** Next scheduled game only — independent of the featured live/final card. */
function sports_pick_next_event(array $events, array $teamCfg, DateTimeInterface $now): ?array
{
    $nowTs = $now->getTimestamp();
    $next = null;
    $nextTs = PHP_INT_MAX;
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $parsed = sports_parse_event($event, $teamCfg);
        if ($parsed === null || $parsed['state'] !== 'pre') {
            continue;
        }
        $ts = strtotime($parsed['date']) ?: 0;
        if ($ts >= $nowTs && $ts < $nextTs) {
            $next = $parsed;
            $nextTs = $ts;
        }
    }
    return $next;
}

/** Decorative sport glyph for cards (inline SVG). */
function sports_sport_icon_svg(string $icon): string
{
    return match ($icon) {
        'football' => '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="32" rx="26" ry="16" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M20 24c8 4 16 4 24 0M20 40c8-4 16-4 24 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="32" y1="16" x2="32" y2="48" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'baseball' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M18 18c6 10 6 18 0 28M46 18c-6 10-6 18 0 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'basketball' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M8 32h48M32 8v48M14 14c12 10 24 10 36 0M14 50c12-10 24-10 36 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        'hockey' => '<svg viewBox="0 0 64 64" aria-hidden="true"><rect x="10" y="28" width="34" height="8" rx="2" fill="currentColor" opacity=".9"/><path d="M44 32h8l4 10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="18" cy="32" r="3" fill="currentColor"/></svg>',
        'soccer' => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M32 8c8 8 8 40 0 48M32 8c-8 8-8 40 0 48M8 32h48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polygon points="32,14 38,22 26,22" fill="currentColor" opacity=".35"/></svg>',
        default => '<svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="24" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>',
    };
}

/** @param list<array<string,mixed>> $cards @return list<array{team:string,league:string,logo:?string,icon:string,text:string,when:string}> */
function sports_next_game_strip(array $cards, DateTimeZone $tz): array
{
    $out = [];
    foreach ($cards as $card) {
        $next = is_array($card['next_game'] ?? null) ? $card['next_game'] : null;
        if ($next !== null) {
            $when = sports_format_game_time((string)$next['date'], $tz);
            $text = (string)$next['matchup'];
            if (empty($card['active_season'])) {
                $text = sports_future_game_label($next) . ' · ' . $text;
            }
            $broadcast = trim((string)($next['broadcast'] ?? ''));
            if ($broadcast !== '') {
                $when = $when !== '' && $when !== '—' ? ($when . ' · ' . $broadcast) : $broadcast;
            }
        } else {
            $when = '';
            $text = sports_league_opens_label((string)($card['league_key'] ?? ''));
        }
        $out[] = [
            'team' => (string)($card['name'] ?? ''),
            'league' => (string)($card['league'] ?? ''),
            'logo' => $card['logo_url'] ?? null,
            'icon' => (string)($card['icon'] ?? 'default'),
            'text' => $text,
            'when' => $when,
        ];
    }
    return $out;
}

/** @param array<string,mixed> $teamCfg @param array<string,array> $scoreboardsByLeague */
function sports_build_team_card(array $teamCfg, array $scoreboardsByLeague, int $ttl, DateTimeZone $tz): array
{
    $league = (string)$teamCfg['league'];
    $teamId = (string)$teamCfg['team_id'];
    $now = new DateTimeImmutable('now', $tz);
    $calendarSeason = sports_league_in_season($league, $now);
    $dataError = false;

    $profile = sports_fetch_team_profile($teamCfg, $ttl);
    if ($profile === null) {
        $dataError = true;
    }
    $teamNode = is_array($profile['team'] ?? null) ? $profile['team'] : [];
    $record = (string)($teamNode['record']['items'][0]['summary'] ?? '');
    $standing = (string)($teamNode['standingSummary'] ?? '');
    $playoffNote = sports_playoff_note($standing);

    $schedule = sports_fetch_schedule_events($teamCfg, $ttl);
    if ($schedule === [] && $profile === null) {
        $dataError = true;
    }
    $sb = $scoreboardsByLeague[$league][$teamId] ?? null;
    $game = sports_pick_event($schedule, $teamCfg, $sb, $now);
    $nextGame = sports_pick_next_event($schedule, $teamCfg, $now);
    $logoUrl = sports_team_logo_url($teamNode);
    $icon = (string)($teamCfg['icon'] ?? 'default');
    $streak = sports_recent_streak($schedule, $teamCfg);
    $recentResults = sports_recent_results($schedule, $teamCfg, 5);

    if ($nextGame === null && !empty($teamNode['nextEvent'][0]) && is_array($teamNode['nextEvent'][0])) {
        $fallbackNext = sports_parse_event($teamNode['nextEvent'][0], $teamCfg);
        if ($fallbackNext !== null && $fallbackNext['state'] === 'pre') {
            $fbTs = strtotime($fallbackNext['date']) ?: 0;
            if ($fbTs >= $now->getTimestamp()) {
                $nextGame = $fallbackNext;
            }
        }
    }

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
    $headline = $dataError ? 'Data unavailable' : sports_league_opens_label($league);
    $detail = $dataError
        ? 'ESPN feed temporarily unavailable'
        : ($record !== '' ? $record . ($standing !== '' ? ' · ' . $standing : '') : ($standing !== '' ? $standing : ''));
    $clockLine = '';
    $opponentLogo = null;
    $opponentAbbr = '';
    $usScore = null;
    $themScore = null;
    $resultClass = '';

    if ($game !== null) {
        $opponentLogo = $game['opponent_logo'] ?? null;
        $opponentAbbr = (string)($game['opponent_abbr'] ?? '');
        $usScore = $game['us_score'] ?? null;
        $themScore = $game['them_score'] ?? null;
        if ($game['state'] === 'in') {
            $mode = 'live';
            $headline = ($game['us_score'] ?? '0') . ' – ' . ($game['them_score'] ?? '0');
            $clockLine = trim((string)($game['clock'] ?? ''));
            $detail = sports_append_game_broadcast($clockLine !== '' ? '' : ($game['status'] !== '' ? $game['status'] : 'Live'), $game);
            if ($clockLine !== '' && trim($detail) === '') {
                $detail = sports_append_game_broadcast('', $game);
            } elseif ($clockLine !== '' && trim((string)($game['broadcast'] ?? '')) !== '') {
                $detail = trim((string)$game['broadcast']);
            }
        } elseif ($game['state'] === 'post') {
            $mode = 'final';
            $headline = ($game['us_score'] ?? '—') . ' – ' . ($game['them_score'] ?? '—');
            $resultClass = $game['won'] === true ? 'win' : ($game['won'] === false ? 'loss' : '');
            $result = $game['won'] === true ? 'Win' : ($game['won'] === false ? 'Loss' : 'Final');
            $detail = $result . ' · ' . $game['matchup'];
            if ($game['status'] !== '' && stripos($game['status'], 'final') === false) {
                $detail = $result . ' · ' . $game['status'];
            }
        } else {
            $gameTs = strtotime($game['date']) ?: 0;
            $daysAway = $gameTs > 0 ? (int)round(($gameTs - $now->getTimestamp()) / 86400) : 999;
            if (!$activeSeason && $daysAway > 21) {
                $mode = 'off';
                $when = sports_format_game_time($game['date'], $tz);
                $label = sports_future_game_label($game);
                $headline = $label . ' · ' . $game['matchup'];
                $detail = sports_append_game_broadcast($when, $game);
                if ($standing !== '') {
                    $detail .= ' · ' . $standing;
                } elseif ($record !== '') {
                    $detail .= ' · ' . $record;
                }
            } else {
                $mode = 'next';
                $headline = $game['matchup'];
                $detail = sports_append_game_broadcast(sports_format_game_time($game['date'], $tz), $game);
            }
        }
    } elseif ($record !== '') {
        $headline = $record;
        $detail = $standing !== '' ? $standing : sports_league_opens_label($league);
    }

    $standingsLine = '';
    if ($activeSeason && ($record !== '' || $standing !== '')) {
        $standingsLine = sports_standings_line($record, $standing);
        if ($playoffNote !== '') {
            $standingsLine = $standingsLine !== '' ? ($standingsLine . ' · ' . $playoffNote) : $playoffNote;
        }
    }

    $badge = match (true) {
        $dataError => 'Unavailable',
        $mode === 'live' => 'Live',
        $mode === 'final' => 'Final',
        !$activeSeason => 'Off season',
        $mode === 'next' => 'Up next',
        default => 'In season',
    };

    return [
        'key' => (string)$teamCfg['key'],
        'name' => (string)$teamCfg['name'],
        'league' => (string)($teamCfg['label'] ?? strtoupper($league)),
        'league_key' => $league,
        'accent' => (string)($teamCfg['accent'] ?? '#ffb347'),
        'icon' => $icon,
        'logo_url' => $logoUrl,
        'opponent_logo' => $opponentLogo,
        'opponent_abbr' => $opponentAbbr,
        'us_score' => $usScore,
        'them_score' => $themScore,
        'result_class' => $resultClass,
        'clock_line' => $clockLine,
        'badge' => $badge,
        'mode' => $mode,
        'active_season' => $activeSeason,
        'headline' => $headline,
        'detail' => $detail,
        'standings_line' => $standingsLine,
        'record' => $record,
        'standing' => $standing,
        'playoff_note' => $playoffNote,
        'streak' => $streak,
        'recent_results' => $recentResults,
        'data_error' => $dataError,
        'game' => $game,
        'next_game' => $nextGame,
    ];
}

function sports_playoff_note(string $standing): string
{
    $s = strtolower(trim($standing));
    if ($s === '') {
        return '';
    }
    foreach (['clinched', 'eliminated', 'playoff', 'postseason', 'wild card', 'seed'] as $needle) {
        if (str_contains($s, $needle)) {
            return trim($standing);
        }
    }

    return '';
}

/** @param list<array> $events @return list<string> */
function sports_recent_results(array $events, array $teamCfg, int $limit = 5): array
{
    $out = [];
    $rows = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $parsed = sports_parse_event($event, $teamCfg);
        if ($parsed === null || ($parsed['state'] ?? '') !== 'post') {
            continue;
        }
        $rows[] = $parsed;
    }
    usort($rows, static fn(array $a, array $b): int => (strtotime((string)$b['date']) ?: 0) <=> (strtotime((string)$a['date']) ?: 0));
    foreach (array_slice($rows, 0, $limit) as $game) {
        $mark = $game['won'] === true ? 'W' : ($game['won'] === false ? 'L' : '—');
        $out[] = $mark . ' ' . (string)$game['matchup'];
    }

    return $out;
}

function sports_recent_streak(array $events, array $teamCfg): string
{
    $rows = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $parsed = sports_parse_event($event, $teamCfg);
        if ($parsed === null || ($parsed['state'] ?? '') !== 'post' || $parsed['won'] === null) {
            continue;
        }
        $rows[] = $parsed;
    }
    usort($rows, static fn(array $a, array $b): int => (strtotime((string)$b['date']) ?: 0) <=> (strtotime((string)$a['date']) ?: 0));
    if ($rows === []) {
        return '';
    }
    $first = (bool)$rows[0]['won'];
    $count = 0;
    foreach ($rows as $row) {
        if ((bool)$row['won'] !== $first) {
            break;
        }
        $count++;
    }

    return ($first ? 'W' : 'L') . $count;
}

/** Lower = higher priority on the board. */
function sports_card_sort_priority(array $card, DateTimeInterface $now): int
{
    $mode = (string)($card['mode'] ?? 'off');
    $base = match ($mode) {
        'live' => 0,
        'next' => 100,
        'final' => 200,
        'off' => 300,
        default => 400,
    };
    if ($mode === 'next') {
        $game = is_array($card['game'] ?? null) ? $card['game'] : null;
        if ($game !== null) {
            $ts = strtotime((string)($game['date'] ?? '')) ?: PHP_INT_MAX;
            $today = $now->format('Y-m-d');
            if (substr((string)($game['date'] ?? ''), 0, 10) === $today) {
                return $base - 50;
            }

            return $base + min(99, max(0, (int)round(($ts - $now->getTimestamp()) / 3600)));
        }
    }

    return $base;
}

/** @param list<array<string,mixed>> $cards */
function sports_sort_cards(array $cards, ?DateTimeZone $tz = null): array
{
    $tz ??= new DateTimeZone((string)cfg('sports.TIMEZONE', 'America/Detroit'));
    $now = new DateTimeImmutable('now', $tz);
    usort($cards, static function (array $a, array $b) use ($now): int {
        $pa = sports_card_sort_priority($a, $now);
        $pb = sports_card_sort_priority($b, $now);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $cards;
}

/** @param list<array<string,mixed>> $cards */
function sports_show_next_strip(array $cards): bool
{
    if ($cards === []) {
        return false;
    }
    foreach ($cards as $card) {
        if (!empty($card['data_error'])) {
            return true;
        }
        if (empty($card['active_season'])) {
            return true;
        }
        $mode = (string)($card['mode'] ?? 'off');
        if ($mode === 'off' || $mode === 'final') {
            return true;
        }
    }

    return false;
}

/** @param list<array<string,mixed>> $cards @return list<array<string,mixed>> */
function sports_recent_results_strip(array $cards): array
{
    $out = [];
    foreach ($cards as $card) {
        $recent = is_array($card['recent_results'] ?? null) ? $card['recent_results'] : [];
        $streak = trim((string)($card['streak'] ?? ''));
        if ($recent === [] && $streak === '') {
            continue;
        }
        $out[] = [
            'team' => (string)($card['name'] ?? ''),
            'logo' => $card['logo_url'] ?? null,
            'icon' => (string)($card['icon'] ?? 'default'),
            'streak' => $streak,
            'recent' => $recent,
        ];
    }

    return $out;
}

/** @param list<array<string,mixed>> $cards */
function sports_board_focus_live(array $cards): bool
{
    $live = 0;
    foreach ($cards as $card) {
        if (($card['mode'] ?? '') === 'live') {
            $live++;
        }
    }

    return $live === 1 && count($cards) > 1;
}

/** @return array<string,mixed> */
function sports_board_data(string $screen): array
{
    $GLOBALS['diag'] = [];
    $GLOBALS['sports_cache_ages'] = [];
    if (!defined('SPORTS_CACHE_DIR')) {
        define('SPORTS_CACHE_DIR', SIGNAGE_ROOT . '/cache');
    }

    $teams = sports_teams_for_screen($screen);
    $tzName = (string)cfg('sports.TIMEZONE', 'America/Detroit');
    $tz = new DateTimeZone($tzName);
    $baseTtl = max(60, (int)cfg('sports.CACHE_TTL', 300));
    $scoreboardsByLeague = sports_scoreboards_for_teams($teams, min($baseTtl, 90));

    $cards = [];
    $anyLive = false;
    foreach ($teams as $teamCfg) {
        $card = sports_build_team_card($teamCfg, $scoreboardsByLeague, $baseTtl, $tz);
        if (($card['mode'] ?? '') === 'live') {
            $anyLive = true;
        }
        $cards[] = $card;
    }
    $cards = sports_sort_cards($cards, $tz);
    $teamCount = count($cards);
    $focusLive = sports_board_focus_live($cards);
    $nextStrip = sports_next_game_strip($cards, $tz);
    $showNextStrip = sports_show_next_strip($cards);
    $recentStrip = sports_recent_results_strip($cards);
    $cacheAge = sports_cache_max_age();
    $reloadSec = $anyLive ? max(45, (int)cfg('sports.RELOAD_SEC', 120)) : max(300, (int)cfg('sports.RELOAD_SEC', 120));
    $hasData = $teamCount > 0;

    return [
        'cards' => $cards,
        'team_count' => $teamCount,
        'any_live' => $anyLive,
        'focus_live' => $focusLive,
        'next_strip' => $nextStrip,
        'show_next_strip' => $showNextStrip,
        'recent_strip' => $recentStrip,
        'has_data' => $hasData,
        'cache_age' => $cacheAge,
        'reload_sec' => $reloadSec,
        'timezone' => $tzName,
    ];
}

/** Slim card payload for sports.php?api=1 polling. */
function sports_card_api_payload(array $card): array
{
    return [
        'key' => (string)($card['key'] ?? ''),
        'mode' => (string)($card['mode'] ?? 'off'),
        'badge' => (string)($card['badge'] ?? ''),
        'name' => (string)($card['name'] ?? ''),
        'league' => (string)($card['league'] ?? ''),
        'headline' => (string)($card['headline'] ?? ''),
        'detail' => (string)($card['detail'] ?? ''),
        'clock_line' => (string)($card['clock_line'] ?? ''),
        'standings_line' => (string)($card['standings_line'] ?? ''),
        'logo_url' => $card['logo_url'] ?? null,
        'opponent_logo' => $card['opponent_logo'] ?? null,
        'opponent_abbr' => (string)($card['opponent_abbr'] ?? ''),
        'us_score' => $card['us_score'] ?? null,
        'them_score' => $card['them_score'] ?? null,
        'result_class' => (string)($card['result_class'] ?? ''),
        'icon' => (string)($card['icon'] ?? 'default'),
        'accent' => (string)($card['accent'] ?? '#ffb347'),
        'data_error' => !empty($card['data_error']),
        'active_season' => !empty($card['active_season']),
    ];
}

function sports_card_badge_class(string $badge): string
{
    return match ($badge) {
        'Live' => 'live',
        'Up next' => 'next',
        'Off season', 'Unavailable' => 'off',
        'Final' => 'final',
        default => '',
    };
}

/** Render one team card (shared by page + optional server-side reuse). */
function sports_render_card(array $c, bool $focus = false): string
{
    $mode = (string)($c['mode'] ?? 'off');
    $badge = (string)($c['badge'] ?? '');
    $badgeClass = sports_card_badge_class($badge);
    $icon = (string)($c['icon'] ?? 'default');
    $logo = $c['logo_url'] ?? null;
    $oppLogo = $c['opponent_logo'] ?? null;
    $standings = (string)($c['standings_line'] ?? '');
    $clockLine = trim((string)($c['clock_line'] ?? ''));
    $resultClass = trim((string)($c['result_class'] ?? ''));
    $showScoreboard = in_array($mode, ['live', 'final'], true)
        && ($c['us_score'] ?? null) !== null
        && ($c['them_score'] ?? null) !== null;
    $classes = trim('card ' . $mode
        . ($badge === 'Off season' ? ' offseason' : '')
        . ($focus ? ' focus' : '')
        . ($resultClass !== '' ? ' result-' . $resultClass : '')
        . (!empty($c['data_error']) ? ' error' : ''));

    ob_start();
    ?>
    <article class="<?= htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') ?>"
             data-card-key="<?= htmlspecialchars((string)($c['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
             data-mode="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>"
             style="--accent:<?= htmlspecialchars((string)($c['accent'] ?? '#ffb347'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="card-row">
        <div class="logo-wrap team-logo">
          <?php if ($logo): ?>
          <img src="<?= htmlspecialchars((string)$logo, ENT_QUOTES, 'UTF-8') ?>" alt="" data-field="logo">
          <?php else: ?>
          <div class="sport-fallback" data-field="logo-fallback"><?= sports_sport_icon_svg($icon) ?></div>
          <?php endif; ?>
        </div>
        <div class="card-copy">
          <div class="card-top">
            <div class="team" data-field="name"><?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="meta">
              <span class="pill" data-field="league"><?= htmlspecialchars((string)($c['league'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="badge <?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>" data-field="badge"><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>
          <?php if ($showScoreboard): ?>
          <div class="scoreboard" data-field="scoreboard">
            <div class="score-team">
              <?php if ($logo): ?><img src="<?= htmlspecialchars((string)$logo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="score-logo"><?php endif; ?>
              <span class="score-num" data-field="us_score"><?= htmlspecialchars((string)$c['us_score'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <span class="score-dash">–</span>
            <div class="score-team opp">
              <span class="score-num" data-field="them_score"><?= htmlspecialchars((string)$c['them_score'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php if ($oppLogo): ?><img src="<?= htmlspecialchars((string)$oppLogo, ENT_QUOTES, 'UTF-8') ?>" alt="" class="score-logo" data-field="opp_logo"><?php endif; ?>
            </div>
          </div>
          <?php if ($clockLine !== ''): ?>
          <div class="clock-line" data-field="clock"><?= htmlspecialchars($clockLine, ENT_QUOTES, 'UTF-8') ?></div>
          <?php else: ?>
          <div class="clock-line" data-field="clock" style="display:none"></div>
          <?php endif; ?>
          <?php else: ?>
          <div class="headline" data-field="headline"><?= htmlspecialchars((string)($c['headline'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
          <div class="detail" data-field="detail"><?= htmlspecialchars((string)($c['detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($standings !== ''): ?>
          <div class="standings" data-field="standings"><?= htmlspecialchars($standings, ENT_QUOTES, 'UTF-8') ?></div>
          <?php else: ?>
          <div class="standings" data-field="standings" style="display:none"></div>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
    return (string)ob_get_clean();
}
