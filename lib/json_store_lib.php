<?php
/**
 * Locked read-modify-write for JSON state files (settings, users, audit, presence).
 */

/** @var string|null */
$GLOBALS['__signage_json_last_error'] = null;

function signage_json_last_error(): string
{
    return (string)($GLOBALS['__signage_json_last_error'] ?? '');
}

function signage_json_lock_path(string $path): string
{
    return $path . '.lock';
}

/** Copy the current file to path.bak before overwrite (one generation). */
function signage_json_backup_previous(string $path, string $suffix = '.bak'): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $size = @filesize($path);
    if ($size === false || $size < 1) {
        return false;
    }

    return @copy($path, $path . $suffix);
}

/**
 * @param callable(array): (array|false|null) $mutator
 * @param array{default?:array,pretty?:bool|callable,lock_wait_sec?:float,ensure_dir?:bool,sort_keys?:bool|callable,backup?:bool|string} $options
 * @return array{ok:bool,data?:array,error?:string}
 */
function signage_json_file_update(string $path, callable $mutator, array $options = []): array
{
    $GLOBALS['__signage_json_last_error'] = null;

    $default = $options['default'] ?? [];
    $waitSec = (float)($options['lock_wait_sec'] ?? 10.0);
    $pretty = $options['pretty'] ?? false;
    $ensureDir = (bool)($options['ensure_dir'] ?? true);

    if ($ensureDir) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $GLOBALS['__signage_json_last_error'] = 'mkdir';

            return ['ok' => false, 'error' => 'mkdir'];
        }
    }

    $lockPath = signage_json_lock_path($path);
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }

    $lockFp = @fopen($lockPath, 'c+');
    if ($lockFp === false) {
        $GLOBALS['__signage_json_last_error'] = 'lock_open';

        return ['ok' => false, 'error' => 'lock_open'];
    }

    $deadline = microtime(true) + max(0.5, $waitSec);
    $locked = false;
    while (microtime(true) < $deadline) {
        if (flock($lockFp, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(50000);
    }
    if (!$locked && !flock($lockFp, LOCK_EX)) {
        fclose($lockFp);
        $GLOBALS['__signage_json_last_error'] = 'lock_timeout';

        return ['ok' => false, 'error' => 'lock_timeout'];
    }

    try {
        $current = $default;
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        $result = $mutator($current);
        if ($result === false || $result === null) {
            $GLOBALS['__signage_json_last_error'] = 'aborted';

            return ['ok' => false, 'error' => 'aborted'];
        }
        if (!is_array($result)) {
            $GLOBALS['__signage_json_last_error'] = 'invalid_mutator';

            return ['ok' => false, 'error' => 'invalid_mutator'];
        }

        if (!empty($options['sort_keys'])) {
            if ($options['sort_keys'] === true) {
                ksort($result);
            } elseif (is_callable($options['sort_keys'])) {
                ($options['sort_keys'])($result);
            }
        }

        $usePretty = is_callable($pretty) ? (bool)$pretty($result) : (bool)$pretty;
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($usePretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode($result, $flags);
        if ($json === false) {
            $GLOBALS['__signage_json_last_error'] = 'encode';

            return ['ok' => false, 'error' => 'encode'];
        }

        if (!empty($options['backup']) && is_file($path)) {
            $suffix = is_string($options['backup']) ? $options['backup'] : '.bak';
            signage_json_backup_previous($path, $suffix);
        }

        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            $GLOBALS['__signage_json_last_error'] = 'write';

            return ['ok' => false, 'error' => 'write'];
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $GLOBALS['__signage_json_last_error'] = 'rename';

            return ['ok' => false, 'error' => 'rename'];
        }

        return ['ok' => true, 'data' => $result];
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}
