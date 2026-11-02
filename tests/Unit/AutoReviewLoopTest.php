<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\Http\Controllers\AutoReviewController;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\AutoReviewWorkerHeartbeat;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Support\WorkerStatusService;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Illuminate\Http\Request;

final class AutoReviewLoopTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempBudgetDir;
    private RequestBudget $budget;

    protected function setUp(): void
    {
        // Strictly uses isolated temporary SQLite database
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempBudgetDir = sys_get_temp_dir().'/proofpatch_auto_review_budget_'.bin2hex(random_bytes(6));
        $this->budget = new RequestBudget($this->tempBudgetDir, 500000, 10);
    }

    protected function tearDown(): void
    {
        $ledger = $this->tempBudgetDir.'/runtime-ledger.json';
        if (file_exists($ledger)) {
            @unlink($ledger);
        }
        if (is_dir($this->tempBudgetDir)) {
            @rmdir($this->tempBudgetDir);
        }

        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function mockGitHubClient(array $openPulls = [], string $repoFullName = 'acme/public-repo', bool $isPrivate = false): GitHubAppClient
    {
        $parts = explode('/', $repoFullName);
        $owner = $parts[0] ?? 'acme';
        $name = $parts[1] ?? 'public-repo';

        $http = new MockHttpClient([
            // 1. getCurrentUser
            new MockResponse(json_encode(['id' => 12345, 'login' => 'maintainer'])),
            // 2. listSelectedRepositories: /user/installations
            new MockResponse(json_encode([
                'installations' => [['id' => 101]],
            ])),
            // 3. listSelectedRepositories: /user/installations/101/repositories
            new MockResponse(json_encode([
                'repositories' => [
                    [
                        'id' => 100,
                        'name' => $name,
                        'full_name' => $repoFullName,
                        'owner' => ['login' => $owner],
                        'private' => $isPrivate,
                        'visibility' => $isPrivate ? 'private' : 'public',
                        'default_branch' => 'main',
                    ],
                ],
            ])),
            // 4. listOpenPullRequests
            new MockResponse(json_encode($openPulls)),
        ]);

        return new GitHubAppClient($http);
    }

    private function mockPatchPreviewer(string $headSha = '1111111111111111111111111111111111111111', string $baseSha = '2222222222222222222222222222222222222222'): PublicPatchPreview
    {
        $repoData = ['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public'];
        $pullData = ['number' => 42, 'head' => ['sha' => $headSha], 'base' => ['sha' => $baseSha], 'changed_files' => 1];
        $filesData = [
            [
                'filename' => 'src/Calculator.php',
                'additions' => 1,
                'deletions' => 1,
                'patch' => "@@ -10,1 +10,1 @@\n-  return round(\$val, 1);\n+  return round(\$val, 2);",
            ],
        ];

        $http = new MockHttpClient([
            new MockResponse(json_encode($repoData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($filesData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($repoData)),
        ]);

        return new PublicPatchPreview($http);
    }

    private function mockModelReviewResponse(string $summary = 'Advisory review of calculation rounding fix.'): MockResponse
    {
        $modelContent = json_encode([
            'summary' => $summary,
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [
                [
                    'path' => 'src/Calculator.php',
                    'status' => 'approval',
                    'lineHint' => 10,
                    'comment' => 'Precision increase to 2 decimals looks correct.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $body = json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => ['content' => $modelContent],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 350,
                'completion_tokens' => 95,
            ],
        ], JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }

    public function testOptInAndOptOutPerRepository(): void
    {
        $this->assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/public-repo'));

        // Enable
        $setting = RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        $this->assertTrue($setting->enabled);
        $this->assertTrue($setting->public_only);
        $this->assertTrue(RepositoryAutoReviewSetting::isRepoEnabled('acme/public-repo'));
        $this->assertContains('acme/public-repo', RepositoryAutoReviewSetting::enabledRepositories());

        // Disable
        $disabled = RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', false);
        $this->assertFalse($disabled->enabled);
        $this->assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/public-repo'));
        $this->assertNotContains('acme/public-repo', RepositoryAutoReviewSetting::enabledRepositories());

        // Reject invalid repository format
        $this->expectException(\InvalidArgumentException::class);
        RepositoryAutoReviewSetting::setRepoEnabled('invalid-repo-format', true);
    }

    public function testNewPullRequestDetectionAndAdvisoryReview(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation rounding error',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = $this->mockPatchPreviewer();
        $modelHttp = new MockHttpClient([$this->mockModelReviewResponse()]);
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['reposChecked']);
        $this->assertSame(1, $result['pullsInspected']);
        $this->assertSame(1, $result['reviewsCompleted']);
        $this->assertSame(0, $result['reviewsFailed']);
        $this->assertSame(0, $result['reviewsSkipped']);

        // Check review record in database
        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('completed', $record->status);
        $this->assertSame('local_only', $record->published_status); // Strictly generated local review
        $this->assertSame('low', $record->risk_level);
        $this->assertSame('approve', $record->recommendation);
        $this->assertStringContainsString('rounding fix', $record->summary);
        $this->assertCount(1, $record->file_findings);
        $this->assertSame('src/Calculator.php', $record->file_findings[0]['path']);
        $this->assertTrue($record->is_public);
    }

    public function testAutoCommentNeedsSeparateOwnerOptInAndPostsOnlyOnce(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        \ProofPatch\Models\AutomaticInlineSetting::setForOwner(12345, 'acme/public-repo', true);
        $head = str_repeat('1', 40);
        $base = str_repeat('2', 40);
        $open = [[
            'number' => 42, 'title' => 'Fix calculation', 'state' => 'open',
            'head' => ['ref' => 'fix', 'sha' => $head],
            'base' => ['ref' => 'main', 'sha' => $base, 'repo' => ['private' => false, 'visibility' => 'public']],
            'user' => ['login' => 'contributor'],
        ]];
        $modelBody = json_encode([
            'summary' => 'Please check rounding.', 'riskLevel' => 'medium', 'recommendation' => 'discuss',
            'fileFindings' => [['path' => 'src/Calculator.php', 'status' => 'concern', 'lineHint' => 10, 'comment' => 'Could this handle null input?']],
        ], JSON_THROW_ON_ERROR);
        $model = new MockHttpClient([new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $modelBody]]],
            'usage' => ['prompt_tokens' => 350, 'completion_tokens' => 95],
        ]))]);
        $posts = 0;
        $http = new MockHttpClient(function (string $method, string $url) use (&$posts, $head, $base): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            if ($method === 'POST') { ++$posts; return new MockResponse('{"id":321}', ['http_code' => 201]); }
            if ($path === '/user') return new MockResponse('{"id":12345,"login":"acme"}');
            if ($path === '/user/installations') return new MockResponse('{"installations":[{"id":101}]}');
            if ($path === '/user/installations/101/repositories') return new MockResponse(json_encode(['repositories' => [[
                'id' => 100, 'name' => 'public-repo', 'full_name' => 'acme/public-repo', 'owner' => ['login' => 'acme'],
                'private' => false, 'visibility' => 'public', 'default_branch' => 'main',
            ]]]));
            if ($path === '/repos/acme/public-repo/pulls/42') return new MockResponse(json_encode([
                'number' => 42, 'state' => 'open', 'changed_files' => 1, 'head' => ['sha' => $head],
                'base' => ['sha' => $base, 'repo' => ['private' => false, 'visibility' => 'public']],
            ]));
            if ($path === '/repos/acme/public-repo/pulls/42/files') return new MockResponse(json_encode([[
                'filename' => 'src/Calculator.php', 'additions' => 1, 'deletions' => 1,
                'patch' => "@@ -10,1 +10,1 @@\n-  return round(\$val, 1);\n+  return round(\$val, 2);",
            ]]));
            throw new \RuntimeException('Unexpected request');
        });
        $publisher = new \ProofPatch\GitHub\AutomaticInlineCommentPublisher(
            \Illuminate\Support\Facades\DB::connection()->getPdo(), $http, static fn (string $token): bool => true,
            static fn (string $token, string $repo): string => 'test-installation-token'
        );
        $service = new AutoReviewService(
            github: $this->mockGitHubClient($open), patchPreviewer: $this->mockPatchPreviewer(),
            reviewAgent: new PullRequestReview($model, $this->budget), budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789', automaticCommentPublisher: $publisher,
        );
        $result = $service->poll('mock-token-xyz');
        self::assertSame(1, $result['reviewsCompleted']);
        self::assertSame(1, $posts, json_encode($result));
        self::assertSame('posted', $result['details'][0]['automaticComment']['status']);
        self::assertSame('one_inline_comment', AutoReviewResult::findForRevision('acme/public-repo', 42, $head, $base)->published_status);
    }

    public function testWorkerRevokesCommentConsentWhenRepositoryDisappearsFromGrant(): void
    {
        \ProofPatch\Models\AutomaticInlineSetting::setForOwner(12345, 'acme/lost-repo', true);
        $service = new AutoReviewService(github: $this->mockGitHubClient(), budget: $this->budget);
        self::assertSame('ok', $service->poll('mock-token-xyz')['status']);
        self::assertFalse(\ProofPatch\Models\AutomaticInlineSetting::enabled(12345, 'acme/lost-repo'));
        // Re-adding the grant later is not itself consent to post again.
        $regranted = new AutoReviewService(github: $this->mockGitHubClient([], 'acme/lost-repo'), budget: $this->budget);
        self::assertSame('ok', $regranted->poll('mock-token-xyz')['status']);
        self::assertFalse(\ProofPatch\Models\AutomaticInlineSetting::enabled(12345, 'acme/lost-repo'));
    }

    public function testBaseShaChangeDetection(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $headSha = '1111111111111111111111111111111111111111';
        $oldBaseSha = '2222222222222222222222222222222222222222';
        $newBaseSha = '4444444444444444444444444444444444444444';

        // Pre-populate old review for base 1
        AutoReviewResult::create([
            'repository' => 'acme/public-repo',
            'pull_number' => 42,
            'head_sha' => $headSha,
            'base_sha' => $oldBaseSha,
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Review against base 1',
            'published_status' => 'local_only',
        ]);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation (rebased on new main)',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => $headSha],
                'base' => ['ref' => 'main', 'sha' => $newBaseSha, 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = $this->mockPatchPreviewer($headSha, $newBaseSha);
        $modelHttp = new MockHttpClient([$this->mockModelReviewResponse('Review against new base 2.')]);
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        // Must review the new base-SHA revision
        $this->assertSame(1, $result['reviewsCompleted']);

        $oldRecord = AutoReviewResult::findForRevision('acme/public-repo', 42, $headSha, $oldBaseSha);
        $newRecord = AutoReviewResult::findForRevision('acme/public-repo', 42, $headSha, $newBaseSha);

        $this->assertNotNull($oldRecord);
        $this->assertSame('Review against base 1', $oldRecord->summary);

        $this->assertNotNull($newRecord);
        $this->assertSame('completed', $newRecord->status);
        $this->assertStringContainsString('Review against new base 2', $newRecord->summary);
    }

    public function testConcurrentWorkersHandledWithAtomicClaim(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha = '2222222222222222222222222222222222222222';

        // Pre-claim the revision as in_progress (simulating worker 1 starting 10s ago)
        AutoReviewResult::create([
            'repository' => 'acme/public-repo',
            'pull_number' => 42,
            'head_sha' => $headSha,
            'base_sha' => $baseSha,
            'status' => 'in_progress',
            'is_public' => true,
            'published_status' => 'local_only',
        ]);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => $headSha],
                'base' => ['ref' => 'main', 'sha' => $baseSha, 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        // Worker 2 must skip and NOT overwrite the row
        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, $headSha, $baseSha);
        $this->assertSame('in_progress', $record->status);
    }

    public function testInterruptedAttemptTreatedAsUnknownWithoutSilentRetry(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha = '2222222222222222222222222222222222222222';

        // Pre-create stale in-progress row (created 10 minutes ago, process crashed while calling model)
        $staleRecord = new AutoReviewResult();
        $staleRecord->repository = 'acme/public-repo';
        $staleRecord->pull_number = 42;
        $staleRecord->head_sha = $headSha;
        $staleRecord->base_sha = $baseSha;
        $staleRecord->status = 'in_progress';
        $staleRecord->is_public = true;
        $staleRecord->published_status = 'local_only';
        $staleRecord->created_at = now()->subMinutes(10);
        $staleRecord->updated_at = now()->subMinutes(10);
        $staleRecord->save();

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => $headSha],
                'base' => ['ref' => 'main', 'sha' => $baseSha, 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);

        // Assert record transitioned to 'unknown' to prevent duplicate provider charges
        $updated = AutoReviewResult::findForRevision('acme/public-repo', 42, $headSha, $baseSha);
        $this->assertSame('unknown', $updated->status);
        $this->assertStringContainsString('interrupted', (string) $updated->error_message);
    }

    public function testOptOutHonoredImmediatelyBeforePaidCall(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha = '2222222222222222222222222222222222222222';

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient(function (string $method, string $url) use ($headSha, $baseSha): MockResponse {
            // User opts out while diff is loading before paid model call
            RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', false);
            if (str_ends_with($url, '/files?per_page=100')) {
                return new MockResponse(json_encode([
                    ['filename' => 'src/Calculator.php', 'additions' => 1, 'deletions' => 1, 'patch' => "@@ -10,3 +10,3 @@\n-  return 1;\n+  return 2;"]
                ]));
            }
            if (str_contains($url, '/pulls/42')) {
                return new MockResponse(json_encode(['number' => 42, 'head' => ['sha' => $headSha], 'base' => ['sha' => $baseSha], 'changed_files' => 1]));
            }
            return new MockResponse(json_encode(['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public']));
        }));

        // Model must NEVER be called
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: new GitHubAppClient(new MockHttpClient()),
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $outcome = $service->reviewPullRevision('acme/public-repo', 42, $headSha, $baseSha, 'Title');

        $this->assertSame('skipped_opted_out', $outcome['status']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, $headSha, $baseSha);
        $this->assertSame('skipped_opted_out', $record->status);
    }

    public function testWorkerStatusDistinguishesLongRunningRequestFromDeadProcess(): void
    {
        $statusService = new WorkerStatusService();

        // 1. Stopped / offline
        $status = $statusService->getStatus();
        $this->assertFalse($status['isLive']);
        $this->assertSame('offline', $status['status']);

        // 2. Active running worker
        $pid = getmypid() ?: 12345;
        AutoReviewWorkerHeartbeat::recordBeat('worker-1', $pid, 'localhost', 1);

        $status = $statusService->getStatus();
        $this->assertTrue($status['isLive']);
        $this->assertSame('running', $status['status']);

        // 3. Busy on long-running model/network request (>45s elapsed, but process confirmed alive)
        AutoReviewWorkerHeartbeat::where('worker_id', 'worker-1')->update([
            'last_heartbeat_at' => now()->subSeconds(60),
        ]);

        $status = $statusService->getStatus();
        $this->assertTrue($status['isLive']);
        $this->assertSame('busy', $status['status']);
        $this->assertStringContainsString('long-running', $status['message']);

        // 4. Dead process (non-existent PID)
        AutoReviewWorkerHeartbeat::recordBeat('worker-dead', 9999999, 'localhost', 1);
        $status = $statusService->getStatus();
        $this->assertFalse($status['isLive']);
        $this->assertSame('offline', $status['status']);
        $this->assertStringContainsString('terminated', $status['message']);
    }

    public function testToggleRequiresInstalledRepositoryAccess(): void
    {
        $controller = new AutoReviewController();

        // Attempt toggle on repo NOT installed in session
        $unauthorizedRequest = Request::create('/repositories/auto-review/toggle', 'POST', [
            'repository' => 'victim/private-repo',
            'enabled' => true,
        ]);
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $session->put('github_auth', [
            'token' => 'ghp_test_token_123',
            'user' => ['id' => 1, 'login' => 'maintainer'],
            'repositories' => [
                ['fullName' => 'acme/authorized-repo'],
            ],
        ]);
        $unauthorizedRequest->setLaravelSession($session);

        $response = $controller->toggle($unauthorizedRequest);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not installed or authorized', $data['error']);

        // Attempt toggle on repo THAT IS installed
        $authorizedRequest = Request::create('/repositories/auto-review/toggle', 'POST', [
            'repository' => 'acme/authorized-repo',
            'enabled' => true,
        ]);
        $authorizedRequest->setLaravelSession($session);

        $responseAuth = $controller->toggle($authorizedRequest);
        $this->assertSame(200, $responseAuth->getStatusCode());
        $dataAuth = json_decode((string) $responseAuth->getContent(), true);
        $this->assertTrue($dataAuth['success']);
        $this->assertTrue($dataAuth['enabled']);
    }

    public function testEndpointsRejectUnauthenticatedRequests(): void
    {
        $controller = new AutoReviewController();

        // 1. Unauthenticated toggle -> 401
        $req1 = Request::create('/repositories/auto-review/toggle', 'POST', ['repository' => 'acme/repo', 'enabled' => true]);
        $req1->setLaravelSession(new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10)));
        $res1 = $controller->toggle($req1);
        $this->assertSame(401, $res1->getStatusCode());

        // 2. Unauthenticated status -> 401
        $req2 = Request::create('/repositories/auto-review/status', 'GET');
        $req2->setLaravelSession(new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10)));
        $res2 = $controller->status($req2);
        $this->assertSame(401, $res2->getStatusCode());

        // 3. Unauthenticated results -> 401
        $req3 = Request::create('/repositories/auto-review/results', 'GET');
        $req3->setLaravelSession(new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10)));
        $res3 = $controller->results($req3);
        $this->assertSame(401, $res3->getStatusCode());
    }

    public function testResultsEndpointEnforcesScopedRepositoryAuthorization(): void
    {
        $controller = new AutoReviewController();
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $session->put('github_auth', [
            'token' => 'ghp_valid_token_12345',
            'user' => ['id' => 1, 'login' => 'maintainer'],
            'repositories' => [
                ['fullName' => 'acme/authorized-repo'],
            ],
        ]);

        // Accessing unauthorized repository reviews -> 403
        $unauthorizedReq = Request::create('/repositories/auto-review/results', 'GET', [
            'repository' => 'victim/secret-repo',
        ]);
        $unauthorizedReq->setLaravelSession($session);
        $unauthRes = $controller->results($unauthorizedReq);
        $this->assertSame(403, $unauthRes->getStatusCode());

        // Create reviews in database for authorized and unauthorized repos
        AutoReviewResult::create([
            'repository' => 'acme/authorized-repo',
            'pull_number' => 1,
            'head_sha' => '1111111111111111111111111111111111111111',
            'base_sha' => '2222222222222222222222222222222222222222',
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Authorized repo review',
            'published_status' => 'local_only',
        ]);
        AutoReviewResult::create([
            'repository' => 'other/foreign-repo',
            'pull_number' => 2,
            'head_sha' => '3333333333333333333333333333333333333333',
            'base_sha' => '4444444444444444444444444444444444444444',
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Foreign repo review',
            'published_status' => 'local_only',
        ]);

        // General results query without repo filter only returns authorized repo reviews
        $generalReq = Request::create('/repositories/auto-review/results', 'GET');
        $generalReq->setLaravelSession($session);
        $generalRes = $controller->results($generalReq);
        $this->assertSame(200, $generalRes->getStatusCode());
        $results = json_decode((string) $generalRes->getContent(), true)['results'];
        $this->assertCount(1, $results);
        $this->assertSame('acme/authorized-repo', $results[0]['repository']);
    }

    public function testPrivateRepositoryToggleFailsClosedAndDisablesSetting(): void
    {
        $controller = new AutoReviewController();
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $session->put('github_auth', [
            'token' => '[REDACTED]',
            'user' => ['id' => 1, 'login' => 'maintainer'],
            'repositories' => [
                ['fullName' => 'acme/private-repo', 'isPrivate' => true],
            ],
        ]);

        // A stale enabled setting for the private repo must be switched off.
        RepositoryAutoReviewSetting::setRepoEnabled('acme/private-repo', true);
        $this->assertTrue(RepositoryAutoReviewSetting::isRepoEnabled('acme/private-repo'));

        $request = Request::create('/repositories/auto-review/toggle', 'POST', [
            'repository' => 'acme/private-repo',
            'enabled' => true,
        ]);
        $request->setLaravelSession($session);

        $response = $controller->toggle($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('public repositories only', $data['error']);
        $this->assertFalse(RepositoryAutoReviewSetting::isRepoEnabled('acme/private-repo'));
    }

    public function testDashboardAutoReviewPropsAreScopedToAuthorizedRepositories(): void
    {
        $dashboard = new \ProofPatch\Http\Controllers\DashboardController();
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $session->put('github_auth', [
            'token' => '[REDACTED]',
            'user' => ['id' => 1, 'login' => 'maintainer'],
            'repositories' => [
                ['fullName' => 'acme/authorized-repo', 'isPrivate' => false],
            ],
        ]);

        RepositoryAutoReviewSetting::setRepoEnabled('acme/authorized-repo', true);
        RepositoryAutoReviewSetting::setRepoEnabled('other/foreign-repo', true);
        AutoReviewResult::create([
            'repository' => 'acme/authorized-repo',
            'pull_number' => 1,
            'head_sha' => '1111111111111111111111111111111111111111',
            'base_sha' => '2222222222222222222222222222222222222222',
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Authorized repo review',
            'published_status' => 'local_only',
        ]);
        AutoReviewResult::create([
            'repository' => 'other/foreign-repo',
            'pull_number' => 2,
            'head_sha' => '3333333333333333333333333333333333333333',
            'base_sha' => '4444444444444444444444444444444444444444',
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Foreign repo review',
            'published_status' => 'local_only',
        ]);

        foreach (['/settings' => 'settings', '/repositories' => 'repositories'] as $path => $method) {
            $request = Request::create($path, 'GET');
            $request->setLaravelSession($session);
            $props = $dashboard->{$method}($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
            $autoReview = $props['autoReview'];

            $this->assertArrayHasKey('acme/authorized-repo', $autoReview['settings']);
            $this->assertArrayNotHasKey('other/foreign-repo', $autoReview['settings']);
            $this->assertSame(1, $autoReview['stats']['totalReviews']);
            $this->assertSame(1, $autoReview['stats']['completed']);
            $this->assertCount(1, $autoReview['recentResults']);
            $this->assertSame('acme/authorized-repo', $autoReview['recentResults'][0]['repository']);
            $this->assertSame('local_only', $autoReview['recentResults'][0]['published_status']);
        }

        // Disconnected visitors see no settings or findings at all.
        $anonSession = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));
        $anonRequest = Request::create('/settings', 'GET');
        $anonRequest->setLaravelSession($anonSession);
        $anonProps = $dashboard->settings($anonRequest)->toResponse($anonRequest)->getOriginalContent()->getData()['page']['props'];
        $this->assertSame([], $anonProps['autoReview']['settings']);
        $this->assertSame([], $anonProps['autoReview']['recentResults']);
        $this->assertSame(0, $anonProps['autoReview']['stats']['totalReviews']);
    }

    public function testDurableAttemptLedgerReportsUnknownAfterLostResponseOrWorkerCrash(): void
    {
        $head = str_repeat('a', 40);
        $base = str_repeat('b', 40);
        $record = AutoReviewResult::create([
            'repository' => 'acme/public-repo', 'pull_number' => 42, 'head_sha' => $head,
            'base_sha' => $base, 'status' => 'completed', 'is_public' => true,
            'published_status' => 'local_only',
        ]);
        $key = \ProofPatch\GitHub\AutomaticInlineCommentPublisher::revisionKey('ACME/PUBLIC-REPO', 42, $head, $base);
        \Illuminate\Support\Facades\DB::table('automatic_inline_attempts')->insert([
            'revision_key' => $key, 'user_id' => 7, 'repository' => 'acme/public-repo',
            'pull_number' => 42, 'status' => 'uncertain',
        ]);
        self::assertSame('publication_unknown', $record->fresh()->published_status);
        self::assertSame('publication_unknown', $record->fresh()->toArray()['published_status']);

        // GitHub confirmation may be stored before the worker updates its review.
        \Illuminate\Support\Facades\DB::table('automatic_inline_attempts')->where('revision_key', $key)
            ->update(['status' => 'posted', 'comment_id' => 123]);
        self::assertSame('one_inline_comment', $record->fresh()->published_status);
    }

    public function testDashboardMarksAutomaticReviewSource(): void
    {
        $dashboard = new \ProofPatch\Http\Controllers\DashboardController();
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha = '2222222222222222222222222222222222222222';
        AutoReviewResult::create([
            'repository' => 'acme/public-repo',
            'pull_number' => 42,
            'head_sha' => $headSha,
            'base_sha' => $baseSha,
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Automatic review summary',
            'raw_review' => [
                'summary' => 'Automatic review summary',
                'riskLevel' => 'low',
                'recommendation' => 'approve',
            ],
            'published_status' => 'local_only',
        ]);

        $session->put('pullRequest', [
            'repository' => 'acme/public-repo',
            'number' => 42,
            'headSha' => $headSha,
            'baseSha' => $baseSha,
            'isPrivate' => false,
        ]);
        $session->put('pullRequestSnapshot', ['id' => 'snap1']);

        $request = Request::create('/repositories', 'GET');
        $request->setLaravelSession($session);
        $props = $dashboard->repositories($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];

        $this->assertNotNull($props['review']);
        $this->assertSame('automatic', $props['review']['source']);
        $this->assertSame('Automatic review summary', $props['review']['summary']);
    }

    public function testDashboardRepositoriesPageDisplaysReviewOnlyForMatchingBaseSha(): void
    {
        $dashboard = new \ProofPatch\Http\Controllers\DashboardController();
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(10));

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha1 = '2222222222222222222222222222222222222222';
        $baseSha2 = '3333333333333333333333333333333333333333';

        // Seed review for base 1
        AutoReviewResult::create([
            'repository' => 'acme/public-repo',
            'pull_number' => 42,
            'head_sha' => $headSha,
            'base_sha' => $baseSha1,
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Review for Base 1',
            'raw_review' => [
                'summary' => 'Review for Base 1',
                'riskLevel' => 'low',
                'recommendation' => 'approve',
            ],
            'published_status' => 'local_only',
        ]);

        // 1. Load PR matching base 1 -> Review IS loaded
        $pull1 = [
            'repository' => 'acme/public-repo',
            'number' => 42,
            'headSha' => $headSha,
            'baseSha' => $baseSha1,
            'isPrivate' => false,
        ];
        $session->put('pullRequest', $pull1);
        $session->put('pullRequestSnapshot', ['id' => 'snap1']);

        $request1 = Request::create('/repositories', 'GET');
        $request1->setLaravelSession($session);

        $response1 = $dashboard->repositories($request1);
        $props1 = $response1->toResponse($request1)->getOriginalContent()->getData()['page']['props'];
        $this->assertNotNull($props1['review']);
        $this->assertSame('Review for Base 1', $props1['review']['summary']);

        // 2. Base branch moved / rebased on GitHub to base 2 -> Review for base 1 must NOT be shown!
        $pull2 = [
            'repository' => 'acme/public-repo',
            'number' => 42,
            'headSha' => $headSha,
            'baseSha' => $baseSha2,
            'isPrivate' => false,
        ];
        $session->put('pullRequest', $pull2);

        $request2 = Request::create('/repositories', 'GET');
        $request2->setLaravelSession($session);

        $response2 = $dashboard->repositories($request2);
        $props2 = $response2->toResponse($request2)->getOriginalContent()->getData()['page']['props'];
        $this->assertNull($props2['review']); // Must be null because baseSha does not match!
    }

    public function testDeduplicationSameHeadShaNotReReviewed(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $headSha = '1111111111111111111111111111111111111111';
        $baseSha = '2222222222222222222222222222222222222222';

        // Pre-populate completed review
        AutoReviewResult::create([
            'repository' => 'acme/public-repo',
            'pull_number' => 42,
            'head_sha' => $headSha,
            'base_sha' => $baseSha,
            'status' => 'completed',
            'is_public' => true,
            'summary' => 'Already completed',
            'published_status' => 'local_only',
        ]);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => $headSha],
                'base' => ['ref' => 'main', 'sha' => $baseSha, 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);
        $this->assertSame('skipped_already_reviewed', $result['details'][0]['action']);
    }

    public function testAuthorizationRevocationHandledGracefully(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $githubHttp = new MockHttpClient([
            new MockResponse('{"message": "Bad credentials"}', ['http_code' => 401]),
        ]);
        $github = new GitHubAppClient($githubHttp);

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('revoked-token');

        $this->assertSame('auth_revoked', $result['status']);
        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testRevokedCliTokenClearsOnlyItsBoundOwnersConsentAndRegrantDoesNotRestoreIt(): void
    {
        \ProofPatch\GitHub\GitHubTokenStore::save('stored-revoked-token', ['id' => 12345, 'login' => 'maintainer'], []);
        \ProofPatch\Models\AutomaticInlineSetting::setForOwner(12345, 'acme/public-repo', true);
        \ProofPatch\Models\AutomaticInlineSetting::setForOwner(77, 'other/repo', true);

        try {
            $makeRevoked = fn () => new AutoReviewService(github: new GitHubAppClient(new MockHttpClient([
                new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]),
            ])), budget: $this->budget);

            // A different failed token must never revoke the stored account's consent.
            self::assertSame('auth_revoked', $makeRevoked()->poll('foreign-token')['status']);
            self::assertTrue(\ProofPatch\Models\AutomaticInlineSetting::enabled(12345, 'acme/public-repo'));
            self::assertSame('auth_revoked', $makeRevoked()->poll('stored-revoked-token')['status']);
            self::assertFalse(\ProofPatch\Models\AutomaticInlineSetting::enabled(12345, 'acme/public-repo'));
            self::assertTrue(\ProofPatch\Models\AutomaticInlineSetting::enabled(77, 'other/repo'));

            // A successful later grant is not a fresh opt-in to publish comments.
            $service = new AutoReviewService(github: $this->mockGitHubClient(), budget: $this->budget);
            self::assertSame('ok', $service->poll('new-token')['status']);
            self::assertFalse(\ProofPatch\Models\AutomaticInlineSetting::enabled(12345, 'acme/public-repo'));
        } finally {
            \ProofPatch\GitHub\GitHubTokenStore::clear();
        }
    }

    public function testDeauthorizedRepositoryIsSkipped(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/unauthorized-repo', true);

        // Installed repositories only includes acme/other-repo
        $github = $this->mockGitHubClient([], 'acme/other-repo');

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['reposChecked']);
        $this->assertSame('skipped_unauthorized', $result['details'][0]['action']);
    }

    public function testPrivateRepoOrVisibilityChangeFailsClosed(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        // PR on private repo
        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Secret changes',
                'state' => 'open',
                'head' => ['ref' => 'private-feature', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => true, 'visibility' => 'private']],
                'user' => ['login' => 'contributor1'],
            ],
        ], 'acme/public-repo', true);

        $patchPreviewer = new PublicPatchPreview(new MockHttpClient());
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);
        $this->assertSame('skipped_private', $result['details'][0]['action']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('skipped_private', $record->status);
        $this->assertFalse($record->is_public);
    }

    public function testBudgetExhaustionStopsWithoutDuplicateCharges(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        // Exhaust budget with a small limit
        $exhaustedBudget = new RequestBudget($this->tempBudgetDir, 100, 1);
        $exhaustedBudget->run(50, fn() => ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]); // Attempt 1 consumed, max attempts reached

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = $this->mockPatchPreviewer();
        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $exhaustedBudget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $exhaustedBudget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);
        $this->assertTrue($result['budgetExhausted']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('skipped_budget', $record->status);
    }

    public function testUserAssignedCapStopsAutopilotBeforeModelCall(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        \ProofPatch\Models\AutopilotBudget::saveCaps(null, 1);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = $this->mockPatchPreviewer();
        $modelHttp = new MockHttpClient(function (): never {
            $this->fail('Model must not be called once the autopilot cap is reached.');
        });
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsSkipped']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('skipped_budget', $record->status);
        $this->assertStringContainsString('Autopilot spend cap', (string) $record->error_message);
    }

    public function testStaleCommitHandling(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        // PublicPatchPreview throws because the PR head changed during fetch (TOCTOU mismatch)
        $patchPreviewer = new PublicPatchPreview(new MockHttpClient([
            new MockResponse(json_encode(['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public'])),
            // Re-check returns mismatched head sha
            new MockResponse(json_encode(['number' => 42, 'head' => ['sha' => '9999999999999999999999999999999999999999'], 'base' => ['sha' => '2222222222222222222222222222222222222222'], 'changed_files' => 1])),
        ]));

        $modelHttp = new MockHttpClient();
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result = $service->poll('mock-token-xyz');

        $this->assertSame(0, $result['reviewsCompleted']);
        $this->assertSame(1, $result['reviewsFailed']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('changed or is unavailable', (string) $record->error_message);
    }

    public function testFailuresRecordedWithoutSilentRetries(): void
    {
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $github = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $patchPreviewer = $this->mockPatchPreviewer();

        // Model returns HTTP 500 error
        $modelHttp = new MockHttpClient([
            new MockResponse('Internal server error', ['http_code' => 500]),
        ]);
        $reviewAgent = new PullRequestReview($modelHttp, $this->budget);

        $service = new AutoReviewService(
            github: $github,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        // Cycle 1: Model fails
        $result1 = $service->poll('mock-token-xyz');
        $this->assertSame(1, $result1['reviewsFailed']);

        $record = AutoReviewResult::findForRevision('acme/public-repo', 42, '1111111111111111111111111111111111111111', '2222222222222222222222222222222222222222');
        $this->assertNotNull($record);
        $this->assertSame('failed', $record->status);

        // Cycle 2: Same revision is SKIPPED, no silent retry loop
        $github2 = $this->mockGitHubClient([
            [
                'number' => 42,
                'title' => 'Fix calculation',
                'state' => 'open',
                'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
                'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'contributor1'],
            ],
        ]);

        $service2 = new AutoReviewService(
            github: $github2,
            patchPreviewer: $patchPreviewer,
            reviewAgent: $reviewAgent,
            budget: $this->budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );

        $result2 = $service2->poll('mock-token-xyz');
        $this->assertSame(0, $result2['reviewsFailed']);
        $this->assertSame(1, $result2['reviewsSkipped']);
        $this->assertSame('skipped_failed_previously', $result2['details'][0]['action']);
    }

    public function testProtectedEncryptedTokenStorage(): void
    {
        $rawToken = 'ghp_secret_access_token_1234567890';
        $user = ['id' => 999, 'login' => 'pratikbhujel'];
        $repos = [['fullName' => 'pratikbhujel/test-repo']];

        \ProofPatch\GitHub\GitHubTokenStore::save($rawToken, $user, $repos);

        // Check storage path file content
        $path = \ProofPatch\GitHub\GitHubTokenStore::getStoragePath();
        $this->assertFileExists($path);

        $fileRaw = file_get_contents($path);
        // Assert token is NEVER stored in plaintext
        $this->assertStringNotContainsString($rawToken, $fileRaw);

        // Assert file permissions are owner-only 0600
        $perms = fileperms($path) & 0777;
        $this->assertSame(0600, $perms);

        // Decrypts accurately
        $data = \ProofPatch\GitHub\GitHubTokenStore::get();
        $this->assertSame($rawToken, $data['token']);
        $this->assertSame($rawToken, \ProofPatch\GitHub\GitHubTokenStore::getToken());

        // Clear removes file
        \ProofPatch\GitHub\GitHubTokenStore::clear();
        $this->assertFileDoesNotExist($path);
    }

    public function testPaginationHandlingForRepositoriesAndPullRequests(): void
    {
        // Setup mock HTTP returning multiple pages
        $page1Repos = array_map(fn($i) => [
            'id' => 100 + $i,
            'name' => "repo-{$i}",
            'full_name' => "acme/repo-{$i}",
            'owner' => ['login' => 'acme'],
            'private' => false,
            'visibility' => 'public',
            'default_branch' => 'main',
        ], range(1, 100));

        $page2Repos = [
            [
                'id' => 201,
                'name' => 'repo-101',
                'full_name' => 'acme/repo-101',
                'owner' => ['login' => 'acme'],
                'private' => false,
                'visibility' => 'public',
                'default_branch' => 'main',
            ],
        ];

        $page1Pulls = array_map(fn($i) => [
            'number' => $i,
            'title' => "PR {$i}",
            'state' => 'open',
            'head' => ['ref' => "feature-{$i}", 'sha' => str_repeat('a', 40)],
            'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
            'user' => ['login' => "dev-{$i}"],
        ], range(1, 100));

        $page2Pulls = [
            [
                'number' => 101,
                'title' => 'PR 101',
                'state' => 'open',
                'head' => ['ref' => 'feature-101', 'sha' => str_repeat('c', 40)],
                'base' => ['ref' => 'main', 'sha' => str_repeat('d', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
                'user' => ['login' => 'dev-101'],
            ],
        ];

        $http = new MockHttpClient([
            // 1. listSelectedRepositories: installations
            new MockResponse(json_encode(['total_count' => 1, 'installations' => [['id' => 10]]])),
            // 2. installations/10/repositories: page 1 (100 items)
            new MockResponse(json_encode(['total_count' => 101, 'repositories' => $page1Repos])),
            // 3. installations/10/repositories: page 2 (1 item)
            new MockResponse(json_encode(['total_count' => 101, 'repositories' => $page2Repos])),
            // 4. listOpenPullRequests for repo: page 1 (100 items)
            new MockResponse(json_encode($page1Pulls)),
            // 5. listOpenPullRequests for repo: page 2 (1 item)
            new MockResponse(json_encode($page2Pulls)),
        ]);

        $client = new GitHubAppClient($http);

        // Verify repository pagination
        $repoStatus = [];
        $repos = $client->listSelectedRepositories('mock-token', $repoStatus);
        $this->assertCount(101, $repos);
        $this->assertFalse($repoStatus['truncated']);
        $this->assertSame('acme/repo-101', $repos[100]['fullName']);

        // Verify pull request pagination
        $prStatus = [];
        $pulls = $client->listOpenPullRequests('mock-token', 'acme/repo-1', $prStatus);
        $this->assertCount(101, $pulls);
        $this->assertFalse($prStatus['truncated']);
        $this->assertSame(101, $pulls[100]['number']);
    }

    public function testPaginationTruncationWhenExceedingMaxPages(): void
    {
        // 6 pages of 100 pull requests -> triggers truncation guard at page 5
        $fullPage = array_map(fn($i) => [
            'number' => $i,
            'title' => "PR {$i}",
            'state' => 'open',
            'head' => ['ref' => "feature-{$i}", 'sha' => str_repeat('a', 40)],
            'base' => ['ref' => 'main', 'sha' => str_repeat('b', 40), 'repo' => ['private' => false, 'visibility' => 'public']],
            'user' => ['login' => 'dev'],
        ], range(1, 100));

        $responses = array_fill(0, 6, new MockResponse(json_encode($fullPage)));
        $http = new MockHttpClient($responses);
        $client = new GitHubAppClient($http);

        $prStatus = [];
        $pulls = $client->listOpenPullRequests('mock-token', 'acme/repo-1', $prStatus);

        // Max 5 pages * 100 items = 500 items
        $this->assertCount(500, $pulls);
        $this->assertTrue($prStatus['truncated']);
    }
}
