<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Parsed autopilot cap input. Guards run here, before any persistence:
 * the caller supplies the ceilings so this layer never touches Eloquent.
 */
final class SaveAutopilotBudgetInput
{
    public function __construct(
        public ?int $maxAttempts,
        public ?int $maxMicros,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromRaw(array $raw, int $attemptsCeiling, int $microsCeiling): self
    {
        $rawAttempts = $raw['maxAttempts'] ?? null;
        $rawDollars = $raw['maxDollars'] ?? null;

        $maxAttempts = null;
        if ($rawAttempts !== null && trim((string) $rawAttempts) !== '') {
            if (!preg_match('/\A[1-9][0-9]{0,5}\z/D', trim((string) $rawAttempts))) {
                throw new \InvalidArgumentException('Attempt cap must be a whole number of at least 1.');
            }
            $maxAttempts = min((int) trim((string) $rawAttempts), $attemptsCeiling);
        }

        $maxMicros = null;
        if ($rawDollars !== null && trim((string) $rawDollars) !== '') {
            if (!is_numeric(trim((string) $rawDollars)) || (float) trim((string) $rawDollars) <= 0) {
                throw new \InvalidArgumentException('Spend cap must be a positive dollar amount.');
            }
            $maxMicros = (int) min(
                round((float) trim((string) $rawDollars) * 1000000),
                $microsCeiling
            );
        }

        return new self($maxAttempts, $maxMicros);
    }
}
