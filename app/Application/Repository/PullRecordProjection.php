<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Builds the pull request record strictly from authoritative GitHub metadata.
 * Pure projection: missing required keys throw, exactly as before, so the
 * caller keeps its single failure path.
 */
final class PullRecordProjection
{
    /** @param array<string, mixed> $patchPreview @return array<string, mixed> */
    public static function fromPatchPreview(array $patchPreview, string $repoName, int $number, bool $private): array
    {
        return [
            'repository' => $repoName,
            'number' => $number,
            'title' => $patchPreview['title'],
            'body' => $patchPreview['body'] ?? '',
            'state' => $patchPreview['state'],
            'isMerged' => $patchPreview['isMerged'] ?? false,
            'isDraft' => ($patchPreview['isDraft'] ?? false) === true,
            'head' => $patchPreview['head'],
            'base' => $patchPreview['base'],
            'headSha' => $patchPreview['headSha'],
            'baseSha' => $patchPreview['baseSha'],
            'url' => $patchPreview['url'],
            'user' => $patchPreview['user'] ?? 'unknown',
            'isPrivate' => $private,
            'changedFiles' => $patchPreview['changedFiles'],
            'files' => array_map(static fn(array $f): array => [
                'name' => $f['name'],
                'status' => $f['status'],
                'additions' => (int) ($f['additions'] ?? 0),
                'deletions' => (int) ($f['deletions'] ?? 0),
            ], $patchPreview['files']),
        ];
    }
}
