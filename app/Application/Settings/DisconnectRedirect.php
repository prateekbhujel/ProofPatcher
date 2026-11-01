<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Allowlisted post-disconnect redirect target. Only first-party app paths
 * pass through; anything else falls back to the repositories page so a
 * crafted redirect_to can never bounce the browser off-site.
 */
final class DisconnectRedirect
{
    public static function target(mixed $redirectTo): string
    {
        $redirectTo = (string) $redirectTo;

        return in_array($redirectTo, ['/issues', '/repositories', '/settings', '/checks'], true)
            ? $redirectTo
            : '/repositories';
    }
}
