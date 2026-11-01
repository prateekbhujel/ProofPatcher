<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ProofPatch\Models\AutomaticInlineSetting;

final class AutomaticInlineSettingController
{
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
        $settings = DB::table('automatic_inline_settings')->where('user_id', $identity['id'])->where('enabled', true)
            ->pluck('repository')->filter(static fn (string $repo): bool => isset($identity['grants'][strtolower($repo)]))->values()->all();
        return response()->json(['enabled' => $settings]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $identity = $this->identity($request);
        if ($identity === null) return response()->json(['error' => 'Connect GitHub first.'], 401);
        $repo = trim((string) $request->input('repository', ''));
        $enabled = $request->input('enabled');
        if (!preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repo, $parts)
            || in_array($parts[2], ['.', '..'], true) || !is_bool($enabled)) {
            return response()->json(['error' => 'Invalid repository or setting.'], 422);
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
        AutomaticInlineSetting::setForOwner($identity['id'], $repo, $enabled);
        return response()->json(['success' => true, 'repository' => strtolower($repo), 'enabled' => $enabled]);
    }
}
