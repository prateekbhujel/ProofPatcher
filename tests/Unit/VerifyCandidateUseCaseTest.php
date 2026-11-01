<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Agent\SingleFileCandidate;
use ProofPatch\Application\Workbench\VerifyCandidateInput;
use ProofPatch\Application\Workbench\VerifyCandidateUseCase;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Verifier\OwnedCaseVerifier;
use ProofPatch\Verifier\SeparateProcessOracle;

/**
 * The verify use case runs without HTTP or session access: guards and the
 * fail-closed runner check first, then exactly one owned-case run.
 */
final class VerifyCandidateUseCaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/verify-usecase-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/runs', 0700, true);
        mkdir($this->dir . '/approvals', 0700, true);
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
        $repair = SingleFileCandidate::create($base, hash('sha256', $base), "max(1, \$item['quantity'])", "\$item['quantity']");
        self::assertSame(OwnedCaseVerifier::KNOWN_TRUSTED_CANDIDATE_SHA256, hash('sha256', $repair));
        return [$base, $repair];
    }

    private function useCase(?SeparateProcessOracle $runner = null): VerifyCandidateUseCase
    {
        return new VerifyCandidateUseCase(new OwnedCaseVerifier(
            dirname(__DIR__, 2),
            $this->dir . '/runs',
            new ServerHeldPublicationApproval($this->dir . '/approvals'),
            $runner
        ));
    }

    public function testTrustedRepairVerifiesOnHostWithoutRunner(): void
    {
        [, $repair] = $this->fixtureSources();
        $suggestion = ['path' => 'src/OrderTotal.php', 'candidate' => ['source' => $repair]];
        $input = VerifyCandidateInput::fromRaw($suggestion, true, ['repository' => 'fixture'], false);

        $result = $this->useCase()->execute($input);

        self::assertTrue($result->ok, $result->error);
        self::assertSame('VERIFIED', $result->report['decision']);
        self::assertTrue($result->report['gates']['candidatePassed']);
    }

    public function testModelCandidateWithoutRunnerFailsClosedBeforeAnyRun(): void
    {
        [, $repair] = $this->fixtureSources();
        $suggestion = ['path' => 'src/OrderTotal.php', 'model' => 'meta-models/muse-spark-open-1026',
            'candidate' => ['source' => $repair]];

        try {
            VerifyCandidateInput::fromRaw($suggestion, true, ['repository' => 'fixture'], false);
            self::fail('Model candidate without a runner must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('Host execution disabled:', $e->getMessage());
        }
        self::assertSame([], glob($this->dir . '/runs/*') ?: []);
    }

    public function testWrongCandidateStillReturnsAFailedReport(): void
    {
        $wrong = "<?php\ndeclare(strict_types=1);\nfunction orderTotal(array \$items): int {\n    return 0;\n}\n";
        $suggestion = ['path' => 'src/OrderTotal.php', 'candidate' => ['source' => $wrong]];
        $input = VerifyCandidateInput::fromRaw($suggestion, true, ['repository' => 'fixture'], true);

        $result = $this->useCase(new SeparateProcessOracle())->execute($input);

        self::assertTrue($result->ok, $result->error);
        self::assertSame('FAILED', $result->report['decision']);
    }

    public function testVerifierFailureReturnsRawMessage(): void
    {
        [, $repair] = $this->fixtureSources();
        $suggestion = ['path' => 'src/OrderTotal.php', 'candidate' => ['source' => $repair]];
        $input = VerifyCandidateInput::fromRaw($suggestion, true, ['repository' => 'fixture'], false);
        mkdir($this->dir . '/empty-project', 0700, true);
        $broken = new VerifyCandidateUseCase(new OwnedCaseVerifier(
            (string) realpath($this->dir . '/empty-project'),
            $this->dir . '/runs',
            new ServerHeldPublicationApproval($this->dir . '/approvals')
        ));

        $result = $broken->execute($input);

        self::assertFalse($result->ok);
        self::assertStringContainsString('fixture', strtolower($result->error));
    }

    public function testGuardsRejectMalformedInput(): void
    {
        try {
            VerifyCandidateInput::fromRaw(null, true, [], false);
            self::fail('Missing suggestion must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('No candidate proposal available to verify.', $e->getMessage());
        }
        try {
            VerifyCandidateInput::fromRaw(['path' => 'src/Other.php', 'candidate' => ['source' => 'x']], true, [], false);
            self::fail('Non-allowlisted path must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('Automated host verification is currently restricted', $e->getMessage());
        }
        try {
            VerifyCandidateInput::fromRaw(['path' => 'src/OrderTotal.php', 'candidate' => ['source' => 'x']], true, null, false);
            self::fail('Missing context must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('No active issue context available to verify.', $e->getMessage());
        }
    }
}
