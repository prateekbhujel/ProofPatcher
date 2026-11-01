<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Dual private-gate for review previews: a preview is estimated only when
 * both the patch preview and the pull request are explicitly non-private.
 * Unknown or missing visibility fails closed. Pure predicate; the caller
 * owns estimation, approvals, and sessions.
 */
final class ReviewPreviewEligibility
{
    public static function allows(mixed $patchPreview, mixed $pull): bool
    {
        return $patchPreview !== null && ($patchPreview['isPrivate'] ?? true) === false
            && is_array($pull) && ($pull['isPrivate'] ?? true) === false;
    }
}
