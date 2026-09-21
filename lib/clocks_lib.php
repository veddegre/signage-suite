<?php
/**
 * World clocks — IANA timezones, no external API.
 */

require_once dirname(__DIR__) . '/config.php';

const CLOCKS_MAX = 12;
const CLOCKS_MIN = 1;

/**
 * Built-in city set used when clocks.CLOCKS is empty.
 *
 * @return array<string,array{label:string,tz:string,home?:bool,off?:bool}>
 */
function clocks_default_rows(): array
{
    return [
        'detroit' => ['label' => 'Grand Rapids', 'tz' => 'America/Detroit', 'home' => true],
        'utc' => ['label' => 'UTC', 'tz' => 'UTC'],
        'newyork' => ['label' => 'New York', 'tz' => 'America/New_York'],
        'chicago' => ['label' => 'Chicago', 'tz' => 'America/Chicago'],
        'denver' => ['label' => 'Denver', 'tz' => 'America/Denver'],
        'losangeles' => ['label' => 'Los Angeles', 'tz' => 'America/Los_Angeles'],
        'london' => ['label' => 'London', 'tz' => 'Europe/London'],
        'tokyo' => ['label' => 'Tokyo', 'tz' => 'Asia/Tokyo'],
    ];
}

function clocks_home_timezone_name(): string
{
    $tz = trim((string)cfg('clocks.TIMEZONE', 'America/Detroit'));
    if ($tz === '' || clocks_timezone($tz) === null) {
        return 'America/Detroit';
    }

    return $tz;
}

function clocks_show_seconds(): bool
{
    return (bool)cfg('clocks.SHOW_SECONDS', true);
}

function clocks_show_date(): bool
{
    return (bool)cfg('clocks.SHOW_DATE', true);
}

function clocks_show_offset(): bool
{
    return (bool)cfg('clocks.SHOW_OFFSET', true);
}

function clocks_show_analog(): bool
{
    return (bool)cfg('clocks.SHOW_ANALOG', true);
}

function clocks_max_count(): int
{
    return max(CLOCKS_MIN, min(CLOCKS_MAX, (int)cfg('clocks.MAX_CLOCKS', 8)));
}

/** @return DateTimeZone|null */
function clocks_timezone(string $name): ?DateTimeZone
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    try {
        return new DateTimeZone($name);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Raw configured map (or built-ins). Includes off / invalid rows.
 *
 * @return array<string,array<string,mixed>>
 */
function clocks_configured_rows(): array
{
    $raw = cfg('clocks.CLOCKS', []);
    if (!is_array($raw) || $raw === []) {
        return clocks_default_rows();
    }
    $out = [];
    foreach ($raw as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = is_string($key) ? trim($key) : '';
        $tz = trim((string)($row['tz'] ?? $row['timezone'] ?? ''));
        $label = trim((string)($row['label'] ?? ''));
        if ($id === '') {
            $id = $tz !== '' ? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tz) ?? 'clock') : '';
        }
        if ($id === '') {
            continue;
        }
        $out[$id] = [
            'label' => $label,
            'tz' => $tz,
            'home' => !empty($row['home']),
            'off' => !empty($row['off']),
        ];
    }

    return $out !== [] ? $out : clocks_default_rows();
}

/**
 * Admin row editor — keyed rows including Off so they can be re-enabled.
 *
 * @return list<array<string,mixed>>
 */
function clocks_admin_rows(): array
{
    $rows = [];
    foreach (clocks_configured_rows() as $key => $row) {
        $rows[] = [
            '_key' => $key,
            'label' => (string)($row['label'] ?? ''),
            'tz' => (string)($row['tz'] ?? ''),
            'home' => !empty($row['home']),
            'off' => !empty($row['off']),
        ];
    }

    return $rows;
}

function clocks_offset_label(DateTimeInterface $dt): string
{
    $seconds = (int)$dt->format('Z');
    $sign = $seconds >= 0 ? '+' : "\u{2212}";
    $abs = abs($seconds);
    $hours = intdiv($abs, 3600);
    $mins = intdiv($abs % 3600, 60);
    if ($mins === 0) {
        return 'UTC' . $sign . $hours;
    }

    return sprintf('UTC%s%d:%02d', $sign, $hours, $mins);
}

function clocks_is_night(int $hour): bool
{
    return $hour < 6 || $hour >= 20;
}

/**
 * yesterday / today / tomorrow relative to the home timezone calendar day.
 *
 * @return 'yesterday'|'today'|'tomorrow'
 */
