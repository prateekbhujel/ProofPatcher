<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ProofPatch\Support\AutoReviewPanelProps;
use ProofPatch\Support\SessionHelper;

/**
 * Lean OS-shell status for every app page: connection, counts, budget,
 * worker, and the one next step. Session and local stores only — no model
 * calls, no GitHub network, no worker runs.
 */
final class OsStatusController
{
    public function __construct(private ?\Hari\Registry $hari = null)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $auth = SessionHelper::get($request, 'github_auth');
        $connected = \ProofPatch\Application\Settings\GitHubConnectionState::isConnected($auth);
        $repos = ($connected && is_array($auth['repositories'] ?? null)) ? array_values(array_filter(
            $auth['repositories'],
            static fn(mixed $repo): bool => is_array($repo) && is_string($repo['fullName'] ?? null)
        )) : [];
        $selectedRepo = $connected ? SessionHelper::get($request, 'selected_github_repo') : null;
        $selectedRepo = is_string($selectedRepo) && trim($selectedRepo) !== '' ? trim($selectedRepo) : null;
        $repoIssues = $connected ? SessionHelper::get($request, 'github_repo_issues', []) : [];
        $repoPulls = $connected ? SessionHelper::get($request, 'github_repo_pulls', []) : [];

        $budgetText = null;
        try {
            $budget = ($this->hari ?? \Hari\Hari::registry())->resolve('budget.status', 1, 'web')->invoke([]);
            if (is_array($budget)) {
                $budgetText = '$' . number_format(((int) ($budget['remainingMicros'] ?? 0)) / 1000000, 2) . ' left';
            }
        } catch (\Throwable) {}

        $worker = ['isLive' => false, 'status' => 'offline'];
        $findingsCount = 0;
        try {
            $autoReview = AutoReviewPanelProps::forRequest($request, 1, $this->hari);
            $worker = [
                'isLive' => (bool) ($autoReview['worker']['isLive'] ?? false),
                'status' => is_string($autoReview['worker']['status'] ?? null) ? $autoReview['worker']['status'] : 'offline',
            ];
            $findingsCount = (int) ($autoReview['stats']['totalReviews'] ?? 0);
        } catch (\Throwable) {}

        return response()->json([
            'connected' => $connected,
            'userLogin' => is_array($auth) && is_array($auth['user'] ?? null) ? ($auth['user']['login'] ?? null) : null,
            'reposCount' => count($repos),
            'selectedRepo' => $selectedRepo,
            'openIssues' => is_array($repoIssues) ? count(array_filter($repoIssues, static fn ($issue) => is_array($issue) && ($issue['state'] ?? null) === 'open')) : 0,
            'openPulls' => is_array($repoPulls) ? count(array_filter($repoPulls, static fn ($pull) => is_array($pull) && ($pull['state'] ?? null) === 'open')) : 0,
            'findingsCount' => $findingsCount,
            'budgetText' => $budgetText,
            'workerLive' => $worker['isLive'],
            'workerStatus' => $worker['status'],
            'next' => $this->nextStep($connected, count($repos), $selectedRepo),
        ]);
    }

    /** @return array{key: string, title: string, detail: string, href: string, cta: string} */
    private function nextStep(bool $connected, int $reposCount, ?string $selectedRepo): array
    {
        return \ProofPatch\Application\Settings\ConnectionNextStep::for($connected, $reposCount, $selectedRepo);
    }
}
