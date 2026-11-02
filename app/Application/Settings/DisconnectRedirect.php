<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Post-disconnect redirect target. Kept for its callers; the allowlist
 * itself lives in AppRedirectTarget, shared with the connect flow.
 */
final class DisconnectRedirect
{
    public static function target(mixed $redirectTo): string
    {
        return AppRedirectTarget::target($redirectTo, '/repositories');
    }
}
