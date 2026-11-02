<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;
use ProofPatch\GitHub\ServerHeldPublicationApproval;

final class ServerHeldPublicationApprovalTest extends TestCase
{
    private string $tempDir;
    private ServerHeldPublicationApproval $store;
    private const SECRET = 'server-test-secret-key-32-bytes-long!!';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/proofpatch_pub_approval_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->store = new ServerHeldPublicationApproval($this->tempDir, self::SECRET, 1800);
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

    public function testIssueCreatesValidServerHeldTokenWith0600Permissions(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);

        $tokenFile = $this->tempDir . '/' . $token . '.json';
        self::assertFileExists($tokenFile);
        $perms = fileperms($tokenFile) & 0777;
        self::assertSame(0600, $perms);
    }

    public function testVerifyAndConsumeAtomicallyConsumesToken(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $consumed = $this->store->verifyAndConsume(
            $token,
            $runId,
            $evidenceDir,
            $receiptSha,
            $candidateSha
        );

        self::assertSame($token, $consumed['token']);
        self::assertSame($runId, $consumed['runId']);
        self::assertFalse($consumed['isSimulated']);

        // Original file must be gone (atomically moved to .consumed)
        $tokenFile = $this->tempDir . '/' . $token . '.json';
        self::assertFileDoesNotExist($tokenFile);
    }

    public function testPrivateProposalRunIsBoundToPublicationApproval(): void
    {
        $run = 'run-private';
        $evidence = $this->tempDir.'/runs/'.$run;
        mkdir($evidence, 0700, true);
        $receipt = str_repeat('a', 64);
        $candidate = str_repeat('c', 64);
        $proposalId = str_repeat('d', 32);
        $issue = fn () => $this->store->issue($run, $evidence, $receipt,
            str_repeat('e', 40), 'owner/repo', 1, 'src/OrderTotal.php',
            str_repeat('b', 64), $candidate, str_repeat('f', 64), false, $proposalId);
        $token = $issue();
        self::assertSame($token, $this->store->findActiveTokenForRun($run, $candidate));
        $record = $this->store->verifyAndConsume($token, $run, $evidence, $receipt, $candidate, $proposalId);
        self::assertSame($proposalId, $record['privateProvenanceId']);
        $wrong = $issue();
        $this->expectExceptionMessage('private proposal run');
        $this->store->verifyAndConsume($wrong, $run, $evidence, $receipt, $candidate, str_repeat('f', 32));
    }

    public function testReplayConsumptionFailsClosed(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // First consumption succeeds
        $this->store->verifyAndConsume($token, $runId, $evidenceDir, $receiptSha, $candidateSha);

        // Second consumption (replay attack) MUST fail closed
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publication authorization token is invalid, expired, or was already consumed.');

        $this->store->verifyAndConsume($token, $runId, $evidenceDir, $receiptSha, $candidateSha);
    }

    public function testRejectsTamperedHmacSignature(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // Tamper with the file's HMAC on disk
        $tokenFile = $this->tempDir . '/' . $token . '.json';
        $record = json_decode((string) file_get_contents($tokenFile), true);
        $record['hmac'] = str_repeat('0', 64); // Forged HMAC!
        file_put_contents($tokenFile, json_encode($record, JSON_PRETTY_PRINT));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publication authorization record failed HMAC signature verification.');

        $this->store->verifyAndConsume($token, $runId, $evidenceDir, $receiptSha, $candidateSha);
    }

    public function testRejectsExpiredToken(): void
    {
        // Store with 1 second TTL
        $shortStore = new ServerHeldPublicationApproval($this->tempDir, self::SECRET, ttlSeconds: 1);

        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $shortStore->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        // Sleep 2 seconds to exceed 1s TTL
        sleep(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Publication authorization token has expired.');

        $shortStore->verifyAndConsume($token, $runId, $evidenceDir, $receiptSha, $candidateSha);
    }

    public function testRejectsSimulatedRunsDuringConsumption(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: true // Marked simulated
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot publish proposal: Verification was executed using a mock/simulated runner, not genuine provider microVM isolation.');

        $this->store->verifyAndConsume($token, $runId, $evidenceDir, $receiptSha, $candidateSha);
    }

    public function testConcurrentProcessesRaceOnlyOneSucceeds(): void
    {
        $runId = 'run-' . bin2hex(random_bytes(6));
        $evidenceDir = $this->tempDir . '/runs/' . $runId;
        mkdir($evidenceDir, 0700, true);
        $receiptSha = str_repeat('a', 64);
        $baseSha = str_repeat('b', 64);
        $candidateSha = str_repeat('c', 64);
        $testSha = str_repeat('d', 64);

        $token = $this->store->issue(
            $runId,
            $evidenceDir,
            $receiptSha,
            \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
            \ProofPatch\GitHub\DedicatedTestRepo::name(),
            1,
            'src/OrderTotal.php',
            $baseSha,
            $candidateSha,
            $testSha,
            isSimulated: false
        );

        $autoload = escapeshellarg(dirname(__DIR__, 2) . '/vendor/autoload.php');
        $storageDir = escapeshellarg($this->tempDir);
        $secretArg = escapeshellarg(self::SECRET);
        $evidenceArg = escapeshellarg($evidenceDir);

        $script = <<<PHP
require {$autoload};
\$store = new \ProofPatch\GitHub\ServerHeldPublicationApproval({$storageDir}, {$secretArg}, 1800);
try {
    \$store->verifyAndConsume('{$token}', '{$runId}', {$evidenceArg}, '{$receiptSha}', '{$candidateSha}');
    echo "SUCCESS\n";
} catch (\Throwable \$e) {
    echo "FAILED: " . \$e->getMessage() . "\n";
}
PHP;

        $cmd1 = 'php -r ' . escapeshellarg($script);
        $cmd2 = 'php -r ' . escapeshellarg($script);

        $p1 = popen($cmd1, 'r');
        $p2 = popen($cmd2, 'r');

        $out1 = $p1 !== false ? trim((string) stream_get_contents($p1)) : '';
        $out2 = $p2 !== false ? trim((string) stream_get_contents($p2)) : '';

        if ($p1 !== false) {
            pclose($p1);
        }
        if ($p2 !== false) {
            pclose($p2);
        }

        $outputs = [$out1, $out2];
        $successCount = count(array_filter($outputs, static fn($o) => $o === 'SUCCESS'));
        $failCount = count(array_filter($outputs, static fn($o) => str_starts_with($o, 'FAILED:')));

        // Atomic POSIX rename guarantees exactly ONE process succeeds
        self::assertSame(1, $successCount, "Expected exactly 1 SUCCESS: " . json_encode($outputs));
        self::assertSame(1, $failCount, "Expected exactly 1 FAILED: " . json_encode($outputs));
        self::assertStringContainsString('already consumed', implode(' ', $outputs));
    }
}
