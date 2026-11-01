<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\SnapshotVisibilityPolicy;

final class SnapshotVisibilityPolicyTest extends TestCase
{
    /** @param array<string, mixed> $pull */
    private function saved(array $pull): array
    {
        return ['snapshot' => ['pullRequest' => $pull]];
    }

    public function testExplicitlyPublicSnapshots(): void
    {
        self::assertTrue(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => false])));
        self::assertTrue(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => false, 'visibility' => 'public'])));
    }

    public function testEverythingElseFailsClosed(): void
    {
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic([]));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic(['snapshot' => []]));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic(['snapshot' => ['pullRequest' => null]]));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic(['snapshot' => ['pullRequest' => 'junk']]));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => true])));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved([])));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => 0])));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => false, 'visibility' => 'internal'])));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => false, 'visibility' => 'PUBLIC'])));
        self::assertFalse(SnapshotVisibilityPolicy::isExplicitlyPublic($this->saved(['isPrivate' => null])));
    }
}
