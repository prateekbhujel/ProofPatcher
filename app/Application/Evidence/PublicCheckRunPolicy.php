<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Decides whether a check-run report may be shown publicly. Backend evidence
 * may contain private repository names and is not yet bound to a user, so
 * owned-backend runs stay hidden unless synthetic.
 */
final class PublicCheckRunPolicy
{
    /** @param array<string, mixed> $report */
    public static function isPublic(array $report): bool
    {
        if (($report['kind'] ?? null) === 'owned_backend' || array_key_exists('context', $report)) {
            return ($report['isSynthetic'] ?? false) === true;
        }
        return true; // Local checkout verifier; not sourced from a GitHub task.
    }
}
