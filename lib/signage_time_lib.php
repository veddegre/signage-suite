<?php
/**
 * 12/24-hour clock formatting — global default with per-screen override via rotation.
 * Rotation passes ?clockfmt=12|24 on board iframes; direct views use ?screen= or global default.
 */

/** True when ?clockfmt= suppresses format (rotation iframe). */
function signage_clock_format_from_query(): ?bool
{
    if (!isset($_GET['clockfmt'])) {
        return null;
    }

    return (string)$_GET['clockfmt'] === '24';
}

/** Effective 24-hour mode for the current request. */
function signage_clock_24h(?string $screen = null): bool
{
    $fromQuery = signage_clock_format_from_query();
    if ($fromQuery !== null) {
        return $fromQuery;
    }
    require_once __DIR__ . '/rotation_lib.php';
    if ($screen === null && isset($_GET['screen']) && trim((string)$_GET['screen']) !== '') {
        $screen = rotation_normalize_screen_key((string)$_GET['screen']);
    }
    if ($screen !== null && $screen !== '') {
        return rotation_screen_clock_24h($screen);
    }

    return rotation_global_clock_24h();
}

function signage_time_format_php(): string
{
    return signage_time_format_php_for(null);
}

/** @return non-empty-string Query fragment e.g. clockfmt=24 (no leading &). */
function signage_clock_format_query(?string $screen = null): string
{
    return 'clockfmt=' . (signage_clock_24h($screen) ? '24' : '12');
}

/** @param array<string,mixed> $extra */
function signage_js_clock_locale_options(?string $timezone = null, ?string $screen = null, array $extra = []): array
{
    $opts = [
        'hour' => signage_clock_24h($screen) ? '2-digit' : 'numeric',
        'minute' => '2-digit',
        'hour12' => !signage_clock_24h($screen),
    ];
    if ($timezone !== null && $timezone !== '') {
        $opts['timeZone'] = $timezone;
    }

    return array_merge($opts, $extra);
}

function signage_js_clock_options_json(?string $timezone = null, ?string $screen = null): string
{
    return json_encode(signage_js_clock_locale_options($timezone, $screen), JSON_UNESCAPED_SLASHES);
}

function signage_format_time(int $ts, ?DateTimeZone $tz = null, ?string $screen = null): string
{
    $fmt = signage_time_format_php_for($screen);
    if ($tz !== null) {
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);

        return $dt->format($fmt);
    }

    return date($fmt, $ts);
}

function signage_format_datetime(int $ts, string $dateFormat = 'M j', ?DateTimeZone $tz = null, ?string $screen = null): string
{
    $sep = str_ends_with(trim($dateFormat), ',') ? ' ' : ', ';
    $fmt = trim($dateFormat) . $sep . signage_time_format_php_for($screen);
    if ($tz !== null) {
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);

        return $dt->format($fmt);
    }

    return date($fmt, $ts);
}

function signage_time_format_php_for(?string $screen = null): string
{
    return signage_clock_24h($screen) ? 'H:i' : 'g:i A';
}

function signage_format_time_range(int $start, int $end, ?string $screen = null): string
{
    if (signage_clock_24h($screen)) {
        return date('H:i', $start) . '–' . date('H:i', $end);
    }

    return date('g:i', $start) . '–' . date('g:i A', $end);
}

function signage_dt_format_time(\DateTimeInterface $dt, ?string $screen = null): string
{
    return $dt->format(signage_time_format_php_for($screen));
}

function signage_dt_format_datetime(\DateTimeInterface $dt, string $dateFormat, ?string $screen = null): string
{
    $sep = str_ends_with(trim($dateFormat), ',') ? ' ' : (str_contains($dateFormat, '·') ? ' · ' : ', ');

    return $dt->format(trim($dateFormat) . $sep . signage_time_format_php_for($screen));
}

/** Reusable JS IIFE for a wall clock element. */
function signage_clock_tick_script(string $elementId = 'clock', ?string $timezone = null, ?string $screen = null): string
{
    $id = json_encode($elementId);
    $opts = signage_js_clock_options_json(null, $screen);
    $tzJs = $timezone !== null && $timezone !== '' ? json_encode($timezone) : 'null';

    return <<<JS
(function () {
  const tz = {$tzJs};
  const opts = {$opts};
  if (tz) opts.timeZone = tz;
  function tick() {
    const el = document.getElementById({$id});
    if (!el) return;
    el.textContent = new Date().toLocaleTimeString(undefined, opts);
  }
  tick();
  setInterval(tick, 1000);
})();
JS;
}

/** JS expression returning formatted time (for inline assignment). */
function signage_js_format_time_expr(string $dateVar = 'new Date()', ?string $timezoneVar = null, ?string $screen = null): string
{
    if ($dateVar === 'now' || $dateVar === 'n') {
        $dateVar = 'new Date()';
    }
    $opts = signage_js_clock_options_json(null, $screen);
    if ($timezoneVar !== null && $timezoneVar !== '') {
        return $dateVar . '.toLocaleTimeString(undefined, Object.assign(' . $opts . ', {timeZone: ' . $timezoneVar . '}))';
    }

    return $dateVar . '.toLocaleTimeString(undefined, ' . $opts . ')';
}
