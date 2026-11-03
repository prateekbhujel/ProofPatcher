<?php

declare(strict_types=1);

namespace ProofPatch\Services;

use ProofPatch\Agent\Credentials;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\GitHub\AutomaticInlineCommentPublisher;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\AutomaticInlineSetting;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use Symfony\Component\HttpClient\HttpClient;

final class AutoReviewService
{
    public function __construct(
        private ?GitHubAppClient $github = null,
        private ?PublicPatchPreview $patchPreviewer = null,
        private ?PullRequestReview $reviewAgent = null,
        private ?RequestBudget $budget = null,
        private ?string $basetenKey = null,
        private ?AutomaticInlineCommentPublisher $automaticCommentPublisher = null,
        private ?MaintainerIssueWatcher $issueWatcher = null,
    ) {
        $this->github ??= new GitHubAppClient(HttpClient::create());
        $this->patchPreviewer ??= new PublicPatchPreview(HttpClient::create());
        $this->budget ??= RequestBudget::forRuntime();
        $this->reviewAgent ??= new PullRequestReview(HttpClient::create(), $this->budget);
    }

    /**
     * Executes a single polling cycle across all authorized repositories with auto-review enabled.
     *
     * @return array{
     *     status: string,
     *     reposChecked: int,
     *     pullsInspected: int,
     *     reviewsCompleted: int,
     *     reviewsSkipped: int,
     *     reviewsFailed: int,
     *     budgetExhausted: bool,
     *     followups?: list<string>,
     *     details: array<array<string, mixed>>,
     *     errors: array<string>
     * }
     */
    public function poll(?string $token = null, ?string $onlyRepo = null): array
    {
        $token = $token !== null && trim($token) !== '' ? trim($token) : GitHubTokenStore::getToken();

        if ($token === null || $token === '') {
            return [
                'status' => 'no_token',
                'reposChecked' => 0,
                'pullsInspected' => 0,
                'reviewsCompleted' => 0,
                'reviewsSkipped' => 0,
                'reviewsFailed' => 0,
                'budgetExhausted' => false,
                'details' => [],
                'errors' => ['No authorized GitHub token found. Connect via GitHub Device Flow in Settings.'],
            ];
        }

        // 1. Recheck GitHub identity and installed repository scope
        $authorizedRepos = [];
        try {
            $user = $this->github->getCurrentUser($token);
            if (!is_int($user['id'] ?? null) || $user['id'] <= 0) {
                throw new \RuntimeException('GitHub did not return a valid user identity.');
            }

            $status = [];
            $selectedRepos = $this->github->listSelectedRepositories($token, $status);
            if (($status['truncated'] ?? true) || !empty($status['errors'])) {
                throw new \RuntimeException('GitHub repository permissions could not be verified.');
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                AutomaticInlineSetting::retainOnlyAuthorizedPublic($user['id'], $selectedRepos);
            }
            foreach ($selectedRepos as $repo) {
                if (is_array($repo) && is_string($repo['fullName'] ?? null)) {
                    $authorizedRepos[strtolower($repo['fullName'])] = $repo;
                }
            }
        } catch (\Throwable $e) {
            // A CLI worker can observe a revoked token without any web session.
            // Only the identity stored alongside this *same* token may lose its
            // consent; an arbitrary failed token must not affect another user.
            $consentCleanupFailed = false;
            try {
                $stored = GitHubTokenStore::get();
                $storedToken = $stored['token'] ?? null;
                $storedUser = $stored['user']['id'] ?? null;
                if (is_string($storedToken) && hash_equals(trim($storedToken), $token)
                    && is_int($storedUser) && $storedUser > 0
                    && \Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                    AutomaticInlineSetting::revokeAllForUser($storedUser);
                }
            } catch (\Throwable) {
                $consentCleanupFailed = true;
            }
            return [
                'status' => 'auth_revoked',
                'reposChecked' => 0,
                'pullsInspected' => 0,
                'reviewsCompleted' => 0,
                'reviewsSkipped' => 0,
                'reviewsFailed' => 0,
                'budgetExhausted' => false,
                'details' => [],
                'errors' => array_merge(['GitHub authorization check failed: '.$e->getMessage()],
                    $consentCleanupFailed ? ['Automatic comment consent could not be cleared; reconnect and review settings before resuming.'] : []),
            ];
        }

        // 2. Determine repositories configured for automatic review
        $enabledRepos = RepositoryAutoReviewSetting::enabledRepositories();
        if ($onlyRepo !== null) {
            $enabledRepos = array_filter($enabledRepos, static fn(string $r): bool => strcasecmp($r, $onlyRepo) === 0);
        }

        $summary = [
            'status' => 'ok',
            'reposChecked' => 0,
            'pullsInspected' => 0,
            'reviewsCompleted' => 0,
            'reviewsSkipped' => 0,
            'reviewsFailed' => 0,
            'budgetExhausted' => false,
            'followups' => [],
            'details' => [],
            'errors' => [],
        ];
        if ($enabledRepos !== [] && !\Illuminate\Support\Facades\Schema::hasTable('review_followups')) {
            $summary['errors'][] = 'Reply tracking is unavailable until the database migrations are applied.';
        }

        foreach ($enabledRepos as $repoName) {
            $lowerRepo = strtolower($repoName);
            if (!isset($authorizedRepos[$lowerRepo])) {
                $summary['details'][] = [
                    'repository' => $repoName,
                    'action' => 'skipped_unauthorized',
                    'reason' => 'Repository is not authorized in current GitHub App installation scope.',
                ];
                continue;
            }

            $summary['reposChecked']++;

            // 3. List open pull requests with pagination support
            $pullsStatus = [];
            try {
                $pulls = $this->github->listOpenPullRequests($token, $repoName, $pullsStatus);
            } catch (\Throwable $e) {
                $summary['errors'][] = "Failed to list PRs for {$repoName}: ".$e->getMessage();
                continue;
            }

            if (!empty($pullsStatus['errors'])) {
                foreach ($pullsStatus['errors'] as $pe) {
                    $summary['errors'][] = "PR list error for {$repoName}: {$pe}";
                }
            }

            // 4. Process each pull request
            foreach ($pulls as $pull) {
                $summary['pullsInspected']++;
                $prNumber = (int) ($pull['number'] ?? 0);
                $headSha = (string) ($pull['headSha'] ?? '');
                $baseSha = (string) ($pull['baseSha'] ?? '');
                $title = (string) ($pull['title'] ?? '');
                $isPrivate = ($pull['isPrivate'] ?? true) === true;

                if ($prNumber <= 0 || !preg_match('/\A[a-f0-9]{40}\z/', $headSha) || !preg_match('/\A[a-f0-9]{40}\z/', $baseSha)) {
                    $summary['reviewsSkipped']++;
                    continue;
                }

                // Strictly fail closed: private or uncertain-visibility pull requests are never sent to a model
                if ($isPrivate) {
                    $this->recordSkippedPrivate($repoName, $prNumber, $headSha, $baseSha);
                    $summary['reviewsSkipped']++;
                    $summary['details'][] = [
                        'repository' => $repoName,
                        'number' => $prNumber,
                        'headSha' => $headSha,
                        'baseSha' => $baseSha,
                        'action' => 'skipped_private',
                        'reason' => 'Repository or PR is private. Automatic reviews strictly process public diffs.',
                    ];
                    continue;
                }

                // A reply is a request for attention, not proof that a fix works.
                // Track only opt-in public repositories and only after a complete PR listing.
                if (($authorizedRepos[$lowerRepo]['isPrivate'] ?? null) === false
                    && ($pullsStatus['truncated'] ?? true) === false && empty($pullsStatus['errors'])
                    && \Illuminate\Support\Facades\Schema::hasTable('review_followups')) {
                    try {
                        $links = (new ReviewFollowupTracker($this->github))->detect(
                            $token, (int) $user['id'], $repoName, $prNumber, $headSha, $baseSha
                        );
                        array_push($summary['followups'], ...$links);
                    } catch (\Throwable) {
                        $summary['errors'][] = 'A review reply check was incomplete; no follow-up was recorded for that pull request.';
                    }
                }

                // Existing record check for (repository, prNumber, headSha, baseSha)
                $existing = AutoReviewResult::findForRevision($repoName, $prNumber, $headSha, $baseSha);
                if ($existing !== null) {
                    if ($existing->status === 'completed') {
                        $summary['reviewsSkipped']++;
                        $summary['details'][] = [
                            'repository' => $repoName,
                            'number' => $prNumber,
                            'headSha' => $headSha,
                            'baseSha' => $baseSha,
                            'action' => 'skipped_already_reviewed',
                        ];
                        continue;
                    }

                    if ($existing->status === 'in_progress') {
                        // Stale in-progress attempt: treat as unknown and NEVER auto-retry to prevent duplicate charges
                        $inProgressSeconds = time() - ($existing->updated_at?->getTimestamp() ?? 0);
                        if ($inProgressSeconds >= 300) {
                            $existing->update([
                                'status' => 'unknown',
                                'error_message' => 'Review attempt was interrupted while in progress. Automatic retry skipped to prevent duplicate provider charges.',
                            ]);
                        }
                        $summary['reviewsSkipped']++;
                        $summary['details'][] = [
                            'repository' => $repoName,
                            'number' => $prNumber,
                            'headSha' => $headSha,
                            'baseSha' => $baseSha,
                            'action' => 'skipped_in_progress_or_unknown',
                            'reason' => 'Revision was in progress or interrupted. Silent retries are disabled.',
                        ];
                        continue;
                    }

                    if ($existing->status === 'failed' || $existing->status === 'unknown') {
                        $summary['reviewsSkipped']++;
                        $summary['details'][] = [
                            'repository' => $repoName,
                            'number' => $prNumber,
                            'headSha' => $headSha,
                            'baseSha' => $baseSha,
                            'action' => 'skipped_failed_previously',
                            'reason' => 'Review previously failed or had unknown outcome. Will not silently retry without revision update.',
                        ];
                        continue;
                    }
                }

                // If budget is already exhausted, do not attempt model calls
                if ($summary['budgetExhausted']) {
                    $this->recordSkippedBudget($repoName, $prNumber, $headSha, $baseSha);
                    $summary['reviewsSkipped']++;
                    continue;
                }

                // 5. Attempt atomic reservation and review execution
                $outcome = $this->reviewPullRevision($repoName, $prNumber, $headSha, $baseSha, $title);
                if ($outcome['status'] === 'completed') {
                    $summary['reviewsCompleted']++;
                    $publication = $this->publishAutomaticComment($token, (int) $user['id'], $repoName, $prNumber, $headSha, $baseSha);
                    if ($publication !== null) {
                        $outcome['automaticComment'] = $publication;
                    }
                    // Automatic merge and close remain suspended until complete GitHub
                    // status/thread coverage and revision races are independently verified.
                } elseif ($outcome['status'] === 'skipped_budget') {
                    $summary['reviewsSkipped']++;
                    $summary['budgetExhausted'] = true;
                } elseif ($outcome['status'] === 'failed') {
                    $summary['reviewsFailed']++;
                } else {
                    $summary['reviewsSkipped']++;
                }

                $summary['details'][] = $outcome;
            }
        }

        // Issue watching is part of maintainer autopilot: it only spends
        // requests on repositories where a maintainer switch or automatic
        // commenting is on. Autopilot reviews alone keep prior behavior.
        $summary['issuesInspected'] = 0;
        $summary['issueActions'] = [];
        try {
            $watchRepos = [];
            foreach ($enabledRepos as $repoName) {
                $flags = \ProofPatch\Models\MaintainerAutopilotSetting::flagsFor($repoName);
                if ($flags['fixIssues'] || $flags['autoReady'] || $flags['autoMerge']
                    || \ProofPatch\Models\AutomaticInlineSetting::enabled((int) $user['id'], $repoName)) {
                    $watchRepos[] = $repoName;
                }
            }
            if ($watchRepos !== []) {
                $watcher = $this->issueWatcher ?? new MaintainerIssueWatcher($this->github);
                $issues = $watcher->poll($token, (int) $user['id'], $authorizedRepos, $watchRepos, $onlyRepo);
                $summary['issuesInspected'] = $issues['issuesInspected'];
                $summary['issueActions'] = $issues['actions'];
                array_push($summary['errors'], ...$issues['errors']);
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'Issue watch failed: '.$e->getMessage();
        }

        return $summary;
    }

    /**
     * Gated auto-close for revisions that are red and rejected. An
     * explanation comment must land first; without it the PR stays open.
     * The attempt is recorded before closing and never retried.
     *
     * @param array<string, mixed> $authorizedRepos
     * @param array<string, mixed> $pull
     * @param array<string, mixed> $outcome
     * @return array{status: string, detail?: string}|null
     */
    private function maybeAutoClose(string $token, int $userId, array $authorizedRepos, string $repoName, array $pull, array $outcome): ?array
    {
        $flags = \ProofPatch\Models\MaintainerAutopilotSetting::flagsFor($repoName);
        if (!$flags['autoClose']) {
            return null;
        }
        $number = (int) ($pull['number'] ?? 0);
        $head = (string) ($pull['headSha'] ?? '');
        $base = (string) ($pull['baseSha'] ?? '');
        if ($number < 1 || !preg_match('/\A[a-f0-9]{40}\z/', $head)) {
            return ['status' => 'blocked', 'detail' => 'Pull request revision is invalid.'];
        }
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('autopilot_close_actions')
                || \Illuminate\Support\Facades\DB::table('autopilot_close_actions')
                    ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                    ->where('pull_number', $number)->where('head_sha', $head)->exists()) {
                return ['status' => 'already_attempted', 'detail' => 'Auto-close was already attempted for this revision and is never retried automatically.'];
            }
        } catch (\Throwable) {
            return ['status' => 'blocked', 'detail' => 'Auto-close attempt history could not be checked.'];
        }

