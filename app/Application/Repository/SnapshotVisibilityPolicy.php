<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Canonical saved-snapshot visibility rule: a snapshot counts as public only
 * when its pull request explicitly carries isPrivate === false, and any
 * present visibility key says 'public'. Everything else fails closed. Pure
 * predicate; callers own storage, sessions, and redirects.
 */
final class SnapshotVisibilityPolicy
{
    /** @param array<string, mixed> $savedSnapshot */
    public static function isExplicitlyPublic(array $savedSnapshot): bool
    {
        $pull = $savedSnapshot['snapshot']['pullRequest'] ?? null;
        if (!is_array($pull)) {
            return false;
        }

        // Must explicitly have isPrivate === false
        if (!array_key_exists('isPrivate', $pull) || $pull['isPrivate'] !== false) {
            return false;
        }

        // If visibility key exists, it must be 'public'
        if (array_key_exists('visibility', $pull) && $pull['visibility'] !== 'public') {
            return false;
        }

        return true;
    }
}
