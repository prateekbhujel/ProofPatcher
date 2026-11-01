<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\PatchPreviewResolution;

final class PatchPreviewResolutionTest extends TestCase
{
    public function testFreshSessionPreviewWins(): void
    {
        $session = ['headSha' => 'abc123', 'files' => []];
        $disk = ['headSha' => 'abc123', 'files' => ['stale']];

        $resolution = PatchPreviewResolution::resolve($session, $disk, ['headSha' => 'abc123']);

        self::assertSame(PatchPreviewResolution::USE_CACHED, $resolution->decision);
        self::assertSame($session, $resolution->preview);
    }

    public function testFreshDiskPreviewUsedWhenSessionMissing(): void
    {
        $disk = ['headSha' => 'abc123'];

        $resolution = PatchPreviewResolution::resolve(null, $disk, ['headSha' => 'abc123']);

        self::assertSame(PatchPreviewResolution::USE_DISK, $resolution->decision);
        self::assertSame($disk, $resolution->preview);
    }

    public function testStaleDiskFallsThroughToFetchForPublicPull(): void
    {
        $resolution = PatchPreviewResolution::resolve(
            null,
            ['headSha' => 'old'],
            ['headSha' => 'abc123', 'isPrivate' => false]
        );

        self::assertSame(PatchPreviewResolution::FETCH, $resolution->decision);
        self::assertNull($resolution->preview);
    }

    public function testPublicPullWithoutDiskFallsThroughToFetch(): void
    {
        $resolution = PatchPreviewResolution::resolve(
            ['headSha' => 'old'],
            null,
            ['headSha' => 'abc123', 'isPrivate' => false]
        );

        self::assertSame(PatchPreviewResolution::FETCH, $resolution->decision);
        self::assertNull($resolution->preview);
    }

    public function testCorruptDiskPreviewIsTreatedAsAbsent(): void
    {
        $resolution = PatchPreviewResolution::resolve(
            'not-an-array',
            ['headSha' => ['corrupt']],
            ['headSha' => 'abc123', 'isPrivate' => false]
        );

        self::assertSame(PatchPreviewResolution::FETCH, $resolution->decision);
        self::assertNull($resolution->preview);
    }

    public function testStaleSessionNeverConsultsDisk(): void
    {
        $resolution = PatchPreviewResolution::resolve(
            ['headSha' => 'old'],
            ['headSha' => 'abc123'],
            ['headSha' => 'abc123', 'isPrivate' => false]
        );

        self::assertSame(PatchPreviewResolution::FETCH, $resolution->decision);
    }

    public function testMissingPreviewOnPrivatePullRequiresReopen(): void
    {
        $resolution = PatchPreviewResolution::resolve(null, null, ['headSha' => 'abc123', 'isPrivate' => true]);

        self::assertSame(PatchPreviewResolution::STALE_PRIVATE, $resolution->decision);
        self::assertNull($resolution->preview);
    }

    public function testFreshSessionPreviewServesPrivatePull(): void
    {
        $session = ['headSha' => 'abc123'];

        $resolution = PatchPreviewResolution::resolve($session, null, ['headSha' => 'abc123', 'isPrivate' => true]);

        self::assertSame(PatchPreviewResolution::USE_CACHED, $resolution->decision);
        self::assertSame($session, $resolution->preview);
    }

    public function testCorruptSessionPreviewPropagatesToCaller(): void
    {
        $this->expectException(\TypeError::class);

        PatchPreviewResolution::resolve(
            ['headSha' => ['corrupt']],
            ['headSha' => 'abc123'],
            ['headSha' => 'abc123', 'isPrivate' => false]
        );
    }
}
