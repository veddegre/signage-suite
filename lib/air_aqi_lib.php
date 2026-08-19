<?php
/**
 * Air-quality scoring: EPA AQI math, NWS alert floors, effective headline.
 * Aerosol optical depth is not used as an AQI floor — it is column haze, not
 * ground-level smoke.
 */

/** Convert PM2.5 (µg/m³) to EPA US AQI using standard breakpoints. */
function air_pm25_to_aqi(?float $pm25): ?int
{
    if ($pm25 === null) {
        return null;
    }
    $bps = [
        [0.0, 12.0, 0, 50],
        [12.1, 35.4, 51, 100],
        [35.5, 55.4, 101, 150],
        [55.5, 150.4, 151, 200],
        [150.5, 250.4, 201, 300],
        [250.5, 350.4, 301, 400],
        [350.5, 500.4, 401, 500],
    ];
    foreach ($bps as [$cLow, $cHigh, $iLow, $iHigh]) {
        if ($pm25 < $cLow) {
            continue;
        }
        if ($pm25 <= $cHigh) {
            return (int)round(($iHigh - $iLow) / ($cHigh - $cLow) * ($pm25 - $cLow) + $iLow);
        }
    }

    return 500;
}

/**
 * Alerts that actually describe ground-level air quality (not fire weather or fog).
 *
 * @param list<array{event?:string,headline?:string,severity?:string,description?:string}> $alerts
 * @return list<array{event:string,headline:string,severity:string,description:string}>
 */
function air_nws_aq_alerts(array $alerts): array
{
    $out = [];
    foreach ($alerts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $event = trim((string)($a['event'] ?? ''));
        $headline = trim((string)($a['headline'] ?? ''));
        if ($event === '') {
            continue;
        }
        $blob = $event . ' ' . $headline;
        if (!preg_match(
            '/air\s+quality|dense\s+smoke|blowing\s+dust|ozone\s+action|particulate|pm2\.?5/i',
            $blob
        )) {
            continue;
        }
        $out[] = [
            'event' => $event,
            'headline' => $headline,
            'severity' => trim((string)($a['severity'] ?? 'Moderate')),
            'description' => trim((string)($a['description'] ?? '')),
        ];
    }

    return $out;
}

/** Map EPA category language in NWS alert text to a minimum AQI floor. */
function air_nws_text_aqi_floor(string $text): ?int
{
    $cat = air_nws_text_category($text);

    return $cat['floor'] ?? null;
}

/**
 * @return array{label:string,floor:int}|null
 */
function air_nws_text_category(string $text): ?array
{
    $t = strtolower($text);
    if (preg_match('/\bhazardous\b/', $t)) {
        return ['label' => 'Hazardous', 'floor' => 301];
    }
    if (preg_match('/very\s+unhealthy/', $t)) {
        return ['label' => 'Very Unhealthy', 'floor' => 201];
    }
    if (preg_match('/unhealthy\s+for\s+sensitive|sensitive\s+groups/', $t)) {
        return ['label' => 'Unhealthy for Sensitive Groups', 'floor' => 101];
    }
    if (preg_match('/\bunhealthy\b/', $t)) {
        return ['label' => 'Unhealthy', 'floor' => 151];
    }

    return null;
}

/**
 * Highest EPA category mentioned across active NWS air-quality alerts.
 *
 * @param list<array{event:string,headline:string,severity:string,description?:string}> $alerts
 * @return array{label:string,floor:int}|null
 */
function air_nws_alert_category(array $alerts): ?array
{
    $best = null;
    foreach ($alerts as $a) {
        $blob = implode(' ', array_filter([
            (string)($a['event'] ?? ''),
            (string)($a['headline'] ?? ''),
            (string)($a['description'] ?? ''),
        ]));
        $cat = air_nws_text_category($blob);
        if ($cat === null) {
            continue;
        }
        if ($best === null || $cat['floor'] > $best['floor']) {
            $best = $cat;
        }
    }

    return $best;
}

