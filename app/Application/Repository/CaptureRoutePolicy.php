<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

use ProofPatch\GitHub\PullRequestReader;

/**
 * Routes a submitted pull-request target: when it addresses the configured
 * private test repo, capture must use the authenticated session-only path
 * rather than the public reader. Pure decision; the caller owns the merge
 * into the capture request.
 */
final class CaptureRoutePolicy
{
    /** @return array{repository: string, number: int}|null */
    public static function privateTestRepoTarget(string $url, ?string $configured): ?array
    {
        if (!is_string($configured)) {
            return null;
        }
        $normalized = PullRequestReader::normalizeUrl($url);
        if (!preg_match('~\Ahttps://github\.com/([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})/pull/([1-9][0-9]{0,8})/?\z~', $normalized, $match)) {
            return null;
        }
        if (strcasecmp($match[1].'/'.$match[2], $configured) !== 0) {
            return null;
        }

        return ['repository' => $configured, 'number' => (int) $match[3]];
    }
}
