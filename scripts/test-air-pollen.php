#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/lib/air_pollen_lib.php';

$fail = 0;
function expect(bool $ok, string $msg): void
{
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    $fail++;
    echo "FAIL  $msg\n";
}

[$label0, $color0] = air_upi_band(0);
expect($label0 === 'None', 'UPI 0 → None');
expect($color0 === 'var(--mist)', 'UPI 0 uses mist color');

[$label3] = air_upi_band(3);
expect($label3 === 'Moderate', 'UPI 3 → Moderate');

[$labelHigh] = air_google_pollen_row_label(4, 'Very High');
expect($labelHigh === 'High', 'label follows UPI 4, not category string');

[$labelNone] = air_google_pollen_row_label(0, 'None');
expect($labelNone === 'None', 'UPI 0 stays None even with category');

$wet = air_pollen_weather_upi_delta(['precipProb' => 70, 'humidity' => 85, 'windMph' => 5]);
expect($wet < -0.5, 'heavy rain + humidity suppresses UPI');

$favor = air_pollen_weather_upi_delta(['precipProb' => 10, 'humidity' => 40, 'windMph' => 12]);
expect($favor > 0.2, 'dry moderate wind favors UPI');

$rows = [
    ['name' => 'Grass', 'val' => 3.0, 'unit' => 'upi', 'label' => 'Moderate', 'color' => 'var(--beacon)'],
    ['name' => 'Tree', 'val' => null, 'unit' => 'upi', 'label' => 'Off season', 'color' => 'var(--mist)'],
];
$adj = air_pollen_apply_weather_delta($rows, -0.8);
expect((float)$adj[0]['val'] === 2.0, 'weather delta lowers Grass UPI 3→2');
expect($adj[0]['label'] === 'Low', 'adjusted Grass label is Low');
expect($adj[1]['val'] === null, 'off-season row stays null');

$periods = [
    [
        'startTime' => '2026-08-20T10:00:00-04:00',
        'probabilityOfPrecipitation' => ['value' => 80],
        'relativeHumidity' => ['value' => 90],
        'windSpeed' => '5 mph',
        'temperature' => 70,
        'temperatureUnit' => 'F',
    ],
];
$wx = air_pollen_nws_day_weather($periods, '2026-08-20');
expect($wx !== null && (float)$wx['precipProb'] === 80.0, 'NWS day weather aggregates precip');

exit($fail > 0 ? 1 : 0);
