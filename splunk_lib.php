<?php
/**
 * Splunk panels board — shared helpers for splunk.php and admin.
 */

require_once __DIR__ . '/config.php';

/** @return list<string> */
function splunk_panel_type_options(): array
{
    return ['single', 'list', 'trend'];
}

function splunk_panel_type_label(string $type): string
{
    return match (strtolower($type)) {
        'list' => 'Bar list',
        'trend' => 'Trend chart',
        default => 'Single stat',
    };
}

/** Default panel set (matches original splunk.php). */
function splunk_default_panels(): array
{
    return [
        ['title' => 'Events Today', 'type' => 'single', 'field' => 'count', 'earliest' => '@d',
         'spl' => 'index=network | stats count'],
        ['title' => 'Blocked Today', 'type' => 'single', 'field' => 'count', 'earliest' => '@d',
         'spl' => 'index=network action=denied | stats count'],
        ['title' => 'Active Sources (1h)', 'type' => 'single', 'field' => 'dc', 'earliest' => '-1h',
         'spl' => 'index=network | stats dc(src_ip) as dc'],
        ['title' => 'Top Blocked Countries (24h)', 'type' => 'list', 'label' => 'country', 'value' => 'count',
         'spl' => 'index=network action=denied | stats count by country | sort -count | head 6'],
        ['title' => 'Events Over Time (24h)', 'type' => 'trend', 'value' => 'count', 'wide' => true,
         'spl' => 'index=network | timechart span=1h count'],
    ];
}

/** All panels for admin (includes disabled). */
function splunk_admin_panels(?array $fromConfig = null): array
{
    if ($fromConfig === null) {
        $fromConfig = cfg('splunk.PANELS', null);
        if ($fromConfig === null) {
            return splunk_default_panels();
        }
    }
    if (!is_array($fromConfig)) {
        return splunk_default_panels();
    }
    if ($fromConfig === []) {
        return [];
    }
    return splunk_normalize_panels($fromConfig, false);
}

/** Panels for the wall — skips disabled entries. */
function splunk_wall_panels(?array $fromConfig = null): array
{
    if ($fromConfig === null) {
        $fromConfig = cfg('splunk.PANELS', null);
    }
    if ($fromConfig === null) {
        $panels = splunk_default_panels();
    } elseif (!is_array($fromConfig) || $fromConfig === []) {
        return [];
    } else {
        $panels = splunk_normalize_panels($fromConfig, false);
    }
    $out = [];
    foreach ($panels as $p) {
        if (!empty($p['off'])) {
            continue;
        }
        $out[] = $p;
    }
    return $out;
}

/**
 * Normalize panel list from config or POST.
 * @param list<mixed> $raw
 * @return list<array<string,mixed>>
 */
function splunk_normalize_panels(array $raw, bool $dropEmptySpl = true): array
{
    $out = [];
    foreach ($raw as $p) {
        if (!is_array($p)) {
            continue;
        }
        $norm = splunk_normalize_panel($p);
        if ($norm === null) {
            continue;
        }
        if ($dropEmptySpl && trim((string)($norm['spl'] ?? '')) === '') {
            continue;
        }
        $out[] = $norm;
    }
    return $out;
}

