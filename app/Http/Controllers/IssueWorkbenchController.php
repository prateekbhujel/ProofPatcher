<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ProofPatch\Agent\Credentials;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\GitHub\PublicIssueContext;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Models\LocalTaskCard;
use ProofPatch\Support\NotificationHelper;
use ProofPatch\Support\SessionHelper;
use ProofPatch\Support\TaskWorkflowHelper;
use Symfony\Component\HttpClient\HttpClient;

final class IssueWorkbenchController
{
    public const CASE_SESSION_KEYS = [
        'issue_task_id',
        'issue_context',
        'issue_suggestion',
        'issue_draft',
        'issue_verification_report',
        'issue_published_pr',
        'issue_publication_token',
        'issue_approval_token',
        'issue_approval_user_id',
        'issue_is_dedicated_test_repo',
        'issue_is_owned_fixture',
    ];

    public function __construct(
        private ?\Symfony\Contracts\HttpClient\HttpClientInterface $http = null,
        private ?RequestBudget $customBudget = null,
        private ?\ProofPatch\Verifier\IsolatedRunnerInterface $customRunner = null,
        private ?\ProofPatch\Agent\SingleUseReviewApproval $customApprovals = null,
        private ?\Hari\Registry $hari = null,
    ) {}

    private function getHttpClient(): \Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return $this->http ?? HttpClient::create();
    }

    private function budget(): RequestBudget
    {
        return $this->customBudget ?? RequestBudget::forRuntime();
    }

    private function approvalStore(): \ProofPatch\Agent\SingleUseReviewApproval
    {
        if ($this->customApprovals !== null) {
            return $this->customApprovals;
        }
        $dir = getenv('PROOFPATCH_APPROVALS_DIR')
            ?: (function_exists('storage_path') ? storage_path('app/review_approvals') : sys_get_temp_dir() . '/proofpatch_approvals');
        return new \ProofPatch\Agent\SingleUseReviewApproval($dir);
    }

    private function resolveContextHeadSha(array $context): string
    {
        return TaskWorkflowHelper::resolveContextHeadSha($context);
    }

    private function resolveGitHubToken(Request $request): ?string
    {
        return TaskWorkflowHelper::resolveGitHubToken($request);
    }

    /** Public repositories and the dedicated private test case need a verified grant. */
    private function canSendModelContext(Request $request, array $context): bool
    {
        return \ProofPatch\Application\Workbench\ModelContextPolicy::canSend(
            $request->session()->get('github_auth'),
            $request->attributes->get('proofpatch_github_access'),
            $context,
            \ProofPatch\GitHub\PrivatePullSession::usesServerSession()
        );
    }

    private function sessionTaskHasScreenshot(Request $request): bool
    {
        try {
            $taskId = $request->session()->get('issue_task_id');
            if (!is_int($taskId) && !ctype_digit((string) ($taskId ?? ''))) {
                return false;
            }
            return WorkbenchTask::whereKey((int) $taskId)->whereNotNull('image_mime')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function isPrivateDedicatedContext(Request $request, array $context): bool
    {
        return \ProofPatch\Application\Workbench\ModelContextPolicy::isPrivateDedicated(
            $request->session()->get('github_auth'),
            $context
        );
    }

    public function show(Request $request): Response
    {
        $activeContext = $request->session()->get('issue_context');
        if (is_array($activeContext) && empty($activeContext['isSynthetic']) && $this->resolveGitHubToken($request) === null) {
            $request->session()->forget(self::CASE_SESSION_KEYS);
        }
        $context = $request->session()->get('issue_context');
        $modelContextAllowed = is_array($context) && $this->canSendModelContext($request, $context);
        $privateModelConsentRequired = $modelContextAllowed && is_array($context)
            && (bool) $request->session()->get('issue_is_dedicated_test_repo')
            && $this->isPrivateDedicatedContext($request, $context);
        try {
            $modelKeyAvailable = strlen(Credentials::activeKey()) >= 16;
        } catch (\Throwable) {
            $modelKeyAvailable = false;
        }
        $preview = null;
        $budget = null;
        try {
            // An injected test budget is the caller's explicit ledger; the
            // shared runtime ledger is read through the skill instead.
            $budget = $this->customBudget !== null
                ? $this->customBudget->status()
                : ($this->hari ?? \Hari\Hari::registry())->resolve('budget.status', 1, 'workbench')->invoke([]);
            if (is_array($context)) {
                $preview = (new IssueSuggestion($this->getHttpClient(), $this->budget()))->preview($context);
                $budget['canRequest'] = $budget['canRequest'] && $budget['remainingMicros'] >= $preview['estimatedMicros'];

                if ($modelContextAllowed) {
                    $approvalStore = $this->approvalStore();
                    $headSha = $this->resolveContextHeadSha($context);
                    $approval = $approvalStore->issue(
                        (string) $context['repository'],
                        (int) $context['issueNumber'],
                        $headSha,
                        (string) $preview['digest']
                    );
                    $preview['approvalToken'] = $approval['token'];
                    $request->session()->put('issue_approval_token', $approval['token']);
                    if ($privateModelConsentRequired) {
                        $request->session()->put('issue_approval_user_id', $request->attributes->get('proofpatch_github_access')['userId']);
                    }
                } else {
                    $request->session()->forget('issue_approval_token');
                }
            }
        } catch (\Throwable) {
            $request->session()->forget('issue_context');
        }

        $publishedPr = $request->session()->get('issue_published_pr');
        $verificationReport = $request->session()->get('issue_verification_report');
        if (is_array($publishedPr)) {
            $reportId = is_array($verificationReport) ? ($verificationReport['id'] ?? null) : null;
            if ($reportId === null || ($publishedPr['verificationRunId'] ?? null) !== $reportId) {
                $publishedPr = null;
                $request->session()->forget('issue_published_pr');
            }
        }

        $tasks = [];
        $localTasks = [];
        // Privacy flags for the few card repositories only — the full session
        // list stays server-side; pickers page through /github/repositories/search.
        $privacyByRepo = [];
        $sessionAuth = $request->session()->get('github_auth');
        if (is_array($sessionAuth) && is_array($sessionAuth['repositories'] ?? null)) {
            foreach ($sessionAuth['repositories'] as $repo) {
                if (is_array($repo) && is_string($repo['fullName'] ?? null)) {
                    $privacyByRepo[strtolower($repo['fullName'])] = ($repo['isPrivate'] ?? null) !== false;
                }
            }
        }
        $access = $request->attributes->get('proofpatch_github_access');
        if (is_array($access) && is_int($access['userId'] ?? null) && $access['userId'] > 0
            && is_array($access['repositories'] ?? null) && $access['repositories'] !== []) {
            $localTasks = LocalTaskCard::select(['id', 'title', 'description', 'repository', 'status', 'updated_at', 'image_mime'])
                ->where('github_user_id', $access['userId'])
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(repository)'), array_keys($access['repositories']))
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (LocalTaskCard $card) => $card->toBoardArray() + [
                    'repositoryIsPrivate' => $privacyByRepo[strtolower($card->repository)] ?? true,
                ])
                ->values()
                ->all();
        }
        try {
            $query = WorkbenchTask::orderByDesc('updated_at');
            $access = $request->attributes->get('proofpatch_github_access');
            if (is_array($access)) {
                $userId = $access['userId'] ?? null;
                $repos = array_keys($access['repositories'] ?? []);
                $query->where(function ($q) use ($userId, $repos): void {
                    $q->where('is_synthetic', true);
                    if (is_int($userId) && $repos !== []) {
                        $q->orWhere(function ($owned) use ($userId, $repos): void {
                            $owned->where('github_user_id', $userId)
                                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(repository)'), $repos);
                        });
                    }
                });
            } elseif ($this->resolveGitHubToken($request) === null) {
                $query->where('is_synthetic', true);
            }
            $tasks = $query
                ->get()
                ->map(fn (WorkbenchTask $t) => $t->toBoardArray())
                ->values()
                ->all();
        } catch (\Throwable) {
        }

        $access = $request->attributes->get('proofpatch_github_access');
        $verified = !is_array($access) || is_int($access['userId'] ?? null);
        $githubAuth = $verified ? $request->session()->get('github_auth') : null;
        $deviceFlow = $request->session()->get('github_device_flow');
        $connected = $verified && $this->resolveGitHubToken($request) !== null;
        $selectedRepo = $connected ? $request->session()->get('selected_github_repo') : null;
        $repoIssues = $connected ? ($request->session()->get('github_repo_issues') ?? []) : [];
        $triageAnalyzer = new \ProofPatch\GitHub\PrTriageAnalyzer();
        $triagedIssues = [];
        if (is_array($repoIssues)) {
            foreach ($repoIssues as $issue) {
                if (is_array($issue)) {
                    $triagedIssues[] = array_merge($issue, [
                        'triage' => $triageAnalyzer->triageIssue($issue),
                    ]);
                }
            }
        }
        $repoPulls = $connected ? ($request->session()->get('github_repo_pulls') ?? []) : [];
        $repoListIncomplete = $connected && (bool) $request->session()->get('github_repo_list_incomplete', false);
        $clientIdConfigured = (bool) (getenv('PROOFPATCH_GITHUB_CLIENT_ID') || getenv('GITHUB_APP_CLIENT_ID'));

        $githubConnection = [
            'isConfigured' => $clientIdConfigured,
            'clientId' => getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: null,
            'isConnected' => \ProofPatch\Application\Settings\GitHubConnectionState::isConnected($githubAuth),
            'user' => is_array($githubAuth) ? ($githubAuth['user'] ?? null) : null,
            'repositoryCount' => is_array($githubAuth) && is_array($githubAuth['repositories'] ?? null)
                ? count($githubAuth['repositories'])
                : 0,
            'selectedRepo' => $selectedRepo,
            'dedicatedIssueNumber' => is_string($selectedRepo)
                && \ProofPatch\GitHub\DedicatedTestRepo::name() !== null
                && strcasecmp($selectedRepo, \ProofPatch\GitHub\DedicatedTestRepo::name()) === 0
                    ? \ProofPatch\GitHub\DedicatedTestRepoFetcher::ISSUE_NUMBER : null,
            'repoIssues' => $triagedIssues,
            'repoPulls' => $repoPulls,
            'repoListIncomplete' => $repoListIncomplete,
            'deviceFlow' => is_array($deviceFlow) ? [
                'userCode' => (string) ($deviceFlow['userCode'] ?? ''),
                'verificationUri' => (string) ($deviceFlow['verificationUri'] ?? ''),
                'expiresAt' => (int) ($deviceFlow['expiresAt'] ?? 0),
                'interval' => (int) ($deviceFlow['interval'] ?? 5),
            ] : null,
        ];

        $hasLoadedContext = is_array($context) && $preview !== null;
        $completeness = ($hasLoadedContext && is_string($context['title'] ?? null) && is_string($context['body'] ?? null))
            ? \ProofPatch\Support\IssueCompleteness::analyze($context['title'], $context['body'])
            : null;

        return Inertia::render('Issues', [
            'context' => $hasLoadedContext ? $context : null,
            'completeness' => $completeness,
            'preview' => $preview,
            'suggestion' => $request->session()->get('issue_suggestion'),
            'draft' => $request->session()->get('issue_draft'),
            'verificationReport' => $verificationReport,
            'isOwnedFixture' => (bool) $request->session()->get('issue_is_owned_fixture'),
            'isDedicatedTestRepo' => (bool) $request->session()->get('issue_is_dedicated_test_repo'),
            'publishedPr' => $publishedPr,
            'hasPublicationToken' => is_string($request->session()->get('issue_publication_token')),
            'publisherDisconnected' => false,
            'budget' => $budget,
            'error' => $request->session()->get('error'),
            'tasks' => $tasks,
            'localTasks' => $localTasks,
            'cardPreview' => CardPreviewController::visiblePreview($request),
            'cardProposalPreview' => CardProposalController::visible($request, 'card_proposal_preview'),
            'cardProposalResult' => CardProposalController::visible($request, 'card_proposal_result'),
            'githubConnection' => $githubConnection,
            'modelContextAllowed' => $modelContextAllowed,
            'privateModelConsentRequired' => $privateModelConsentRequired,
            'modelKeyAvailable' => $modelKeyAvailable,
            'hasScreenshot' => $this->sessionTaskHasScreenshot($request),
        ]);
    }

    public function loadOwnedCase(Request $request): RedirectResponse
    {
        $request->session()->forget(self::CASE_SESSION_KEYS);
        $fixturePath = base_path('tests/Fixtures/owned-review-case');
        $srcFile = $fixturePath.'/src/OrderTotal.php';
        if (!file_exists($srcFile)) {
            return redirect('/issues')->with('error', 'Owned review case fixture not found.');
        }

        try {
            $source = (string) file_get_contents($srcFile);
            $context = [
                'isSynthetic' => true,
                'provenance' => 'Local checked-in test fixture (tests/Fixtures/owned-review-case)',
                'repository' => 'synthetic-fixture/order-total',
                'issueNumber' => 101,
                'issueUrl' => null,
                'commit' => null,
                'title' => '[Synthetic Fixture] Zero-quantity order items incorrectly charged once',
                'body' => "NOTICE: Deliberately seeded offline synthetic test case from tests/Fixtures/owned-review-case.\n"
                    ."Checked-in local test case; no remote GitHub issue or commit provenance.\n"
                    ."In tests/order_total.php, expected 250, got 750 because max(1, quantity) is used.\n"
                    ."Target: repair calculation in OrderTotal::calculate so zero quantity items contribute zero.",
                'path' => 'src/OrderTotal.php',
                'source' => $source,
                'sourceSha256' => hash('sha256', $source),
            ];

            PublicIssueContext::validateContext($context);
            $task = WorkbenchTask::recordIntake($context, false);
            if ($request->boolean('reset') && $task->is_synthetic) {
                $task->status = 'queued';
                $task->candidate_source = null;
                $task->candidate_sha256 = null;
                $task->proposal_digest = null;
                $task->is_offline_example = false;
                $task->metadata = null;
                $task->verification_report_id = null;
                $task->verification_decision = null;
                $task->version = ((int) $task->version) + 1;
                $task->save();
            }
            $this->hydrateTaskSession($request, $task);

            return redirect('/issues');
        } catch (\Throwable $error) {
            return redirect('/issues')->with('error', 'Failed to load owned review case: ' . $error->getMessage());
        }
    }

    public function loadDedicatedCase(Request $request): RedirectResponse
    {
        $request->session()->forget(self::CASE_SESSION_KEYS);

        // Require explicitly authorized authenticated application session
        $token = $this->resolveGitHubToken($request);

        if ($token === null || trim($token) === '') {
            return redirect('/issues')->with('error', 'Authentication required: Connect your GitHub account before fetching the dedicated test repository.');
        }

        try {
            $fetcher = new \ProofPatch\GitHub\DedicatedTestRepoFetcher($this->getHttpClient());
            $context = $fetcher->fetchAndVerify($token);

            $access = $request->attributes->get('proofpatch_github_access');
            $task = WorkbenchTask::recordIntake($context, true, githubUserId: $access['userId'] ?? null);
            $this->hydrateTaskSession($request, $task);

            return redirect('/issues');
        } catch (\Throwable $error) {
            return redirect('/issues')->with('error', 'Failed to fetch dedicated test case from GitHub: ' . $error->getMessage());
        }
    }

    public function selectRepository(Request $request): RedirectResponse
    {
        $token = $this->resolveGitHubToken($request);
        if ($token === null) {
            return redirect('/issues')->with('error', 'Connect your GitHub account to choose repositories.');
        }

        $repoName = trim((string) $request->input('repository', ''));
        if ($repoName === '') {
            return redirect('/issues')->with('error', 'Select a repository.');
        }

        try {
            $client = new \ProofPatch\GitHub\GitHubAppClient($this->getHttpClient());
            $issueStatus = [];
            $pullStatus = [];
            $issues = $client->listOpenIssues($token, $repoName, $issueStatus, 'all');
            $pulls = $client->listOpenPullRequests($token, $repoName, $pullStatus, 'all');
            if (!empty($issueStatus['errors']) || !empty($pullStatus['errors'])) {
                throw new \RuntimeException('GitHub did not return a complete issue and pull request list. Try again.');
            }

            $previousRepo = $request->session()->get('selected_github_repo');
            if (is_string($previousRepo) && strcasecmp($previousRepo, $repoName) !== 0) {
                $request->session()->forget(array_merge(self::CASE_SESSION_KEYS, [
                    'card_preview', 'card_proposal_preview', 'card_proposal_result',
                ]));
            }
            $request->session()->put('selected_github_repo', $repoName);
            try {
                // The terminal reads this same selection file.
                \ProofPatch\Support\KernelCli::setSelectedRepo($repoName);
            } catch (\Throwable) {
            }
            $request->session()->put('github_repo_issues', $issues);
            $request->session()->put('github_repo_pulls', $pulls);
            $request->session()->put('github_repo_list_incomplete', (bool) ($issueStatus['truncated'] || $pullStatus['truncated']));

            $this->addNotification($request, 'info', 'Repository Loaded', "Found " . count($issues) . " issues and " . count($pulls) . " pull requests for {$repoName}.");

            return redirect('/issues');
        } catch (\Throwable $e) {
            return redirect('/issues')->with('error', 'Failed to load repository data: ' . $e->getMessage());
        }
    }

    public function loadIssue(Request $request): RedirectResponse
    {
        $token = $this->resolveGitHubToken($request);
        if ($token === null) {
            return redirect('/issues')->with('error', 'Connect your GitHub account before loading issues.');
        }

        $repoName = trim((string) $request->input('repository', ''));
        $issueNumber = (int) $request->input('issue_number', 0);
        $path = trim((string) $request->input('path', ''));

        if ($repoName === '' || $issueNumber <= 0) {
            return redirect('/issues')->with('error', 'Select a valid issue to load.');
        }

        $dedicatedName = \ProofPatch\GitHub\DedicatedTestRepo::name();
        $isDedicated = $dedicatedName !== null && strcasecmp($repoName, $dedicatedName) === 0
            && $issueNumber === \ProofPatch\GitHub\DedicatedTestRepoFetcher::ISSUE_NUMBER;
        if (!$isDedicated && $path === '') {
            return redirect('/issues')->with('error', 'Choose the source file to review.');
        }

        try {
            if ($isDedicated) {
                $fetcher = new \ProofPatch\GitHub\DedicatedTestRepoFetcher($this->getHttpClient());
                $context = $fetcher->fetchAndVerify($token);
                $access = $request->attributes->get('proofpatch_github_access');
                $task = WorkbenchTask::recordIntake($context, true, githubUserId: $access['userId'] ?? null);
                $request->session()->forget(self::CASE_SESSION_KEYS);
                $this->hydrateTaskSession($request, $task);
                $this->addNotification($request, 'success', 'Dedicated Issue Loaded', "Loaded {$repoName}#{$issueNumber} into the workbench.");
                return redirect('/issues');
            }

            $client = new \ProofPatch\GitHub\GitHubAppClient($this->getHttpClient());
            $context = $client->fetchIssueContext($token, $repoName, $issueNumber, $path);
            $access = $request->attributes->get('proofpatch_github_access');
            $task = WorkbenchTask::recordIntake($context, false, githubUserId: $access['userId'] ?? null);
            $request->session()->forget(self::CASE_SESSION_KEYS);
            $this->hydrateTaskSession($request, $task);

            $this->addNotification($request, 'success', 'Issue Loaded', "Loaded {$repoName}#{$issueNumber} ({$path}) into the workbench.");
            return redirect('/issues');
        } catch (\Throwable $e) {
            return redirect('/issues')->with('error', 'Failed to load issue: ' . $e->getMessage());
        }
    }

    public function saveDraft(Request $request): RedirectResponse
    {
        $comment = (string) $request->input('comment', '');
        if (trim($comment) === '') {
            $request->session()->forget('issue_draft');
        } else {
            $request->session()->put('issue_draft', [
                'comment' => $comment,
                'savedAt' => gmdate(DATE_ATOM),
                'readOnlyPolicy' => 'Local draft only. No external GitHub pull request comments or mutations are made without explicit user authorization.',
            ]);
        }
        return redirect('/issues');
    }

    public function capture(Request $request): RedirectResponse
    {
        try {
            $url = $request->input('issue_url');
            $path = $request->input('path');
            if (!is_string($url) || strlen($url) > 512 || !is_string($path)) {
                throw new \InvalidArgumentException('Enter a public GitHub issue URL and a source file path.');
            }
            $access = $request->attributes->get('proofpatch_github_access');
            $githubUserId = is_array($access) && is_int($access['userId'] ?? null)
                ? $access['userId']
                : null;
            if ($githubUserId === null || $githubUserId <= 0) {
                throw new \RuntimeException('Connect GitHub before loading an issue.');
            }
            [$owner, $repo] = PublicIssueContext::parseIssueUrl($url);
            $allowed = $access['repositories'] ?? null;
            if (!is_array($allowed) || !isset($allowed[strtolower($owner.'/'.$repo)])) {
                throw new \RuntimeException('Grant the GitHub app access to this repository before loading its issue.');
            }
            $context = (new PublicIssueContext($this->getHttpClient()))->fetch($url, $path);

            $task = WorkbenchTask::recordIntake($context, false, githubUserId: $githubUserId);
            $metadata = $task->metadata ?? [];
            if (!($metadata['is_confirmed_public'] ?? false)) {
                $metadata['is_confirmed_public'] = true;
                $task->metadata = $metadata;
                $task->save();
            }

            $request->session()->forget(self::CASE_SESSION_KEYS);
            $this->hydrateTaskSession($request, $task);
            return redirect('/issues');
        } catch (\Throwable $error) {
            return redirect('/issues')->with('error', $error->getMessage());
        }
    }

    public function propose(Request $request): RedirectResponse
    {
        $context = $request->session()->get('issue_context');
        $isOwned = (bool) $request->session()->get('issue_is_owned_fixture');
        $isDedicated = (bool) $request->session()->get('issue_is_dedicated_test_repo');
        if (is_array($context) && empty($context['isSynthetic']) && $this->resolveGitHubToken($request) === null) {
            return redirect('/issues')->with('error', 'Connect your GitHub account to use this task.');
        }
        $digest = $request->input('digest');
        // Clear previous suggestion and report for fresh generation; preserve active context
        $request->session()->forget(['issue_suggestion', 'issue_verification_report', 'issue_publication_token']);
        // Persist while the session lock is held, even if the worker dies during the provider call.
        $request->session()->save();
        try {
            if (!is_array($context)) {
                throw new \InvalidArgumentException('Review and approve the request first.');
            }
            $task = $this->resolveAndValidateTask($request, $context);
            $expectedVersion = (int) ($task->version ?? 1);
            $expectedSourceSha = (string) ($context['sourceSha256'] ?? '');

            $input = \ProofPatch\Application\Workbench\ProposeSuggestionInput::fromRaw(
                [
                    'digest' => $digest,
                    'approved' => $request->input('approved'),
                    'approval_token' => $request->input('approval_token'),
                    'offline_reference' => $request->input('offline_reference'),
                    'private_consent' => $request->input('private_consent'),
                    'candidate_sha' => $request->input('candidate_sha'),
                    'session_auth' => $request->session()->get('github_auth'),
                    'verified_access' => $request->attributes->get('proofpatch_github_access'),
                    'saved_approval_token' => $request->session()->get('issue_approval_token'),
                    'approval_user_id' => $request->session()->get('issue_approval_user_id'),
                    'github_token' => $this->resolveGitHubToken($request),
                    'uses_server_session' => \ProofPatch\GitHub\PrivatePullSession::usesServerSession(),
                ],
                $context,
                $isOwned,
                $isDedicated
            );
            $useCase = new \ProofPatch\Application\Workbench\ProposeSuggestionUseCase(
                new IssueSuggestion($this->getHttpClient(), $this->budget()),
                $this->budget(),
                $this->approvalStore(),
                new \ProofPatch\Agent\ServerHeldModelProposal(),
                \ProofPatch\Agent\PrivateProposalStore::forRuntime(),
                $this->getHttpClient(),
                base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php')
            );
            $result = $useCase->execute($input);
            if ($result->approvalConsumed) {
                $request->session()->forget('issue_approval_token');
            }
            if ($result->privateApprovalConsumed) {
                $request->session()->forget('issue_approval_user_id');
            }
            if (!$result->ok) {
                return redirect('/issues')->with('error', $result->error);
            }
            $suggestion = $result->suggestion;

            $suggestion['safetyNotice'] = 'Host execution of untrusted PHP candidates is disabled: worktrees and temporary directories are not security sandboxes. This candidate is an in-memory preview only.';
            $task->recordProposal(
                $suggestion,
                expectedVersion: $expectedVersion,
                expectedSourceSha: $expectedSourceSha
            );
            $request->session()->put('issue_suggestion', $suggestion);
            return redirect('/issues');
        } catch (\ProofPatch\Exceptions\StaleTaskConcurrencyException $concurrencyError) {
            $request->session()->forget(['issue_suggestion', 'issue_verification_report', 'issue_publication_token']);
            return redirect('/issues')->with('error', $concurrencyError->getMessage());
        } catch (\Throwable $error) {
            return redirect('/issues')->with('error', $error->getMessage());
        }
    }

    public function verifyCandidate(Request $request): RedirectResponse
    {
        $suggestion = $request->session()->get('issue_suggestion');
        $isOwned = (bool) $request->session()->get('issue_is_owned_fixture');
        $context = $request->session()->get('issue_context');

        $rawGrant = getenv('MODAL_GRANT_VERIFIED') ?: ($_ENV['MODAL_GRANT_VERIFIED'] ?? $_SERVER['MODAL_GRANT_VERIFIED'] ?? 'false');
        $isolatedRunner = $this->customRunner
            ?? (\ProofPatch\Verifier\TrustedOracleRunner::isEnabled()
                ? new \ProofPatch\Verifier\TrustedOracleRunner()
                : new \ProofPatch\Verifier\ModalIsolatedRunner(grantVerified: $rawGrant === 'true'));
        $isolatedAvailable = $isolatedRunner->isAvailable()['available'];

        try {
            $input = \ProofPatch\Application\Workbench\VerifyCandidateInput::fromRaw($suggestion, $isOwned, $context, $isolatedAvailable);
        } catch (\Throwable $guardError) {
            return redirect('/issues')->with('error', $guardError->getMessage());
        }

        try {
            $candidateSha = $input->candidateSha;
            $task = $this->resolveAndValidateTask($request, $input->context, expectedCandidateSha: $candidateSha);
            $expectedVersion = (int) ($task->version ?? 1);
            $expectedCandidateSha = $candidateSha;

            $approvalStore = new \ProofPatch\GitHub\ServerHeldPublicationApproval(storage_path('app/publication_approvals'));
            $skill = ($this->hari ?? \Hari\Hari::registry())->resolve('verify.candidate', 1, 'workbench');
            if (!$skill instanceof \ProofPatch\Abilities\VerifyCandidateSkill) {
                throw new \RuntimeException('Candidate verification skill is unavailable.');
            }
            $outcome = $skill->withVerifier(new \ProofPatch\Verifier\OwnedCaseVerifier(
                base_path(),
                null,
                $approvalStore,
                $isolatedAvailable ? $isolatedRunner : null
            ))->invoke([
                'suggestion' => $suggestion,
                'is_owned' => $isOwned,
                'context' => $context,
                'isolated_available' => $isolatedAvailable,
            ]);
            if (!is_array($outcome) || ($outcome['ok'] ?? null) !== true) {
                $request->session()->forget(['issue_verification_report', 'issue_publication_token']);
                $error = is_string($outcome['error'] ?? null) && $outcome['error'] !== ''
                    ? $outcome['error'] : 'Candidate verification skill failed.';
                return redirect('/issues')->with('error', 'Verification error: ' . $error);
            }
            $report = $outcome['report'];

            // ATOMIC OPTIMISTIC PERSISTENCE:
            // Persist verification ONLY if the task version and active candidate SHA still match.
            // If another session or proposal changed the candidate/task in between, this rejects atomically.
            $task->recordVerification(
                $report,
                expectedVersion: $expectedVersion,
                expectedCandidateSha: $expectedCandidateSha
            );

            // Only AFTER atomic persistence succeeds do we grant report and publication token in session:
            $request->session()->put('issue_verification_report', $report);
            if (isset($report['publicationAuthorizationToken'])) {
                $request->session()->put('issue_publication_token', $report['publicationAuthorizationToken']);
            }

            // Update candidate verified status in suggestion
            $suggestion['candidate']['verified'] = ($report['decision'] === 'VERIFIED');
            $suggestion['candidate']['verificationRunId'] = $report['id'];
            $suggestion['candidate']['status'] = $report['decision'] === 'VERIFIED' ? 'verified_allowlisted_candidate' : 'failed_verification';
            $request->session()->put('issue_suggestion', $suggestion);

            $this->addNotification(
                $request,
                $report['decision'] === 'VERIFIED' ? 'success' : 'warning',
                $report['decision'] === 'VERIFIED' ? 'Candidate Verified with Receipt' : 'Candidate Verification Failed',
                "OrderTotal bug verification {$report['decision']}: run {$report['id']}, receipt {$report['receipt']['sha256']}.",
                '/issues'
            );

            return redirect('/issues');
        } catch (\ProofPatch\Exceptions\StaleTaskConcurrencyException $concurrencyError) {
            // Revoke publication token so it cannot be published under stale verification or revived
            if (isset($approvalStore)) {
                if (isset($report['publicationAuthorizationToken'])) {
                    $approvalStore->revoke((string) $report['publicationAuthorizationToken']);
                }
                if (isset($report['id'])) {
                    $approvalStore->revokeForRun((string) $report['id'], $expectedCandidateSha);
                }
            }
            $request->session()->forget(['issue_verification_report', 'issue_publication_token']);
            return redirect('/issues')->with('error', $concurrencyError->getMessage());
        } catch (\Throwable $error) {
            if (isset($approvalStore)) {
                if (isset($report['publicationAuthorizationToken'])) {
                    $approvalStore->revoke((string) $report['publicationAuthorizationToken']);
                }
                if (isset($report['id'], $expectedCandidateSha)) {
                    $approvalStore->revokeForRun((string) $report['id'], $expectedCandidateSha);
                }
            }
            $request->session()->forget(['issue_verification_report', 'issue_publication_token']);
            return redirect('/issues')->with('error', 'Verification error: ' . $error->getMessage());
        }
    }

    public function publishDraftPr(Request $request): RedirectResponse
    {
        $context = $request->session()->get('issue_context');
        $report = $request->session()->get('issue_verification_report');
        $suggestion = $request->session()->get('issue_suggestion');

        try {
            $input = \ProofPatch\Application\Workbench\PublishDraftPrInput::fromRaw([
                'context' => $context,
                'report' => $report,
                'suggestion' => $suggestion,
                'is_dedicated' => (bool) $request->session()->get('issue_is_dedicated_test_repo'),
                'auth_token' => $request->session()->get('issue_publication_token'),
                'github_token' => $this->resolveGitHubToken($request),
                'is_private_dedicated' => is_array($context) && $this->isPrivateDedicatedContext($request, $context),
            ]);
        } catch (\Throwable $inputError) {
            return redirect('/issues')->with('error', $inputError->getMessage());
        }
        $candidateSha = $input->candidateSha;

        try {
            $task = $this->resolveAndValidateTask(
                $request,
                $input->context,
                expectedCandidateSha: $candidateSha,
                expectedRunId: $input->report['id'] ?? null
            );
            $expectedVersion = (int) ($task->version ?? 1);

            // 1. ATOMIC PUBLICATION CLAIM BEFORE NETWORK:
            // Claim the task atomically (transitions status to 'publishing' and increments version).
            // This blocks competing publications or modifications while network call is in flight.
            // No SQLite transaction is held across the network.
            $task->claimPublication(
                $expectedVersion,
                $candidateSha,
                (string) ($input->report['id'] ?? '')
            );
            $claimedVersion = (int) $task->version;
        } catch (\ProofPatch\Exceptions\StaleTaskConcurrencyException $concurrencyError) {
            return redirect('/issues')->with('error', 'PR Publication blocked: ' . $concurrencyError->getMessage());
        } catch (\Throwable $taskError) {
            return redirect('/issues')->with('error', 'PR Publication blocked: ' . $taskError->getMessage());
        }

        // 2. NETWORK CALL TO GITHUB:
        // Execute the external network request outside any DB transaction:
        $approvalStore = new \ProofPatch\GitHub\ServerHeldPublicationApproval(storage_path('app/publication_approvals'));
        // Dedicated-only explicit user action path with connected publisher
        $useCase = new \ProofPatch\Application\Workbench\PublishDraftPrUseCase(
            new \ProofPatch\GitHub\DedicatedTestRepoPublisher(
                $approvalStore,
                $this->getHttpClient(),
                disconnected: false
            )
        );
        $result = $useCase->execute($input);

        if ($result->status === 'blocked') {
            // Pre-write failure: safe to release publication claim
            $task->releasePublicationClaim($claimedVersion);
            return redirect('/issues')->with('error', $result->message);
        }

        if ($result->status === 'outcome_unknown') {
            // Could not confirm PR exists: fail closed with honest outcome-unknown state!
            // Preserve publication claim and deterministic branch identity; do NOT release claim or repeat writes.
            try {
                $task->recordOutcomeUnknown($result->branch, $result->runId, $result->detail, $claimedVersion);
            } catch (\Throwable $stateError) {
                $this->storeDurableRecoveryEvidence($task, $claimedVersion, $result->runId, [
                    'branch' => $result->branch,
                    'repository' => $result->repository,
                    'status' => 'outcome_unknown',
                ], $stateError->getMessage());
            }

            $request->session()->put('issue_publication_reconciliation', [
                'status' => 'outcome_unknown',
                'repository' => $result->repository,
                'branch' => $result->branch,
                'runId' => $result->runId,
                'error' => $result->detail,
            ]);

            return redirect('/issues')->with('error', $result->message);
        }

        // 3. EXTERNAL PR WAS CREATED ON GITHUB:
        // External PR was successfully created on GitHub. Store in session immediately.
        $pr = $result->pr;
        $request->session()->put('issue_published_pr', $pr);
        $request->session()->forget('issue_publication_token');

        // 4. ATOMIC TASK FINALIZATION:
        try {
            $task->recordPublication(
                $pr,
                expectedVersion: $claimedVersion,
                expectedRunId: $input->report['id'] ?? null,
                expectedCandidateSha: $candidateSha
            );
        } catch (\Throwable $dbError) {
            // External PR creation succeeded on GitHub.
            // Do NOT perform an unconditional DB update by ID, which could overwrite a newer task.
            // Instead, write durable recovery evidence to server storage and preserve in session.
            $this->storeDurableRecoveryEvidence($task, $claimedVersion, $input->report['id'] ?? 'unknown', $pr, $dbError->getMessage());

            if ($result->status === 'published') {
                $this->addNotification(
                    $request,
                    'warning',
                    'Draft PR Created (Local Database Save Failed)',
                    "Draft PR #{$pr['prNumber']} was created on GitHub at {$pr['prUrl']}, but saving task state to local database failed: {$dbError->getMessage()}.",
                    $pr['prUrl']
                );

                return redirect('/issues')->with(
                    'error',
                    "Draft PR #{$pr['prNumber']} was published successfully to {$pr['prUrl']}. However, local task recording failed ({$dbError->getMessage()}). Do not retry publication."
                );
            }
        }

        if ($result->status === 'reconciled') {
            $this->addNotification(
                $request,
                'success',
                'Draft PR Reconciled',
                "Draft PR #{$pr['prNumber']} was verified created on GitHub for {$pr['repository']}.",
                $pr['prUrl']
            );
        } else {
            $this->addNotification(
                $request,
                'success',
                'Draft PR Prepared',
                "Draft PR #{$pr['prNumber']} created in {$pr['repository']}.",
                $pr['prUrl']
            );
        }

        return redirect('/issues');
    }

    public function switchTask(Request $request): RedirectResponse
    {
        $taskId = $request->input('task_id');
        $task = WorkbenchTask::find($taskId);
        if ($task === null) {
            return redirect('/issues')->with('error', 'Task not found.');
        }

        if (!$task->is_synthetic && $this->resolveGitHubToken($request) === null) {
            return redirect('/issues')->with('error', 'Connect your GitHub account to open this task.');
        }

        $request->session()->forget(self::CASE_SESSION_KEYS);
        $this->hydrateTaskSession($request, $task);

        return redirect('/issues');
    }

    private function hydrateTaskSession(Request $request, WorkbenchTask $task): void
    {
        TaskWorkflowHelper::hydrateTaskSession($request, $task);
    }

    private function resolveAndValidateTask(
        Request $request,
        array $context,
        ?string $expectedCandidateSha = null,
        ?string $expectedRunId = null
    ): WorkbenchTask {
        return TaskWorkflowHelper::resolveAndValidateTask($request, $context, $expectedCandidateSha, $expectedRunId);
    }

    public function reconcilePr(Request $request): RedirectResponse
    {
        $githubToken = $this->resolveGitHubToken($request);
        if ($githubToken === null) {
            return redirect('/issues')->with('error', 'Authentication required: Connect your GitHub account before reconciling pull requests.');
        }

        $context = $request->session()->get('issue_context');
        if (!is_array($context)) {
            return redirect('/issues')->with('error', 'No active issue context available.');
        }

        try {
            $task = $this->resolveAndValidateTask($request, $context);
        } catch (\Throwable) {
            return redirect('/issues')->with('error', 'Task not found or no longer matches this session.');
        }

        if ($task->status !== 'outcome_unknown' || !is_string($task->verification_report_id)
            || !is_string($task->candidate_sha256)
            || ($task->metadata['outcome_unknown']['runId'] ?? null) !== $task->verification_report_id) {
            return redirect('/issues')->with('error', 'No matching uncertain publication is available for reconciliation.');
        }

        $branchName = $task->published_branch ?? $task->metadata['outcome_unknown']['branch'] ?? null;
        if (!is_string($branchName) || $branchName === '') {
            return redirect('/issues')->with('error', 'No publication branch recorded for this task to reconcile.');
        }

        $publisher = new \ProofPatch\GitHub\DedicatedTestRepoPublisher(
            new \ProofPatch\GitHub\ServerHeldPublicationApproval(storage_path('app/publication_approvals')),
            $this->getHttpClient(),
            disconnected: false
        );

        try {
            $reconciled = $publisher->reconcileExistingPr($githubToken, $branchName);
        } catch (\Throwable) {
            return redirect('/issues')->with('error', 'Could not confirm the GitHub outcome. The publication remains locked; check GitHub before retrying.');
        }
        if ($reconciled !== null) {
            $reconciled['verificationRunId'] = $task->verification_report_id;
            $task->recordPublication($reconciled, (int) $task->version, $task->verification_report_id, $task->candidate_sha256);
            $request->session()->put('issue_published_pr', $reconciled);
            $request->session()->forget('issue_publication_reconciliation');

            $this->addNotification(
                $request,
                'success',
                'Draft PR Reconciled',
                "Draft PR #{$reconciled['prNumber']} was verified created on GitHub for {$reconciled['repository']}.",
                $reconciled['prUrl']
            );

            return redirect('/issues');
        }

        return redirect('/issues')->with('error', "No pull request was found for branch '{$branchName}'. The outcome remains uncertain; check GitHub before retrying.");
    }

    private function storeDurableRecoveryEvidence(
        WorkbenchTask $task,
        int $claimedVersion,
        string $runId,
        array $prResult,
        string $dbErrorMessage
    ): void {
        TaskWorkflowHelper::storeDurableRecoveryEvidence($task, $claimedVersion, $runId, $prResult, $dbErrorMessage);
    }

    public function transcribeVoice(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('audio')) {
            return response()->json(['status' => 'error', 'message' => 'No audio clip received.'], 422);
        }

        $file = $request->file('audio');
        if ($file === null || !$file->isValid() || $file->getSize() > 4194304) {
            return response()->json(['status' => 'error', 'message' => 'Audio clip exceeds the 4 MB local limit.'], 422);
        }

        $whisperBin = is_executable('/Users/pratikbhujel/.local/bin/whisper')
            ? '/Users/pratikbhujel/.local/bin/whisper'
            : 'whisper';
        $ffmpegBin = is_executable('/opt/homebrew/bin/ffmpeg')
            ? '/opt/homebrew/bin/ffmpeg'
            : 'ffmpeg';

        $id = bin2hex(random_bytes(8));
        $tmpDir = sys_get_temp_dir();
        $inputPath = $tmpDir . "/pp_voice_{$id}.webm";
        $wavPath = $tmpDir . "/pp_voice_{$id}.wav";
        $jsonPath = $tmpDir . "/pp_voice_{$id}.json";

        try {
            file_put_contents($inputPath, file_get_contents($file->getRealPath()));

            $ffmpeg = new \Symfony\Component\Process\Process([
                $ffmpegBin, '-y', '-i', $inputPath, '-ar', '16000', '-ac', '1', $wavPath,
            ]);
            $ffmpeg->setTimeout(10);
            $ffmpeg->run();
            if (!$ffmpeg->isSuccessful() || !is_file($wavPath)) {
                return response()->json(['status' => 'error', 'message' => 'Could not decode audio locally.'], 500);
            }

            $whisper = new \Symfony\Component\Process\Process([
                $whisperBin,
                $wavPath,
                '--model', 'tiny.en',
                '--language', 'en',
                '--fp16', 'False',
                '--output_format', 'json',
                '--output_dir', $tmpDir,
            ]);
            $whisper->setEnv(['PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin']);
            $whisper->setTimeout(20);
            $whisper->run();

            if (!is_file($jsonPath)) {
                return response()->json(['status' => 'error', 'message' => 'Local Whisper model did not produce output.'], 500);
            }

            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            $transcript = trim((string) ($decoded['text'] ?? ''));

            return response()->json([
                'status' => 'ok',
                'transcript' => $transcript,
                'engine' => 'local-whisper-tiny.en',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Local voice transcription failed.'], 500);
        } finally {
            foreach ([$inputPath, $wavPath, $jsonPath] as $p) {
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }

    private function addNotification(Request $request, string $type, string $title, string $message, ?string $link = null): void
    {
        NotificationHelper::add($request, $type, $title, $message, $link);
    }
}
