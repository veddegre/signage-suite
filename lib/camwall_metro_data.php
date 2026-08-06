<?php
/**
 * Grand Rapids metro MDOT camera preset — labels from Mi Drive camera API.
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
        // I-196
        '001' => ['44th St', 'I-196'],
        '004' => ['Jackson St', 'I-196'],
        '008' => ['Chicago Dr', 'I-196'],
        '022' => ['Kent Trails', 'I-196'],
        '037' => ['32nd Ave', 'I-196'],
        '041' => ['M-11', 'I-196'],
        '054' => ['M-45', 'I-196'],
        '055' => ['College Ave', 'I-196'],
        '058' => ['BS I-196 (Chicago Dr)', 'I-196'],
        '059' => ['Lane Ave', 'I-196'],
        '060' => ['Fuller Ave', 'I-196'],
        '090' => ['Plymouth', 'I-196'],
        '091' => ['I96 2', 'I-196'],
        '092' => ['8th Ave', 'I-196'],
        '093' => ['M6 Entr Ramp', 'I-196'],
        '094' => ['M6 Entr Ramp 2', 'I-196'],
        '097' => ['Butterworth St North', 'I-196'],
        '098' => ['Butterworth St South', 'I-196'],
        // I-96
        '002' => ['M-44 Connector', 'I-96'],
        '003' => ['M-37/M-44', 'I-96'],
        '009' => ['M-44 Connector', 'I-96'],
        '010' => ['M-11 (28th St)', 'I-96'],
        '012' => ['M-37', 'I-96'],
        '013' => ['Forest Hill Ave', 'I-96'],
        '046' => ['M-37', 'I-96'],
        '050' => ['M-21', 'I-96'],
        '062' => ['I-196', 'I-96'],
        '064' => ['Cascade Rd', 'I-96'],
        '065' => ['3 Mile Rd', 'I-96'],
        '067' => ['Knapp St', 'I-96'],
        '068' => ['Walker Ave', 'I-96'],
        '069' => ['Leonard St', 'I-96'],
        '070' => ['36th St', 'I-96'],
        '071' => ['36th St', 'I-96'],
        '072' => ['Fruit Ridge', 'I-96'],
        '076' => ['M-6', 'I-96'],
        '087' => ['Fruitport RA', 'I-96'],
        '088' => ['112th Avenue', 'I-96'],
        // M-11
        '011' => ['Burlingame Ave', 'M-11'],
        '015' => ['Byron Center Ave', 'M-11'],
        '018' => ['Ivanrest Ave', 'M-11'],
        '019' => ['Eastern Ave', 'M-11'],
        '020' => ['East Paris Ave', 'M-11'],
        '030' => ['Kalamazoo Ave', 'M-11'],
        '033' => ['Patterson Ave', 'M-11'],
        '034' => ['Division Ave', 'M-11'],
        '035' => ['M-37 (East Beltline Ave)', 'M-11'],
        '038' => ['Clyde Park Ave', 'M-11'],
        '039' => ['Radcliff Ave', 'M-11'],
        '042' => ['Breton Ave', 'M-11'],
        '045' => ['M-11 interchange', 'M-11'],
        '096' => ['I-96 WB Ramp', 'M-11'],
        // M-37
        '023' => ['4 Mile Rd', 'M-37'],
        '025' => ['Center Dr', 'M-37'],
        '031' => ['Alphenhorn Dr', 'M-37'],
        '032' => ['44th St', 'M-37'],
        '073' => ['Fulton', 'M-37'],
        '074' => ['M-6', 'M-37'],
        // M-44
        '066' => ['M-44 Connector', 'M-44'],
        '075' => ['Leonard St', 'M-44'],
        // M-6
        '027' => ['Kalamazoo Ave', 'M-6'],
        '029' => ['Hanna Lake Ave', 'M-6'],
        '099' => ['Byron Center Ave', 'M-6'],
        // US-131
        '005' => ['M-6', 'US-131'],
        '006' => ['I-96 Ramps', 'US-131'],
        '007' => ['Wealthy St', 'US-131'],
        '014' => ['44th St', 'US-131'],
        '016' => ['36th St', 'US-131'],
        '017' => ['54th St', 'US-131'],
        '040' => ['6 Mile Rd', 'US-131'],
        '043' => ['Pine Island Dr', 'US-131'],
        '044' => ['Post Dr', 'US-131'],
        '047' => ['Leonard St', 'US-131'],
        '048' => ['West River Dr', 'US-131'],
        '049' => ['Ann St', 'US-131'],
        '051' => ['Franklin St', 'US-131'],
        '052' => ['US-131 interchange', 'US-131'],
        '053' => ['I-96', 'US-131'],
        '056' => ['Market Ave', 'US-131'],
        '057' => ['Hall St', 'US-131'],
        '061' => ['M-11', 'US-131'],
        '063' => ['Pearl St', 'US-131'],
        '100' => ['Burton St', 'US-131'],
        '101' => ['US-131 interchange 2', 'US-131'],
        // US-31
        '102' => ['Hayes St', 'US-31'],
        '103' => ['Robbins Rd', 'US-31'],
        '104' => ['Taylor Ave', 'US-31'],
        '105' => ['Washington Ave', 'US-31'],
        // Other
        '036' => ['44th Street @ Division Ave', 'Other'],
    ];
}

/** @return array<string,array{0:string,1:string}> internet_cam id => [label, route] */
function camwall_metro_internet_cam_labels(): array
{
    return [
        '107' => ['92nd St.', 'US-131'],
        '108' => ['Saugatuck Rest Area', 'I-196'],
        '154' => ['Byron Rd', 'I-196'],
        '155' => ['M-45', 'US-31'],
        '166' => ['Snow Ave', 'I-96'],
        '167' => ['M-40', 'US-31'],
        '168' => ['S of 24th St', 'US-31'],
        '169' => ['Chicago Dr', 'US-31'],
        '170' => ['Washington', 'US-31'],
        '201' => ['68th Ave', 'I-96'],
        '202' => ['E of 24th Ave', 'I-96'],
        '203' => ['M11', 'I-96'],
        '207' => ['60th St', 'I-196'],
        '208' => ['M40', 'I-196'],
        '209' => ['Zeeland Rest Area', 'I-196'],
        '212' => ['142nd Ave', 'US-131'],
    ];
}