        $concerns = 0;
        $record = AutoReviewResult::findForRevision($repoName, $number, $head, $base);
        if ($record !== null && is_array($record->file_findings)) {
            foreach ($record->file_findings as $finding) {
                if (is_array($finding) && ($finding['status'] ?? null) === 'concern') {
                    $concerns++;
                }
            }
        }
        try {
            $readiness = $this->github->getMergeReadiness($token, $repoName, $number, $head);
        } catch (\Throwable $e) {
            return ['status' => 'blocked', 'detail' => 'Close readiness could not be read: '.$e->getMessage()];
        }
        $gate = \ProofPatch\Application\Repository\AutoCloseGate::evaluate([
            'autoCloseEnabled' => true,
            'reviewStatus' => 'completed',
            'recommendation' => $outcome['recommendation'] ?? null,
            'checksState' => $readiness['checksState'],
            'concernCount' => $concerns,
            'state' => $readiness['state'],
            'isMerged' => $readiness['isMerged'],
            'reviewedHead' => $head,
            'currentHead' => $readiness['currentHead'],
        ]);
        if (!$gate['allowed']) {
            return ['status' => 'blocked', 'detail' => implode(' ', $gate['reasons'])];
        }

        $explanation = "Closing this one — plainly, so nobody wastes time on it:\n\n"
            ."- The review asked for changes, and there is at least one real blocker in it.\n"
            ."- The checks are red on the exact commit reviewed (`".substr($head, 0, 12)."`).\n\n"
            ."Red and rejected shouldn't sit in the queue pretending to be nearly there. Push a fixed branch or reopen with the blockers addressed and I'll happily look again.\n\n"
            ."— ProofPatcher autopilot. A maintainer can reopen this anytime; the final call is always human.";
        if (!$this->postPullComment($token, $repoName, $number, $explanation)) {
            return ['status' => 'blocked', 'detail' => 'Explanation comment could not be posted, so the pull request was left open.'];
        }

