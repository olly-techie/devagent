<?php
declare(strict_types=1);

/**
 * DevAgent — Routes
 */

use DevAgent\Controllers\AuthController;
use DevAgent\Controllers\RepoController;
use DevAgent\Controllers\TaskController;
use DevAgent\Controllers\LogController;

// ── Health (no auth) ────────────────────────────────────
$router->get('/api/health', function() {
    $dbOk = false;
    try { db()->query('SELECT 1'); $dbOk = true; } catch (\Throwable) {}
    $ok = $dbOk;
    jsonResponse([
        'status' => $ok ? 'ok' : 'degraded',
        'db'     => $dbOk,
        'php'    => PHP_VERSION,
        'ts'     => date('c'),
    ], $ok ? 200 : 503);
});

// ── Auth ────────────────────────────────────────────────
$router->get('/api/connect-github',           [AuthController::class, 'redirectToGitHub']);
$router->post('/api/connect-github/callback', [AuthController::class, 'handleCallback']);
$router->get('/api/me',                       [AuthController::class, 'me']);
$router->post('/api/logout',                  [AuthController::class, 'logout']);

// ── Repos ────────────────────────────────────────────────
$router->get('/api/repos', [RepoController::class, 'index']);

// ── Tasks ────────────────────────────────────────────────
$router->post('/api/run-claude-task', [TaskController::class, 'run']);
$router->get('/api/tasks',            [TaskController::class, 'index']);
$router->get('/api/tasks/{id}',       [TaskController::class, 'show']);

// ── Logs ─────────────────────────────────────────────────
$router->get('/api/logs',        [LogController::class, 'index']);
$router->get('/api/logs/stream', [LogController::class, 'stream']);
