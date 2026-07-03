<?php
/**
 * ntfy alert cache — webhook ingest, optional poll, hero strip / optional board.
 */

require_once dirname(__DIR__) . '/config.php';

const NTFY_CACHE_FILE = SIGNAGE_ROOT . '/cache/ntfy_recent.json';
const NTFY_POLL_STATE_FILE = SIGNAGE_ROOT . '/cache/ntfy_poll_state.json';
const NTFY_DEFAULT_MAX = 40;
const NTFY_DEFAULT_POLL_SEC = 30;

function ntfy_max_messages(): int
{
    return max(5, min(200, (int)cfg('ntfy.NTFY_MAX_MESSAGES', NTFY_DEFAULT_MAX)));
}

function ntfy_webhook_token(): string
{
    return trim((string)cfg('ntfy.NTFY_WEBHOOK_TOKEN', ''));
}

function ntfy_server_base(): string
{
    $base = rtrim(trim((string)cfg('ntfy.NTFY_SERVER', 'https://ntfy.sh')), '/');
    if ($base === '') {
        $base = 'https://ntfy.sh';
    }

    return $base;
}

function ntfy_poll_topic(): string
{
    return strtolower(trim((string)cfg('ntfy.NTFY_POLL_TOPIC', '')));
}

function ntfy_poll_interval_sec(): int
{
    return max(10, min(300, (int)cfg('ntfy.NTFY_POLL_SEC', NTFY_DEFAULT_POLL_SEC)));
}

function ntfy_poll_enabled(): bool
{
    return ntfy_poll_topic() !== '';
}

/** @return list<array<string,mixed>> */
function ntfy_recent_messages(int $limit = 10): array
{
    if (!is_file(NTFY_CACHE_FILE)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(NTFY_CACHE_FILE), true);
    if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
        return [];
    }
    $msgs = array_values($data['messages']);
    if ($limit > 0 && count($msgs) > $limit) {
        $msgs = array_slice($msgs, 0, $limit);
    }

    return $msgs;
}

/** @param array<string,mixed> $payload */
function ntfy_append_message(array $payload): bool
{
    $title = trim((string)($payload['title'] ?? ''));
    $message = trim((string)($payload['message'] ?? $payload['body'] ?? ''));
    if ($title === '' && $message === '') {
        return false;
    }
    $entry = [
        'title' => $title !== '' ? $title : $message,
        'message' => $message,
        'priority' => max(1, min(5, (int)($payload['priority'] ?? $payload['prio'] ?? 3))),
        'tags' => is_array($payload['tags'] ?? null) ? $payload['tags'] : [],
        'topic' => trim((string)($payload['topic'] ?? '')),
        'time' => (int)($payload['time'] ?? time()),
    ];
    if ($entry['time'] <= 0) {
        $entry['time'] = time();
    }
    $cacheDir = dirname(NTFY_CACHE_FILE);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $existing = [];
    if (is_file(NTFY_CACHE_FILE)) {
        $decoded = json_decode((string)file_get_contents(NTFY_CACHE_FILE), true);
        if (is_array($decoded['messages'] ?? null)) {
            $existing = $decoded['messages'];
        }
    }
    foreach ($existing as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        if (($ex['title'] ?? '') === $entry['title']
            && (int)($ex['time'] ?? 0) === (int)$entry['time']
            && trim((string)($ex['topic'] ?? '')) === $entry['topic']) {
            return true;
        }
    }
    array_unshift($existing, $entry);
    $max = ntfy_max_messages();
    if (count($existing) > $max) {
        $existing = array_slice($existing, 0, $max);
    }

    return (bool)@file_put_contents(
        NTFY_CACHE_FILE,
        json_encode(['messages' => $existing, 'updated_at' => time()], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function ntfy_poll_refresh_if_due(): void
{
    if (!ntfy_poll_enabled()) {
        return;
    }
    $state = [];
    if (is_file(NTFY_POLL_STATE_FILE)) {
        $decoded = json_decode((string)file_get_contents(NTFY_POLL_STATE_FILE), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
    $last = (int)($state['last_poll'] ?? 0);
    if ($last > 0 && (time() - $last) < ntfy_poll_interval_sec()) {
        return;
    }
    ntfy_poll_refresh();
}

function ntfy_poll_refresh(): bool
{
    $topic = ntfy_poll_topic();
    if ($topic === '') {
        return false;
    }
    $url = ntfy_server_base() . '/' . rawurlencode($topic) . '/json?poll=1&since=10m';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    @file_put_contents(
        NTFY_POLL_STATE_FILE,
        json_encode(['last_poll' => time(), 'url' => $url], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    if ($raw === false || trim($raw) === '') {
        return false;
    }
    $lines = preg_split('/\r\n|\n|\r/', trim($raw)) ?: [];
    $added = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $payload = json_decode($line, true);
        if (!is_array($payload)) {
            continue;
        }
        $payload['topic'] = $topic;
        if (!isset($payload['time']) && isset($payload['time_unix'])) {
            $payload['time'] = (int)$payload['time_unix'];
        }
        if (ntfy_append_message($payload)) {
            $added = true;
        }
    }

    return $added;
}

/** Publish a push notification to an ntfy topic (server from ntfy board config). */
function ntfy_publish(string $topic, string $title, string $message, int $priority = 3): bool
{
    $topic = strtolower(trim($topic));
    if ($topic === '' || ($title === '' && $message === '')) {
        return false;
    }
    $url = ntfy_server_base() . '/' . rawurlencode($topic);
    $headers = [
        'Title: ' . str_replace(["\r", "\n"], ' ', $title),
        'Priority: ' . max(1, min(5, $priority)),
        'Content-Type: text/plain; charset=utf-8',
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $message,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $result = @file_get_contents($url, false, $ctx);

    return $result !== false;
}

/** Handle inbound webhook POST. */
function ntfy_handle_webhook_request(): void
{
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);

        return;
    }
    $token = ntfy_webhook_token();
    if ($token === '' || $token === 'PUT-NTFY-WEBHOOK-TOKEN-HERE') {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Webhook token not configured']);

        return;
    }
    $auth = trim((string)($_SERVER['HTTP_X_SIGNAGE_TOKEN'] ?? $_GET['token'] ?? ''));
    if (!hash_equals($token, $auth)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);

        return;
    }
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = ['message' => trim($raw)];
    }
    $topic = trim((string)($_GET['topic'] ?? ''));
    if ($topic !== '') {
        $payload['topic'] = $topic;
    }
    if (!ntfy_append_message($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty payload']);

        return;
    }
    echo json_encode(['ok' => true]);
}
