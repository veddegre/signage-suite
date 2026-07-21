<?php
/**
 * Meal calendar — weekly plan + date overrides for signage display.
 */

/** @return list<string> */
function meals_weekday_names(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function meals_normalize_weekday(string $day): string
{
    $day = trim($day);
    foreach (meals_weekday_names() as $name) {
        if (strcasecmp($day, $name) === 0) {
            return $name;
        }
        if (strlen($day) >= 3 && strcasecmp(substr($name, 0, 3), substr($day, 0, 3)) === 0) {
            return $name;
        }
    }

    return $day;
}

/** @param array<string,mixed> $row */
function meals_row_text(array $row, string $key): string
{
    $v = trim((string)($row[$key] ?? ''));
    if ($v === '' && $key === 'dinner' && isset($row['meal'])) {
        $v = trim((string)$row['meal']);
    }

    return $v;
}

/** @return array<string,array<string,string>> weekday => slot => text */
function meals_weekly_plan_map(?array $rows): array
{
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $day = meals_normalize_weekday((string)($row['weekday'] ?? ''));
        if ($day === '') {
            continue;
        }
        $out[$day] = [
            'breakfast' => meals_row_text($row, 'breakfast'),
            'lunch' => meals_row_text($row, 'lunch'),
            'dinner' => meals_row_text($row, 'dinner'),
            'note' => meals_row_text($row, 'note'),
        ];
    }

    return $out;
}

/** @return array<string,array<string,string>> YYYY-MM-DD => slot => text */
function meals_override_map(?array $rows): array
{
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = trim((string)($row['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        $out[$date] = [
            'breakfast' => meals_row_text($row, 'breakfast'),
            'lunch' => meals_row_text($row, 'lunch'),
            'dinner' => meals_row_text($row, 'dinner'),
            'note' => meals_row_text($row, 'note'),
        ];
    }

    return $out;
}

/**
 * Resolve meals for one calendar day.
 *
 * @return array{breakfast:string,lunch:string,dinner:string,note:string,override:bool}
 */
function meals_for_date(string $ymd, array $weekly, array $overrides): array
{
    $blank = ['breakfast' => '', 'lunch' => '', 'dinner' => '', 'note' => '', 'override' => false];
    if (isset($overrides[$ymd])) {
        $o = $overrides[$ymd];

        return [
            'breakfast' => $o['breakfast'],
            'lunch' => $o['lunch'],
            'dinner' => $o['dinner'],
            'note' => $o['note'],
            'override' => true,
        ];
    }
    $weekday = date('l', strtotime($ymd . ' 12:00:00'));
    if (!isset($weekly[$weekday])) {
        return $blank;
    }
    $w = $weekly[$weekday];

    return [
        'breakfast' => $w['breakfast'],
        'lunch' => $w['lunch'],
        'dinner' => $w['dinner'],
        'note' => $w['note'],
        'override' => false,
    ];
}

/**
 * Rolling 7-day meal plan starting today (or from a midnight timestamp).
 *
 * @return list<array{ymd:string,ts:int,weekday:string,label:string,is_today:bool,meals:array<string,mixed>}>
 */
function meals_week_window(?array $weeklyRows, ?array $overrideRows, ?int $startMidnight = null): array
{
    $weekly = meals_weekly_plan_map($weeklyRows);
    $overrides = meals_override_map($overrideRows);
    $start = $startMidnight ?? strtotime('today');
    $todayKey = date('Y-m-d');
    $out = [];
    for ($d = 0; $d < 7; $d++) {
        $ts = strtotime("+{$d} day", $start);
        $ymd = date('Y-m-d', $ts);
        $out[] = [
            'ymd' => $ymd,
            'ts' => $ts,
            'weekday' => date('l', $ts),
            'label' => date('D', $ts),
            'is_today' => $ymd === $todayKey,
            'meals' => meals_for_date($ymd, $weekly, $overrides),
        ];
    }

    return $out;
}

/** @param array<string,mixed> $meals */
function meals_day_has_content(array $meals, bool $showBreakfast, bool $showLunch): bool
{
    if ($meals['dinner'] !== '') {
        return true;
    }
    if ($showLunch && $meals['lunch'] !== '') {
        return true;
    }
    if ($showBreakfast && $meals['breakfast'] !== '') {
        return true;
    }

    return $meals['note'] !== '';
}
