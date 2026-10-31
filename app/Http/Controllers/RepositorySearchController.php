<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ProofPatch\Support\RepositorySearch;

/**
 * Paginated search over the connected user's own repositories.
 *
 * Powers select2-style pickers so pages never ship the whole list to the
 * browser. Read-only: it filters the session list verified by
 * AuthorizeGithubTasks and returns names plus privacy flags only.
 */
final class RepositorySearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $access = $request->attributes->get('proofpatch_github_access');
        $userId = is_array($access) ? ($access['userId'] ?? null) : null;
        if (!is_int($userId) || $userId <= 0) {
            return response()->json(['error' => 'Connect GitHub to search repositories.'], 401);
        }

        $auth = $request->session()->get('github_auth');
        $repositories = is_array($auth) && is_array($auth['repositories'] ?? null) ? $auth['repositories'] : [];

        $result = RepositorySearch::search(
            $repositories,
            (string) $request->query('q', ''),
            $this->positiveInt($request->query('page'), 1),
            $this->positiveInt($request->query('per_page'), RepositorySearch::DEFAULT_PER_PAGE),
        );

        return response()
            ->json([
                'data' => $result['data'],
                'meta' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage'],
                    'hasMore' => $result['hasMore'],
                ],
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,6}$/', trim($value)) === 1) {
            return (int) $value;
        }
        return $fallback;
    }
}
