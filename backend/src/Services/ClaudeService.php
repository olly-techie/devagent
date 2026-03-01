<?php
declare(strict_types=1);

namespace DevAgent\Services;

class ClaudeService
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = env('ANTHROPIC_API_KEY') ?? throw new \RuntimeException('ANTHROPIC_API_KEY not set');
        $this->model  = env('CLAUDE_MODEL', 'claude-sonnet-4-6');
    }

    /**
     * Generate code/instructions for the given task.
     * Returns an array of files to create/modify.
     *
     * @return array{files: array<array{path: string, content: string, action: string}>, pr_description: string}
     */
    public function generateCode(string $taskDescription, string $repoContext): array
    {
        $systemPrompt = <<<SYSTEM
You are DevAgent, an expert AI software developer. Your job is to implement code changes in response to task descriptions.

RULES:
1. Always respond with a valid JSON object — no markdown, no explanation outside JSON.
2. The JSON must have:
   - "files": array of file objects to create/modify
   - "pr_title": a concise PR title (max 72 chars)
   - "pr_description": a full markdown PR description explaining the changes
3. Each file object must have:
   - "path": relative file path from repo root
   - "content": complete file content (do NOT truncate)
   - "action": "create" or "update"
4. Write production-quality code. Include comments. Follow best practices.
5. If creating multiple files, include all related files (e.g., also update imports).
6. Generate realistic, working code — not placeholders.

RESPOND ONLY WITH VALID JSON.
SYSTEM;

        $userMessage = <<<USER
Task: {$taskDescription}

Repository context:
{$repoContext}

Implement this task. Return the JSON with files to create/update.
USER;

        $response = $this->callAPI($systemPrompt, $userMessage, 4096);

        // Parse the JSON response
        $parsed = json_decode($response, true);
        if (!$parsed || !isset($parsed['files'])) {
            // Try to extract JSON from response
            if (preg_match('/\{.*\}/s', $response, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!$parsed || !isset($parsed['files'])) {
            throw new \RuntimeException('Claude did not return valid JSON with files');
        }

        return $parsed;
    }

    /**
     * Summarize what was done for the PR description.
     */
    public function summarizeChanges(string $taskDescription, array $files): string
    {
        $fileList = implode("\n", array_map(fn($f) => "- {$f['path']} ({$f['action']})", $files));

        $response = $this->callAPI(
            'You write concise, professional PR descriptions in markdown. Be specific about what changed and why.',
            "Task: {$taskDescription}\n\nFiles changed:\n{$fileList}\n\nWrite a PR description.",
            512
        );

        return $response;
    }

    /**
     * Call the Claude API (non-streaming for simplicity in background process).
     */
    public function callAPI(string $systemPrompt, string $userMessage, int $maxTokens = 2048): string
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new \RuntimeException("API cURL error: {$error}");

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $data['error']['message'] ?? "API error {$httpCode}";
            throw new \RuntimeException("Claude API error: {$msg}");
        }

        return $data['content'][0]['text'] ?? '';
    }
}
