<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Models\AutopilotBudget;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class AutopilotBudgetTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testAbsentCapsNeverBlock(): void
    {
        $this->assertNull(AutopilotBudget::current());
        $this->assertNull(AutopilotBudget::blockingReason(9999, 99999999, 50000));
    }

    public function testAttemptCapBlocksAtLimit(): void
    {
        AutopilotBudget::saveCaps(10, null);

        $this->assertNull(AutopilotBudget::blockingReason(9, 0, 50000));
        $this->assertSame(
            'Autopilot attempt cap reached (10 attempts).',
            AutopilotBudget::blockingReason(10, 0, 50000)
        );
    }

    public function testSpendCapBlocksAtLimitAndOnOverrun(): void
    {
        AutopilotBudget::saveCaps(null, 1000000); // $1.00

        $this->assertNull(AutopilotBudget::blockingReason(0, 500000, 100000));
        $this->assertSame(
            'Autopilot spend cap reached ($1.00).',
            AutopilotBudget::blockingReason(0, 1000000, 100000)
        );
        // A review whose estimate alone would cross the cap is blocked too.
        $this->assertSame(
            'Autopilot spend cap reached ($1.00).',
            AutopilotBudget::blockingReason(0, 990000, 50000)
        );
    }

    public function testInvalidCapsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AutopilotBudget::saveCaps(0, null);
    }

    public function testClearCapsRestoresUncapped(): void
    {
        AutopilotBudget::saveCaps(5, 500000);
        $this->assertNotNull(AutopilotBudget::current());

        AutopilotBudget::clearCaps();

        $this->assertNull(AutopilotBudget::current());
        $this->assertNull(AutopilotBudget::blockingReason(9999, 99999999, 50000));
    }

    public function testLatestRowWins(): void
    {
        AutopilotBudget::saveCaps(5, null);
        AutopilotBudget::saveCaps(50, null);

        $current = AutopilotBudget::current();
        $this->assertNotNull($current);
        $this->assertSame(50, $current->max_attempts);
    }
}
