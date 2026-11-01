<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\TriageCandidates;
use ProofPatch\Application\Review\SavedAutoReview;

final class RepositoriesReadPathTest extends TestCase
{
    public function testFirstCompletedReviewMergesAutomaticSource(): void
    {
        $outcome = ['rows' => [
            ['status' => 'completed', 'published_status' => 'local_only',
                'raw_review' => ['summary' => 's', 'findings' => []]],
        ]];

        self::assertSame(
            ['summary' => 's', 'findings' => [], 'source' => 'automatic', 'publishedStatus' => 'local_only'],
            SavedAutoReview::firstCompletedReview($outcome)
        );
    }

    public function testFirstCompletedReviewRejectsAnythingElse(): void
    {
        self::assertNull(SavedAutoReview::firstCompletedReview(null));
        self::assertNull(SavedAutoReview::firstCompletedReview('junk'));
        self::assertNull(SavedAutoReview::firstCompletedReview([]));
        self::assertNull(SavedAutoReview::firstCompletedReview(['rows' => 'junk']));
        self::assertNull(SavedAutoReview::firstCompletedReview(['rows' => []]));
        self::assertNull(SavedAutoReview::firstCompletedReview(['rows' => [['status' => 'failed', 'raw_review' => ['a' => 1]]]]));
        self::assertNull(SavedAutoReview::firstCompletedReview(['rows' => [['status' => 'completed']]]));
    }

    public function testTriageCandidateIdentity(): void
    {
        $current = ['repository' => 'acme/repo', 'number' => 8];
        self::assertFalse(TriageCandidates::isDifferentPull(['repository' => 'acme/repo', 'number' => 8], $current));
        self::assertTrue(TriageCandidates::isDifferentPull(['repository' => 'acme/repo', 'number' => 9], $current));
        self::assertTrue(TriageCandidates::isDifferentPull(['repository' => 'acme/other', 'number' => 8], $current));
        self::assertFalse(TriageCandidates::isDifferentPull(null, $current));
        self::assertFalse(TriageCandidates::isDifferentPull('junk', $current));
        self::assertFalse(TriageCandidates::isDifferentPull([], []));
        self::assertTrue(TriageCandidates::isDifferentPull(['repository' => 'acme/repo'], $current));
    }
}
