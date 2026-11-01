<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/** Outcome of verifying a candidate. A FAILED decision is still `ok`: the run completed and the report must be persisted. */
final readonly class VerifyCandidateResult
{
    public function __construct(
        public bool $ok,
        public array $report,
        public string $error,
    ) {
    }

    /** @param array<string,mixed> $report */
    public static function success(array $report): self
    {
        return new self(true, $report, '');
    }

    public static function failure(string $error): self
    {
        return new self(false, [], $error);
    }
}
