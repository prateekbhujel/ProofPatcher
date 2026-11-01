<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Classifies the stored device-flow session before polling: missing shape,
 * expired code, or ready. Pure decision over the session value and now; the
 * caller owns redirects and session clearing.
 */
final class DeviceFlowSession
{
    /** @return 'missing'|'expired'|null */
    public static function check(mixed $flow, int $now): ?string
    {
        if (!is_array($flow) || !isset($flow['clientId'], $flow['deviceCode'])) {
            return 'missing';
        }
        if ($now > ($flow['expiresAt'] ?? 0)) {
            return 'expired';
        }

        return null;
    }
}
