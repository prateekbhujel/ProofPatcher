<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Http\Middleware\AuthorizeGithubTasks;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Support\TaskWorkflowHelper;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubTaskAccessIsolationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function context(): array
    {
        $source = "<?php echo 1;\n";
        return [
            'repository' => 'owner/private-repo', 'issueNumber' => 1,
            'issueUrl' => 'https://github.com/owner/private-repo/issues/1',
            'path' => 'app.php', 'commit' => str_repeat('a', 40),
            'title' => 'Private issue', 'body' => 'Private details', 'source' => $source,
            'sourceSha256' => hash('sha256', $source),
        ];
    }

    private function middleware(int $userId, bool $grant): AuthorizeGithubTasks
    {
        $responses = [
            new MockResponse(json_encode(['id' => $userId, 'login' => 'user'.$userId])),
            new MockResponse(json_encode(['installations' => $grant ? [['id' => 101]] : []])),
        ];
        if ($grant) {
            $responses[] = new MockResponse(json_encode(['repositories' => [[
                'id' => 5, 'name' => 'private-repo', 'full_name' => 'owner/private-repo',
                'owner' => ['login' => 'owner'], 'private' => true,
            ]]]));
        }
        return new AuthorizeGithubTasks(new GitHubAppClient(new MockHttpClient($responses)));
    }

    private function request(string $method, string $path, array $data = [], int $sessionUser = 1): Request
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'test-token', 'user' => ['id' => $sessionUser]]);
        app('redirect')->setSession($session);
        $request = Request::create($path, $method, $data);
        $request->setLaravelSession($session);
        return $request;
    }

    public function testOnlyCurrentOwnerWithCurrentGrantCanSeeOrOpenTask(): void
    {
        $context = $this->context();
        $alice = WorkbenchTask::recordIntake($context, false, githubUserId: 1);
        $bob = WorkbenchTask::recordIntake($context, false, githubUserId: 2);
        $legacy = WorkbenchTask::recordIntake($context);
        self::assertNotSame($alice->id, $bob->id);

        $request = $this->request('GET', '/issues');
        $this->middleware(1, true)->handle($request, static fn () => new Response());
        $page = (new IssueWorkbenchController())->show($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
        $ids = array_column($page['tasks'], 'id');
        self::assertContains($alice->id, $ids);
        self::assertNotContains($bob->id, $ids);
        self::assertNotContains($legacy->id, $ids);

        $other = $this->request('POST', '/issues/switch-task', ['task_id' => $bob->id]);
        $response = $this->middleware(1, true)->handle($other, static fn () => new Response('should not run'));
        self::assertTrue($response->isRedirection());
        self::assertNull($other->session()->get('issue_context'));

        $revoked = $this->request('POST', '/issues/switch-task', ['task_id' => $alice->id]);
        $response = $this->middleware(1, false)->handle($revoked, static fn () => new Response('should not run'));
        self::assertTrue($response->isRedirection());
        self::assertNull($revoked->session()->get('issue_context'));
    }

    public function testAccountChangeClearsOldContextAndCachedPrivateIssues(): void
    {
        $alice = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
        $request = $this->request('GET', '/issues', sessionUser: 1);
        $request->session()->put('issue_task_id', $alice->id);
        $request->session()->put('issue_context', $this->context());
        $request->session()->put('selected_github_repo', 'owner/private-repo');
        $request->session()->put('github_repo_issues', [['title' => 'Secret']]);
        $this->middleware(2, true)->handle($request, static fn () => new Response());
        self::assertNull($request->session()->get('issue_context'));
        self::assertNull($request->session()->get('github_repo_issues'));
    }

    public function testCachedPublicFlagCannotKeepTaskVisibleAfterAccessIsRevoked(): void
    {
        $task = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
        $task->metadata = array_merge($task->metadata ?? [], ['is_confirmed_public' => true]);
        $task->save();

        $request = $this->request('GET', '/issues');
        $request->session()->put('issue_task_id', $task->id);
        $request->session()->put('issue_context', $this->context());
        $this->middleware(1, false)->handle($request, static fn () => new Response());
        $page = (new IssueWorkbenchController())->show($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];

        self::assertNotContains($task->id, array_column($page['tasks'], 'id'));
        self::assertNull($request->session()->get('issue_context'));

        $open = $this->request('POST', '/issues/switch-task', ['task_id' => $task->id]);
        $response = $this->middleware(1, false)->handle($open, static fn () => new Response('should not run'));
        self::assertTrue($response->isRedirection());
    }

    public function testMissingOrInvalidIdentityFailsClosed(): void
    {
        $task = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
        $request = $this->request('POST', '/issues/switch-task', ['task_id' => $task->id]);
        $http = new MockHttpClient([new MockResponse(json_encode(['login' => 'no-id']))]);
        $response = (new AuthorizeGithubTasks(new GitHubAppClient($http)))
            ->handle($request, static fn () => new Response('should not run'));
        self::assertTrue($response->isRedirection());
        self::assertNull($request->session()->get('issue_context'));
    }

    public function testPrivateBackendEvidenceIsNotListedOrServedFromChecks(): void
    {
        $root = sys_get_temp_dir().'/proofpatch-private-evidence-'.bin2hex(random_bytes(6));
        $id = '20261029T120000Z-123456789abc';
        mkdir($root.'/'.$id.'/baseline', 0700, true);
        file_put_contents($root.'/'.$id.'/baseline/checkout.png', 'private image');
        file_put_contents($root.'/'.$id.'/result.json', json_encode([
            'id' => $id, 'decision' => 'VERIFIED', 'kind' => 'owned_backend', 'isSynthetic' => false,
            'context' => ['repository' => 'owner/private-repo', 'issueNumber' => 1],
        ], JSON_THROW_ON_ERROR));
        try {
            $controller = new DashboardController();
            (new \ReflectionProperty($controller, 'runsDir'))->setValue($controller, $root);
            $request = $this->request('GET', '/checks', ['run' => $id]);
            $props = $controller->checks($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
            self::assertArrayNotHasKey($id, $props['history']);
            self::assertNull($props['report']);
            self::assertSame(404, $controller->imageByPath($id, 'baseline')->getStatusCode());
        } finally {
            unlink($root.'/'.$id.'/baseline/checkout.png');
            unlink($root.'/'.$id.'/result.json');
            rmdir($root.'/'.$id.'/baseline');
            rmdir($root.'/'.$id);
            rmdir($root);
        }
    }

    public function testSyntheticBackendReportIsNotPresentedAsCheckoutEvidence(): void
    {
        $root = sys_get_temp_dir().'/proofpatch-checks-kind-'.bin2hex(random_bytes(6));
        $id = '20261103T153037Z-5712bc7f0c8c';
        mkdir($root.'/'.$id, 0700, true);
        file_put_contents($root.'/'.$id.'/result.json', json_encode([
            'id' => $id, 'decision' => 'VERIFIED', 'kind' => 'owned_backend', 'isSynthetic' => true,
            'gates' => ['baselineReproduced' => true, 'candidatePassed' => true],
        ], JSON_THROW_ON_ERROR));
        try {
            $controller = new DashboardController();
            (new \ReflectionProperty($controller, 'runsDir'))->setValue($controller, $root);
            $request = $this->request('GET', '/checks', ['run' => $id]);
            $props = $controller->checks($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
            self::assertArrayNotHasKey($id, $props['history']);
            self::assertNull($props['report']);
        } finally {
            unlink($root.'/'.$id.'/result.json');
            rmdir($root.'/'.$id);
            rmdir($root);
        }
    }

    public function testUnauthorizedSessionsCannotRunAnyTaskAction(): void
    {
        $task = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
        foreach (['draft', 'propose', 'verify', 'publish-pr', 'reconcile-pr'] as $action) {
            $request = $this->request('POST', '/issues/'.$action, sessionUser: 2);
            $request->session()->put('issue_task_id', $task->id);
            $request->session()->put('issue_context', $this->context());
            $response = $this->middleware(2, true)->handle($request, static fn () => new Response('should not run'));
            self::assertTrue($response->isRedirection(), $action);
            self::assertNull($request->session()->get('issue_context'), $action);
        }
    }

    public function testRevokedRepositoryGrantHidesItsOldNotifications(): void
    {
        $request = $this->request('GET', '/');
        $request->session()->put('notifications', [
            ['title' => 'Private issue', 'githubUserId' => 1, 'githubRepository' => 'owner/private-repo', 'isLocal' => false],
            ['title' => 'Local fixture', 'githubUserId' => null, 'githubRepository' => null, 'isLocal' => true],
            ['title' => 'Old unclassified message'],
        ]);
        $this->middleware(1, false)->handle($request, static fn () => new Response());
        self::assertSame(['Local fixture'], array_column($request->session()->get('notifications'), 'title'));
    }

    public function testRealWebRouteDoesNotListPrivateTasksAnonymously(): void
    {
        $task = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
        $request = Request::create('/issues', 'GET', [], [], [], [
            'HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INERTIA_VERSION' => hash_file('xxh128', public_path('build/manifest.json')),
        ]);
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        try {
            self::assertSame(200, $response->getStatusCode());
            $page = json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR);
            self::assertNotContains($task->id, array_column($page['props']['tasks'], 'id'));
            self::assertStringNotContainsString('Private details', (string) $response->getContent());
        } finally {
            $kernel->terminate($request, $response);
        }
    }

    public function testAuthenticatedIntakeBindsTaskToVerifiedUser(): void
    {
        $context = $this->context();
        $http = new MockHttpClient([
            new MockResponse(json_encode(['default_branch' => 'main', 'private' => true])),
            new MockResponse(json_encode(['sha' => $context['commit']])),
            new MockResponse(json_encode(['number' => 1, 'title' => $context['title'], 'body' => $context['body']])),
            new MockResponse(json_encode(['type' => 'file', 'encoding' => 'base64', 'content' => base64_encode($context['source'])])),
        ]);
        $controller = new IssueWorkbenchController($http);
        $request = $this->request('POST', '/issues/load-issue', [
            'repository' => $context['repository'], 'issue_number' => 1, 'path' => $context['path'],
        ]);
        $this->middleware(1, true)->handle($request, fn ($req) => $controller->loadIssue($req));
        $task = WorkbenchTask::findOrFail($request->session()->get('issue_task_id'));
        self::assertSame(1, $task->github_user_id);
        self::assertSame('Private details', $task->body);
    }

    public function testPrivateAndUnknownVisibilityNeverReachModel(): void
    {
        $previousKey = getenv('BASETEN_API_KEY');
        putenv('BASETEN_API_KEY=' . str_repeat('x', 32));
        $budgetDir = sys_get_temp_dir() . '/proofpatch-privacy-budget-' . bin2hex(random_bytes(6));
        mkdir($budgetDir, 0700);
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;
            throw new \RuntimeException('Unexpected external call');
        });
        try {
            $task = WorkbenchTask::recordIntake($this->context(), false, githubUserId: 1);
            $budget = new RequestBudget($budgetDir, 500000, 10);
            $controller = new IssueWorkbenchController($http, $budget);
            foreach ([true, null] as $visibility) {
                $request = $this->request('POST', '/issues/propose');
                $request->session()->put('github_auth.repositories', [[
                    'fullName' => 'owner/private-repo', 'isPrivate' => $visibility,
                ]]);
                $request->attributes->set('proofpatch_github_access', [
                    'userId' => 1, 'repositories' => ['owner/private-repo' => true],
                ]);
                TaskWorkflowHelper::hydrateTaskSession($request, $task);
                $context = $request->session()->get('issue_context');
                $digest = (new IssueSuggestion($http, $budget))->preview($context)['digest'];
                $request->merge(['approved' => true, 'digest' => $digest]);

                $response = $controller->propose($request);
                self::assertTrue($response->isRedirection());
                self::assertStringContainsString('stays local', (string) $request->session()->get('error'));
                self::assertNull($request->session()->get('issue_suggestion'));
            }
            self::assertSame(0, $calls);
        } finally {
            $previousKey === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY=' . $previousKey);
            foreach (glob($budgetDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($budgetDir);
        }
    }

    public function testDedicatedPublicRepositoryIsRecheckedBeforeSending(): void
    {
        $previousKey = getenv('BASETEN_API_KEY');
        putenv('BASETEN_API_KEY=' . str_repeat('x', 32));
        $budgetDir = sys_get_temp_dir() . '/proofpatch-privacy-budget-' . bin2hex(random_bytes(6));
        mkdir($budgetDir, 0700);
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = [$method, $url];
            return new MockResponse(json_encode(['private' => true, 'visibility' => 'private']));
        });
        try {
            $context = $this->context();
            $context['source'] = (string) file_get_contents(base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php'));
            $context['sourceSha256'] = hash('sha256', $context['source']);
            $context['path'] = 'src/OrderTotal.php';
            $task = WorkbenchTask::recordIntake($context, true, githubUserId: 1);
            $budget = new RequestBudget($budgetDir, 500000, 10);
            $controller = new IssueWorkbenchController($http, $budget);
            $request = $this->request('POST', '/issues/propose');
            $request->session()->put('github_auth.repositories', [[
                'fullName' => 'owner/private-repo', 'isPrivate' => false,
            ]]);
            $request->attributes->set('proofpatch_github_access', [
                'userId' => 1, 'repositories' => ['owner/private-repo' => true],
            ]);
            TaskWorkflowHelper::hydrateTaskSession($request, $task);
            $digest = (new IssueSuggestion($http, $budget))->preview($context)['digest'];
            $request->merge(['approved' => true, 'digest' => $digest]);

            $controller->propose($request);
            self::assertStringContainsString('could not be verified as public', (string) $request->session()->get('error'));
            self::assertNull($request->session()->get('issue_suggestion'));
            self::assertCount(1, $calls);
            self::assertSame('GET', $calls[0][0]);
            self::assertStringStartsWith('https://api.github.com/', $calls[0][1]);
        } finally {
            $previousKey === false ? putenv('BASETEN_API_KEY') : putenv('BASETEN_API_KEY=' . $previousKey);
            foreach (glob($budgetDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($budgetDir);
        }
    }
}
