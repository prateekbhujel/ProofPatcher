<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\ReviewPreviewRedirect;
use ProofPatch\Application\Settings\AutopilotBudgetProjection;

final class ViewModelProjectionTest extends TestCase
{
    public function testBudgetProjectionConvertsMicrosToDollars(): void
    {
        self::assertSame(
            ['maxAttempts' => 25, 'maxReservedDollars' => 2.5],
            AutopilotBudgetProjection::fromRow(25, 2500000)
        );
        self::assertSame(
            ['maxAttempts' => null, 'maxReservedDollars' => null],
            AutopilotBudgetProjection::fromRow(null, null)
        );
        self::assertSame(0.16, AutopilotBudgetProjection::fromRow(19, 155196)['maxReservedDollars']);
    }

    public function testPreviewRedirectKeepsSnapshotContext(): void
    {
        self::assertSame('/repositories?preview=1&snapshot=abc123', ReviewPreviewRedirect::to(['id' => 'abc123']));
        self::assertSame('/repositories?preview=1', ReviewPreviewRedirect::to(null));
        self::assertSame('/repositories?preview=1', ReviewPreviewRedirect::to([]));
        self::assertSame('/repositories?preview=1', ReviewPreviewRedirect::to(['other' => 1]));
        self::assertSame('/repositories?preview=1', ReviewPreviewRedirect::to('junk'));
    }
}
