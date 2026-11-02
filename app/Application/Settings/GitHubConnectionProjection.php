<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Projects the GitHub connection view-model shared by the repositories and
 * settings pages. Pure projection: the caller supplies session values, env
 * values, and flash messages, plus optional page extras that are inserted
 * between the repo selection and the device-flow block, preserving prop order.
 */
final class GitHubConnectionProjection
{
    /**
     * @param array{auth: mixed, deviceFlow: mixed, clientIdConfigured: bool, clientId: mixed, selectedRepo: mixed, statusMessage: mixed, errorMessage: mixed, extras?: array<string, mixed>} $ctx
     * @return array<string, mixed>
     */
    public static function build(array $ctx): array
    {
        $githubAuth = $ctx['auth'];
        $deviceFlow = $ctx['deviceFlow'];

        return [
            'isConfigured' => $ctx['clientIdConfigured'],
            'clientId' => $ctx['clientId'],
            'isConnected' => GitHubConnectionState::isConnected($githubAuth),
            'user' => is_array($githubAuth) ? ($githubAuth['user'] ?? null) : null,
            'repositories' => is_array($githubAuth) ? ($githubAuth['repositories'] ?? []) : [],
            'repositoriesStatus' => is_array($githubAuth) ? ($githubAuth['repositoriesStatus'] ?? null) : null,
            'selectedRepo' => $ctx['selectedRepo'],
            ...($ctx['extras'] ?? []),
            'deviceFlow' => is_array($deviceFlow) ? [
                'userCode' => (string) ($deviceFlow['userCode'] ?? ''),
                'verificationUri' => (string) ($deviceFlow['verificationUri'] ?? ''),
                'expiresAt' => (int) ($deviceFlow['expiresAt'] ?? 0),
                'interval' => (int) ($deviceFlow['interval'] ?? 5),
            ] : null,
            'statusMessage' => $ctx['statusMessage'],
            'errorMessage' => $ctx['errorMessage'],
        ];
    }
}
