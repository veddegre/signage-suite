#!/usr/bin/env php
<?php
/**
 * CLI: test Air & Pollen data sources (AirNow, Open-Meteo, NWS).
 *
 * Usage:
 *   php scripts/diagnose-air.php [--root=/path/to/install]
 *
 * Reads config/settings.json from the install root. Auto-detects
 * /var/www/html/boards when present. Override with SIGNAGE_ROOT or --root=.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/cli_lib.php';

$opts = signage_cli_parse_argv($argv);
$root = signage_cli_resolve_root($opts['root']);
if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', $root);
}
if (!defined('SIGNAGE_CLI')) {
    define('SIGNAGE_CLI', true);
}
require_once $root . '/config.php';

echo 'SIGNAGE_ROOT: ' . SIGNAGE_ROOT . "\n";
echo 'Config: ' . cfg_path() . "\n\n";

if (!is_file(cfg_path())) {
    echo "settings.json not found.\n";
    exit(1);
}

$lat = (float)cfg('air.LAT', 42.9720);
$lon = (float)cfg('air.LON', -85.9536);
$place = (string)cfg('air.PLACE', 'West Michigan');
$airnowKey = trim((string)cfg('air.AIRNOW_API_KEY', ''));
$googleKey = trim((string)cfg('air.GOOGLE_POLLEN_API_KEY', ''));

echo "Location: {$place} ({$lat}, {$lon})\n";
echo 'air.AIRNOW_API_KEY: ' . ($airnowKey !== '' ? 'set (' . strlen($airnowKey) . ' chars)' : 'NOT SET') . "\n";
echo 'air.GOOGLE_POLLEN_API_KEY: ' . ($googleKey !== '' ? 'set (' . strlen($googleKey) . ' chars)' : 'not set') . "\n";
if ($googleKey !== '' && $airnowKey === '') {
    echo "\nNote: Google Pollen and EPA AirNow are different keys. AQI readings need air.AIRNOW_API_KEY.\n";
}
echo "\n";

if ($airnowKey === '') {
    echo "AirNow skipped — paste your EPA key in admin → Air & Pollen → EPA AirNow API key, then save.\n\n";
} else {
    $url = 'https://www.airnowapi.org/aq/observation/latLong/current/?' . http_build_query([
        'format' => 'application/json',
        'latitude' => $lat,
        'longitude' => $lon,
        'distance' => 100,
        'API_KEY' => $airnowKey,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'HomeSignage/DiagnoseAir/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo "AirNow HTTP {$code}\n";
    if ($body === false) {
        echo "curl error: {$err}\n\n";
    } else {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            echo "Invalid JSON\n\n";
        } elseif (isset($data['WebServiceError'])) {
            foreach ((array)$data['WebServiceError'] as $row) {
                if (is_array($row)) {
                    echo 'API error: ' . (string)($row['Message'] ?? 'unknown') . "\n";
                }
            }
            echo "\n";
        } elseif ($data === []) {
            echo "Empty observation list — no monitor within 100 mi of this lat/lon.\n\n";
        } else {
            echo "Observations:\n";
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $param = (string)($row['ParameterName'] ?? '?');
                $aqi = $row['AQI'] ?? '—';
                $area = (string)($row['ReportingArea'] ?? '');
                $cat = is_array($row['Category'] ?? null) ? (string)($row['Category']['Name'] ?? '') : '';
                echo "  {$param}: AQI {$aqi} ({$cat}) — {$area}\n";
            }
            echo "\n";
        }
    }
}

$nwsUrl = sprintf('https://api.weather.gov/alerts/active?point=%.4F,%.4F', $lat, $lon);
$ch = curl_init($nwsUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'HomeSignage/DiagnoseAir/1.0',
    CURLOPT_HTTPHEADER => ['Accept: application/geo+json'],
]);
$nwsBody = curl_exec($ch);
$nwsCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "NWS alerts HTTP {$nwsCode}\n";
if ($nwsBody !== false) {
    $nws = json_decode($nwsBody, true);
    $aq = 0;
    foreach ((array)($nws['features'] ?? []) as $feat) {
        $event = (string)($feat['properties']['event'] ?? '');
        if (preg_match('/air\s+quality|smoke|haze/i', $event)) {
            $aq++;
            echo '  ' . $event . ': ' . substr((string)($feat['properties']['headline'] ?? ''), 0, 80) . "\n";
        }
    }
    if ($aq === 0) {
        echo "  (no air-quality alerts for this point)\n";
    }
}
echo "\n";

$omUrl = 'https://air-quality-api.open-meteo.com/v1/air-quality?' . http_build_query([
    'latitude' => $lat,
    'longitude' => $lon,
    'current' => 'us_aqi,us_aqi_pm2_5,us_aqi_pm10,us_aqi_ozone',
]);
$ch = curl_init($omUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => 'HomeSignage/DiagnoseAir/1.0',
]);
$omBody = curl_exec($ch);
$omCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "Open-Meteo HTTP {$omCode}\n";
if ($omBody !== false) {
    $om = json_decode($omBody, true);
    $cur = is_array($om['current'] ?? null) ? $om['current'] : [];
    foreach (['us_aqi', 'us_aqi_pm2_5', 'us_aqi_pm10', 'us_aqi_ozone'] as $field) {
        if (isset($cur[$field])) {
            echo '  ' . $field . ': ' . $cur[$field] . "\n";
        }
    }
}

echo "\nDone.\n";