/** Catalog keys that should also appear under additional route optgroups. */
function camwall_metro_also_routes(): array
{
    return [
        'gr-045' => ['I-196'],
        'gr-052' => ['I-196'],
        'gr-101' => ['I-196'],
    ];
}

/** @return array<string,array<string,mixed>> */
function camwall_metro_cameras(): array
{
    $skipUrls = array_flip(camwall_corridor_snapshot_urls());
    $alsoRoutes = camwall_metro_also_routes();
    $out = [];
    $sort = 100;

    foreach (camwall_metro_grand_cam_labels() as $id => [$name, $route]) {
        $url = 'https://micamerasimages.net/thumbs/grand_cam_' . $id . '.flv.jpg?item=1';
        if (isset($skipUrls[$url])) {
            continue;
        }
        $key = 'gr-' . $id;
        $entry = [
            'name' => $name,
            'route' => $route,
            'url' => $url,
            'sort' => $sort++,
        ];
        if (!empty($alsoRoutes[$key])) {
            $entry['also_routes'] = $alsoRoutes[$key];
        }
        $out[$key] = $entry;
    }

    foreach (camwall_metro_internet_cam_labels() as $id => [$name, $route]) {
        $url = 'https://micamerasimages.net/thumbs/internet_cam_' . $id . '.flv.jpg?item=1';
        if (isset($skipUrls[$url])) {
            continue;
        }
        $key = 'inet-' . $id;
        $entry = [
            'name' => $name,
            'route' => $route,
            'url' => $url,
            'sort' => $sort++,
        ];
        if (!empty($alsoRoutes[$key])) {
            $entry['also_routes'] = $alsoRoutes[$key];
        }
        $out[$key] = $entry;
    }

    return $out;
}
