<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

final class SaveAutopilotBudgetResult
{
    public function __construct(
        public bool $ok,
        public ?int $maxAttempts,
        public ?int $maxMicros,
        public bool $clears,
        public string $message,
        public string $error,
    ) {
    }
}
