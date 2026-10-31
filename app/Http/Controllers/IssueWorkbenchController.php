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
        'issue_is_dedicated_test_repo',
        'issue_is_owned_fixture',
    ];

    public function __construct(
        private ?\Symfony\Contracts\HttpClient\HttpClientInterface $http = null,
        private ?RequestBudget $customBudget = null,
        private ?\ProofPatch\Verifier\IsolatedRunnerInterface $customRunner = null,
        private ?\ProofPatch\Agent\SingleUseReviewApproval $customApprovals = null,
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

    /** Only an explicitly public repository with a current grant may be sent to a model. */
    private function canSendModelContext(Request $request, array $context): bool
    {
        if (($context['isSynthetic'] ?? false) === true) {
            return true;
        }

        $access = $request->attributes->get('proofpatch_github_access');
        $repo = $context['repository'] ?? null;
        $auth = $request->session()->get('github_auth');
        if (!is_array($access) || !is_int($access['userId'] ?? null) || !is_string($repo)
            || !isset($access['repositories'][strtolower($repo)]) || !is_array($auth['repositories'] ?? null)) {
            return false;
        }
        foreach ($auth['repositories'] as $repository) {
            if (is_array($repository) && is_string($repository['fullName'] ?? null)
                && strcasecmp($repository['fullName'], $repo) === 0) {
                return ($repository['isPrivate'] ?? null) === false;
            }
        }
        return false;
    }

    public function show(Request $request): Response
    {
        $activeContext = $request->session()->get('issue_context');
        if (is_array($activeContext) && empty($activeContext['isSynthetic']) && $this->resolveGitHubToken($request) === null) {
            $request->session()->forget(self::CASE_SESSION_KEYS);
        }
        $context = $request->session()->get('issue_context');
        $modelContextAllowed = is_array($context) && $this->canSendModelContext($request, $context);
        try {
            $modelKeyAvailable = strlen(Credentials::activeKey()) >= 16;
        } catch (\Throwable) {
            $modelKeyAvailable = false;
        }
        $preview = null;
        $budget = null;
        try {
            $budget = $this->budget()->status();
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
        $access = $request->attributes->get('proofpatch_github_access');
        if (is_array($access) && is_int($access['userId'] ?? null) && $access['userId'] > 0
            && is_array($access['repositories'] ?? null) && $access['repositories'] !== []) {
            $localTasks = LocalTaskCard::select(['id', 'title', 'description', 'repository', 'status', 'updated_at', 'image_mime'])
                ->where('github_user_id', $access['userId'])
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(repository)'), array_keys($access['repositories']))
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (LocalTaskCard $card) => $card->toBoardArray())
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
            'isConnected' => is_array($githubAuth) && isset($githubAuth['token']),
            'user' => is_array($githubAuth) ? ($githubAuth['user'] ?? null) : null,
            'repositories' => is_array($githubAuth) ? ($githubAuth['repositories'] ?? []) : [],
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
            'modelKeyAvailable' => $modelKeyAvailable,
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

            if ($request->input('approved') !== true || !is_string($digest)) {
                throw new \InvalidArgumentException('Review and approve the request first.');
            }
            $budgetCanRequest = (bool) $this->budget()->status()['canRequest'];
            $service = new IssueSuggestion($this->getHttpClient(), $this->budget());
            if (!hash_equals($service->preview($context)['digest'], $digest)) {
                throw new \InvalidArgumentException('Review and approve the exact request first.');
            }

            if ($isDedicated) {
                if (($context['sourceSha256'] ?? '') !== \ProofPatch\GitHub\DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256) {
                    throw new \RuntimeException('The dedicated test repository source file was modified.');
                }

                $hasKey = false;
                try {
                    $key = Credentials::baseten();
                    $hasKey = is_string($key) && strlen($key) >= 16;
                } catch (\Throwable) {
                    $hasKey = false;
                }

                if ($hasKey && $budgetCanRequest && !$request->boolean('offline_reference')) {
                    if (!$this->canSendModelContext($request, $context)) {
                        throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
                    }
                    // The repository may have changed visibility since intake. Recheck
                    // anonymously immediately before sending any content to a provider.
                    $current = (new PublicIssueContext($this->getHttpClient()))->fetch($context['issueUrl'], $context['path']);
                    if (!hash_equals($service->preview($current)['digest'], $digest)) {
                        throw new \RuntimeException('The issue or repository changed. Preview the request again.');
                    }
                    $this->consumeProposalApproval($request, $context, $digest);
                    $suggestion = $service->propose($context, $digest, Credentials::baseten());
                    $suggestion['commit'] = $context['commit'] ?? \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit();
                    $suggestion['isSynthetic'] = false;
                    $suggestion['isDedicated'] = true;
                    $suggestion['provenance'] = 'Dedicated test repository';
                } else {
                    $proposalStore = new \ProofPatch\Agent\ServerHeldModelProposal();
                    $requestedCandidateSha = $request->input('candidate_sha');
                    $serverProposal = null;
                    if (is_string($requestedCandidateSha) && preg_match('/\A[a-f0-9]{64}\z/', $requestedCandidateSha)) {
                        $serverProposal = $proposalStore->lookup($requestedCandidateSha);
                    }

                    if ($serverProposal !== null && hash_equals($serverProposal['sourceSha256'], $context['sourceSha256'])) {
                        // Candidate was genuinely proposed by Meta Muse Spark on public fixture with exact matching source SHA-256
                        $candidateSource = $serverProposal['candidateSource'];
                        $candidateSha = $serverProposal['candidateSha256'];
                        $suggestion = [
                            'status' => 'suggested',
                            'isOfflineExample' => false,
                            'isSynthetic' => false,
                            'isDedicated' => true,
                            'provenance' => 'Dedicated test repository (candidate generated via Meta Muse Spark on public fixture)',
                            'repository' => $context['repository'],
                            'issueNumber' => $context['issueNumber'],
                            'commit' => $context['commit'] ?? \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
                            'path' => $context['path'],
                            'sourceSha256' => $context['sourceSha256'],
                            'find' => $serverProposal['find'],
                            'replace' => $serverProposal['replace'],
                            'explanation' => $serverProposal['explanation'],
                            'model' => $serverProposal['model'],
                            'requestDigest' => $serverProposal['requestDigest'],
                            'usage' => $serverProposal['usage'],
                            'candidate' => [
                                'status' => 'unverified_in_memory_preview',
                                'source' => $candidateSource,
                                'sha256' => $candidateSha,
                                'applied' => false,
                                'verified' => false,
                            ],
                        ];
                    } else {
                        // Offline reference proposal - strictly no model, no tokens, clearly labeled as offline reference
                        $candidateSource = \ProofPatch\Agent\SingleFileCandidate::create(
                            $context['source'],
                            $context['sourceSha256'],
                            "max(1, \$item['quantity'])",
                            "\$item['quantity']",
                        );
                        $candidateSha = hash('sha256', $candidateSource);
                        $suggestion = [
                            'status' => 'offline_reference_example',
                            'isOfflineExample' => true,
                            'isSynthetic' => false,
                            'isDedicated' => true,
                            'provenance' => 'Dedicated test repository (offline reference example)',
                            'repository' => $context['repository'],
                            'issueNumber' => $context['issueNumber'],
                            'commit' => $context['commit'] ?? \ProofPatch\GitHub\DedicatedTestRepo::requireBaseCommit(),
                            'path' => $context['path'],
                            'sourceSha256' => $context['sourceSha256'],
                            'find' => "max(1, \$item['quantity'])",
                            'replace' => "\$item['quantity']",
                            'explanation' => "Offline reference example for OrderTotal zero-quantity bug. No external provider call was made.",
                            'model' => null,
                            'requestDigest' => $digest,
                            'usage' => null,
                            'candidate' => [
                                'status' => 'unverified_in_memory_preview',
                                'source' => $candidateSource,
                                'sha256' => $candidateSha,
                                'applied' => false,
                                'verified' => false,
                            ],
                        ];
                    }
                }
            } elseif ($isOwned) {
                // Verify local fixture integrity
                $fixturePath = base_path('tests/Fixtures/owned-review-case/src/OrderTotal.php');
                if (!file_exists($fixturePath) || hash_file('sha256', $fixturePath) !== $context['sourceSha256']) {
                    throw new \RuntimeException('The local fixture file was modified.');
                }

                $hasKey = false;
                try {
                    $key = Credentials::baseten();
                    $hasKey = is_string($key) && strlen($key) >= 16;
                } catch (\Throwable) {
                    $hasKey = false;
                }

                if ($hasKey && $budgetCanRequest && !$request->boolean('offline_reference')) {
                    if (!$this->canSendModelContext($request, $context)) {
                        throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
                    }
                    $this->consumeProposalApproval($request, $context, $digest);
                    $suggestion = $service->propose($context, $digest, Credentials::baseten());
                    $suggestion['commit'] = null;
                    $suggestion['isSynthetic'] = true;
                    $suggestion['provenance'] = 'Local checked-in test fixture (tests/Fixtures/owned-review-case)';
                } else {
                    // Safe offline deterministic proposal clearly marked as an offline reference example
                    $candidateSource = \ProofPatch\Agent\SingleFileCandidate::create(
                        $context['source'],
                        $context['sourceSha256'],
                        "max(1, \$item['quantity'])",
                        "\$item['quantity']",
                    );
                    $suggestion = [
                        'status' => 'offline_reference_example',
                        'isOfflineExample' => true,
                        'isSynthetic' => true,
                        'provenance' => 'Local checked-in test fixture (tests/Fixtures/owned-review-case)',
                        'repository' => $context['repository'],
                        'issueNumber' => $context['issueNumber'],
                        'commit' => null,
                        'path' => $context['path'],
                        'sourceSha256' => $context['sourceSha256'],
                        'find' => "max(1, \$item['quantity'])",
                        'replace' => "\$item['quantity']",
                        'explanation' => "Offline reference example for OrderTotal boundary calculation. No external provider call was made.",
                        'model' => null,
                        'requestDigest' => $digest,
                        'usage' => null,
                        'candidate' => [
                            'status' => 'unverified_in_memory_preview',
                            'source' => $candidateSource,
                            'sha256' => hash('sha256', $candidateSource),
                            'applied' => false,
                            'verified' => false,
                        ],
                    ];
                }
            } else {
                if (!$this->canSendModelContext($request, $context)) {
                    throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
                }
                if (!$budgetCanRequest) {
                    throw new \RuntimeException('The local model request allowance is exhausted.');
                }
                // Refetch without credentials to verify the issue, public visibility and commit still match.
                $current = (new PublicIssueContext($this->getHttpClient()))->fetch($context['issueUrl'], $context['path']);
                if (!hash_equals($service->preview($current)['digest'], $digest)) {
                    throw new \RuntimeException('The issue or repository changed. Preview the request again.');
                }
                $this->consumeProposalApproval($request, $current, $digest);
                $suggestion = $service->propose($current, $digest, Credentials::baseten());
            }

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

        if (!is_array($suggestion) || !isset($suggestion['candidate']['source'])) {
            return redirect('/issues')->with('error', 'No candidate proposal available to verify.');
        }

        if (!$isOwned || ($suggestion['path'] ?? '') !== 'src/OrderTotal.php') {
            return redirect('/issues')->with('error', 'Automated host verification is currently restricted to the allowlisted owned review case fixture. Worktrees and temporary directories are not security sandboxes for arbitrary untrusted code.');
        }

        if (!is_array($context)) {
            return redirect('/issues')->with('error', 'No active issue context available to verify.');
        }

        $candidateSource = (string) $suggestion['candidate']['source'];
        $candidateSha = hash('sha256', $candidateSource);

        $rawGrant = getenv('MODAL_GRANT_VERIFIED') ?: ($_ENV['MODAL_GRANT_VERIFIED'] ?? $_SERVER['MODAL_GRANT_VERIFIED'] ?? 'false');
        $isolatedRunner = $this->customRunner ?? new \ProofPatch\Verifier\ModalIsolatedRunner(grantVerified: $rawGrant === 'true');
        $isolatedAvailable = $isolatedRunner->isAvailable()['available'];

        // Fail closed for model-generated or untrusted candidate code if microVM runner is unavailable
        if (!$isolatedAvailable && (!empty($suggestion['model']) || !hash_equals(\ProofPatch\Verifier\OwnedCaseVerifier::KNOWN_TRUSTED_CANDIDATE_SHA256, $candidateSha))) {
            return redirect('/issues')->with('error', 'Host execution disabled: Model-generated or untrusted candidate code cannot be executed on the host. Worktrees, temporary directories, and token filters are not security sandboxes; an isolated microVM runner is required.');
        }

        try {
            $task = $this->resolveAndValidateTask($request, $context, expectedCandidateSha: $candidateSha);
            $expectedVersion = (int) ($task->version ?? 1);
            $expectedCandidateSha = $candidateSha;

            $approvalStore = new \ProofPatch\GitHub\ServerHeldPublicationApproval(storage_path('app/publication_approvals'));
            $verifier = new \ProofPatch\Verifier\OwnedCaseVerifier(
                base_path(),
                null,
                $approvalStore,
                $isolatedAvailable ? $isolatedRunner : null
            );
            $report = $verifier->verify($candidateSource, $context);

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
        $authToken = $request->session()->get('issue_publication_token');
        $isDedicated = (bool) $request->session()->get('issue_is_dedicated_test_repo');

        if (!is_array($context) || !is_array($report) || !is_array($suggestion) || !isset($suggestion['candidate']['source'])) {
            return redirect('/issues')->with('error', 'No verified candidate run available for publication.');
        }

        if (!$isDedicated) {
            return redirect('/issues')->with('error', 'Pull request publication is strictly restricted to the dedicated test repository.');
        }

        $evidenceDir = $report['evidenceDirectory'] ?? null;
        if (!is_string($evidenceDir) || !is_dir($evidenceDir)) {
            return redirect('/issues')->with('error', 'Execution evidence directory is missing.');
        }

        if ($authToken === null || trim((string) $authToken) === '') {
            return redirect('/issues')->with('error', 'Publication refused: Server-held publication authorization token is required.');
        }

        // Reconcile and resolve GitHub authorization token safely
        $githubToken = $this->resolveGitHubToken($request);
        if ($githubToken === null || trim($githubToken) === '') {
            return redirect('/issues')->with('error', 'Authentication required: Connect your GitHub account before publishing.');
        }

        $candidateSha = hash('sha256', (string) $suggestion['candidate']['source']);

        try {
            $task = $this->resolveAndValidateTask(
                $request,
                $context,
                expectedCandidateSha: $candidateSha,
                expectedRunId: $report['id'] ?? null
            );
            $expectedVersion = (int) ($task->version ?? 1);

            // 1. ATOMIC PUBLICATION CLAIM BEFORE NETWORK:
            // Claim the task atomically (transitions status to 'publishing' and increments version).
            // This blocks competing publications or modifications while network call is in flight.
            // No SQLite transaction is held across the network.
            $task->claimPublication(
                $expectedVersion,
                $candidateSha,
                (string) ($report['id'] ?? '')
            );
            $claimedVersion = (int) $task->version;
        } catch (\ProofPatch\Exceptions\StaleTaskConcurrencyException $concurrencyError) {
            return redirect('/issues')->with('error', 'PR Publication blocked: ' . $concurrencyError->getMessage());
        } catch (\Throwable $taskError) {
            return redirect('/issues')->with('error', 'PR Publication blocked: ' . $taskError->getMessage());
        }

        // 2. NETWORK CALL TO GITHUB:
        // Execute the external network request outside any DB transaction:
        try {
            $approvalStore = new \ProofPatch\GitHub\ServerHeldPublicationApproval(storage_path('app/publication_approvals'));
            // Dedicated-only explicit user action path with connected publisher
            $publisher = new \ProofPatch\GitHub\DedicatedTestRepoPublisher(
                $approvalStore,
                $this->getHttpClient(),
                disconnected: false
            );

            $publicationContext = $context;
            if (($suggestion['isOfflineExample'] ?? false) === true) {
                $publicationContext['proposalOrigin'] = 'offline_reference';
            } elseif (!empty($suggestion['model'])) {
                $publicationContext['proposalOrigin'] = 'muse';
                $publicationContext['model'] = $suggestion['model'];
                $publicationContext['usage'] = $suggestion['usage'] ?? null;
            } else {
                $publicationContext['proposalOrigin'] = 'unspecified';
            }
            $result = $publisher->publishDraftPr(
                $publicationContext,
                $evidenceDir,
                (string) $suggestion['candidate']['source'],
                (string) $authToken,
                $githubToken
            );
        } catch (\ProofPatch\Exceptions\AmbiguousPublicationException $ambiguousError) {
            // Ambiguous write state: attempt read-only reconciliation before failing closed
            $reconciled = null;
            try {
                $reconciled = $publisher->reconcileExistingPr($githubToken, $ambiguousError->branch);
            } catch (\Throwable) {
                $reconciled = null;
            }

            if ($reconciled !== null) {
                // Read-only check verified PR was actually created on GitHub
                $reconciled['receiptSha256'] = (string) ($report['receipt']['sha256'] ?? '');
                $reconciled['verificationRunId'] = (string) ($report['id'] ?? '');

                $request->session()->put('issue_published_pr', $reconciled);
                $request->session()->forget('issue_publication_token');

                try {
                    $task->recordPublication(
                        $reconciled,
                        expectedVersion: $claimedVersion,
                        expectedRunId: $report['id'] ?? null,
                        expectedCandidateSha: $candidateSha
                    );
                } catch (\Throwable $dbErr) {
                    $this->storeDurableRecoveryEvidence($task, $claimedVersion, $report['id'] ?? 'unknown', $reconciled, $dbErr->getMessage());
                }

                $this->addNotification(
                    $request,
                    'success',
                    'Draft PR Reconciled',
                    "Draft PR #{$reconciled['prNumber']} was verified created on GitHub for {$reconciled['repository']}.",
                    $reconciled['prUrl']
                );

                return redirect('/issues');
            }

            // Could not confirm PR exists: fail closed with honest outcome-unknown state!
            // Preserve publication claim and deterministic branch identity; do NOT release claim or repeat writes.
            try {
                $task->recordOutcomeUnknown($ambiguousError->branch, $ambiguousError->runId, $ambiguousError->getMessage(), $claimedVersion);
            } catch (\Throwable $stateError) {
                $this->storeDurableRecoveryEvidence($task, $claimedVersion, $ambiguousError->runId, [
                    'branch' => $ambiguousError->branch,
                    'repository' => $ambiguousError->repository,
                    'status' => 'outcome_unknown',
                ], $stateError->getMessage());
            }

            $request->session()->put('issue_publication_reconciliation', [
                'status' => 'outcome_unknown',
                'repository' => $ambiguousError->repository,
                'branch' => $ambiguousError->branch,
                'runId' => $ambiguousError->runId,
                'error' => $ambiguousError->getMessage(),
            ]);

            return redirect('/issues')->with(
                'error',
                "PR publication outcome unknown: writes began for branch '{$ambiguousError->branch}', but confirmation failed ({$ambiguousError->getMessage()}). Automatic retries are blocked to prevent duplicate branches or PRs. Reconcile the branch state first."
            );
        } catch (\Throwable $publishError) {
            // Pre-write failure: safe to release publication claim
            $task->releasePublicationClaim($claimedVersion);
            return redirect('/issues')->with('error', 'PR Publication blocked: ' . $publishError->getMessage());
        }

        // 3. EXTERNAL PR WAS CREATED ON GITHUB:
        // External PR was successfully created on GitHub. Store in session immediately.
        $request->session()->put('issue_published_pr', $result);
        $request->session()->forget('issue_publication_token');

        // 4. ATOMIC TASK FINALIZATION:
        try {
            $task->recordPublication(
                $result,
                expectedVersion: $claimedVersion,
                expectedRunId: $report['id'] ?? null,
                expectedCandidateSha: $candidateSha
            );
        } catch (\Throwable $dbError) {
            // External PR creation succeeded on GitHub.
            // Do NOT perform an unconditional DB update by ID, which could overwrite a newer task.
            // Instead, write durable recovery evidence to server storage and preserve in session.
            $this->storeDurableRecoveryEvidence($task, $claimedVersion, $report['id'] ?? 'unknown', $result, $dbError->getMessage());

            $this->addNotification(
                $request,
                'warning',
                'Draft PR Created (Local Database Save Failed)',
                "Draft PR #{$result['prNumber']} was created on GitHub at {$result['prUrl']}, but saving task state to local database failed: {$dbError->getMessage()}.",
                $result['prUrl']
            );

            return redirect('/issues')->with(
                'error',
                "Draft PR #{$result['prNumber']} was published successfully to {$result['prUrl']}. However, local task recording failed ({$dbError->getMessage()}). Do not retry publication."
            );
        }

        $this->addNotification(
            $request,
            'success',
            'Draft PR Prepared',
            "Draft PR #{$result['prNumber']} created in {$result['repository']}.",
            $result['prUrl']
        );

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

    private function consumeProposalApproval(Request $request, array $context, string $digest): void
    {
        TaskWorkflowHelper::consumeProposalApproval($request, $context, $digest, $this->approvalStore());
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
