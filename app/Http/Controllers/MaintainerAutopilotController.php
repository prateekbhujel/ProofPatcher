<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ProofPatch\Models\MaintainerAutopilotSetting;

/**
 * Maintainer autopilot switches (fix demo issue, auto-ready, auto-merge).
 * Same authority as automatic comments: only the signed-in owner's own
 * public, installed repositories. Every flag defaults off and is checked
 * again at action time; this endpoint never enables anything implicitly.
 */
final class MaintainerAutopilotController
{
    private const FLAGS = ['fix_issues', 'auto_ready', 'auto_merge', 'auto_close'];

    private function identity(Request $request): ?array
    {
        $access = $request->attributes->get('proofpatch_github_access');
        $auth = $request->session()->get('github_auth');
        if (!is_array($access) || !is_int($access['userId'] ?? null) || $access['userId'] < 1
            || !is_array($access['repositories'] ?? null) || !is_array($auth)
            || ($auth['user']['id'] ?? null) !== $access['userId']
            || !is_string($auth['user']['login'] ?? null) || !is_string($auth['token'] ?? null)
            || $auth['token'] === '' || !is_array($auth['repositories'] ?? null)) return null;
        return ['id' => $access['userId'], 'login' => $auth['user']['login'],
            'grants' => $access['repositories'], 'repositories' => $auth['repositories']];
    }

    public function status(Request $request): JsonResponse
    {
        $identity = $this->identity($request);
        if ($identity === null) return response()->json(['error' => 'Connect GitHub first.'], 401);

        $settings = [];
        $issueActions = [];
        $mergeActions = [];
        $closeActions = [];
        try {
            if (Schema::hasTable('maintainer_autopilot_settings')) {
                foreach (DB::table('maintainer_autopilot_settings')->get() as $row) {
                    $repo = strtolower((string) $row->repository);
                    if (!isset($identity['grants'][$repo])) continue;
                    $settings[$repo] = [
                        'fix_issues' => (bool) $row->fix_issues,
                        'auto_ready' => (bool) $row->auto_ready,
                        'auto_merge' => MaintainerAutopilotSetting::flagsFor($repo)['autoMerge'],
                        'auto_close' => (bool) ($row->auto_close ?? false),
                    ];
                }
            }
            if (Schema::hasTable('autopilot_issue_actions')) {
                $issueActions = DB::table('autopilot_issue_actions')->orderByDesc('id')->limit(10)->get()
                    ->filter(static fn ($row): bool => isset($identity['grants'][strtolower((string) $row->repository)]))
                    ->map(static fn ($row): array => [
                        'repository' => $row->repository, 'issue_number' => (int) $row->issue_number,
                        'action' => $row->action, 'detail' => $row->detail,
                        'published' => (bool) $row->published, 'created_at' => $row->created_at,
                    ])->values()->all();
            }
            if (Schema::hasTable('autopilot_merge_actions')) {
                $mergeActions = DB::table('autopilot_merge_actions')->orderByDesc('id')->limit(10)->get()
                    ->filter(static fn ($row): bool => isset($identity['grants'][strtolower((string) $row->repository)]))
                    ->map(static fn ($row): array => [
                        'repository' => $row->repository, 'pull_number' => (int) $row->pull_number,
                        'outcome' => $row->outcome, 'detail' => $row->detail, 'created_at' => $row->created_at,
                    ])->values()->all();
            }
        } catch (\Throwable) {
            return response()->json(['error' => 'Maintainer autopilot settings are unavailable. Run php artisan migrate.'], 500);
        }
            if (Schema::hasTable('autopilot_close_actions')) {
                $closeActions = DB::table('autopilot_close_actions')->orderByDesc('id')->limit(10)->get()
                    ->filter(static fn ($row): bool => isset($identity['grants'][strtolower((string) $row->repository)]))
                    ->map(static fn ($row): array => [
                        'repository' => $row->repository, 'pull_number' => (int) $row->pull_number,
                        'outcome' => $row->outcome, 'detail' => $row->detail, 'created_at' => $row->created_at,
                    ])->values()->all();
            }
        return response()->json(['settings' => $settings, 'issueActions' => $issueActions, 'mergeActions' => $mergeActions, 'closeActions' => $closeActions]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $identity = $this->identity($request);
        if ($identity === null) return response()->json(['error' => 'Connect GitHub first.'], 401);
        $repo = trim((string) $request->input('repository', ''));
        $flag = (string) $request->input('flag', '');
        $enabled = $request->input('enabled');
        if (!preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repo, $parts)
            || in_array($parts[2], ['.', '..'], true) || !in_array($flag, self::FLAGS, true) || !is_bool($enabled)) {
            return response()->json(['error' => 'Invalid repository or setting.'], 422);
        }
        if ($enabled && in_array($flag, ['auto_ready', 'auto_close'], true)) {
            return response()->json(['error' => 'This action is unavailable while its safeguards are verified.'], 422);
        }
        if (strcasecmp($parts[1], $identity['login']) !== 0 || !isset($identity['grants'][strtolower($repo)])) {
            return response()->json(['error' => 'Only your own installed repositories can be enabled.'], 403);
        }
        $public = false;
        foreach ($identity['repositories'] as $item) {
            if (is_array($item) && strcasecmp((string) ($item['fullName'] ?? ''), $repo) === 0
                && ($item['isPrivate'] ?? null) === false) $public = true;
        }
        if (!$public) return response()->json(['error' => 'Only public repositories are supported.'], 422);
        if (!Schema::hasTable('maintainer_autopilot_settings')) {
            return response()->json(['error' => 'Maintainer autopilot settings are unavailable. Run php artisan migrate.'], 500);
        }
        if ($flag === 'auto_merge' && !Schema::hasColumn('maintainer_autopilot_settings', 'auto_merge_armed_at')) {
            return response()->json(['error' => 'Run php artisan migrate before changing auto-merge.'], 422);
        }
        MaintainerAutopilotSetting::setFlag($repo, $flag, $enabled);
        return response()->json(['success' => true, 'repository' => strtolower($repo), 'flag' => $flag, 'enabled' => $enabled]);
    }
}
