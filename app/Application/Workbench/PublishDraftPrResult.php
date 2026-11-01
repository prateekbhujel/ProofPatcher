<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/**
 * Outcome of the publish network step. `published` and `reconciled` both
 * carry the PR record; `outcome_unknown` carries branch identity plus the
 * exact user message; `blocked` is a pre-write refusal with its message.
 */
final readonly class PublishDraftPrResult
{
    public function __construct(
        public string $status,
        public array $pr,
        public string $message,
        public string $repository,
        public string $branch,
        public string $runId,
        public string $detail,
    ) {
    }

    /** @param array<string,mixed> $pr */
    public static function published(array $pr): self
    {
        return new self('published', $pr, '', '', '', '', '');
    }

    /** @param array<string,mixed> $pr */
    public static function reconciled(array $pr): self
    {
        return new self('reconciled', $pr, '', '', '', '', '');
    }

    public static function outcomeUnknown(string $repository, string $branch, string $runId, string $detail): self
    {
        $message = "PR publication outcome unknown: writes began for branch '{$branch}', but confirmation failed ({$detail}). Automatic retries are blocked to prevent duplicate branches or PRs. Reconcile the branch state first.";

        return new self('outcome_unknown', [], $message, $repository, $branch, $runId, $detail);
    }

    public static function blocked(string $message): self
    {
        return new self('blocked', [], $message, '', '', '', '');
    }
}
