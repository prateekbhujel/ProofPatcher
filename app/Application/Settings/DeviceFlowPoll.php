<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Interprets one device-flow poll result as a single outcome. Authorized
 * requires the access token to be present; anything unrecognized is an
 * error. Pure decision; the caller owns network follow-ups and sessions.
 */
final class DeviceFlowPoll
{
    /** @param array<string, mixed> $result @return 'authorized'|'pending'|'slow_down'|'expired'|'denied'|'error' */
    public static function outcome(array $result): string
    {
        $status = $result['status'];
        if ($status === 'authorized' && isset($result['accessToken'])) {
            return 'authorized';
        }
        if (in_array($status, ['pending', 'slow_down', 'expired', 'denied'], true)) {
            return $status;
        }

        return 'error';
    }
}
