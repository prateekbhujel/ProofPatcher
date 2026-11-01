<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

use ProofPatch\Application\Repository\SnapshotVisibilityPolicy;

/**
 * Decides whether a saved snapshot may appear on the public overview and
 * builds its recent entry. Fail-closed: malformed snapshots and anything
 * not explicitly public are refused (null). Pure policy; the caller owns
 * storage reads and aggregation.
 */
final class OverviewSnapshotPolicy
{
    /**
     * @param array<string, mixed> $saved
     * @return array{id: string, pull: array<string, mixed>, capturedAt: string}|null
     */
    public static function recentEntry(array $saved, string $id): ?array
    {
        $pull = $saved['snapshot']['pullRequest'] ?? null;
        if (!is_array($pull) || !is_string($pull['repository'] ?? null) || !is_int($pull['number'] ?? null)
            || !is_string($pull['title'] ?? null) || !is_string($pull['state'] ?? null)
            || !is_string($pull['headSha'] ?? null) || !is_string($saved['snapshot']['capturedAt'] ?? null)) {
            return null;
        }
        // Fail-closed security check: do not expose private or unknown-visibility snapshots on the public overview.
        if (!SnapshotVisibilityPolicy::isExplicitlyPublic($saved)) {
            return null;
        }

        return ['id' => $id, 'pull' => $pull, 'capturedAt' => $saved['snapshot']['capturedAt']];
    }
}
