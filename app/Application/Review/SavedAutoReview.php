<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Reads the first completed automatic review out of a review-result skill
 * outcome, tagged as an automatic source. Anything else (no rows, wrong
 * status, missing payload) yields null so the session review stands.
 * Pure interpretation; the caller owns the skill call.
 */
final class SavedAutoReview
{
    /** @return array<string, mixed>|null */
    public static function firstCompletedReview(mixed $outcome): ?array
    {
        $rows = is_array($outcome) ? ($outcome['rows'] ?? null) : null;
        $first = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($first) || ($first['status'] ?? null) !== 'completed' || !is_array($first['raw_review'] ?? null)) {
            return null;
        }

        return array_merge($first['raw_review'], ['source' => 'automatic', 'publishedStatus' => $first['published_status'] ?? null]);
    }
}
