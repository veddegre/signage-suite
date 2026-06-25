<?php
/**
 * Cloud outage status — fetch + normalize public provider feeds.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_lib.php';

const OUTAGES_CACHE_DIR = __DIR__ . '/cache';

/** @return list<string> */
function outages_enabled_providers(): array
{
    $map = [
        'aws' => (bool)cfg('outages.ENABLE_AWS', true),
        'azure' => (bool)cfg('outages.ENABLE_AZURE', true),
        'github' => (bool)cfg('outages.ENABLE_GITHUB', true),
        'cloudflare' => (bool)cfg('outages.ENABLE_CLOUDFLARE', true),
        'o365' => (bool)cfg('outages.ENABLE_O365', true),
        'google' => (bool)cfg('outages.ENABLE_GOOGLE', true),
    ];
    $out = [];
    foreach ($map as $id => $on) {
        if ($on) {
            $out[] = $id;
        }
    }
    return $out;
}

function outages_cache_ttl(): int
{
    return max(30, (int)cfg('outages.CACHE_TTL', 120));
}

function outages_max_issues(): int
{
    return max(1, min(5, (int)cfg('outages.MAX_ISSUES', 3)));
}

function outages_us_only(): bool
{
    return (bool)cfg('outages.US_ONLY', true);
}

function outages_cache_key(string $id): string
{
    return $id . (outages_us_only() ? '_us' : '_all');
}

function outages_text_is_us(string $text): bool
{
    if ($text === '') {
        return false;
    }
    if (preg_match('/\bUnited States\b|,\s*US\b|\bU\.S\.\b|\bUSA\b/i', $text)) {
        return true;
    }
    if (preg_match('/\bUS East\b|\bUS West\b|GovCloud|\bN\. Virginia\b|\bN\. California\b/i', $text)) {
        return true;
    }
    if (preg_match('/\b(Virginia|Ohio|Oregon|California|Texas|Illinois|Florida|Georgia|Arizona|Colorado|Washington)\b/i', $text)
        && !preg_match('/\b(Bahrain|UAE|India|Europe|Asia|Pacific|London|Paris|Frankfurt|Tokyo|Sydney|Singapore|Amsterdam|Dubai|Delhi|Mumbai|Chennai)\b/i', $text)) {
        return true;
    }
    return (bool)preg_match('/\b(East US|West US|Central US|North Central US|South Central US|West Central US)\b/i', $text);
}

function outages_text_is_non_us_region(string $text): bool
{
    return (bool)preg_match('/\b(Europe|Asia Pacific|Middle East|Africa|South America|UK|United Kingdom|'
        . 'Australia|Japan|Korea|Singapore|India|UAE|Bahrain|China|Canada|Mexico|Brazil|Germany|France|Ireland|'
        . 'Netherlands|Sweden|Norway|Poland|Italy|Spain|Delhi|Chennai|Mumbai|Tokyo|Seoul|Sydney|London|Paris|Frankfurt|'
        . 'Amsterdam|Dubai|Jakarta|Melbourne|Taiwan|Hong Kong|Africa|Zurich|Milan)\b/i', $text);
}

function outages_aws_is_us(array $item): bool
{
    $service = (string)($item['service'] ?? '');
    if (preg_match('/(?:^|-)(us-east-\d|us-west-\d|us-gov-[a-z]+-\d)(?:$|-)/i', $service)) {
        return true;
    }
    return outages_text_is_us((string)($item['region_name'] ?? ''));
}

/** @param array<string,mixed> $location */
function outages_google_location_is_us(array $location): bool
{
    $title = trim((string)($location['title'] ?? $location['location'] ?? ''));
    if ($title === '') {
        $title = json_encode($location, JSON_UNESCAPED_UNICODE) ?: '';
    }
    return outages_text_is_us($title);
}

/** @param array<string,mixed> $item */
function outages_google_incident_affects_us(array $item): bool
{
    $locs = [];
    foreach (['currently_affected_locations', 'previously_affected_locations'] as $key) {
        foreach ($item[$key] ?? [] as $loc) {
            if (is_array($loc)) {
                $locs[] = $loc;
            }
        }
    }
    if ($locs !== []) {
        foreach ($locs as $loc) {
            if (outages_google_location_is_us($loc)) {
                return true;
            }
        }
        return false;
    }
    $text = trim((string)($item['external_desc'] ?? ''));
    if ($text === '') {
        return true;
    }
    if (outages_text_is_us($text)) {
        return true;
    }
    return !outages_text_is_non_us_region($text);
}

