<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Console\Commands\KernelReviewCommand;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Support\KernelCli;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KernelReviewCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const HEAD = '1111111111111111111111111111111111111111';

    private const BASE = '2222222222222222222222222222222222222222';

    private mixed $app = null;

    private ?string $tokenBackup = null;

    private string $tempBudgetDir;

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

        $this->tempBudgetDir = sys_get_temp_dir() . '/proofpatch_review_budget_' . bin2hex(random_bytes(6));
        mkdir($this->tempBudgetDir, 0700, true);
    }

    protected function tearDown(): void
    {
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

    private function pullPayload(bool $private = false): array
    {
        return [
            'number' => 42,
            'title' => 'Fix calculation rounding error',
            'state' => 'open',
            'head' => ['ref' => 'fix-rounding', 'sha' => self::HEAD],
            'base' => ['ref' => 'main', 'sha' => self::BASE, 'repo' => $private
                ? ['private' => true, 'visibility' => 'private']
                : ['private' => false, 'visibility' => 'public']],
            'changed_files' => 1,
            'user' => ['login' => 'contributor1'],
        ];
    }

    private function filesPayload(): array
    {
        return [[
            'filename' => 'src/Calculator.php', 'additions' => 1, 'deletions' => 1,
            'patch' => "@@ -10,3 +10,3 @@\n-  return round(\$val, 1);\n+  return round(\$val, 2);",
        ]];
    }

    private function github(bool $private = false): GitHubAppClient
    {
        $pull = $this->pullPayload($private);

        return new GitHubAppClient(new MockHttpClient([
            new MockResponse(json_encode($pull)),
            new MockResponse(json_encode($this->filesPayload())),
            new MockResponse(json_encode($pull)),
        ]));
    }

    private function service(bool $withReview, bool $failPatch = false): AutoReviewService
    {
        $budget = new RequestBudget($this->tempBudgetDir, 500000, 10);
        $repoData = ['full_name' => 'acme/public-repo', 'private' => false, 'visibility' => 'public'];
        $pullData = ['number' => 42, 'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE], 'changed_files' => 1];

        $previewer = new PublicPatchPreview($failPatch
            ? new MockHttpClient()
            : new MockHttpClient([
                new MockResponse(json_encode($repoData)),
                new MockResponse(json_encode($pullData)),
                new MockResponse(json_encode($this->filesPayload())),
                new MockResponse(json_encode($pullData)),
                new MockResponse(json_encode($repoData)),
            ]));

        $modelContent = json_encode([
            'summary' => 'Advisory review of calculation rounding fix.',
            'riskLevel' => 'low',
            'recommendation' => 'approve',
            'fileFindings' => [[
                'path' => 'src/Calculator.php', 'status' => 'approval', 'lineHint' => 10,
                'comment' => 'Precision increase to 2 decimals looks correct.',
            ]],
        ], JSON_THROW_ON_ERROR);
        $agent = new PullRequestReview(new MockHttpClient($withReview ? [new MockResponse(json_encode([
            'model' => PullRequestReview::MODEL,
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => $modelContent],
            ]],
            'usage' => ['prompt_tokens' => 350, 'completion_tokens' => 95],
        ]))] : []), $budget);

        return new AutoReviewService(
            github: $this->github(),
            patchPreviewer: $previewer,
            reviewAgent: $agent,
            budget: $budget,
            basetenKey: 'valid-test-baseten-key-123456789'
        );
    }

    /** @return array{exit: int, display: string} */
    private function runCommand(GitHubAppClient $github, AutoReviewService $service, array $input): array
    {
        $command = new KernelReviewCommand($github, $service);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $exit = $tester->execute($input);

        return ['exit' => $exit, 'display' => $tester->getDisplay()];
    }

    public function testParsesTargets(): void
    {
        $this->assertSame(['repo' => 'acme/repo', 'number' => 42], KernelCli::parsePrTarget('acme/repo#42'));
        $this->assertSame(
            ['repo' => 'acme/repo', 'number' => 7],
            KernelCli::parsePrTarget('https://github.com/acme/repo/pull/7')
        );
        $this->assertSame(
            ['repo' => 'acme/repo', 'number' => 7],
            KernelCli::parsePrTarget('https://github.com/acme/repo/pull/7/files?x=1')
        );
        $this->assertNull(KernelCli::parsePrTarget('nope'));
        $this->assertNull(KernelCli::parsePrTarget('acme/repo#0'));
        $this->assertNull(KernelCli::parsePrTarget('acme/repo#x'));
        $this->assertNull(KernelCli::parsePrTarget('https://evil.com/acme/repo/pull/1'));
        $this->assertNull(KernelCli::parsePrTarget('https://github.com/acme/repo/issues/1'));
    }

    public function testNoTokenFailsClosed(): void
    {
        $result = $this->runCommand($this->github(), $this->service(false), ['target' => 'acme/public-repo#42']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('github:connect', $result['display']);
    }

    public function testBadTargetFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand($this->github(), $this->service(false), ['target' => 'not-a-target']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('owner/repo#123', $result['display']);
    }

    public function testUnauthorizedRepoFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand($this->github(), $this->service(false), ['target' => 'evil/other#1']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('not in the current GitHub grant', $result['display']);
    }

    public function testDisabledRepoFailsClosed(): void
    {
        $this->connect();

        $result = $this->runCommand($this->github(), $this->service(false), ['target' => 'acme/public-repo#42']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('enable-review', $result['display']);
    }

    public function testPrivatePullRefusesWithoutReviewing(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $result = $this->runCommand($this->github(true), $this->service(false), ['target' => 'acme/public-repo#42']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('private', $result['display']);
        $this->assertNull(AutoReviewResult::findForRevision('acme/public-repo', 42, self::HEAD, self::BASE));
    }

    public function testCompletedReviewPrintsFinding(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $result = $this->runCommand($this->github(), $this->service(true), ['target' => 'acme/public-repo#42']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('acme/public-repo#42', $result['display']);
        $this->assertStringContainsString('approve', $result['display']);
        $this->assertStringContainsString('low', $result['display']);
        $this->assertStringContainsString('Advisory review of calculation rounding fix.', $result['display']);
        $this->assertStringContainsString('local only', $result['display']);

        $finding = AutoReviewResult::findForRevision('acme/public-repo', 42, self::HEAD, self::BASE);
        $this->assertNotNull($finding);
        $this->assertSame('completed', $finding->status);
    }

    public function testAcceptsFullPullUrl(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $result = $this->runCommand(
            $this->github(),
            $this->service(true),
            ['target' => 'https://github.com/acme/public-repo/pull/42']
        );

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('acme/public-repo#42', $result['display']);
    }

    public function testFailedReviewExitsFailure(): void
    {
        $this->connect();
        RepositoryAutoReviewSetting::setRepoEnabled('acme/public-repo', true);

        $result = $this->runCommand(
            $this->github(),
            $this->service(false, true),
            ['target' => 'acme/public-repo#42']
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('failed', $result['display']);
    }
}
