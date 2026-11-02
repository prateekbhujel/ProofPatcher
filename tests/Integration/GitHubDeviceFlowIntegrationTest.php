<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class GitHubDeviceFlowIntegrationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempSnapshots;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempSnapshots = sys_get_temp_dir().'/proofpatch_deviceflow_int_'.bin2hex(random_bytes(6));
        putenv('PROOFPATCH_SNAPSHOT_DIR='.$this->tempSnapshots);
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();

        putenv('PROOFPATCH_SNAPSHOT_DIR');
        foreach (glob($this->tempSnapshots.'/*') ?: [] as $f) {
            @unlink($f);
        }
        if (is_dir($this->tempSnapshots)) {
            @rmdir($this->tempSnapshots);
        }
        $_SESSION = [];
        // Web and terminal genuinely share this state now: poll success
        // saves the token and selection syncs the repo, so clean both.
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();

        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testHostCliCredentialCannotBeConnectedThroughHttp(): void
    {
        $app = app();
        $request = Request::create('/github/local/connect', 'POST');
        $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayNotHasKey('github_auth', $_SESSION);
    }

    public function testInitiateFailsWhenNoClientIdProvided(): void
    {
        putenv('PROOFPATCH_GITHUB_CLIENT_ID');
        putenv('GITHUB_APP_CLIENT_ID');

        $controller = new DashboardController();
        $request = Request::create('/github/device/initiate', 'POST', []);

        $response = $controller->initiateDeviceFlow($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['github_device_flow'] ?? null);
        $this->assertStringContainsString('Client ID is required', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testPollFailsWhenNoDeviceFlowActive(): void
    {
        $controller = new DashboardController();
        $request = Request::create('/github/device/poll', 'POST', []);

        $response = $controller->pollDeviceFlow($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('No active device flow session', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testPollFailsWhenCodeExpired(): void
    {
        $_SESSION['github_device_flow'] = [
            'clientId' => 'Iv1.test',
            'deviceCode' => 'expired_code',
            'userCode' => 'ABCD-1234',
            'verificationUri' => 'https://github.com/login/device',
            'expiresAt' => time() - 100, // Expired in past
            'interval' => 5,
        ];

        $controller = new DashboardController();
        $request = Request::create('/github/device/poll', 'POST', []);

        $response = $controller->pollDeviceFlow($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($_SESSION['github_device_flow'] ?? null);
        $this->assertStringContainsString('expired', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testDisconnectClearsAllGitHubSessionState(): void
    {
        $_SESSION['github_auth'] = ['token' => 'tok', 'user' => ['login' => 'testuser']];
        $_SESSION['github_device_flow'] = ['deviceCode' => 'abc'];
        $_SESSION['selected_github_repo'] = 'testuser/repo';
        $_SESSION['github_repo_pulls'] = [['number' => 1]];

        $controller = new DashboardController();
        $response = $controller->disconnectDeviceFlow();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertArrayNotHasKey('github_auth', $_SESSION);
        $this->assertArrayNotHasKey('github_device_flow', $_SESSION);
        $this->assertArrayNotHasKey('selected_github_repo', $_SESSION);
        $this->assertArrayNotHasKey('github_repo_pulls', $_SESSION);
    }

    public function testSelectInstalledRepositoryFailsWhenUnauthenticated(): void
    {
        $controller = new DashboardController();
        $request = Request::create('/github/repositories/select', 'POST', ['repository' => 'owner/repo']);

        $response = $controller->selectInstalledRepository($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('not connected', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testSelectInstalledRepositoryFailsWhenRepoNotAuthorized(): void
    {
        $_SESSION['github_auth'] = [
            'token' => 'mock_token',
            'repositories' => [
                ['fullName' => 'authorized/repo'],
            ],
        ];

        $controller = new DashboardController();
        $request = Request::create('/github/repositories/select', 'POST', ['repository' => 'unauthorized/repo']);

        $response = $controller->selectInstalledRepository($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('not authorized for this installation', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testCaptureInstalledPullRequestFailsWhenInvalidNumber(): void
    {
        $_SESSION['github_auth'] = [
            'token' => 'mock_token',
            'repositories' => [
                ['fullName' => 'authorized/repo'],
            ],
        ];

        $controller = new DashboardController();
        $request = Request::create('/github/pulls/capture', 'POST', [
            'repository' => 'authorized/repo',
            'number' => 0,
        ]);

        $response = $controller->captureInstalledPullRequest($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('valid pull request number', $_SESSION['github_error'] ?? (session('github_error') ?? ''));
    }

    public function testLaravelSessionPersistenceAcrossLifecycle(): void
    {
        $session = app('session')->driver('array');
        $controller = new DashboardController();

        // Store connected GitHub auth into Laravel session
        $session->put('github_auth', [
            'token' => 'ghu_super_secret_access_token_12345',
            'user' => [
                'login' => 'pratikbhujel',
                'name' => 'Pratik Bhujel',
                'avatarUrl' => null,
                'htmlUrl' => 'https://github.com/pratikbhujel',
            ],
            'repositories' => [
                [
                    'id' => 1,
                    'name' => 'my-repo',
                    'fullName' => 'pratikbhujel/my-repo',
                    'owner' => 'pratikbhujel',
                    'isPrivate' => true,
                    'defaultBranch' => 'main',
                    'htmlUrl' => 'https://github.com/pratikbhujel/my-repo',
                ],
            ],
            'connectedAt' => gmdate(DATE_ATOM),
        ]);
        $session->save();

        // Subsequent request using the same Laravel session to view repositories
        $viewRequest = Request::create('/repositories', 'GET');
        $viewRequest->setLaravelSession($session);

        $inertiaResponse = $controller->repositories($viewRequest);
        $this->assertInstanceOf(\Inertia\Response::class, $inertiaResponse);

        $props = $inertiaResponse->toResponse($viewRequest)->getOriginalContent()->getData()['page']['props'];

        // Verify connected state is correctly read from Laravel session
        $this->assertTrue($props['githubConnection']['isConnected']);
        $this->assertSame('pratikbhujel', $props['githubConnection']['user']['login']);
        $this->assertCount(1, $props['githubConnection']['repositories']);
        $this->assertTrue($props['githubConnection']['repositories'][0]['isPrivate']);

        // CRITICAL: Verify secret token is NEVER exposed to client-side props
        $serializedProps = json_encode($props);
        $this->assertStringNotContainsString('ghu_super_secret_access_token_12345', $serializedProps);
        $this->assertArrayNotHasKey('token', $props['githubConnection']);

        // Disconnect request using same Laravel session
        $disconnectRequest = Request::create('/github/device/disconnect', 'POST');
        $disconnectRequest->setLaravelSession($session);

        $disconnectResponse = $controller->disconnectDeviceFlow($disconnectRequest);
        $this->assertSame(302, $disconnectResponse->getStatusCode());

        // Verify session was cleared in Laravel session store
        $this->assertNull($session->get('github_auth'));
        $this->assertNull($session->get('github_device_flow'));
    }

    public function testPollDeviceFlowCollectsMultiInstallationPaginationAndErrors(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_device_flow', [
            'clientId' => 'test_client_id',
            'deviceCode' => 'test_device_code',
            'userCode' => 'ABCD-1234',
            'verificationUri' => 'https://github.com/login/device',
            'expiresAt' => time() + 300,
            'interval' => 5,
        ]);
        $session->save();

        // Mock HTTP for device flow poll
        $flowHttp = new \Symfony\Component\HttpClient\MockHttpClient([
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
                'access_token' => 'ghu_test_token_multiorg',
                'token_type' => 'bearer',
                'scope' => '',
            ])),
        ]);
        $deviceFlow = new \ProofPatch\GitHub\GitHubDeviceFlow($flowHttp);

        // Mock HTTP for GitHubAppClient:
        // 1. /user
        // 2. /user/installations?per_page=100&page=1 (returns 2 installations)
        // 3. /user/installations/101/repositories (returns 1 repo)
        // 4. /user/installations/102/repositories (fails with 500 to simulate partial installation error)
        $appHttp = new \Symfony\Component\HttpClient\MockHttpClient([
            // Call 1: /user
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
                'login' => 'octocat',
                'name' => 'The Octocat',
                'avatar_url' => null,
                'html_url' => 'https://github.com/octocat',
            ])),
            // Call 2: /user/installations?per_page=100&page=1
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
                'total_count' => 2,
                'installations' => [
                    ['id' => 101, 'account' => ['login' => 'octocat']],
                    ['id' => 102, 'account' => ['login' => 'org-beta']],
                ],
            ])),
            // Call 3: /user/installations/101/repositories?per_page=100&page=1
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
                'total_count' => 1,
                'repositories' => [
                    [
                        'id' => 1001,
                        'name' => 'repo-alpha',
                        'full_name' => 'octocat/repo-alpha',
                        'owner' => ['login' => 'octocat'],
                        'private' => false,
                        'default_branch' => 'main',
                        'html_url' => 'https://github.com/octocat/repo-alpha',
                    ],
                ],
            ])),
            // Call 4: /user/installations/102/repositories?per_page=100&page=1 (fails with 500 error to trigger partial installation error)
            new \Symfony\Component\HttpClient\Response\MockResponse('{"message": "rate limited by GitHub API"}', [
                'http_code' => 500,
            ]),
        ]);
        $client = new \ProofPatch\GitHub\GitHubAppClient($appHttp);

        $controller = new DashboardController($client, $deviceFlow);
        $request = Request::create('/github/device/poll', 'POST');
        $request->setLaravelSession($session);

        $response = $controller->pollDeviceFlow($request);
        $this->assertSame(302, $response->getStatusCode());

        $auth = $session->get('github_auth');
        $this->assertIsArray($auth);
        $this->assertSame('octocat', $auth['user']['login']);
        $this->assertArrayHasKey('repositoriesStatus', $auth);
        $this->assertTrue($auth['repositoriesStatus']['truncated']);
        $this->assertSame(2, $auth['repositoriesStatus']['installationsCount']);
        $this->assertNotEmpty($auth['repositoriesStatus']['errors']);

        // Verify warning notification was pushed
        $notifications = $session->get('notifications', []);
        $this->assertNotEmpty($notifications);
        $titles = array_column($notifications, 'title');
        $this->assertContains('Repositories Incomplete', $titles);

        // Verify props in /repositories reflect status and errors
        $viewRequest = Request::create('/repositories', 'GET');
        $viewRequest->setLaravelSession($session);
        $inertiaResponse = $controller->repositories($viewRequest);
        $props = $inertiaResponse->toResponse($viewRequest)->getOriginalContent()->getData()['page']['props'];

        $this->assertNotNull($props['githubConnection']['repositoriesStatus']);
        $this->assertTrue($props['githubConnection']['repositoriesStatus']['truncated']);
        $this->assertSame(2, $props['githubConnection']['repositoriesStatus']['installationsCount']);
        $this->assertNotEmpty($props['githubConnection']['repositoriesStatus']['errors']);
    }

    public function testSelectInstalledRepositoryCollectsPullRequestTruncationAndErrors(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', [
            'token' => 'ghu_test_token',
            'user' => ['login' => 'octocat'],
            'repositories' => [
                ['fullName' => 'octocat/repo-alpha', 'isPrivate' => false],
            ],
            'repositoriesStatus' => ['truncated' => false, 'errors' => []],
        ]);
        $session->save();

        // Generate 100 pulls to trigger multi-page iteration and error on page 2
        $page1Pulls = [];
        for ($i = 1; $i <= 100; $i++) {
            $page1Pulls[] = [
                'number' => $i,
                'title' => "Open PR #{$i}",
                'state' => 'open',
                'head' => ['ref' => "patch-{$i}", 'sha' => str_repeat('a', 40)],
                'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor'],
            ];
        }

        $appHttp = new \Symfony\Component\HttpClient\MockHttpClient([
            // Issues are refreshed first on every repository selection.
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([])),
            // Page 1: returns 100 pulls
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode($page1Pulls)),
            // Page 2: returns 500 server error
            new \Symfony\Component\HttpClient\Response\MockResponse('{"message": "rate limit exceeded"}', [
                'http_code' => 500,
            ]),
        ]);
        $client = new \ProofPatch\GitHub\GitHubAppClient($appHttp);

        $controller = new DashboardController($client);
        $selectRequest = Request::create('/github/repositories/select', 'POST', [
            'repository' => 'octocat/repo-alpha',
        ]);
        $selectRequest->setLaravelSession($session);

        $response = $controller->selectInstalledRepository($selectRequest);
        $this->assertSame(302, $response->getStatusCode());
        // The terminal reads this same shared selection.
        $this->assertSame('octocat/repo-alpha', KernelCli::getSelectedRepo());

        $pullsStatus = $session->get('github_repo_pulls_status');
        $this->assertIsArray($pullsStatus);
        $this->assertTrue($pullsStatus['truncated']);
        $this->assertSame(100, $pullsStatus['count']);
        $this->assertNotEmpty($pullsStatus['errors']);

        // Verify warning notification was added
        $notifications = $session->get('notifications', []);
        $titles = array_column($notifications, 'title');
        $this->assertContains('Pull Requests Incomplete', $titles);

        // Verify /repositories Inertia props contain pull request status and errors
        $viewRequest = Request::create('/repositories', 'GET');
        $viewRequest->setLaravelSession($session);
        $inertiaResponse = $controller->repositories($viewRequest);
        $props = $inertiaResponse->toResponse($viewRequest)->getOriginalContent()->getData()['page']['props'];

        $this->assertNotNull($props['githubConnection']['repoPullsStatus']);
        $this->assertTrue($props['githubConnection']['repoPullsStatus']['truncated']);
        $this->assertSame(100, $props['githubConnection']['repoPullsStatus']['count']);
        $this->assertNotEmpty($props['githubConnection']['repoPullsStatus']['errors']);
    }

    public function testPollWithoutFlowReturnsToOriginPage(): void
    {
        $controller = new DashboardController();
        $request = Request::create('/github/device/poll', 'POST', ['redirect_to' => '/issues']);

        $response = $controller->pollDeviceFlow($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/issues', $response->getTargetUrl());
    }

    public function testPollRejectsOffsiteRedirectTarget(): void
    {
        $controller = new DashboardController();
        $request = Request::create('/github/device/poll', 'POST', ['redirect_to' => 'https://evil.invalid']);

        $response = $controller->pollDeviceFlow($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/repositories', $response->getTargetUrl());
    }
}
