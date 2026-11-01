<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\KernelTelemetryCommand;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

final class KernelTelemetryCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private string $tempDir;
    private ?string $oldLedgerOverride = null;
    private mixed $app = null;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->app = $this->bootstrapAndMigrateIsolatedDatabase();

        $this->tempDir = sys_get_temp_dir().'/proofpatch_cmd_telemetry_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);

        $ledger = $this->tempDir.'/runtime-ledger.json';
        file_put_contents($ledger, json_encode([
            'version' => 1,
            'attempts' => [
                ['at' => '2026-10-31T10:00:00+00:00', 'reservedMicros' => 15000, 'status' => 'received',
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]],
            ],
        ]));
        $this->oldLedgerOverride = getenv('PROOFPATCH_LEDGER_FILE') !== false ? (string) getenv('PROOFPATCH_LEDGER_FILE') : null;
        putenv('PROOFPATCH_LEDGER_FILE='.$ledger);

        AutoReviewResult::create([
            'repository' => 'acme/repo', 'pull_number' => 1,
            'head_sha' => str_repeat('1', 40), 'base_sha' => str_repeat('2', 40),
            'status' => 'completed', 'is_public' => true, 'summary' => 'Done',
            'recommendation' => 'approve', 'model' => 'muse-spark-1.3',
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50],
            'published_status' => 'local_only',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->oldLedgerOverride !== null) {
            putenv('PROOFPATCH_LEDGER_FILE='.$this->oldLedgerOverride);
        } else {
            putenv('PROOFPATCH_LEDGER_FILE');
        }

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

    public function testCommandRendersTelemetryTables(): void
    {
        $command = new KernelTelemetryCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute([]));

        $output = $tester->getDisplay();
        $this->assertStringContainsString('ProofPatcher Kernel telemetry', $output);
        $this->assertStringContainsString('Model requests', $output);
        $this->assertStringContainsString('Held for model calls', $output);
        $this->assertStringContainsString('Tokens in / out', $output);
        $this->assertStringContainsString('100 / 20', $output);
        $this->assertStringContainsString('muse-spark-1.3', $output);
        $this->assertStringContainsString('Recent activity', $output);
        $this->assertStringContainsString('acme/repo#1', $output);
    }

    public function testLedgerOptionIsAccepted(): void
    {
        $command = new KernelTelemetryCommand();
        $this->assertSame('kernel:telemetry', $command->getName());
        $this->assertTrue($command->getDefinition()->hasOption('ledger'));
    }
}
