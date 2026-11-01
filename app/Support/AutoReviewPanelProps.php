<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ProofPatch\Models\RepositoryAutoReviewSetting;

/**
 * Builds automatic-review panel props scoped strictly to repositories
 * authorized in the active GitHub session. Settings, counts, and recent
 * findings for repositories outside the current grant are never exposed.
 */
final class AutoReviewPanelProps
{
    /**
     * @return array{
     *     worker: array,
     *     settings: array,
     *     stats: array{totalReviews: int, completed: int, failed: int, skippedPrivate: int, skippedBudget: int},
     *     recentResults: array,
     *     publicOnly: bool
     * }
     */
    public static function forRequest(Request $request, int $limit = 10, ?\Hari\Registry $hari = null): array
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
            $props['worker'] = (new WorkerStatusService())->getStatus();
        } catch (\Throwable) {}

        $authorized = [];
        $auth = SessionHelper::get($request, 'github_auth');
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
            $lowerColumn = DB::raw('LOWER(repository)');
            $props['settings'] = RepositoryAutoReviewSetting::whereIn($lowerColumn, $keys)
                ->get(['repository', 'enabled', 'public_only'])
                ->keyBy('repository')
                ->all();

            $registry = $hari ?? \Hari\Hari::registry();
            $validKeys = array_values(array_filter($keys, static fn (mixed $repo): bool =>
                is_string($repo) && preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $repo) === 1));
            $statsOutcome = $validKeys === [] ? [] : $registry->resolve('review.result', 1, 'web')->invoke([
                'stats_for' => $validKeys,
                'match' => 'lower',
            ]);
            $counts = is_array($statsOutcome['stats'] ?? null) ? $statsOutcome['stats'] : [];
            $props['stats'] = [
                'totalReviews' => (int) ($counts['total'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'skippedPrivate' => (int) ($counts['skipped_private'] ?? 0),
                'skippedBudget' => (int) ($counts['skipped_budget'] ?? 0),
            ];

            $listOutcome = $validKeys === [] ? [] : $registry->resolve('review.result', 1, 'web')->invoke([
                'repositories' => $validKeys,
                'limit' => max(1, min(25, $limit)),
            ]);
            $props['recentResults'] = [];
            foreach ((is_array($listOutcome['rows'] ?? null) ? $listOutcome['rows'] : []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                // Same keys the panel consumed from the direct query.
                $props['recentResults'][] = [
                    'id' => $row['id'] ?? null,
                    'repository' => $row['repository'] ?? null,
                    'pull_number' => $row['pull_number'] ?? null,
                    'head_sha' => $row['head_sha'] ?? null,
                    'base_sha' => $row['base_sha'] ?? null,
                    'status' => $row['status'] ?? null,
                    'summary' => $row['summary'] ?? null,
                    'risk_level' => $row['risk_level'] ?? null,
                    'recommendation' => $row['recommendation'] ?? null,
                    'model' => $row['model'] ?? null,
                    'published_status' => $row['published_status'] ?? null,
                    'reviewed_at' => $row['reviewed_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        } catch (\Throwable) {}

        return $props;
    }
}
