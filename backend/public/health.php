<?php
declare(strict_types=1);

/**
 * GET /api/health
 * Railway healthcheck endpoint.
 * Returns 200 when app + DB are reachable, 503 otherwise.
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$checks = [
    'app' => true,
    'db'  => false,
    'php' => PHP_VERSION,
];

// Check DB connectivity
try {
    db()->query('SELECT 1');
    $checks['db'] = true;
} catch (\Throwable $e) {
    $checks['db_error'] = 'Database unreachable';
}

$allGood = $checks['app'] && $checks['db'];
$status  = $allGood ? 200 : 503;

http_response_code($status);
echo json_encode([
    'status' => $allGood ? 'ok' : 'degraded',
    'checks' => $checks,
    'ts'     => date('c'),
], JSON_UNESCAPED_SLASHES);
