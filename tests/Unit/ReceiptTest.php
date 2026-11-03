<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Evidence\Receipt;

final class ReceiptTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/proofpatch-receipt-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/baseline', 0700);
        foreach (['checkout.png', 'observation.json', 'phpunit.log', 'junit.xml'] as $file) {
            file_put_contents($this->directory.'/baseline/'.$file, 'test evidence '.$file);
        }
        $run = ['id' => '20261027T040000Z-123456abcdef', 'decision' => 'INCONCLUSIVE',
            'baseCommit' => str_repeat('a', 40), 'runs' => ['baseline' => [
                'observation' => ['screenshotSha256' => hash_file('sha256', $this->directory.'/baseline/checkout.png')],
                'logSha256' => hash_file('sha256', $this->directory.'/baseline/phpunit.log'),
                'junitSha256' => hash_file('sha256', $this->directory.'/baseline/junit.xml'),
            ]]];
        file_put_contents($this->directory.'/result.json', json_encode($run, JSON_THROW_ON_ERROR));
        $receipt = (new Receipt())->create($this->directory);
        file_put_contents($this->directory.'/receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $path) {
            $path->isDir() && !$path->isLink() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->directory);
    }

    public function testDetectsAlteredEvidenceAndChecksExternalDigest(): void
    {
        $validator = new Receipt();
        $result = $validator->verify($this->directory);
        self::assertFalse($result['externallyAnchored']);
        self::assertSame('INCONCLUSIVE', $result['decision']);
        self::assertTrue($validator->verify($this->directory, $result['sha256'])['externallyAnchored']);
        file_put_contents($this->directory.'/baseline/checkout.png', 'tampered');
        $this->expectException(\InvalidArgumentException::class);
        $validator->verify($this->directory, $result['sha256']);
    }

    public function testRejectsUntrustedAnchor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Receipt())->verify($this->directory, str_repeat('b', 64));
    }

    public function testEvidenceCanMoveWithoutRewritingItsReportOrDigest(): void
    {
        $validator = new Receipt();
        $report = json_decode((string) file_get_contents($this->directory.'/result.json'), true, 64, JSON_THROW_ON_ERROR);
        $report['evidenceDirectory'] = $this->directory;
        file_put_contents($this->directory.'/result.json', json_encode($report, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/receipt.json', json_encode($validator->create($this->directory), JSON_THROW_ON_ERROR));
        $before = $validator->verify($this->directory);
        $reportBytes = file_get_contents($this->directory.'/result.json');
        $receiptBytes = file_get_contents($this->directory.'/receipt.json');
        $destination = $this->directory.'-moved';
        self::assertTrue(rename($this->directory, $destination));
        $this->directory = $destination;

        self::assertSame($before, $validator->verify($destination));
        self::assertSame($reportBytes, file_get_contents($destination.'/result.json'));
        self::assertSame($receiptBytes, file_get_contents($destination.'/receipt.json'));
    }

    public function testRejectsMissingEvidence(): void
    {
        unlink($this->directory.'/baseline/junit.xml');
        $this->expectException(\InvalidArgumentException::class);
        (new Receipt())->verify($this->directory);
    }

    public function testRejectsSymlinkedEvidence(): void
    {
        unlink($this->directory.'/baseline/checkout.png');
        symlink($this->directory.'/baseline/phpunit.log', $this->directory.'/baseline/checkout.png');
        $this->expectException(\InvalidArgumentException::class);
        (new Receipt())->verify($this->directory);
    }

    public function testOwnedBackendReceiptCreationAndVerification(): void
    {
        $dir = sys_get_temp_dir().'/proofpatch-owned-receipt-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        mkdir($dir.'/baseline', 0700);
        mkdir($dir.'/negative', 0700);
        mkdir($dir.'/candidate', 0700);

        file_put_contents($dir.'/patch.diff', "diff --git a/src/OrderTotal.php b/src/OrderTotal.php\n");
        file_put_contents($dir.'/baseline/run.log', "zero quantity: expected 250, got 750\n");
        file_put_contents($dir.'/negative/run.log', "multiple items: expected 330, got 0\n");
        file_put_contents($dir.'/candidate/run.log', "All order total cases passed.\n");

        $report = [
            'id' => '20261028T120000Z-abcdef123456',
            'kind' => 'owned_backend',
            'decision' => 'VERIFIED',
            'baseCommit' => str_repeat('c', 40),
            'patchSha256' => hash_file('sha256', $dir.'/patch.diff'),
            'gates' => [
                'baselineReproduced' => true,
                'negativeControlCaught' => true,
                'candidatePassed' => true,
                'candidateStaticSafetyPassed' => true,
                'allowlistIntegrityIntact' => true,
            ],
            'runs' => [
                'baseline' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $dir.'/baseline/run.log')],
                'negative' => ['exitCode' => 1, 'logSha256' => hash_file('sha256', $dir.'/negative/run.log')],
                'candidate' => ['exitCode' => 0, 'logSha256' => hash_file('sha256', $dir.'/candidate/run.log')],
            ],
        ];

        file_put_contents($dir.'/result.json', json_encode($report, JSON_THROW_ON_ERROR));

        $receiptHelper = new Receipt();
        $receipt = $receiptHelper->create($dir);
        self::assertArrayHasKey('manifest', $receipt);
        self::assertSame('owned_backend', $receipt['manifest']['kind']);
        self::assertSame('VERIFIED', $receipt['manifest']['decision']);

        file_put_contents($dir.'/receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR));

        $verified = $receiptHelper->verify($dir, $receipt['sha256']);
        self::assertSame('VALID', $verified['integrity']);
        self::assertTrue($verified['externallyAnchored']);
        self::assertSame('VERIFIED', $verified['decision']);

        // Tampering with negative log must fail
        file_put_contents($dir.'/negative/run.log', 'tampered log');
        $this->expectException(\InvalidArgumentException::class);
        $receiptHelper->verify($dir, $receipt['sha256']);
    }
}
