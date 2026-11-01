<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Counts check runs for the public overview: only publicly visible reports
 * count, and only receipted VERIFIED ones count as verified. Malformed
 * reads are skipped, matching the read loop's fail-closed behavior. Pure
 * aggregation; the caller owns evidence reads.
 */
final class OverviewRunCounts
{
    /** @return array{int, int} [total, verified] */
    public static function count(array $reads): array
    {
        $total = 0;
        $verified = 0;
        foreach ($reads as $run) {
            if (!is_array($run) || !is_array($run['report'] ?? null) || !PublicCheckRunPolicy::isPublic($run['report'])) {
                continue;
            }
            $total++;
            if (($run['receipt'] ?? null) !== null && ($run['report']['decision'] ?? null) === 'VERIFIED') {
                $verified++;
            }
        }

        return [$total, $verified];
    }
}
