<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\SingleFileCandidate;
use ProofPatch\Application\Workbench\PublishDraftPrInput;
use ProofPatch\Application\Workbench\PublishDraftPrUseCase;
use ProofPatch\Evidence\Receipt;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The publish use case runs without HTTP or session access: one GitHub write
 * sequence, read-only reconciliation when ambiguous, never a retry.
 */
final class PublishDraftPrUseCaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/publish-usecase-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array{0: string, 1: string} base source, trusted repair */
    private function fixtureSources(): array
    {
        $base = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/src/OrderTotal.php');
        return [$base, SingleFileCandidate::create($base, hash('sha256', $base), "max(1, \$item['quantity'])", "\$item['quantity']")];
    }

    /** @return array{0: string, 1: array<string,mixed>} run dir, report */
    private function evidenceFor(string $candidateSource): array
    {
        $runDir = $this->dir . '/runs/run-' . bin2hex(random_bytes(4));
        mkdir($runDir . '/baseline', 0700, true);
        mkdir($runDir . '/negative', 0700, true);
        mkdir($runDir . '/candidate', 0700, true);
        [$base, ] = $this->fixtureSources();
        $test = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case/tests/order_total.php');
        $candidateSha = hash('sha256', $candidateSource);
        file_put_contents($runDir . '/patch.diff', "--- a/src/OrderTotal.php\n+++ b/src/OrderTotal.php\n");
        file_put_contents($runDir . '/baseline/run.log', "zero quantity: expected 250, got 750\n");
        file_put_contents($runDir . '/negative/run.log', "multiple items: expected 330, got 0\n");
        file_put_contents($runDir . '/candidate/run.log', "All order total cases passed.\n");
        $report = ['id' => '20261101T120000Z-' . substr(hash('sha256', $candidateSha), 0, 12), 'kind' => 'owned_backend',
            'decision' => 'VERIFIED', 'isolated' => true, 'oracleVerified' => true, 'genuineProviderEvidence' => true,
            'baseCommit' => DedicatedTestRepo::requireBaseCommit(), 'targetFile' => 'src/OrderTotal.php',
            'baseSha256' => hash('sha256', $base), 'candidateSha256' => $candidateSha,
            'testSha256' => hash('sha256', $test), 'patchSha256' => hash_file('sha256', $runDir . '/patch.diff'),
            'gates' => ['baselineReproduced' => true, 'negativeControlCaught' => true, 'candidatePassed' => true,
                'candidateStaticSafetyPassed' => true, 'allowlistIntegrityIntact' => true],
            'runs' => ['baseline' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $runDir . '/baseline/run.log')],
                'negative' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $runDir . '/negative/run.log')],
                'candidate' => ['exitCode' => 0, 'logSha256' => hash_file('sha256', $runDir . '/candidate/run.log')]],
            'evidenceDirectory' => $runDir];
        file_put_contents($runDir . '/result.json', json_encode($report, JSON_PRETTY_PRINT));
        $receipt = (new Receipt())->create($runDir);
        file_put_contents($runDir . '/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT));
        $report['receipt'] = $receipt;
        return [$runDir, $report, $receipt];
    }

    /** @return array{0: PublishDraftPrInput, 1: PublishDraftPrUseCase} */
    private function scenario(callable $responder): array
    {
        [, $repair] = $this->fixtureSources();
        [$runDir, $report, $receipt] = $this->evidenceFor($repair);
        $approvals = new ServerHeldPublicationApproval($this->dir . '/pub-approvals');
        $token = $approvals->issue($report['id'], $runDir, $receipt['sha256'], DedicatedTestRepo::requireBaseCommit(),
            DedicatedTestRepo::requireName(), 1, 'src/OrderTotal.php', $report['baseSha256'],
            $report['candidateSha256'], $report['testSha256'], isSimulated: false);
        $context = ['repository' => DedicatedTestRepo::requireName(), 'issueNumber' => 1, 'path' => 'src/OrderTotal.php'];
        $suggestion = ['isOfflineExample' => true, 'model' => null, 'candidate' => ['source' => $repair]];
        $input = PublishDraftPrInput::fromRaw(['context' => $context, 'report' => $report, 'suggestion' => $suggestion,
            'is_dedicated' => true, 'auth_token' => $token, 'github_token' => 'mock-token-1234567890',
            'is_private_dedicated' => false]);
        $useCase = new PublishDraftPrUseCase(new DedicatedTestRepoPublisher(
            new ServerHeldPublicationApproval($this->dir . '/pub-approvals'), new MockHttpClient($responder), disconnected: false
        ));
        return [$input, $useCase];
    }

    private function gitFlowResponder(bool $failPullCreate, ?callable $reconcile = null): callable
    {
        $repo = DedicatedTestRepo::requireName();
        $base = DedicatedTestRepo::requireBaseCommit();
        return function (string $method, string $url, array $options = []) use ($repo, $base, $failPullCreate, $reconcile): MockResponse {
            if (str_contains($url, '/pulls?head=')) {
                if ($reconcile === null) {
                    return new MockResponse(json_encode([]));
                }
                return $reconcile($url);
            }
            if (str_ends_with($url, '/git/ref/heads/main')) {
                return new MockResponse(json_encode(['object' => ['sha' => $base]]));
            }
            if (str_contains($url, '/git/commits/' . $base)) {
                return new MockResponse(json_encode(['tree' => ['sha' => str_repeat('1', 40)]]));
            }
            if (str_ends_with($url, '/git/blobs')) {
                return new MockResponse(json_encode(['sha' => str_repeat('2', 40)]));
            }
            if (str_ends_with($url, '/git/trees')) {
                return new MockResponse(json_encode(['sha' => str_repeat('3', 40)]));
            }
            if (str_ends_with($url, '/git/commits')) {
                return new MockResponse(json_encode(['sha' => str_repeat('4', 40)]));
            }
            if (str_ends_with($url, '/git/refs')) {
                return new MockResponse(json_encode(['ref' => 'refs/heads/proofpatch/1-x']));
            }
            if (str_ends_with($url, '/pulls')) {
                if ($failPullCreate) {
                    throw new \RuntimeException('connection reset during PR create');
                }
                return new MockResponse(json_encode(['number' => 9,
                    'html_url' => 'https://github.com/' . $repo . '/pull/9', 'draft' => true]));
            }
            throw new \RuntimeException('Unexpected: ' . $method . ' ' . $url);
        };
    }

    public function testOfflineReferencePublishes(): void
    {
        [$input, $useCase] = $this->scenario($this->gitFlowResponder(false));

        $result = $useCase->execute($input);

        self::assertSame('published', $result->status);
        self::assertSame(9, $result->pr['prNumber']);
        self::assertTrue($result->pr['draft']);
    }

    public function testMissingEvidenceBlocksBeforeAnyWrite(): void
    {
        [$input, ] = $this->scenario($this->gitFlowResponder(false));
        @unlink($input->evidenceDir . '/result.json');
        $useCase = new PublishDraftPrUseCase(new DedicatedTestRepoPublisher(
            new ServerHeldPublicationApproval($this->dir . '/pub-approvals'),
            new MockHttpClient(fn(): MockResponse => throw new \RuntimeException('No network may happen.')),
            disconnected: false
        ));

        $result = $useCase->execute($input);

        self::assertSame('blocked', $result->status);
        self::assertStringStartsWith('PR Publication blocked: Evidence directory is missing', $result->message);
    }

    public function testMidWriteFailureWithNoRemotePrIsOutcomeUnknown(): void
    {
        [$input, $useCase] = $this->scenario($this->gitFlowResponder(true));

        $result = $useCase->execute($input);

        self::assertSame('outcome_unknown', $result->status);
        self::assertStringContainsString('Automatic retries are blocked', $result->message);
        self::assertStringContainsString('proofpatch/1-', $result->branch);
        self::assertSame(DedicatedTestRepo::requireName(), $result->repository);
    }

    public function testMidWriteFailureWithRemotePrReconciles(): void
    {
        $repo = DedicatedTestRepo::requireName();
        $reconcile = function (string $url) use ($repo): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            [, $branch] = explode(':', (string) ($query['head'] ?? ':'));
            return new MockResponse(json_encode([['number' => 11,
                'html_url' => 'https://github.com/' . $repo . '/pull/11', 'draft' => true,
                'head' => ['ref' => $branch, 'sha' => str_repeat('5', 40), 'repo' => ['full_name' => $repo]]]]));
        };
        [$input, $useCase] = $this->scenario($this->gitFlowResponder(true, $reconcile));

        $result = $useCase->execute($input);

        self::assertSame('reconciled', $result->status);
        self::assertSame(11, $result->pr['prNumber']);
        self::assertNotSame('', $result->pr['receiptSha256']);
        self::assertNotSame('', $result->pr['verificationRunId']);
    }

    public function testGuardsRejectMalformedInput(): void
    {
        try {
            PublishDraftPrInput::fromRaw([]);
            self::fail('Empty input must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('No verified candidate run available for publication.', $e->getMessage());
        }
        [, $repair] = $this->fixtureSources();
        [$runDir, $report] = $this->evidenceFor($repair);
        $base = ['context' => ['repository' => 'r'], 'report' => $report,
            'suggestion' => ['candidate' => ['source' => $repair]], 'is_dedicated' => true,
            'auth_token' => 'token', 'github_token' => 'gh'];
        try {
            PublishDraftPrInput::fromRaw(array_merge($base, ['is_dedicated' => false]));
            self::fail('Non-dedicated input must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Pull request publication is strictly restricted to the dedicated test repository.', $e->getMessage());
        }
        try {
            PublishDraftPrInput::fromRaw(array_merge($base, ['auth_token' => '  ']));
            self::fail('Blank token must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Publication refused: Server-held publication authorization token is required.', $e->getMessage());
        }
        try {
            PublishDraftPrInput::fromRaw(array_merge($base, ['github_token' => '']));
            self::fail('Missing GitHub token must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Authentication required: Connect your GitHub account before publishing.', $e->getMessage());
        }
    }
}
