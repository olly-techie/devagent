<?php
declare(strict_types=1);

namespace DevAgent\Controllers;

class LogController
{
    /**
     * GET /api/logs?task_id=X
     */
    public function index(array $params): void
    {
        $user   = requireAuth(); // Header-only auth
        $taskId = (int) ($_GET['task_id'] ?? 0);

        if ($taskId <= 0) jsonError('task_id must be a positive integer', 400);

        $task = $this->getTask($taskId, $user['id']);
        if (!$task) jsonError('Task not found', 404);

        $stmt = db()->prepare('
            SELECT level, message, created_at
            FROM logs
            WHERE task_id = ?
            ORDER BY id ASC
        ');
        $stmt->execute([$taskId]);

        jsonResponse([
            'logs'      => $stmt->fetchAll(),
            'status'    => $task['status'],
            'pr_url'    => $task['pr_url'],
            'pr_number' => $task['pr_number'],
            'pr_title'  => $task['pr_title'],
        ]);
    }

    /**
     * GET /api/logs/stream?task_id=X&token=Y
     * SSE endpoint — EventSource cannot send headers, so token is in query param.
     * Uses requireAuthSSE() which only accepts this on this specific endpoint.
     */
    public function stream(array $params): void
    {
        $user   = requireAuthSSE(); // Query-param auth, SSE-only
        $taskId = (int) ($_GET['task_id'] ?? 0);

        if ($taskId <= 0) {
            $this->sseAbort(400, 'task_id must be a positive integer');
        }

        $task = $this->getTask($taskId, $user['id']);
        if (!$task) {
            $this->sseAbort(404, 'Task not found');
        }

        // SSE headers
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no'); // Nginx: disable proxy buffering
        header('Connection: keep-alive');

        while (ob_get_level()) ob_end_flush();

        $lastLogId  = 0;
        $maxSeconds = 300;
        $start      = time();

        while (true) {
            if (time() - $start > $maxSeconds) {
                $this->sseEvent('done', ['message' => 'Stream timeout']);
                break;
            }

            if (connection_aborted()) break;

            // Fetch new log entries
            $stmt = db()->prepare('
                SELECT id, level, message, created_at
                FROM logs
                WHERE task_id = ? AND id > ?
                ORDER BY id ASC
                LIMIT 50
            ');
            $stmt->execute([$taskId, $lastLogId]);
            $newLogs = $stmt->fetchAll();

            foreach ($newLogs as $log) {
                $this->sseEvent('log', [
                    'level'   => $log['level'],
                    'message' => $log['message'],
                    'ts'      => $log['created_at'],
                ]);
                $lastLogId = $log['id'];
            }

            // Check task status
            $stmt = db()->prepare('SELECT status, pr_url, pr_number, pr_title FROM tasks WHERE id = ?');
            $stmt->execute([$taskId]);
            $current = $stmt->fetch();

            if ($current['status'] === 'done') {
                if ($current['pr_url']) {
                    $this->sseEvent('pr_opened', [
                        'pr_url'    => $current['pr_url'],
                        'pr_number' => $current['pr_number'],
                        'pr_title'  => $current['pr_title'],
                    ]);
                }
                $this->sseEvent('done', ['message' => 'Task completed']);
                break;
            }

            if ($current['status'] === 'failed') {
                $this->sseEvent('error_event', ['message' => 'Task failed. Check logs for details.']);
                break;
            }

            // Heartbeat keeps connection alive through proxies
            echo ": heartbeat\n\n";
            flush();

            sleep(2);
        }
    }

    // ── Private ────────────────────────────────────────────

    private function sseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private function sseAbort(int $code, string $message): never
    {
        http_response_code($code);
        $this->sseEvent('error_event', ['message' => $message]);
        exit;
    }

    private function getTask(int $taskId, int $userId): array|false
    {
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$taskId, $userId]);
        return $stmt->fetch();
    }
}
