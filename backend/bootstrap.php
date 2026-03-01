<?php
declare(strict_types=1);

/**
 * DevAgent — Bootstrap
 * Loads env, sets up DB, defines global helper functions.
 */

// ── Load .env ────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '='))        continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (!empty($k)) putenv("{$k}={$v}");
    }
}

// ── Global helpers ────────────────────────────────────────

function env(string $key, mixed $default = null): mixed {
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'devagent'),
    );

    $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);

    return $pdo;
}

function jsonResponse(mixed $data, int $code = 200): never {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function jsonError(string $message, int $code = 400): never {
    jsonResponse(['error' => $message], $code);
}

/**
 * Parse JSON request body. Rejects malformed JSON and non-object/array payloads.
 */
function requestBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];

    try {
        $decoded = json_decode($raw, true, 10, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        jsonError('Invalid JSON body: ' . $e->getMessage(), 400);
    }

    if (!is_array($decoded)) {
        jsonError('Request body must be a JSON object', 400);
    }

    return $decoded;
}

/**
 * Extract Bearer token from Authorization header ONLY.
 * Never reads from GET parameters (prevents token leakage into access logs).
 */
function bearerToken(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
        return strlen($token) >= 32 ? $token : null;
    }
    return null;
}

/**
 * Authenticate via Authorization header. Returns the user row.
 * Use this for all standard API endpoints.
 */
function requireAuth(): array {
    $token = bearerToken();
    if (!$token) {
        jsonError('Missing or invalid Authorization header', 401);
    }
    return resolveSession($token);
}

/**
 * Authenticate via query param token (SSE-only — browsers can't set headers on EventSource).
 * Only call this from the SSE stream endpoint.
 */
function requireAuthSSE(): array {
    $token = $_GET['token'] ?? '';
    if (!$token || strlen($token) < 32) {
        jsonError('Missing or invalid token', 401);
    }
    return resolveSession($token);
}

/**
 * Shared session resolution logic.
 */
function resolveSession(string $token): array {
    // Constant-time token comparison to prevent timing attacks
    $stmt = db()->prepare('
        SELECT * FROM users
        WHERE session_expires > NOW()
        AND LENGTH(session_token) = LENGTH(?)
    ');
    $stmt->execute([hash('sha256', $token)]);
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $user) {
        if (hash_equals($user['session_token'], hash('sha256', $token))) {
            return $user;
        }
    }

    jsonError('Unauthorized or session expired', 401);
}

// ── Autoloader ────────────────────────────────────────────
spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/src/';
    $path = $base . str_replace(['DevAgent\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($path)) require_once $path;
});
