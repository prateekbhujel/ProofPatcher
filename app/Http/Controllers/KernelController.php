<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProofPatch\Agent\ProviderConfigStore;
use ProofPatch\Support\AutoReviewPanelProps;
use ProofPatch\Support\SessionHelper;

/**
 * Kernel home: one pipeline overview (Intake -> Propose -> Verify -> Publish)
 * with the single next step for the current session state. Read-only: it never
 * triggers model calls, GitHub writes, or worker runs.
 */
final class KernelController
{
    public function __construct(private ?\Hari\Registry $hari = null)
    {
    }

    public function show(Request $request): InertiaResponse
    {
        $auth = SessionHelper::get($request, 'github_auth');
        $connected = is_array($auth) && isset($auth['token']) && is_string($auth['token']) && trim($auth['token']) !== '';
        $repos = ($connected && is_array($auth['repositories'] ?? null)) ? array_values(array_filter(
            $auth['repositories'],
            static fn(mixed $repo): bool => is_array($repo) && is_string($repo['fullName'] ?? null)
        )) : [];
        $selectedRepo = $connected ? SessionHelper::get($request, 'selected_github_repo') : null;
        $selectedRepo = is_string($selectedRepo) && trim($selectedRepo) !== '' ? trim($selectedRepo) : null;
        $repoIssues = $connected ? SessionHelper::get($request, 'github_repo_issues', []) : [];
        $repoPulls = $connected ? SessionHelper::get($request, 'github_repo_pulls', []) : [];

        $providerStatus = ProviderConfigStore::status();

        $budget = null;
        try {
            $budget = ($this->hari ?? \Hari\Hari::registry())->resolve('budget.status', 1, 'web')->invoke([]);
        } catch (\Throwable) {}

        $autopilotCaps = null;
        try {
            $row = \ProofPatch\Models\AutopilotBudget::current();
            $autopilotCaps = $row === null ? null : [
                'maxAttempts' => $row->max_attempts,
                'maxReservedDollars' => $row->max_reserved_micros === null
                    ? null
                    : round($row->max_reserved_micros / 1000000, 2),
            ];
        } catch (\Throwable) {}

        $autoReview = AutoReviewPanelProps::forRequest($request, 5, $this->hari);

        $context = SessionHelper::get($request, 'issue_context');
        $hasContext = is_array($context);
        $suggestion = SessionHelper::get($request, 'issue_suggestion');
        $hasSuggestion = is_array($suggestion);
        $report = SessionHelper::get($request, 'issue_verification_report');
        $hasReport = is_array($report);
        $published = SessionHelper::get($request, 'issue_published_pr');
        $hasPublished = is_array($published);
        $pull = SessionHelper::get($request, 'pullRequest');
        $hasPull = is_array($pull);
        $review = SessionHelper::get($request, 'pr_review');
        $hasReview = $review !== null;
        $isDedicated = (bool) SessionHelper::get($request, 'issue_is_dedicated_test_repo', false);
        $gates = $hasReport && is_array($report['gates'] ?? null) ? $report['gates'] : [];
        $requiredGates = ['baselineReproduced', 'negativeControlCaught', 'candidatePassed', 'candidateStaticSafetyPassed', 'allowlistIntegrityIntact'];
        $verified = $hasReport && ($report['decision'] ?? null) === 'VERIFIED';
        foreach ($requiredGates as $gate) {
            $verified = $verified && ($gates[$gate] ?? null) === true;
        }
        $genuineRun = $verified && ($report['isSimulated'] ?? true) === false
            && ($report['isolated'] ?? false) === true && ($report['genuineProviderEvidence'] ?? false) === true
            && ($report['oracleVerified'] ?? false) === true;

        $state = [
            'connected' => $connected,
            'reposCount' => count($repos),
            'selectedRepo' => $selectedRepo,
            'hasContext' => $hasContext,
            'hasSuggestion' => $hasSuggestion,
            'hasReport' => $hasReport,
            'verified' => $verified,
            'genuineRun' => $genuineRun,
            'isDedicated' => $isDedicated,
            'hasPublished' => $hasPublished,
            'hasPull' => $hasPull,
            'hasReview' => $hasReview,
        ];

        $lastVerification = null;
        if ($hasReport && isset($report['id'], $report['decision'])) {
            $lastVerification = [
                'id' => $report['id'],
                'decision' => $report['decision'],
                'isSimulated' => $report['isSimulated'] ?? null,
                'receiptSha256' => is_array($report['receipt'] ?? null) ? ($report['receipt']['sha256'] ?? null) : null,
                'repository' => $report['context']['repository'] ?? ($hasContext ? ($context['repository'] ?? null) : null),
            ];
        }

        return Inertia::render('Kernel', [
            'connected' => $connected,
            'user' => is_array($auth) ? ($auth['user'] ?? null) : null,
            'reposCount' => count($repos),
            'selectedRepo' => $selectedRepo,
            'openIssues' => is_array($repoIssues) ? count(array_filter($repoIssues, static fn ($issue) => is_array($issue) && ($issue['state'] ?? null) === 'open')) : 0,
            'openPulls' => is_array($repoPulls) ? count(array_filter($repoPulls, static fn ($pull) => is_array($pull) && ($pull['state'] ?? null) === 'open')) : 0,
            'provider' => [
                'label' => $providerStatus['activeLabel'],
                'model' => $providerStatus['activeModel'],
                'keyReady' => $providerStatus['activeKeyAvailable'],
            ],
            'budget' => $budget,
            'autopilotCaps' => $autopilotCaps,
            'worker' => $autoReview['worker'],
            'autoStats' => $autoReview['stats'],
            'recentFindings' => $autoReview['recentResults'],
            'nextStep' => $this->nextStep($state, is_array($published) ? $published : null),
            'stages' => $this->stages($state, $lastVerification),
            'lastVerification' => $lastVerification,
            'publishedPr' => $hasPublished ? [
                'prNumber' => $published['prNumber'] ?? null,
                'prUrl' => $published['prUrl'] ?? null,
                'repository' => $published['repository'] ?? null,
            ] : null,
        ]);
    }

