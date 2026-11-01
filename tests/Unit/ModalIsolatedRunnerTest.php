<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Verifier\ModalIsolatedRunner;

final class ModalIsolatedRunnerTest extends TestCase
{
    public function testGeneratesValidPythonScriptWithGvisorAndBlockedNetwork(): void
    {
        $runner = new ModalIsolatedRunner();
        $source = "<?php function orderTotal(array \$items): int { return 100; }\n";
        $test = "<?php exit(0);\n";
        $script = $runner->generateModalRunnerScript($source, $test, 'src/OrderTotal.php', 30);
        $spec = $runner->generateModalRunnerSpec($source, $test, 'src/OrderTotal.php', 30);

        // Security boundary assertions in standalone Python script
        self::assertStringContainsString('gvisor', $script);
        self::assertStringContainsString('block_network=True', $script);
        self::assertStringContainsString('secrets=[]', $script);
        self::assertStringContainsString('volumes={}', $script);
        self::assertStringContainsString('env={}', $script);
        self::assertStringContainsString(ModalIsolatedRunner::VERIFIED_PHP_IMAGE, $script);
        self::assertStringContainsString('sb.terminate(wait=True)', $script);
        self::assertStringContainsString('sb.poll()', $script);
        self::assertStringContainsString('cpu=(0.25, 0.25)', $script);
        self::assertStringContainsString('memory=(256, 256)', $script);

        // Spec generator assertions
        self::assertSame(base64_encode($source), $spec['source_b64']);
        self::assertSame(base64_encode($test), $spec['test_harness_b64']);
        self::assertSame('src/OrderTotal.php', $spec['relative_path']);
        self::assertSame(30, $spec['timeout_seconds']);

        // Validate standalone Python helper syntax using python3 -m py_compile
        $helperPath = $runner->getHelperScriptPath();
        self::assertFileExists($helperPath);
        $cmd = 'python3 -m py_compile ' . escapeshellarg($helperPath) . ' 2>&1';
        $output = shell_exec($cmd);
        self::assertEmpty($output, 'Standalone Python helper has syntax errors: ' . $output);
    }

    public function testDistinguishesSimulatedRunnerFromGenuineEvidence(): void
    {
        $simulatedExecutor = function (string $source, string $test, string $path, int $timeout): array {
            return [
                'exitCode' => 0,
                'output' => "All cases passed in mock sandbox.\n",
                'sandboxId' => 'mock-sb-01',
                'networkBlocked' => true,
            ];
        };

        $runner = new ModalIsolatedRunner(sandboxExecutor: $simulatedExecutor);
        $availability = $runner->isAvailable();

        self::assertTrue($availability['available']);
        self::assertSame('simulated', $availability['runner']);
        self::assertFalse($availability['isGenuineProvider'], 'Simulated runner must NOT claim genuine provider status');

        $result = $runner->runIsolated('<?php', '<?php');
        self::assertSame('simulated-test-runner', $result['runnerType']);
        self::assertFalse($result['genuineProviderEvidence'], 'Simulation must explicitly state genuineProviderEvidence is false');
        self::assertFalse($result['isolated'], 'Simulation must honestly state isolated is false');
        self::assertFalse($result['networkBlocked'], 'A host callback cannot establish network isolation');
        self::assertSame(0, $result['exitCode']);
        self::assertSame('mock-sb-01', $result['sandboxId']);
    }

