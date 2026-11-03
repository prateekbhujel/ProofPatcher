<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Human-readable UTC labels for timestamps shown in the terminal. */
final class DisplayDate
{
    public static function date(?string $value): string
    {
        return self::format($value, 'j M Y');
    }

    public static function dateTime(?string $value): string
    {
        return self::format($value, 'j M Y, H:i');
    }

    private static function format(?string $value, string $format): string
    {
        if ($value === null || trim($value) === '') return '—';
        try {
            $utc = new DateTimeZone('UTC');
            return (new DateTimeImmutable($value, $utc))->setTimezone($utc)->format($format);
        } catch (\Exception) {
            return $value;
        }
    }
}