/**
 * AQI floor from NWS only when the product names an EPA category or is a
 * warning-level air-quality / dense-smoke event. Generic “alert” copy is not a floor.
 *
 * @param list<array{event:string,headline:string,severity:string,description?:string}> $alerts
 */
function air_nws_alert_floor(array $alerts): ?int
{
    if ($alerts === []) {
        return null;
    }
    $floor = null;
    foreach ($alerts as $a) {
        $blob = implode(' ', array_filter([
            (string)($a['event'] ?? ''),
            (string)($a['headline'] ?? ''),
            (string)($a['description'] ?? ''),
        ]));
        $textFloor = air_nws_text_aqi_floor($blob);
        if ($textFloor !== null) {
            $floor = max($floor ?? 0, $textFloor);
            continue;
        }
        $event = strtolower((string)($a['event'] ?? ''));
        $sev = strtolower((string)($a['severity'] ?? ''));
        $isAq = (bool)preg_match('/air\s+quality|dense\s+smoke|ozone|particulate/', $event);
        if (!$isAq) {
            continue;
        }
        if (str_contains($event, 'warning') || $sev === 'extreme') {
            $floor = max($floor ?? 0, 151);
        } elseif ($sev === 'severe') {
            $floor = max($floor ?? 0, 101);
        }
    }

    return $floor;
}

/**
 * EPA overall AQI = max pollutant sub-index. NWS may raise the headline only
 * when it names an EPA category (or is a warning). AOD never raises AQI.
 *
 * @param array{pm25:?int,pm10:?int,ozone:?int,no2:?int} $pollutantAqis
 * @param list<array{event:string,headline:string,severity:string,description?:string}> $nwsAlerts
 * @return array{
 *   effective:?int,
 *   pollutant_max:?int,
 *   model:?int,
 *   floor:?int,
 *   nws_floor:?int,
 *   smoke_floor:?int,
 *   driver:string,
 *   nws_category:?array{label:string,floor:int},
 *   note:string
 * }
 */
function air_effective_aqi(
    array $pollutantAqis,
    ?int $modelAqi,
    ?float $pm25,
    ?float $aod,
    array $nwsAlerts,
    bool $preferMonitors = false
): array {
    unset($aod);
    $filtered = array_filter($pollutantAqis, static fn($v) => $v !== null);
    $pollutantMax = $filtered !== [] ? max($filtered) : null;
    if ($pollutantMax === null) {
        $pollutantMax = $modelAqi ?? air_pm25_to_aqi($pm25);
    }
    $nwsCategory = air_nws_alert_category($nwsAlerts);
    $nwsFloor = air_nws_alert_floor($nwsAlerts);
    if ($preferMonitors && $pollutantMax !== null && $nwsCategory === null) {
        $nwsFloor = null;
    }
    $candidates = array_filter([$pollutantMax, $nwsFloor], static fn($v) => $v !== null);
    $effective = $candidates !== [] ? max($candidates) : null;

    $driver = 'pollutants';
    if ($effective !== null && $nwsFloor !== null && $effective === $nwsFloor && $nwsFloor > ($pollutantMax ?? 0)) {
        $driver = 'nws';
    }

    $note = '';
    if ($driver === 'nws' && $nwsCategory !== null) {
        $note = 'NWS alert: ' . $nwsCategory['label'] . ' — model readings below are not current ground conditions';
    } elseif ($nwsAlerts !== [] && $driver === 'nws') {
        $note = (string)($nwsAlerts[0]['event'] ?? 'Air quality alert') . ' active';
    } elseif ($nwsAlerts !== []) {
        $note = (string)($nwsAlerts[0]['event'] ?? 'Air quality alert') . ' active';
    } elseif ($modelAqi !== null && $pollutantMax !== null && $pollutantMax > $modelAqi + 20) {
        $note = 'Per-pollutant AQI above consolidated model reading';
    }

    return [
        'effective' => $effective,
        'pollutant_max' => $pollutantMax,
        'model' => $modelAqi,
        'floor' => $nwsFloor,
        'nws_floor' => $nwsFloor,
        'smoke_floor' => null,
        'driver' => $driver,
        'nws_category' => $nwsCategory,
        'note' => $note,
    ];
}
