#!/usr/bin/env php
<?php
/**
 * Catch PHP parse errors and load-time fatals across the tree.
 *
 * Usage:
 *   php scripts/check-php.php [--root=PATH] [--lint-only] [--no-tests]
 *
 * Phases:
 *   1. php -l on every .php file (excluding vendor/)
 *   2. require config.php, schema.php, and each lib/*.php (CLI bootstrap)
 *   3. run scripts/test-*.php (offline unit checks)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$lintOnly = false;
$runTests = true;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
        continue;
    }
    if ($arg === '--lint-only') {
        $lintOnly = true;
        continue;
    }
    if ($arg === '--no-tests') {
        $runTests = false;
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        echo "Usage: php scripts/check-php.php [--root=PATH] [--lint-only] [--no-tests]\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown option: $arg\n");
    exit(2);
}

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "Not a signage root (missing config.php): $root\n");
    exit(1);
}

$failures = 0;

function check_fail(string $msg): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL: $msg\n");
}

function check_ok(string $msg): void
{
    echo "OK: $msg\n";
}

/** @return list<string> */
function collect_php_files(string $root): array
{
    $files = [];
    $skipDirs = ['vendor' => true, '.git' => true, 'node_modules' => true];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = substr($path, strlen($root) + 1);
        foreach (explode('/', $rel) as $part) {
            if (isset($skipDirs[$part])) {
                continue 2;
            }
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

echo "== Syntax lint (php -l)\n";
$lintCount = 0;
foreach (collect_php_files($root) as $path) {
    $lintCount++;
    $rel = substr($path, strlen($root) + 1);
    $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $out = shell_exec($cmd) ?? '';
    if (!str_contains($out, 'No syntax errors detected')) {
        check_fail("$rel — $out");
    }
}
check_ok("$lintCount PHP file(s) linted");

if ($lintOnly) {
    exit(($failures > 0) ? 1 : 0);
}

echo "== Load bootstrap (config, schema, lib/*.php)\n";

if (!defined('SIGNAGE_ROOT')) {
    define('SIGNAGE_ROOT', $root);
}
if (!defined('SIGNAGE_CLI')) {
    define('SIGNAGE_CLI', true);
}

$prevHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_WARNING || $severity === E_NOTICE || $severity === E_DEPRECATED) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once $root . '/config.php';
    check_ok('config.php');
    require_once $root . '/schema.php';
    check_ok('schema.php');

    $libs = glob($root . '/lib/*.php') ?: [];
    sort($libs);
    foreach ($libs as $libPath) {
        $base = basename($libPath);
        require_once $libPath;
        check_ok('lib/' . $base);
    }
} catch (Throwable $e) {
    check_fail('bootstrap load — ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($prevHandler !== null) {
        set_error_handler($prevHandler);
    } else {
        restore_error_handler();
    }
}

if ($runTests) {
    echo "== Offline test scripts\n";
    $tests = glob($root . '/scripts/test-*.php') ?: [];
    sort($tests);
    if ($tests === []) {
        echo "   (none)\n";
    }
    foreach ($tests as $testPath) {
        $rel = 'scripts/' . basename($testPath);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testPath) . ' 2>&1';
        $lines = [];
        $code = 0;
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            $tail = trim(implode("\n", $lines));
            if ($tail === '') {
                $tail = "exit $code";
            }
            check_fail("$rel — $tail");
        } else {
            check_ok($rel);
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) failed.\n");
    exit(1);
}

echo "\nAll PHP checks passed.\n";
exit(0);
