<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Support;

use ProofPatch\GitHub\BotInstallationTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A real token-provider instance backed by synthetic GitHub responses. */
final class SyntheticBotProvider
{
    private string $keyPath;
    public bool $denyGrant = false;
    public ?\Closure $onIssue = null;

    public function __construct(private string $repository)
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) throw new \RuntimeException('Cannot create test key.');
        $this->keyPath = (string) tempnam(sys_get_temp_dir(), 'pp-test-bot-');
        file_put_contents($this->keyPath, $pem);
        chmod($this->keyPath, 0600);
    }

    public function __destruct()
    {
        @unlink($this->keyPath);
    }

    public function provider(): BotInstallationTokenProvider
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/app/installations/30/access_tokens' && $this->onIssue !== null) ($this->onIssue)();
            $repo = ['id' => 40, 'full_name' => $this->repository, 'private' => false];
            $data = match ($path) {
                '/app' => ['id' => 10, 'client_id' => 'client'],
                '/repos/'.$this->repository.'/installation' => ['id' => 30, 'app_id' => 10],
                '/user/installations/30/repositories' => ['total_count' => $this->denyGrant ? 0 : 1,
                    'repositories' => $this->denyGrant ? [] : [$repo]],
                '/app/installations/30/access_tokens' => ['token' => 'synthetic-bot-token',
                    'expires_at' => gmdate('c', time() + 3600),
                    'permissions' => ['pull_requests' => 'write', 'contents' => 'write', 'metadata' => 'read']],
                '/installation/repositories' => ['total_count' => 1, 'repositories' => [$repo]],
                default => throw new \RuntimeException('Unexpected synthetic GitHub request.'),
            };
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $method === 'POST' ? 201 : 200]);
        });
        return new BotInstallationTokenProvider($http, '10', 'client', $this->keyPath);
    }
}
