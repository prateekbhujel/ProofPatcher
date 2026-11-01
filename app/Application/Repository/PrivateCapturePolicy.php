<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Decides whether a private repository capture is allowed. Pure policy: the
 * caller supplies the server-session flag, the rechecked access grant, the
 * session identity, and the configured test repo, so this layer never reads
 * config, sessions, or the network.
 */
final class PrivateCapturePolicy
{
    public const DENIAL = 'Private repository capture is disabled. ProofPatch operates strictly on public repositories to prevent proprietary code leakage in shared local snapshot stores.';

    /** @param array<string, mixed>|null $access */
    public static function denial(
        bool $private,
        bool $serverSessions,
        ?array $access,
        mixed $authUserId,
        string $repoName,
        ?string $configuredRepo,
    ): ?string {
        if (!$private) {
            return null;
        }
        if (!$serverSessions || !is_array($access)
            || !is_int($access['userId'] ?? null) || $access['userId'] < 1
            || $authUserId !== $access['userId']
            || !is_array($access['repositories'] ?? null) || !isset($access['repositories'][strtolower($repoName)])
            || !is_string($configuredRepo) || strcasecmp($configuredRepo, $repoName) !== 0) {
            return self::DENIAL;
        }

        return null;
    }

    /**
     * Whether the repositories page may offer private capture for the selected
     * repo: the grant is rechecked, the selection matches the configured test
     * repo, and server sessions can hold the private pull state.
     */
    public static function canCapturePrivateTestRepo(
        mixed $access,
        mixed $selectedRepo,
        ?string $configuredRepo,
        bool $serverSessions,
    ): bool {
        return is_array($access)
            && is_int($access['userId'] ?? null)
            && $configuredRepo !== null
            && is_string($selectedRepo)
            && strcasecmp((string) $selectedRepo, (string) $configuredRepo) === 0
            && isset($access['repositories'][strtolower((string) $configuredRepo)])
            && $serverSessions;
    }
}
