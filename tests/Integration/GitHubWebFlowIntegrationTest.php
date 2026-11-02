<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\GitHub\GitHubWebFlow;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubWebFlowIntegrationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
        $this->setEnv('PROOFPATCH_GITHUB_CLIENT_ID', 'Iv1.testclient');
        $this->setEnv('GITHUB_APP_CLIENT_ID', null);
    }

    protected function tearDown(): void
    {
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
        foreach ($this->envBackup as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value, true);
        }
        $this->envBackup = [];
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function setEnv(string $key, ?string $value, bool $restore = false): void
    {
        if (!$restore) {
            $this->envBackup[$key] ??= getenv($key);
        }
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function request(string $path, array $query, $session): Request
    {
        app('redirect')->setSession($session);
        $request = Request::create($path, 'GET', $query);
        $request->setLaravelSession($session);
        return $request;
    }

    public function testAuthorizeNeedsConfiguredSecret(): void
    {
        $this->setEnv('PROOFPATCH_GITHUB_CLIENT_SECRET', null);
        $session = app('session')->driver('array');
        $controller = new DashboardController();

        $response = $controller->redirectToGitHub($this->request('/github/authorize', ['redirect_to' => '/settings'], $session));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/settings', $response->getTargetUrl());
        $this->assertStringContainsString('PROOFPATCH_GITHUB_CLIENT_SECRET', (string) $session->get('github_error'));
        $this->assertStringContainsString('PROOFPATCH_GITHUB_CLIENT_SECRET', (string) $session->get('error'));
    }

    public function testAuthorizeSendsBrowserToGitHubWithStateOnly(): void
    {
        $this->setEnv('PROOFPATCH_GITHUB_CLIENT_SECRET', 'test-secret-not-in-url');
        $session = app('session')->driver('array');
        $controller = new DashboardController();

        $response = $controller->redirectToGitHub($this->request('/github/authorize', ['redirect_to' => '/issues'], $session));

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getTargetUrl();
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('Iv1.testclient', $params['client_id']);
        $this->assertSame('http://127.0.0.1:8000/github/callback', $params['redirect_uri']);
        $this->assertStringNotContainsString('test-secret-not-in-url', $location);

        $oauth = $session->get('github_oauth');
        $this->assertIsArray($oauth);
        $this->assertSame($params['state'], $oauth['state']);
        $this->assertSame('/issues', $oauth['returnTo']);
    }

    public function testCallbackDeniedReturnsToOriginWithFriendlyError(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_oauth', ['state' => 's1', 'returnTo' => '/settings', 'createdAt' => time()]);
        $controller = new DashboardController();

        $response = $controller->handleGitHubCallback($this->request('/github/callback', ['error' => 'access_denied', 'state' => 's1'], $session));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/settings', $response->getTargetUrl());
        $this->assertSame('Authorization was declined on GitHub.', $session->get('github_error'));
        $this->assertNull($session->get('github_oauth'));
        $this->assertNull($session->get('github_auth'));
    }

    public function testCallbackRejectsForgedState(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_oauth', ['state' => 'real-state', 'returnTo' => '/issues', 'createdAt' => time()]);
        $controller = new DashboardController();

        $response = $controller->handleGitHubCallback($this->request('/github/callback', ['code' => 'abc', 'state' => 'forged'], $session));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/issues', $response->getTargetUrl());
        $this->assertSame('Sign-in could not be verified. Please try again.', $session->get('github_error'));
        $this->assertNull($session->get('github_auth'));
    }

    public function testCallbackRejectsExpiredState(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_oauth', ['state' => 's1', 'returnTo' => '/issues', 'createdAt' => time() - 3600]);
        $controller = new DashboardController();

        $response = $controller->handleGitHubCallback($this->request('/github/callback', ['code' => 'abc', 'state' => 's1'], $session));

        $this->assertStringContainsString('could not be verified', (string) $session->get('github_error'));
        $this->assertNull($session->get('github_auth'));
    }

    public function testCallbackSuccessConnectsAndReturnsToOrigin(): void
    {
        $this->setEnv('PROOFPATCH_GITHUB_CLIENT_SECRET', 'test-secret');
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_oauth', ['state' => 's1', 'returnTo' => '/settings', 'createdAt' => time()]);

        $exchangeHttp = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'ghu_liveish', 'token_type' => 'bearer'])),
        ]);
        $appHttp = new MockHttpClient([
            new MockResponse(json_encode(['id' => 42, 'login' => 'octo', 'name' => 'Octo', 'avatar_url' => null, 'html_url' => 'https://github.com/octo'])),
            new MockResponse(json_encode(['installations' => [['id' => 7]]])),
            new MockResponse(json_encode(['repositories' => [[
                'id' => 5, 'name' => 'secret-repo', 'full_name' => 'octo/secret-repo',
                'owner' => ['login' => 'octo'], 'private' => true, 'default_branch' => 'main', 'html_url' => 'https://github.com/octo/secret-repo',
            ]]])),
        ]);
        $controller = new DashboardController(
            new GitHubAppClient($appHttp),
            null,
            null,
            new GitHubWebFlow($exchangeHttp),
        );

        $response = $controller->handleGitHubCallback($this->request('/github/callback', ['code' => 'good-code', 'state' => 's1'], $session));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/settings', $response->getTargetUrl());
        $auth = $session->get('github_auth');
        $this->assertSame('octo', $auth['user']['login']);
        $this->assertSame('octo/secret-repo', $auth['repositories'][0]['fullName']);
        $this->assertTrue($auth['repositories'][0]['isPrivate']);
        $this->assertSame('ghu_liveish', GitHubTokenStore::getToken());
        $this->assertStringContainsString('Successfully connected', (string) $session->get('github_status'));
        // State is single-use: replaying the callback must fail.
        $replay = $controller->handleGitHubCallback($this->request('/github/callback', ['code' => 'good-code', 'state' => 's1'], $session));
        $this->assertStringContainsString('could not be verified', (string) $session->get('github_error'));
    }

    public function testCallbackNeverReturnsOffsite(): void
    {
        $session = app('session')->driver('array');
        // Even a poisoned session value cannot bounce the browser off-site.
        $session->put('github_oauth', ['state' => 's1', 'returnTo' => 'https://evil.invalid', 'createdAt' => time()]);
        $controller = new DashboardController();

        $response = $controller->handleGitHubCallback($this->request('/github/callback', ['error' => 'access_denied', 'state' => 's1'], $session));

        $this->assertStringEndsWith('/issues', $response->getTargetUrl());
    }
}
