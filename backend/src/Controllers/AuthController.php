<?php
declare(strict_types=1);

namespace DevAgent\Controllers;

use DevAgent\Security\Encryption;
use DevAgent\Security\RateLimiter;
use DevAgent\Security\Validator;

class AuthController
{
    /**
     * GET /api/connect-github
     * Generate a cryptographically random state token, persist it to DB,
     * then return the GitHub OAuth authorization URL.
     *
     * State is stored in the DB (not PHP session) so it survives across
     * requests and works in stateless deployments.
     */
    public function redirectToGitHub(array $params): void
    {
        $ip = $this->clientIp();
        RateLimiter::enforce(RateLimiter::key('oauth', $ip), 10, 900);

        $clientId = env('GITHUB_CLIENT_ID')
            ?? throw new \RuntimeException('GITHUB_CLIENT_ID not configured');
        $redirect = env('GITHUB_REDIRECT_URI')
            ?? throw new \RuntimeException('GITHUB_REDIRECT_URI not configured');

        // 32 bytes = 256 bits of entropy
        $state = bin2hex(random_bytes(32));

        // Persist state with 10-minute TTL
        db()->prepare('
            INSERT INTO oauth_states (state, expires_at, ip_address)
            VALUES (?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), ?)
        ')->execute([$state, $ip]);

        $url = 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $clientId,
            'redirect_uri' => $redirect,
            'scope'        => 'repo,read:user',
            'state'        => $state,
        ]);

        jsonResponse(['auth_url' => $url]);
    }

    /**
     * POST /api/connect-github/callback
     * Exchange OAuth code for token. Validates state to prevent CSRF.
     */
    public function handleCallback(array $params): void
    {
        $ip = $this->clientIp();
        RateLimiter::enforce(RateLimiter::key('oauth-callback', $ip), 10, 900);

        $body  = requestBody();
        $code  = trim($body['code'] ?? '');
        $state = trim($body['state'] ?? '');

        $v = Validator::make(['code' => $code, 'state' => $state])
            ->required('code')->string('code', 10, 200)
            ->required('state')->string('state', 64, 64)
            ->matches('state', '/^[a-f0-9]{64}$/', 'Invalid state format');
        $v->abortIfFails();

        // Validate + consume OAuth state (CSRF protection)
        $stmt = db()->prepare('
            SELECT id FROM oauth_states
            WHERE state = ? AND expires_at > NOW()
        ');
        $stmt->execute([$state]);
        $stateRow = $stmt->fetch();

        if (!$stateRow) {
            jsonError('Invalid or expired OAuth state. Please restart the login flow.', 400);
        }

        // Single-use: delete immediately after verification
        db()->prepare('DELETE FROM oauth_states WHERE id = ?')
            ->execute([$stateRow['id']]);

        // Exchange code for GitHub access token
        $tokenResponse = $this->exchangeCodeForToken($code);

        if (!isset($tokenResponse['access_token'])) {
            jsonError('GitHub authorization failed. Please try connecting again.', 400);
        }

        $rawToken = $tokenResponse['access_token'];

        // Fetch GitHub user profile
        $ghUser = $this->fetchGitHubUser($rawToken);
        if (empty($ghUser['id'])) {
            jsonError('Could not retrieve GitHub profile. Please try again.', 500);
        }

        // Encrypt GitHub token before storing in DB
        $encryptedToken = Encryption::instance()->encrypt($rawToken);

        // Session token: client receives raw value; DB stores SHA-256 hash.
        // This means a DB dump cannot be used to hijack sessions.
        $rawSession    = bin2hex(random_bytes(32));
        $hashedSession = hash('sha256', $rawSession);
        $sessionExpires = date('Y-m-d H:i:s', strtotime('+30 days'));

        db()->prepare('
            INSERT INTO users
                (github_id, login, name, email, avatar_url, github_token_enc,
                 session_token, session_expires, created_at, updated_at)
            VALUES
                (:github_id, :login, :name, :email, :avatar_url, :enc,
                 :session, :expires, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                login            = VALUES(login),
                name             = VALUES(name),
                email            = VALUES(email),
                avatar_url       = VALUES(avatar_url),
                github_token_enc = VALUES(github_token_enc),
                session_token    = VALUES(session_token),
                session_expires  = VALUES(session_expires),
                updated_at       = NOW()
        ')->execute([
            'github_id' => $ghUser['id'],
            'login'     => substr($ghUser['login'], 0, 255),
            'name'      => substr($ghUser['name'] ?? $ghUser['login'], 0, 255),
            'email'     => isset($ghUser['email']) ? substr($ghUser['email'], 0, 255) : null,
            'avatar_url'=> isset($ghUser['avatar_url']) ? substr($ghUser['avatar_url'], 0, 512) : null,
            'enc'       => $encryptedToken,
            'session'   => $hashedSession,
            'expires'   => $sessionExpires,
        ]);

        jsonResponse([
            'session_token' => $rawSession, // raw token returned to client only
            'user' => [
                'login'      => $ghUser['login'],
                'name'       => $ghUser['name'] ?? $ghUser['login'],
                'avatar_url' => $ghUser['avatar_url'] ?? null,
            ],
        ]);
    }

    /**
     * GET /api/me
     */
    public function me(array $params): void
    {
        $user = requireAuth();
        jsonResponse([
            'user' => [
                'login'      => $user['login'],
                'name'       => $user['name'],
                'avatar_url' => $user['avatar_url'],
            ],
        ]);
    }

    /**
     * POST /api/logout
     */
    public function logout(array $params): void
    {
        $user = requireAuth();
        // Tombstone rather than NULL — immediately invalidates any in-flight requests
        db()->prepare('
            UPDATE users
            SET session_token = ?, session_expires = NOW()
            WHERE id = ?
        ')->execute(['revoked_' . bin2hex(random_bytes(8)), $user['id']]);

        jsonResponse(['message' => 'Logged out successfully']);
    }

    // ── Private helpers ────────────────────────────────────

    private function exchangeCodeForToken(string $code): array
    {
        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: DevAgent/1.0',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'client_id'     => env('GITHUB_CLIENT_ID'),
                'client_secret' => env('GITHUB_CLIENT_SECRET'),
                'code'          => $code,
                'redirect_uri'  => env('GITHUB_REDIRECT_URI'),
            ]),
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException('GitHub token exchange failed');
        return json_decode($response, true) ?? [];
    }

    private function fetchGitHubUser(string $token): array
    {
        $ch = curl_init('https://api.github.com/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'User-Agent: DevAgent/1.0',
            ],
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) throw new \RuntimeException('GitHub user fetch failed');
        return json_decode($response, true) ?? [];
    }

    private function clientIp(): string
    {
        if (env('TRUST_PROXY') === 'true') {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded) return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
