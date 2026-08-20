<?php
/**
 * Pollen display helpers + local NWS weather nudge for Google UPI.
 *
 * Aligns with echoweather: label/color from numeric UPI only (not Google category
 * strings), and optional weather modifiers (rain/humidity/wind) that nudge today's
 * airborne pollen risk without extra Google API calls.
 */

/** Google Universal Pollen Index (0–5) → label and color. */
function air_upi_band(int|float|null $upi): array
{
    if ($upi === null) {
        return ['—', 'var(--mist)'];
    }
    $n = (float)$upi;
    if ($n <= 0) {
        return ['None', 'var(--mist)'];
    }
    $bucket = (int)max(1, min(5, (int)round($n)));

    return match ($bucket) {
        1       => ['Very low', '#39c46d'],
        2       => ['Low', '#39c46d'],
        3       => ['Moderate', 'var(--beacon)'],
        4       => ['High', '#ff9d4d'],
        default => ['Very high', '#ff5d5d'],
    };
}

/**
 * Label + color from UPI only — do not override with Google category strings
 * (those can fight the numeric band, same bug echoweather fixed in v289).
 */
function air_google_pollen_row_label(?int $upi, string $category = ''): array
{
    unset($category);

    return air_upi_band($upi);
}

/**
 * Weather modifier in UPI units (−0.8 … +0.5).
 * Rain / high humidity suppress airborne pollen; dry + moderate wind favors it.
 *
 * @param array{precipProb?:float,humidity?:float|null,windMph?:float|null}|null $wx
 */
function air_pollen_weather_upi_delta(?array $wx): float
{
    if ($wx === null) {
        return 0.0;
    }
    $delta = 0.0;
    $precip = (float)($wx['precipProb'] ?? 0);
    if ($precip >= 60) {
        $delta -= 0.8;
    } elseif ($precip >= 40) {
        $delta -= 0.5;
    } elseif ($precip >= 25) {
        $delta -= 0.25;
    }

    $rh = $wx['humidity'] ?? null;
    if ($rh !== null) {
        if ($rh >= 80) {
            $delta -= 0.25;
        } elseif ($rh <= 45) {
            $delta += 0.15;
        }
    }

    $wind = $wx['windMph'] ?? null;
    if ($wind !== null) {
        if ($wind >= 8 && $wind <= 20 && $precip < 30) {
            $delta += 0.3;
        } elseif ($wind > 30) {
            $delta -= 0.15;
        }
    }

    return max(-0.8, min(0.5, $delta));
}

function air_pollen_parse_nws_wind_mph(string $s): ?float
{
    if (preg_match('/(\d+)\s*to\s*(\d+)/i', $s, $m)) {
        return ((float)$m[1] + (float)$m[2]) / 2;
    }
    if (preg_match('/(\d+)/', $s, $m)) {
        return (float)$m[1];
    }

    return null;
}

/**
 * Aggregate NWS hourly periods into a day weather summary.
 *
 * @param list<array<string,mixed>> $periods
 * @return array{precipProb:float,humidity:float|null,windMph:float|null,tempF:float|null,sampleCount:int}|null
 */
function air_pollen_nws_day_weather(array $periods, string $dateStr): ?array
{
    $precip = [];
    $humidity = [];
    $wind = [];
    $temp = [];
    foreach ($periods as $p) {
        if (!is_array($p)) {
            continue;
        }
        $start = (string)($p['startTime'] ?? '');
        if (strlen($start) < 10 || substr($start, 0, 10) !== $dateStr) {
            continue;
        }
        $prob = $p['probabilityOfPrecipitation']['value'] ?? null;
        if ($prob !== null) {
            $precip[] = (float)$prob;
        }
        $rh = $p['relativeHumidity']['value'] ?? null;
        if ($rh !== null) {
            $humidity[] = (float)$rh;
        }
        $w = air_pollen_parse_nws_wind_mph((string)($p['windSpeed'] ?? ''));
        if ($w !== null) {
            $wind[] = $w;
        }
        $t = $p['temperature'] ?? null;
        $unit = strtoupper((string)($p['temperatureUnit'] ?? 'F'));
        if ($t !== null) {
            $tf = $unit === 'C' ? ((float)$t * 9 / 5 + 32) : (float)$t;
            $temp[] = $tf;
        }
    }
    $n = max(count($precip), count($humidity), count($wind), count($temp));
    if ($n === 0) {
        return null;
    }

    return [
        'precipProb' => $precip ? array_sum($precip) / count($precip) : 0.0,
        'humidity' => $humidity ? array_sum($humidity) / count($humidity) : null,
        'windMph' => $wind ? array_sum($wind) / count($wind) : null,
        'tempF' => $temp ? array_sum($temp) / count($temp) : null,
        'sampleCount' => $n,
    ];
}

/**
 * Apply a weather UPI delta to Google pollen rows (in place).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function air_pollen_apply_weather_delta(array $rows, float $delta): array
{
    if (abs($delta) < 0.05) {
        return $rows;
    }
    foreach ($rows as &$row) {
        if (($row['unit'] ?? '') !== 'upi' || $row['val'] === null) {
            continue;
        }
        $local = max(0.0, min(5.0, (float)$row['val'] + $delta));
        $upiInt = (int)round($local);
        [$label, $color] = air_upi_band($local);
        $row['val'] = (float)$upiInt;
        $row['label'] = $label;
        $row['color'] = $color;
        $row['weather_adjusted'] = true;
    }
    unset($row);

    return $rows;
}
