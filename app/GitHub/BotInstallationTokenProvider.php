<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Creates a short-lived, single-repository token for the configured GitHub App. */
final readonly class BotInstallationTokenProvider
{
    private const ROOT = 'https://api.github.com';

    public function __construct(
        private HttpClientInterface $http,
        private ?string $appId = null,
        private ?string $clientId = null,
        private ?string $privateKeyPath = null,
    ) {}

    public function forRepository(string $userToken, string $repository): string
    {
        if ($userToken === '' || !preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository, $parts)
            || in_array($parts[2], ['.', '..'], true)) {
            throw new \InvalidArgumentException('Invalid GitHub repository or user authorization.');
        }

        $appId = trim($this->appId ?? (string) getenv('PROOFPATCH_GITHUB_APP_ID'));
        $clientId = trim($this->clientId ?? (string) getenv('PROOFPATCH_GITHUB_CLIENT_ID'));
        $keyPath = trim($this->privateKeyPath ?? (string) getenv('PROOFPATCH_GITHUB_APP_PRIVATE_KEY_PATH'));
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $appId) || $clientId === '' || !str_starts_with($keyPath, '/')) {
            throw new BotIdentityConfigurationException('GitHub bot is not configured. Set the app ID and owner-only private key path.');
        }
        $stat = @lstat($keyPath);
        if (!is_array($stat) || !is_file($keyPath) || is_link($keyPath) || ($stat['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || $stat['size'] < 1 || $stat['size'] > 16384) {
            throw new BotIdentityConfigurationException('GitHub bot private key must be an owner-only regular file.');
        }
        $privateKey = @file_get_contents($keyPath);
        $key = is_string($privateKey) ? @openssl_pkey_get_private($privateKey) : false;
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($key === false || !is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new BotIdentityConfigurationException('GitHub bot private key is invalid or unsupported.');
        }

        $now = time();
        $unsigned = self::base64url('{"alg":"RS256","typ":"JWT"}').'.'.self::base64url(json_encode([
            'iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId,
        ], JSON_THROW_ON_ERROR));
        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new BotIdentityConfigurationException('Could not sign GitHub bot authentication.');
        }
        $jwt = $unsigned.'.'.self::base64url($signature);

        $app = $this->request('GET', '/app', $jwt);
        if ((string) ($app['id'] ?? '') !== $appId || ($app['client_id'] ?? null) !== $clientId) {
            throw new \RuntimeException('The configured GitHub App does not match the sign-in app.');
        }
        $root = '/repos/'.rawurlencode($parts[1]).'/'.rawurlencode($parts[2]);
        $installation = $this->request('GET', $root.'/installation', $jwt);
        $installationId = $installation['id'] ?? null;
        if (!is_int($installationId) || $installationId < 1 || (string) ($installation['app_id'] ?? '') !== $appId) {
            throw new \RuntimeException('GitHub App installation could not be verified for this repository.');
        }

        // Verify this user can see this exact repository under this exact app installation.
        $repositoryId = null;
        for ($page = 1; $page <= 5; $page++) {
            $listed = $this->request('GET', '/user/installations/'.$installationId.'/repositories?per_page=100&page='.$page, $userToken);
            $repos = $listed['repositories'] ?? null;
            if (!is_array($repos) || !array_is_list($repos) || !is_int($listed['total_count'] ?? null)) {
                throw new \RuntimeException('GitHub user repository access could not be verified.');
            }
            foreach ($repos as $repo) {
                if (is_array($repo) && is_string($repo['full_name'] ?? null) && strcasecmp($repo['full_name'], $repository) === 0
                    && is_int($repo['id'] ?? null) && $repo['id'] > 0 && ($repo['private'] ?? null) === false) {
                    $repositoryId = $repo['id'];
                    break 2;
                }
            }
            if (count($repos) < 100) break;
        }
        if ($repositoryId === null) throw new \RuntimeException('GitHub user access to the app installation could not be verified.');

        $issued = $this->request('POST', '/app/installations/'.$installationId.'/access_tokens', $jwt, [
            'repository_ids' => [$repositoryId], 'permissions' => ['pull_requests' => 'write'],
        ], 201);
        $token = $issued['token'] ?? null;
        $expires = is_string($issued['expires_at'] ?? null) ? strtotime($issued['expires_at']) : false;
        $permissions = $issued['permissions'] ?? null;
        if (!is_string($token) || $token === '' || hash_equals($userToken, $token) || $expires === false
            || $expires <= $now || $expires > $now + 3700 || !is_array($permissions)
            || ($permissions['pull_requests'] ?? null) !== 'write') {
            throw new \RuntimeException('GitHub did not issue a valid scoped bot token.');
        }
        foreach ($permissions as $permission => $level) {
            if (!in_array($permission, ['pull_requests', 'metadata'], true)
                || ($permission === 'metadata' && $level !== 'read')) {
                throw new \RuntimeException('GitHub bot token has unexpected permissions.');
            }
        }
        $scoped = $this->request('GET', '/installation/repositories?per_page=100', $token);
        $scopedRepos = $scoped['repositories'] ?? null;
        if (($scoped['total_count'] ?? null) !== 1 || !is_array($scopedRepos) || count($scopedRepos) !== 1
            || ($scopedRepos[0]['id'] ?? null) !== $repositoryId
            || !is_string($scopedRepos[0]['full_name'] ?? null) || strcasecmp($scopedRepos[0]['full_name'], $repository) !== 0) {
            throw new \RuntimeException('GitHub bot token repository scope could not be verified.');
        }
        return $token;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function request(string $method, string $path, string $credential, ?array $json = null, int $expected = 200): array
    {
        try {
            $options = ['headers' => ['Accept' => 'application/vnd.github+json', 'Authorization' => 'Bearer '.$credential,
                'User-Agent' => 'ProofPatcher', 'X-GitHub-Api-Version' => '2022-11-28'],
                'timeout' => 15, 'max_duration' => 20, 'max_redirects' => 0];
            if ($json !== null) $options['json'] = $json;
            $response = $this->http->request($method, self::ROOT.$path, $options);
            if ($response->getStatusCode() !== $expected) throw new \RuntimeException();
            $body = $response->getContent(false);
            if (strlen($body) > 1048576) throw new \RuntimeException();
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new \RuntimeException();
            return $decoded;
        } catch (\Throwable) {
            // Do not forward transport exceptions, response bodies, JWTs, or tokens into logs.
            throw new \RuntimeException('GitHub bot authorization could not be verified.');
        }
    }
}
