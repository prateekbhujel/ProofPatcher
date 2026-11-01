<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

use ProofPatch\GitHub\ManualInlineCommentPublisher;

/**
 * Builds one approval digest per commentable draft, but only when the loaded
 * pull request is on the configured test repo, the access grant covers it,
 * and the revision SHAs are known. Anything else yields no digests, so the
 * manual-post buttons stay inert. Pure policy; the caller owns sessions.
 */
final class CommentApprovalDigests
{
    /** @return array<string, string> */
    public static function forDrafts(mixed $access, mixed $pull, mixed $drafts, ?string $configured): array
    {
        $approvals = [];
        $repoName = is_array($pull) ? ($pull['repository'] ?? null) : null;
        if (!is_array($access) || !is_int($access['userId'] ?? null) || !is_string($repoName)
            || !is_string($configured) || strcasecmp($repoName, $configured) !== 0
            || !isset($access['repositories'][strtolower($repoName)]) || !is_int($pull['number'] ?? null)
            || !is_string($pull['headSha'] ?? null) || !is_string($pull['baseSha'] ?? null) || !is_array($drafts)) {
            return [];
        }
        foreach ($drafts as $draft) {
            if (is_array($draft) && is_string($draft['id'] ?? null) && ($draft['commentable'] ?? false) === true) {
                $approvals[$draft['id']] = ManualInlineCommentPublisher::digest(
                    $access['userId'], $repoName, $pull['number'], $pull['headSha'], $pull['baseSha'], $draft
                );
            }
        }

        return $approvals;
    }
}