function clocks_day_relative(DateTimeInterface $city, DateTimeInterface $home): string
{
    $cityDay = (int)$city->format('Ymd');
    $homeDay = (int)$home->format('Ymd');
    if ($cityDay < $homeDay) {
        return 'yesterday';
    }
    if ($cityDay > $homeDay) {
        return 'tomorrow';
    }

    return 'today';
}

/**
 * @return list<array<string,mixed>>
 */
function clocks_enabled_cities(): array
{
    $homeName = clocks_home_timezone_name();
    $max = clocks_max_count();
    $out = [];
    $homeAssigned = false;
    foreach (clocks_configured_rows() as $key => $row) {
        if (!empty($row['off'])) {
            continue;
        }
        $tzName = trim((string)($row['tz'] ?? ''));
        $tz = clocks_timezone($tzName);
        if ($tz === null) {
            continue;
        }
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '') {
            $label = str_replace('_', ' ', (string)preg_replace('/^.*\//', '', $tzName));
        }
        $isHome = !empty($row['home']);
        if ($isHome) {
            $homeAssigned = true;
        }
        $out[] = [
            'key' => (string)$key,
            'label' => $label,
            'tz' => $tz->getName(),
            'home' => $isHome,
        ];
        if (count($out) >= $max) {
            break;
        }
    }
    if ($out === []) {
        foreach (clocks_default_rows() as $key => $row) {
            $tz = clocks_timezone((string)$row['tz']);
            if ($tz === null) {
                continue;
            }
            $out[] = [
                'key' => (string)$key,
                'label' => (string)$row['label'],
                'tz' => $tz->getName(),
                'home' => !empty($row['home']),
            ];
            if (count($out) >= $max) {
                break;
            }
        }
    }
    if (!$homeAssigned && $out !== []) {
        foreach ($out as $i => $city) {
            if (strcasecmp((string)$city['tz'], $homeName) === 0) {
                $out[$i]['home'] = true;
                $homeAssigned = true;
                break;
            }
        }
        if (!$homeAssigned) {
            $out[0]['home'] = true;
        }
    }

    return $out;
}

/**
 * Wall snapshot for one instant (PHP first paint + tests).
 *
 * @param list<array<string,mixed>>|null $cities
 * @return list<array<string,mixed>>
 */
function clocks_wall_snapshot(?DateTimeImmutable $now = null, ?array $cities = null, ?string $screen = null): array
{
    $homeTz = clocks_timezone(clocks_home_timezone_name()) ?? new DateTimeZone('America/Detroit');
    $nowUtc = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($nowUtc->getTimezone()->getName() !== 'UTC') {
        $nowUtc = $nowUtc->setTimezone(new DateTimeZone('UTC'));
    }
    $homeNow = $nowUtc->setTimezone($homeTz);
    $seconds = clocks_show_seconds();
    $h24 = signage_clock_24h($screen);
    $timeFmt = $h24 ? ($seconds ? 'H:i:s' : 'H:i') : ($seconds ? 'g:i:s A' : 'g:i A');
    $cities = $cities ?? clocks_enabled_cities();
    $out = [];
    foreach ($cities as $city) {
        $tz = clocks_timezone((string)($city['tz'] ?? ''));
        if ($tz === null) {
            continue;
        }
        $local = $nowUtc->setTimezone($tz);
        $hour = (int)$local->format('G');
        $minute = (int)$local->format('i');
        $second = (int)$local->format('s');
        $out[] = [
            'key' => (string)($city['key'] ?? ''),
            'label' => (string)($city['label'] ?? ''),
            'tz' => $tz->getName(),
            'home' => !empty($city['home']),
            'time' => $local->format($timeFmt),
            'date' => $local->format('D j M'),
            'weekday' => $local->format('l'),
            'offset' => clocks_offset_label($local),
            'abbr' => $local->format('T'),
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'hour12' => (int)$local->format('g'),
            'night' => clocks_is_night($hour),
            'day_rel' => clocks_day_relative($local, $homeNow),
        ];
    }

    return $out;
}

function clocks_grid_columns(int $count): int
{
    $count = max(1, $count);
    if ($count <= 1) {
        return 1;
    }
    if ($count <= 4) {
        return 2;
    }
    if ($count <= 6) {
        return 3;
    }

    return 4;
}

/** @return list<string> */
function clocks_invalid_timezone_names(): array
{
    $bad = [];
    foreach (clocks_configured_rows() as $row) {
        if (!empty($row['off'])) {
            continue;
        }
        $tz = trim((string)($row['tz'] ?? ''));
        if ($tz !== '' && clocks_timezone($tz) === null) {
            $bad[] = $tz;
        }
    }

    return $bad;
}
