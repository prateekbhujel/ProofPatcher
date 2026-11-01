<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Review\CommentApprovalDigests;
use ProofPatch\GitHub\ManualInlineCommentPublisher;

final class CommentApprovalDigestsTest extends TestCase
{
    /** @return array{access: array<string, mixed>, pull: array<string, mixed>, drafts: array<string, mixed>} */
    private function good(): array
    {
        return [
            'access' => ['userId' => 7, 'repositories' => ['acme/repo' => true]],
            'pull' => [
                'repository' => 'acme/repo', 'number' => 8,
                'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40),
            ],
            'drafts' => [
                'd1' => ['id' => 'd1', 'commentable' => true, 'path' => 'a.php', 'line' => 1, 'side' => 'RIGHT', 'comment' => 'x'],
                'd2' => ['id' => 'd2', 'commentable' => false, 'path' => 'a.php', 'line' => 2, 'side' => 'RIGHT', 'comment' => 'y'],
                'junk' => 'not-a-draft',
            ],
        ];
    }

    public function testDigestsOnlyCommentableDrafts(): void
    {
        $g = $this->good();
        $approvals = CommentApprovalDigests::forDrafts($g['access'], $g['pull'], $g['drafts'], 'acme/repo');

        self::assertSame(['d1'], array_keys($approvals));
        self::assertSame(
            ManualInlineCommentPublisher::digest(7, 'acme/repo', 8, str_repeat('a', 40), str_repeat('b', 40), $g['drafts']['d1']),
            $approvals['d1']
        );
    }

    public function testAnyMissingProofYieldsNoDigests(): void
    {
        $g = $this->good();
        $cases = [
            'no access' => [null, $g['pull'], $g['drafts'], 'acme/repo'],
            'non-integer user' => [['userId' => '7'], $g['pull'], $g['drafts'], 'acme/repo'],
            'no pull' => [$g['access'], null, $g['drafts'], 'acme/repo'],
            'wrong repo' => [$g['access'], array_merge($g['pull'], ['repository' => 'acme/other']), $g['drafts'], 'acme/repo'],
            'unconfigured' => [$g['access'], $g['pull'], $g['drafts'], null],
            'grant missing repo' => [['userId' => 7, 'repositories' => []], $g['pull'], $g['drafts'], 'acme/repo'],
            'missing number' => [$g['access'], array_merge($g['pull'], ['number' => null]), $g['drafts'], 'acme/repo'],
            'missing head' => [$g['access'], array_merge($g['pull'], ['headSha' => null]), $g['drafts'], 'acme/repo'],
            'drafts not array' => [$g['access'], $g['pull'], null, 'acme/repo'],
        ];
        foreach ($cases as $name => [$access, $pull, $drafts, $configured]) {
            self::assertSame([], CommentApprovalDigests::forDrafts($access, $pull, $drafts, $configured), $name);
        }
    }
}
