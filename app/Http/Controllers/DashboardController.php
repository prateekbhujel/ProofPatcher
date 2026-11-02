<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProofPatch\Agent\Credentials;
use ProofPatch\Agent\ModelProposal;
use ProofPatch\Agent\PullRequestReview;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\Evidence\RunEvidence;
use ProofPatch\GitHub\DiffHunkParser;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubDeviceFlow;
use ProofPatch\GitHub\PullRequestReader;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\PrivatePullSession;
use ProofPatch\Patch\CssPatch;
use ProofPatch\Support\LocalProcess;
use ProofPatch\Support\NotificationHelper;
use ProofPatch\Support\SessionHelper;
use ProofPatch\Verifier\CheckoutVerifier;
use Symfony\Component\HttpClient\HttpClient;

final class DashboardController
{
    private string $project;
    private string $runsDir;
    private string $snapshotsDir;
    private string $approvalsDir;

    public function __construct(
        private ?GitHubAppClient $gitHubAppClient = null,
        private ?GitHubDeviceFlow $deviceFlowClient = null,
        private ?\Hari\Registry $hari = null,
        private ?\ProofPatch\GitHub\GitHubWebFlow $webFlowClient = null,
    ) {
        $this->project = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $this->runsDir = $this->project.'/artifacts/runs';
        $this->snapshotsDir = getenv('PROOFPATCH_SNAPSHOT_DIR') ?: $this->project.'/artifacts/pull-requests';
        $home = getenv('HOME');
        $this->approvalsDir = getenv('PROOFPATCH_APPROVALS_DIR') ?: ($home ? $home.'/.local/share/proofpatch/runtime/approvals' : $this->project.'/artifacts/approvals');
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
            @session_start();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function getGitHubAppClient(): GitHubAppClient
    {
        return $this->gitHubAppClient ?? new GitHubAppClient(HttpClient::create());
    }

    private function getDeviceFlowClient(): GitHubDeviceFlow
    {
        return $this->deviceFlowClient ?? new GitHubDeviceFlow(HttpClient::create());
    }

    private function getWebFlowClient(): \ProofPatch\GitHub\GitHubWebFlow
    {
        return $this->webFlowClient ?? new \ProofPatch\GitHub\GitHubWebFlow(HttpClient::create());
    }

    private function githubClientSecret(): ?string
    {
        $secret = trim((string) (getenv('PROOFPATCH_GITHUB_CLIENT_SECRET') ?: getenv('GITHUB_APP_CLIENT_SECRET') ?: ''));
        return $secret !== '' ? $secret : null;
    }

    private function githubCallbackUrl(): string
    {
        return rtrim((string) (getenv('APP_URL') ?: 'http://127.0.0.1:8000'), '/') . '/github/callback';
    }

    public function overview(): InertiaResponse
    {
        $store = new PullRequestSnapshot($this->snapshotsDir);
        $rawFiles = glob($this->snapshotsDir.'/*.json') ?: [];
        $files = array_values(array_filter($rawFiles, static fn(string $f): bool => !str_ends_with($f, '-preview.json')));
        rsort($files, SORT_STRING);
        $recent = [];
        $totalSnapshots = 0;

        foreach ($files as $file) {
            $id = basename($file, '.json');
            try {
                $saved = $store->load($id);
                $entry = \ProofPatch\Application\Repository\OverviewSnapshotPolicy::recentEntry($saved, $id);
                if ($entry === null) {
                    continue;
                }
                $totalSnapshots++;
                $key = $entry['pull']['repository'].'#'.$entry['pull']['number'];
                $recent[$key] ??= $entry;
            } catch (\Throwable) {
                continue;
            }
        }

        $runFiles = glob($this->runsDir.'/*/result.json') ?: [];
        $reads = [];
        foreach ($runFiles as $rf) {
            try {
                $reads[] = (new RunEvidence())->read(dirname($rf));
            } catch (\Throwable) {}
        }
        // Existing private GitHub evidence has no account ownership.
        [$totalRuns, $verifiedRuns] = \ProofPatch\Application\Evidence\OverviewRunCounts::count($reads);

        return Inertia::render('Overview', [
            'totalSnapshots' => $totalSnapshots,
            'totalRuns' => $totalRuns,
            'verifiedRuns' => $verifiedRuns,
            'recent' => array_values($recent),
        ]);
    }

    public function repositories(Request $request): InertiaResponse|\Illuminate\Http\RedirectResponse
    {
        $error = session('error');
        if ($request->isMethod('post')) {
            try {
                $url = (string) $request->input('url');
                if (strlen($url) > 512 || trim($url) === '') {
                    throw new \InvalidArgumentException('Enter a GitHub pull request URL or shorthand.');
                }
                // The dedicated private test repo must use the authenticated,
                // session-only capture path rather than the public reader.
                $target = \ProofPatch\Application\Repository\CaptureRoutePolicy::privateTestRepoTarget($url, DedicatedTestRepo::name());
                if ($target !== null) {
                    $request->merge(['repository' => $target['repository'], 'number' => $target['number']]);
                    return $this->captureInstalledPullRequest($request);
                }
                $pullRequest = (new PullRequestReader(HttpClient::create()))->read($url);
                if (($pullRequest['isPrivate'] ?? false) === true) {
                    throw new \InvalidArgumentException('Private repositories are not supported. ProofPatch operates strictly on public repositories to prevent proprietary code leakage.');
                }
                $snapshot = (new PullRequestSnapshot($this->snapshotsDir))->capture($pullRequest);
                $this->forgetSession($request, ...PrivatePullSession::KEYS);
                $this->putSession($request, 'pullRequest', $pullRequest);
                $this->putSession($request, 'pullRequestSnapshot', $snapshot);
                $this->forgetSession($request, 'pr_review', 'review_drafts');
                $this->addNotification($request, 'success', 'PR Captured', "Captured snapshot for {$pullRequest['repository']}#{$pullRequest['number']}");

                return redirect('/repositories');
            } catch (\Throwable $exception) {
                return redirect('/repositories')->with('error', $exception->getMessage());
            }
        }

        $pull = $this->getSession($request, 'pullRequest');
        if (is_array($pull) && ($pull['isPrivate'] ?? false) === true && !PrivatePullSession::allows($request, $pull)) {
            PrivatePullSession::clear($request);
            $pull = null;
        }
        $snapshot = $this->getSession($request, 'pullRequestSnapshot');
        if ($request->has('snapshot')) {
            if (is_array($pull) && ($pull['isPrivate'] ?? false) === true) {
                PrivatePullSession::clear($request);
                $pull = null;
                $snapshot = null;
            }
            try {
                $snapshotId = (string) $request->query('snapshot');
                $previousSnapshotId = is_array($snapshot) ? ($snapshot['id'] ?? null) : null;
                $isNewSnapshot = $previousSnapshotId !== $snapshotId;

                $saved = (new PullRequestSnapshot($this->snapshotsDir))->load($snapshotId);
                $pull = $saved['snapshot']['pullRequest'] ?? null;

                if (!\ProofPatch\Application\Repository\SnapshotVisibilityPolicy::isExplicitlyPublic($saved)) {
                    $pull = null;
                    $snapshot = null;
                    $this->forgetSession($request, 'pullRequest', 'pullRequestSnapshot', 'pr_review', 'review_drafts', 'patch_preview');
                    $error = 'Access denied: private or unverified repository snapshots cannot be loaded. ProofPatch operates strictly on verified public repositories.';
                }

                if ($pull !== null) {
                    $snapshot = ['id' => $saved['snapshot']['id'], 'capturedAt' => $saved['snapshot']['capturedAt'], 'sha256' => $saved['sha256']];
                    $this->putSession($request, 'pullRequest', $pull);
                    $this->putSession($request, 'pullRequestSnapshot', $snapshot);
                    if ($isNewSnapshot) {
                        $this->forgetSession($request, 'pr_review', 'review_drafts');
                    }
                    $cachedPatchPreview = $this->getSession($request, 'patch_preview');
                    if (isset($cachedPatchPreview['headSha']) && !\ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead((string) $cachedPatchPreview['headSha'], $pull['headSha'] ?? null)) {
                        $this->forgetSession($request, 'patch_preview');
                    }
                }
            } catch (\Throwable) {
                $pull = null;
                $snapshot = null;
                $this->forgetSession($request, 'pullRequest', 'pullRequestSnapshot', 'pr_review', 'review_drafts', 'patch_preview');
                $error = 'This saved pull-request snapshot is unavailable or invalid.';
            }
        }

        $patchPreview = null;
        $patchPreviewError = null;
        $cachedPreview = $this->getSession($request, 'patch_preview');
        if (is_array($cachedPreview) && is_array($pull) && !\ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead((string) ($cachedPreview['headSha'] ?? ''), $pull['headSha'] ?? null)) {
            $cachedPreview = null;
            $this->forgetSession($request, 'patch_preview');
        }
        $isPrivatePull = is_array($pull) && (($pull['isPrivate'] ?? false) === true);
        $diskPreviewFile = (! $isPrivatePull && is_array($snapshot) && isset($snapshot['id']))
            ? $this->snapshotsDir.'/'.$snapshot['id'].'-preview.json'
            : null;
        if (!is_array($cachedPreview) && $diskPreviewFile && is_file($diskPreviewFile)) {
            try {
                $diskData = json_decode((string) file_get_contents($diskPreviewFile), true, 64, JSON_THROW_ON_ERROR);
                if (is_array($diskData) && \ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead($diskData['headSha'] ?? null, $pull['headSha'] ?? null)) {
                    $cachedPreview = $diskData;
                    $this->putSession($request, 'patch_preview', $diskData);
                }
            } catch (\Throwable) {}
        }

        if ($request->query('preview') === '1' && is_array($pull) && is_array($snapshot)) {
            try {
                if (is_array($cachedPreview) && \ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead($cachedPreview['headSha'] ?? null, $pull['headSha'] ?? null)) {
                    $patchPreview = $cachedPreview;
                } else {
                    // Deliberately unauthenticated: private PR content must never be previewed here.
                    $patchPreview = (new PublicPatchPreview(HttpClient::create()))->fetch($pull);
                    $this->putSession($request, 'patch_preview', $patchPreview);
                    if ($diskPreviewFile && ! $isPrivatePull) {
                        @file_put_contents($diskPreviewFile, json_encode($patchPreview, JSON_PRETTY_PRINT));
                    }
                }
            } catch (\Throwable) {
                if (is_array($cachedPreview) && \ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead($cachedPreview['headSha'] ?? null, $pull['headSha'] ?? null)) {
                    $patchPreview = $cachedPreview;
                } else {
                    $patchPreviewError = 'Unable to preview this public pull request. It may have changed, be private, or be unavailable. Capture it again and retry.';
                }
            }
        } elseif (is_array($cachedPreview) && is_array($pull) && \ProofPatch\Application\Review\PatchPreviewFreshness::matchesHead($cachedPreview['headSha'] ?? null, $pull['headSha'] ?? null)) {
            $patchPreview = $cachedPreview;
        }

        $budget = $this->hariBudget();
        try {
            $modelKeyAvailable = strlen(Credentials::activeKey()) >= 16;
        } catch (\Throwable) {
            $modelKeyAvailable = false;
        }

        $reviewPreview = null;
        if (\ProofPatch\Application\Review\ReviewPreviewEligibility::allows($patchPreview, $pull)) {
            try {
                $reviewAgent = new PullRequestReview(HttpClient::create(), $this->budget());
                $reviewPreview = $reviewAgent->preview($pull, $patchPreview);
                if ($budget !== null) {
                    $budget['canRequest'] = $budget['canRequest'] && $budget['remainingMicros'] >= $reviewPreview['estimatedMicros'];
                }

                // Issue a server-held single-use approval token bound to repository, PR, head SHA, and digest
                $approvalStore = $this->approvalStore();
                $approval = $approvalStore->issue(
                    (string) $pull['repository'],
                    (int) $pull['number'],
                    (string) $pull['headSha'],
                    (string) $reviewPreview['digest'],
                );
                $reviewPreview['approvalToken'] = $approval['token'];
                $this->putSession($request, 'review_approval_token', $approval['token']);
            } catch (\Throwable) {
                $reviewPreview = null;
            }
        }

        $review = $this->getSession($request, 'pr_review');
        $reviewError = session('review_error') ?? $this->getSession($request, 'review_error');
        $this->forgetSession($request, 'review_error');

        if ($review === null && is_array($pull) && isset($pull['repository'], $pull['number'], $pull['headSha'], $pull['baseSha'])) {
            try {
                $outcome = ($this->hari ?? \Hari\Hari::registry())->resolve('review.result', 1, 'web')->invoke([
                    'repository' => (string) $pull['repository'],
                    'pull_number' => (int) $pull['number'],
                    'head_sha' => (string) $pull['headSha'],
                    'base_sha' => (string) $pull['baseSha'],
                ]);
                $savedReview = \ProofPatch\Application\Review\SavedAutoReview::firstCompletedReview($outcome);
                if ($savedReview !== null) {
                    $review = $savedReview;
                }
            } catch (\Throwable) {}
        }

        $triage = null;
        if ($pull !== null) {
            $policyFile = $this->project.'/.proofpatchrc.json';
            $policy = is_file($policyFile) ? json_decode((string) file_get_contents($policyFile), true) ?? [] : [];
            $analyzer = new \ProofPatch\GitHub\PrTriageAnalyzer($policy);
            $candidatePulls = [];
            foreach (glob($this->snapshotsDir.'/*.json') ?: [] as $candFile) {
                try {
                    $candData = (new PullRequestSnapshot($this->snapshotsDir))->load(basename($candFile, '.json'));
                    // Strictly fail closed: never leak private or unknown-visibility snapshot metadata into unauthenticated triage
                    if (!\ProofPatch\Application\Repository\SnapshotVisibilityPolicy::isExplicitlyPublic($candData)) {
                        continue;
                    }
                    $candPull = $candData['snapshot']['pullRequest'] ?? null;
                    if (\ProofPatch\Application\Repository\TriageCandidates::isDifferentPull($candPull, $pull)) {
                        $candidatePulls[] = $candPull;
                    }
                } catch (\Throwable) {}
            }
            $triage = $analyzer->triage($pull, $candidatePulls);
        }

        $githubAuth = $this->getSession($request, 'github_auth');
        $deviceFlow = $this->getSession($request, 'github_device_flow');
        $clientIdConfigured = (bool) (getenv('PROOFPATCH_GITHUB_CLIENT_ID') || getenv('GITHUB_APP_CLIENT_ID'));
        $selectedRepo = $this->getSession($request, 'selected_github_repo');
        $access = $request->attributes->get('proofpatch_github_access');

        $githubConnection = \ProofPatch\Application\Settings\GitHubConnectionProjection::build([
            'auth' => $githubAuth,
            'deviceFlow' => $deviceFlow,
            'clientIdConfigured' => $clientIdConfigured,
            'clientId' => getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: null,
            'selectedRepo' => $selectedRepo,
            'statusMessage' => session('github_status') ?? $this->getSession($request, 'github_status'),
            'errorMessage' => session('github_error') ?? $this->getSession($request, 'github_error'),
            'extras' => [
                'canCapturePrivateTestRepo' => \ProofPatch\Application\Repository\PrivateCapturePolicy::canCapturePrivateTestRepo(
                    $access, $selectedRepo, DedicatedTestRepo::name(), PrivatePullSession::usesServerSession()
                ),
                'repoPulls' => $this->getSession($request, 'github_repo_pulls') ?? [],
                'repoPullsStatus' => $this->getSession($request, 'github_repo_pulls_status') ?? null,
            ],
        ]);
        $this->forgetSession($request, 'github_status', 'github_error');

        $commentApprovals = \ProofPatch\Application\Review\CommentApprovalDigests::forDrafts(
            $access,
            $pull,
            $this->getSession($request, 'review_drafts', []),
            DedicatedTestRepo::name()
        );

        $autoReview = $this->scopedAutoReviewProps($request);

        return Inertia::render('Repositories', [
            'pull' => $pull,
            'snapshot' => $snapshot,
            'triage' => $triage,
            'patchPreview' => $patchPreview,
            'patchPreviewError' => $patchPreviewError,
            'reviewPreview' => $reviewPreview,
            'review' => $review,
            'reviewError' => $reviewError,
            'drafts' => array_values($this->getSession($request, 'review_drafts', [])),
            'commentApprovals' => $commentApprovals,
            'commentPosted' => $this->getSession($request, 'comment_posted') === true,
            'budget' => $budget,
            'modelKeyAvailable' => $modelKeyAvailable,
            'githubConnection' => $githubConnection,
            'autoReview' => $autoReview,
            'error' => $error ?: null,
        ]);
    }

    public function checks(Request $request): InertiaResponse|\Illuminate\Http\RedirectResponse
    {
        $runsDir = $this->runsDir;
        $runDir = static fn(string $id): ?string => \ProofPatch\Application\Evidence\CheckRunDirectory::resolve($runsDir, $id);

        if ($request->isMethod('post')) {
            try {
                if ($request->input('action') === 'propose') {
                    $id = (string) $request->input('source_run', '');
                    $dir = $runDir($id);
                    $home = getenv('HOME');
                    if ($dir === null || !$home) {
                        throw new \InvalidArgumentException('Select a run with a reproduced baseline first.');
                    }
                    $sourceReport = json_decode((string) file_get_contents($dir.'/result.json'), true, 64, JSON_THROW_ON_ERROR);
                    if (!\ProofPatch\Application\Evidence\PublicCheckRunPolicy::isPublic($sourceReport)) {
                        throw new \InvalidArgumentException('This run is unavailable.');
                    }
                    if (($sourceReport['proposal']['file'] ?? null) !== $request->input('fixture')) {
                        throw new \InvalidArgumentException('Run the selected fixture first so the model sees its own baseline.');
                    }
                    $agent = new ModelProposal($this->project, HttpClient::create(), RequestBudget::forRuntime());
                    $this->putSession($request, 'proposal', $agent->propose($dir, Credentials::baseten()));

                    return redirect('/checks?run='.rawurlencode($id));
                }

                $fixture = (string) $request->input('fixture');
                if (!in_array($fixture, CssPatch::FILES, true)) {
                    throw new \InvalidArgumentException('Choose a supported checkout fixture.');
                }
                $source = file_get_contents($this->project.'/'.$fixture);
                if ($source === false) {
                    throw new \RuntimeException('The checkout fixture is unavailable.');
                }
                $patch = CssPatch::fromArray([
                    'file' => $fixture,
                    'baseSha256' => hash('sha256', $source),
                    'find' => $request->input('find'),
                    'replace' => $request->input('replace'),
                ]);
                $generated = $this->getSession($request, 'proposal');
                $provenance = \ProofPatch\Application\Evidence\ModelProvenancePolicy::forPatch(
                    $generated, $patch->toArray(), $request->input('source_run')
                );
                $skill = ($this->hari ?? \Hari\Hari::registry())->resolve('verify.patch', 1, 'web');
                if (!$skill instanceof \ProofPatch\Abilities\VerifyPatchSkill) {
                    throw new \RuntimeException('Patch verification skill is unavailable.');
                }
                $report = $skill->withVerifier(new CheckoutVerifier($this->project))
                    ->invoke(['patch' => $patch->toArray(), 'provenance' => $provenance]);

                return redirect('/checks?run='.rawurlencode($report['id']));
            } catch (\Throwable $exception) {
                $currentRun = (string) $request->input('source_run', '');
                return redirect('/checks'.($currentRun ? '?run='.rawurlencode($currentRun) : ''))->with('error', $exception->getMessage());
            }
        }

        $runId = (string) $request->query('run', '');
        $history = [];
        $latestVisualRunId = null;
        $runFiles = glob($this->runsDir.'/*/result.json') ?: [];
        rsort($runFiles, SORT_STRING);
        $runFiles = \ProofPatch\Application\Evidence\CheckRunHistory::prioritizeRequested($runFiles, $runId, $this->runsDir);
        foreach ($runFiles as $file) {
            if (count($history) >= 20 && ($runId === '' || isset($history[$runId]))) {
                break;
            }
            $id = basename(dirname($file));
            if (isset($history[$id]) || $runDir($id) === null || is_link($file) || filesize($file) > 1048576) {
                continue;
            }
            try {
                $run = (new RunEvidence())->read(dirname($file));
                if (!\ProofPatch\Application\Evidence\PublicCheckRunPolicy::isPublic($run['report'])) {
                    continue;
                }
                $runPath = dirname($file);
                $hasVisual = is_file($runPath.'/baseline/checkout.png') && is_file($runPath.'/candidate/checkout.png');
                $history[$id] = array_merge($run['report'], [
                    'integrityValid' => $run['receipt'] !== null,
                    'hasVisual' => $hasVisual,
                ]);
            } catch (\Throwable) {}
        }
        krsort($history, SORT_STRING);
        $history = array_slice($history, 0, 20, true);

        // Find the latest verified run with visual screenshots
        $latestVisualRunId = \ProofPatch\Application\Evidence\CheckRunSelection::latestVisualRunId($history);

        if ($runId === '' && $history !== []) {
            // Default to the latest verified visual checkout run if present, or first verified run
            $runId = \ProofPatch\Application\Evidence\CheckRunSelection::defaultRunId($history, $latestVisualRunId);
        }
        $report = $history[$runId] ?? null;
        $selectedHasVisual = $report !== null && is_file($this->runsDir.'/'.$runId.'/baseline/checkout.png') && is_file($this->runsDir.'/'.$runId.'/candidate/checkout.png');
        $currentHead = trim(LocalProcess::output(['git', '-C', $this->project, 'rev-parse', 'HEAD']));
        $baselineReady = \ProofPatch\Application\Evidence\CheckRunHistory::isBaselineReady($report, $currentHead);
        $budgetStatus = $this->hariBudget();

        $proposal = $this->getSession($request, 'proposal');
        if (!is_array($proposal) || ($proposal['sourceRunId'] ?? null) !== $runId) {
            $proposal = null;
        }
        $fixture = \ProofPatch\Application\Evidence\CheckRunSelection::fixture($request->input('fixture'), $proposal, $report);
        $receipt = null;
        $receiptError = null;
        if ($report !== null && $runDir($runId) !== null) {
            try {
                $outcome = ($this->hari ?? \Hari\Hari::registry())
                    ->resolve('evidence.verify', 1, 'web')
                    ->invoke(['directory' => $runDir($runId)]);
                [$receipt, $receiptError] = \ProofPatch\Application\Evidence\CheckRunHistory::receiptOutcome($outcome);
            } catch (\Throwable $exception) {
                $receiptError = $exception->getMessage();
            }
        }

        return Inertia::render('Checks', [
            'runId' => $runId,
            'fixture' => $fixture,
            'report' => $report,
            'receipt' => $receipt,
            'receiptError' => $receiptError,
            'history' => $history,
            'budgetStatus' => $budgetStatus,
            'baselineReady' => $baselineReady,
            'proposal' => $proposal,
            'hasVisual' => $selectedHasVisual,
            'latestVisualRunId' => $latestVisualRunId,
            'error' => session('error'),
        ]);
    }

    public function reviewPullRequest(Request $request): \Illuminate\Http\RedirectResponse
    {
        $pull = $this->getSession($request, 'pullRequest');
        if (is_array($pull) && ($pull['isPrivate'] ?? true) !== false) {
            return redirect('/repositories')->with('error', 'Private pull request content cannot be sent to a model.');
        }
        $snapshot = $this->getSession($request, 'pullRequestSnapshot');
        if (!is_array($pull) || !is_array($snapshot)) {
            return redirect('/repositories')->with('error', 'Capture or load a pull request before requesting a review.');
        }

        try {
            \ProofPatch\Application\Review\ManualReviewGuards::validate(
                $request->boolean('confirm'), $request->input('digest', ''), $request->input('approval_token', '')
            );

            $digest = (string) $request->input('digest', '');
            $approvalToken = (string) $request->input('approval_token', '');

            // ATOMIC SERVER-HELD SINGLE-USE CONSUMPTION BEFORE PROVIDER CALL
            // Strictly consumes the approval token and fails closed on replays or duplicates.
            $approvalStore = $this->approvalStore();
            $approvalStore->consume(
                $approvalToken,
                (string) $pull['repository'],
                (int) $pull['number'],
                (string) $pull['headSha'],
                $digest,
            );
            $this->forgetSession($request, 'review_approval_token');

            $cachedPreview = $this->getSession($request, 'patch_preview');
            $resolution = \ProofPatch\Application\Review\PatchPreviewResolution::resolve($cachedPreview, null, $pull);
            if ($resolution->decision === \ProofPatch\Application\Review\PatchPreviewResolution::USE_CACHED) {
                $patchPreview = $resolution->preview;
            } else {
                if ($resolution->decision === \ProofPatch\Application\Review\PatchPreviewResolution::STALE_PRIVATE) {
                    throw new \RuntimeException('Private pull request content cannot be sent to a model.');
                }
                $patchPreview = (new PublicPatchPreview(HttpClient::create()))->fetch($pull);
                $this->putSession($request, 'patch_preview', $patchPreview);
            }
            if (($patchPreview['isPrivate'] ?? true) !== false) {
                throw new \RuntimeException('Private pull request content cannot be sent to a model.');
            }
            $reviewAgent = new PullRequestReview(HttpClient::create(), $this->budget());
            $key = Credentials::baseten();
            $privateConsent = (bool) $request->input('private_consent', false);

            $result = $reviewAgent->review($pull, $patchPreview, $digest, $key, $privateConsent);
            $result['source'] = 'manual';
            $this->putSession($request, 'pr_review', $result);

            return redirect('/repositories?preview=1');
        } catch (\Throwable $exception) {
            return redirect('/repositories?preview=1')->with('review_error', $exception->getMessage());
        }
    }

    public function clearReview(Request $request = null): \Illuminate\Http\RedirectResponse
    {
        $this->forgetSession($request, 'pr_review');
        return redirect('/repositories?preview=1');
    }

    public function mergePull(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->pullAction($request, 'merge');
    }

    public function closePull(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->pullAction($request, 'close');
    }

    private function pullAction(Request $request, string $action): \Illuminate\Http\RedirectResponse
    {
        $auth = $this->getSession($request, 'github_auth');
        $pull = $this->getSession($request, 'pullRequest');
        $access = $request->attributes->get('proofpatch_github_access');
        $expectedHead = (string) $request->input('head_sha', '');
        $guardError = \ProofPatch\Application\Repository\PullActionState::guardError($request->input('confirmed'), $auth, $pull, $access);
        if ($guardError !== null) {
            return redirect('/repositories')->with('error', $guardError);
        }
        try {
            $actions = new \ProofPatch\GitHub\PullRequestActions(HttpClient::create());
            $number = (int) ($pull['number'] ?? 0);
            if ($action === 'merge') {
                $result = $actions->merge($auth['token'], $access, $pull, $expectedHead);
                $pull = \ProofPatch\Application\Repository\PullActionState::appliedPull($pull, $action);
                $message = "Pull request #{$number} was merged (commit ".substr($result['sha'], 0, 7).').';
            } else {
                $actions->close($auth['token'], $access, $pull, $expectedHead);
                $pull = \ProofPatch\Application\Repository\PullActionState::appliedPull($pull, $action);
                $message = "Pull request #{$number} was closed without merging.";
            }
            $this->putSession($request, 'pullRequest', $pull);
            $repoPulls = $this->getSession($request, 'github_repo_pulls');
            if (is_array($repoPulls)) {
                $this->putSession($request, 'github_repo_pulls', \ProofPatch\Application\Repository\PullActionState::syncedRepoPulls($repoPulls, $number, $pull['state']));
            }
            $this->addNotification($request, 'success', $action === 'merge' ? 'Merged' : 'Closed', $message);

            return redirect('/repositories')->with('status', $message);
        } catch (\Throwable $e) {
            return redirect('/repositories')->with('error', $e->getMessage());
        }
    }

    public function saveDraftComment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $pull = $this->getSession($request, 'pullRequest');
        if (is_array($pull) && ($pull['isPrivate'] ?? false) === true && !PrivatePullSession::allows($request, $pull)) {
            PrivatePullSession::clear($request);
            return redirect('/repositories')->with('error', 'Connect GitHub and grant access to this repository.');
        }
        if (!is_array($pull)) {
            return redirect('/repositories')->with('error', 'Capture or load a pull request before drafting comments.');
        }

        try {
            $input = \ProofPatch\Application\Review\SaveDraftCommentInput::fromRaw([
                'path' => (string) $request->input('path', ''),
                'comment' => (string) $request->input('comment', ''),
                'headSha' => (string) $request->input('headSha', ''),
                'line' => $request->input('line'),
                'side' => (string) $request->input('side', 'RIGHT'),
            ], $pull);

            $cachedPreview = $this->getSession($request, 'patch_preview');
            $snapshot = $this->getSession($request, 'pullRequestSnapshot');
            $diskPreviewFile = ($pull['isPrivate'] ?? false) !== true && is_array($snapshot) && isset($snapshot['id']) ? $this->snapshotsDir.'/'.$snapshot['id'].'-preview.json' : null;
            $diskPreview = null;
            if (!is_array($cachedPreview) && $diskPreviewFile && is_file($diskPreviewFile)) {
                try {
                    $diskData = json_decode((string) file_get_contents($diskPreviewFile), true, 64, JSON_THROW_ON_ERROR);
                    $diskPreview = is_array($diskData) ? $diskData : null;
                } catch (\Throwable) {}
            }

            $resolution = \ProofPatch\Application\Review\PatchPreviewResolution::resolve($cachedPreview, $diskPreview, $pull);
            if ($resolution->decision === \ProofPatch\Application\Review\PatchPreviewResolution::USE_CACHED) {
                $patchPreview = $resolution->preview;
            } elseif ($resolution->decision === \ProofPatch\Application\Review\PatchPreviewResolution::USE_DISK) {
                $patchPreview = $resolution->preview;
                $this->putSession($request, 'patch_preview', $patchPreview);
            } else {
                if ($resolution->decision === \ProofPatch\Application\Review\PatchPreviewResolution::STALE_PRIVATE) {
                    throw new \RuntimeException('Open the private pull request again before adding comments.');
                }
                $patchPreview = (new PublicPatchPreview(HttpClient::create()))->fetch($pull);
                $this->putSession($request, 'patch_preview', $patchPreview);
            }
            $outcome = (new \ProofPatch\Application\Review\SaveDraftCommentUseCase())
                ->execute($input->withPreview($patchPreview));
            if (!$outcome->ok) {
                throw new \InvalidArgumentException($outcome->error);
            }

            $drafts = $this->getSession($request, 'review_drafts', []);
            $drafts[$outcome->draft['id']] = $outcome->draft;
            $this->putSession($request, 'review_drafts', $drafts);
            $message = ($pull['isPrivate'] ?? false) === true
                ? 'Draft comment saved.' : "Draft comment saved on {$input->path}".($input->line ? ":{$input->line}" : '');
            $this->addNotification($request, 'success', 'Draft Saved', $message);

            return redirect(\ProofPatch\Application\Review\ReviewPreviewRedirect::to($snapshot));
        } catch (\Throwable $exception) {
            $snapshot = $this->getSession($request, 'pullRequestSnapshot');
            $message = ($pull['isPrivate'] ?? false) === true ? 'Could not save this draft. Check the selected line.' : $exception->getMessage();
            $this->putSession($request, 'review_error', $message);
            return redirect(\ProofPatch\Application\Review\ReviewPreviewRedirect::to($snapshot))->with('review_error', $message);
        }
    }