/** @param array{title:string,detail:string,impact:string} $issue */
function outages_rss_issue_is_us(array $issue): bool
{
    $text = $issue['title'] . ' ' . $issue['detail'];
    if (outages_text_is_us($text)) {
        return true;
    }
    if (outages_text_is_non_us_region($text)) {
        return false;
    }
    return true;
}

function outages_statuspage_name_is_us(string $name): bool
{
    if (outages_text_is_us($name)) {
        return true;
    }
    if (preg_match('/,\s*[A-Z]{2},\s*United States\b/i', $name)) {
        return true;
    }
    if (preg_match('/\((IAD|EWR|ORD|DFW|LAX|SJC|SEA|MIA|ATL|PHX|DEN|BOS|IAD|DCA|BWI|CLT|MSP|DTW|PHL|SLC|PDX|SAN|AUS|HOU|MCO|TPA|BNA|STL|IND|CMH|CVG|PIT|RDU|ORF|FSD|OMA|MCI|ABQ|ELP|TUS|SMF|SNA|OAK|JFK|LGA|SFO)\)/i', $name)) {
        return true;
    }
    return false;
}

/** @return array{body:false|string,code:int,err:string} */
function outages_http_get(string $url, array $headers = [], int $timeout = 12): array
{
    $policy = signage_fetch_url_allowed($url, signage_allow_private_fetch());
    if (!$policy['ok']) {
        return ['body' => false, 'code' => 0, 'err' => (string)($policy['error'] ?? 'blocked URL')];
    }
    if (!function_exists('curl_init')) {
        return ['body' => false, 'code' => 0, 'err' => 'PHP curl extension missing'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: HomeSignage/OutagesBoard/1.0'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'body' => is_string($body) ? $body : false,
        'code' => $code,
        'err' => $err,
    ];
}

function outages_decode_body(string $raw): string
{
    if (strlen($raw) >= 2 && ((($raw[0] === "\xFF") && ($raw[1] === "\xFE")) || (($raw[0] === "\xFE") && ($raw[1] === "\xFF")))) {
        $conv = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        if (is_string($conv) && $conv !== '') {
            return $conv;
        }
    }
    return $raw;
}

function outages_plain(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function outages_google_title(string $desc): string
{
    if (preg_match('/\*\*Title\*\*\s*\n(.+?)(?:\n|$)/u', $desc, $m)) {
        return trim($m[1]);
    }
    $line = strtok($desc, "\n");
    return trim(is_string($line) ? $line : $desc);
}

/** @param list<string> $statuses */
function outages_worst_status(array $statuses): string
{
    $rank = ['operational' => 0, 'maintenance' => 1, 'degraded' => 2, 'outage' => 3, 'unknown' => 1, 'unconfigured' => 0];
    $worst = 'operational';
    $score = 0;
    foreach ($statuses as $s) {
        $s = (string)$s;
        $r = $rank[$s] ?? 1;
        if ($r > $score) {
            $score = $r;
            $worst = $s;
        }
    }
    return $worst;
}

function outages_statuspage_indicator(?string $indicator): string
{
    return match (strtolower(trim((string)$indicator))) {
        'none' => 'operational',
        'minor' => 'degraded',
        'major', 'critical' => 'outage',
        'maintenance' => 'maintenance',
        default => 'unknown',
    };
}

function outages_statuspage_component(?string $status): string
{
    return match (strtolower(trim((string)$status))) {
        'operational' => 'operational',
        'degraded_performance', 'partial_outage' => 'degraded',
        'major_outage' => 'outage',
        'under_maintenance' => 'maintenance',
        default => 'unknown',
    };
}

/** @return array{id:string,name:string,status:string,summary:string,issues:list<array{title:string,detail:string,impact:string}>,url:string,error:?string} */
function outages_provider_shell(string $id, string $name, string $url): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'unknown',
        'summary' => 'Status unavailable',
        'issues' => [],
        'url' => $url,
        'error' => null,
    ];
}

