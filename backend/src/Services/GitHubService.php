<?php
declare(strict_types=1);

namespace DevAgent\Services;

class GitHubService
{
    private string $token;
    private string $baseUrl = 'https://api.github.com';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * List repos for the authenticated user.
     */
    public function listRepos(int $page = 1, int $perPage = 30): array
    {
        return $this->get('/user/repos', [
            'sort'      => 'updated',
            'per_page'  => $perPage,
            'page'      => $page,
            'affiliation' => 'owner,collaborator',
        ]);
    }

    /**
     * Get default branch of a repo.
     */
    public function getRepo(string $owner, string $repo): array
    {
        return $this->get("/repos/{$owner}/{$repo}");
    }

    /**
     * Get the SHA of a branch tip.
     */
    public function getBranchSha(string $owner, string $repo, string $branch): string
    {
        $data = $this->get("/repos/{$owner}/{$repo}/git/ref/heads/{$branch}");
        return $data['object']['sha'] ?? throw new \RuntimeException("Could not find branch: {$branch}");
    }

    /**
     * Create a new branch from a given SHA.
     */
    public function createBranch(string $owner, string $repo, string $branch, string $fromSha): array
    {
        return $this->post("/repos/{$owner}/{$repo}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $fromSha,
        ]);
    }

    /**
     * Get file contents from a repo (returns base64 encoded content + sha).
     */
    public function getFile(string $owner, string $repo, string $path, string $branch): ?array
    {
        try {
            return $this->get("/repos/{$owner}/{$repo}/contents/{$path}", ['ref' => $branch]);
        } catch (\RuntimeException) {
            return null; // File doesn't exist
        }
    }

    /**
     * Create or update a file on a branch.
     * $content should be the raw file content (not base64 encoded).
     */
    public function upsertFile(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        string $branch,
        ?string $existingSha = null
    ): array {
        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];

        if ($existingSha) {
            $payload['sha'] = $existingSha;
        }

        return $this->put("/repos/{$owner}/{$repo}/contents/{$path}", $payload);
    }

    /**
     * Create a pull request.
     */
    public function createPullRequest(
        string $owner,
        string $repo,
        string $title,
        string $body,
        string $head,
        string $base
    ): array {
        return $this->post("/repos/{$owner}/{$repo}/pulls", [
            'title' => $title,
            'body'  => $body,
            'head'  => $head,
            'base'  => $base,
        ]);
    }

    /**
     * Add labels to a PR.
     */
    public function addLabels(string $owner, string $repo, int $issueNumber, array $labels): void
    {
        $this->post("/repos/{$owner}/{$repo}/issues/{$issueNumber}/labels", [
            'labels' => $labels,
        ]);
    }

    /**
     * List repo contents (directory listing).
     */
    public function listContents(string $owner, string $repo, string $path = '', string $branch = 'main'): array
    {
        return $this->get("/repos/{$owner}/{$repo}/contents/{$path}", ['ref' => $branch]);
    }

    // ── HTTP helpers ──────────────────────────────────────

    private function get(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . $endpoint;
        if ($query) $url .= '?' . http_build_query($query);
        return $this->request('GET', $url);
    }

    private function post(string $endpoint, array $body): array
    {
        return $this->request('POST', $this->baseUrl . $endpoint, $body);
    }

    private function put(string $endpoint, array $body): array
    {
        return $this->request('PUT', $this->baseUrl . $endpoint, $body);
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: DevAgent/1.0',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $body ? json_encode($body) : null,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) throw new \RuntimeException("cURL error: {$error}");

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? "GitHub API error {$httpCode}";
            throw new \RuntimeException($msg);
        }

        return $data ?? [];
    }
}