    public function deleteDraftComment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $id = (string) $request->input('id', '');
        $drafts = $this->getSession($request, 'review_drafts', []);
        if (isset($drafts[$id])) {
            unset($drafts[$id]);
            $this->putSession($request, 'review_drafts', $drafts);
            $this->addNotification($request, 'info', 'Draft Removed', 'Draft comment was removed.');
        }
        $snapshot = $this->getSession($request, 'pullRequestSnapshot');
        return redirect(\ProofPatch\Application\Review\ReviewPreviewRedirect::to($snapshot));
    }

    public function clearDraftComments(Request $request = null): \Illuminate\Http\RedirectResponse
    {
        $this->forgetSession($request, 'review_drafts');
        $this->addNotification($request, 'info', 'Drafts Cleared', 'All draft review comments were cleared.');
        $snapshot = $this->getSession($request, 'pullRequestSnapshot');
        return redirect(\ProofPatch\Application\Review\ReviewPreviewRedirect::to($snapshot));
    }

    public function initiateDeviceFlow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = \ProofPatch\Application\Settings\AppRedirectTarget::target($request->input('redirect_to'), '/repositories');
        try {
            $clientId = \ProofPatch\Application\Settings\DeviceFlowClientId::resolve(
                $request->input('client_id'), getenv('PROOFPATCH_GITHUB_CLIENT_ID'), getenv('GITHUB_APP_CLIENT_ID')
            );

            $flow = $this->getDeviceFlowClient();
            $initData = $flow->initiate($clientId);

            $flowData = [
                'clientId' => $clientId,
                'deviceCode' => $initData['deviceCode'],
                'userCode' => $initData['userCode'],
                'verificationUri' => $initData['verificationUri'],
                'expiresAt' => time() + $initData['expiresIn'],
                'interval' => $initData['interval'],
            ];
            $this->putSession($request, 'github_device_flow', $flowData);
            $this->forgetSession($request, 'github_auth', 'selected_github_repo', 'github_repo_pulls');
            $this->addNotification($request, 'info', 'Device Flow Started', "Enter code {$initData['userCode']} at GitHub to authorize.");

            return redirect($target)->with('github_status', 'Device flow initiated. Enter code '.$initData['userCode'].' on GitHub.');
        } catch (\Throwable $e) {
            $this->putSession($request, 'github_error', $e->getMessage());
            return redirect($target)->with('github_error', $e->getMessage());
        }
    }

    public function pollDeviceFlow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = \ProofPatch\Application\Settings\AppRedirectTarget::target($request->input('redirect_to'), '/repositories');
        $flow = $this->getSession($request, 'github_device_flow');
        $sessionState = \ProofPatch\Application\Settings\DeviceFlowSession::check($flow, time());
        if ($sessionState === 'missing') {
            return redirect($target)->with('github_error', 'No active device flow session. Please initiate first.');
        }

        if ($sessionState === 'expired') {
            $this->forgetSession($request, 'github_device_flow');
            return redirect($target)->with('github_error', 'Verification code expired. Please initiate connection again.');
        }

        try {
            $deviceFlow = $this->getDeviceFlowClient();
            $result = $deviceFlow->poll((string) $flow['clientId'], (string) $flow['deviceCode']);
            $outcome = \ProofPatch\Application\Settings\DeviceFlowPoll::outcome($result);

            if ($outcome === 'authorized') {
                return $this->completeGitHubConnection($request, (string) $result['accessToken'], $target);
            }

            if ($outcome === 'pending') {
                return redirect($target)->with('github_status', 'Authorization pending. Enter user code '.$flow['userCode'].' at '.$flow['verificationUri'].'.');
            }

            if ($outcome === 'slow_down') {
                $flow['interval'] = $result['interval'] ?? 10;
                $this->putSession($request, 'github_device_flow', $flow);
                return redirect($target)->with('github_status', 'Rate limited by GitHub. Please wait a few seconds before checking again.');
            }

            if ($outcome === 'expired') {
                $this->forgetSession($request, 'github_device_flow');
                return redirect($target)->with('github_error', 'Verification code expired. Please initiate connection again.');
            }

            if ($outcome === 'denied') {
                $this->forgetSession($request, 'github_device_flow');
                return redirect($target)->with('github_error', 'Authorization was declined on GitHub.');
            }

            return redirect($target)->with('github_error', 'GitHub authorization error: '.($result['error'] ?? 'Unknown error'));
        } catch (\Throwable $e) {
            return redirect($target)->with('github_error', 'Failed to poll GitHub: '.$e->getMessage());
        }
    }

    /**
     * Finishes any GitHub sign-in (device or web flow) once a token exists:
     * verify the user, load the granted repositories, save the shared
     * sign-in, and return to the page the user started from.
     */
    private function completeGitHubConnection(Request $request, string $token, string $target): \Illuminate\Http\RedirectResponse
    {
        $client = $this->getGitHubAppClient();
        $user = $client->getCurrentUser($token);
        $reposStatus = [];
        $repos = $client->listSelectedRepositories($token, $reposStatus);

        $this->forgetSession($request, ...array_merge(IssueWorkbenchController::CASE_SESSION_KEYS,
            ['selected_github_repo', 'github_repo_issues', 'github_repo_pulls', 'notifications']));

        $authData = [
            'token' => $token,
            'user' => $user,
            'repositories' => $repos,
            'repositoriesStatus' => $reposStatus,
            'connectedAt' => gmdate(DATE_ATOM),
            'verified_token_sha256' => hash('sha256', $token),
            'verified_at' => time(),
        ];
        $this->putSession($request, 'github_auth', $authData);
        \ProofPatch\GitHub\GitHubTokenStore::save($token, $user, $repos, $reposStatus);
        try {
            // A new grant invalidates any previous shared selection.
            \ProofPatch\Support\KernelCli::clearSelectedRepo();
        } catch (\Throwable) {
        }
        $this->forgetSession($request, 'github_device_flow');
        $this->addNotification($request, 'success', 'GitHub Connected', "Successfully connected as @{$user['login']}.");

        $warn = \ProofPatch\Application\Repository\GitHubListWarning::message(
            $reposStatus,
            'Repositories list was truncated by pagination limits.',
            'Some repositories could not be retrieved: '
        );
        if ($warn !== null) {
            $this->addNotification($request, 'warning', 'Repositories Incomplete', $warn);
        }

        return redirect($target)->with('github_status', 'Successfully connected to GitHub as @'.$user['login'].'.');
    }

    /** Flow errors are flashed under both keys: each origin page reads one. */
    private function githubFlowError(string $target, string $message): \Illuminate\Http\RedirectResponse
    {
        return redirect($target)->with('github_error', $message)->with('error', $message);
    }

    /**
     * Web sign-in, step 1: remember where the user started, then send the
     * browser to GitHub. The entry point of the "Sign in with GitHub"
     * button. No credentials in the URL; the secret stays server-side.
     */
    public function redirectToGitHub(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = \ProofPatch\Application\Settings\AppRedirectTarget::target($request->query('redirect_to'), '/issues');
        try {
            $clientId = \ProofPatch\Application\Settings\DeviceFlowClientId::resolve(
                $request->query('client_id'), getenv('PROOFPATCH_GITHUB_CLIENT_ID'), getenv('GITHUB_APP_CLIENT_ID')
            );
        } catch (\Throwable $e) {
            return $this->githubFlowError($target, $e->getMessage());
        }
        if ($this->githubClientSecret() === null) {
            return $this->githubFlowError($target, 'Web sign-in is not configured: add the GitHub App client secret as PROOFPATCH_GITHUB_CLIENT_SECRET in .env, then retry.');
        }

        $state = bin2hex(random_bytes(32));
        $this->putSession($request, 'github_oauth', ['state' => $state, 'returnTo' => $target, 'createdAt' => time()]);

        return redirect($this->getWebFlowClient()->authorizeUrl($clientId, $this->githubCallbackUrl(), $state));
    }

    /**
     * Web sign-in, step 2: GitHub returns here with a code. The state is
     * single-use and short-lived; the return page comes from the session,
     * never from the query string.
     */
    public function handleGitHubCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        $oauth = $this->getSession($request, 'github_oauth');
        $target = \ProofPatch\Application\Settings\AppRedirectTarget::target(
            is_array($oauth) ? ($oauth['returnTo'] ?? null) : null,
            '/issues'
        );
        // State is single-use whatever happens next.
        $this->forgetSession($request, 'github_oauth');

        if ($request->query('error') !== null) {
            $message = $request->query('error') === 'access_denied'
                ? 'Authorization was declined on GitHub.'
                : 'GitHub authorization failed: ' . ((string) $request->query('error_description', 'please try again.'));
            return $this->githubFlowError($target, $message);
        }

        $expected = is_array($oauth) ? (string) ($oauth['state'] ?? '') : '';
        $given = (string) $request->query('state', '');
        $fresh = is_array($oauth) && is_int($oauth['createdAt'] ?? null) && $oauth['createdAt'] >= time() - 600;
        if ($expected === '' || $given === '' || !hash_equals($expected, $given) || !$fresh) {
            return $this->githubFlowError($target, 'Sign-in could not be verified. Please try again.');
        }

        try {
            $clientId = \ProofPatch\Application\Settings\DeviceFlowClientId::resolve(
                null, getenv('PROOFPATCH_GITHUB_CLIENT_ID'), getenv('GITHUB_APP_CLIENT_ID')
            );
            $secret = $this->githubClientSecret();
            if ($secret === null) {
                throw new \RuntimeException('Web sign-in is not configured: add the GitHub App client secret as PROOFPATCH_GITHUB_CLIENT_SECRET in .env, then retry.');
            }
            $tokenData = $this->getWebFlowClient()->exchangeCode($clientId, $secret, (string) $request->query('code', ''), $this->githubCallbackUrl());

            return $this->completeGitHubConnection($request, $tokenData['accessToken'], $target);
        } catch (\Throwable $e) {
            return $this->githubFlowError($target, 'Failed to connect GitHub: ' . $e->getMessage());
        }
    }

    public function disconnectDeviceFlowRequest(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->disconnectDeviceFlow($request);
    }

    public function disconnectDeviceFlow(?Request $request = null): \Illuminate\Http\RedirectResponse
    {
        $auth = $this->getSession($request, 'github_auth');
        $userId = is_array($auth) ? ($auth['user']['id'] ?? null) : null;
        if (is_int($userId) && $userId > 0 && \Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
            \ProofPatch\Models\AutomaticInlineSetting::revokeAllForUser($userId);
        }
        $currentPull = $this->getSession($request, 'pullRequest');
        if (is_array($currentPull) && (($currentPull['isPrivate'] ?? false) === true)) {
            $this->forgetSession(
                $request,
                'pullRequest',
                'pullRequestSnapshot',
                'patch_preview',
                'pr_review',
                'review_drafts',
                'private_pull_owner',
                'review_approval_token'
            );
        }

        $this->forgetSession(
            $request,
            'github_auth',
            'github_device_flow',
            'selected_github_repo',
            'github_repo_issues',
            'github_repo_pulls',
            'github_repo_pulls_status'
        );
        $this->forgetSession($request, ...array_merge(IssueWorkbenchController::CASE_SESSION_KEYS, ['notifications']));
        \ProofPatch\GitHub\GitHubTokenStore::clear();
        try {
            \ProofPatch\Support\KernelCli::clearSelectedRepo();
        } catch (\Throwable) {
        }
        $this->addNotification($request, 'info', 'GitHub Disconnected', 'Disconnected from GitHub device session.');

        $target = \ProofPatch\Application\Settings\DisconnectRedirect::target(
            $request !== null ? $request->input('redirect_to', '') : ''
        );

        return redirect($target)->with('github_status', 'Disconnected from GitHub.');
    }

    public function selectInstalledRepository(Request $request): \Illuminate\Http\RedirectResponse
    {
        $auth = $this->getSession($request, 'github_auth');
        if (!is_array($auth) || !isset($auth['token'])) {
            return redirect('/repositories')->with('github_error', 'GitHub account is not connected.');
        }

        $repoName = trim((string) $request->input('repository', ''));
        $authorizedRepos = $auth['repositories'] ?? [];
        $found = \ProofPatch\Application\Repository\AuthorizedRepository::find(
            is_array($authorizedRepos) ? $authorizedRepos : [],
            $repoName
        );

        if ($found === null) {
            return redirect('/repositories')->with('github_error', 'Selected repository is not authorized for this installation.');
        }

        try {
            $client = $this->getGitHubAppClient();
            $issueStatus = [];
            $issues = $client->listOpenIssues((string) $auth['token'], $repoName, $issueStatus, 'all');
            $pullsStatus = [];
            $pulls = $client->listOpenPullRequests((string) $auth['token'], $repoName, $pullsStatus, 'all');

            $previousRepo = $this->getSession($request, 'selected_github_repo');
            if (is_string($previousRepo) && strcasecmp($previousRepo, $repoName) !== 0) {
                $this->forgetSession(
                    $request,
                    'pullRequest',
                    'pullRequestSnapshot',
                    'patch_preview',
                    'pr_review',
                    'pr_review_preview',
                    'review_drafts',
                    'review_approval_token',
                    'proposal',
                    'private_pull_owner'
                );
            }
            $this->putSession($request, 'selected_github_repo', $repoName);
            $this->putSession($request, 'github_repo_issues', $issues);
            $this->putSession($request, 'github_repo_pulls', $pulls);
            $this->putSession($request, 'github_repo_pulls_status', $pullsStatus);
            try {
                // The terminal reads this same selection file.
                \ProofPatch\Support\KernelCli::setSelectedRepo($repoName);
            } catch (\Throwable) {
            }

            $warn = \ProofPatch\Application\Repository\GitHubListWarning::message(
                $pullsStatus,
                'Pull requests list was capped at pagination limit (500).',
                'Some pull requests could not be retrieved: '
            );
            if ($warn !== null) {
                $this->addNotification($request, 'warning', 'Pull Requests Incomplete', $warn);
            }

            $this->addNotification($request, 'info', 'Repository Selected', 'Loaded '.count($issues).' issues and '.count($pulls).' pull requests for '.$repoName.'.');

            return redirect('/repositories')->with('github_status', 'Loaded '.count($issues).' issues and '.count($pulls).' pull requests for '.$repoName.'.');
        } catch (\Throwable $e) {
            return redirect('/repositories')->with('github_error', 'Failed to list issues and pull requests: '.$e->getMessage());
        }
    }

    public function captureInstalledPullRequest(Request $request): \Illuminate\Http\RedirectResponse
    {
        $auth = $this->getSession($request, 'github_auth');
        if (!is_array($auth) || !isset($auth['token'])) {
            return redirect('/repositories')->with('github_error', 'GitHub account is not connected.');
        }

        $repoName = trim((string) $request->input('repository', ''));
        $number = (int) $request->input('number', 0);
        if ($number <= 0) {
            return redirect('/repositories')->with('github_error', 'A valid pull request number is required.');
        }

        $authorizedRepos = $auth['repositories'] ?? [];
        $foundRepo = \ProofPatch\Application\Repository\AuthorizedRepository::find(
            is_array($authorizedRepos) ? $authorizedRepos : [],
            $repoName
        );

        if ($foundRepo === null) {
            $this->putSession($request, 'github_error', 'Selected repository is not authorized for this installation.');
            return redirect('/repositories')->with('github_error', 'Selected repository is not authorized for this installation.');
        }

        $private = ($foundRepo['isPrivate'] ?? true) !== false;
        $access = $request->attributes->get('proofpatch_github_access');
        $denial = \ProofPatch\Application\Repository\PrivateCapturePolicy::denial(
            $private,
            PrivatePullSession::usesServerSession(),
            is_array($access) ? $access : null,
            $auth['user']['id'] ?? null,
            $repoName,
            DedicatedTestRepo::name()
        );
        if ($denial !== null) {
            $this->putSession($request, 'github_error', $denial);
            return redirect('/repositories')->with('github_error', $denial);
        }

        try {
            $client = $this->getGitHubAppClient();
            $token = (string) $auth['token'];

            $patchPreview = $client->fetchPullRequestPatch($token, $repoName, $number);

            // A visibility change during capture must not fall through to public storage.
            if (($patchPreview['isPrivate'] ?? true) !== $private) {
                $msg = 'Repository visibility changed. Select the repository again.';
                $this->putSession($request, 'github_error', $msg);
                return redirect('/repositories')->with('github_error', $msg);
            }

            // Construct pull request record strictly from authoritative GitHub metadata, rejecting untrusted client inputs
            $pull = \ProofPatch\Application\Repository\PullRecordProjection::fromPatchPreview($patchPreview, $repoName, $number, $private);

            if ($private) {
                $this->forgetSession($request, ...PrivatePullSession::KEYS);
                $this->putSession($request, 'private_pull_owner', $access['userId']);
                $this->putSession($request, 'pullRequest', $pull);
                $this->putSession($request, 'patch_preview', $patchPreview);
                return redirect('/repositories?preview=1');
            }

            $snapshot = (new PullRequestSnapshot($this->snapshotsDir))->capture($pull);
            $this->forgetSession($request, ...PrivatePullSession::KEYS);
            $this->putSession($request, 'pullRequest', $pull);
            $this->putSession($request, 'pullRequestSnapshot', $snapshot);
            $this->putSession($request, 'patch_preview', $patchPreview);
            $this->forgetSession($request, 'pr_review', 'review_drafts');

            $diskPreviewFile = $this->snapshotsDir.'/'.$snapshot['id'].'-preview.json';
            @file_put_contents($diskPreviewFile, json_encode($patchPreview, JSON_PRETTY_PRINT));
            $this->addNotification($request, 'success', 'PR Captured', "Captured snapshot for {$repoName}#{$number}");

            return redirect('/repositories?preview=1&snapshot='.$snapshot['id']);
        } catch (\Throwable $e) {
            return redirect('/repositories')->with('github_error', $private ? 'Unable to load the private pull request.' : 'Failed to capture pull request: '.$e->getMessage());
        }
    }

    public function settings(Request $request): InertiaResponse
    {
        $githubAuth = $this->getSession($request, 'github_auth');
        $deviceFlow = $this->getSession($request, 'github_device_flow');
        $clientIdConfigured = (bool) (getenv('PROOFPATCH_GITHUB_CLIENT_ID') || getenv('GITHUB_APP_CLIENT_ID'));

        $githubConnection = \ProofPatch\Application\Settings\GitHubConnectionProjection::build([
            'auth' => $githubAuth,
            'deviceFlow' => $deviceFlow,
            'clientIdConfigured' => $clientIdConfigured,
            'clientId' => getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: null,
            'selectedRepo' => $this->getSession($request, 'selected_github_repo'),
            'statusMessage' => session('github_status') ?? $this->getSession($request, 'github_status'),
            'errorMessage' => session('github_error') ?? $this->getSession($request, 'github_error'),
        ]);
        $this->forgetSession($request, 'github_status', 'github_error');

        $budget = $this->hariBudget();

        $policyFile = $this->project.'/.proofpatchrc.json';
        $policy = is_file($policyFile) ? json_decode((string) file_get_contents($policyFile), true) ?? [] : [];

        try {
            foreach (($githubConnection['repositories'] ?? []) as $r) {
                if (is_array($r) && is_string($r['fullName'] ?? null) && ($r['isPrivate'] ?? null) === true) {
                    \ProofPatch\Models\RepositoryAutoReviewSetting::disableForRepository($r['fullName']);
                }
            }
        } catch (\Throwable) {}
        $autoReview = $this->scopedAutoReviewProps($request);

        $providerConfig = \ProofPatch\Agent\ProviderConfigStore::status();
        $statusMessage = session('status') ?? $this->getSession($request, 'status');
        $errorMessage = session('error') ?? $this->getSession($request, 'error');
        $this->forgetSession($request, 'status', 'error');

        $autopilotCaps = null;
        try {
            $row = \ProofPatch\Models\AutopilotBudget::current();
            $autopilotCaps = $row === null ? null : \ProofPatch\Application\Settings\AutopilotBudgetProjection::fromRow($row->max_attempts, $row->max_reserved_micros);
        } catch (\Throwable) {}

        return Inertia::render('Settings', [
            'githubConnection' => $githubConnection,
            'budget' => $budget,
            'policy' => $policy,
            'autoReview' => $autoReview,
            'providerConfig' => $providerConfig,
            'autopilotBudget' => $autopilotCaps,
            'statusMessage' => $statusMessage,
            'errorMessage' => $errorMessage,
        ]);
    }

    public function saveAutopilotBudget(Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $input = \ProofPatch\Application\Settings\SaveAutopilotBudgetInput::fromRaw(
                ['maxAttempts' => $request->input('maxAttempts'), 'maxDollars' => $request->input('maxDollars')],
                \ProofPatch\Models\AutopilotBudget::MAX_ATTEMPTS_CEILING,
                \ProofPatch\Models\AutopilotBudget::MAX_MICROS_CEILING
            );
            $outcome = (new \ProofPatch\Application\Settings\SaveAutopilotBudgetUseCase())->execute($input);

            if ($outcome->clears) {
                \ProofPatch\Models\AutopilotBudget::clearCaps();
            } else {
                \ProofPatch\Models\AutopilotBudget::saveCaps($outcome->maxAttempts, $outcome->maxMicros);
            }
            $msg = $outcome->message;
            $this->putSession($request, 'status', $msg);

            return redirect('/settings')->with('status', $msg);
        } catch (\Throwable $e) {
            $this->putSession($request, 'error', $e->getMessage());
            return redirect('/settings')->with('error', $e->getMessage());
        }
    }

    public function selectProvider(Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $provider = (string) $request->input('provider', '');
            $model = $request->filled('model') ? (string) $request->input('model') : null;
            \ProofPatch\Agent\ProviderConfigStore::selectActive($provider, $model);
            $target = \ProofPatch\Agent\ProviderConfigStore::activeTarget();

            $this->forgetSession($request, 'review_approval_token', 'issue_approval_token', 'card_proposal_preview');
            $msg = "Active Muse provider set to {$target['label']} ({$target['model']}).";
            $this->putSession($request, 'status', $msg);

            return redirect('/settings')->with('status', $msg);
        } catch (\Throwable $e) {
            $this->putSession($request, 'error', $e->getMessage());
            return redirect('/settings')->with('error', $e->getMessage());
        }
    }

    public function saveProviderKey(Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $provider = (string) $request->input('provider', '');
            $apiKey = (string) $request->input('api_key', '');
            \ProofPatch\Agent\ProviderConfigStore::saveKey($provider, $apiKey);
            $label = \ProofPatch\Agent\ProviderConfigStore::PROVIDERS[strtolower(trim($provider))]['label'] ?? strtoupper($provider);
            $msg = "Encrypted API key saved locally for {$label}. No validation request was sent.";
            $this->putSession($request, 'status', $msg);

            return redirect('/settings')->with('status', $msg);
        } catch (\Throwable $e) {
            $this->putSession($request, 'error', $e->getMessage());
            return redirect('/settings')->with('error', $e->getMessage());
        }
    }

    public function removeProviderKey(Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $provider = (string) $request->input('provider', '');
            \ProofPatch\Agent\ProviderConfigStore::removeKey($provider);
            $label = \ProofPatch\Agent\ProviderConfigStore::PROVIDERS[strtolower(trim($provider))]['label'] ?? strtoupper($provider);
            $msg = "Removed stored API key for {$label}.";
            $this->putSession($request, 'status', $msg);

            return redirect('/settings')->with('status', $msg);
        } catch (\Throwable $e) {
            $this->putSession($request, 'error', $e->getMessage());
            return redirect('/settings')->with('error', $e->getMessage());
        }
    }

    public function docs(Request $request): InertiaResponse
    {
        return Inertia::render('Docs');
    }

    public function clearNotifications(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->forgetSession($request, 'notifications');
        return back()->with('success', 'Notifications cleared.');
    }

    private function addNotification(?Request $request, string $type, string $title, string $message, ?string $link = null): void
    {
        NotificationHelper::add($request, $type, $title, $message, $link);
    }

    private function budget(): RequestBudget
    {
        return RequestBudget::forRuntime();
    }

    private function scopedAutoReviewProps(Request $request): array
    {
        return \ProofPatch\Support\AutoReviewPanelProps::forRequest($request, 10, $this->hari);
    }

    /** @return array<string, mixed>|null */
    private function hariBudget(): ?array
    {
        try {
            $status = ($this->hari ?? \Hari\Hari::registry())->resolve('budget.status', 1, 'web')->invoke([]);
        } catch (\Throwable) {
            return null;
        }
        return is_array($status) ? $status : null;
    }

    private function approvalStore(): SingleUseReviewApproval
    {
        return new SingleUseReviewApproval($this->approvalsDir);
    }

    public function image(Request $request): Response
    {
        $runId = (string) $request->query('run', '');
        $imageType = (string) $request->query('image', '');

        return $this->serveImage($runId, $imageType);
    }

    public function imageByPath(string $runId, string $image): Response
    {
        return $this->serveImage($runId, $image);
    }

    private function serveImage(string $runId, string $imageType): Response
    {
        $folder = \ProofPatch\Application\Evidence\CheckRunImage::folderFor($imageType);
        if (!\ProofPatch\Application\Evidence\CheckRunImage::isValidRunId($runId) || $folder === null) {
            return $this->missingImage();
        }
        $reportPath = $this->runsDir.'/'.$runId.'/result.json';
        try {
            $report = json_decode((string) file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($report) || !\ProofPatch\Application\Evidence\PublicCheckRunPolicy::isPublic($report)) {
                throw new \RuntimeException('Image unavailable.');
            }
        } catch (\Throwable) {
            return $this->missingImage();
        }
        $path = \ProofPatch\Application\Evidence\CheckRunImage::resolvePath($this->runsDir, $runId, $folder);
        if ($path === null) {
            return $this->missingImage();
        }

        return response(file_get_contents($path) ?: '', 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function missingImage(): Response
    {
        return response('Image unavailable.', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    private function getSession(?Request $request, string $key, mixed $default = null): mixed
    {
        return SessionHelper::get($request, $key, $default);
    }

    private function putSession(?Request $request, string $key, mixed $value): void
    {
        SessionHelper::put($request, $key, $value);
    }

    private function forgetSession(?Request $request, string ...$keys): void
    {
        SessionHelper::forget($request, ...$keys);
    }
}
