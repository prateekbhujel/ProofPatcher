<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Decides whether a snapshot candidate is a *different* pull than the loaded
 * one, so triage overlap compares against other pulls only. Pure predicate;
 * the caller owns snapshot loading and authorization.
 */
final class TriageCandidates
{
    public static function isDifferentPull(mixed $candidate, mixed $current): bool
    {
        return is_array($candidate)
            && (($candidate['number'] ?? null) !== ($current['number'] ?? null)
                || ($candidate['repository'] ?? '') !== ($current['repository'] ?? ''));
    }
}
