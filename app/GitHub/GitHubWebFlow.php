<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub web authorization flow (the browser redirect sign-in) for the
 * local web app. The terminal keeps the device flow; both end at
 * GitHubAppClient verification and the same token store.
 *
 * The client secret travels only in the token-exchange POST body, from
 * the environment. It never appears in URLs, redirects, or logs.
 *
 * https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-a-user-access-token-for-a-github-app
 */
final readonly class GitHubWebFlow
{
    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    private const ACCESS_TOKEN_URL = 'https://github.com/login/oauth/access_token';

    public function __construct(private HttpClientInterface $http)
    {
    }

    /** Step 1: where to send the browser to authorize on GitHub. */
    public function authorizeUrl(string $clientId, string $redirectUri, string $state): string
    {
        $clientId = trim($clientId);
        if ($clientId === '' || strlen($clientId) > 100 || !preg_match('/\A[a-zA-Z0-9._-]+\z/', $clientId)) {
            throw new \InvalidArgumentException('A valid GitHub App Client ID is required.');
        }

        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Step 2: exchange the callback code for a user access token.
     *
     * @return array{accessToken: string, tokenType: string}
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $code = trim($code);
        if ($clientId === '' || $clientSecret === '' || $code === '') {
            throw new \InvalidArgumentException('Client ID, client secret, and authorization code are required.');
        }

        $response = $this->http->request('POST', self::ACCESS_TOKEN_URL, [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'ProofPatcher',
            ],
            'json' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            'timeout' => 15,
        ]);

        $data = json_decode($response->getContent(false), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub authorization failed: invalid response from GitHub.');
        }
        if (isset($data['access_token']) && is_string($data['access_token']) && $data['access_token'] !== '') {
            return [
                'accessToken' => (string) $data['access_token'],
                'tokenType' => (string) ($data['token_type'] ?? 'bearer'),
            ];
        }

        // GitHub reports exchange failures as error fields, not HTTP status.
        throw new \RuntimeException('GitHub authorization failed: ' . ((string) ($data['error_description'] ?? $data['error'] ?? 'unknown error')));
    }
}
