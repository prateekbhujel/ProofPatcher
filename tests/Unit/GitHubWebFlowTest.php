<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubWebFlow;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubWebFlowTest extends TestCase
{
    public function testAuthorizeUrlCarriesClientRedirectAndState(): void
    {
        $flow = new GitHubWebFlow(new MockHttpClient());
        $url = $flow->authorizeUrl('Iv1.abc123', 'http://127.0.0.1:8000/github/callback', 'state-xyz');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('Iv1.abc123', $params['client_id']);
        $this->assertSame('http://127.0.0.1:8000/github/callback', $params['redirect_uri']);
        $this->assertSame('state-xyz', $params['state']);
        $this->assertStringNotContainsString('secret', strtolower($url));
    }

    public function testAuthorizeUrlRejectsBadClientId(): void
    {
        $flow = new GitHubWebFlow(new MockHttpClient());
        $this->expectException(\InvalidArgumentException::class);
        $flow->authorizeUrl('bad id!', 'http://127.0.0.1:8000/github/callback', 's');
    }

    public function testExchangeCodeReturnsToken(): void
    {
        $seen = null;
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'ghu_token', 'token_type' => 'bearer']))]);
        $flow = new GitHubWebFlow($http);
        $result = $flow->exchangeCode('cid', 'client-secret-value', 'the-code', 'http://127.0.0.1:8000/github/callback');
        $this->assertSame('ghu_token', $result['accessToken']);
    }

    public function testExchangeCodeSurfacesGitHubErrorWithoutLeakingSecret(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.']))]);
        $flow = new GitHubWebFlow($http);
        try {
            $flow->exchangeCode('cid', 'super-secret-value', 'bad-code', 'http://127.0.0.1:8000/github/callback');
            $this->fail('exchange must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incorrect or expired', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage());
        }
    }

    public function testExchangeCodeRequiresSecret(): void
    {
        $flow = new GitHubWebFlow(new MockHttpClient());
        $this->expectException(\InvalidArgumentException::class);
        $flow->exchangeCode('cid', '', 'code', 'http://127.0.0.1:8000/github/callback');
    }
}
