<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ProofPatch\Models\AutoReviewResult;
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
    public static function forRequest(Request $request, int $limit = 10): array
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

            $props['stats'] = [
                'totalReviews' => AutoReviewResult::whereIn($lowerColumn, $keys)->count(),
                'completed' => AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'completed')->count(),
                'failed' => AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'failed')->count(),
                'skippedPrivate' => AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'skipped_private')->count(),
                'skippedBudget' => AutoReviewResult::whereIn($lowerColumn, $keys)->where('status', 'skipped_budget')->count(),
            ];

            $props['recentResults'] = AutoReviewResult::whereIn($lowerColumn, $keys)
                ->latest('id')
                ->limit(max(1, min(25, $limit)))
                ->get(['id', 'repository', 'pull_number', 'status', 'summary', 'risk_level', 'recommendation', 'model', 'published_status', 'reviewed_at', 'created_at'])
                ->toArray();
        } catch (\Throwable) {}

        return $props;
    }
}
