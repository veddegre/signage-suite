<?php
/**
 * Helpers for CLI scripts (diagnose-*, etc.).
 */

/** Common Apache/nginx deploy path when the repo checkout has no local settings. */
function signage_cli_default_deploy_root(): string
{
    return '/var/www/html/boards';
}

/**
 * Pick which install root to load config from.
 * Order: --root= / SIGNAGE_ROOT env → script tree if settings.json exists → deploy path → script tree.
 */
function signage_cli_resolve_root(?string $override = null): string
{
    if ($override !== null && $override !== '') {
        return rtrim($override, '/');
    }
    $env = getenv('SIGNAGE_ROOT');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }

    $scriptRoot = dirname(__DIR__);
    if (is_file($scriptRoot . '/config/settings.json')) {
        return $scriptRoot;
    }

    $deploy = signage_cli_default_deploy_root();
    if ($scriptRoot !== $deploy && is_file($deploy . '/config/settings.json')) {
        return $deploy;
    }

    return $scriptRoot;
}

/**
 * @return array{root:?string, needle:string, positional:list<string>}
 */
function signage_cli_parse_argv(array $argv): array
{
    $root = null;
    $needle = '';
    $positional = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = rtrim(substr($arg, 7), '/');
            continue;
        }
        if (str_starts_with($arg, '--needle=')) {
            $needle = substr($arg, 9);
            continue;
        }
        $positional[] = $arg;
    }

    return ['root' => $root, 'needle' => $needle, 'positional' => $positional];
}
