<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Support\KernelCli;

/**
 * Terminal twin of the web disconnect: clears the stored GitHub sign-in and
 * the shared repository selection, and revokes posting consent for the
 * stored account. Never prints the token. Web sessions already open keep
 * their in-memory session until they disconnect or it expires.
 */
final class GitHubDisconnectCommand extends Command
{
    protected $signature = 'github:disconnect';

    protected $description = 'Disconnect GitHub for CLI and web (clears the stored sign-in)';

    public function handle(): int
    {
        try {
            $previous = GitHubTokenStore::get();
        } catch (\Throwable) {
            $previous = null;
        }

        if (!is_array($previous) || !is_string($previous['token'] ?? null) || trim($previous['token']) === '') {
            $this->line('Not connected — nothing to clear.');
            return self::SUCCESS;
        }

        $login = is_array($previous['user'] ?? null) ? ($previous['user']['login'] ?? null) : null;
        $userId = is_array($previous['user'] ?? null) ? ($previous['user']['id'] ?? null) : null;

        try {
            if (is_int($userId) && $userId > 0 && \Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                \ProofPatch\Models\AutomaticInlineSetting::revokeAllForUser($userId);
            }
        } catch (\Throwable) {
        }

        GitHubTokenStore::clear();
        KernelCli::clearSelectedRepo();

        $this->info(is_string($login) && $login !== '' ? "Disconnected @{$login}. Stored sign-in cleared." : 'Disconnected. Stored sign-in cleared.');

        return self::SUCCESS;
    }
}
