<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\GitHubListWarning;

final class GitHubListWarningTest extends TestCase
{
    public function testCompleteListHasNoWarning(): void
    {
        self::assertNull(GitHubListWarning::message(
            ['truncated' => false, 'errors' => []],
            'Truncated.',
            'Errors: '
        ));
    }

    public function testTruncatedListUsesTruncatedMessage(): void
    {
        self::assertSame(
            'Truncated.',
            GitHubListWarning::message(
                ['truncated' => true, 'errors' => ['first', 'second']],
                'Truncated.',
                'Errors: '
            )
        );
    }

    public function testErrorsAreJoinedWhenNotTruncated(): void
    {
        self::assertSame(
            'Errors: first; second',
            GitHubListWarning::message(
                ['truncated' => false, 'errors' => ['first', 'second']],
                'Truncated.',
                'Errors: '
            )
        );
    }

    public function testMissingStatusKeysAreTreatedAsComplete(): void
    {
        self::assertNull(GitHubListWarning::message([], 'Truncated.', 'Errors: '));
    }
}