        $access = ['userId' => $userId, 'repositories' => []];
        foreach (array_keys($authorizedRepos) as $lower) {
            $access['repositories'][$lower] = true;
        }
        $actions = new \ProofPatch\GitHub\PullRequestActions(HttpClient::create());
        try {
            \Illuminate\Support\Facades\DB::table('autopilot_close_actions')->insert([
                'repository' => $repoName, 'pull_number' => $number, 'head_sha' => $head,
                'outcome' => 'attempting', 'detail' => 'Gate passed; explanation posted; close in progress.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $actions->close($token, $access, ['repository' => $repoName, 'number' => $number, 'headSha' => $head], $head);
            \Illuminate\Support\Facades\DB::table('autopilot_close_actions')
                ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                ->where('pull_number', $number)->where('head_sha', $head)
                ->update(['outcome' => 'closed', 'detail' => 'Closed after explanation comment.', 'updated_at' => now()]);
            return ['status' => 'closed', 'detail' => 'Closed after explanation comment.'];
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\DB::table('autopilot_close_actions')
                    ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                    ->where('pull_number', $number)->where('head_sha', $head)
                    ->update(['outcome' => 'unknown', 'detail' => 'Close outcome is unknown; check GitHub before any manual retry.', 'updated_at' => now()]);
            } catch (\Throwable) {}
            return ['status' => 'unknown', 'detail' => 'Close outcome is unknown; check GitHub before any manual retry.'];
        }
    }

