<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Console\Commands\KernelShellCommand;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The interactive menu itself needs a human tty, but the non-interactive
 * bail-out is pinned here: without a tty the shell must exit with guidance
 * instead of spinning the menu forever.
 */
final class KernelShellCommandTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    public function testMenuOffersExplicitMentionListener(): void
    {
        $command = new KernelShellCommand();
        $method = new \ReflectionMethod($command, 'menuOptions');
        $options = $method->invoke($command);
        $this->assertContains('mentions', array_column($options, 'key'));
        $this->assertContains('quit', array_column($options, 'key'));
    }

    public function testNonInteractiveBailsOutWithGuidance(): void
    {
        $this->setupIsolatedDatabase();
        $app = $this->bootstrapAndMigrateIsolatedDatabase();

        try {
            $command = new KernelShellCommand();
            $command->setLaravel($app);
            $tester = new CommandTester($command);
            $exit = $tester->execute([]);

            $this->assertSame(1, $exit);
            $display = $tester->getDisplay();
            $this->assertStringContainsString('interactive terminal', $display);
            $this->assertStringContainsString('kernel:status', $display);
            $this->assertStringContainsString('kernel:autopilot', $display);
        } finally {
            $this->teardownIsolatedDatabase();
            @restore_error_handler();
            @restore_exception_handler();
        }
    }
}