    /**
     * @param array{connected: bool, reposCount: int, selectedRepo: ?string, hasContext: bool, hasSuggestion: bool, hasReport: bool, verified: bool, genuineRun: bool, isDedicated: bool, hasPublished: bool, hasPull: bool, hasReview: bool} $state
     */
    private function nextStep(array $state, ?array $published): array
    {
        // Resume active work first: the fixture and public-capture paths run
        // without a GitHub connection, so they outrank the connect step.
        if ($state['hasPublished']) {
            return [
                'key' => 'done',
                'title' => 'Draft PR published',
                'detail' => 'The verified change is on GitHub as a draft. Review it there, or start the next target.',
                'href' => is_string($published['prUrl'] ?? null) ? $published['prUrl'] : '/issues',
                'cta' => 'Open draft PR',
            ];
        }

        if ($state['verified'] && $state['genuineRun'] && $state['isDedicated']) {
            return [
                'key' => 'publish',
                'title' => 'Create the draft PR',
                'detail' => 'Fixture checks passed. Publishing needs your explicit go-ahead.',
                'href' => '/issues',
                'cta' => 'Review and publish',
            ];
        }

        if ($state['hasSuggestion'] && $state['hasReport'] && !$state['verified']) {
            return [
                'key' => 'verify',
                'title' => 'Review the check results',
                'detail' => 'The fixture checks did not pass. Inspect the results before proceeding.',
                'href' => '/issues',
                'cta' => 'Open check results',
            ];
        }

        if ($state['hasSuggestion'] && !$state['hasReport']) {
            return [
                'key' => 'verify',
                'title' => 'Run the checks',
                'detail' => 'A proposal is waiting. Run the fixture checks before anything is published.',
                'href' => '/issues',
                'cta' => 'Run checks',
            ];
        }

        if ($state['hasContext'] && !$state['hasSuggestion']) {
            return [
                'key' => 'propose',
                'title' => 'Ask for a repair proposal',
                'detail' => 'An issue is loaded. Ask Muse for a single-file repair, or use the reference repair.',
                'href' => '/issues',
                'cta' => 'Open the proposal',
            ];
        }

        if ($state['hasPull'] && !$state['hasReview']) {
            return [
                'key' => 'review',
                'title' => 'Review the pull request',
                'detail' => 'A pull request is captured. Ask Muse for an advisory review of its diff.',
                'href' => '/repositories',
                'cta' => 'Open the review',
            ];
        }

        if (!$state['connected']) {
            return [
                'key' => 'connect',
                'title' => 'Connect GitHub',
                'detail' => 'Link your account so the kernel can load repositories, issues, and pull requests.',
                'href' => '/issues',
                'cta' => 'Continue with GitHub',
            ];
        }

        if ($state['reposCount'] === 0) {
            return [
                'key' => 'grant',
                'title' => 'Grant repository access',
                'detail' => 'Install the GitHub App on the repositories the kernel should patch, then reconnect.',
                'href' => '/settings',
                'cta' => 'Open Settings',
            ];
        }

        if ($state['selectedRepo'] === null) {
            return [
                'key' => 'select_repo',
                'title' => 'Pick a target repository',
                'detail' => 'Choose which granted repository to work on first.',
                'href' => '/issues',
                'cta' => 'Choose a repository',
            ];
        }

        return [
            'key' => 'target',
            'title' => 'Open an issue or pull request',
            'detail' => 'Pick a target from the workbench, or enable automatic reviews in Settings and let the worker watch.',
            'href' => '/issues',
            'cta' => 'Open the workbench',
        ];
    }