    private function postPullComment(string $userToken, string $repository, int $number, string $body): bool
    {
        try {
            $botToken = (new \ProofPatch\GitHub\BotInstallationTokenProvider(HttpClient::create()))->forRepository($userToken, $repository);
            $response = HttpClient::create()->request('POST', "https://api.github.com/repos/{$repository}/issues/{$number}/comments", [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer '.$botToken,
                    'User-Agent' => 'ProofPatcher',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'json' => ['body' => $body],
                'timeout' => 15,
                'max_redirects' => 0,
            ]);
            return $response->getStatusCode() === 201;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Gated auto-merge after a completed autopilot review. Every gate
     * condition must pass; the attempt is recorded before merging and is
     * never retried for the same revision, whatever the outcome.
     *
     * @param array<string, mixed> $authorizedRepos
     * @param array<string, mixed> $pull
     * @param array<string, mixed> $outcome
     * @return array{status: string, detail?: string}|null
     */
    private function maybeAutoMerge(string $token, int $userId, array $authorizedRepos, string $repoName, array $pull, array $outcome): ?array
    {
        $flags = \ProofPatch\Models\MaintainerAutopilotSetting::flagsFor($repoName);
        if (!$flags['autoMerge']) {
            return null;
        }
        $number = (int) ($pull['number'] ?? 0);
        $head = (string) ($pull['headSha'] ?? '');
        if ($number < 1 || !preg_match('/\A[a-f0-9]{40}\z/', $head)) {
            return ['status' => 'blocked', 'detail' => 'Pull request revision is invalid.'];
        }
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('autopilot_merge_actions')
                && \Illuminate\Support\Facades\DB::table('autopilot_merge_actions')
                    ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                    ->where('pull_number', $number)->where('head_sha', $head)->exists()) {
                return ['status' => 'already_attempted', 'detail' => 'Auto-merge was already attempted for this revision and is never retried automatically.'];
            }
        } catch (\Throwable) {
            return ['status' => 'blocked', 'detail' => 'Auto-merge attempt history could not be checked.'];
        }

        try {
            $readiness = $this->github->getMergeReadiness($token, $repoName, $number, $head);
        } catch (\Throwable $e) {
            return ['status' => 'blocked', 'detail' => 'Merge readiness could not be read: '.$e->getMessage()];
        }
        $gate = \ProofPatch\Application\Repository\AutoMergeGate::evaluate([
            'autoMergeEnabled' => true,
            'autoReadyEnabled' => $flags['autoReady'],
            'reviewStatus' => 'completed',
            'recommendation' => $outcome['recommendation'] ?? null,
            'checksState' => $readiness['checksState'],
            'unresolvedThreads' => $readiness['unresolvedThreads'],
            'isDraft' => $readiness['isDraft'],
            'state' => $readiness['state'],
            'isMerged' => $readiness['isMerged'],
            'reviewedHead' => $head,
            'currentHead' => $readiness['currentHead'],
        ]);
        if (!$gate['allowed']) {
            return ['status' => 'blocked', 'detail' => implode(' ', $gate['reasons'])];
        }

        $access = ['userId' => $userId, 'repositories' => []];
        foreach (array_keys($authorizedRepos) as $lower) {
            $access['repositories'][$lower] = true;
        }
        $pullDescriptor = ['repository' => $repoName, 'number' => $number, 'headSha' => $head];
        $actions = new \ProofPatch\GitHub\PullRequestActions(HttpClient::create());
        try {
            \Illuminate\Support\Facades\DB::table('autopilot_merge_actions')->insert([
                'repository' => $repoName, 'pull_number' => $number, 'head_sha' => $head,
                'outcome' => 'attempting', 'detail' => 'Gate passed; merge in progress.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($gate['needsReady']) {
                $actions->markReady($token, $access, $pullDescriptor, $head);
            }
            $result = $actions->merge($token, $access, $pullDescriptor, $head);
            \Illuminate\Support\Facades\DB::table('autopilot_merge_actions')
                ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                ->where('pull_number', $number)->where('head_sha', $head)
                ->update(['outcome' => 'merged', 'detail' => 'Merged as '.substr($result['sha'], 0, 12).'.', 'updated_at' => now()]);
            return ['status' => 'merged', 'detail' => 'Merged as '.substr($result['sha'], 0, 12).'.'];
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\DB::table('autopilot_merge_actions')
                    ->whereRaw('LOWER(repository) = ?', [strtolower($repoName)])
                    ->where('pull_number', $number)->where('head_sha', $head)
                    ->update(['outcome' => 'unknown', 'detail' => 'Merge outcome is unknown; check GitHub before any manual retry. '.$e->getMessage(), 'updated_at' => now()]);
            } catch (\Throwable) {}
            return ['status' => 'unknown', 'detail' => 'Merge outcome is unknown; check GitHub before any manual retry.'];
        }
    }

