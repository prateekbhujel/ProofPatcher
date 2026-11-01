<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\OverviewRunCounts;

final class OverviewRunCountsTest extends TestCase
{
    public function testCountsPublicAndVerifiedRuns(): void
    {
        $reads = [
            ['report' => ['kind' => 'checkout', 'decision' => 'VERIFIED'], 'receipt' => ['sha256' => 'x']],
            ['report' => ['kind' => 'checkout', 'decision' => 'FAILED'], 'receipt' => ['sha256' => 'x']],
            ['report' => ['kind' => 'checkout', 'decision' => 'VERIFIED'], 'receipt' => null],
            ['report' => ['kind' => 'owned_backend', 'isSynthetic' => false, 'decision' => 'VERIFIED'], 'receipt' => ['sha256' => 'x']],
            ['report' => ['kind' => 'owned_backend', 'isSynthetic' => true, 'decision' => 'VERIFIED'], 'receipt' => ['sha256' => 'x']],
        ];

        self::assertSame([4, 2], OverviewRunCounts::count($reads));
        self::assertSame([0, 0], OverviewRunCounts::count([]));
    }

    public function testMalformedReadsAreSkipped(): void
    {
        $reads = [
            null,
            'junk',
            [],
            ['report' => null],
            ['report' => 'junk'],
            ['receipt' => ['sha256' => 'x']],
        ];

        self::assertSame([0, 0], OverviewRunCounts::count($reads));
    }
}
