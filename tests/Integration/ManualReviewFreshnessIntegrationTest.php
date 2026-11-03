<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Integration;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\GitHub\PublicBaseReviewContext;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ManualReviewFreshnessIntegrationTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $dir;
    private array $oldEnv = [];
    private int $providerCalls = 0;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        $this->dir = sys_get_temp_dir().'/proofpatch-manual-freshness-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        foreach (['PROOFPATCH_APPROVALS_DIR', 'PROOFPATCH_PROVIDER_CONFIG_FILE', 'BASETEN_API_KEY'] as $key) {
            $value = getenv($key);
            $this->oldEnv[$key] = $value === false ? null : $value;
        }
        putenv('PROOFPATCH_APPROVALS_DIR='.$this->dir.'/approvals');
        putenv('PROOFPATCH_PROVIDER_CONFIG_FILE='.$this->dir.'/provider.enc');
        putenv('BASETEN_API_KEY=test-baseten-key-123456789');
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->oldEnv as $key => $value) putenv($value === null ? $key : $key.'='.$value);
        $_SESSION = [];
        $this->remove($this->dir);
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testVisibilityChangeAfterCaptureStopsBeforeModel(): void
    {
        $this->exercise(['private' => true, 'visibility' => 'private'], $this->currentPull(), $this->files(), 0);
    }

    public function testStartingAnotherReviewSetsAsideAutomaticResultOnlyForThatRevision(): void
    {
        $oldDriver = config('session.driver');
        config(['session.driver' => 'array']);
        try {
            $head = str_repeat('a', 40);
            $base = str_repeat('b', 40);
            $pull = ['repository' => 'example/project', 'number' => 1, 'title' => 'Fix issue',
                'headSha' => $head, 'baseSha' => $base, 'isPrivate' => false, 'changedFiles' => 1, 'files' => []];
            foreach ([$head, str_repeat('c', 40)] as $revision) {
                \ProofPatch\Models\AutoReviewResult::create([
                    'repository' => 'example/project', 'pull_number' => 1, 'head_sha' => $revision,
                    'base_sha' => $base, 'status' => 'completed', 'is_public' => true,
                    'model' => 'test', 'raw_review' => ['summary' => 'Saved automatic review', 'fileFindings' => []],
                ]);
            }
            $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(60));
            $session->put('pullRequest', $pull);
            $controller = new DashboardController();
            $get = static function () use ($session): Request {
                $request = Request::create('/repositories', 'GET');
                $request->setLaravelSession($session);
                return $request;
            };
            $page = static fn (Request $request): array => $controller->repositories($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];

            self::assertSame('automatic', $page($get())['review']['source']);
            $clear = Request::create('/repositories/review/clear', 'POST');
            $clear->setLaravelSession($session);
            self::assertStringContainsString('tab=review', $controller->clearReview($clear)->getTargetUrl());
            self::assertNull($page($get())['review']);
            self::assertSame(2, \ProofPatch\Models\AutoReviewResult::count(), 'Saved reviews are not deleted.');

            $pull['headSha'] = str_repeat('c', 40);
            $session->put('pullRequest', $pull);
            self::assertSame('automatic', $page($get())['review']['source'], 'A different revision still shows its saved review.');
        } finally {
            config(['session.driver' => $oldDriver]);
        }
    }

    public function testManualGithubTokenMustMatchVerifiedServerSessionIdentity(): void
    {
        $previousDriver = config('session.driver');
        config(['session.driver' => 'array']);
        try {
            $request = Request::create('/repositories/review', 'POST');
            $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(60));
            $request->setLaravelSession($session);
            $token = 'ghu_test_token_123456789';
            $session->put('github_auth', ['token' => $token, 'user' => ['id' => 42], 'verified_token_sha256' => hash('sha256', $token)]);
            $request->attributes->set('proofpatch_github_access', ['userId' => 42]);
            $method = new \ReflectionMethod(DashboardController::class, 'verifiedManualGitHubToken');
            $controller = new DashboardController();
            self::assertSame($token, $method->invoke($controller, $request));

            foreach ([
                ['userId' => 43, 'sha' => hash('sha256', $token)],
                ['userId' => 42, 'sha' => hash('sha256', 'a different token')],
                ['userId' => null, 'sha' => hash('sha256', $token)],
            ] as $case) {
                $session->put('github_auth', ['token' => $token, 'user' => ['id' => 42], 'verified_token_sha256' => $case['sha']]);
                $request->attributes->set('proofpatch_github_access', ['userId' => $case['userId']]);
                try { $method->invoke($controller, $request); self::fail('Unverified token was accepted.'); }
                catch (\RuntimeException $e) { self::assertStringNotContainsString($token, $e->getMessage()); }
            }
        } finally {
            config(['session.driver' => $previousDriver]);
        }
    }

    public function testPublicPreviewGetUsesVerifiedSessionTokenAndDoesNotExposeIt(): void
    {
        $oldDriver = config('session.driver');
        config(['session.driver' => 'array']);
        try {
            $token = 'ghu_test_token_123456789';
            $request = Request::create('/repositories?preview=1', 'GET');
            $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(60));
            $request->setLaravelSession($session);
            $session->put('github_auth', ['token' => $token, 'user' => ['id' => 42, 'login' => 'maintainer'], 'repositories' => [], 'verified_token_sha256' => hash('sha256', $token)]);
            $session->put('pullRequest', ['repository' => 'example/project', 'number' => 1, 'title' => 'Fix issue', 'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40), 'isPrivate' => false, 'changedFiles' => 1, 'files' => []]);
            $session->put('pullRequestSnapshot', ['id' => 'uncached-test-'.bin2hex(random_bytes(8))]);
            $request->attributes->set('proofpatch_github_access', ['userId' => 42, 'repositories' => []]);
            $responses = [$this->publicRepo(), $this->currentPull(), $this->files(), $this->currentPull(), $this->publicRepo()];
            $calls = 0;
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls, $token) {
                ++$calls;
                self::assertSame('GET', $method);
                self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
                self::assertSame(0, $options['max_redirects']);
                self::assertContains('Authorization: Bearer '.$token, $options['headers']);
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            $page = (new DashboardController(manualGitHubHttp: $http))->repositories($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
            self::assertSame(5, $calls);
            self::assertSame('src/a.php', $page['patchPreview']['files'][0]['name']);
            self::assertStringNotContainsString($token, json_encode($page, JSON_THROW_ON_ERROR));
        } finally {
            config(['session.driver' => $oldDriver]);
        }
    }

    public function testPublicPreviewGetDoesNotRetryWithoutAuthOnDeniedSession(): void
    {
        $oldDriver = config('session.driver');
        config(['session.driver' => 'array']);
        try {
            $token = 'ghu_test_token_123456789';
            $request = Request::create('/repositories?preview=1', 'GET');
            $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(60));
            $request->setLaravelSession($session);
            $session->put('github_auth', ['token' => $token, 'user' => ['id' => 42], 'repositories' => [], 'verified_token_sha256' => hash('sha256', $token)]);
            $session->put('pullRequest', ['repository' => 'example/project', 'number' => 1, 'title' => 'Fix issue', 'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40), 'isPrivate' => false, 'changedFiles' => 1, 'files' => []]);
            $session->put('pullRequestSnapshot', ['id' => 'uncached-test-'.bin2hex(random_bytes(8))]);
            $request->attributes->set('proofpatch_github_access', ['userId' => 42, 'repositories' => []]);
            $calls = 0;
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls, $token) {
                ++$calls;
                self::assertContains('Authorization: Bearer '.$token, $options['headers']);
                return new MockResponse('denied', ['http_code' => 403]);
            });
            $page = (new DashboardController(manualGitHubHttp: $http))->repositories($request)->toResponse($request)->getOriginalContent()->getData()['page']['props'];
            self::assertSame(1, $calls);
            self::assertNull($page['patchPreview']);
            self::assertNotNull($page['patchPreviewError']);
            self::assertStringNotContainsString($token, json_encode($page, JSON_THROW_ON_ERROR));
        } finally {
            config(['session.driver' => $oldDriver]);
        }
    }

    public function testPublicCaptureUsesVerifiedSessionTokenWithoutExposingIt(): void
    {
        $oldDriver = config('session.driver');
        $oldSnapshotDir = getenv('PROOFPATCH_SNAPSHOT_DIR');
        config(['session.driver' => 'array']);
        putenv('PROOFPATCH_SNAPSHOT_DIR='.$this->dir.'/capture');
        try {
            $token = 'ghu_test_token_123456789';
            $request = Request::create('/repositories', 'POST', ['url' => 'example/project#1']);
            $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(60));
            $request->setLaravelSession($session);
            $session->put('github_auth', ['token' => $token, 'user' => ['id' => 42, 'login' => 'maintainer'], 'repositories' => [], 'verified_token_sha256' => hash('sha256', $token)]);
            $request->attributes->set('proofpatch_github_access', ['userId' => 42, 'repositories' => []]);
            $repo = $this->publicRepo();
            $pull = $this->currentPull() + ['title' => 'Fix issue', 'state' => 'open'];
            $pull['head']['ref'] = 'fix'; $pull['base']['ref'] = 'main'; $pull['base']['repo'] = $repo;
            $responses = [$repo, $pull, [['filename' => 'src/a.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 1]], ['total_count' => 0, 'check_runs' => []], $pull, $repo];
            $calls = 0;
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls, $token) {
                ++$calls;
                self::assertSame('GET', $method);
                self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
                self::assertSame(0, $options['max_redirects']);
                self::assertContains('Authorization: Bearer '.$token, $options['headers']);
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            $controller = new DashboardController(manualGitHubHttp: $http);
            self::assertSame(302, $controller->repositories($request)->getStatusCode());
            self::assertSame(6, $calls);
            self::assertSame('example/project', $session->get('pullRequest')['repository']);
            $get = Request::create('/repositories', 'GET');
            $get->setLaravelSession($session);
            $get->attributes->set('proofpatch_github_access', ['userId' => 42, 'repositories' => []]);
            $page = $controller->repositories($get)->toResponse($get)->getOriginalContent()->getData()['page']['props'];
            self::assertStringNotContainsString($token, json_encode($page, JSON_THROW_ON_ERROR));
        } finally {
            config(['session.driver' => $oldDriver]);
            putenv($oldSnapshotDir === false ? 'PROOFPATCH_SNAPSHOT_DIR' : 'PROOFPATCH_SNAPSHOT_DIR='.$oldSnapshotDir);
        }
    }

    public function testVisibilityChangeDuringFetchStopsBeforeModel(): void
    {
        $this->exercise($this->publicRepo(), $this->currentPull(), $this->files(), 0,
            ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private']);
    }

    public function testChangedBaseWithSameHeadStopsBeforeModel(): void
    {
        $pull = $this->currentPull();
        $pull['base']['sha'] = str_repeat('c', 40);
        $this->exercise($this->publicRepo(), $pull, $this->files(), 0);
    }

    public function testChangedBaseDuringFetchStopsBeforeModel(): void
    {
        $changed = $this->currentPull();
        $changed['base']['sha'] = str_repeat('c', 40);
        $this->exercise($this->publicRepo(), $this->currentPull(), $this->files(), 0, null, $changed);
    }

    public function testChangedPatchRequiresNewApprovalAndStopsBeforeModel(): void
    {
        $this->exercise($this->publicRepo(), $this->currentPull(), $this->files('different'), 0);
        self::assertStringContainsString('preview changed', (string) session('review_error'));
    }

    public function testUnchangedPublicRevisionMakesOneModelCallAndCannotReplay(): void
    {
        [$controller, $request] = $this->exercise($this->publicRepo(), $this->currentPull(), $this->files(), 1);
        self::assertSame('manual', $_SESSION['pr_review']['source']);
        $controller->reviewPullRequest($request);
        self::assertSame(1, $this->providerCalls);
        self::assertStringContainsString('already been consumed', (string) session('review_error'));
    }

    public function testOptionalBaseContextIsApprovedAndRefetchedBeforeOneModelCall(): void
    {
        $this->exerciseBaseContext('happy', 1);
    }

    public function testSelectedFileReviewRechecksRevisionScopeAndNeverApprovesWholePr(): void
    {
        foreach (['happy', 'base', 'selection', 'count'] as $mode) {
            $this->providerCalls = 0;
            $this->remove($this->dir.'/approvals');
            $this->remove($this->dir.'/budget');
            $_SESSION = [];
            $head = str_repeat('a', 40); $base = str_repeat('b', 40);
            $pull = ['repository' => 'example/project', 'number' => 1, 'title' => 'Large PR',
                'headSha' => $head, 'baseSha' => $base, 'isPrivate' => false, 'changedFiles' => 2];
            $patch = ['repository' => 'example/project', 'number' => 1, 'headSha' => $head, 'baseSha' => $base,
                'isPrivate' => false, 'changedFiles' => 2, 'filesTruncated' => true,
                'files' => [['name' => 'src/a.php', 'status' => 'available', 'patch' => "@@ -1 +1 @@\n-before\n+after"]]];
            $_SESSION['pullRequest'] = $pull;
            $_SESSION['pullRequestSnapshot'] = ['id' => 'test'];
            $_SESSION['patch_preview'] = $patch;
            $_SESSION['review_selected_file'] = ['repository' => 'example/project', 'number' => 1,
                'headSha' => $head, 'baseSha' => $base, 'path' => $mode === 'selection' ? 'src/other.php' : 'src/a.php'];
            $model = new MockHttpClient(function () {
                ++$this->providerCalls;
                return new MockResponse(json_encode(['model' => PullRequestReview::MODEL,
                    'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                        'summary' => 'One file.', 'riskLevel' => 'low', 'recommendation' => 'approve', 'fileFindings' => [],
                    ], JSON_THROW_ON_ERROR)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]], JSON_THROW_ON_ERROR));
            });
            $agent = new PullRequestReview($model, new RequestBudget($this->dir.'/budget', 150000, 4));
            $digest = $agent->preview($pull, $patch, null, 'src/a.php')['digest'];
            $approval = (new SingleUseReviewApproval($this->dir.'/approvals'))->issue('example/project', 1, $head, $digest);
            $current = $this->currentPull();
            $current['changed_files'] = $mode === 'count' ? 3 : 2;
            if ($mode === 'base') $current['base']['sha'] = str_repeat('c', 40);
            $responses = [$this->publicRepo(), $current, $this->files(), $current, $this->publicRepo()];
            $github = new MockHttpClient(static function () use (&$responses) {
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            $controller = new DashboardController(manualPatchPreviewer: new PublicPatchPreview($github), manualReviewAgent: $agent);
            $request = Request::create('/repositories/review', 'POST', ['confirm' => '1', 'digest' => $digest, 'approval_token' => $approval['token']]);
            $controller->reviewPullRequest($request);
            self::assertSame($mode === 'happy' ? 1 : 0, $this->providerCalls, $mode);
            if ($mode === 'happy') {
                self::assertSame('discuss', $_SESSION['pr_review']['recommendation']);
                self::assertSame(2, $_SESSION['pr_review']['coverage']['totalFiles']);
                self::assertSame('src/a.php', $_SESSION['pr_review']['coverage']['path']);
            } else {
                self::assertArrayNotHasKey('pr_review', $_SESSION);
            }
        }
    }

    public function testSelectionAcceptsOnlyACompleteCapturedPublicFile(): void
    {
        foreach (['valid', 'missing', 'private'] as $mode) {
            $_SESSION = [];
            $head = str_repeat('a', 40); $base = str_repeat('b', 40);
            $_SESSION['pullRequest'] = ['repository' => 'example/project', 'number' => 1, 'title' => 'Large PR',
                'headSha' => $head, 'baseSha' => $base, 'isPrivate' => $mode === 'private', 'changedFiles' => 2];
            $current = $this->currentPull(); $current['changed_files'] = 2;
            $files = $this->files();
            if ($mode === 'missing') unset($files[0]['patch']);
            $responses = [$this->publicRepo(), $current, $files, $current, $this->publicRepo()];
            $calls = 0;
            $github = new MockHttpClient(static function () use (&$responses, &$calls) {
                ++$calls;
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            $agent = new PullRequestReview(new MockHttpClient(), new RequestBudget($this->dir.'/budget', 150000, 4));
            $controller = new DashboardController(manualPatchPreviewer: new PublicPatchPreview($github), manualReviewAgent: $agent);
            $controller->selectReviewFile(Request::create('/repositories/review/file', 'POST', ['path' => 'src/a.php']));
            if ($mode === 'valid') self::assertSame('src/a.php', $_SESSION['review_selected_file']['path']);
            else self::assertArrayNotHasKey('review_selected_file', $_SESSION);
            self::assertSame($mode === 'private' ? 0 : 5, $calls);
        }
    }

    public function testOptionalBaseContextStopsOnPrivateRepoOrChangedBase(): void
    {
        $this->exerciseBaseContext('private', 0);
        $this->exerciseBaseContext('base', 0);
    }

    public function testOptionalBaseContextRejectsTamperedBlobBeforeModel(): void
    {
        $this->exerciseBaseContext('tamper', 0);
    }

    private function exerciseBaseContext(string $mode, int $expectedCalls): void
    {
        $this->providerCalls = 0;
        $this->remove($this->dir.'/approvals');
        $this->remove($this->dir.'/budget');
        $_SESSION = [];
        $head = str_repeat('a', 40); $base = str_repeat('b', 40);
        $root = str_repeat('c', 40); $dir = str_repeat('d', 40);
        $source = "before\n"; $blob = sha1('blob '.strlen($source)."\0".$source);
        $pull = ['repository' => 'example/project', 'number' => 1, 'title' => 'Fix issue',
            'headSha' => $head, 'baseSha' => $base, 'isPrivate' => false, 'changedFiles' => 1];
        $_SESSION['pullRequest'] = $pull; $_SESSION['pullRequestSnapshot'] = ['id' => 'test'];
        $afterPreparation = false;
        $http = new MockHttpClient(function (string $method, string $url) use (&$afterPreparation, $mode, $head, $base, $root, $dir, $source, $blob) {
            self::assertSame('GET', $method);
            if (str_ends_with($url, '/git/commits/'.$base)) $data = ['sha' => $base, 'tree' => ['sha' => $root]];
            elseif (str_ends_with($url, '/git/trees/'.$root)) $data = ['sha' => $root, 'truncated' => false, 'tree' => [['path' => 'src', 'type' => 'tree', 'mode' => '040000', 'sha' => $dir]]];
            elseif (str_ends_with($url, '/git/trees/'.$dir)) $data = ['sha' => $dir, 'truncated' => false, 'tree' => [['path' => 'a.php', 'type' => 'blob', 'mode' => '100644', 'sha' => $blob]]];
            elseif (str_ends_with($url, '/git/blobs/'.$blob)) $data = ['sha' => $blob, 'size' => strlen($source), 'encoding' => 'base64', 'content' => base64_encode($afterPreparation && $mode === 'tamper' ? "xxxxxx\n" : $source)];
            elseif (str_contains($url, '/pulls/1/files')) $data = $this->files();
            elseif (str_ends_with($url, '/pulls/1')) $data = ['number' => 1, 'head' => ['sha' => $head], 'base' => ['sha' => $afterPreparation && $mode === 'base' ? str_repeat('f', 40) : $base], 'changed_files' => 1];
            else $data = ['full_name' => 'example/project', 'private' => $afterPreparation && $mode === 'private', 'visibility' => $afterPreparation && $mode === 'private' ? 'private' : 'public'];
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
        });
        $model = new MockHttpClient(function () {
            ++$this->providerCalls;
            return new MockResponse(json_encode(['model' => PullRequestReview::MODEL,
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                    'summary' => 'A change.', 'riskLevel' => 'low', 'recommendation' => 'discuss', 'fileFindings' => [],
                ], JSON_THROW_ON_ERROR)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]], JSON_THROW_ON_ERROR));
        });
        $agent = new PullRequestReview($model, new RequestBudget($this->dir.'/budget', 150000, 4));
        $controller = new DashboardController(manualPatchPreviewer: new PublicPatchPreview($http), manualReviewAgent: $agent, manualBaseContext: new PublicBaseReviewContext($http));
        $controller->prepareReviewBaseContext(Request::create('/repositories/review/base-context', 'POST', ['path' => 'src/a.php']));
        self::assertSame('src/a.php', $_SESSION['review_base_context']['path']);
        $requestPreview = $agent->preview($pull, $_SESSION['patch_preview'], $_SESSION['review_base_context']);
        self::assertStringContainsString('before', $requestPreview['body']['messages'][1]['content']);
        $digest = $requestPreview['digest'];
        $approval = (new SingleUseReviewApproval($this->dir.'/approvals'))->issue('example/project', 1, $head, $digest);
        $afterPreparation = true;
        $controller->reviewPullRequest(Request::create('/repositories/review', 'POST', ['confirm' => '1', 'digest' => $digest, 'approval_token' => $approval['token']]));
        self::assertSame($expectedCalls, $this->providerCalls);
        if ($expectedCalls === 0) self::assertArrayNotHasKey('pr_review', $_SESSION);
        else self::assertSame('manual', $_SESSION['pr_review']['source']);
    }

    /** @return array{DashboardController,Request} */
    private function exercise(array $repository, array $currentPull, array $files, int $expectedCalls, ?array $lastRepository = null, ?array $lastPull = null): array
    {
        $head = str_repeat('a', 40);
        $base = str_repeat('b', 40);
        $pull = ['repository' => 'example/project', 'number' => 1, 'title' => 'Fix issue',
            'headSha' => $head, 'baseSha' => $base, 'isPrivate' => false, 'changedFiles' => 1];
        $preview = ['repository' => 'example/project', 'number' => 1, 'headSha' => $head,
            'baseSha' => $base, 'isPrivate' => false, 'changedFiles' => 1, 'filesTruncated' => false,
            'files' => [['name' => 'src/a.php', 'status' => 'available', 'patch' => "@@ -1 +1 @@\n-before\n+after"]]];
        $_SESSION['pullRequest'] = $pull;
        $_SESSION['pullRequestSnapshot'] = ['id' => 'test'];
        $_SESSION['patch_preview'] = $preview;

        $model = new MockHttpClient(function () {
            ++$this->providerCalls;
            return new MockResponse(json_encode(['model' => PullRequestReview::MODEL,
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                    'summary' => 'A change.', 'riskLevel' => 'low', 'recommendation' => 'discuss', 'fileFindings' => [],
                ], JSON_THROW_ON_ERROR)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]], JSON_THROW_ON_ERROR));
        });
        $agent = new PullRequestReview($model, new RequestBudget($this->dir.'/budget', 150000, 4));
        $digest = $agent->preview($pull, $preview)['digest'];
        $approval = (new SingleUseReviewApproval($this->dir.'/approvals'))->issue('example/project', 1, $head, $digest);
        $responses = [$repository, $currentPull, $files, $lastPull ?? $currentPull, $lastRepository ?? $repository];
        $github = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses) {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertStringNotContainsString('Authorization:', implode(' ', $options['headers'] ?? []));
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        $controller = new DashboardController(manualPatchPreviewer: new PublicPatchPreview($github), manualReviewAgent: $agent);
        $request = Request::create('/repositories/review', 'POST', ['confirm' => '1', 'digest' => $digest, 'approval_token' => $approval['token']]);
        $controller->reviewPullRequest($request);
        self::assertSame($expectedCalls, $this->providerCalls);
        if ($expectedCalls === 0) self::assertArrayNotHasKey('pr_review', $_SESSION);
        return [$controller, $request];
    }

    private function currentPull(): array
    {
        return ['number' => 1, 'head' => ['sha' => str_repeat('a', 40)],
            'base' => ['sha' => str_repeat('b', 40)], 'changed_files' => 1];
    }

    private function publicRepo(): array
    {
        return ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
    }

    private function files(string $added = 'after'): array
    {
        return [['filename' => 'src/a.php', 'additions' => 1, 'deletions' => 1,
            'patch' => "@@ -1 +1 @@\n-before\n+".$added]];
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $this->remove($path.'/'.$entry);
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) unlink($path);
    }
}
