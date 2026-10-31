<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Console\Commands\KernelAutopilotCommand;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\AutoReviewWorkerHeartbeat;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KernelAutopilotCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private mixed $app = null;

    private ?string $tokenBackup = null;

    private string $tempBudgetDir;

    /** @var resource|null */
    private $heldLock = null;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->app = $this->bootstrapAndMigrateIsolatedDatabase();

        $path = GitHubTokenStore::getStoragePath();
        if (is_file($path)) {
            $this->tokenBackup = (string) file_get_contents($path);
        }
        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();

        $this->tempBudgetDir = sys_get_temp_dir() . '/proofpatch_autopilot_budget_' . bin2hex(random_bytes(6));
        mkdir($this->tempBudgetDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->heldLock)) {
            @flock($this->heldLock, LOCK_UN);
            @fclose($this->heldLock);
            $this->heldLock = null;
        }
        GitHubTokenStore::clear();
        if ($this->tokenBackup !== null) {
            file_put_contents(GitHubTokenStore::getStoragePath(), $this->tokenBackup, LOCK_EX);
            @chmod(GitHubTokenStore::getStoragePath(), 0600);
        }
        $this->tokenBackup = null;
        KernelCli::clearSelectedRepo();
        foreach (glob($this->tempBudgetDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempBudgetDir);
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function connect(): void
    {
        GitHubTokenStore::save('mock-token-xyz', ['id' => 1, 'login' => 'octocat'], [
            ['fullName' => 'acme/public-repo'],
        ]);
    }

    private function budget(): RequestBudget
    {
        return new RequestBudget($this->tempBudgetDir, 500000, 10);
    }

    private function githubClient(array $openPulls = []): GitHubAppClient
    {
        return new GitHubAppClient(new MockHttpClient([
            new MockResponse(json_encode(['id' => 12345, 'login' => 'maintainer'])),
            new MockResponse(json_encode(['installations' => [['id' => 101]]])),
            new MockResponse(json_encode(['repositories' => [[
                'id' => 100, 'name' => 'public-repo', 'full_name' => 'acme/public-repo',
                'owner' => ['login' => 'acme'], 'private' => false, 'visibility' => 'public',
                'default_branch' => 'main',
            ]]])),
            new MockResponse(json_encode($openPulls)),
        ]));
    }

    private function patchPreviewer(): PublicPatchPreview
    {
        $head = '1111111111111111111111111111111111111111';
        $base = '2222222222222222222222222222222222222222';
        $repoData = ['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public'];
        $pullData = ['number' => 42, 'head' => ['sha' => $head], 'base' => ['sha' => $base], 'changed_files' => 1];
        $filesData = [[
            'filename' => 'src/Calculator.php', 'additions' => 1, 'deletions' => 1,
            'patch' => "@@ -10,3 +10,3 @@\n-  return round(\$val, 1);\n+  return round(\$val, 2);",
        ]];

        return new PublicPatchPreview(new MockHttpClient([
            new MockResponse(json_encode($repoData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($filesData)),
            new MockResponse(json_encode($pullData)),
            new MockResponse(json_encode($repoData)),
        ]));
    }

    private function modelResponse(): MockResponse
    {
        $modelContent = json_encode([
            'summary' => 'Advisory review of calculation rounding fix.',
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [[
                'path' => 'src/Calculator.php', 'status' => 'approval', 'lineHint' => 10,
                'comment' => 'Precision increase to 2 decimals looks correct.',
            ]],
        ], JSON_THROW_ON_ERROR);

        return new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => $modelContent],
            ]],
            'usage' => ['prompt_tokens' => 350, 'completion_tokens' => 95],
        ]));
    }

    private function service(GitHubAppClient $github, bool $withReview = false): AutoReviewService
    {
        $budget = $this->budget();

        return new AutoReviewService(
            github: $github,
            patchPreviewer: $withReview ? $this->patchPreviewer() : new PublicPatchPreview(new MockHttpClient()),
            reviewAgent: $withReview ? new PullRequestReview(new MockHttpClient([$this->modelResponse()]), $budget) : new PullRequestReview(new MockHttpClient(), $budget),
            budget: $budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );
    }

    /** @return array{exit: int, display: string} */
    private function runCommand(AutoReviewService $service, array $input = []): array
    {
        $command = new KernelAutopilotCommand($service);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input);

        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    public function testNoTokenFailsClosed(): void
    {
        $result = $this->runCommand($this->service($this->githubClient()), ['--repo' => 'acme/public-repo']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('github:connect', $result['display']);
    }

    public function testNoRepoFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand($this->service($this->githubClient()), []);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('kernel:select-repo', $result['display']);
    }

    public function testUnauthorizedRepoFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand($this->service($this->githubClient()), ['--repo' => 'evil/other']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('not in the current GitHub grant', $result['display']);
    }

    public function testDisabledRepoFailsClosed(): void
    {
        $this->connect();
        KernelCli::setSelectedRepo('acme/public-repo');

        $result = $this->runCommand($this->service($this->githubClient()), ['--max-cycles' => '1']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('enable-review', $result['display']);
    }

    public function testEmptyCycleSucceedsAndBeats(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $result = $this->runCommand(
            $this->service($this->githubClient([])),
            ['--repo' => 'acme/public-repo', '--max-cycles' => '1', '--interval' => '0']
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('cycle 1', $result['display']);
        $this->assertStringContainsString('0 completed', $result['display']);

        $beat = AutoReviewWorkerHeartbeat::orderByDesc('id')->first();
        $this->assertNotNull($beat);
        $this->assertSame('stopped', $beat->status);
    }

    public function testCompletedReviewCycleRecordsFinding(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        $pulls = [[
            'number' => 42,
            'title' => 'Fix calculation rounding error',
            'state' => 'open',
            'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
            'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
            'user' => ['login' => 'contributor1'],
        ]];

        $result = $this->runCommand(
            $this->service($this->githubClient($pulls), true),
            ['--repo' => 'acme/public-repo', '--max-cycles' => '1', '--interval' => '0']
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('1 completed', $result['display']);

        $finding = AutoReviewResult::findForRevision(
            'acme/public-repo', 42,
            '1111111111111111111111111111111111111111',
            '2222222222222222222222222222222222222222'
        );
        $this->assertNotNull($finding);
        $this->assertSame('completed', $finding->status);
        $this->assertSame('local_only', $finding->published_status);
    }

    public function testSecondCycleSkipsAlreadyReviewed(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        $pull = [
            'number' => 42,
            'title' => 'Fix calculation rounding error',
            'state' => 'open',
            'head' => ['ref' => 'fix-rounding', 'sha' => '1111111111111111111111111111111111111111'],
            'base' => ['ref' => 'main', 'sha' => '2222222222222222222222222222222222222222', 'repo' => ['private' => false, 'visibility' => 'public']],
            'user' => ['login' => 'contributor1'],
        ];
        // Two poll cycles need two full GitHub response sequences.
        $github = new GitHubAppClient(new MockHttpClient([
            new MockResponse(json_encode(['id' => 12345, 'login' => 'maintainer'])),
            new MockResponse(json_encode(['installations' => [['id' => 101]]])),
            new MockResponse(json_encode(['repositories' => [[
                'id' => 100, 'name' => 'public-repo', 'full_name' => 'acme/public-repo',
                'owner' => ['login' => 'acme'], 'private' => false, 'visibility' => 'public',
                'default_branch' => 'main',
            ]]])),
            new MockResponse(json_encode([$pull])),
            new MockResponse(json_encode(['id' => 12345, 'login' => 'maintainer'])),
            new MockResponse(json_encode(['installations' => [['id' => 101]]])),
            new MockResponse(json_encode(['repositories' => [[
                'id' => 100, 'name' => 'public-repo', 'full_name' => 'acme/public-repo',
                'owner' => ['login' => 'acme'], 'private' => false, 'visibility' => 'public',
                'default_branch' => 'main',
            ]]])),
            new MockResponse(json_encode([$pull])),
        ]));

        $result = $this->runCommand(
            $this->service($github, true),
            ['--repo' => 'acme/public-repo', '--max-cycles' => '2', '--interval' => '0']
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('cycle 2', $result['display']);
        $this->assertStringContainsString('1 completed', $result['display']);
        $this->assertStringContainsString('1 skipped', $result['display']);
    }

    public function testHeldLockSkipsRun(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $lockDir = storage_path('framework');
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0700, true);
        }
        $this->heldLock = fopen($lockDir . '/auto_review_worker.lock', 'c+');
        $this->assertNotFalse($this->heldLock);
        flock($this->heldLock, LOCK_EX | LOCK_NB);

        $result = $this->runCommand(
            $this->service($this->githubClient([])),
            ['--repo' => 'acme/public-repo', '--max-cycles' => '1', '--interval' => '0']
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('already running', $result['display']);
    }

    public function testRevokedAuthFailsFast(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);
        $github = new GitHubAppClient(new MockHttpClient([
            new MockResponse('{}', ['http_code' => 401]),
        ]));

        $result = $this->runCommand(
            $this->service($github),
            ['--repo' => 'acme/public-repo', '--max-cycles' => '1', '--interval' => '0']
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('revoked', $result['display']);
    }
}
