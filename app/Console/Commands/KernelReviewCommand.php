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
                            {target : Pull request as owner/repo#123 or a github.com pull URL}';

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
            $this->error('No GitHub token found. Run php artisan github:connect first.');
            return self::FAILURE;
        }

        $parsed = KernelCli::parsePrTarget((string) $this->argument('target'));
        if ($parsed === null) {
            $this->error('Expected a target like owner/repo#123 or https://github.com/owner/repo/pull/123.');
            return self::FAILURE;
        }

        $state = KernelCli::connectState();
        $authorized = [];
        foreach ($state['repos'] as $row) {
            $authorized[strtolower($row['fullName'])] = $row['fullName'];
        }
        if (!isset($authorized[strtolower($parsed['repo'])])) {
            $this->error("{$parsed['repo']} is not in the current GitHub grant.");
            return self::FAILURE;
        }
        $repo = $authorized[strtolower($parsed['repo'])];
        $number = $parsed['number'];

        if (!RepositoryAutoReviewSetting::isRepoEnabled($repo)) {
            $this->error("Auto-review is off for {$repo}. Enable it: php artisan kernel:select-repo --enable-review {$repo}");
            return self::FAILURE;
        }

        try {
            $patch = $this->github->fetchPullRequestPatch($token, $repo, $number);
        } catch (\Throwable $e) {
            $this->error("Could not load {$repo}#{$number}: " . $e->getMessage());
            return self::FAILURE;
        }

        if (($patch['isPrivate'] ?? true) === true) {
            $this->error("{$repo}#{$number} is private or its visibility is uncertain. Only public diffs are reviewed.");
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
            $this->error('Review failed: ' . $e->getMessage());
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

        $this->error("{$repo}#{$number}: review failed — " . (string) ($outcome['error'] ?? 'unknown error'));
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
