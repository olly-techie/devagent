<?php
declare(strict_types=1);

namespace DevAgent\Controllers;

use DevAgent\Services\GitHubService;

class RepoController
{
    /**
     * GET /api/repos
     * Lists all repos the authenticated user has access to.
     */
    public function index(array $params): void
    {
        $user   = requireAuth();
        $github = new GitHubService($user['github_token']);

        $page    = (int) ($_GET['page'] ?? 1);
        $perPage = min((int) ($_GET['per_page'] ?? 30), 100);

        $repos = $github->listRepos($page, $perPage);

        jsonResponse([
            'repos' => $repos,
            'page'  => $page,
        ]);
    }
}
