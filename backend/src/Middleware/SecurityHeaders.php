<?php
declare(strict_types=1);

namespace DevAgent\Middleware;

/**
 * SecurityHeaders — Applied before every request.
 *
 * Responsibilities:
 *  1. CORS with strict origin allowlist (not wildcard reflection)
 *  2. HTTP security headers (CSP, HSTS, X-Content-Type-Options, etc.)
 *  3. Request body size enforcement
 *  4. Content-Type enforcement on POST/PUT/PATCH
 *  5. Global exception handler (no stack traces to clients)
 */
final class SecurityHeaders
{
    /** Maximum accepted request body size (bytes). Default: 64 KB */
    private const MAX_BODY_BYTES = 65_536;

    /**
     * Run all security checks. Call this at the very start of index.php,
     * before routing.
     */
    public static function apply(): void
    {
        self::registerExceptionHandler();
        self::cors();
        self::httpHeaders();
        self::enforceBodySize();
        self::enforceContentType();
    }

    // ── CORS ──────────────────────────────────────────────

    /**
     * Strict CORS: only allow origins listed in ALLOWED_ORIGINS env var.
     * Never reflects arbitrary Origin values back.
     */
    private static function cors(): void
    {
        $allowed = self::allowedOrigins();
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        // SSE streams: browser sends Origin — must still validate
        if ($origin && in_array($origin, $allowed, strict: true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        } elseif (empty($origin)) {
            // Same-origin or server-to-server — no CORS header needed
        } else {
            // Unknown origin — reject preflight, allow request to proceed to
            // auth check (which will fail with 401 anyway for protected routes)
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(403);
                exit;
            }
        }

        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400'); // Cache preflight 24h

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Parse ALLOWED_ORIGINS env var (comma-separated list of origins).
     */
    private static function allowedOrigins(): array
    {
        $raw = env('ALLOWED_ORIGINS', 'http://localhost:8080');
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    // ── HTTP Security Headers ─────────────────────────────

    private static function httpHeaders(): void
    {
        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');

        // Deny framing entirely (clickjacking protection)
        header('X-Frame-Options: DENY');

        // Don't send Referer to cross-origin destinations
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions Policy — disable unnecessary browser features
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');

        // HSTS — only send on HTTPS
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy
        // API-only backend: no HTML served, so this is belt-and-suspenders
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        // Remove fingerprinting headers
        header_remove('X-Powered-By');
        header_remove('Server');
    }

    // ── Request Body Size ──────────────────────────────────

    private static function enforceBodySize(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], strict: true)) {
            return;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            jsonError(
                sprintf('Request body too large. Maximum is %d KB.', self::MAX_BODY_BYTES / 1024),
                413
            );
        }
    }

    // ── Content-Type Enforcement ───────────────────────────

    private static function enforceContentType(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], strict: true)) {
            return;
        }

        // Skip for OAuth callback which may be a form POST from GitHub
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($uri === '/api/connect-github/callback') {
            return;
        }

        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!str_contains($ct, 'application/json')) {
            jsonError('Content-Type must be application/json', 415);
        }
    }

    // ── Global Exception Handler ───────────────────────────

    /**
     * Catch all uncaught exceptions and errors.
     * Log internally, return a generic error to the client.
     * Never expose stack traces, file paths, or DB details.
     */
    private static function registerExceptionHandler(): void
    {
        set_exception_handler(function (\Throwable $e) {
            self::logError($e);
            $debug = env('APP_ENV', 'production') === 'development';
            jsonResponse([
                'error'   => 'An internal server error occurred',
                'detail'  => $debug ? $e->getMessage() : null,
            ], 500);
        });

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            if (!(error_reporting() & $errno)) return false;
            $msg = "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}";
            error_log($msg);
            // Don't throw for notices/warnings in production
            if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR) {
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
            return true;
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                error_log("Fatal PHP error: " . json_encode($error));
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'An internal server error occurred']);
                }
            }
        });
    }

    /**
     * Write error details to PHP error log (never to client output).
     */
    private static function logError(\Throwable $e): void
    {
        $context = sprintf(
            "[DevAgent] Uncaught %s: %s in %s:%d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        error_log($context);
    }

    private static function isHttps(): bool
    {
        return (
            ($_SERVER['HTTPS'] ?? '') === 'on' ||
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
            ($_SERVER['SERVER_PORT'] ?? '') === '443'
        );
    }
}
