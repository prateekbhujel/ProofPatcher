<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * The one "what next" state machine for the connection pipeline, shared by
 * the Kernel home, the OS status feed, and the terminal status command so
 * every surface guides the user through the same steps: connect, grant,
 * select a repository, then open a target.
 */
final class ConnectionNextStep
{
    /** @return 'connect'|'grant'|'select_repo'|'target' */
    public static function key(bool $connected, int $reposCount, ?string $selectedRepo): string
    {
        if (!$connected) {
            return 'connect';
        }
        if ($reposCount === 0) {
            return 'grant';
        }
        if ($selectedRepo === null || trim($selectedRepo) === '') {
            return 'select_repo';
        }

        return 'target';
    }

    /** @return array{key: string, title: string, detail: string, href: string, cta: string} */
    public static function for(bool $connected, int $reposCount, ?string $selectedRepo): array
    {
        return match (self::key($connected, $reposCount, $selectedRepo)) {
            'connect' => [
                'key' => 'connect',
                'title' => 'Connect GitHub',
                'detail' => 'Link your account.',
                'href' => '/issues',
                'cta' => 'Continue with GitHub',
            ],
            'grant' => [
                'key' => 'grant',
                'title' => 'Grant repository access',
                'detail' => 'Install the GitHub App, then reconnect.',
                'href' => '/settings',
                'cta' => 'Open Settings',
            ],
            'select_repo' => [
                'key' => 'select_repo',
                'title' => 'Pick a target repository',
                'detail' => 'Choose a granted repository.',
                'href' => '/issues',
                'cta' => 'Choose a repository',
            ],
            default => [
                'key' => 'target',
                'title' => 'Open an issue or pull request',
                'detail' => 'Pick a target from the workbench.',
                'href' => '/issues',
                'cta' => 'Open the workbench',
            ],
        };
    }
}
