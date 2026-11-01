<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\AuthorizedRepository;
use ProofPatch\Application\Repository\PrivateCapturePolicy;
use ProofPatch\Application\Repository\PullRecordProjection;

final class RepositoryCapturePolicyTest extends TestCase
{
    public function testLookupFindsGrantRowByFullName(): void
    {
        $repos = [
            ['fullName' => 'acme/other', 'isPrivate' => false],
            'junk-row',
            ['fullName' => 'acme/target', 'isPrivate' => true],
        ];

        self::assertSame(['fullName' => 'acme/target', 'isPrivate' => true], AuthorizedRepository::find($repos, 'acme/target'));
        self::assertNull(AuthorizedRepository::find($repos, 'acme/missing'));
        self::assertNull(AuthorizedRepository::find([], 'acme/target'));
    }

    public function testPublicCaptureIsNeverDenied(): void
    {
        self::assertNull(PrivateCapturePolicy::denial(false, false, null, null, 'acme/repo', null));
    }

    public function testPrivateCaptureAllowedOnlyWithFullProof(): void
    {
        $access = ['userId' => 7, 'repositories' => ['acme/repo' => true]];

        self::assertNull(PrivateCapturePolicy::denial(true, true, $access, 7, 'acme/repo', 'acme/repo'));
        // Configured test repo matches case-insensitively.
        self::assertNull(PrivateCapturePolicy::denial(true, true, $access, 7, 'acme/repo', 'ACME/REPO'));
    }

    public function testPrivateCaptureDeniesEachMissingProof(): void
    {
        $good = ['userId' => 7, 'repositories' => ['acme/repo' => true]];
        $cases = [
            'no server sessions' => [false, $good, 7, 'acme/repo', 'acme/repo'],
            'no access grant' => [true, null, 7, 'acme/repo', 'acme/repo'],
            'non-integer user' => [true, ['userId' => '7', 'repositories' => []], 7, 'acme/repo', 'acme/repo'],
            'identity mismatch' => [true, $good, 9, 'acme/repo', 'acme/repo'],
            'repo not in grant' => [true, ['userId' => 7, 'repositories' => ['acme/other' => true]], 7, 'acme/repo', 'acme/repo'],
            'no configured repo' => [true, $good, 7, 'acme/repo', null],
            'wrong configured repo' => [true, $good, 7, 'acme/repo', 'acme/other'],
        ];
        foreach ($cases as $name => [$sessions, $access, $userId, $repo, $configured]) {
            self::assertSame(PrivateCapturePolicy::DENIAL, PrivateCapturePolicy::denial(true, $sessions, $access, $userId, $repo, $configured), $name);
        }
    }

    public function testCanCapturePrivateTestRepoNeedsFullProof(): void
    {
        $access = ['userId' => 7, 'repositories' => ['acme/repo' => true]];

        self::assertTrue(PrivateCapturePolicy::canCapturePrivateTestRepo($access, 'acme/repo', 'acme/repo', true));
        self::assertTrue(PrivateCapturePolicy::canCapturePrivateTestRepo($access, 'ACME/REPO', 'acme/repo', true));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo(null, 'acme/repo', 'acme/repo', true));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo(['userId' => '7'], 'acme/repo', 'acme/repo', true));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo($access, 'acme/other', 'acme/repo', true));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo($access, 'acme/repo', null, true));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo($access, 'acme/repo', 'acme/repo', false));
        self::assertFalse(PrivateCapturePolicy::canCapturePrivateTestRepo(
            ['userId' => 7, 'repositories' => []], 'acme/repo', 'acme/repo', true
        ));
    }

    public function testProjectionBuildsRecordFromAuthoritativeMetadata(): void
    {
        $preview = [
            'title' => 'Fix it', 'body' => 'Details', 'state' => 'open',
            'isMerged' => false, 'isDraft' => true,
            'head' => 'feature', 'base' => 'main',
            'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40),
            'url' => 'https://example.invalid/pr/1', 'user' => 'octo',
            'changedFiles' => 2,
            'files' => [
                ['name' => 'a.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
                ['name' => 'b.php', 'status' => 'added'],
            ],
        ];

        $pull = PullRecordProjection::fromPatchPreview($preview, 'acme/repo', 12, false);

        self::assertSame('acme/repo', $pull['repository']);
        self::assertSame(12, $pull['number']);
        self::assertSame('Fix it', $pull['title']);
        self::assertTrue($pull['isDraft']);
        self::assertFalse($pull['isPrivate']);
        self::assertSame('octo', $pull['user']);
        self::assertSame([
            ['name' => 'a.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
            ['name' => 'b.php', 'status' => 'added', 'additions' => 0, 'deletions' => 0],
        ], $pull['files']);
    }

    public function testProjectionAppliesDefaultsAndPrivateFlag(): void
    {
        $preview = [
            'title' => 'T', 'state' => 'closed',
            'head' => 'h', 'base' => 'b',
            'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40),
            'url' => 'https://example.invalid/pr/2',
            'changedFiles' => 0, 'files' => [],
        ];

        $pull = PullRecordProjection::fromPatchPreview($preview, 'acme/repo', 2, true);

        self::assertSame('', $pull['body']);
        self::assertFalse($pull['isMerged']);
        self::assertFalse($pull['isDraft']);
        self::assertSame('unknown', $pull['user']);
        self::assertTrue($pull['isPrivate']);
    }
}
