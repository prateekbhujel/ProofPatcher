<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/** Validated draft-comment input. Guards run here, before any session write. */
final class SaveDraftCommentInput
{
    public function __construct(
        public string $path,
        public string $comment,
        public string $headSha,
        public ?int $line,
        public string $side,
        /** @var array<string, mixed> */
        public array $pull,
        /** @var array<string, mixed>|null */
        public ?array $patchPreview,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $pull
     */
    public static function fromRaw(array $raw, array $pull): self
    {
        $path = (string) ($raw['path'] ?? '');
        $comment = trim((string) ($raw['comment'] ?? ''));
        $headSha = (string) ($raw['headSha'] ?? '');
        $lineRaw = $raw['line'] ?? null;
        $line = is_numeric($lineRaw) ? (int) $lineRaw : null;
        $side = (string) ($raw['side'] ?? 'RIGHT');

        if ($path === '' || strlen($path) > 4096) {
            throw new \InvalidArgumentException('A valid file path is required.');
        }
        if ($comment === '' || strlen($comment) > 2000 || str_contains($comment, "\0")) {
            throw new \InvalidArgumentException('Draft comment must be between 1 and 2000 characters.');
        }
        if (!preg_match('/\A[a-f0-9]{40}\z/', $headSha)) {
            throw new \InvalidArgumentException('A valid 40-character head SHA is required.');
        }
        if (!hash_equals((string) ($pull['headSha'] ?? ''), $headSha)) {
            throw new \InvalidArgumentException('Cannot anchor comment: draft head SHA does not match current captured head SHA.');
        }
        return new self($path, $comment, $headSha, $line, $side, $pull, null);
    }

    /** @param array<string, mixed> $patchPreview */
    public function withPreview(array $patchPreview): self
    {
        $clone = clone $this;
        $clone->patchPreview = $patchPreview;
        return $clone;
    }
}