/** @return array<string,mixed>|null */
function splunk_normalize_panel(array $p): ?array
{
    $type = strtolower(trim((string)($p['type'] ?? 'single')));
    if (!in_array($type, splunk_panel_type_options(), true)) {
        $type = 'single';
    }
    $spl = trim((string)($p['spl'] ?? ''));
    $title = trim((string)($p['title'] ?? ''));
    if ($title === '' && $spl === '') {
        return null;
    }
    if ($title === '') {
        $title = 'Panel';
    }

    $out = [
        'title' => $title,
        'type' => $type,
        'spl' => $spl,
    ];

    foreach (['field', 'label', 'value', 'unit', 'earliest', 'latest'] as $k) {
        $v = trim((string)($p[$k] ?? ''));
        if ($v !== '') {
            $out[$k] = $v;
        }
    }
    if (!empty($p['wide'])) {
        $out['wide'] = true;
    }
    if (!empty($p['off'])) {
        $out['off'] = true;
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function splunk_panels_from_post(array $rows): array
{
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $obj = [
            'title' => trim((string)($row['title'] ?? '')),
            'type' => trim((string)($row['type'] ?? 'single')),
            'spl' => trim((string)($row['spl'] ?? '')),
        ];
        foreach (['field', 'label', 'value', 'unit', 'earliest', 'latest'] as $k) {
            $v = trim((string)($row[$k] ?? ''));
            if ($v !== '') {
                $obj[$k] = $v;
            }
        }
        if (!empty($row['wide'])) {
            $obj['wide'] = true;
        }
        if (!empty($row['off'])) {
            $obj['off'] = true;
        }
        $norm = splunk_normalize_panel($obj);
        if ($norm === null || trim((string)($norm['spl'] ?? '')) === '') {
            continue;
        }
        $out[] = $norm;
    }
    return $out;
}

/** @return list<array<string,mixed>>|null null = invalid JSON */
function splunk_panels_from_json_string(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return null;
    }
    return splunk_normalize_panels($dec);
}

function splunk_configured(): bool
{
    return cfg('splunk.SPLUNK_TOKEN', '') !== ''
        && cfg('splunk.SPLUNK_TOKEN', '') !== 'PUT-YOUR-SPLUNK-AUTH-TOKEN-HERE';
}

function splunk_base_url(): string
{
    return (string)cfg('splunk.SPLUNK_BASE', 'https://192.168.86.30:8089');
}

function splunk_verify_tls(): bool
{
    return (bool)cfg('splunk.SPLUNK_VERIFY_TLS', false);
}

function splunk_cache_ttl(): int
{
    return max(30, (int)cfg('splunk.CACHE_TTL', 120));
}

function splunk_preview_url(): string
{
    return signage_board_preview_url('splunk.php');
}

/**
 * Run a oneshot Splunk search; returns result rows or null on failure.
 * @return list<array<string,mixed>>|null
 */
function splunk_oneshot(string $spl, string $earliest, string $latest, ?array &$diag = null): ?array
{
    if (!splunk_configured()) {
        if ($diag !== null) {
            $diag = 'Splunk token not configured';
        }
        return null;
    }

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $ttl = splunk_cache_ttl();
    $key = 'splunk_' . md5($spl . $earliest . $latest);
    $f = $cacheDir . "/$key.json";
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) {
            return $d;
        }
    }

    $search = stripos(ltrim($spl), 'search ') === 0 || ltrim($spl)[0] === '|' ? $spl : 'search ' . $spl;
    $ch = curl_init(rtrim(splunk_base_url(), '/') . '/services/search/jobs?output_mode=json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'exec_mode' => 'oneshot',
            'search' => $search,
            'earliest_time' => $earliest,
            'latest_time' => $latest,
            'count' => 0,
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cfg('splunk.SPLUNK_TOKEN', '')],
        CURLOPT_SSL_VERIFYPEER => splunk_verify_tls(),
        CURLOPT_SSL_VERIFYHOST => splunk_verify_tls() ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body !== false && $code === 200) {
        $j = json_decode($body, true);
        if (isset($j['results']) && is_array($j['results'])) {
            @file_put_contents($f, json_encode($j['results']), LOCK_EX);
            return $j['results'];
        }
        if ($diag !== null) {
            $diag = 'no results array in response';
        }
    } else {
        if ($diag !== null) {
            $diag = $err !== '' ? "curl: $err" : "HTTP $code";
        }
    }

    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
    return null;
}

/**
 * Test one panel definition (admin).
 * @return array{ok:bool,rows:int,preview:?string,error:?string}
 */
