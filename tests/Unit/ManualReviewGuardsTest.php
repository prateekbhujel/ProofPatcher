<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\ManualReviewGuards;

final class ManualReviewGuardsTest extends TestCase
{
    public function testValidTriplePasses(): void
    {
        ManualReviewGuards::validate(true, str_repeat('a', 64), str_repeat('b', 64));
        $this->expectNotToPerformAssertions();
    }

    public function testConfirmationIsCheckedFirst(): void
    {
        foreach ([false, 0, null, ''] as $confirm) {
            try {
                ManualReviewGuards::validate($confirm, 'bad', 'bad');
                self::fail('confirmation must be required');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('You must review and explicitly approve the request before sending.', $e->getMessage());
            }
        }
    }

    public function testDigestAndTokenMustBeHex64(): void
    {
        foreach (['', 'xyz', str_repeat('a', 63), str_repeat('a', 65), str_repeat('A', 64)] as $bad) {
            try {
                ManualReviewGuards::validate(true, $bad, str_repeat('b', 64));
                self::fail('digest must be validated');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('A valid preview digest is required.', $e->getMessage());
            }
            try {
                ManualReviewGuards::validate(true, str_repeat('a', 64), $bad);
                self::fail('token must be validated');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('A valid server-held approval token is required.', $e->getMessage());
            }
        }
    }
}
