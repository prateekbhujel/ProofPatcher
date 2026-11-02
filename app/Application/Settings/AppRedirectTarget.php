<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Allowlisted in-app redirect target for the GitHub connect and disconnect
 * flows. Only first-party app paths pass through; anything else falls back
 * so a crafted redirect_to can never bounce the browser off-site.
 */
final class AppRedirectTarget
{
    private const ALLOWED = ['/', '/issues', '/repositories', '/settings', '/checks'];

    public static function target(mixed $redirectTo, string $fallback = '/repositories'): string
    {
        $redirectTo = is_string($redirectTo) ? $redirectTo : '';
        if (in_array($redirectTo, self::ALLOWED, true)) {
            return $redirectTo;
        }

        return in_array($fallback, self::ALLOWED, true) ? $fallback : '/repositories';
    }
}
