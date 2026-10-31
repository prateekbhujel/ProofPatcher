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
use ProofPatch\Evidence\Receipt;
use ProofPatch\Evidence\RunEvidence;
use ProofPatch\GitHub\DiffHunkParser;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubDeviceFlow;
use ProofPatch\GitHub\PullRequestReader;
use ProofPatch\GitHub\PullRequestSnapshot;
use ProofPatch\GitHub\PublicPatchPreview;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\ManualInlineCommentPublisher;
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
                $pull = $saved['snapshot']['pullRequest'] ?? null;
                if (!is_array($pull) || !is_string($pull['repository'] ?? null) || !is_int($pull['number'] ?? null)
                    || !is_string($pull['title'] ?? null) || !is_string($pull['state'] ?? null)
                    || !is_string($pull['headSha'] ?? null) || !is_string($saved['snapshot']['capturedAt'] ?? null)) {
                    continue;
                }
                // Fail-closed security check: do not expose private or unknown-visibility snapshots on the public overview
                if (!$this->isExplicitlyPublicSnapshot($saved)) {
                    continue;
                }
                $totalSnapshots++;
                $key = $pull['repository'].'#'.$pull['number'];
                $recent[$key] ??= ['id' => $id, 'pull' => $pull, 'capturedAt' => $saved['snapshot']['capturedAt']];
            } catch (\Throwable) {
                continue;
            }
        }

        $runFiles = glob($this->runsDir.'/*/result.json') ?: [];
        $totalRuns = 0;
        $verifiedRuns = 0;
        foreach ($runFiles as $rf) {
            try {
                $run = (new RunEvidence())->read(dirname($rf));
                // Existing private GitHub evidence has no account ownership.
                if (!$this->isPublicCheckRun($run['report'])) {
                    continue;
                }
                $totalRuns++;
                if ($run['receipt'] !== null && $run['report']['decision'] === 'VERIFIED') {
                    $verifiedRuns++;
                }
            } catch (\Throwable) {}
        }

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

                if (!$this->isAuthorizedForSnapshot($request, $saved)) {
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
                    if (isset($cachedPatchPreview['headSha']) && !hash_equals((string) $cachedPatchPreview['headSha'], (string) ($pull['headSha'] ?? ''))) {
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
        if (is_array($cachedPreview) && is_array($pull) && !hash_equals((string) ($cachedPreview['headSha'] ?? ''), (string) ($pull['headSha'] ?? ''))) {
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
                if (is_array($diskData) && hash_equals($diskData['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
                    $cachedPreview = $diskData;
                    $this->putSession($request, 'patch_preview', $diskData);
                }
            } catch (\Throwable) {}
        }

        if ($request->query('preview') === '1' && is_array($pull) && is_array($snapshot)) {
            try {
                if (is_array($cachedPreview) && hash_equals($cachedPreview['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
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
                if (is_array($cachedPreview) && hash_equals($cachedPreview['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
                    $patchPreview = $cachedPreview;
                } else {
                    $patchPreviewError = 'Unable to preview this public pull request. It may have changed, be private, or be unavailable. Capture it again and retry.';
                }
            }
        } elseif (is_array($cachedPreview) && is_array($pull) && hash_equals($cachedPreview['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
            $patchPreview = $cachedPreview;
        }

        $budget = null;
        try {
            $budget = $this->budget()->status();
        } catch (\Throwable) {}
        try {
            $modelKeyAvailable = strlen(Credentials::activeKey()) >= 16;
        } catch (\Throwable) {
            $modelKeyAvailable = false;
        }

        $reviewPreview = null;
        if ($patchPreview !== null && ($patchPreview['isPrivate'] ?? true) === false
            && is_array($pull) && ($pull['isPrivate'] ?? true) === false) {
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
                $savedAuto = \ProofPatch\Models\AutoReviewResult::findForRevision(
                    (string) $pull['repository'],
                    (int) $pull['number'],
                    (string) $pull['headSha'],
                    (string) $pull['baseSha']
                );
                if ($savedAuto !== null && $savedAuto->status === 'completed' && is_array($savedAuto->raw_review)) {
                    $review = array_merge($savedAuto->raw_review, ['source' => 'automatic']);
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
                    if (!$this->isAuthorizedForSnapshot($request, $candData)) {
                        continue;
                    }
                    $candPull = $candData['snapshot']['pullRequest'] ?? null;
                    if (is_array($candPull) && (($candPull['number'] ?? null) !== ($pull['number'] ?? null) || ($candPull['repository'] ?? '') !== ($pull['repository'] ?? ''))) {
                        $candidatePulls[] = $candPull;
                    }
                } catch (\Throwable) {}
            }
            $triage = $analyzer->triage($pull, $candidatePulls);
        }

        $githubAuth = $this->getSession($request, 'github_auth');
        $deviceFlow = $this->getSession($request, 'github_device_flow');
        $clientIdConfigured = (bool) (getenv('PROOFPATCH_GITHUB_CLIENT_ID') || getenv('GITHUB_APP_CLIENT_ID'));

        $githubConnection = [
            'isConfigured' => $clientIdConfigured,
            'clientId' => getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: null,
            'isConnected' => is_array($githubAuth) && isset($githubAuth['token']),
            'user' => is_array($githubAuth) ? ($githubAuth['user'] ?? null) : null,
            'repositories' => is_array($githubAuth) ? ($githubAuth['repositories'] ?? []) : [],
            'repositoriesStatus' => is_array($githubAuth) ? ($githubAuth['repositoriesStatus'] ?? null) : null,
            'selectedRepo' => $this->getSession($request, 'selected_github_repo'),
            'canCapturePrivateTestRepo' => is_array($request->attributes->get('proofpatch_github_access'))
                && is_int($request->attributes->get('proofpatch_github_access')['userId'] ?? null)
                && DedicatedTestRepo::name() !== null
                && is_string($this->getSession($request, 'selected_github_repo'))
                && strcasecmp((string) $this->getSession($request, 'selected_github_repo'), (string) DedicatedTestRepo::name()) === 0
                && isset($request->attributes->get('proofpatch_github_access')['repositories'][strtolower((string) DedicatedTestRepo::name())])
                && PrivatePullSession::usesServerSession(),
            'repoPulls' => $this->getSession($request, 'github_repo_pulls') ?? [],
            'repoPullsStatus' => $this->getSession($request, 'github_repo_pulls_status') ?? null,
            'deviceFlow' => is_array($deviceFlow) ? [
                'userCode' => (string) ($deviceFlow['userCode'] ?? ''),
                'verificationUri' => (string) ($deviceFlow['verificationUri'] ?? ''),
                'expiresAt' => (int) ($deviceFlow['expiresAt'] ?? 0),
                'interval' => (int) ($deviceFlow['interval'] ?? 5),
            ] : null,
            'statusMessage' => session('github_status') ?? $this->getSession($request, 'github_status'),
            'errorMessage' => session('github_error') ?? $this->getSession($request, 'github_error'),
        ];
        $this->forgetSession($request, 'github_status', 'github_error');

        $commentApprovals = [];
        $access = $request->attributes->get('proofpatch_github_access');
        $repoName = is_array($pull) ? ($pull['repository'] ?? null) : null;
        $configured = DedicatedTestRepo::name();
        $drafts = $this->getSession($request, 'review_drafts', []);
        if (is_array($access) && is_int($access['userId'] ?? null) && is_string($repoName)
            && is_string($configured) && strcasecmp($repoName, $configured) === 0
            && isset($access['repositories'][strtolower($repoName)]) && is_int($pull['number'] ?? null)
            && is_string($pull['headSha'] ?? null) && is_string($pull['baseSha'] ?? null) && is_array($drafts)) {
            foreach ($drafts as $draft) {
                if (is_array($draft) && is_string($draft['id'] ?? null) && ($draft['commentable'] ?? false) === true) {
                    $commentApprovals[$draft['id']] = ManualInlineCommentPublisher::digest(
                        $access['userId'], $repoName, $pull['number'], $pull['headSha'], $pull['baseSha'], $draft
                    );
                }
            }
        }

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
        $validId = static fn(string $id): bool => (bool) preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $id);
        $runDir = function (string $id) use ($validId): ?string {
            if (!$validId($id) || is_link($this->runsDir) || is_link($this->runsDir.'/'.$id)) {
                return null;
            }
            $base = realpath($this->runsDir);
            $path = realpath($this->runsDir.'/'.$id);
            return $base !== false && $path !== false && dirname($path) === $base && is_dir($path) ? $path : null;
        };

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
                    if (!$this->isPublicCheckRun($sourceReport)) {
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
                $provenance = null;
                if (is_array($generated) && ($generated['patch'] ?? null) === $patch->toArray()
                    && ($generated['sourceRunId'] ?? null) === $request->input('source_run')) {
                    $provenance = [
                        'origin' => 'model', 'model' => $generated['model'], 'usage' => $generated['usage'],
                        'sourceRunId' => $generated['sourceRunId'], 'sourceReceiptSha256' => $generated['sourceReceiptSha256'],
                    ];
                }
                $report = (new CheckoutVerifier($this->project))->verify($patch, $provenance);

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
        if ($runId !== '' && $validId($runId)) {
            $requestedFile = $this->runsDir.'/'.$runId.'/result.json';
            if (is_file($requestedFile) && !in_array($requestedFile, array_slice($runFiles, 0, 25), true)) {
                array_unshift($runFiles, $requestedFile);
            }
        }
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
                if (!$this->isPublicCheckRun($run['report'])) {
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
        foreach ($history as $id => $item) {
            if (!empty($item['hasVisual']) && ($item['decision'] ?? '') === 'VERIFIED') {
                $latestVisualRunId = $id;
                break;
            }
        }
        if ($latestVisualRunId === null) {
            foreach ($history as $id => $item) {
                if (!empty($item['hasVisual'])) {
                    $latestVisualRunId = $id;
                    break;
                }
            }
        }

        if ($runId === '' && $history !== []) {
            // Default to the latest verified visual checkout run if present, or first verified run
            $runId = $latestVisualRunId;
            if (!$runId) {
                foreach ($history as $id => $item) {
                    if (($item['decision'] ?? '') === 'VERIFIED') {
                        $runId = $id;
                        break;
                    }
                }
            }
            $runId = $runId ?: array_key_first($history);
        }
        $report = $history[$runId] ?? null;
        $selectedHasVisual = $report !== null && is_file($this->runsDir.'/'.$runId.'/baseline/checkout.png') && is_file($this->runsDir.'/'.$runId.'/candidate/checkout.png');
        $currentHead = trim(LocalProcess::output(['git', '-C', $this->project, 'rev-parse', 'HEAD']));
        $baselineReady = $report !== null && ($report['integrityValid'] ?? false) && ($report['baseCommit'] ?? null) === $currentHead
            && ($report['runs']['baseline']['exitCode'] ?? null) === 1;
        $budgetStatus = null;
        try {
            $budgetStatus = RequestBudget::forRuntime()->status();
        } catch (\Throwable) {}

        $proposal = $this->getSession($request, 'proposal');
        if (!is_array($proposal) || ($proposal['sourceRunId'] ?? null) !== $runId) {
            $proposal = null;
        }
        $fixture = $request->input('fixture') ?? $proposal['patch']['file'] ?? $report['proposal']['file'] ?? CssPatch::FILE;
        if (!is_string($fixture) || !in_array($fixture, CssPatch::FILES, true)) {
            $fixture = CssPatch::FILE;
        }
        $receipt = null;
        $receiptError = null;
        if ($report !== null && $runDir($runId) !== null) {
            try {
                $receipt = (new Receipt())->verify($runDir($runId));
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
            if (!$request->boolean('confirm')) {
                throw new \InvalidArgumentException('You must review and explicitly approve the request before sending.');
            }

            $digest = (string) $request->input('digest', '');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
                throw new \InvalidArgumentException('A valid preview digest is required.');
            }

            $approvalToken = (string) $request->input('approval_token', '');
            if (!preg_match('/\A[a-f0-9]{64}\z/', $approvalToken)) {
                throw new \InvalidArgumentException('A valid server-held approval token is required.');
            }

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
            if (is_array($cachedPreview) && hash_equals($cachedPreview['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
                $patchPreview = $cachedPreview;
            } else {
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
        if ($request->input('confirmed') !== true || !is_array($auth) || !is_string($auth['token'] ?? null)
            || !is_array($pull) || !is_array($access)) {
            return redirect('/repositories')->with('error', 'Connect GitHub, load the pull request and confirm the action.');
        }
        try {
            $actions = new \ProofPatch\GitHub\PullRequestActions(HttpClient::create());
            $number = (int) ($pull['number'] ?? 0);
            if ($action === 'merge') {
                $result = $actions->merge($auth['token'], $access, $pull, $expectedHead);
                $pull['state'] = 'merged';
                $pull['isMerged'] = true;
                $message = "Pull request #{$number} was merged (commit ".substr($result['sha'], 0, 7).').';
            } else {
                $actions->close($auth['token'], $access, $pull, $expectedHead);
                $pull['state'] = 'closed';
                $message = "Pull request #{$number} was closed without merging.";
            }
            $this->putSession($request, 'pullRequest', $pull);
            $repoPulls = $this->getSession($request, 'github_repo_pulls');
            if (is_array($repoPulls)) {
                foreach ($repoPulls as &$rp) {
                    if (is_array($rp) && ($rp['number'] ?? null) === $number) {
                        $rp['state'] = $pull['state'];
                        $rp['isMerged'] = $pull['state'] === 'merged';
                    }
                }
                unset($rp);
                $this->putSession($request, 'github_repo_pulls', $repoPulls);
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
            $path = (string) $request->input('path', '');
            $comment = trim((string) $request->input('comment', ''));
            $headSha = (string) $request->input('headSha', '');
            $lineRaw = $request->input('line');
            $line = is_numeric($lineRaw) ? (int) $lineRaw : null;
            $side = (string) $request->input('side', 'RIGHT');

            if ($path === '' || strlen($path) > 4096) {
                throw new \InvalidArgumentException('A valid file path is required.');
            }
            if ($comment === '' || strlen($comment) > 2000 || str_contains($comment, "\0")) {
                throw new \InvalidArgumentException('Draft comment must be between 1 and 2000 characters.');
            }
            if (!preg_match('/\A[a-f0-9]{40}\z/', $headSha)) {
                throw new \InvalidArgumentException('A valid 40-character head SHA is required.');
            }

            if (!hash_equals($pull['headSha'] ?? '', $headSha)) {
                throw new \InvalidArgumentException('Cannot anchor comment: draft head SHA does not match current captured head SHA.');
            }

            $cachedPreview = $this->getSession($request, 'patch_preview');
            $snapshot = $this->getSession($request, 'pullRequestSnapshot');
            $diskPreviewFile = ($pull['isPrivate'] ?? false) !== true && is_array($snapshot) && isset($snapshot['id']) ? $this->snapshotsDir.'/'.$snapshot['id'].'-preview.json' : null;
            if (!is_array($cachedPreview) && $diskPreviewFile && is_file($diskPreviewFile)) {
                try {
                    $diskData = json_decode((string) file_get_contents($diskPreviewFile), true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($diskData) && hash_equals($diskData['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
                        $cachedPreview = $diskData;
                        $this->putSession($request, 'patch_preview', $diskData);
                    }
                } catch (\Throwable) {}
            }

            if (is_array($cachedPreview) && hash_equals($cachedPreview['headSha'] ?? '', (string) ($pull['headSha'] ?? ''))) {
                $patchPreview = $cachedPreview;
            } else {
                if (($pull['isPrivate'] ?? false) === true) {
                    throw new \RuntimeException('Open the private pull request again before adding comments.');
                }
                $patchPreview = (new PublicPatchPreview(HttpClient::create()))->fetch($pull);
                $this->putSession($request, 'patch_preview', $patchPreview);
            }
            $filePatch = null;
            foreach ($patchPreview['files'] ?? [] as $f) {
                if (($f['name'] ?? null) === $path) {
                    $filePatch = $f['patch'] ?? null;
                    break;
                }
            }

            $anchorResult = DiffHunkParser::validateAnchor($filePatch, $line, $headSha, $pull['headSha'], $side);

            // A positive lineHint alone is not proof it is commentable:
            // if a line number is specified, it MUST be commentable within the captured diff hunk!
            if ($line !== null && !$anchorResult['commentable']) {
                throw new \InvalidArgumentException("Line {$line} is not commentable on {$side} side: {$anchorResult['message']}");
            }

            $draftId = hash('sha256', $path.':'.($line ?? 'file').':'.$headSha);
            $drafts = $this->getSession($request, 'review_drafts', []);
            $drafts[$draftId] = [
                'id' => $draftId,
                'path' => $path,
                'line' => $line,
                'side' => $side,
                'comment' => $comment,
                'headSha' => $headSha,
                'status' => $anchorResult['status'],
                'valid' => $anchorResult['valid'],
                'commentable' => $anchorResult['commentable'],
                'lineType' => $anchorResult['lineType'],
                'message' => $anchorResult['message'],
                'updatedAt' => gmdate(DATE_ATOM),
            ];
            $this->putSession($request, 'review_drafts', $drafts);
            $message = ($pull['isPrivate'] ?? false) === true
                ? 'Draft comment saved.' : "Draft comment saved on {$path}".($line ? ":{$line}" : '');
            $this->addNotification($request, 'success', 'Draft Saved', $message);

            $snapshotQuery = is_array($snapshot) && isset($snapshot['id']) ? '&snapshot='.$snapshot['id'] : '';
            return redirect('/repositories?preview=1'.$snapshotQuery);
        } catch (\Throwable $exception) {
            $snapshot = $this->getSession($request, 'pullRequestSnapshot');
            $snapshotQuery = is_array($snapshot) && isset($snapshot['id']) ? '&snapshot='.$snapshot['id'] : '';
            $message = ($pull['isPrivate'] ?? false) === true ? 'Could not save this draft. Check the selected line.' : $exception->getMessage();
            $this->putSession($request, 'review_error', $message);
            return redirect('/repositories?preview=1'.$snapshotQuery)->with('review_error', $message);
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
        $snapshotQuery = is_array($snapshot) && isset($snapshot['id']) ? '&snapshot='.$snapshot['id'] : '';
        return redirect('/repositories?preview=1'.$snapshotQuery);
    }

    public function clearDraftComments(Request $request = null): \Illuminate\Http\RedirectResponse
    {
        $this->forgetSession($request, 'review_drafts');
        $this->addNotification($request, 'info', 'Drafts Cleared', 'All draft review comments were cleared.');
        $snapshot = $this->getSession($request, 'pullRequestSnapshot');
        $snapshotQuery = is_array($snapshot) && isset($snapshot['id']) ? '&snapshot='.$snapshot['id'] : '';
        return redirect('/repositories?preview=1'.$snapshotQuery);
    }

    public function initiateDeviceFlow(Request $request): \Illuminate\Http\RedirectResponse
    {
        try {
            $clientId = (string) ($request->input('client_id') ?: getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: '');
            $clientId = trim($clientId);
            if ($clientId === '') {
                throw new \InvalidArgumentException('A GitHub App Client ID is required. Set PROOFPATCH_GITHUB_CLIENT_ID or provide it in the form.');
            }

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

            return redirect('/repositories')->with('github_status', 'Device flow initiated. Enter code '.$initData['userCode'].' on GitHub.');
        } catch (\Throwable $e) {
            $this->putSession($request, 'github_error', $e->getMessage());
            return redirect('/repositories')->with('github_error', $e->getMessage());
        }
    }

    public function pollDeviceFlow(Request $request): \Illuminate\Http\RedirectResponse
    {
        $flow = $this->getSession($request, 'github_device_flow');
        if (!is_array($flow) || !isset($flow['clientId'], $flow['deviceCode'])) {
            return redirect('/repositories')->with('github_error', 'No active device flow session. Please initiate first.');
        }

        if (time() > ($flow['expiresAt'] ?? 0)) {
            $this->forgetSession($request, 'github_device_flow');
            return redirect('/repositories')->with('github_error', 'Verification code expired. Please initiate connection again.');
        }

        try {
            $deviceFlow = $this->getDeviceFlowClient();
            $result = $deviceFlow->poll((string) $flow['clientId'], (string) $flow['deviceCode']);

            if ($result['status'] === 'authorized' && isset($result['accessToken'])) {
                $token = (string) $result['accessToken'];
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
                $this->forgetSession($request, 'github_device_flow');
                $this->addNotification($request, 'success', 'GitHub Connected', "Successfully connected as @{$user['login']}.");

                if (($reposStatus['truncated'] ?? false) || !empty($reposStatus['errors'] ?? [])) {
                    $warn = ($reposStatus['truncated'] ?? false)
                        ? 'Repositories list was truncated by pagination limits.'
                        : 'Some repositories could not be retrieved: '.implode('; ', $reposStatus['errors']);
                    $this->addNotification($request, 'warning', 'Repositories Incomplete', $warn);
                }

                return redirect('/repositories')->with('github_status', 'Successfully connected to GitHub as @'.$user['login'].'.');
            }

            if ($result['status'] === 'pending') {
                return redirect('/repositories')->with('github_status', 'Authorization pending. Enter user code '.$flow['userCode'].' at '.$flow['verificationUri'].'.');
            }

            if ($result['status'] === 'slow_down') {
                $flow['interval'] = $result['interval'] ?? 10;
                $this->putSession($request, 'github_device_flow', $flow);
                return redirect('/repositories')->with('github_status', 'Rate limited by GitHub. Please wait a few seconds before checking again.');
            }

            if ($result['status'] === 'expired') {
                $this->forgetSession($request, 'github_device_flow');
                return redirect('/repositories')->with('github_error', 'Verification code expired. Please initiate connection again.');
            }

            if ($result['status'] === 'denied') {
                $this->forgetSession($request, 'github_device_flow');
                return redirect('/repositories')->with('github_error', 'Authorization was declined on GitHub.');
            }

            return redirect('/repositories')->with('github_error', 'GitHub authorization error: '.($result['error'] ?? 'Unknown error'));
        } catch (\Throwable $e) {
            return redirect('/repositories')->with('github_error', 'Failed to poll GitHub: '.$e->getMessage());
        }
    }

    public function disconnectDeviceFlowRequest(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->disconnectDeviceFlow($request);
    }

    public function disconnectDeviceFlow(?Request $request = null): \Illuminate\Http\RedirectResponse
    {
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
        $this->addNotification($request, 'info', 'GitHub Disconnected', 'Disconnected from GitHub device session.');

        $redirectTo = $request !== null ? (string) $request->input('redirect_to', '') : '';
        $target = in_array($redirectTo, ['/issues', '/repositories', '/settings', '/checks'], true)
            ? $redirectTo
            : '/repositories';

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
        $found = null;
        foreach ($authorizedRepos as $r) {
            if (is_array($r) && ($r['fullName'] ?? '') === $repoName) {
                $found = $r;
                break;
            }
        }

        if ($found === null) {
            return redirect('/repositories')->with('github_error', 'Selected repository is not authorized for this installation.');
        }

        try {
            $client = $this->getGitHubAppClient();
            $pullsStatus = [];
            $pulls = $client->listOpenPullRequests((string) $auth['token'], $repoName, $pullsStatus, 'all');
            $this->putSession($request, 'selected_github_repo', $repoName);
            $this->putSession($request, 'github_repo_pulls', $pulls);
            $this->putSession($request, 'github_repo_pulls_status', $pullsStatus);

            if (($pullsStatus['truncated'] ?? false) || !empty($pullsStatus['errors'] ?? [])) {
                $warn = ($pullsStatus['truncated'] ?? false)
                    ? 'Pull requests list was capped at pagination limit (500).'
                    : 'Some pull requests could not be retrieved: '.implode('; ', $pullsStatus['errors']);
                $this->addNotification($request, 'warning', 'Pull Requests Incomplete', $warn);
            }

            $this->addNotification($request, 'info', 'Repository Selected', 'Loaded open pull requests for '.$repoName.'.');

            return redirect('/repositories')->with('github_status', 'Loaded open pull requests for '.$repoName.'.');
        } catch (\Throwable $e) {
            return redirect('/repositories')->with('github_error', 'Failed to list pull requests: '.$e->getMessage());
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
        $foundRepo = null;
        foreach ($authorizedRepos as $r) {
            if (is_array($r) && ($r['fullName'] ?? '') === $repoName) {
                $foundRepo = $r;
                break;
            }
        }

        if ($foundRepo === null) {
            $this->putSession($request, 'github_error', 'Selected repository is not authorized for this installation.');
            return redirect('/repositories')->with('github_error', 'Selected repository is not authorized for this installation.');
        }

        $private = ($foundRepo['isPrivate'] ?? true) !== false;
        $access = $request->attributes->get('proofpatch_github_access');
        $configured = DedicatedTestRepo::name();
        if ($private && (!PrivatePullSession::usesServerSession() || !is_array($access)
            || !is_int($access['userId'] ?? null) || $access['userId'] < 1
            || ($auth['user']['id'] ?? null) !== $access['userId']
            || !is_array($access['repositories'] ?? null) || !isset($access['repositories'][strtolower($repoName)])
            || !is_string($configured) || strcasecmp($configured, $repoName) !== 0)) {
            $msg = 'Private repository capture is disabled. ProofPatch operates strictly on public repositories to prevent proprietary code leakage in shared local snapshot stores.';
            $this->putSession($request, 'github_error', $msg);
            return redirect('/repositories')->with('github_error', $msg);
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
            $pull = [
                'repository' => $repoName,
                'number' => $number,
                'title' => $patchPreview['title'],
                'body' => $patchPreview['body'] ?? '',
                'state' => $patchPreview['state'],
                'isMerged' => $patchPreview['isMerged'] ?? false,
                'isDraft' => ($patchPreview['isDraft'] ?? false) === true,
                'head' => $patchPreview['head'],
                'base' => $patchPreview['base'],
                'headSha' => $patchPreview['headSha'],
                'baseSha' => $patchPreview['baseSha'],
                'url' => $patchPreview['url'],
                'user' => $patchPreview['user'] ?? 'unknown',
                'isPrivate' => $private,
                'changedFiles' => $patchPreview['changedFiles'],
                'files' => array_map(static fn(array $f): array => [
                    'name' => $f['name'],
                    'status' => $f['status'],
                    'additions' => (int) ($f['additions'] ?? 0),
                    'deletions' => (int) ($f['deletions'] ?? 0),
                ], $patchPreview['files']),
            ];

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

        $githubConnection = [
            'isConfigured' => $clientIdConfigured,
            'clientId' => getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: null,
            'isConnected' => is_array($githubAuth) && isset($githubAuth['token']),
            'user' => is_array($githubAuth) ? ($githubAuth['user'] ?? null) : null,
            'repositories' => is_array($githubAuth) ? ($githubAuth['repositories'] ?? []) : [],
            'repositoriesStatus' => is_array($githubAuth) ? ($githubAuth['repositoriesStatus'] ?? null) : null,
            'selectedRepo' => $this->getSession($request, 'selected_github_repo'),
            'deviceFlow' => is_array($deviceFlow) ? [
                'userCode' => (string) ($deviceFlow['userCode'] ?? ''),
                'verificationUri' => (string) ($deviceFlow['verificationUri'] ?? ''),
                'expiresAt' => (int) ($deviceFlow['expiresAt'] ?? 0),
                'interval' => (int) ($deviceFlow['interval'] ?? 5),
            ] : null,
            'statusMessage' => session('github_status') ?? $this->getSession($request, 'github_status'),
            'errorMessage' => session('github_error') ?? $this->getSession($request, 'github_error'),
        ];
        $this->forgetSession($request, 'github_status', 'github_error');

        $budget = null;
        try {
            $budget = $this->budget()->status();
        } catch (\Throwable) {}

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

        return Inertia::render('Settings', [
            'githubConnection' => $githubConnection,
            'budget' => $budget,
            'policy' => $policy,
            'autoReview' => $autoReview,
            'providerConfig' => $providerConfig,
            'statusMessage' => $statusMessage,
            'errorMessage' => $errorMessage,
        ]);
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

    private function isExplicitlyPublicSnapshot(array $savedSnapshot): bool
    {
        return PullRequestSnapshot::isExplicitlyPublic($savedSnapshot);
    }

    private function isAuthorizedForSnapshot(Request $request, array $savedSnapshot): bool
    {
        // Fail closed for private or unknown saved snapshots everywhere:
        // ProofPatch operates strictly on explicitly public repositories.
        // Cached session permissions cannot guarantee remote authorization integrity after revocation.
        return $this->isExplicitlyPublicSnapshot($savedSnapshot);
    }

    private function budget(): RequestBudget
    {
        return RequestBudget::forRuntime();
    }

    /**
     * Builds the automatic-review panel props scoped strictly to repositories
     * authorized in the active GitHub session. Settings, counts, and recent
     * findings for repositories outside the current grant are never exposed.
     */
    private function scopedAutoReviewProps(Request $request): array
    {
        $props = [
            'worker' => [
                'isLive' => false,
                'status' => 'offline',
                'workerPid' => null,
                'lastHeartbeatAt' => null,
                'cycleCount' => 0,
                'publicOnly' => true,
                'message' => 'Review worker is offline.',
            ],
            'settings' => [],
            'stats' => [
                'totalReviews' => 0,
                'completed' => 0,
                'failed' => 0,
                'skippedPrivate' => 0,
                'skippedBudget' => 0,
            ],
            'recentResults' => [],
            'publicOnly' => true,
        ];

        try {
            $props['worker'] = (new \ProofPatch\Support\WorkerStatusService())->getStatus();
        } catch (\Throwable) {}

        $authorized = [];
        $auth = $this->getSession($request, 'github_auth');
        if (is_array($auth) && isset($auth['token']) && is_array($auth['repositories'] ?? null)) {
            foreach ($auth['repositories'] as $repo) {
                if (is_array($repo) && is_string($repo['fullName'] ?? null) && trim($repo['fullName']) !== '') {
                    $authorized[strtolower(trim($repo['fullName']))] = true;
                }
            }
        }
        if ($authorized === []) {
            return $props;
        }

        try {
            $keys = array_keys($authorized);
            $lowerColumn = \Illuminate\Support\Facades\DB::raw('LOWER(repository)');
            $props['settings'] = \ProofPatch\Models\RepositoryAutoReviewSetting::whereIn($lowerColumn, $keys)
                ->get(['repository', 'enabled', 'public_only'])
                ->keyBy('repository')
                ->all();

            $props['stats'] = [
                'totalReviews' => \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)->count(),
                'completed' => \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'completed')->count(),
                'failed' => \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'failed')->count(),
                'skippedPrivate' => \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'skipped_private')->count(),
                'skippedBudget' => \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'skipped_budget')->count(),
            ];

            $props['recentResults'] = \ProofPatch\Models\AutoReviewResult::whereIn($lowerColumn, $keys)
                ->latest('id')
                ->limit(10)
                ->get(['id', 'repository', 'pull_number', 'status', 'summary', 'risk_level', 'recommendation', 'model', 'published_status', 'reviewed_at', 'created_at'])
                ->toArray();
        } catch (\Throwable) {}

        return $props;
    }

    /** Backend evidence may contain private repository names and is not yet bound to a user. */
    private function isPublicCheckRun(array $report): bool
    {
        if (($report['kind'] ?? null) === 'owned_backend' || array_key_exists('context', $report)) {
            return ($report['isSynthetic'] ?? false) === true;
        }
        return true; // Local checkout verifier; not sourced from a GitHub task.
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
        $validId = (bool) preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $runId);
        $folder = match ($imageType) {
            'before', 'baseline' => 'baseline',
            'after', 'candidate' => 'candidate',
            default => null,
        };
        if (!$validId || $folder === null) {
            return response('Image unavailable.', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }
        $reportPath = $this->runsDir.'/'.$runId.'/result.json';
        try {
            $report = json_decode((string) file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($report) || !$this->isPublicCheckRun($report)) {
                throw new \RuntimeException('Image unavailable.');
            }
        } catch (\Throwable) {
            return response('Image unavailable.', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }
        $path = realpath($this->runsDir.'/'.$runId.'/'.$folder.'/checkout.png');
        $base = realpath($this->runsDir);
        if ($path === false || $base === false || !str_starts_with($path, $base) || !is_file($path)) {
            return response('Image unavailable.', 404, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        return response(file_get_contents($path) ?: '', 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
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
