<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Support\Telemetry;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class TelemetryTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempDir = sys_get_temp_dir().'/proofpatch_telemetry_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }

        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function fixtureLedger(): string
    {
        $path = $this->tempDir.'/runtime-ledger.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'attempts' => [
                ['at' => '2026-10-31T10:00:00+00:00', 'reservedMicros' => 15000, 'status' => 'received',
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]],
                ['at' => '2026-10-30T10:00:00+00:00', 'reservedMicros' => 12000, 'status' => 'uncertain'],
            ],
        ]));

        return $path;
    }

    private function seedReviews(): void
    {
        AutoReviewResult::create([
            'repository' => 'acme/repo', 'pull_number' => 1,
            'head_sha' => str_repeat('1', 40), 'base_sha' => str_repeat('2', 40),
            'status' => 'completed', 'is_public' => true, 'summary' => 'Done',
            'recommendation' => 'approve', 'model' => 'muse-spark-1.3',
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50],
            'published_status' => 'local_only',
        ]);
        AutoReviewResult::create([
            'repository' => 'acme/repo', 'pull_number' => 2,
            'head_sha' => str_repeat('3', 40), 'base_sha' => str_repeat('4', 40),
            'status' => 'failed', 'is_public' => true,
            'published_status' => 'local_only',
        ]);
    }

    public function testCollectAggregatesLedgerAndReviews(): void
    {
        $this->seedReviews();
        $data = Telemetry::collect($this->fixtureLedger());

        $this->assertTrue($data['ledgerFound']);
        $this->assertSame(2, $data['requests']['total']);
        $this->assertSame(1, $data['requests']['received']);
        $this->assertSame(1, $data['requests']['uncertain']);
        $this->assertSame(27000, $data['requests']['reservedMicros']);

        $this->assertSame(['prompt' => 100, 'completion' => 20, 'total' => 120], $data['tokens']);

        $this->assertCount(2, $data['perDay']);
        $this->assertSame('2026-10-31', $data['perDay'][0]['day']);
        $this->assertSame(1, $data['perDay'][0]['requests']);
        $this->assertSame(15000, $data['perDay'][0]['micros']);

        $this->assertSame(2, $data['reviews']['total']);
        $this->assertSame(1, $data['reviews']['completed']);
        $this->assertSame(1, $data['reviews']['failed']);

        $this->assertCount(1, $data['models']);
        $this->assertSame('muse-spark-1.3', $data['models'][0]['model']);
        $this->assertSame(1, $data['models'][0]['reviews']);
        $this->assertSame(300, $data['models'][0]['promptTokens']);
        $this->assertSame(50, $data['models'][0]['completionTokens']);

        // Feed merges both sources newest-first: the seeded reviews (now) precede ledger rows.
        $this->assertCount(4, $data['feed']);
        $this->assertSame(['review', 'review', 'model_request', 'model_request'], array_column($data['feed'], 'kind'));
        $this->assertContains('acme/repo#1 · approve', array_column($data['feed'], 'detail'));
        $this->assertContains('acme/repo#2', array_column($data['feed'], 'detail'));
        $this->assertContains(350, array_column($data['feed'], 'tokens'));
    }

    public function testMissingLedgerYieldsHonestZeros(): void
    {
        $data = Telemetry::collect($this->tempDir.'/does-not-exist.json');

        $this->assertFalse($data['ledgerFound']);
        $this->assertSame(0, $data['requests']['total']);
        $this->assertSame(0, $data['tokens']['total']);
        $this->assertSame([], $data['perDay']);
        $this->assertSame([], $data['feed']);
    }

    public function testCorruptLedgerFailsClosed(): void
    {
        $path = $this->tempDir.'/runtime-ledger.json';
        file_put_contents($path, '{not json');
        $data = Telemetry::collect($path);

        $this->assertTrue($data['ledgerFound']);
        $this->assertSame(0, $data['requests']['total']);
    }
}
