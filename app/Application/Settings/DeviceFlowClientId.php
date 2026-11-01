<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Resolves the GitHub App client ID for device-flow initiation: explicit
 * form input first, then the primary and fallback env vars. Pure over
 * caller-supplied values; fails closed with the setup hint when empty.
 */
final class DeviceFlowClientId
{
    public static function resolve(mixed $input, mixed $primary, mixed $fallback): string
    {
        $clientId = trim((string) ($input ?: $primary ?: $fallback ?: ''));
        if ($clientId === '') {
            throw new \InvalidArgumentException('A GitHub App Client ID is required. Set PROOFPATCH_GITHUB_CLIENT_ID or provide it in the form.');
        }

        return $clientId;
    }
}
