<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Evidence\Receipt;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IssueWorkbenchConnectedWorkflowTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempBudgetDir;
    private ?string $originalHome;
    private ?string $originalBasetenKey;
    private MockHttpClient $blockingHttp;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->originalHome = getenv('HOME') !== false ? (string) getenv('HOME') : null;
        $this->originalBasetenKey = getenv('BASETEN_API_KEY') !== false ? (string) getenv('BASETEN_API_KEY') : null;

        // Block outbound network and isolate environment
        putenv('BASETEN_API_KEY');
        $this->tempBudgetDir = sys_get_temp_dir().'/proofpatch-budget-'.bin2hex(random_bytes(6));
        mkdir($this->tempBudgetDir, 0700, true);
        putenv('HOME='.$this->tempBudgetDir);

        $this->blockingHttp = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Strict network isolation: real network requests are blocked in tests.');
        });

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();

        if ($this->originalHome !== null) {
            putenv('HOME='.$this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalBasetenKey !== null) {
            putenv('BASETEN_API_KEY='.$this->originalBasetenKey);
        } else {
            putenv('BASETEN_API_KEY');
        }

        $this->removeDirectory($this->tempBudgetDir);
        $_SESSION = [];
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (new \FilesystemIterator($path) as $file) {
            $file->isDir() && !$file->isLink() ? $this->removeDirectory($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }

    public function testConnectedOwnedWorkflowFromIntakeToProposeToVerifyAndDraft(): void
    {
        $session = app('session')->driver('array');
        $budget = new RequestBudget($this->tempBudgetDir, 500000, 10);
        $controller = new IssueWorkbenchController($this->blockingHttp, $budget);

        // Step 1: Load owned review case
        $loadReq = Request::create('/issues/load-owned', 'POST');
        $loadReq->setLaravelSession($session);
        $controller->loadOwnedCase($loadReq);

        $context = $session->get('issue_context');
        self::assertIsArray($context);
        self::assertTrue($context['isSynthetic']);
        self::assertSame('src/OrderTotal.php', $context['path']);

        // Step 2: Propose (deterministic offline reference in test environment)
        $preview = (new IssueSuggestion($this->blockingHttp, $budget))->preview($context);
        $proposeReq = Request::create('/issues/propose', 'POST', [
            'approved' => true,
            'digest' => $preview['digest'],
        ]);
        $proposeReq->setLaravelSession($session);
        $controller->propose($proposeReq);

        $suggestion = $session->get('issue_suggestion');
        self::assertIsArray($suggestion);
        self::assertArrayHasKey('candidate', $suggestion);
        self::assertFalse($suggestion['candidate']['verified']);
        self::assertStringContainsString('$item[\'quantity\']', $suggestion['candidate']['source']);

        // Step 3: Verify candidate with ProofPatch Verifier
        $verifyReq = Request::create('/issues/verify', 'POST');
        $verifyReq->setLaravelSession($session);
        $verifyRes = $controller->verifyCandidate($verifyReq);

        self::assertTrue($verifyRes->isRedirection());
        self::assertStringEndsWith('/issues', $verifyRes->getTargetUrl());

        $report = $session->get('issue_verification_report');
        self::assertIsArray($report);
        self::assertSame('VERIFIED', $report['decision']);
        self::assertSame('owned_backend', $report['kind']);
        self::assertTrue($report['isSynthetic']);

        // Check all 5 verification gates
        self::assertTrue($report['gates']['baselineReproduced']);
        self::assertTrue($report['gates']['negativeControlCaught']);
        self::assertTrue($report['gates']['candidatePassed']);
        self::assertTrue($report['gates']['candidateStaticSafetyPassed']);
        self::assertTrue($report['gates']['allowlistIntegrityIntact']);

        // Check runs exit codes
        self::assertSame(1, $report['runs']['baseline']['exitCode']);
        self::assertSame(1, $report['runs']['negative']['exitCode']);
        self::assertSame(0, $report['runs']['candidate']['exitCode']);

        // Check cryptographic receipt
        self::assertArrayHasKey('receipt', $report);
        self::assertSame('VALID', $report['receipt']['integrity']);
        self::assertFalse($report['receipt']['externallyAnchored']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $report['receipt']['sha256']);

        // Verify receipt from disk independently
        // Locally generated manifest without external expected digest is not externally anchored
        $diskVerified = (new Receipt())->verify($report['evidenceDirectory']);
        self::assertSame('VALID', $diskVerified['integrity']);
        self::assertFalse($diskVerified['externallyAnchored']);
        self::assertSame('VERIFIED', $diskVerified['decision']);

        // When verified against an independently provided expected digest, it is externally anchored
        $anchoredVerified = (new Receipt())->verify($report['evidenceDirectory'], $report['receipt']['sha256']);
        self::assertSame('VALID', $anchoredVerified['integrity']);
        self::assertTrue($anchoredVerified['externallyAnchored']);

        // Suggestion updated in session
        $updatedSuggestion = $session->get('issue_suggestion');
        self::assertTrue($updatedSuggestion['candidate']['verified']);
        self::assertSame('verified_allowlisted_candidate', $updatedSuggestion['candidate']['status']);

        // Notification queued in tray
        $notifications = $session->get('notifications');
        self::assertIsArray($notifications);
        self::assertNotEmpty($notifications);
        self::assertSame('success', $notifications[0]['type']);
        self::assertSame('Candidate Verified with Receipt', $notifications[0]['title']);

        // Step 4: Save local review draft
        $draftReq = Request::create('/issues/draft', 'POST', [
            'comment' => 'Verified OrderTotal bug reproduction (exit 1), negative control (exit 1), and candidate fix (exit 0) with valid cryptographic receipt.',
        ]);
        $draftReq->setLaravelSession($session);
        $controller->saveDraft($draftReq);

        $draft = $session->get('issue_draft');
        self::assertIsArray($draft);
        self::assertStringContainsString('Verified OrderTotal bug', $draft['comment']);
        self::assertStringContainsString('Local draft only', $draft['readOnlyPolicy']);

        // Step 5: Render workbench page
        $showReq = Request::create('/issues', 'GET');
        $showReq->setLaravelSession($session);
        $showRes = $controller->show($showReq);
        self::assertInstanceOf(\Inertia\Response::class, $showRes);
    }

    public function testVerifyCandidateFailsWhenNoCandidateAvailable(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $controller = new IssueWorkbenchController($this->blockingHttp);

        $req = Request::create('/issues/verify', 'POST');
        $req->setLaravelSession($session);
        $res = $controller->verifyCandidate($req);

        self::assertTrue($res->isRedirection());
        self::assertSame('No candidate proposal available to verify.', $session->get('error'));
    }

    public function testVerifyCandidateRejectsArbitraryUntrustedPath(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('issue_is_owned_fixture', false);
        $session->put('issue_suggestion', [
            'path' => 'app/ArbitraryUntrusted.php',
            'candidate' => ['source' => '<?php echo "untrusted";'],
        ]);

        $controller = new IssueWorkbenchController($this->blockingHttp);
        $req = Request::create('/issues/verify', 'POST');
        $req->setLaravelSession($session);
        $res = $controller->verifyCandidate($req);

        self::assertTrue($res->isRedirection());
        self::assertStringContainsString('restricted to the allowlisted owned review case fixture', (string) $session->get('error'));
    }

    public function testSelectRepositoryLoadsIssuesAndPullsIntoSession(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'gho_dummy_token_12345']);

        $mockHttp = new MockHttpClient([
            // 1. /repos/my-org/my-repo/issues?state=open...
            new MockResponse(json_encode([
                [
                    'number' => 10,
                    'title' => 'Cart total calculation wrong with zero items',
                    'body' => 'See src/OrderTotal.php',
                    'user' => ['login' => 'tester'],
                    'html_url' => 'https://github.com/my-org/my-repo/issues/10',
                    'created_at' => '2026-10-29T12:00:00Z',
                    'labels' => [['name' => 'bug']],
                ],
            ])),
            // 2. /repos/my-org/my-repo/pulls?state=open...
            new MockResponse(json_encode([
                [
                    'number' => 11,
                    'title' => 'Update README',
                    'state' => 'open',
                    'head' => ['ref' => 'patch-1', 'sha' => '1111222233334444555566667777888899990000'],
                    'base' => ['ref' => 'main', 'sha' => '2222333344445555666677778888999900001111'],
                    'user' => ['login' => 'contributor'],
                ],
            ])),
        ]);

        $controller = new IssueWorkbenchController($mockHttp);

        $req = Request::create('/issues/select-repository', 'POST', [
            'repository' => 'my-org/my-repo',
        ]);
        $req->setLaravelSession($session);
        $res = $controller->selectRepository($req);

        self::assertTrue($res->isRedirection());
        self::assertStringEndsWith('/issues', $res->getTargetUrl());

        self::assertSame('my-org/my-repo', $session->get('selected_github_repo'));
        $issues = $session->get('github_repo_issues');
        self::assertIsArray($issues);
        self::assertCount(1, $issues);
        self::assertSame(10, $issues[0]['number']);
        self::assertSame('Cart total calculation wrong with zero items', $issues[0]['title']);

        $pulls = $session->get('github_repo_pulls');
        self::assertIsArray($pulls);
        self::assertCount(1, $pulls);
        self::assertSame(11, $pulls[0]['number']);
    }

    public function testLoadIssueFetchesAndIntakesTask(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'gho_dummy_token_12345']);

        $sourceCode = "<?php\n\nclass OrderTotal {\n    public function calculate(): int { return 100; }\n}\n";
        $mockHttp = new MockHttpClient([
            // 1. /repos/my-org/my-repo (repo metadata for default branch)
            new MockResponse(json_encode(['default_branch' => 'main'])),
            // 2. /repos/my-org/my-repo/commits/main
            new MockResponse(json_encode(['sha' => '1111222233334444555566667777888899990000'])),
            // 3. /repos/my-org/my-repo/issues/10
            new MockResponse(json_encode([
                'number' => 10,
                'title' => 'Zero-quantity order items error',
                'body' => 'Calculation error in OrderTotal.php',
            ])),
            // 4. /repos/my-org/my-repo/contents/src/OrderTotal.php?ref=...
            new MockResponse(json_encode([
                'type' => 'file',
                'encoding' => 'base64',
                'size' => strlen($sourceCode),
                'content' => base64_encode($sourceCode),
            ])),
        ]);

        $controller = new IssueWorkbenchController($mockHttp);

        $req = Request::create('/issues/load-issue', 'POST', [
            'repository' => 'my-org/my-repo',
            'issue_number' => 10,
            'path' => 'src/OrderTotal.php',
        ]);
        $req->setLaravelSession($session);
        $res = $controller->loadIssue($req);

        self::assertTrue($res->isRedirection());
        self::assertStringEndsWith('/issues', $res->getTargetUrl());

        $context = $session->get('issue_context');
        self::assertIsArray($context);
        self::assertSame('my-org/my-repo', $context['repository']);
        self::assertSame(10, $context['issueNumber']);
        self::assertSame('src/OrderTotal.php', $context['path']);
        self::assertSame($sourceCode, $context['source']);

        $taskId = $session->get('issue_task_id');
        self::assertNotNull($taskId);
        $task = \ProofPatch\Models\WorkbenchTask::find($taskId);
        self::assertNotNull($task);
        self::assertSame('my-org/my-repo', $task->repository);
        self::assertSame(10, $task->issue_number);
        self::assertSame('queued', $task->status);

        // A disconnected session must not see or restore an authenticated repository's saved task.
        $session->forget('github_auth');
        $session->put('github_repo_issues', [['title' => 'Private issue']]);
        $session->put('github_repo_pulls', [['title' => 'Private pull request']]);
        $show = Request::create('/issues', 'GET');
        $show->setLaravelSession($session);
        $props = $controller->show($show)->toResponse($show)->getOriginalContent()->getData()['page']['props'];
        self::assertNull($props['context']);
        self::assertNotContains($taskId, array_column($props['tasks'], 'id'));
        self::assertSame([], $props['githubConnection']['repoIssues']);
        self::assertSame([], $props['githubConnection']['repoPulls']);
        $switch = Request::create('/issues/switch-task', 'POST', ['task_id' => $taskId]);
        $switch->setLaravelSession($session);
        $controller->switchTask($switch);
        self::assertNull($session->get('issue_context'));
    }

    public function testMissingSourcePathDoesNotGuessOrDiscardExistingTask(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $session->put('issue_task_id', 123);
        $session->put('issue_context', ['title' => 'Existing task']);
        $request = Request::create('/issues/load-issue', 'POST', [
            'repository' => 'owner/repo', 'issue_number' => 42, 'path' => '',
        ]);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController($this->blockingHttp))->loadIssue($request);

        self::assertStringContainsString('Choose the source file', (string) $session->get('error'));
        self::assertSame(123, $session->get('issue_task_id'));
        self::assertSame(['title' => 'Existing task'], $session->get('issue_context'));
    }

    public function testRepositoryListFailureDoesNotLookLikeAnEmptyRepository(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $session->put('selected_github_repo', 'owner/previous');
        $session->put('github_repo_issues', [['number' => 1, 'title' => 'Previous']]);
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 403]),
            new MockResponse('[]'),
        ]);
        $request = Request::create('/issues/select-repository', 'POST', ['repository' => 'owner/repo']);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController($http))->selectRepository($request);

        self::assertStringContainsString('GitHub did not return a complete', (string) $session->get('error'));
        self::assertSame('owner/previous', $session->get('selected_github_repo'));
        self::assertSame([['number' => 1, 'title' => 'Previous']], $session->get('github_repo_issues'));
    }

    public function testIssueFetchFailureKeepsTheCurrentTask(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $session->put('issue_task_id', 123);
        $session->put('issue_context', ['title' => 'Existing task']);
        $request = Request::create('/issues/load-issue', 'POST', [
            'repository' => 'owner/repo', 'issue_number' => 42, 'path' => 'src/example.php',
        ]);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController($this->blockingHttp))->loadIssue($request);

        self::assertStringContainsString('Failed to load issue', (string) $session->get('error'));
        self::assertSame(123, $session->get('issue_task_id'));
        self::assertSame(['title' => 'Existing task'], $session->get('issue_context'));
    }

    public function testSwitchingRepositoriesClearsOnlyActiveSessionContext(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $session->put('selected_github_repo', 'owner/previous');
        $session->put('issue_context', ['repository' => 'owner/previous']);
        $session->put('issue_suggestion', ['path' => 'old.php']);
        $session->put('card_preview', ['context' => ['repository' => 'owner/previous']]);
        $request = Request::create('/issues/select-repository', 'POST', ['repository' => 'owner/new']);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController(new MockHttpClient([new MockResponse('[]'), new MockResponse('[]')])))->selectRepository($request);

        self::assertSame('owner/new', $session->get('selected_github_repo'));
        self::assertNull($session->get('issue_context'));
        self::assertNull($session->get('issue_suggestion'));
        self::assertNull($session->get('card_preview'));
    }

    public function testRefreshingSameRepositoryKeepsActiveSessionContext(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $session->put('selected_github_repo', 'owner/repo');
        $session->put('issue_context', ['repository' => 'owner/repo']);
        $request = Request::create('/issues/select-repository', 'POST', ['repository' => 'owner/repo']);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController(new MockHttpClient([new MockResponse('[]'), new MockResponse('[]')])))->selectRepository($request);

        self::assertSame(['repository' => 'owner/repo'], $session->get('issue_context'));
    }

    public function testTruncatedRepositoryListIsMarkedAsPartial(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $session->put('github_auth', ['token' => 'test-token']);
        $pages = array_map(
            static fn () => new MockResponse(json_encode(array_fill(0, 100, ['number' => 1, 'title' => 'Issue']))),
            range(1, 5),
        );
        $pages[] = new MockResponse('[]');
        $request = Request::create('/issues/select-repository', 'POST', ['repository' => 'owner/repo']);
        $request->setLaravelSession($session);

        (new IssueWorkbenchController(new MockHttpClient($pages)))->selectRepository($request);

        self::assertSame('owner/repo', $session->get('selected_github_repo'));
        self::assertTrue($session->get('github_repo_list_incomplete'));
    }

    public function testSelectRepositoryAndLoadIssueRequireAuthentication(): void
    {
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $controller = new IssueWorkbenchController($this->blockingHttp);

        $reqSelect = Request::create('/issues/select-repository', 'POST', ['repository' => 'owner/repo']);
        $reqSelect->setLaravelSession($session);
        $resSelect = $controller->selectRepository($reqSelect);
        self::assertTrue($resSelect->isRedirection());
        self::assertStringContainsString('Connect your GitHub account', (string) $session->get('error'));

        $session->forget('error');
        $reqLoad = Request::create('/issues/load-issue', 'POST', ['repository' => 'owner/repo', 'issue_number' => 1]);
        $reqLoad->setLaravelSession($session);
        $resLoad = $controller->loadIssue($reqLoad);
        self::assertTrue($resLoad->isRedirection());
        self::assertStringContainsString('Connect your GitHub account', (string) $session->get('error'));
    }

    public function testShowDeliversGitHubConnectionDataToView(): void
    {
        $session = app('session')->driver('array');
        $session->put('github_auth', [
            'token' => 'gho_valid_test_token_123',
            'user' => ['login' => 'testuser', 'name' => 'Test User'],
            'repositories' => [
                ['fullName' => 'testuser/alpha', 'isPrivate' => false],
                ['fullName' => 'testuser/beta', 'isPrivate' => true],
            ],
        ]);
        $session->put('selected_github_repo', 'testuser/alpha');
        $session->put('github_repo_issues', [
            ['number' => 42, 'title' => 'Bug in OrderTotal', 'body' => 'Details', 'user' => 'tester'],
        ]);
        $session->put('github_repo_pulls', [
            ['number' => 43, 'title' => 'Fix OrderTotal', 'state' => 'open'],
        ]);

        $controller = new IssueWorkbenchController($this->blockingHttp);
        $req = Request::create('/issues', 'GET');
        $req->setLaravelSession($session);

        $response = $controller->show($req);
        self::assertInstanceOf(\Inertia\Response::class, $response);

        $reflection = new \ReflectionClass($response);
        $propsProperty = $reflection->getProperty('props');
        $propsProperty->setAccessible(true);
        $props = $propsProperty->getValue($response);

        self::assertArrayHasKey('githubConnection', $props);
        $conn = $props['githubConnection'];
        self::assertTrue($conn['isConnected']);
        self::assertSame('testuser', $conn['user']['login']);
        // The full list stays server-side; pickers page the search endpoint.
        self::assertArrayNotHasKey('repositories', $conn);
        self::assertSame(2, $conn['repositoryCount']);
        self::assertSame('testuser/alpha', $conn['selectedRepo']);
        self::assertCount(1, $conn['repoIssues']);
        self::assertSame(42, $conn['repoIssues'][0]['number']);
        self::assertCount(1, $conn['repoPulls']);
    }
}
