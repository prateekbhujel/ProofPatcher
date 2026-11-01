<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Timing-safe head comparison for cached patch previews: a preview is usable
 * only when its head still matches the pull request head. The preview head is
 * used verbatim (a non-string head throws, as before); the pull head tolerates
 * a missing pull. Callers keep their own shape guards.
 */
final class PatchPreviewFreshness
{
    public static function matchesHead(mixed $previewHead, mixed $pullHead): bool
    {
        return hash_equals($previewHead ?? '', (string) ($pullHead ?? ''));
    }
}
