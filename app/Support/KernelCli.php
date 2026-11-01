<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use ProofPatch\GitHub\GitHubTokenStore;

/**
 * Shared helpers for the kernel terminal surface (kernel:* commands and the
 * interactive kernel shell). File state lives under storage/app with
 * owner-only permissions; the PHPUnit bootstrap redirects storage to an
 * isolated temp dir, so tests never touch developer state.
 */
final class KernelCli
{
    public const VERSION = 'v0.3';

    private const SELECTION_FILE = 'kernel_selected_repo';

    /** @return list<string> */
    public static function bootLines(): array
    {
        return [
            'ProofPatcher Kernel ' . self::VERSION . ' — terminal shell',
            'Review pull requests and check supported patches.',
        ];
    }

    public static function isValidRepo(string $repo): bool
    {
        $repo = trim($repo);
        if ($repo === '' || strlen($repo) > 100) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repo) === 1;
    }

    public static function selectionPath(): string
    {
        $dir = null;
        if (function_exists('storage_path')) {
            try {
                $dir = storage_path('app');
            } catch (\Throwable) {
                $dir = null;
            }
        }
        $dir ??= dirname(__DIR__, 2) . '/storage/app';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir . '/' . self::SELECTION_FILE;
    }

    public static function getSelectedRepo(): ?string
    {
        $path = self::selectionPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $repo = trim($raw);

        return self::isValidRepo($repo) ? $repo : null;
    }

    public static function setSelectedRepo(string $repo): void
    {
        $repo = trim($repo);
        if (!self::isValidRepo($repo)) {
            throw new \InvalidArgumentException('Expected an owner/repo repository handle.');
        }

        $path = self::selectionPath();
        file_put_contents($path, $repo . "\n", LOCK_EX);
        @chmod($path, 0600);
    }

    public static function clearSelectedRepo(): void
    {
        $path = self::selectionPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Parses `owner/repo#123` or a github.com pull URL.
     *
     * @return ?array{repo: string, number: int}
     */
    public static function parsePrTarget(string $target): ?array
    {
        $target = trim($target);
        if (preg_match('/\A([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)#(\d{1,9})\z/', $target, $m) === 1) {
            $number = (int) $m[2];

            return $number > 0 ? ['repo' => $m[1], 'number' => $number] : null;
        }

        if (!str_starts_with(strtolower($target), 'http')) {
            return null;
        }
        $parts = parse_url($target);
        if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
            return null;
        }
        if (preg_match('/\A\/([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)\/pull\/(\d{1,9})(?:\/.*)?\z/', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }
        $number = (int) $m[2];

        return $number > 0 ? ['repo' => $m[1], 'number' => $number] : null;
    }

    /**
     * One error language for the whole terminal surface: every failure names
     * where it happened, why, and the single fix. Pure so both commands and
     * the shell render identical reports.
     */
    public static function report(string $where, string $why, ?string $fix = null): string
    {
        $lines = ['✕ ' . trim($where), '  why: ' . trim($why)];
        if ($fix !== null && trim($fix) !== '') {
            $lines[] = '  fix: ' . trim($fix);
        }

        return implode("\n", $lines);
    }

    /**
     * Autopilot preconditions, checked before any prompt. Returns null when
     * the run can start, otherwise the blocker as why + fix.
     *
     * @param list<string> $authorizedLower
     * @return ?array{why: string, fix: string}
     */
    public static function autopilotBlocker(?string $repo, array $authorizedLower, bool $reviewEnabled): ?array
    {
        if ($repo === null || trim($repo) === '') {
            return ['why' => 'no repository selected', 'fix' => 'php artisan kernel:select-repo'];
        }
        $repo = trim($repo);
        if (!self::isValidRepo($repo)) {
            return ['why' => "expected owner/repo, got '{$repo}'", 'fix' => 'php artisan kernel:select-repo --list'];
        }
        $allowed = array_fill_keys($authorizedLower, true);
        if (!isset($allowed[strtolower($repo)])) {
            return ['why' => "{$repo} is not in the current GitHub grant", 'fix' => 'php artisan kernel:select-repo --list'];
        }
        if (!$reviewEnabled) {
            return ['why' => "auto-review is off for {$repo}", 'fix' => "php artisan kernel:select-repo --enable-review {$repo}"];
        }

        return null;
    }

    /**
     * @return array{connected: bool, user: ?array{login: ?string}, repos: list<array{fullName: string}>}
     */
    public static function connectState(): array
    {
        $state = ['connected' => false, 'user' => null, 'repos' => []];
        try {
            $auth = GitHubTokenStore::get();
        } catch (\Throwable) {
            return $state;
        }
        if (!is_array($auth) || !isset($auth['token']) || !is_string($auth['token']) || trim($auth['token']) === '') {
            return $state;
        }
        $repos = [];
        if (is_array($auth['repositories'] ?? null)) {
            foreach ($auth['repositories'] as $repo) {
                if (is_array($repo) && is_string($repo['fullName'] ?? null) && trim($repo['fullName']) !== '') {
                    $repos[] = ['fullName' => trim($repo['fullName']), 'isPrivate' => is_bool($repo['isPrivate'] ?? null) ? $repo['isPrivate'] : null];
                }
            }
        }

        return [
            'connected' => true,
            'user' => ['login' => is_array($auth['user'] ?? null) ? ($auth['user']['login'] ?? null) : null],
            'repos' => $repos,
        ];
    }
}