    /** Posting is a separate, owner-bound opt-in and cannot change review status. */
    private function publishAutomaticComment(string $token, int $userId, string $repo, int $number, string $head, string $base): ?array
    {
        try {
            if (!AutomaticInlineSetting::enabled($userId, $repo)) return null;
            $record = AutoReviewResult::findForRevision($repo, $number, $head, $base);
            if ($record === null || $record->status !== 'completed' || $record->is_public !== true
                || !is_array($record->file_findings)) return null;
            $publisher = $this->automaticCommentPublisher ?? new AutomaticInlineCommentPublisher(
                \Illuminate\Support\Facades\DB::connection()->getPdo(), HttpClient::create()
            );
            $result = $publisher->publish($token, $userId,
                ['repository' => $repo, 'number' => $number, 'headSha' => $head, 'baseSha' => $base],
                $record->file_findings,
                is_string($record->recommendation) ? $record->recommendation : null);
            if ($result === null) return null;
            $record->update(['published_status' => 'one_inline_comment']);
            return ['status' => 'posted', 'url' => $result['url']];
        } catch (\Throwable $error) {
            // Never retry an ambiguous result or mark the local review failed.
            $unknown = str_contains($error->getMessage(), 'outcome is unknown');
            return ['status' => $unknown ? 'unknown' : 'failed',
                'message' => $unknown ? 'Check GitHub before another attempt.' : ($error instanceof \ProofPatch\GitHub\BotIdentityConfigurationException
                    ? $error->getMessage() : 'Automatic comment could not be posted.')];
        }
    }

