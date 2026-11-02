<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/**
 * Formats findings per docs/REVIEW_COMMENT_STYLE.md: Conventional-Comments
 * label, blocking decoration, subject/discussion split, suggestion fence.
 * Pure function of ($finding, $verdict); sanitization stays in the publisher.
 */
final class ReviewCommentFormatter
{
    private const LABELS = ['concern' => 'issue', 'note' => 'nitpick', 'approval' => 'praise'];

    /**
     * @param array{status?: mixed, comment?: mixed} $finding
     */
    public static function label(array $finding): string
    {
        $status = is_string($finding['status'] ?? null) ? $finding['status'] : '';
        return self::LABELS[$status] ?? 'note';
    }

    /**
     * @param array{status?: mixed, comment?: mixed} $finding
     */
    public static function blocking(array $finding, ?string $verdict): ?bool
    {
        if (!is_string($verdict)) {
            return null;
        }
        if (($finding['status'] ?? null) !== 'concern') {
            return false;
        }
        return $verdict === 'request_changes';
    }

    /**
     * @param array{status?: mixed, comment?: mixed} $finding
     * @return array{subject: string, discussion: string}
     */
    public static function split(array $finding): array
    {
        $comment = trim((string) ($finding['comment'] ?? ''));
        if ($comment === '') {
            return ['subject' => '', 'discussion' => ''];
        }
        $parts = preg_split('/(?<=[.?!])\s+/', $comment, 2);
        $subject = trim((string) ($parts[0] ?? $comment));
        $discussion = trim((string) ($parts[1] ?? ''));
        if (strlen($subject) > 160) {
            $subject = rtrim(mb_strcut($subject, 0, 157, 'UTF-8')).'…';
            $discussion = $comment;
        }
        return ['subject' => $subject, 'discussion' => $discussion];
    }

    /**
     * @param array{status?: mixed, comment?: mixed} $finding
     */
    public static function format(array $finding, ?string $verdict, ?string $suggestion): string
    {
        $label = self::label($finding);
        $blocking = self::blocking($finding, $verdict);
        $head = '**'.$label.':**';
        if ($blocking === false) {
            $head = '**'.$label.' (non-blocking):**';
        }
        ['subject' => $subject, 'discussion' => $discussion] = self::split($finding);
        $body = $head.' '.$subject;
        if ($discussion !== '' && $discussion !== $subject) {
            $body .= "\n\n".$discussion;
        }
        if (is_string($suggestion) && trim($suggestion) !== '') {
            $body .= "\n\n```suggestion\n".$suggestion."\n```";
        }
        return $body;
    }
}
