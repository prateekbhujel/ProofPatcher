<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Verifier\OwnedCaseVerifier;
use ProofPatch\Verifier\TrustedOracleRunner;

final class UnsafeOracleFailClosedTest extends TestCase
{
    public function testOptInDoesNotEnableExperimentalTransport(): void
    {
        $old = getenv('TRUSTED_ORACLE_ENABLED');
        $oldEnv = $_ENV['TRUSTED_ORACLE_ENABLED'] ?? null;
        try {
            putenv('TRUSTED_ORACLE_ENABLED=true');
            $_ENV['TRUSTED_ORACLE_ENABLED'] = 'true';
            self::assertFalse(TrustedOracleRunner::isEnabled());
            $runner = new TrustedOracleRunner(enabled: true);
            self::assertFalse($runner->isAvailable()['available']);
            try {
                $runner->runIsolated('<?php', '<?php');
                self::fail('An explicit constructor opt-in must not run the unsafe transport.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('not isolated', $e->getMessage());
            }
        } finally {
            $old === false ? putenv('TRUSTED_ORACLE_ENABLED') : putenv('TRUSTED_ORACLE_ENABLED='.$old);
            if ($oldEnv === null) unset($_ENV['TRUSTED_ORACLE_ENABLED']);
            else $_ENV['TRUSTED_ORACLE_ENABLED'] = $oldEnv;
        }
    }

    public function testExplicitHelperPathCannotBypassPublicRunnerBlock(): void
    {
        $temp = sys_get_temp_dir().'/proofpatch-oracle-helper-'.bin2hex(random_bytes(6));
        mkdir($temp, 0700);
        $helper = $temp.'/helper';
        $marker = $temp.'/executed';
        file_put_contents($helper, "#!/bin/sh\ntouch ".escapeshellarg($marker)."\n");
        chmod($helper, 0700);
        try {
            $runner = new TrustedOracleRunner(pythonPath: $helper, enabled: true);
            self::assertFalse($runner->isAvailable()['available']);
            try {
                $runner->runIsolated('<?php', '<?php');
                self::fail('An explicit helper path must not bypass the unsafe runner block.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('not isolated', $e->getMessage());
            }
            self::assertFileDoesNotExist($marker, 'The helper must never be spawned.');
        } finally {
            @unlink($helper);
            @unlink($marker);
            @rmdir($temp);
        }
    }

    public function testConcreteRunnerCannotVerifyEvenWithPriorCandidateHash(): void
    {
        $temp = sys_get_temp_dir().'/proofpatch-oracle-block-'.bin2hex(random_bytes(6));
        mkdir($temp, 0700);
        $calls = 0;
        $runner = new TrustedOracleRunner(enabled: true, sandboxExecutor: function () use (&$calls): array {
            ++$calls;
            return ['exitCode' => 0, 'output' => "All order total cases passed.\n", 'oracleVerified' => true,
                'genuineProviderEvidence' => true];
        });
        $source = <<<'PHP'
<?php
function orderTotal(array $items): int {
    $oracle = new SplFileObject('/opt/proofpatch_worker/tests/order_total.php');
    return 0;
}
PHP;
        self::assertTrue(OwnedCaseVerifier::checkCandidateSafety($source)['safe']);
        try {
            $verifier = new OwnedCaseVerifier(project: dirname(__DIR__, 2), runsRoot: $temp, isolatedRunner: $runner);
            $report = $verifier->verify($source, ['verifiedCandidateSha' => hash('sha256', $source)]);
            self::assertSame(0, $calls, 'The vulnerable runner must never execute.');
            self::assertSame('FAILED', $report['decision']);
            self::assertFalse($report['oracleVerified']);
            self::assertArrayNotHasKey('publicationAuthorizationToken', $report);
        } finally {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $entry) $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            @rmdir($temp);
        }
    }
}
