<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Support\KernelCli;

/**
 * Selects the working repository for the kernel terminal surface. Only
 * repositories inside the current GitHub token grant can be selected, and
 * auto-review can only be toggled for those same repositories.
 */
final class KernelSelectRepoCommand extends Command
{
    protected $signature = 'kernel:select-repo
                            {repo? : Repository handle in owner/repo form}
                            {--list : List authorized repositories instead of selecting}
                            {--enable-review : Enable automatic review for the repository}
                            {--disable-review : Disable automatic review for the repository}';

    protected $description = 'Select the working repository for the kernel terminal';

    public function handle(): int
    {
        $state = KernelCli::connectState();
        if (!$state['connected']) {
            $this->error(KernelCli::report('kernel:select-repo · cannot start', 'not connected', 'php artisan github:connect'));
            return self::FAILURE;
        }

        $authorized = [];
        foreach ($state['repos'] as $repo) {
            $authorized[strtolower($repo['fullName'])] = $repo['fullName'];
        }
        if ($authorized === []) {
            $this->error(KernelCli::report('kernel:select-repo · cannot start', 'no repositories in the current GitHub grant', 'install the GitHub App on a repository, then re-run github:connect'));
            return self::FAILURE;
        }

        if ((bool) $this->option('list')) {
            $this->listRepositories($authorized);
            return self::SUCCESS;
        }

        $repo = $this->argument('repo');
        if (!is_string($repo) || trim($repo) === '') {
            $repo = $this->choice('Select a repository', array_values($authorized));
        }
        $repo = trim((string) $repo);

        if (!KernelCli::isValidRepo($repo)) {
            $this->error(KernelCli::report('kernel:select-repo · cannot start', "expected a repository handle in owner/repo form, got '{$repo}'", 'php artisan kernel:select-repo --list'));
            return self::FAILURE;
        }
        if (!isset($authorized[strtolower($repo)])) {
            $this->error(KernelCli::report('kernel:select-repo · cannot start', "{$repo} is not in the current GitHub grant. Authorized: " . implode(', ', array_values($authorized)) . '.', 'php artisan kernel:select-repo --list'));
            return self::FAILURE;
        }
        $repo = $authorized[strtolower($repo)];

        KernelCli::setSelectedRepo($repo);

        $reviewNote = '';
        if ((bool) $this->option('enable-review') && (bool) $this->option('disable-review')) {
            $this->error(KernelCli::report('kernel:select-repo · cannot start', 'pass only one of --enable-review and --disable-review', 're-run with a single flag'));
            return self::FAILURE;
        }
        if ((bool) $this->option('enable-review')) {
            RepositoryAutoReviewSetting::setRepoEnabled($repo, true);
            $reviewNote = ' — auto-review enabled';
        } elseif ((bool) $this->option('disable-review')) {
            RepositoryAutoReviewSetting::disableForRepository($repo);
            $reviewNote = ' — auto-review disabled';
        } else {
            $reviewNote = RepositoryAutoReviewSetting::isRepoEnabled($repo) ? ' — auto-review enabled' : ' — auto-review off';
        }

        $this->info("Selected {$repo}{$reviewNote}.");
        if (!RepositoryAutoReviewSetting::isRepoEnabled($repo)) {
            $this->line("Enable reviews: php artisan kernel:select-repo --enable-review {$repo}");
        } else {
            $this->line("Next: php artisan kernel:autopilot --repo={$repo} --minutes=10");
        }

        return self::SUCCESS;
    }

    /** @param array<string, string> $authorized */
    private function listRepositories(array $authorized): void
    {
        $selected = KernelCli::getSelectedRepo();
        $rows = [];
        foreach ($authorized as $lower => $name) {
            $markers = [];
            if ($selected !== null && strtolower($selected) === $lower) {
                $markers[] = 'selected';
            }
            $markers[] = RepositoryAutoReviewSetting::isRepoEnabled($name) ? 'review enabled' : 'review off';
            $rows[] = [$name, implode(', ', $markers)];
        }
        $this->table(['Repository', 'State'], $rows);
    }
}
