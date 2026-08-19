#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/lib/air_aqi_lib.php';

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

$today = air_effective_aqi(
    ['pm25' => 22, 'pm10' => 18, 'ozone' => 40, 'no2' => null],
    56,
    5.4,
    0.30,
    []
);
expect(($today['effective'] ?? null) === 40, 'AOD 0.30 does not floor AQI above pollutants');
expect(($today['driver'] ?? '') === 'pollutants', 'driver stays pollutants when AOD is high and PM is low');
expect(($today['smoke_floor'] ?? null) === null, 'no synthetic smoke floor');

$redFlag = air_nws_aq_alerts([
    ['event' => 'Red Flag Warning', 'headline' => 'Red Flag Warning for fire weather', 'severity' => 'Severe'],
]);
expect($redFlag === [], 'Red Flag is not an air-quality alert');

$fog = air_nws_aq_alerts([
    ['event' => 'Dense Fog Advisory', 'headline' => 'Dense fog this morning', 'severity' => 'Moderate'],
]);
expect($fog === [], 'Dense fog is not an air-quality alert');

$aqAlert = air_nws_aq_alerts([
    ['event' => 'Air Quality Alert', 'headline' => 'Unhealthy for Sensitive Groups', 'severity' => 'Moderate', 'description' => ''],
]);
expect($aqAlert !== [], 'Air Quality Alert is kept');
$floor = air_nws_alert_floor($aqAlert);
expect($floor === 101, 'NWS sensitive-groups language floors at 101');

$generic = air_nws_alert_floor([
    ['event' => 'Air Quality Alert', 'headline' => 'Air Quality Alert issued', 'severity' => 'Moderate', 'description' => ''],
]);
expect($generic === null, 'generic AQ alert without EPA category is not a floor');

$monitors = air_effective_aqi(
    ['pm25' => 35, 'pm10' => null, 'ozone' => null, 'no2' => null],
    35,
    8.0,
    0.40,
    $aqAlert,
    true
);
expect(($monitors['effective'] ?? null) === 101, 'explicit NWS category can still raise above AirNow');
$monitorsGeneric = air_effective_aqi(
    ['pm25' => 35, 'pm10' => null, 'ozone' => null, 'no2' => null],
    35,
    8.0,
    0.40,
    [['event' => 'Air Quality Alert', 'headline' => 'Air Quality Alert issued', 'severity' => 'Moderate', 'description' => '']],
    true
);
expect(($monitorsGeneric['effective'] ?? null) === 35, 'AirNow wins over generic NWS alert with no category');

echo $fail === 0 ? "All checks passed.\n" : "$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
