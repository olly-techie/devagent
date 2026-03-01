<?php
declare(strict_types=1);

/**
 * DevAgent — Backend Entry Point
 *
 * Security middleware is applied BEFORE routing:
 *  1. Exception handler (no stack traces to clients)
 *  2. CORS with strict origin allowlist
 *  3. HTTP security headers
 *  4. Request body size limit (64 KB)
 *  5. Content-Type enforcement on mutating requests
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';

use DevAgent\Middleware\SecurityHeaders;
use DevAgent\Router;

// ── Security first — always before routing ────────────────
SecurityHeaders::apply();

// ── Default response content-type ────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ── Route ─────────────────────────────────────────────────
$router = new Router();
require_once __DIR__ . '/../routes.php';
$router->dispatch();
