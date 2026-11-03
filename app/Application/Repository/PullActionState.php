<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Pure state decisions for pull-request merge/close actions: the entry guard
 * plus the post-action session-state updates. The caller owns the GitHub
 * calls, sessions, and redirects.
 */
final class PullActionState
{
    public const GUARD_ERROR = 'Connect GitHub, load the pull request and confirm the action.';

    /** Grant membership only; GitHub still decides whether the user may write. */
    public static function hasRepositoryGrant(mixed $pull, mixed $access): bool
    {
        $repository = is_array($pull) ? ($pull['repository'] ?? null) : null;
        $userId = is_array($access) ? ($access['userId'] ?? null) : null;
        $repositories = is_array($access) ? ($access['repositories'] ?? null) : null;
        return is_string($repository) && $repository !== ''
            && is_int($userId) && $userId > 0 && is_array($repositories)
            && ($repositories[strtolower($repository)] ?? false) === true;
    }

    public static function guardError(mixed $confirmed, mixed $auth, mixed $pull, mixed $access): ?string
    {
        if ($confirmed !== true || !is_array($auth) || !is_string($auth['token'] ?? null)
            || !is_array($pull) || !is_array($access)) {
            return self::GUARD_ERROR;
        }

        return null;
    }

    /** @param array<string, mixed> $pull @return array<string, mixed> */
    public static function appliedPull(array $pull, string $action): array
    {
        if ($action === 'merge') {
            $pull['state'] = 'merged';
            $pull['isMerged'] = true;
        } else {
            $pull['state'] = 'closed';
        }

        return $pull;
    }

    /**
     * @param array<int, mixed> $repoPulls
     * @return array<int, mixed>
     */
    public static function syncedRepoPulls(array $repoPulls, int $number, string $state): array
    {
        foreach ($repoPulls as &$rp) {
            if (is_array($rp) && ($rp['number'] ?? null) === $number) {
                $rp['state'] = $state;
                $rp['isMerged'] = $state === 'merged';
            }
        }
        unset($rp);

        return $repoPulls;
    }
}
