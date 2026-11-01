<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

final class SaveDraftCommentResult
{
    /** @param array<string, mixed> $draft */
    public function __construct(
        public bool $ok,
        public array $draft,
        public string $error,
    ) {
    }
}
