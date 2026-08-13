<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

session_start();

header('Content-Type: application/json; charset=utf-8');

/*
 * CORS for local development, where the React dev server (Vite, usually
 * http://localhost:5173) runs on a different origin/port than this API.
 * We use PHP sessions (cookies) to remember the logged-in ESXi session, so
 * the origin must be echoed back explicitly (not "*") and credentials must
 * be allowed. In production, if you serve the built React app from the same
 * origin as this API, none of this is needed.
 */
$allowedOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === '' || $raw === false) {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

// Fatal PHP errors (e.g. TypeError outside a try/catch, out-of-memory,
// syntax issues in a code path) bypass normal exception handling and would
// otherwise emit raw HTML, breaking the frontend's JSON parsing with no
// useful message. Convert them to a JSON error response too.
register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => "Fatal error: {$error['message']} in {$error['file']}:{$error['line']}",
        ]);
    }
});

/**
 * Build a logged-in ESXiClient from the credentials stashed in the PHP
 * session by POST /login. We re-authenticate on every request rather than
 * trying to persist the raw SOAP session cookie across PHP processes —
 * simpler and more robust for a small admin panel, at the cost of one extra
 * Login SOAP call per request.
 */
function esxiClientFromSession(): \EsxiV9\Client\ESXiClient
{
    if (empty($_SESSION['esxi'])) {
        fail('Not authenticated. POST /login first.', 401);
    }

    $s = $_SESSION['esxi'];

    $config = new \EsxiV9\Config\Config(
        host: $s['host'],
        username: $s['username'],
        password: $s['password'],
        ssl: $s['ssl'],
        allowedSelfSigned: $s['allowSelfSigned'],
    );

    $client = new \EsxiV9\Client\ESXiClient($config);

    try {
        $client->auth()->login();
    } catch (\Throwable $e) {
        fail('ESXi authentication failed: ' . $e->getMessage(), 401);
    }

    return $client;
}