<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;

/**
 * Decides whether issue context may leave the server for a model request.
 *
 * Pure decision logic over plain data: the verified server-side grant, the
 * session authentication snapshot, and the issue context. Synthetic fixtures
 * and confirmed-public repositories pass; anything else stays local except
 * the dedicated private test case, which additionally needs a server-held
 * session, the pinned issue and file, and a matching user identity.
 */
final class ModelContextPolicy
{
    public static function canSend(?array $sessionAuth, mixed $access, array $context, bool $usesServerSession = true): bool
    {
        if (($context['isSynthetic'] ?? false) === true) {
            return true;
        }

        $repo = $context['repository'] ?? null;
        if (!is_array($access) || !is_int($access['userId'] ?? null) || !is_string($repo)
            || !isset($access['repositories'][strtolower($repo)]) || !is_array($sessionAuth['repositories'] ?? null)) {
            return false;
        }
        foreach ($sessionAuth['repositories'] as $repository) {
            if (is_array($repository) && is_string($repository['fullName'] ?? null)
                && strcasecmp($repository['fullName'], $repo) === 0) {
                if (($repository['isPrivate'] ?? null) === false) {
                    return true;
                }
                $dedicated = DedicatedTestRepo::name();
                return ($repository['isPrivate'] ?? null) === true && is_string($dedicated)
                    && $usesServerSession
                    && strcasecmp($repo, $dedicated) === 0
                    && ($context['issueNumber'] ?? null) === DedicatedTestRepoFetcher::ISSUE_NUMBER
                    && ($context['path'] ?? null) === DedicatedTestRepoFetcher::SOURCE_PATH
                    && ($sessionAuth['user']['id'] ?? null) === $access['userId'];
            }
        }

        return false;
    }

    public static function isPrivateDedicated(?array $sessionAuth, array $context): bool
    {
        foreach (is_array($sessionAuth['repositories'] ?? null) ? $sessionAuth['repositories'] : [] as $repo) {
            if (is_array($repo) && is_string($repo['fullName'] ?? null)
                && is_string($context['repository'] ?? null)
                && strcasecmp($repo['fullName'], $context['repository']) === 0) {
                return ($repo['isPrivate'] ?? null) === true;
            }
        }

        return false;
    }
}
