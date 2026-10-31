<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\KernelReviewsCommand;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

final class KernelReviewsCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private mixed $app = null;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->app = $this->bootstrapAndMigrateIsolatedDatabase();

        AutoReviewResult::create([
            'repository' => 'acme/roadrunner', 'pull_number' => 7,
            'head_sha' => str_repeat('1', 40), 'base_sha' => str_repeat('2', 40),
            'status' => 'completed', 'is_public' => true, 'summary' => 'Rounding fix looks correct.',
            'risk_level' => 'low', 'recommendation' => 'approve',
            'published_status' => 'local_only',
        ]);
        AutoReviewResult::create([
            'repository' => 'acme/anvil', 'pull_number' => 3,
            'head_sha' => str_repeat('3', 40), 'base_sha' => str_repeat('4', 40),
            'status' => 'failed', 'is_public' => true,
            'published_status' => 'local_only',
        ]);
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function runCommand(array $input = []): string
    {
        $command = new KernelReviewsCommand();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute($input));

        return $tester->getDisplay();
    }

    public function testListsRecentFindings(): void
    {
        $output = $this->runCommand([]);

        $this->assertStringContainsString('acme/roadrunner#7', $output);
        $this->assertStringContainsString('approve', $output);
        $this->assertStringContainsString('acme/anvil#3', $output);
        $this->assertStringContainsString('failed', $output);
    }

    public function testRepositoryFilterExcludesOtherRepos(): void
    {
        $output = $this->runCommand(['--repository' => 'acme/roadrunner']);

        $this->assertStringContainsString('acme/roadrunner#7', $output);
        $this->assertStringNotContainsString('acme/anvil#3', $output);
    }

    public function testStatusFilterAndLimit(): void
    {
        $output = $this->runCommand(['--status' => 'failed', '--limit' => '1']);

        $this->assertStringContainsString('acme/anvil#3', $output);
        $this->assertStringNotContainsString('acme/roadrunner#7', $output);
    }

    public function testEmptyStoreSaysSo(): void
    {
        AutoReviewResult::query()->delete();

        $this->assertStringContainsString('No automatic review findings yet.', $this->runCommand([]));
    }
}
