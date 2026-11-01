<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

use ProofPatch\GitHub\DiffHunkParser;

/**
 * Builds one anchored review draft from validated input. Pure domain logic:
 * no sessions, no HTTP, no network (the caller resolves the patch preview).
 */
final class SaveDraftCommentUseCase
{
    public function execute(SaveDraftCommentInput $in): SaveDraftCommentResult
    {
        if ($in->patchPreview === null) {
            return new SaveDraftCommentResult(false, [], 'Open the pull request again before adding comments.');
        }
        $filePatch = null;
        foreach ($in->patchPreview['files'] ?? [] as $file) {
            if (($file['name'] ?? null) === $in->path) {
                $filePatch = $file['patch'] ?? null;
                break;
            }
        }

        $anchor = DiffHunkParser::validateAnchor($filePatch, $in->line, $in->headSha, (string) ($in->pull['headSha'] ?? ''), $in->side);

        // A positive lineHint alone is not proof it is commentable:
        // if a line number is specified, it MUST be commentable within the captured diff hunk.
        if ($in->line !== null && !$anchor['commentable']) {
            return new SaveDraftCommentResult(false, [], "Line {$in->line} is not commentable on {$in->side} side: {$anchor['message']}");
        }

        $draftId = hash('sha256', $in->path.':'.($in->line ?? 'file').':'.$in->headSha);
        return new SaveDraftCommentResult(true, [
            'id' => $draftId,
            'path' => $in->path,
            'line' => $in->line,
            'side' => $in->side,
            'comment' => $in->comment,
            'headSha' => $in->headSha,
            'status' => $anchor['status'],
            'valid' => $anchor['valid'],
            'commentable' => $anchor['commentable'],
            'lineType' => $anchor['lineType'],
            'message' => $anchor['message'],
            'updatedAt' => gmdate(DATE_ATOM),
        ], '');
    }
}
