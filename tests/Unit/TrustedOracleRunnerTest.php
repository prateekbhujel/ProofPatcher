<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Verifier\TrustedOracleRunner;

final class TrustedOracleRunnerTest extends TestCase
{
    private string|false $previousEnabled;

    protected function setUp(): void
    {
        $this->previousEnabled = getenv('TRUSTED_ORACLE_ENABLED');
    }

    protected function tearDown(): void
    {
        $this->previousEnabled === false
            ? putenv('TRUSTED_ORACLE_ENABLED')
            : putenv('TRUSTED_ORACLE_ENABLED=' . $this->previousEnabled);
    }

    public function testDisabledByDefault(): void
    {
        putenv('TRUSTED_ORACLE_ENABLED');
        unset($_ENV['TRUSTED_ORACLE_ENABLED'], $_SERVER['TRUSTED_ORACLE_ENABLED']);
        $runner = new TrustedOracleRunner();
        $status = $runner->isAvailable();
        self::assertFalse($status['available']);
        self::assertSame('trusted-oracle', $status['runner']);
        self::assertFalse($status['isGenuineProvider']);
        self::assertStringContainsString('not enabled', $status['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trusted oracle runner unavailable');
        $runner->runIsolated('<?php', '<?php');
    }

    public function testEnabledRunnerAcceptsArbitrarySourceInSpec(): void
    {
        $runner = new TrustedOracleRunner(enabled: true);
        $arbitrary = "<?php\n// never allowlisted\nfunction orderTotal(array \$items): int { return 1; }\n";
        $spec = $runner->generateRunnerSpec($arbitrary, '<?php', 'src/OrderTotal.php', 30);
        self::assertSame(base64_encode($arbitrary), $spec['source_b64']);
        self::assertSame('src/OrderTotal.php', $spec['relative_path']);
        self::assertSame(TrustedOracleRunner::VERIFIED_PHP_IMAGE, $spec['image']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $spec['oracle_token']);
    }

    public function testTimeoutClampsToProviderRange(): void
    {
        $runner = new TrustedOracleRunner(enabled: true);
        self::assertSame(10, $runner->generateRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 5)['timeout_seconds']);
        self::assertSame(30, $runner->generateRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 30)['timeout_seconds']);
        self::assertSame(60, $runner->generateRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 600)['timeout_seconds']);
    }

    public function testHelperScriptDeclaresSandboxBoundaries(): void
    {
        $runner = new TrustedOracleRunner(enabled: true);
        $script = $runner->generateRunnerScript('<?php', '<?php', 'src/OrderTotal.php', 30);
        foreach (['gvisor', 'block_network=True', 'secrets=[]', 'volumes={}', 'env={}',
            TrustedOracleRunner::VERIFIED_PHP_IMAGE, 'sb.terminate(wait=True)',
            'trusted-oracle/v1', 'proofpatch_extract_cases'] as $marker) {
            self::assertStringContainsString($marker, $script, "missing: {$marker}");
        }
        self::assertStringNotContainsString('PINNED_SOURCE_HASHES', $script);

        $helperPath = $runner->getHelperScriptPath();
        self::assertFileExists($helperPath);
        $output = shell_exec('python3 -m py_compile ' . escapeshellarg($helperPath) . ' 2>&1');
        self::assertEmpty($output, 'Trusted oracle helper has syntax errors: ' . $output);
    }

    public function testDiagnosticAttestationNeverCountsAndUnattestedFailsClosed(): void
    {
        $attested = $this->runWithFakeHelper(json_encode([
            'protocol' => 'trusted-oracle/v1', 'exit_code' => 0, 'sandbox_id' => 'sb-1',
            'attested' => true, 'truncated' => false, 'timed_out' => false,
            'stdout' => 'passed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR), 0);
        self::assertSame(1, $attested['exitCode']);
        self::assertFalse($attested['oracleVerified']);
        self::assertFalse($attested['isolated']);
        self::assertFalse($attested['genuineProviderEvidence']);
        self::assertSame('diagnostic-untrusted-transport', $attested['runnerType']);

        $unattested = $this->runWithFakeHelper(json_encode([
            'protocol' => 'trusted-oracle/v1', 'exit_code' => 1, 'sandbox_id' => 'sb-2',
            'attested' => false, 'truncated' => false, 'timed_out' => false,
            'stdout' => 'failed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR), 1);
        self::assertSame(1, $unattested['exitCode']);
        self::assertFalse($unattested['oracleVerified']);
    }

    public function testWrongProtocolExitMismatchFloodAndMalformedRejected(): void
    {
        $good = ['protocol' => 'trusted-oracle/v1', 'exit_code' => 0, 'sandbox_id' => 'sb-9',
            'attested' => true, 'truncated' => false, 'timed_out' => false, 'stdout' => 'ok', 'stderr' => ''];

        try {
            $this->runWithFakeHelper(json_encode(['protocol' => 'other/v9'] + $good, JSON_THROW_ON_ERROR), 0);
            self::fail('wrong protocol must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Corrupted private result data', $e->getMessage());
        }

        try {
            $this->runWithFakeHelper(json_encode($good, JSON_THROW_ON_ERROR), 7);
            self::fail('exit mismatch must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exit status does not match', $e->getMessage());
        }

        try {
            $this->runWithFakeHelper(json_encode($good, JSON_THROW_ON_ERROR), 0, true);
            self::fail('output flood must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('output exceeded', $e->getMessage());
        }

        $malformed = $good;
        $malformed['attested'] = 'yes';
        try {
            $this->runWithFakeHelper(json_encode($malformed, JSON_THROW_ON_ERROR), 0);
            self::fail('malformed result must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Corrupted private result data', $e->getMessage());
        }
    }

    public function testSimulatedExecutorNeverClaimsIsolation(): void
    {
        $runner = new TrustedOracleRunner(
            sandboxExecutor: fn(): array => ['exitCode' => 0, 'output' => "All order total cases passed.\n",
                'oracleVerified' => true, 'sandboxId' => 'mock-sb-1']
        );
        $status = $runner->isAvailable();
        self::assertTrue($status['available']);
        self::assertFalse($status['isGenuineProvider']);

        $result = $runner->runIsolated('<?php', '<?php');
        self::assertFalse($result['isolated']);
        self::assertFalse($result['genuineProviderEvidence']);
        self::assertFalse($result['networkBlocked']);
        self::assertFalse($result['oracleVerified']);
        self::assertSame(1, $result['exitCode']);
        self::assertSame('simulated-test-runner', $result['runnerType']);
    }

    /** @return array<string, mixed> */
    private function runWithFakeHelper(string $result, int $exitCode, bool $flood = false): array
    {
        $file = tempnam(sys_get_temp_dir(), 'proofpatch-trusted-fake-');
        if ($file === false) {
            throw new \RuntimeException('Cannot create fake helper.');
        }
        $script = "#!/bin/sh\nprintf '%s' " . escapeshellarg($result) . " > \"\$3\"\n";
        if ($flood) {
            $script .= "head -c 70000 /dev/zero\n";
        }
        file_put_contents($file, $script . 'exit ' . $exitCode . "\n");
        chmod($file, 0700);
        try {
            // Exercise protocol checks without re-enabling the public runner.
            $runner = new TrustedOracleRunner(pythonPath: $file, enabled: true);
            $method = new \ReflectionMethod($runner, 'runDiagnosticTransport');
            return $method->invoke($runner, '<?php', '<?php');
        } finally {
            @unlink($file);
        }
    }
}