function splunk_test_panel(array $panel): array
{
    $norm = splunk_normalize_panel($panel);
    if ($norm === null || trim((string)($norm['spl'] ?? '')) === '') {
        return ['ok' => false, 'rows' => 0, 'preview' => null, 'error' => 'Title and SPL are required.'];
    }
    if (!splunk_configured()) {
        return ['ok' => false, 'rows' => 0, 'preview' => null, 'error' => 'Set Splunk base URL and auth token in Board settings first.'];
    }

    $diag = null;
    $rows = splunk_oneshot(
        (string)$norm['spl'],
        (string)($norm['earliest'] ?? '-24h@h'),
        (string)($norm['latest'] ?? 'now'),
        $diag
    );

    if ($rows === null) {
        return ['ok' => false, 'rows' => 0, 'preview' => null, 'error' => $diag ?? 'Search failed'];
    }

    $preview = splunk_panel_preview_text($norm, $rows);
    return ['ok' => true, 'rows' => count($rows), 'preview' => $preview, 'error' => null];
}

/** Short human summary of first result row(s) for admin test feedback. */
function splunk_panel_preview_text(array $panel, array $rows): string
{
    if ($rows === []) {
        return 'Search OK — 0 rows returned.';
    }
    $type = (string)($panel['type'] ?? 'single');
    if ($type === 'single') {
        $field = (string)($panel['field'] ?? 'count');
        $v = $rows[0][$field] ?? null;
        return $v !== null ? "First row: $field = $v" : 'First row has no field "' . $field . '"';
    }
    if ($type === 'list') {
        $label = (string)($panel['label'] ?? 'label');
        $value = (string)($panel['value'] ?? 'count');
        $parts = [];
        foreach (array_slice($rows, 0, 3) as $r) {
            $parts[] = (string)($r[$label] ?? '?') . '=' . (string)($r[$value] ?? '?');
        }
        return count($rows) . ' rows — ' . implode(', ', $parts);
    }
    $value = (string)($panel['value'] ?? 'count');
    $nums = [];
    foreach ($rows as $r) {
        if (isset($r[$value]) && is_numeric($r[$value])) {
            $nums[] = (float)$r[$value];
        }
    }
    if ($nums === []) {
        return count($rows) . ' rows — no numeric "' . $value . '" field';
    }
    return count($rows) . ' points — max ' . max($nums) . ', latest ' . end($nums);
}

/** Build an SVG area chart from timechart rows. */
function splunk_trend_svg(array $rows, string $valueField, int $w = 1140, int $hgt = 240): string
{
    $pts = [];
    foreach ($rows as $r) {
        if (isset($r[$valueField]) && is_numeric($r[$valueField])) {
            $pts[] = (float)$r[$valueField];
        }
    }
    $n = count($pts);
    if ($n < 2) {
        return '<div class="nodata">not enough data</div>';
    }
    $max = max($pts);
    $max = $max > 0 ? $max : 1;
    $coords = [];
    foreach ($pts as $i => $v) {
        $x = round($i / ($n - 1) * $w, 1);
        $y = round($hgt - ($v / $max) * ($hgt - 14) - 4, 1);
        $coords[] = "$x,$y";
    }
    $line = implode(' ', $coords);
    $area = "0,$hgt " . $line . " $w,$hgt";
    return '<svg viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none">'
         . '<polygon points="' . $area . '" fill="rgba(255,179,71,.16)"/>'
         . '<polyline points="' . $line . '" fill="none" stroke="#ffb347" stroke-width="3" '
         . 'stroke-linejoin="round" stroke-linecap="round"/></svg>';
}

function splunk_fmt_num($n): string
{
    $n = (float)$n;
    if ($n >= 1e6) {
        return number_format($n / 1e6, 1) . 'M';
    }
    if ($n >= 1e4) {
        return number_format($n / 1e3, 1) . 'k';
    }
    return number_format($n);
}
