<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\ReviewPreviewEligibility;

final class ReviewPreviewEligibilityTest extends TestCase
{
    public function testAllowsOnlyDoublyPublic(): void
    {
        self::assertTrue(ReviewPreviewEligibility::allows(['isPrivate' => false], ['isPrivate' => false]));
        self::assertFalse(ReviewPreviewEligibility::allows(null, ['isPrivate' => false]));
        self::assertFalse(ReviewPreviewEligibility::allows(['isPrivate' => false], null));
        self::assertFalse(ReviewPreviewEligibility::allows(['isPrivate' => false], 'junk'));
        self::assertFalse(ReviewPreviewEligibility::allows(['isPrivate' => true], ['isPrivate' => false]));
        self::assertFalse(ReviewPreviewEligibility::allows(['isPrivate' => false], ['isPrivate' => true]));
        self::assertFalse(ReviewPreviewEligibility::allows([], []));
        self::assertFalse(ReviewPreviewEligibility::allows(['isPrivate' => 0], ['isPrivate' => false]));
    }
}
