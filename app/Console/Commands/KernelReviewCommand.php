<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Support\KernelCli;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Reviews one pull request revision on demand. Public diffs only, stays
 * local-only like automatic reviews: nothing is ever posted to GitHub.
 */
final class KernelReviewCommand extends Command
{
    protected $signature = 'kernel:review
                            {target? : Pull request as owner/repo#123 or a github.com pull URL}';

    protected $description = 'Review one pull request revision in the terminal';

    private GitHubAppClient $github;

    private AutoReviewService $service;

    public function __construct(?GitHubAppClient $github = null, ?AutoReviewService $service = null)
    {
        parent::__construct();
        $this->github = $github ?? new GitHubAppClient(HttpClient::create());
        $this->service = $service ?? new AutoReviewService();
    }

    public function handle(): int
    {
        $token = GitHubTokenStore::getToken();
        if ($token === null || $token === '') {
            $this->error(KernelCli::report('kernel:review · cannot start', 'no GitHub token stored', 'php artisan github:connect'));
            return self::FAILURE;
        }

        $target = $this->argument('target');
        if (!is_string($target) || trim($target) === '') {
            $this->error(KernelCli::report('kernel:review · cannot start', 'no pull request target given', 'kernel:review owner/repo#123'));
            return self::FAILURE;
        }
        $parsed = KernelCli::parsePrTarget($target);
        if ($parsed === null) {
            $this->error(KernelCli::report('kernel:review · cannot start', 'expected a target like owner/repo#123 or https://github.com/owner/repo/pull/123', 'kernel:review owner/repo#123'));
            return self::FAILURE;
        }

        $state = KernelCli::connectState();
        $authorized = [];
        foreach ($state['repos'] as $row) {
            $authorized[strtolower($row['fullName'])] = $row['fullName'];
        }
        if (!isset($authorized[strtolower($parsed['repo'])])) {
            $this->error(KernelCli::report('kernel:review · cannot start', "{$parsed['repo']} is not in the current GitHub grant", 'php artisan kernel:select-repo --list'));
            return self::FAILURE;
        }
        $repo = $authorized[strtolower($parsed['repo'])];
        $number = $parsed['number'];

        if (!RepositoryAutoReviewSetting::isRepoEnabled($repo)) {
            $this->error(KernelCli::report('kernel:review · cannot start', "auto-review is off for {$repo}", "php artisan kernel:select-repo --enable-review {$repo}"));
            return self::FAILURE;
        }

        try {
            $patch = $this->github->fetchPullRequestPatch($token, $repo, $number);
        } catch (\Throwable $e) {
            $this->error(KernelCli::report("kernel:review · cannot load {$repo}#{$number}", $e->getMessage(), 'check the PR exists and re-run'));
            return self::FAILURE;
        }

        if (($patch['isPrivate'] ?? true) === true) {
            $this->error(KernelCli::report("kernel:review · cannot start", "{$repo}#{$number} is private or its visibility is uncertain. Only public diffs are reviewed.", 'review a public pull request'));
            return self::FAILURE;
        }

        $this->line("Reviewing {$repo}#{$number} — {$patch['title']} ...");

        try {
            $outcome = $this->service->reviewPullRevision(
                $repo,
                $number,
                (string) $patch['headSha'],
                (string) $patch['baseSha'],
                (string) $patch['title']
            );
        } catch (\Throwable $e) {
            $this->error(KernelCli::report('kernel:review · failed', $e->getMessage(), 'fix the cause above, then re-run kernel:review'));
            return self::FAILURE;
        }

        $status = (string) ($outcome['status'] ?? 'failed');
        if ($status === 'completed') {
            $this->printFinding($repo, $number, $outcome);
            return self::SUCCESS;
        }
        if (str_starts_with($status, 'skipped_')) {
            $reason = (string) ($outcome['reason'] ?? $outcome['error'] ?? 'skipped');
            $this->warn("{$repo}#{$number}: {$status} — {$reason}");
            return self::SUCCESS;
        }

        $this->error(KernelCli::report("kernel:review · {$repo}#{$number} failed", (string) ($outcome['error'] ?? 'unknown error'), 'fix the cause above, then re-run kernel:review'));
        return self::FAILURE;
    }

    /** @param array<string, mixed> $outcome */
    private function printFinding(string $repo, int $number, array $outcome): void
    {
        $recommendation = (string) ($outcome['recommendation'] ?? 'no recommendation');
        $risk = (string) ($outcome['riskLevel'] ?? 'unknown risk');

        $summary = null;
        $findingsCount = 0;
        try {
            $record = AutoReviewResult::findForRevision(
                $repo,
                $number,
                (string) ($outcome['headSha'] ?? ''),
                (string) ($outcome['baseSha'] ?? '')
            );
            if ($record !== null) {
                $summary = is_string($record->summary) ? $record->summary : null;
                $findings = $record->file_findings;
                $findingsCount = is_array($findings) ? count($findings) : 0;
            }
        } catch (\Throwable) {
        }

        $this->info("{$repo}#{$number}: {$recommendation} · {$risk} risk");
        if ($summary !== null && trim($summary) !== '') {
            $this->line(trim($summary));
        }
        $this->line("file findings: {$findingsCount}");
        $this->line('Review stays in ProofPatcher (local only) — nothing was posted to GitHub.');
    }
}
