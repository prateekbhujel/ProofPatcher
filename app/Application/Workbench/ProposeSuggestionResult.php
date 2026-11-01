<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/**
 * Outcome of proposing a suggestion. The single-use approval is consumed
 * server-side inside the use case, so the flags tell the controller which
 * session keys to forget to stay in sync, on success and on failure alike.
 */
final readonly class ProposeSuggestionResult
{
    public function __construct(
        public bool $ok,
        public array $suggestion,
        public string $error,
        public bool $approvalConsumed,
        public bool $privateApprovalConsumed,
    ) {
    }

    public static function success(array $suggestion, bool $approvalConsumed, bool $privateApprovalConsumed): self
    {
        return new self(true, $suggestion, '', $approvalConsumed, $privateApprovalConsumed);
    }

    public static function failure(string $error, bool $approvalConsumed, bool $privateApprovalConsumed): self
    {
        return new self(false, [], $error, $approvalConsumed, $privateApprovalConsumed);
    }
}