/** @return array<string,mixed>|null */
function outages_cached_json(string $key, callable $fetch): ?array
{
    if (!is_dir(OUTAGES_CACHE_DIR)) {
        @mkdir(OUTAGES_CACHE_DIR, 0775, true);
    }
    $f = OUTAGES_CACHE_DIR . '/outages_' . outages_cache_key($key) . '.json';
    $ttl = outages_cache_ttl();
    if ($ttl > 0 && is_file($f) && (time() - filemtime($f)) < $ttl) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            return $d;
        }
    }
    $d = $fetch();
    if (is_array($d)) {
        @file_put_contents($f, json_encode($d, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $d;
    }
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/** @return array<string,mixed> */
function outages_fetch_statuspage(string $id, string $name, string $host, bool $regional = false): array
{
    $out = outages_provider_shell($id, $name, 'https://' . $host . '/');
    $resp = outages_http_get('https://' . $host . '/api/v2/summary.json');
    if ($resp['body'] === false || $resp['code'] !== 200) {
        $out['error'] = $resp['err'] !== '' ? 'curl: ' . $resp['err'] : 'HTTP ' . $resp['code'];
        return $out;
    }
    $data = json_decode($resp['body'], true);
    if (!is_array($data)) {
        $out['error'] = 'Invalid JSON';
        return $out;
    }

    $usOnly = outages_us_only();
    $pageStatus = outages_statuspage_indicator($data['status']['indicator'] ?? null);
    $summary = trim((string)($data['status']['description'] ?? ''));
    $issues = [];
    $statuses = ($regional && $usOnly) ? ['operational'] : [$pageStatus];

    foreach ($data['components'] ?? [] as $comp) {
        if (!is_array($comp) || !empty($comp['group'])) {
            continue;
        }
        $compName = trim((string)($comp['name'] ?? 'Component'));
        if ($usOnly && $regional && !outages_statuspage_name_is_us($compName)) {
            continue;
        }
        $cStatus = outages_statuspage_component($comp['status'] ?? null);
        if ($cStatus === 'operational') {
            continue;
        }
        $statuses[] = $cStatus;
        $issues[] = [
            'title' => $compName,
            'detail' => str_replace('_', ' ', (string)($comp['status'] ?? '')),
            'impact' => $cStatus,
        ];
    }

    foreach ($data['incidents'] ?? [] as $inc) {
        if (!is_array($inc)) {
            continue;
        }
        $incName = trim((string)($inc['name'] ?? 'Incident'));
        if ($usOnly && $regional && !outages_text_is_us($incName) && outages_text_is_non_us_region($incName)) {
            continue;
        }
        $impact = outages_statuspage_indicator($inc['impact'] ?? 'minor');
        $statuses[] = $impact === 'operational' ? 'degraded' : $impact;
        $issues[] = [
            'title' => $incName,
            'detail' => ucfirst(str_replace('_', ' ', (string)($inc['status'] ?? ''))),
            'impact' => $impact,
        ];
    }

    $out['status'] = outages_worst_status($statuses);
    if ($usOnly && $regional) {
        $out['summary'] = $issues === []
            ? 'No active US incidents'
            : count($issues) . ' active US incident' . (count($issues) === 1 ? '' : 's');
    } else {
        $out['summary'] = $summary !== '' ? $summary : ($out['status'] === 'operational' ? 'All systems operational' : 'Active incidents');
        if ($usOnly && !$regional) {
            $out['summary'] = ($out['summary'] !== '' ? $out['summary'] : 'All systems operational') . ' (global)';
        }
    }
    $out['issues'] = array_slice($issues, 0, outages_max_issues());
    return $out;
}

/** @return array<string,mixed> */
function outages_fetch_aws(): array
{
    $out = outages_provider_shell('aws', 'AWS', 'https://health.aws.amazon.com/health/status');
    $resp = outages_http_get('https://status.aws.amazon.com/data.json', [], 20);
    if ($resp['body'] === false || $resp['code'] !== 200) {
        $out['error'] = $resp['err'] !== '' ? 'curl: ' . $resp['err'] : 'HTTP ' . $resp['code'];
        return $out;
    }
    $data = json_decode(outages_decode_body($resp['body']), true);
    if (!is_array($data)) {
        $out['error'] = 'Invalid JSON';
        return $out;
    }
    if ($data === []) {
        $out['status'] = 'operational';
        $out['summary'] = outages_us_only() ? 'No active US events' : 'No active events';
        return $out;
    }

    $issues = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (outages_us_only() && !outages_aws_is_us($item)) {
            continue;
        }
        $region = trim((string)($item['region_name'] ?? ''));
        $service = trim((string)($item['service_name'] ?? 'AWS'));
        $summary = trim((string)($item['summary'] ?? 'Operational issue'));
        $title = $service . ($region !== '' ? ' · ' . $region : '');
        $issues[] = [
            'title' => $title,
            'detail' => $summary,
            'impact' => 'outage',
        ];
    }
    if ($issues === []) {
        $out['status'] = 'operational';
        $out['summary'] = outages_us_only() ? 'No active US events' : 'No active events';
        return $out;
    }
    $out['status'] = 'outage';
    $out['summary'] = count($issues) . ' active ' . (outages_us_only() ? 'US ' : '') . 'event' . (count($issues) === 1 ? '' : 's');
    $out['issues'] = array_slice($issues, 0, outages_max_issues());
    return $out;
}

/** @return list<array{title:string,detail:string,impact:string}> */
function outages_parse_rss_items(string $xml): array
{
    libxml_use_internal_errors(true);
    $root = simplexml_load_string($xml);
    if ($root === false) {
        return [];
    }
    $issues = [];
    foreach ($root->channel->item ?? [] as $item) {
        $title = trim((string)$item->title);
        $desc = outages_plain((string)$item->description);
        if ($title === '' && $desc === '') {
            continue;
        }
        if ($title === '' && $desc !== '') {
            $title = $desc;
            $desc = '';
        }
        $issues[] = [
            'title' => $title,
            'detail' => $desc,
            'impact' => 'degraded',
        ];
    }
    return $issues;
}

/** @return array<string,mixed> */
function outages_fetch_azure(): array
{
    $out = outages_provider_shell('azure', 'Azure', 'https://azure.status.microsoft/');
    $resp = outages_http_get('https://rssfeed.azure.status.microsoft/en-us/status/feed/');
    if ($resp['body'] === false || $resp['code'] !== 200) {
        $out['error'] = $resp['err'] !== '' ? 'curl: ' . $resp['err'] : 'HTTP ' . $resp['code'];
        return $out;
    }
    $issues = outages_parse_rss_items($resp['body']);
    if (outages_us_only()) {
        $issues = array_values(array_filter($issues, 'outages_rss_issue_is_us'));
    }
    if ($issues === []) {
        $out['status'] = 'operational';
        $out['summary'] = outages_us_only() ? 'No active US events' : 'No active events';
        return $out;
    }
    $out['status'] = outages_worst_status(array_column($issues, 'impact'));
    $out['summary'] = count($issues) . ' active posting' . (count($issues) === 1 ? '' : 's');
    $out['issues'] = array_slice($issues, 0, outages_max_issues());
    return $out;
}

/** @return array<string,mixed> */
function outages_fetch_google(): array
{
    $out = outages_provider_shell('google', 'Google Workspace', 'https://www.google.com/appsstatus/dashboard/');
    $resp = outages_http_get('https://www.google.com/appsstatus/dashboard/incidents.json', [], 20);
    if ($resp['body'] === false || $resp['code'] !== 200) {
        $out['error'] = $resp['err'] !== '' ? 'curl: ' . $resp['err'] : 'HTTP ' . $resp['code'];
        return $out;
    }
    $data = json_decode($resp['body'], true);
    if (!is_array($data)) {
        $out['error'] = 'Invalid JSON';
        return $out;
    }

    $issues = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $end = $item['end'] ?? null;
        if ($end !== null && trim((string)$end) !== '') {
            continue;
        }
        if (outages_us_only() && !outages_google_incident_affects_us($item)) {
            continue;
        }
        $impactRaw = strtoupper((string)($item['status_impact'] ?? ''));
        $impact = str_contains($impactRaw, 'DISRUPTION') || str_contains($impactRaw, 'OUTAGE')
            ? 'outage'
            : (str_contains($impactRaw, 'INFORMATION') ? 'degraded' : 'degraded');
        $desc = trim((string)($item['external_desc'] ?? ''));
        $title = outages_google_title($desc);
        if ($title === '') {
            $title = trim((string)($item['service_name'] ?? 'Google Workspace'));
        }
        $products = [];
        foreach ($item['affected_products'] ?? [] as $prod) {
            if (is_array($prod) && !empty($prod['title'])) {
                $products[] = (string)$prod['title'];
            }
        }
        $detail = $products !== [] ? implode(', ', array_slice($products, 0, 4)) : trim((string)($item['service_name'] ?? ''));
        $issues[] = [
            'title' => $title,
            'detail' => $detail,
            'impact' => $impact,
        ];
    }

    if ($issues === []) {
        $out['status'] = 'operational';
        $out['summary'] = outages_us_only() ? 'No active US incidents' : 'No active incidents';
        return $out;
    }
    $out['status'] = outages_worst_status(array_column($issues, 'impact'));
    $out['summary'] = count($issues) . ' active incident' . (count($issues) === 1 ? '' : 's');
    $out['issues'] = array_slice($issues, 0, outages_max_issues());
    return $out;
}