    /**
     * Reviews a specific pull request revision with TOCTOU recheck, public validation, and budget guard.
     */
    public function reviewPullRevision(string $repository, int $pullNumber, string $headSha, string $baseSha, string $title = '', ?\ProofPatch\GitHub\MentionReviewAuthorization $mention = null): array
    {
        // 1. Atomic claim via direct unique INSERT (fails closed on concurrent workers or existing claims)
        try {
            $record = new AutoReviewResult();
            $record->repository = $repository;
            $record->pull_number = $pullNumber;
            $record->head_sha = $headSha;
            $record->base_sha = $baseSha;
            $record->status = 'in_progress';
            $record->is_public = true;
            $record->published_status = 'local_only';
            $record->save();
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException) {
            // Concurrent worker race or existing claim: another process claimed this exact revision
            $existing = AutoReviewResult::findForRevision($repository, $pullNumber, $headSha, $baseSha);
            if ($existing !== null && $existing->status === 'in_progress') {
                $seconds = time() - ($existing->updated_at?->getTimestamp() ?? 0);
                if ($seconds >= 300) {
                    $existing->update([
                        'status' => 'unknown',
                        'error_message' => 'Review attempt was interrupted while in progress. Automatic retry skipped to prevent duplicate provider charges.',
                    ]);
                }
            }

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'skipped_concurrent_worker',
                'reason' => 'Concurrent worker or prior process claimed revision.',
            ];
        }