    /**
     * @param array{connected: bool, reposCount: int, selectedRepo: ?string, hasContext: bool, hasSuggestion: bool, hasReport: bool, verified: bool, genuineRun: bool, isDedicated: bool, hasPublished: bool, hasPull: bool, hasReview: bool} $state
     */
    private function stages(array $state, ?array $lastVerification): array
    {
        $targeted = $state['hasContext'] || $state['hasPull'];

        $intakeDetail = 'No adapter connected.';
        if ($state['selectedRepo'] !== null) {
            $intakeDetail = $state['selectedRepo'];
        } elseif ($state['hasContext'] || $state['hasPull']) {
            $intakeDetail = 'Target loaded.';
        } elseif ($state['connected']) {
            $intakeDetail = $state['reposCount'].' granted repositories';
        }
        $intake = [
            'key' => 'intake',
            'title' => 'Intake',
            'detail' => $intakeDetail,
            'state' => ($state['selectedRepo'] !== null || $state['hasContext'] || $state['hasPull'])
                ? 'done'
                : ($state['connected'] ? 'active' : 'todo'),
            'href' => '/issues',
        ];

        $propose = [
            'key' => 'propose',
            'title' => 'Propose',
            'detail' => $state['hasSuggestion'] ? 'Proposal ready for checks.' : ($state['hasReview'] ? 'Advisory review ready.' : 'No proposal yet.'),
            'state' => ($state['hasSuggestion'] || $state['hasReview']) ? 'done' : ($targeted ? 'active' : 'todo'),
            'href' => $state['hasPull'] && !$state['hasContext'] ? '/repositories' : '/issues',
        ];

        $verifyDetail = 'Not checked yet.';
        if ($lastVerification !== null) {
            $verifyDetail = $state['verified']
                ? 'Fixture checks passed.'
                : 'Fixture checks not confirmed.';
        }
        $verify = [
            'key' => 'verify',
            'title' => 'Verify',
            'detail' => $verifyDetail,
            'state' => $state['verified'] ? 'done' : ($state['hasSuggestion'] ? 'active' : 'todo'),
            'href' => '/issues',
        ];

        $publish = [
            'key' => 'publish',
            'title' => 'Publish',
            'detail' => $state['hasPublished'] ? 'Draft PR on GitHub.' : 'Nothing published.',
            'state' => $state['hasPublished'] ? 'done' : (($state['verified'] && $state['genuineRun'] && $state['isDedicated']) ? 'active' : 'todo'),
            'href' => '/issues',
        ];

        return [$intake, $propose, $verify, $publish];
    }
}