function outages_ms_graph_configured(): bool
{
    return trim((string)cfg('outages.MS_GRAPH_TENANT_ID', '')) !== ''
        && trim((string)cfg('outages.MS_GRAPH_CLIENT_ID', '')) !== ''
        && trim((string)cfg('outages.MS_GRAPH_CLIENT_SECRET', '')) !== '';
}

function outages_ms_graph_token(): ?string
{
    if (!outages_ms_graph_configured()) {
        return null;
    }
    $cached = outages_cached_json('ms_graph_token', static function (): ?array {
        $tenant = trim((string)cfg('outages.MS_GRAPH_TENANT_ID', ''));
        $client = trim((string)cfg('outages.MS_GRAPH_CLIENT_ID', ''));
        $secret = trim((string)cfg('outages.MS_GRAPH_CLIENT_SECRET', ''));
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $client,
                'client_secret' => $secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            CURLOPT_HTTPHEADER => ['User-Agent: HomeSignage/OutagesBoard/1.0'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            return null;
        }
        $d = json_decode($body, true);
        if (!is_array($d) || empty($d['access_token'])) {
            return null;
        }
        $expires = max(60, (int)($d['expires_in'] ?? 3600) - 120);
        return [
            'token' => (string)$d['access_token'],
            'expires_at' => time() + $expires,
        ];
    });
    if (!is_array($cached) || empty($cached['token'])) {
        return null;
    }
    if ((int)($cached['expires_at'] ?? 0) <= time()) {
        @unlink(OUTAGES_CACHE_DIR . '/outages_' . outages_cache_key('ms_graph_token') . '.json');
        return outages_ms_graph_token();
    }
    return (string)$cached['token'];
}