        // 2. Fetch and recheck diff and visibility via PublicPatchPreview (fails closed if repo is private or changed)
        $pullDescriptor = [
            'repository' => $repository,
            'number' => $pullNumber,
            'headSha' => $headSha,
            'baseSha' => $baseSha,
            'title' => $title,
            'isPrivate' => false,
        ];

        try {
            $patchPreview = $this->patchPreviewer->fetch($pullDescriptor);
        } catch (\Throwable $e) {
            $this->finishPreflight($record, $mention, [
                'status' => 'failed',
                'error_message' => 'Diff fetch or visibility verification failed: '.$e->getMessage(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }

        // Recheck public flag
        if (($patchPreview['isPrivate'] ?? true) !== false) {
            $this->finishPreflight($record, $mention, [
                'status' => 'skipped_private',
                'is_public' => false,
                'error_message' => 'Repository or PR is not public.',
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'skipped_private',
            ];
        }

        // 3. Generate bounded preview and check budget
        try {
            $preview = $this->reviewAgent->preview($pullDescriptor, $patchPreview);
        } catch (\Throwable $e) {
            $this->finishPreflight($record, $mention, [
                'status' => 'failed',
                'error_message' => 'Review preview generation failed: '.$e->getMessage(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }

        // Check budget limits before calling model
        try {
            $budgetStatus = $this->budget->status();
            if ($budgetStatus['attempts'] >= $budgetStatus['maxAttempts'] || $preview['estimatedMicros'] > $budgetStatus['remainingMicros']) {
                $this->finishPreflight($record, $mention, [
                    'status' => 'skipped_budget',
                    'error_message' => 'Local request budget is exhausted.',
                ]);

                return [
                    'repository' => $repository,
                    'number' => $pullNumber,
                    'headSha' => $headSha,
                    'baseSha' => $baseSha,
                    'status' => 'skipped_budget',
                    'reason' => 'Local request budget is exhausted.',
                ];
            }

            // User-assigned autopilot caps stop automatic spend even when the
            // underlying request budget still has room.
            $spentMicros = max(0, $budgetStatus['limitMicros'] - $budgetStatus['remainingMicros']);
            $capReason = \ProofPatch\Models\AutopilotBudget::blockingReason(
                $budgetStatus['attempts'],
                $spentMicros,
                $preview['estimatedMicros']
            );
            if ($capReason !== null) {
                $this->finishPreflight($record, $mention, [
                    'status' => 'skipped_budget',
                    'error_message' => $capReason,
                ]);

                return [
                    'repository' => $repository,
                    'number' => $pullNumber,
                    'headSha' => $headSha,
                    'baseSha' => $baseSha,
                    'status' => 'skipped_budget',
                    'reason' => $capReason,
                ];
            }
        } catch (\Throwable $e) {
            $this->finishPreflight($record, $mention, [
                'status' => 'skipped_budget',
                'error_message' => 'Budget verification error: '.$e->getMessage(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'skipped_budget',
                'error' => $e->getMessage(),
            ];
        }

        // 4. Honor opt-out immediately before each paid call
        if ($mention !== null
            ? !$mention->allows($repository, $pullNumber, $headSha, $baseSha)
            : !RepositoryAutoReviewSetting::isRepoEnabled($repository)) {
            $status = $mention !== null ? 'skipped_unauthorized' : 'skipped_opted_out';
            $this->finishPreflight($record, $mention, [
                'status' => $status,
                'error_message' => $mention !== null ? 'Mention authorization could not be verified before model call.' : 'Repository was opted out before model call.',
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => $status,
                'reason' => $mention !== null ? 'Mention authorization could not be verified.' : 'Repository was opted out before review execution.',
            ];
        }

        // 5. Resolve Baseten Key
        try {
            $key = $this->basetenKey ?? Credentials::baseten();
        } catch (\Throwable $e) {
            $this->finishPreflight($record, $mention, [
                'status' => 'failed',
                'error_message' => 'Model credentials unavailable: '.$e->getMessage(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'failed',
                'error' => 'Model credentials unavailable: '.$e->getMessage(),
            ];
        }

        // 6. Execute model review (strictly public diff, privateConsent = false)
        try {
            $reviewResult = $this->reviewAgent->review(
                $pullDescriptor,
                $patchPreview,
                $preview['digest'],
                $key,
                false // strictly public consent
            );

            $record->update([
                'status' => 'completed',
                'summary' => $reviewResult['summary'] ?? null,
                'risk_level' => $reviewResult['riskLevel'] ?? null,
                'recommendation' => $reviewResult['recommendation'] ?? null,
                'file_findings' => $reviewResult['fileFindings'] ?? null,
                'raw_review' => $reviewResult,
                'usage' => $reviewResult['usage'] ?? null,
                'error_message' => null,
                'published_status' => 'local_only', // Clearly distinguishes generated local review from GitHub posting
                'model' => $reviewResult['model'] ?? PullRequestReview::MODEL,
                'input_digest' => $reviewResult['inputDigest'] ?? $preview['digest'],
                'reviewed_at' => now(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'completed',
                'recommendation' => $reviewResult['recommendation'] ?? null,
                'riskLevel' => $reviewResult['riskLevel'] ?? null,
            ];
        } catch (\Throwable $e) {
            $record->update([
                'status' => 'failed',
                'error_message' => 'Model review failed: '.$e->getMessage(),
            ]);

            return [
                'repository' => $repository,
                'number' => $pullNumber,
                'headSha' => $headSha,
                'baseSha' => $baseSha,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /** Release only a mention's own preflight claim; no provider request has started. */
    private function finishPreflight(AutoReviewResult $record, ?\ProofPatch\GitHub\MentionReviewAuthorization $mention, array $attributes): void
    {
        if ($mention !== null) {
            // The separate mention ledger keeps the attempt and prevents replay.
            // Do not block later authorized reviews of the same revision.
            $record->delete();
            return;
        }
        $record->update($attributes);
    }

    private function recordSkippedPrivate(string $repo, int $prNumber, string $headSha, string $baseSha): void
    {
        try {
            $record = new AutoReviewResult();
            $record->repository = $repo;
            $record->pull_number = $prNumber;
            $record->head_sha = $headSha;
            $record->base_sha = $baseSha;
            $record->status = 'skipped_private';
            $record->is_public = false;
            $record->error_message = 'Private or uncertain-visibility code is never sent to model inference.';
            $record->published_status = 'local_only';
            $record->save();
        } catch (\Throwable) {}
    }

    private function recordSkippedBudget(string $repo, int $prNumber, string $headSha, string $baseSha): void
    {
        try {
            $record = new AutoReviewResult();
            $record->repository = $repo;
            $record->pull_number = $prNumber;
            $record->head_sha = $headSha;
            $record->base_sha = $baseSha;
            $record->status = 'skipped_budget';
            $record->is_public = true;
            $record->error_message = 'Local request budget is exhausted.';
            $record->published_status = 'local_only';
            $record->save();
        } catch (\Throwable) {}
    }
}
