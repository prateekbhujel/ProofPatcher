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

    public function testTriageKeepsLatestCapturePerRepositoryAndPull(): void
    {
        $old = ['repository' => 'acme/repo', 'number' => 8, 'headSha' => 'old'];
        $new = ['repository' => 'ACME/REPO', 'number' => 8, 'headSha' => 'new'];
        $other = ['repository' => 'acme/other', 'number' => 8, 'headSha' => 'other'];
        $captures = [
            ['id' => 'old', 'capturedAt' => '2026-11-01T09:00:00+00:00', 'pull' => $old],
            ['id' => 'new', 'capturedAt' => '2026-11-01T11:00:00+00:00', 'pull' => $new],
            ['id' => 'other', 'capturedAt' => '2026-11-01T10:00:00+00:00', 'pull' => $other],
            ['id' => 'duplicate', 'capturedAt' => '2026-11-01T10:30:00+00:00', 'pull' => $new],
        ];
        self::assertSame([$new, $other], TriageCandidates::latestPerPull($captures));
        self::assertCount(4, $captures, 'Selection must not mutate the saved history input.');
    }

    public function testTriageEqualTimesHaveStableTiebreakAndMalformedCapturesAreIgnored(): void
    {
        $a = ['repository' => 'acme/repo', 'number' => 8, 'headSha' => 'a'];
        $b = ['repository' => 'acme/repo', 'number' => 8, 'headSha' => 'b'];
        $captures = [
            ['id' => 'b', 'capturedAt' => '2026-11-01T12:00:00+01:00', 'pull' => $b],
            ['id' => 'a', 'capturedAt' => '2026-11-01T11:00:00+00:00', 'pull' => $a],
            ['id' => 'z', 'capturedAt' => 'not a date', 'pull' => $a],
            ['id' => 'z', 'capturedAt' => '2026-11-02T00:00:00+00:00', 'pull' => ['repository' => 'acme/repo']],
        ];
        self::assertSame([$b], TriageCandidates::latestPerPull($captures));
        self::assertSame([$b], TriageCandidates::latestPerPull(array_reverse($captures)));
    }
}
