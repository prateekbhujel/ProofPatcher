<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\PullActionState;

final class PullActionStateTest extends TestCase
{
    public function testRepositoryGrantRequiresVerifiedIdentityAndExactGrantedRepository(): void
    {
        $pull = ['repository' => 'Owner/Repo'];
        $access = ['userId' => 7, 'repositories' => ['owner/repo' => true]];
        self::assertTrue(PullActionState::hasRepositoryGrant($pull, $access));
        self::assertFalse(PullActionState::hasRepositoryGrant($pull, ['userId' => 0, 'repositories' => ['owner/repo' => true]]));
        self::assertFalse(PullActionState::hasRepositoryGrant($pull, ['userId' => 7, 'repositories' => ['owner/other' => true]]));
        self::assertFalse(PullActionState::hasRepositoryGrant($pull, ['userId' => 7, 'repositories' => ['owner/repo' => false]]));
        self::assertFalse(PullActionState::hasRepositoryGrant($pull, null));
        self::assertFalse(PullActionState::hasRepositoryGrant(null, $access));
    }

    public function testGuardRequiresStrictConfirmationAndShape(): void
    {
        $auth = ['token' => 't'];
        $pull = ['number' => 8];
        $access = ['userId' => 7];

        self::assertNull(PullActionState::guardError(true, $auth, $pull, $access));
        foreach ([false, 0, 1, 'true', null] as $confirmed) {
            self::assertSame(PullActionState::GUARD_ERROR, PullActionState::guardError($confirmed, $auth, $pull, $access));
        }
        self::assertSame(PullActionState::GUARD_ERROR, PullActionState::guardError(true, null, $pull, $access));
        self::assertSame(PullActionState::GUARD_ERROR, PullActionState::guardError(true, ['token' => 7], $pull, $access));
        self::assertSame(PullActionState::GUARD_ERROR, PullActionState::guardError(true, $auth, null, $access));
        self::assertSame(PullActionState::GUARD_ERROR, PullActionState::guardError(true, $auth, $pull, null));
    }

    public function testAppliedPull(): void
    {
        $pull = ['number' => 8, 'state' => 'open', 'isMerged' => false, 'title' => 'T'];

        $merged = PullActionState::appliedPull($pull, 'merge');
        self::assertSame('merged', $merged['state']);
        self::assertTrue($merged['isMerged']);
        self::assertSame('T', $merged['title']);

        $closed = PullActionState::appliedPull($pull, 'close');
        self::assertSame('closed', $closed['state']);
        self::assertFalse($closed['isMerged']);

        // Close never touches a pre-existing merged flag, exactly as before.
        $already = PullActionState::appliedPull(array_merge($pull, ['isMerged' => true]), 'close');
        self::assertTrue($already['isMerged']);
    }

    public function testSyncedRepoPulls(): void
    {
        $list = [
            ['number' => 8, 'state' => 'open', 'isMerged' => false],
            ['number' => 9, 'state' => 'open', 'isMerged' => false],
            'junk-row',
            ['number' => '8', 'state' => 'open'],
        ];

        $synced = PullActionState::syncedRepoPulls($list, 8, 'merged');
        self::assertSame('merged', $synced[0]['state']);
        self::assertTrue($synced[0]['isMerged']);
        self::assertSame('open', $synced[1]['state']);
        self::assertSame('junk-row', $synced[2]);
        // Strict number match: the string '8' row is untouched.
        self::assertSame('open', $synced[3]['state']);

        $closed = PullActionState::syncedRepoPulls($list, 8, 'closed');
        self::assertSame('closed', $closed[0]['state']);
        self::assertFalse($closed[0]['isMerged']);
    }
}
