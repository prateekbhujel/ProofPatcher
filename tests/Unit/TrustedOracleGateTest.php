<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Verifier\ModalIsolatedRunner;
use ProofPatch\Verifier\OwnedCaseVerifier;
use ProofPatch\Verifier\TrustedOracleInterface;

/**
 * The trusted-oracle gate: arbitrary statically safe candidates may verify
 * only through an attested TrustedOracleInterface runner. All other runners
 * keep the allowlist, and static safety stays mandatory everywhere.
 */
final class TrustedOracleGateTest extends TestCase
{
    private string $tempDir;
    private string $evidenceDir;
    private string $approvalsDir;

    /** A statically safe repair that is NOT the allowlisted fixture candidate. */
    private const NOVEL_REPAIR = <<<'PHP'
<?php

declare(strict_types=1);

function orderTotal(array $items): int
{
    $total = 0;
    foreach ($items as $item) {
        $price = $item['unit_price'];
        $quantity = $item['quantity'];
        if ($quantity > 0) {
            $total += $price * $quantity;
        }
    }

    return $total;
}
PHP;

    protected function setUp(): void
    {
        $fixture = dirname(__DIR__, 2) . '/tests/Fixtures/owned-review-case';
        $novelSha = hash('sha256', self::NOVEL_REPAIR);
        self::assertNotEquals(OwnedCaseVerifier::KNOWN_TRUSTED_CANDIDATE_SHA256, $novelSha);
        self::assertTrue(OwnedCaseVerifier::checkCandidateSafety(self::NOVEL_REPAIR)['safe']);

        $this->tempDir = sys_get_temp_dir() . '/proofpatch_trusted_gate_' . bin2hex(random_bytes(6));
        $this->evidenceDir = $this->tempDir . '/runs';
        $this->approvalsDir = $this->tempDir . '/approvals';
        mkdir($this->tempDir, 0700, true);
        mkdir($this->evidenceDir, 0700, true);
        mkdir($this->approvalsDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tempDir);
        }
    }

    private function stubTrustedRunner(bool $attestCandidate): TrustedOracleInterface
    {
        return new class($attestCandidate) implements TrustedOracleInterface {
            public function __construct(private readonly bool $attest) {}

            public function oracleProtocolVersion(): string
            {
                return 'trusted-oracle/v1';
            }

            public function isAvailable(): array
            {
                return ['available' => true, 'runner' => 'stub-trusted', 'isGenuineProvider' => false];
            }

            public function runIsolated(string $source, string $testHarness, string $relativePath = 'src/OrderTotal.php', int $timeoutSeconds = 30): array
            {
                if (str_contains($source, 'max(1,')) {
                    return ['exitCode' => 1, 'output' => "zero quantity: expected 250, got 750\n",
                        'oracleVerified' => false, 'sandboxId' => 'sb-stub-base',
                        'durationMs' => 5, 'genuineProviderEvidence' => false];
                }
                if (str_contains($source, 'return 0;')) {
                    return ['exitCode' => 1, 'output' => "multiple items: expected 330, got 0\n",
                        'oracleVerified' => false, 'sandboxId' => 'sb-stub-neg',
                        'durationMs' => 5, 'genuineProviderEvidence' => false];
                }
                return ['exitCode' => $this->attest ? 0 : 1,
                    'output' => $this->attest ? "All order total cases passed.\n" : "zero quantity: expected 250, got 0\n",
                    'oracleVerified' => $this->attest, 'truncated' => false, 'timedOut' => false,
                    'durationMs' => 9, 'sandboxId' => 'sb-stub-cand', 'genuineProviderEvidence' => false];
            }
        };
    }

    public function testNovelCandidateVerifiesOnlyThroughTrustedOracle(): void
    {
        $verifier = new OwnedCaseVerifier(
            project: dirname(__DIR__, 2),
            runsRoot: $this->evidenceDir,
            approvalStore: new ServerHeldPublicationApproval($this->approvalsDir),
            isolatedRunner: $this->stubTrustedRunner(true)
        );
        $report = $verifier->verify(self::NOVEL_REPAIR);
        self::assertSame('VERIFIED', $report['decision']);
        self::assertTrue($report['gates']['candidatePassed']);
        self::assertTrue($report['isolated']);
        // A stub is simulated: it exercises the gate, never publication.
        self::assertTrue($report['isSimulated']);
        self::assertFalse($report['genuineProviderEvidence']);
    }

    public function testNovelCandidateBlockedWithoutTrustedOracle(): void
    {
        $runner = new ModalIsolatedRunner(sandboxExecutor: fn(): array => [
            'exitCode' => 0, 'output' => "All order total cases passed.\n",
            'oracleVerified' => true, 'sandboxId' => 'sb-mock',
        ]);
        $verifier = new OwnedCaseVerifier(
            project: dirname(__DIR__, 2),
            runsRoot: $this->evidenceDir,
            approvalStore: new ServerHeldPublicationApproval($this->approvalsDir),
            isolatedRunner: $runner
        );
        $report = $verifier->verify(self::NOVEL_REPAIR);
        self::assertSame('FAILED', $report['decision']);
        self::assertFalse($report['gates']['candidatePassed']);
        self::assertArrayNotHasKey('publicationAuthorizationToken', $report);
        $log = (string) file_get_contents($report['evidenceDirectory'] . '/candidate/run.log');
        self::assertStringContainsString('test oracle is isolated', $log);
    }

    public function testUnattestedTrustedRunFailsClosed(): void
    {
        $verifier = new OwnedCaseVerifier(
            project: dirname(__DIR__, 2),
            runsRoot: $this->evidenceDir,
            approvalStore: new ServerHeldPublicationApproval($this->approvalsDir),
            isolatedRunner: $this->stubTrustedRunner(false)
        );
        $report = $verifier->verify(self::NOVEL_REPAIR);
        self::assertSame('FAILED', $report['decision']);
        self::assertFalse($report['gates']['candidatePassed']);
        self::assertArrayNotHasKey('publicationAuthorizationToken', $report);
    }

    public function testStaticSafetyStillMandatoryThroughTrustedOracle(): void
    {
        $unsafe = "<?php\ndeclare(strict_types=1);\nfunction orderTotal(array \$items): int {\n    \\system('id');\n    return 0;\n}\n";
        self::assertFalse(OwnedCaseVerifier::checkCandidateSafety($unsafe)['safe']);

        $verifier = new OwnedCaseVerifier(
            project: dirname(__DIR__, 2),
            runsRoot: $this->evidenceDir,
            approvalStore: new ServerHeldPublicationApproval($this->approvalsDir),
            isolatedRunner: $this->stubTrustedRunner(true)
        );
        $report = $verifier->verify($unsafe);
        self::assertSame('FAILED', $report['decision']);
        self::assertFalse($report['gates']['candidateStaticSafetyPassed']);
        self::assertFalse($report['gates']['candidatePassed']);
        self::assertArrayNotHasKey('publicationAuthorizationToken', $report);
    }
}
