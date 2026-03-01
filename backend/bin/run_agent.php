#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DevAgent — Background Agent Runner
 * ====================================
 * Spawned as a background process by TaskController.
 * Receives ONLY the task ID via argv — fetches and decrypts
 * the GitHub token from DB internally.
 *
 * This eliminates the token-in-argv vulnerability where the
 * GitHub token would have been visible in `ps aux` output to
 * any user on the same system.
 *
 * Workflow:
 *   1. Fetch task + decrypt GitHub token from DB
 *   2. Build repo context (file structure, key config files)
 *   3. Call Claude API to generate code
 *   4. Create GitHub branch from base
 *   5. Commit all generated files
 *   6. Open PR with AI-generated title + description
 *   7. Mark task as done / failed
 */

require_once __DIR__ . '/../bootstrap.php';

use DevAgent\Security\Encryption;
use DevAgent\Services\GitHubService;
use DevAgent\Services\ClaudeService;

// ── Args: task ID only ────────────────────────────────────
$taskId = (int) ($argv[1] ?? 0);

if ($taskId <= 0) {
    fwrite(STDERR, "Usage: run_agent.php <task_id>\n");
    exit(1);
}

// ── Load task ─────────────────────────────────────────────
$stmt = db()->prepare('
    SELECT t.*, u.github_token_enc
    FROM tasks t
    JOIN users u ON u.id = t.user_id
    WHERE t.id = ?
');
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task) {
    fwrite(STDERR, "Task {$taskId} not found\n");
    exit(1);
}

