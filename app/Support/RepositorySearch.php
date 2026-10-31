<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/**
 * Filters one user's session repository list by substring and paginates it.
 *
 * Pure function of its inputs: no HTTP, no session, no GitHub calls. The
 * caller supplies the already-authorized list (session `github_auth`), so
 * this class can neither leak nor widen access — it only narrows.
 */
final class RepositorySearch
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 50;
    public const MAX_PAGE = 1000;
    public const MAX_QUERY_CHARS = 100;

    /**
     * @param array<int, mixed> $repositories Session entries shaped like ['fullName' => string, 'isPrivate' => bool].
     * @return array{data: array<int, array{fullName: string, isPrivate: bool}>, total: int, page: int, perPage: int, hasMore: bool}
     */
    public static function search(array $repositories, string $query, int $page, int $perPage): array
    {
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);
        $page = min(max($page, 1), self::MAX_PAGE);
        $needle = mb_strtolower(trim(mb_substr($query, 0, self::MAX_QUERY_CHARS)));

        $matches = [];
        foreach ($repositories as $repo) {
            if (!is_array($repo) || !is_string($repo['fullName'] ?? null) || $repo['fullName'] === '') {
                continue;
            }
            if ($needle !== '' && !str_contains(mb_strtolower($repo['fullName']), $needle)) {
                continue;
            }
            // Unknown privacy reads as private: the safe direction for display and gating.
            $matches[] = ['fullName' => $repo['fullName'], 'isPrivate' => ($repo['isPrivate'] ?? null) !== false];
        }

        $total = count($matches);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_slice($matches, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'hasMore' => $offset + $perPage < $total,
        ];
    }
}
