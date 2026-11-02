<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\BotIdentityConfigurationException;
use ProofPatch\GitHub\BotInstallationTokenProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BotInstallationTokenProviderTest extends TestCase
{
    private string $keyPath;
    private string $publicKey;
    private string $scenario = '';
    private array $calls = [];

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $this->publicKey = $details['key'];
        $this->keyPath = tempnam(sys_get_temp_dir(), 'pp-bot-test-');
        file_put_contents($this->keyPath, $pem);
        chmod($this->keyPath, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);
    }

    private function provider(): BotInstallationTokenProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            $this->calls[] = [$method, $path, $options];
            if ($this->scenario === 'network_error') throw new RuntimeException('secret should not be exposed');
            $data = match ($path) {
                '/app' => ['id' => $this->scenario === 'wrong_app' ? 11 : 10,
                    'client_id' => $this->scenario === 'wrong_client' ? 'other' : 'client'],
                '/repos/owner/repo/installation' => ['id' => 30, 'app_id' => $this->scenario === 'wrong_installation' ? 11 : 10],
                '/user/installations/30/repositories' => ['total_count' => 1, 'repositories' => [[
                    'id' => 40, 'full_name' => $this->scenario === 'no_user_grant' ? 'owner/other' : 'owner/repo',
                    'private' => $this->scenario === 'private_repo',
                ]]],
                '/app/installations/30/access_tokens' => ['token' => $this->scenario === 'same_token' ? 'user-token' : 'bot-token',
                    'expires_at' => gmdate('c', time() + 3600),
                    'permissions' => $this->scenario === 'broad_permissions'
                        ? ['pull_requests' => 'write', 'contents' => 'write'] : ['pull_requests' => 'write', 'metadata' => 'read']],
                '/installation/repositories' => ['total_count' => $this->scenario === 'broad_repo' ? 2 : 1,
                    'repositories' => [['id' => 40, 'full_name' => 'owner/repo']]],
                default => throw new RuntimeException('Unexpected request'),
            };
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $method === 'POST' ? 201 : 200]);
        });
        return new BotInstallationTokenProvider($http, '10', 'client', $this->keyPath);
    }

    public function testSignsAppJwtAndMintsOnlyTheVerifiedRepositoryAndPermission(): void
    {
        self::assertSame('bot-token', $this->provider()->forRepository('user-token', 'owner/repo'));
        self::assertCount(5, $this->calls);
        $auth = implode(' ', $this->calls[0][2]['normalized_headers']['authorization']);
        $jwt = substr($auth, strpos($auth, 'Bearer ') + 7);
        [$header, $payload, $signature] = explode('.', $jwt);
        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('10', $decoded['iss']);
        self::assertLessThanOrEqual(600, $decoded['exp'] - $decoded['iat']);
        self::assertSame(1, openssl_verify($header.'.'.$payload, base64_decode(strtr($signature, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256));
        $body = json_decode($this->calls[3][2]['body'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['repository_ids' => [40], 'permissions' => ['pull_requests' => 'write']], $body);
        self::assertStringContainsString('user-token', implode(' ', $this->calls[2][2]['normalized_headers']['authorization']));
        self::assertStringContainsString('bot-token', implode(' ', $this->calls[4][2]['normalized_headers']['authorization']));
    }

    public function testRejectsMismatchedAppsGrantsAndOverbroadTokens(): void
    {
        foreach (['wrong_app', 'wrong_client', 'wrong_installation', 'no_user_grant', 'private_repo', 'same_token', 'broad_permissions', 'broad_repo'] as $case) {
            $this->scenario = $case;
            try { $this->provider()->forRepository('user-token', 'owner/repo'); self::fail($case.' should fail'); }
            catch (RuntimeException) { self::assertTrue(true, $case); }
        }
    }

    public function testInvalidConfigurationFailsBeforeAnyNetworkRequest(): void
    {
        chmod($this->keyPath, 0644);
        try { $this->provider()->forRepository('user-token', 'owner/repo'); self::fail('Expected invalid key permissions'); }
        catch (BotIdentityConfigurationException) { self::assertSame([], $this->calls); }
    }

    public function testTransportErrorsDoNotLeakCredentialDetails(): void
    {
        $this->scenario = 'network_error';
        try { $this->provider()->forRepository('user-token', 'owner/repo'); self::fail('Expected failure'); }
        catch (RuntimeException $e) { self::assertStringNotContainsString('secret', $e->getMessage()); }
    }
}
