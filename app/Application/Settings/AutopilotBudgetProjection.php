<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Projects an autopilot budget row into settings props. Pure projection:
 * micros become dollars here so views never do money math.
 *
 * @return array{maxAttempts: mixed, maxReservedDollars: float|null}
 */
final class AutopilotBudgetProjection
{
    /** @return array<string, mixed> */
    public static function fromRow(mixed $maxAttempts, mixed $maxReservedMicros): array
    {
        return [
            'maxAttempts' => $maxAttempts,
            'maxReservedDollars' => $maxReservedMicros === null
                ? null
                : round($maxReservedMicros / 1000000, 2),
        ];
    }
}