    public function testFailsClosedWhenPythonOrVenvNotFound(): void
    {
        $runner = new ModalIsolatedRunner(pythonPath: '/non/existent/path/to/python3');
        $status = $runner->isAvailable();

        self::assertFalse($status['available']);
        self::assertFalse($status['isGenuineProvider']);
        self::assertStringContainsString('Modal Python environment not found', $status['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modal isolated runner unavailable');

        $runner->runIsolated('<?php', '<?php');
    }

    public function testFailsClosedWhenGrantUnverified(): void
    {
        $runner = new ModalIsolatedRunner(
            pythonPath: ModalIsolatedRunner::DEFAULT_PYTHON_VENV,
            grantVerified: false
        );

        $status = $runner->isAvailable();
        self::assertFalse($status['available']);
        self::assertSame('Sandbox runs are not enabled.', $status['reason']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modal isolated runner unavailable: Sandbox runs are not enabled.');

        $runner->runIsolated('<?php', '<?php');
    }

    public function testTimeoutClampsToProviderRange(): void
    {
        $runner = new ModalIsolatedRunner();
        self::assertSame(10, $runner->generateModalRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 5)['timeout_seconds']);
        self::assertSame(30, $runner->generateModalRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 30)['timeout_seconds']);
        self::assertSame(60, $runner->generateModalRunnerSpec('<?php', '<?php', 'src/OrderTotal.php', 600)['timeout_seconds']);
    }

    public function testSanitizesRelativePaths(): void
    {
        $runner = new ModalIsolatedRunner(sandboxExecutor: fn() => ['exitCode' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal or absolute paths are strictly prohibited');

        $runner->runIsolated('<?php', '<?php', '../etc/passwd');
    }

    public function testHelperResultMustMatchProcessExitStatus(): void
    {
        $result = json_encode([
            'exit_code' => 0, 'sandbox_id' => 'fake', 'oracle_verified' => true,
            'truncated' => false, 'timed_out' => false, 'stdout' => 'passed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR);
        $helper = $this->fakeHelper($result, 7);
        try {
            $runner = new ModalIsolatedRunner(pythonPath: $helper, grantVerified: true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('exit status does not match');
            $runner->runIsolated('<?php', '<?php');
        } finally {
            @unlink($helper);
        }
    }

    public function testHelperOutputFloodCannotOverrideItsResult(): void
    {
        $result = json_encode([
            'exit_code' => 0, 'sandbox_id' => 'fake', 'oracle_verified' => true,
            'truncated' => false, 'timed_out' => false, 'stdout' => 'passed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR);
        $helper = $this->fakeHelper($result, 0, true);
        try {
            $runner = new ModalIsolatedRunner(pythonPath: $helper, grantVerified: true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('output exceeded');
            $runner->runIsolated('<?php', '<?php');
        } finally {
            @unlink($helper);
        }
    }

    public function testFailedFixtureResultCanBeReportedWithoutClaimingAPass(): void
    {
        $result = json_encode([
            'exit_code' => 1, 'sandbox_id' => 'fake', 'oracle_verified' => false,
            'truncated' => false, 'timed_out' => false, 'stdout' => 'failed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR);
        $helper = $this->fakeHelper($result, 1);
        try {
            $runner = new ModalIsolatedRunner(pythonPath: $helper, grantVerified: true);
            $report = $runner->runIsolated('<?php', '<?php');
            self::assertSame(1, $report['exitCode']);
            self::assertFalse($report['oracleVerified']);
        } finally {
            @unlink($helper);
        }
    }

    public function testMalformedHelperResultIsRejected(): void
    {
        $result = json_encode([
            'exit_code' => '0', 'sandbox_id' => 'fake', 'oracle_verified' => true,
            'truncated' => false, 'timed_out' => false, 'stdout' => 'passed', 'stderr' => '',
        ], JSON_THROW_ON_ERROR);
        $helper = $this->fakeHelper($result, 0);
        try {
            $runner = new ModalIsolatedRunner(pythonPath: $helper, grantVerified: true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Corrupted private result data');
            $runner->runIsolated('<?php', '<?php');
        } finally {
            @unlink($helper);
        }
    }

    private function fakeHelper(string $result, int $exitCode, bool $flood = false): string
    {
        $file = tempnam(sys_get_temp_dir(), 'proofpatch-fake-helper-');
        if ($file === false) {
            throw new \RuntimeException('Cannot create fake helper.');
        }
        $script = "#!/bin/sh\nprintf '%s' ".escapeshellarg($result)." > \"\$3\"\n";
        if ($flood) {
            $script .= "head -c 70000 /dev/zero\n";
        }
        file_put_contents($file, $script.'exit '.$exitCode."\n");
        chmod($file, 0700);
        return $file;
    }
}
