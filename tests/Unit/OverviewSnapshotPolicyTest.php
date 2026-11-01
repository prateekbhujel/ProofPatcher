<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\OverviewSnapshotPolicy;

final class OverviewSnapshotPolicyTest extends TestCase
{
    /** @return array<string, mixed> */
    private function saved(array $pullOverrides = [], array $snapshotOverrides = []): array
    {
        return ['snapshot' => array_merge([
            'pullRequest' => array_merge([
                'repository' => 'acme/repo', 'number' => 3, 'title' => 'Fix',
                'state' => 'open', 'headSha' => str_repeat('a', 40),
                'isPrivate' => false,
            ], $pullOverrides),
            'capturedAt' => '2026-11-01T10:00:00+00:00',
        ], $snapshotOverrides)];
    }

    public function testAcceptsWellFormedPublicSnapshot(): void
    {
        $entry = OverviewSnapshotPolicy::recentEntry($this->saved(), 'snap-1');

        self::assertSame('snap-1', $entry['id']);
        self::assertSame('acme/repo', $entry['pull']['repository']);
        self::assertSame('2026-11-01T10:00:00+00:00', $entry['capturedAt']);
    }

    public function testRefusesMalformedSnapshots(): void
    {
        self::assertNull(OverviewSnapshotPolicy::recentEntry([], 'x'));
        self::assertNull(OverviewSnapshotPolicy::recentEntry(['snapshot' => []], 'x'));
        self::assertNull(OverviewSnapshotPolicy::recentEntry($this->saved(['number' => '3']), 'x'));
        self::assertNull(OverviewSnapshotPolicy::recentEntry($this->saved(['title' => null]), 'x'));
        self::assertNull(OverviewSnapshotPolicy::recentEntry($this->saved([], ['capturedAt' => 123]), 'x'));
    }

    public function testRefusesNonPublicSnapshots(): void
    {
        self::assertNull(OverviewSnapshotPolicy::recentEntry($this->saved(['isPrivate' => true]), 'x'));
        $missing = $this->saved();
        unset($missing['snapshot']['pullRequest']['isPrivate']);
        self::assertNull(OverviewSnapshotPolicy::recentEntry($missing, 'x'));
        self::assertNull(OverviewSnapshotPolicy::recentEntry($this->saved(['visibility' => 'internal']), 'x'));
    }
}
