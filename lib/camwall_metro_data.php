<?php
/**
 * Grand Rapids metro MDOT camera preset — verified micamerasimages.net feeds.
 * Corridor defaults (sort 1–12) live in camwall_lib.php; these use sort 100+.
 */

/** Snapshot URLs already used by the built-in commute corridor preset. */
function camwall_corridor_snapshot_urls(): array
{
    return [
        'https://micamerasimages.net/thumbs/internet_cam_201.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/internet_cam_202.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/internet_cam_203.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/internet_cam_209.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_003.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_008.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_047.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_053.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_056.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_061.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_062.flv.jpg?item=1',
        'https://micamerasimages.net/thumbs/grand_cam_092.flv.jpg?item=1',
    ];
}

/** @return array<string,array{0:string,1:string}> grand_cam id => [label, route] */
function camwall_metro_grand_cam_labels(): array
{
    return [
        '001' => ['Walker', 'I-96'],
        '002' => ['3 Mile Rd', 'I-96'],
        '004' => ['M-37 (east)', 'I-96'],
        '005' => ['M-37 (west)', 'I-96'],
        '006' => ['M-44 Connector (east)', 'I-96'],
        '007' => ['M-44 Connector (west)', 'I-96'],
        '009' => ['Plainfield Ave', 'I-96'],
        '010' => ['East Beltline', 'I-96'],
        '011' => ['Division Ave', 'I-96'],
        '012' => ['Burton St', 'I-96'],
        '013' => ['28th St', 'I-96'],
        '014' => ['44th St', 'I-96'],
        '015' => ['Cascade Rd', 'I-96'],
        '016' => ['Knapp St', 'I-96'],
        '017' => ['Fulton St', 'I-96'],
        '018' => ['Lake Michigan Dr', 'I-96'],
        '019' => ['Wilson Ave', 'I-96'],
        '020' => ['Alpine Ave', 'I-96'],
        '021' => ['Beltline (west)', 'I-96'],
        '022' => ['Fruit Ridge Ave', 'I-96'],
        '023' => ['Comstock Park', 'I-96'],
        '024' => ['Rockford', 'I-96'],
        '025' => ['Belmont', 'I-96'],
        '026' => ['Grandville', 'I-96'],
        '027' => ['Jenison', 'I-96'],
        '028' => ['Allendale (west)', 'I-96'],
        '029' => ['Coopersville', 'I-96'],
        '030' => ['Marne', 'I-96'],
        '040' => ['10 Mile Rd', 'US-131'],
        '041' => ['14 Mile Rd', 'US-131'],
        '042' => ['17 Mile Rd', 'US-131'],
        '043' => ['22 Mile Rd', 'US-131'],
        '044' => ['33 Mile Rd', 'US-131'],
        '045' => ['100th St', 'US-131'],
        '046' => ['North end', 'US-131'],
        '048' => ['Wealthy St', 'US-131'],
        '049' => ['Hall St', 'US-131'],
        '050' => ['Franklin St', 'US-131'],
        '051' => ['Pearl St', 'US-131'],
        '052' => ['Wealthy (south)', 'US-131'],
        '054' => ['44th St', 'US-131'],
        '055' => ['60th St', 'US-131'],
        '057' => ['68th St', 'US-131'],
        '058' => ['76th St', 'US-131'],
        '059' => ['80th Ave', 'US-131'],
        '060' => ['84th Ave', 'US-131'],
        '063' => ['44th St', 'I-196'],
        '064' => ['32nd Ave', 'I-196'],
        '065' => ['Byron Center', 'I-196'],
        '066' => ['Clyde Park', 'I-196'],
        '067' => ['Buchanan Ave', 'I-196'],
        '068' => ['Lake Michigan Dr', 'I-196'],
        '069' => ['Ottawa Ave', 'I-196'],
        '070' => ['Century Ave', 'I-196'],
        '071' => ['Grandville Ave', 'I-196'],
        '072' => ['Port Sheldon', 'I-196'],
        '073' => ['Riley St', 'I-196'],
        '074' => ['Adams St', 'I-196'],
        '075' => ['Holland (east)', 'I-196'],
        '076' => ['Washington Ave', 'I-196'],
        '077' => ['Blake St', 'I-196'],
        '078' => ['Central Ave', 'I-196'],
        '079' => ['Broadmoor', 'I-196'],
        '080' => ['Hudsonville', 'I-196'],
        '081' => ['Wilson Ave', 'M-6'],
        '082' => ['Kalamazoo Ave', 'M-6'],
        '083' => ['Eastern Ave', 'M-6'],
        '084' => ['Breton Rd', 'M-6'],
        '085' => ['East Beltline', 'M-6'],
        '086' => ['Plainfield Ave', 'M-6'],
        '087' => ['Division Ave', 'M-6'],
        '088' => ['South Beltline', 'M-6'],
        '089' => ['Byron Center Rd', 'M-6'],
        '090' => ['Kraft Ave', 'M-6'],
        '091' => ['68th St', 'M-6'],
        '093' => ['M-37', 'M-37'],
        '094' => ['Alpine Ave', 'M-37'],
        '095' => ['4 Mile Rd', 'M-37'],
        '096' => ['10 Mile Rd', 'M-37'],
        '097' => ['North end', 'M-37'],
        '098' => ['Sparta', 'M-37'],
        '099' => ['Casnovia', 'M-37'],
        '100' => ['Grant', 'M-37'],
    ];
}

function camwall_metro_route_fallback(string $grandId): string
{
    $n = (int)$grandId;
    if ($n <= 30) {
        return 'I-96';
    }
    if ($n <= 45) {
        return 'US-131';
    }
    if ($n <= 80) {
        return 'I-196';
    }
    if ($n <= 91) {
        return 'M-6';
    }

    return 'M-37';
}

/** @return array<string,array<string,mixed>> */
function camwall_metro_cameras(): array
{
    $skipUrls = array_flip(camwall_corridor_snapshot_urls());
    $labels = camwall_metro_grand_cam_labels();
    $out = [];
    $sort = 100;

    foreach (range(1, 100) as $n) {
        $id = str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $url = 'https://micamerasimages.net/thumbs/grand_cam_' . $id . '.flv.jpg?item=1';
        if (isset($skipUrls[$url])) {
            continue;
        }
        $key = 'gr-' . $id;
        if (isset($labels[$id])) {
            [$name, $route] = $labels[$id];
        } else {
            $name = 'Camera ' . $id;
            $route = camwall_metro_route_fallback($id);
        }
        $out[$key] = [
            'name' => $name,
            'route' => $route,
            'url' => $url,
            'sort' => $sort++,
        ];
    }

    $internet = [
        '210' => ['Allendale (east)', 'I-96'],
        '211' => ['Standale', 'I-96'],
        '212' => ['Walker (north)', 'I-96'],
        '213' => ['Coopersville (east)', 'I-96'],
        '215' => ['Nunica', 'I-96'],
        '216' => ['Fruitport', 'I-96'],
        '217' => ['Grand Haven (east)', 'I-96'],
    ];
    foreach ($internet as $id => [$name, $route]) {
        $url = 'https://micamerasimages.net/thumbs/internet_cam_' . $id . '.flv.jpg?item=1';
        if (isset($skipUrls[$url])) {
            continue;
        }
        $out['inet-' . $id] = [
            'name' => $name,
            'route' => $route,
            'url' => $url,
            'sort' => $sort++,
        ];
    }

    return $out;
}
