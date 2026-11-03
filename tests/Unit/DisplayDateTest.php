<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\DisplayDate;

final class DisplayDateTest extends TestCase
{
    public function testLabelsUseReadableUtcDates(): void
    {
        self::assertSame('10 Oct 2026', DisplayDate::date('2026-10-10'));
        self::assertSame('11 Oct 2026, 03:30', DisplayDate::dateTime('2026-10-10T23:30:00-04:00'));
        self::assertSame('10 Oct 2026, 13:45', DisplayDate::dateTime('2026-10-10 13:45:00'));
    }

    public function testMissingAndMalformedValuesAreSafe(): void
    {
        self::assertSame('—', DisplayDate::dateTime(null));
        self::assertSame('not a date', DisplayDate::date('not a date'));
    }
}