// ── Decrypt GitHub token ──────────────────────────────────
try {
    $githubToken = Encryption::instance()->decrypt($task['github_token_enc']);
} catch (\Throwable $e) {
    fwrite(STDERR, "Failed to decrypt GitHub token for task {$taskId}: " . $e->getMessage() . "\n");
    db()->prepare("UPDATE tasks SET status = 'failed', updated_at = NOW() WHERE id = ?")
        ->execute([$taskId]);
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────
function log_entry(int $taskId, string $level, string $message): void
{
    db()->prepare('INSERT INTO logs (task_id, level, message, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$taskId, $level, substr($message, 0, 2000)]);
    echo "[{$level}] {$message}\n";
}

function fail_task(int $taskId, string $reason): never
{
    log_entry($taskId, 'error', "Task failed: {$reason}");
    db()->prepare("UPDATE tasks SET status = 'failed', updated_at = NOW() WHERE id = ?")
        ->execute([$taskId]);
    exit(1);
}

// ── Agent main ────────────────────────────────────────────
try {
    db()->prepare("UPDATE tasks SET status = 'running', updated_at = NOW() WHERE id = ?")
        ->execute([$taskId]);

    $github = new GitHubService($githubToken);
    $claude = new ClaudeService();

    $owner    = $task['owner'];
    $repo     = $task['repo'];
    $branch   = $task['branch'];
    $base     = $task['base_branch'];
    $taskDesc = $task['task_description'];

    log_entry($taskId, 'info', "🚀 Starting agent for {$owner}/{$repo}");
    log_entry($taskId, 'info', "📋 Task: " . substr($taskDesc, 0, 200));

    // Step 1: Gather repo context
    log_entry($taskId, 'info', "📂 Fetching repository structure…");
    $repoInfo    = $github->getRepo($owner, $repo);
    $repoContext = buildRepoContext($github, $owner, $repo, $base, $repoInfo);
    log_entry($taskId, 'info', "✓ Repository context ready ({$repoInfo['language']} project)");

    // Step 2: Call Claude
    log_entry($taskId, 'ai', "🤖 Calling Claude AI to generate code…");
    $result = $claude->generateCode($taskDesc, $repoContext);

    $files   = $result['files'] ?? [];
    $prTitle = $result['pr_title'] ?? 'feat: ' . substr($taskDesc, 0, 60);
    $prBody  = $result['pr_description'] ?? '';

    log_entry($taskId, 'ai', "✓ Claude generated " . count($files) . " file(s)");
    foreach ($files as $file) {
        log_entry($taskId, 'ai', "  → {$file['action']}: {$file['path']}");
    }

    if (empty($files)) {
        fail_task($taskId, 'Claude did not generate any files');
    }

    // Step 3: Create branch
    log_entry($taskId, 'info', "🌿 Creating branch: {$branch}");
    $baseSha = $github->getBranchSha($owner, $repo, $base);
    $github->createBranch($owner, $repo, $branch, $baseSha);
    log_entry($taskId, 'success', "✓ Branch created from {$base}");

    // Step 4: Commit files
    log_entry($taskId, 'info', "💾 Committing generated files…");
    foreach ($files as $file) {
        $path        = ltrim($file['path'], '/');
        $content     = $file['content'];
        $action      = $file['action'];
        $existing    = $github->getFile($owner, $repo, $path, $branch);
        $existingSha = $existing['sha'] ?? null;
        $commitMsg   = "feat({$path}): {$action} via DevAgent AI\n\nTask: " . substr($taskDesc, 0, 200);
        $github->upsertFile($owner, $repo, $path, $content, $commitMsg, $branch, $existingSha);
        log_entry($taskId, 'success', "✓ Committed: {$path}");
    }

    // Step 5: Open PR
    log_entry($taskId, 'info', "📬 Opening pull request…");
    $fullPrBody = $prBody . "\n\n---\n*Generated by DevAgent AI — Branch: `{$branch}`*";
    $pr         = $github->createPullRequest($owner, $repo, $prTitle, $fullPrBody, $branch, $base);
    $prNumber   = $pr['number'];
    $prUrl      = $pr['html_url'];

    try { $github->addLabels($owner, $repo, $prNumber, ['ai-generated']); } catch (\Throwable) {}

    log_entry($taskId, 'success', "🎉 PR #{$prNumber} opened: {$prUrl}");

    // Step 6: Mark done
    db()->prepare("
        UPDATE tasks
        SET status = 'done', pr_url = ?, pr_number = ?, pr_title = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$prUrl, $prNumber, $prTitle, $taskId]);

    log_entry($taskId, 'success', "✅ Task completed successfully!");

} catch (\Throwable $e) {
    fail_task($taskId, $e->getMessage());
}

// ── Repo context builder ──────────────────────────────────
function buildRepoContext(GitHubService $github, string $owner, string $repo, string $branch, array $repoInfo): string
{
    $context  = "Repository: {$owner}/{$repo}\n";
    $context .= "Language: " . ($repoInfo['language'] ?? 'Unknown') . "\n";
    $context .= "Description: " . ($repoInfo['description'] ?? 'No description') . "\n";
    $context .= "Default branch: " . ($repoInfo['default_branch'] ?? 'main') . "\n\n";

    try {
        $contents = $github->listContents($owner, $repo, '', $branch);
        if (is_array($contents)) {
            $context .= "Root files:\n";
            foreach (array_slice($contents, 0, 30) as $item) {
                $type     = $item['type'] === 'dir' ? '📁' : '📄';
                $context .= "  {$type} {$item['name']}\n";
            }
            $context .= "\n";
        }
    } catch (\Throwable) {}

    $keyFiles = ['README.md', 'package.json', 'composer.json', 'requirements.txt',
                 'pyproject.toml', 'Cargo.toml', 'go.mod', '.env.example'];

    foreach ($keyFiles as $file) {
        try {
            $data = $github->getFile($owner, $repo, $file, $branch);
            if ($data && isset($data['content'])) {
                $decoded  = base64_decode(str_replace("\n", '', $data['content']));
                $context .= "--- {$file} ---\n" . substr($decoded, 0, 2000) . "\n\n";
            }
        } catch (\Throwable) {}
    }

    return $context;
}
