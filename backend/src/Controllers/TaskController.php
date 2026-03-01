<?php
declare(strict_types=1);

namespace DevAgent\Controllers;

use DevAgent\Security\RateLimiter;
use DevAgent\Security\Validator;

class TaskController
{
    // Strict rate limits — Claude API calls cost money
    private const TASK_LIMIT_PER_HOUR = 10;
    private const TASK_WINDOW_SEC     = 3600;

    /**
     * POST /api/run-claude-task
     */
    public function run(array $params): void
    {
        $user = requireAuth();

        // Per-user rate limit on the most expensive endpoint
        RateLimiter::enforce(
            RateLimiter::key('task', (string) $user['id']),
            self::TASK_LIMIT_PER_HOUR,
            self::TASK_WINDOW_SEC
        );

        $body = requestBody();

        // Full input validation
        $v = Validator::make($body)
            ->required('owner')
            ->string('owner', 1, 100)
            ->matches('owner', '/^[a-zA-Z0-9\-]+$/', 'owner must be a valid GitHub username')

            ->required('repo')
            ->string('repo', 1, 100)
            ->matches('repo', '/^[a-zA-Z0-9\-_\.]+$/', 'repo must be a valid GitHub repository name')

            ->required('branch')
            ->string('branch', 1, 200)
            ->matches('branch', '/^[a-zA-Z0-9\-_\/\.]+$/', 'branch contains invalid characters')
            ->notMatches('branch', '/\.\./', 'branch cannot contain ..')

            ->required('task')
            ->string('task', 10, 4000)

            ->string('base_branch', 1, 200);  // Optional field, but validated if present

        $v->abortIfFails();

        $owner      = $v->get('owner');
        $repo       = $v->get('repo');
        $branch     = $v->get('branch');
        $baseBranch = $v->get('base_branch') ?? 'main';
        $task       = $v->get('task');

        // Validate that base_branch also has no path traversal
        if (str_contains($baseBranch, '..')) {
            jsonError('base_branch cannot contain ..', 422);
        }

        // Check no running task already exists for this user+repo+branch
        // (prevents duplicate agent spawns)
        $stmt = db()->prepare('
            SELECT id FROM tasks
            WHERE user_id = ? AND owner = ? AND repo = ? AND branch = ?
              AND status IN (\'pending\', \'running\')
            LIMIT 1
        ');
        $stmt->execute([$user['id'], $owner, $repo, $branch]);
        if ($stmt->fetch()) {
            jsonError("A task for branch '{$branch}' is already in progress", 409);
        }

        // Insert task record
        $stmt = db()->prepare('
            INSERT INTO tasks
                (user_id, owner, repo, branch, base_branch, task_description, status, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, \'pending\', NOW(), NOW())
        ');
        $stmt->execute([$user['id'], $owner, $repo, $branch, $baseBranch, $task]);
        $taskId = (int) db()->lastInsertId();

        // Dispatch async agent — pass ONLY the task ID.
        // The agent fetches and decrypts the token from DB itself.
        $this->dispatchAsync($taskId);

        jsonResponse([
            'task_id' => $taskId,
            'status'  => 'pending',
        ]);
    }

    /**
     * GET /api/tasks
     */
    public function index(array $params): void
    {
        $user  = requireAuth();
        $limit = min((int) ($_GET['limit'] ?? 20), 50);

        $stmt = db()->prepare('
            SELECT id, owner, repo, branch, base_branch, task_description,
                   status, pr_url, pr_number, created_at
            FROM tasks
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$user['id'], $limit]);

        jsonResponse(['tasks' => $stmt->fetchAll()]);
    }

    /**
     * GET /api/tasks/{id}
     */
    public function show(array $params): void
    {
        $user = requireAuth();
        $id   = (int) ($params['id'] ?? 0);

        if ($id <= 0) jsonError('Invalid task ID', 400);

        $stmt = db()->prepare('
            SELECT id, owner, repo, branch, base_branch, task_description,
                   status, pr_url, pr_number, pr_title, created_at, updated_at
            FROM tasks WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$id, $user['id']]);
        $task = $stmt->fetch();

        if (!$task) jsonError('Task not found', 404);
        jsonResponse(['task' => $task]);
    }

    // ── Private ────────────────────────────────────────────

    private function dispatchAsync(int $taskId): void
    {
        // Pass ONLY the task ID — agent retrieves and decrypts token from DB.
        // This eliminates the token-in-argv vulnerability (visible via `ps aux`).
        $php    = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg(__DIR__ . '/../../bin/run_agent.php');
        $idArg  = escapeshellarg((string) $taskId);

        $cmd = "{$php} {$script} {$idArg} > /dev/null 2>&1 &";
        exec($cmd);
    }
}
