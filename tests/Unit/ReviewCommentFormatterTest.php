<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\ReviewCommentFormatter;

final class ReviewCommentFormatterTest extends TestCase
{
    public function testLabelMap(): void
    {
        self::assertSame('issue', ReviewCommentFormatter::label(['status' => 'concern']));
        self::assertSame('nitpick', ReviewCommentFormatter::label(['status' => 'note']));
        self::assertSame('praise', ReviewCommentFormatter::label(['status' => 'approval']));
        self::assertSame('note', ReviewCommentFormatter::label(['status' => 'bogus']));
    }

    public function testBlockingRule(): void
    {
        self::assertTrue(ReviewCommentFormatter::blocking(['status' => 'concern'], 'request_changes'));
        self::assertFalse(ReviewCommentFormatter::blocking(['status' => 'concern'], 'approve'));
        self::assertNull(ReviewCommentFormatter::blocking(['status' => 'concern'], null));
        self::assertFalse(ReviewCommentFormatter::blocking(['status' => 'note'], 'request_changes'));
        self::assertFalse(ReviewCommentFormatter::blocking(['status' => 'approval'], 'request_changes'));
    }

    public function testSplitSubjectDiscussion(): void
    {
        ['subject' => $subject, 'discussion' => $discussion] = ReviewCommentFormatter::split(
            ['comment' => 'Null dereference when empty. The loop assumes a row exists.']
        );
        self::assertSame('Null dereference when empty.', $subject);
        self::assertSame('The loop assumes a row exists.', $discussion);
    }

    public function testLongSubjectTruncatesButKeepsFullText(): void
    {
        $long = str_repeat('word ', 50).'End. Tail discussion here.';
        ['subject' => $subject, 'discussion' => $discussion] = ReviewCommentFormatter::split(['comment' => $long]);
        self::assertLessThanOrEqual(160, strlen($subject));
        self::assertStringEndsWith('…', $subject);
        self::assertSame(trim($long), $discussion);
    }

    public function testFormatBlockingIssueWithSuggestion(): void
    {
        $body = ReviewCommentFormatter::format(
            ['status' => 'concern', 'comment' => 'Overcharges customers. The sign is inverted.'],
            'request_changes',
            'return $a - $b;'
        );
        self::assertSame(
            "**issue:** Overcharges customers.\n\nThe sign is inverted.\n\n```suggestion\nreturn \$a - \$b;\n```",
            $body
        );
    }

    public function testFormatNonBlockingNitpickWithoutSuggestion(): void
    {
        $body = ReviewCommentFormatter::format(
            ['status' => 'note', 'comment' => 'Shorten this name.'],
            'approve',
            null
        );
        self::assertSame('**nitpick (non-blocking):** Shorten this name.', $body);
    }
}
