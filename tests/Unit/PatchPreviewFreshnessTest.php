<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\PatchPreviewFreshness;

final class PatchPreviewFreshnessTest extends TestCase
{
    public function testMatchingHeads(): void
    {
        self::assertTrue(PatchPreviewFreshness::matchesHead(str_repeat('a', 40), str_repeat('a', 40)));
        self::assertFalse(PatchPreviewFreshness::matchesHead(str_repeat('a', 40), str_repeat('b', 40)));
    }

    public function testMissingHeadsBehaveAsBefore(): void
    {
        self::assertFalse(PatchPreviewFreshness::matchesHead(null, str_repeat('a', 40)));
        self::assertFalse(PatchPreviewFreshness::matchesHead(str_repeat('a', 40), null));
        // Both missing compares empty against empty, exactly like the inlined form.
        self::assertTrue(PatchPreviewFreshness::matchesHead(null, null));
    }

    public function testNonStringPreviewHeadStillThrows(): void
    {
        $this->expectException(\TypeError::class);
        PatchPreviewFreshness::matchesHead(123, str_repeat('a', 40));
    }
}
