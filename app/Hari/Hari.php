<?php

declare(strict_types=1);

namespace Hari;

/**
 * Runtime holder for the booted registry. Production boots and freezes it
 * once via the service provider; tests use isolated `new Registry()`
 * instances and never touch this global.
 */
final class Hari
{
    private static ?Registry $registry = null;

    private function __construct()
    {
    }

    public static function registry(): Registry
    {
        return self::$registry ??= new Registry();
    }

    /** @internal test-only escape hatch; production never resets. */
    public static function reset(): void
    {
        self::$registry = null;
    }
}
