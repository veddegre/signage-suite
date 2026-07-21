<?php
/**
 * Shared OpenWeatherMap fetch helpers for compact weather summaries.
 */

function weather_compass(float $deg): string
{
    static $dirs = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

    return $dirs[(int)round($deg / 22.5) % 16];
}

function weather_icon_url(string $code, int $scale = 2): string
{
    return sprintf('https://openweathermap.org/img/wn/%s@%dx.png', $code, $scale);
}

function weather_json_cached(string $url, string $cacheKey, int $cacheTtl): ?array
{
    $cacheDir = SIGNAGE_ROOT . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $data;
        }
    }
    if (!function_exists('curl_init')) {
        if (is_file($cacheFile)) {
            $data = json_decode((string)file_get_contents($cacheFile), true);
            return is_array($data) ? $data : null;
        }

        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'HomeSignage/Glance/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body !== false && $code === 200) {
        $data = json_decode($body, true);
        if (is_array($data)) {
            @file_put_contents($cacheFile, $body, LOCK_EX);

            return $data;
        }
    }
    if (is_file($cacheFile)) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($data) ? $data : null;
    }

    return null;
}

function weather_owm_url(string $endpoint, float $lat, float $lon, string $units, string $apiKey): string
{
    return sprintf(
        'https://api.openweathermap.org/data/2.5/%s?lat=%F&lon=%F&units=%s&appid=%s',
        $endpoint,
        $lat,
        $lon,
        $units,
        $apiKey
    );
}

/** @return list<array{label:string,date:string,min:int,max:int,pop:int,icon:string,desc:string}> */
function weather_daily_outlook(array $forecast): array
{
    $days = [];
    foreach ($forecast['list'] ?? [] as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $ts = (int)($slot['dt'] ?? 0);
        if ($ts <= 0) {
            continue;
        }
        $key = date('Y-m-d', $ts);
        if (!isset($days[$key])) {
            $days[$key] = [
                'label' => date('D', $ts),
                'date' => date('M j', $ts),
                'min' => PHP_FLOAT_MAX,
                'max' => -PHP_FLOAT_MAX,
                'icon' => (string)($slot['weather'][0]['icon'] ?? '01d'),
                'desc' => (string)($slot['weather'][0]['description'] ?? ''),
                'icon_gap' => PHP_INT_MAX,
                'pop' => 0.0,
            ];
        }
        $d = &$days[$key];
        $d['min'] = min($d['min'], (float)($slot['main']['temp_min'] ?? $slot['main']['temp'] ?? 0));
        $d['max'] = max($d['max'], (float)($slot['main']['temp_max'] ?? $slot['main']['temp'] ?? 0));
        $d['pop'] = max($d['pop'], (float)($slot['pop'] ?? 0));
        $gap = abs((int)date('G', $ts) - 13);
        if ($gap < $d['icon_gap']) {
            $d['icon_gap'] = $gap;
            $d['icon'] = (string)($slot['weather'][0]['icon'] ?? '01d');
            $d['desc'] = (string)($slot['weather'][0]['description'] ?? '');
        }
        unset($d);
    }
    $out = [];
    foreach ($days as $day) {
        $out[] = [
            'label' => (string)$day['label'],
            'date' => (string)$day['date'],
            'min' => (int)round($day['min']),
            'max' => (int)round($day['max']),
            'pop' => (int)round($day['pop'] * 100),
            'icon' => (string)$day['icon'],
            'desc' => (string)$day['desc'],
        ];
    }

    return $out;
}

/**
 * Compact weather for glance-style boards — uses Weather board OWM key and cache TTL.
 *
 * @return array<string,mixed>|null
 */
function weather_glance_summary(float $lat, float $lon): ?array
{
    $apiKey = trim((string)cfg('index.OWM_API_KEY', ''));
    if ($apiKey === '' || $apiKey === 'PUT-YOUR-OPENWEATHERMAP-KEY-HERE') {
        return null;
    }
    $units = (string)cfg('index.UNITS', 'imperial');
    $cacheTtl = max(60, (int)cfg('index.CACHE_TTL', 600));
    $cacheId = sprintf('%F_%F_%s', $lat, $lon, $units);
    $current = weather_json_cached(
        weather_owm_url('weather', $lat, $lon, $units, $apiKey),
        'owm_current_' . $cacheId,
        $cacheTtl
    );
    if (!$current || !isset($current['main'])) {
        return null;
    }
    $forecast = weather_json_cached(
        weather_owm_url('forecast', $lat, $lon, $units, $apiKey),
        'owm_forecast_' . $cacheId,
        $cacheTtl
    );
    $outlook = is_array($forecast) ? weather_daily_outlook($forecast) : [];
    $today = $outlook[0] ?? null;
    $tomorrow = $outlook[1] ?? null;

    $curTemp = (int)round((float)$current['main']['temp']);
    $hi = $today ? (int)$today['max'] : null;
    $lo = $today ? (int)$today['min'] : null;
    if ($hi !== null) {
        $hi = max($hi, $curTemp);
    }
    if ($lo !== null) {
        $lo = min($lo, $curTemp);
    }
    if (isset($current['main']['temp_max'])) {
        $hi = max($hi ?? $curTemp, (int)round((float)$current['main']['temp_max']));
    }
    if (isset($current['main']['temp_min'])) {
        $lo = min($lo ?? $curTemp, (int)round((float)$current['main']['temp_min']));
    }

    return [
        'temp' => $curTemp,
        'feels' => (int)round((float)$current['main']['feels_like']),
        'desc' => ucfirst((string)($current['weather'][0]['description'] ?? '—')),
        'icon' => (string)($current['weather'][0]['icon'] ?? '01d'),
        'hi' => $hi,
        'lo' => $lo,
        'pop' => $today ? (int)$today['pop'] : null,
        'humidity' => (int)($current['main']['humidity'] ?? 0),
        'wind_mph' => (int)round((float)($current['wind']['speed'] ?? 0)),
        'wind_dir' => weather_compass((float)($current['wind']['deg'] ?? 0)),
        'tomorrow_label' => $tomorrow ? ($tomorrow['label'] . ' ' . $tomorrow['date']) : '',
        'tomorrow_hi' => $tomorrow ? (int)$tomorrow['max'] : null,
        'tomorrow_lo' => $tomorrow ? (int)$tomorrow['min'] : null,
        'tomorrow_pop' => $tomorrow ? (int)$tomorrow['pop'] : null,
        'tomorrow_icon' => $tomorrow ? (string)$tomorrow['icon'] : '',
    ];
}