function outages_ms_service_status(?string $status): string
{
    $s = strtolower(trim((string)$status));
    if ($s === '' || str_contains($s, 'operational')) {
        return 'operational';
    }
    if (str_contains($s, 'restored')) {
        return 'degraded';
    }
    if (str_contains($s, 'interruption') || str_contains($s, 'extendedrecovery')) {
        return 'outage';
    }
    if (str_contains($s, 'degradation') || str_contains($s, 'investigating') || str_contains($s, 'advisory')) {
        return 'degraded';
    }
    return 'degraded';
}

function outages_ms_public_status(?string $status): string
{
    $s = strtolower(trim((string)$status));
    if ($s === '' || in_array($s, ['available', 'operational'], true)) {
        return 'operational';
    }
    if (str_contains($s, 'unavailable') || str_contains($s, 'outage')) {
        return 'outage';
    }
    return 'degraded';
}

/** @return array<string,mixed>|null */
function outages_ms_public_post(string $path): ?array
{
    $resp = outages_http_get('https://status.office.com/api/posts/' . rawurlencode($path));
    if ($resp['body'] === false || $resp['code'] !== 200) {
        return null;
    }
    $data = json_decode($resp['body'], true);
    return is_array($data) ? $data : null;
}

/**
 * Public M365 backup status — no app registration.
 * Microsoft only publishes here when widespread issues affect the admin center / public status page.
 * Does not replace tenant-specific Graph health (Exchange, Teams, SharePoint per org).
 *
 * @return array<string,mixed>
 */
function outages_fetch_o365_public(): array
{
    $out = outages_provider_shell('o365', 'Microsoft 365', 'https://status.cloud.microsoft/');
    $post = outages_ms_public_post('mac');
    if ($post === null) {
        $out['error'] = 'Public status feed unavailable';
        return $out;
    }

    $statusRaw = trim((string)($post['Status'] ?? ''));
    $st = outages_ms_public_status($statusRaw);
    $message = outages_plain((string)($post['Message'] ?? ''));
    $title = trim((string)($post['Title'] ?? ''));
    if ($title === '') {
        $title = 'Microsoft 365 Service Health';
    }

    $out['status'] = $st;
    if ($st === 'operational') {
        $out['summary'] = 'No public advisories (backup feed)';
        return $out;
    }

    $out['summary'] = 'Public advisory active';
    $out['issues'] = [[
        'title' => $title,
        'detail' => $message !== '' ? $message : $statusRaw,
        'impact' => $st,
    ]];
    return $out;
}

