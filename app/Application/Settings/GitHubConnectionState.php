<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * One reading of the web session's GitHub connection state.
 *
 * The session keeps a stale token after GitHub rejects it (the middleware
 * nulls the user and repositories but leaves the token so a retry can
 * re-verify). Every surface that shows "connected" must therefore require a
 * verified user, not just a token, or it will claim a dead sign-in works.
 */
final class GitHubConnectionState
{
    public static function isConnected(mixed $auth): bool
    {
        if (!is_array($auth)) {
            return false;
        }
        $token = $auth['token'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            return false;
        }
        $user = $auth['user'] ?? null;

        return is_array($user) && $user !== [];
    }
}
