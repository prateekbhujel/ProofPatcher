<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Verifier\FixtureObservationRunner;

final class FixtureObservationRunnerTest extends TestCase
{
    private const SOURCE = '<?php function orderTotal(array $items): int { return 0; }';

    private function fixtureResult(): array
    {
        return [
            'status' => 'passed', 'scope' => 'finite_local_fixture',
            'candidateSha256' => hash('sha256', self::SOURCE),
            'harnessSha256' => 'b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf',
            'baselineSha256' => '54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859',
            'baseline' => 'fixture_failed', 'negative' => 'fixture_failed', 'candidate' => 'fixture_passed',
            'calls' => ['baseline' => 3, 'negative' => 3, 'candidate' => 3],
        ];
    }

    private function runner(array $result, bool $success = true): FixtureObservationRunner
    {
        return new FixtureObservationRunner(PHP_BINARY, static fn (string $input): array => [
            'success' => $success, 'output' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);
    }

    public function testInjectedResultIsExplicitlySimulated(): void
    {
        $result = $this->runner($this->fixtureResult())->observe(self::SOURCE);
        self::assertSame('passed', $result['status']);
        self::assertSame('finite_local_fixture', $result['scope']);
        self::assertSame('simulated', $result['provider']);
    }

    public function testCandidateDigestMustMatchTheInput(): void
    {
        $result = $this->fixtureResult();
        $result['candidateSha256'] = str_repeat('0', 64);
        $this->expectExceptionMessage('integrity checks');
        $this->runner($result)->observe(self::SOURCE);
    }

    public function testPassingClaimRequiresBothFailedControlsAndEveryCase(): void
    {
        $result = $this->fixtureResult();
        $result['calls']['candidate'] = 2;
        $this->expectExceptionMessage('integrity checks');
        $this->runner($result)->observe(self::SOURCE);
    }

    public function testFailedProcessDoesNotCountAsProviderEvidence(): void
    {
        $this->expectExceptionMessage('did not complete');
        $this->runner($this->fixtureResult(), false)->observe(self::SOURCE);
    }
}