/** @return array<string,mixed> */
function outages_fetch_o365_graph(): array
{
    $out = outages_provider_shell('o365', 'Microsoft 365', 'https://status.cloud.microsoft/');
    $token = outages_ms_graph_token();
    if ($token === null) {
        $out['error'] = 'Graph token request failed';
        return $out;
    }

    $issues = [];
    $statuses = ['operational'];

    $health = outages_http_get(
        'https://graph.microsoft.com/v1.0/admin/serviceAnnouncement/healthOverviews',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    if ($health['body'] !== false && $health['code'] === 200) {
        $data = json_decode($health['body'], true);
        foreach ($data['value'] ?? [] as $svc) {
            if (!is_array($svc)) {
                continue;
            }
            $st = outages_ms_service_status($svc['status'] ?? null);
            if ($st === 'operational') {
                continue;
            }
            $statuses[] = $st;
            $issues[] = [
                'title' => trim((string)($svc['service'] ?? 'Microsoft 365')),
                'detail' => trim(str_replace('service', ' ', (string)($svc['status'] ?? ''))),
                'impact' => $st,
            ];
        }
    }

    $inc = outages_http_get(
        'https://graph.microsoft.com/v1.0/admin/serviceAnnouncement/issues?$filter=isResolved eq false&$top=10',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    if ($inc['body'] !== false && $inc['code'] === 200) {
        $data = json_decode($inc['body'], true);
        foreach ($data['value'] ?? [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $st = outages_ms_service_status($issue['status'] ?? null);
            $title = trim((string)($issue['title'] ?? 'Service issue'));
            $service = trim((string)($issue['service'] ?? ''));
            $detail = trim((string)($issue['impactDescription'] ?? ''));
            $label = $service !== '' ? $service . ' — ' . $title : $title;
            if (outages_us_only()) {
                $geo = $label . ' ' . $detail;
                if (outages_text_is_non_us_region($geo) && !outages_text_is_us($geo)) {
                    continue;
                }
            }
            $statuses[] = $st;
            $issues[] = [
                'title' => $label,
                'detail' => $detail,
                'impact' => $st,
            ];
        }
    }

    if ($health['code'] !== 200 && $inc['code'] !== 200) {
        $out['error'] = 'Graph HTTP ' . max($health['code'], $inc['code']);
        return $out;
    }

    $issues = array_values(array_reduce($issues, static function (array $carry, array $item): array {
        $key = strtolower($item['title']);
        $carry[$key] = $item;
        return $carry;
    }, []));

    if ($issues === []) {
        $out['status'] = 'operational';
        $out['summary'] = outages_us_only() ? 'All services operational (tenant view)' : 'All services operational';
        return $out;
    }

    $out['status'] = outages_worst_status($statuses);
    $out['summary'] = count($issues) . ' active issue' . (count($issues) === 1 ? '' : 's');
    if (outages_us_only()) {
        $out['summary'] .= ' · tenant health';
    }
    $out['issues'] = array_slice($issues, 0, outages_max_issues());
    return $out;
}

/** @return array<string,mixed> */
function outages_fetch_o365(): array
{
    if (outages_ms_graph_configured()) {
        return outages_fetch_o365_graph();
    }
    return outages_fetch_o365_public();
}

/** @return list<array<string,mixed>> */
function outages_fetch_all(): array
{
    $providers = [];
    foreach (outages_enabled_providers() as $id) {
        $cacheId = $id === 'o365' && outages_ms_graph_configured() ? 'o365_graph' : ($id === 'o365' ? 'o365_public' : $id);
        $data = outages_cached_json($cacheId, static function () use ($id): ?array {
            return match ($id) {
                'aws' => outages_fetch_aws(),
                'azure' => outages_fetch_azure(),
                'github' => outages_fetch_statuspage('github', 'GitHub', 'www.githubstatus.com', false),
                'cloudflare' => outages_fetch_statuspage('cloudflare', 'Cloudflare', 'www.cloudflarestatus.com', true),
                'o365' => outages_fetch_o365(),
                'google' => outages_fetch_google(),
                default => null,
            };
        });
        if (is_array($data)) {
            $providers[] = $data;
        }
    }
    return $providers;
}

function outages_status_label(string $status): string
{
    return match ($status) {
        'operational' => 'Operational',
        'degraded' => 'Degraded',
        'outage' => 'Outage',
        'maintenance' => 'Maintenance',
        'unconfigured' => 'Not configured',
        default => 'Unknown',
    };
}

function outages_overall_status(array $providers): string
{
    return outages_worst_status(array_map(static fn($p) => (string)($p['status'] ?? 'unknown'), $providers));
}
