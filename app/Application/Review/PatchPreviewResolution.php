<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Decides which patch preview a draft-comment save may use.
 *
 * Precedence is fixed: a fresh session preview wins, otherwise a fresh disk
 * preview (the caller persists it back to the session), otherwise a public
 * pull refetches while a private pull must be reopened. A stale session
 * preview never falls back to disk. Disk corruption is treated as a cache
 * miss; session corruption propagates to the caller.
 */
final class PatchPreviewResolution
{
    public const USE_CACHED = 'cached';
    public const USE_DISK = 'disk';
    public const FETCH = 'fetch';
    public const STALE_PRIVATE = 'stale_private';

    /** @param array<string, mixed>|null $preview */
    public function __construct(
        public string $decision,
        public ?array $preview,
    ) {
    }

    /** @param array<string, mixed> $pull */
    public static function resolve(mixed $sessionPreview, mixed $diskPreview, array $pull): self
    {
        $head = $pull['headSha'] ?? null;
        if (is_array($sessionPreview) && PatchPreviewFreshness::matchesHead($sessionPreview['headSha'] ?? null, $head)) {
            return new self(self::USE_CACHED, $sessionPreview);
        }
        if (!is_array($sessionPreview) && is_array($diskPreview) && self::diskIsFresh($diskPreview, $head)) {
            return new self(self::USE_DISK, $diskPreview);
        }
        if (($pull['isPrivate'] ?? false) === true) {
            return new self(self::STALE_PRIVATE, null);
        }

        return new self(self::FETCH, null);
    }

    /** @param array<string, mixed> $diskPreview */
    private static function diskIsFresh(array $diskPreview, mixed $head): bool
    {
        try {
            return PatchPreviewFreshness::matchesHead($diskPreview['headSha'] ?? null, $head);
        } catch (\TypeError) {
            return false;
        }
    }
}
