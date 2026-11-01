<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User-assigned autopilot spending caps. Single-row table: when no row (or no
 * column) is set, autopilot is bounded only by the pre-existing local request
 * budget. Caps are spend-based: autopilot stops once cumulative spend reaches
 * them. Never raises the underlying request budget, only tightens autopilot.
 */
final class AutopilotBudget extends Model
{
    protected $table = 'autopilot_budgets';

    protected $fillable = [
        'max_attempts',
        'max_reserved_micros',
    ];

    protected $casts = [
        'max_attempts' => 'integer',
        'max_reserved_micros' => 'integer',
    ];

    public const MAX_ATTEMPTS_CEILING = 100000;

    public const MAX_MICROS_CEILING = 100000000000; // $100,000 in micros

    public static function current(): ?self
    {
        try {
            return self::latest('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function saveCaps(?int $maxAttempts, ?int $maxReservedMicros): self
    {
        if ($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > self::MAX_ATTEMPTS_CEILING)) {
            throw new \InvalidArgumentException('Attempt cap must be between 1 and '.self::MAX_ATTEMPTS_CEILING.'.');
        }
        if ($maxReservedMicros !== null && ($maxReservedMicros < 1 || $maxReservedMicros > self::MAX_MICROS_CEILING)) {
            throw new \InvalidArgumentException('Spend cap must be positive and within bounds.');
        }

        $row = self::latest('id')->first() ?? new self();
        $row->max_attempts = $maxAttempts;
        $row->max_reserved_micros = $maxReservedMicros;
        $row->save();

        return $row;
    }

    public static function clearCaps(): void
    {
        self::query()->delete();
    }

    /**
     * Returns a human reason when caps block another autopilot review, else null.
     * Absent caps or an unreadable row never blocks (fails open to the
     * request-budget check, which still guards every call).
     */
    public static function blockingReason(int $spentAttempts, int $spentMicros, int $estimatedMicros): ?string
    {
        try {
            $caps = self::current();
        } catch (\Throwable) {
            return null;
        }
        if ($caps === null) {
            return null;
        }

        if ($caps->max_attempts !== null && $spentAttempts >= $caps->max_attempts) {
            return "Autopilot request cap reached ({$caps->max_attempts} model requests).";
        }

        if ($caps->max_reserved_micros !== null
            && ($spentMicros >= $caps->max_reserved_micros || $spentMicros + $estimatedMicros > $caps->max_reserved_micros)) {
            return 'Autopilot spend cap reached ($'.number_format($caps->max_reserved_micros / 1000000, 2).').';
        }

        return null;
    }
}
