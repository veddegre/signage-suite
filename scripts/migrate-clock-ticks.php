#!/usr/bin/env php
<?php
/** Replace legacy 12h-only clock tick blocks with signage time helpers. */
$root = dirname(__DIR__) . '/boards';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$changed = [];

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $src = file_get_contents($path);
    $orig = $src;

    // Standalone compact tick (already partially migrated).
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*function tick\(\)\{\s*const n=new Date\(\);\s*let h=n\.getHours\(\);\s*const ap=h>=12\?\'PM\':\'AM\';\s*h=h%12\|\|12;\s*document\.getElementById\(\'clock\'\)\.textContent = h\+[\':\']+String\(n\.getMinutes\(\)\)\.padStart\(2,\'0\'\)\+\' \'+ap;\s*\}\s*tick\(\);\s*setInterval\(tick,\s*1000\);\s*<\?php endif; \?>/s',
        "<?php if (\$showClock): ?>\n  <?= signage_clock_tick_script('clock', TIMEZONE) ?>\n  <?php endif; ?>",
        $src
    );

    // Standalone compact tick with SHOW_CLOCK.
    $src = preg_replace(
        '/<\?php if \(SHOW_CLOCK\): \?>\s*function tick\(\)\{\s*const n=new Date\(\);\s*let h=n\.getHours\(\);\s*const ap=h>=12\?\'PM\':\'AM\';\s*h=h%12\|\|12;\s*document\.getElementById\(\'clock\'\)\.textContent = h\+[\':\']+String\(n\.getMinutes\(\)\)\.padStart\(2,\'0\'\)\+\' \'+ap;\s*\}\s*tick\(\);\s*setInterval\(tick,\s*1000\);\s*<\?php endif; \?>/s',
        "<?php if (SHOW_CLOCK): ?>\n  <?= signage_clock_tick_script('clock', TIMEZONE) ?>\n  <?php endif; ?>",
        $src
    );

    // Multiline tick with optional el check.
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*function tick\(\)\s*\{\s*const n = new Date\(\);\s*let h = n\.getHours\(\);\s*const ap = h >= 12 \? \'PM\' : \'AM\';\s*h = h % 12 \|\| 12;\s*const m = String\(n\.getMinutes\(\)\)\.padStart\(2, \'0\'\);\s*const el = document\.getElementById\(\'clock\'\);\s*if \(el\) el\.textContent = h \+ \':\' \+ m \+ \' \' \+ ap;\s*\}\s*tick\(\);\s*setInterval\(tick, 1000\);\s*<\?php endif; \?>/s',
        "<?php if (\$showClock): ?>\n  <?= signage_clock_tick_script('clock', TIMEZONE) ?>\n  <?php endif; ?>",
        $src
    );

    // Inline one-line script block (maps).
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*function tick\(\)\{const n=new Date\(\);let h=n\.getHours\(\);const ap=h>=12\?\'PM\':\'AM\';h=h%12\|\|12;\s*const el=document\.getElementById\(\'clock\'\);if\(el\)el\.textContent=h\+[\':\']+String\(n\.getMinutes\(\)\)\.padStart\(2,\'0\'\)\+\' \'+ap;\}\s*tick\(\);setInterval\(tick,1000\);\s*<\?php endif; \?>/s',
        "<?php if (\$showClock): ?><?= signage_clock_tick_script('clock', TIMEZONE) ?><?php endif; ?>",
        $src
    );

    // Embedded in shared tick() — variable n.
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*let h = n\.getHours\(\);\s*const ap = h >= 12 \? \'PM\' : \'AM\';\s*h = h % 12 \|\| 12;\s*document\.getElementById\(\'clock\'\)\.(?:innerHTML|textContent)\s*=\s*[\s\S]*?;\s*<\?php endif; \?>/',
        "<?php if (\$showClock): ?>\n    { const el = document.getElementById('clock'); if (el) el.textContent = <?= signage_js_format_time_expr('n') ?>; }\n    <?php endif; ?>",
        $src
    );

    // Embedded in shared tick() — variable now.
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*let h = now\.getHours\(\);\s*const ampm = h >= 12 \? \'PM\' : \'AM\';\s*h = h % 12 \|\| 12;\s*const m = String\(now\.getMinutes\(\)\)\.padStart\(2, \'0\'\);\s*document\.getElementById\(\'clock\'\)\.innerHTML\s*=\s*[\s\S]*?;\s*<\?php endif; \?>/',
        "<?php if (\$showClock): ?>\n    { const el = document.getElementById('clock'); if (el) el.textContent = <?= signage_js_format_time_expr('now') ?>; }\n    <?php endif; ?>",
        $src
    );

    // slides.php style inside function.
    $src = preg_replace(
        '/<\?php if \(\$showClock\): \?>\s*let h = n\.getHours\(\);\s*const ap = h >= 12 \? \'PM\' : \'AM\';\s*h = h % 12 \|\| 12;\s*document\.getElementById\(\'clock\'\)\.textContent\s*=\s*h \+ \':\' \+ String\(n\.getMinutes\(\)\)\.padStart\(2,\'0\'\) \+ \' \' \+ ap;\s*<\?php endif; \?>/s',
        "<?php if (\$showClock): ?>\n        { const el = document.getElementById('clock'); if (el) el.textContent = <?= signage_js_format_time_expr('n') ?>; }\n        <?php endif; ?>",
        $src
    );

    if ($src !== $orig) {
        file_put_contents($path, $src);
        $changed[] = $path;
    }
}

foreach ($changed as $path) {
    echo "updated: $path\n";
}
echo 'done (' . count($changed) . " files)\n";
