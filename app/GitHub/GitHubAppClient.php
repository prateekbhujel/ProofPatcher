<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only client for selected owned repositories accessible via a GitHub App user access token.
 *
 * Implements strict least-privilege read operations:
 * - Only queries repositories explicitly granted to the app installation.
 * - Accurately tags private vs public repository status.
 * - Never modifies, pushes, comments, or deletes anything on GitHub.
 */
final readonly class GitHubAppClient
{
    private const API_ROOT = 'https://api.github.com';
    private const MAX_RESPONSE_BYTES = 1048576; // 1MB safe payload limit

    public function __construct(private HttpClientInterface $http)
    {
    }

    /**
     * @return array{login: string, name: ?string, avatarUrl: ?string, htmlUrl: string}
     */
    public function getCurrentUser(string $token): array
    {
        $data = $this->get('/user', $token);
        if (!is_string($data['login'] ?? null)) {
            throw new \RuntimeException('Failed to retrieve GitHub user profile.');
        }

        return [
            'id' => is_int($data['id'] ?? null) ? $data['id'] : null,
            'login' => (string) $data['login'],
            'name' => isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            'avatarUrl' => isset($data['avatar_url']) && is_string($data['avatar_url']) ? $data['avatar_url'] : null,
            'htmlUrl' => (string) ($data['html_url'] ?? 'https://github.com/'.$data['login']),
        ];
    }

    /**
     * Retrieves the list of repositories explicitly selected for the GitHub App installation.
     * Paginates both installations and repositories per installation, reporting truncation/errors explicitly.
     *
     * @param-out array{truncated: bool, installationsCount: int, repositoriesCount: int, errors: array<string>} $status
     * @return array<int, array{id: int, name: string, fullName: string, owner: string, isPrivate: bool, defaultBranch: string, htmlUrl: string}>
     */
    public function listSelectedRepositories(string $token, ?array &$status = null): array
    {
        $allInstallations = [];
        $instPage = 1;
        $instTruncated = false;
        $repoTruncated = false;
        $errors = [];

        do {
            $instEndpoint = "/user/installations?per_page=100&page={$instPage}";
            try {
                $installationsData = $this->get($instEndpoint, $token);
            } catch (\Throwable $e) {
                $errors[] = "Installations page {$instPage}: ".$e->getMessage();
                $instTruncated = true;
                break;
            }

            $installations = $installationsData['installations'] ?? [];
            if (!is_array($installations) || empty($installations)) {
                break;
            }

            foreach ($installations as $inst) {
                if (is_array($inst)) {
                    $allInstallations[] = $inst;
                }
            }

            $totalInstallations = (int) ($installationsData['total_count'] ?? count($installations));
            $instPage++;
        } while (count($installations) === 100 && count($allInstallations) < $totalInstallations && $instPage <= 5);

        if ($instPage > 5 && count($allInstallations) < ($installationsData['total_count'] ?? count($allInstallations))) {
            $instTruncated = true;
        }

        $repositories = [];
        $seen = [];

        foreach ($allInstallations as $installation) {
            $installationId = $installation['id'] ?? null;
            if (!is_int($installationId)) {
                continue;
            }

            $instReposCount = 0;
            $page = 1;

            try {
                do {
                    $endpoint = "/user/installations/{$installationId}/repositories?per_page=100&page={$page}";
                    $reposData = $this->get($endpoint, $token);
                    $repoList = $reposData['repositories'] ?? [];
                    if (!is_array($repoList) || empty($repoList)) {
                        break;
                    }

                    foreach ($repoList as $repo) {
                        $instReposCount++;
                        $fullName = (string) ($repo['full_name'] ?? '');
                        if ($fullName === '' || isset($seen[$fullName])) {
                            continue;
                        }
                        $seen[$fullName] = true;

                        $repositories[] = [
                            'id' => (int) ($repo['id'] ?? 0),
                            'name' => (string) ($repo['name'] ?? ''),
                            'fullName' => $fullName,
                            'owner' => (string) ($repo['owner']['login'] ?? ''),
                            'isPrivate' => !self::isExplicitlyPublic($repo),
                            'defaultBranch' => (string) ($repo['default_branch'] ?? 'main'),
                            'htmlUrl' => (string) ($repo['html_url'] ?? 'https://github.com/'.$fullName),
                        ];
                    }

                    $totalCount = (int) ($reposData['total_count'] ?? count($repoList));
                    $page++;
                } while (count($repoList) === 100 && $instReposCount < $totalCount && $page <= 5);

                if ($page > 5 && $instReposCount < $totalCount) {
                    $repoTruncated = true;
                }
            } catch (\Throwable $e) {
                $errors[] = "Installation {$installationId}: ".$e->getMessage();
                $repoTruncated = true;
                continue;
            }
        }

        $status = [
            'truncated' => $instTruncated || $repoTruncated,
            'installationsCount' => count($allInstallations),
            'repositoriesCount' => count($repositories),
            'errors' => $errors,
        ];

        return $repositories;
    }

    /**
     * Lists open pull requests for an authorized repository.
     *
     * @param-out array{truncated: bool, count: int, errors: array<string>} $status
     * @return array<int, array{number: int, title: string, head: string, base: string, headSha: string, baseSha: string, user: string, isPrivate: bool}>
     */
    public function listOpenPullRequests(string $token, string $repository, ?array &$status = null, string $stateFilter = 'open'): array
    {
        $this->validateRepositoryName($repository);
        $queryState = in_array($stateFilter, ['open', 'closed', 'all'], true) ? $stateFilter : 'open';
        $results = [];
        $page = 1;
        $truncated = false;
        $errors = [];

        do {
            try {
                $pulls = $this->get("/repos/{$repository}/pulls?state={$queryState}&per_page=100&page={$page}", $token);
            } catch (\Throwable $e) {
                $errors[] = "Pulls page {$page}: ".$e->getMessage();
                $truncated = true;
                break;
            }

            if (!is_array($pulls) || empty($pulls)) {
                break;
            }

            foreach ($pulls as $pull) {
                if (!isset($pull['number'], $pull['title'], $pull['head']['sha'], $pull['base']['sha'])) {
                    continue;
                }

                $isMerged = ($pull['merged'] ?? false) === true || ($pull['merged_at'] ?? null) !== null;
                $isDraft = ($pull['draft'] ?? false) === true;
                $state = $isMerged ? 'merged' : (string) ($pull['state'] ?? 'open');
                $results[] = [
                    'number' => (int) $pull['number'],
                    'state' => $state,
                    'isMerged' => $isMerged,
                    'isDraft' => $isDraft,
                    'title' => (string) $pull['title'],
                    'head' => (string) ($pull['head']['ref'] ?? ''),
                    'base' => (string) ($pull['base']['ref'] ?? ''),
                    'headSha' => (string) ($pull['head']['sha'] ?? ''),
                    'baseSha' => (string) ($pull['base']['sha'] ?? ''),
                    'user' => (string) ($pull['user']['login'] ?? 'unknown'),
                    'body' => (string) ($pull['body'] ?? ''),
                    'isPrivate' => !is_array($pull['base']['repo'] ?? null) || !self::isExplicitlyPublic($pull['base']['repo']),
                ];
            }

            $page++;
        } while (count($pulls) === 100 && $page <= 5);

        if ($page > 5) {
            $truncated = true;
        }

        $status = [
            'truncated' => $truncated,
            'count' => count($results),
            'errors' => $errors,
        ];

        return $results;
    }

    /**
     * Reads a complete, bounded review-comment list for a pinned public PR.
     * Comment bodies are intentionally discarded; replies remain untrusted.
     *
     * @return list<array{id:int, parentId:int}>
     */
    public function listHumanReviewReplies(string $token, string $repository, int $number, string $head, string $base): array
    {
        $path = "/repos/{$repository}/pulls/{$number}";
        $this->assertPublicPullRevision($token, $repository, $number, $head, $base);
        $replies = [];
        $seen = [];
        $botRoots = [];
        for ($page = 1; $page <= 5; $page++) {
            $comments = $this->get($path."/comments?per_page=100&page={$page}", $token);
            if (!array_is_list($comments) || count($comments) > 100) {
                throw new \RuntimeException('Review comment list is incomplete.');
            }
            foreach ($comments as $comment) {
                if (!is_array($comment) || !is_int($comment['id'] ?? null) || $comment['id'] < 1 || isset($seen[$comment['id']])) {
                    throw new \RuntimeException('Review comment list is invalid.');
                }
                $seen[$comment['id']] = true;
                $parent = $comment['in_reply_to_id'] ?? null;
                if ($parent !== null && (!is_int($parent) || $parent < 1)) {
                    throw new \RuntimeException('Review comment parent is invalid.');
                }
                if ($parent !== null && ($comment['user']['type'] ?? null) === 'User') {
                    $replies[] = ['id' => $comment['id'], 'parentId' => $parent];
                }
                if ($parent === null && ($comment['user']['type'] ?? null) === 'Bot') {
                    $botRoots[$comment['id']] = true;
                }
            }
            if (count($comments) < 100) {
                $this->assertPublicPullRevision($token, $repository, $number, $head, $base);
                return array_values(array_filter($replies, static fn (array $reply): bool => isset($botRoots[$reply['parentId']])));
            }
        }
        throw new \RuntimeException('Review comment list exceeds the supported limit.');
    }

    public function assertPublicPullRevision(string $token, string $repository, int $number, string $head, string $base): void
    {
        $this->validateRepositoryName($repository);
        if ($number < 1 || !preg_match('/\A[a-f0-9]{40}\z/', $head) || !preg_match('/\A[a-f0-9]{40}\z/', $base)) {
            throw new \InvalidArgumentException('Invalid pull request revision.');
        }
        $pull = $this->get("/repos/{$repository}/pulls/{$number}", $token);
        $repo = $pull['base']['repo'] ?? null;
        if (($pull['number'] ?? null) !== $number || ($pull['state'] ?? null) !== 'open'
            || ($pull['head']['sha'] ?? null) !== $head || ($pull['base']['sha'] ?? null) !== $base
            || !is_array($repo) || !self::isExplicitlyPublic($repo)) {
            throw new \RuntimeException('Pull request revision or public visibility changed.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function listRecentIssueComments(string $token, string $repository, string $since): array
    {
        $this->validateRepositoryName($repository);
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $since)) {
            throw new \InvalidArgumentException('Invalid comment start time.');
        }
        $all = [];
        $seen = [];
        for ($page = 1; $page <= 5; $page++) {
            $comments = $this->get('/repos/'.$repository.'/issues/comments?since='.rawurlencode($since).'&per_page=100&page='.$page, $token);
            if (!array_is_list($comments) || count($comments) > 100) {
                throw new \RuntimeException('GitHub comment list was incomplete.');
            }
            foreach ($comments as $comment) {
                if (!is_array($comment) || !is_int($comment['id'] ?? null) || $comment['id'] < 1 || isset($seen[$comment['id']])) {
                    throw new \RuntimeException('GitHub comment list was invalid.');
                }
                $seen[$comment['id']] = true;
                $all[] = $comment;
            }
            if (count($comments) < 100) return $all;
        }
        throw new \RuntimeException('GitHub comment list exceeded the supported limit.');
    }

    /** @return array<string, mixed> */
    public function getIssueComment(string $token, string $repository, int $id): array
    {
        $this->validateRepositoryName($repository);
        if ($id < 1) throw new \InvalidArgumentException('Invalid comment ID.');
        return $this->get('/repos/'.$repository.'/issues/comments/'.$id, $token);
    }

    public function canMaintainRepository(string $token, string $repository, string $login, int $userId): bool
    {
        $this->validateRepositoryName($repository);
        if ($userId < 1 || !preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\z/', $login)) return false;
        $data = $this->get('/repos/'.$repository.'/collaborators/'.$login.'/permission', $token);
        return in_array($data['permission'] ?? null, ['admin', 'maintain', 'write'], true)
            && is_array($data['user'] ?? null)
            && ($data['user']['id'] ?? null) === $userId
            && strcasecmp((string) ($data['user']['login'] ?? ''), $login) === 0;
    }

    /**
     * Lists issues (excluding pull requests) for an authorized repository.
     *
     * @param-out array{truncated: bool, count: int, errors: array<string>} $status
     * @return array<int, array{number: int, state: string, title: string, body: string, user: string, htmlUrl: string, createdAt: string, labels: array<string>, isPrivate: bool}>
     */
    public function listOpenIssues(string $token, string $repository, ?array &$status = null, string $stateFilter = 'open'): array
    {
        $this->validateRepositoryName($repository);
        $queryState = in_array($stateFilter, ['open', 'closed', 'all'], true) ? $stateFilter : 'open';
        $results = [];
        $page = 1;
        $truncated = false;
        $errors = [];

        do {
            try {
                $issues = $this->get("/repos/{$repository}/issues?state={$queryState}&per_page=100&page={$page}", $token);
            } catch (\Throwable $e) {
                $errors[] = "Issues page {$page}: ".$e->getMessage();
                $truncated = true;
                break;
            }

            if (!is_array($issues) || empty($issues)) {
                break;
            }

            foreach ($issues as $issue) {
                // In GitHub REST API, pull requests are included in /issues unless filtered
                if (isset($issue['pull_request'])) {
                    continue;
                }

                if (!isset($issue['number'], $issue['title'])) {
                    continue;
                }

                $labels = [];
                if (isset($issue['labels']) && is_array($issue['labels'])) {
                    foreach ($issue['labels'] as $label) {
                        if (is_array($label) && isset($label['name'])) {
                            $labels[] = (string) $label['name'];
                        } elseif (is_string($label)) {
                            $labels[] = $label;
                        }
                    }
                }

                $results[] = [
                    'number' => (int) $issue['number'],
                    'state' => (string) ($issue['state'] ?? 'open'),
                    'title' => (string) $issue['title'],
                    'body' => (string) ($issue['body'] ?? ''),
                    'user' => (string) ($issue['user']['login'] ?? 'unknown'),
                    'htmlUrl' => (string) ($issue['html_url'] ?? "https://github.com/{$repository}/issues/{$issue['number']}"),
                    'createdAt' => (string) ($issue['created_at'] ?? ''),
                    'updatedAt' => (string) ($issue['updated_at'] ?? ''),
                    'labels' => $labels,
                    'isPrivate' => !is_array($issue['repository'] ?? null) || !self::isExplicitlyPublic($issue['repository']),
                ];
            }

            $page++;
        } while (count($issues) === 100 && $page <= 5);

        if ($page > 5) {
            $truncated = true;
        }

        $status = [
            'truncated' => $truncated,
            'count' => count($results),
            'errors' => $errors,
        ];

        return $results;
    }

    /**
     * Fetches authenticated issue context and file content at repository default branch.
     *
     * @return array{
     *     repository: string,
     *     issueNumber: int,
     *     issueUrl: string,
     *     title: string,
     *     body: string,
     *     commit: string,
     *     path: string,
     *     source: string,
     *     sourceSha256: string
     * }
     */
    public function fetchIssueContext(string $token, string $repository, int $issueNumber, string $path): array
    {
        $this->validateRepositoryName($repository);
        PublicIssueContext::validatePath($path);

        $repoData = $this->get("/repos/{$repository}", $token);
        $defaultBranch = (string) ($repoData['default_branch'] ?? 'main');

        $commitData = $this->get("/repos/{$repository}/commits/".rawurlencode($defaultBranch), $token);
        $commit = (string) ($commitData['sha'] ?? '');
        if (!preg_match('/\A[a-f0-9]{40}\z/', $commit)) {
            throw new \RuntimeException('Invalid commit hash for default branch.');
        }

        $issueData = $this->get("/repos/{$repository}/issues/{$issueNumber}", $token);
        if (($issueData['number'] ?? null) !== $issueNumber || isset($issueData['pull_request'])) {
            throw new \RuntimeException('The selected issue is unavailable or is a pull request.');
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $fileData = $this->get("/repos/{$repository}/contents/{$encodedPath}?ref={$commit}", $token);

        if (($fileData['type'] ?? '') !== 'file' || ($fileData['encoding'] ?? '') !== 'base64') {
            throw new \RuntimeException("Unable to read target file '{$path}' as a text file.");
        }

        $source = base64_decode(preg_replace('/\s+/', '', (string) ($fileData['content'] ?? '')), true);
        if (!is_string($source) || str_contains($source, "\0")) {
            throw new \RuntimeException("The file '{$path}' is binary or could not be decoded.");
        }

        $context = [
            'repository' => $repository,
            'issueNumber' => $issueNumber,
            'issueUrl' => "https://github.com/{$repository}/issues/{$issueNumber}",
            'title' => (string) ($issueData['title'] ?? "Issue #{$issueNumber}"),
            'body' => (string) ($issueData['body'] ?? ''),
            'commit' => $commit,
            'path' => $path,
            'source' => $source,
            'sourceSha256' => hash('sha256', $source),
        ];

        PublicIssueContext::validateContext($context);

        return $context;
    }

    /**
     * Fetches unified diff patches for a pull request using authorized read access.
     * Performs a post-fetch recheck of head/base SHA, changed_files, and visibility to prevent TOCTOU race.
     * Returns authoritative metadata for title, state, branches, and author directly from GitHub.
     *
     * @return array{repository: string, number: int, title: string, state: string, isMerged: bool, head: string, base: string, headSha: string, baseSha: string, user: string, url: string, isPrivate: bool, changedFiles: int, filesTruncated: bool, files: array}
     */
    public function fetchPullRequestPatch(string $token, string $repository, int $number): array
    {
        $this->validateRepositoryName($repository);
        $pull = $this->get("/repos/{$repository}/pulls/{$number}", $token);
        $headSha = (string) ($pull['head']['sha'] ?? '');
        $baseSha = (string) ($pull['base']['sha'] ?? '');
        $baseRepo = $pull['base']['repo'] ?? null;
        $isPrivate = !is_array($baseRepo) || !self::isExplicitlyPublic($baseRepo);
        $changedFiles = (int) ($pull['changed_files'] ?? 0);

        if (!preg_match('/\A[a-f0-9]{40}\z/', $headSha) || !preg_match('/\A[a-f0-9]{40}\z/', $baseSha)) {
            throw new \RuntimeException('Incomplete commit SHA data in pull request.');
        }

        $files = $this->get("/repos/{$repository}/pulls/{$number}/files?per_page=100", $token);
        if (!is_array($files)) {
            throw new \RuntimeException('Failed to retrieve changed files.');
        }

        // Recheck pull request after reading mutable files to protect against TOCTOU race
        $postPull = $this->get("/repos/{$repository}/pulls/{$number}", $token);
        $postHeadSha = (string) ($postPull['head']['sha'] ?? '');
        $postBaseSha = (string) ($postPull['base']['sha'] ?? '');
        $postBaseRepo = $postPull['base']['repo'] ?? null;
        $postIsPrivate = !is_array($postBaseRepo) || !self::isExplicitlyPublic($postBaseRepo);
        $postChangedFiles = (int) ($postPull['changed_files'] ?? 0);

        if ($postHeadSha !== $headSha || $postBaseSha !== $baseSha || $postChangedFiles !== $changedFiles || $postIsPrivate !== $isPrivate) {
            throw new \RuntimeException('The pull request or repository visibility changed while diff was loading. Capture it again.');
        }

        $resultFiles = [];
        foreach ($files as $file) {
            $name = (string) ($file['filename'] ?? '');
            $patch = isset($file['patch']) && is_string($file['patch']) ? $file['patch'] : null;
            $resultFiles[] = [
                'name' => $name,
                'status' => $patch !== null ? 'available' : 'missing',
                'patch' => $patch,
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
            ];
        }

        $isMerged = ($postPull['merged'] ?? false) === true || ($postPull['merged_at'] ?? null) !== null;
        $state = $isMerged ? 'merged' : (string) ($postPull['state'] ?? 'open');

        return [
            'repository' => $repository,
            'number' => $number,
            'title' => (string) ($postPull['title'] ?? 'PR #'.$number),
            'body' => (string) ($postPull['body'] ?? ''),
            'state' => $state,
            'isMerged' => $isMerged,
            'isDraft' => ($postPull['draft'] ?? false) === true,
            'head' => (string) ($postPull['head']['ref'] ?? ''),
            'base' => (string) ($postPull['base']['ref'] ?? ''),
            'headSha' => $headSha,
            'baseSha' => $baseSha,
            'user' => (string) ($postPull['user']['login'] ?? 'unknown'),
            'url' => (string) ($postPull['html_url'] ?? "https://github.com/{$repository}/pull/{$number}"),
            'isPrivate' => $isPrivate,
            'changedFiles' => $changedFiles,
            'filesTruncated' => $changedFiles > count($resultFiles),
            'files' => $resultFiles,
        ];
    }

    /**
     * Read-only merge readiness for one pinned PR revision. Unknown check or
     * thread state is reported as unknown/null, never as passing.
     *
     * @return array{state: ?string, isMerged: ?bool, isDraft: ?bool, currentHead: ?string, currentBase: ?string, checksState: string, unresolvedThreads: ?int}
     */
    public function getMergeReadiness(string $token, string $repository, int $number, string $headSha): array
    {
        $this->validateRepositoryName($repository);
        if ($number < 1 || !preg_match('/\A[a-f0-9]{40}\z/', $headSha)) {
            throw new \InvalidArgumentException('Invalid pull request revision.');
        }
        $pull = $this->get("/repos/{$repository}/pulls/{$number}", $token);

        $checksState = $this->completeCheckState($token, $repository, $headSha);

        $unresolved = null;
        try {
            [$owner, $name] = explode('/', $repository, 2);
            $data = $this->post('/graphql', $token, [
                'query' => 'query($owner:String!,$name:String!,$number:Int!){repository(owner:$owner,name:$name){pullRequest(number:$number){reviewThreads(first:100){pageInfo{hasNextPage} nodes{isResolved}}}}}',
                'variables' => ['owner' => $owner, 'name' => $name, 'number' => $number],
            ]);
            $threads = $data['data']['repository']['pullRequest']['reviewThreads'] ?? null;
            if (empty($data['errors']) && is_array($threads) && (($threads['pageInfo']['hasNextPage'] ?? true) === false) && is_array($threads['nodes'] ?? null) && array_is_list($threads['nodes'])) {
                $unresolved = 0;
                foreach ($threads['nodes'] as $node) {
                    if (!is_array($node) || !is_bool($node['isResolved'] ?? null)) {
                        $unresolved = null;
                        break;
                    }
                    if ($node['isResolved'] === false) {
                        $unresolved++;
                    }
                }
            }
        } catch (\Throwable) {
            $unresolved = null;
        }

        return [
            'state' => is_string($pull['state'] ?? null) ? $pull['state'] : null,
            'isMerged' => is_bool($pull['merged'] ?? null) ? $pull['merged'] : null,
            'isDraft' => is_bool($pull['draft'] ?? null) ? $pull['draft'] : null,
            'currentHead' => is_string($pull['head']['sha'] ?? null) ? $pull['head']['sha'] : null,
            'currentBase' => is_string($pull['base']['sha'] ?? null) ? $pull['base']['sha'] : null,
            'baseBranch' => is_string($pull['base']['ref'] ?? null) ? $pull['base']['ref'] : null,
            'checksState' => $checksState,
            'unresolvedThreads' => $unresolved,
        ];
    }

    /** Fail closed unless GitHub enforces up-to-date required checks for administrators. */
    public function hasStrictMergeProtection(string $token, string $repository, ?string $branch): bool
    {
        $this->validateRepositoryName($repository);
        if (!is_string($branch) || $branch === '' || strlen($branch) > 255 || str_contains($branch, "\0")) return false;
        try {
            $p = $this->get('/repos/'.$repository.'/branches/'.rawurlencode($branch).'/protection', $token);
            $checks = $p['required_status_checks'] ?? null;
            if (!is_array($checks) || ($checks['strict'] ?? null) !== true
                || ($p['enforce_admins']['enabled'] ?? null) !== true
                || ($p['required_conversation_resolution']['enabled'] ?? null) !== true) return false;
            $contexts = $checks['contexts'] ?? null;
            if (!is_array($contexts) || !array_is_list($contexts) || $contexts === []) return false;
            foreach ($contexts as $context) if (!is_string($context) || trim($context) === '') return false;
            $bypass = $p['required_pull_request_reviews']['bypass_pull_request_allowances'] ?? null;
            if ($bypass !== null) {
                if (!is_array($bypass)) return false;
                foreach (['users', 'teams', 'apps'] as $type) {
                    if (!is_array($bypass[$type] ?? null) || $bypass[$type] !== []) return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Check runs and legacy commit statuses must both be complete and healthy. */
    private function completeCheckState(string $token, string $repository, string $headSha): string
    {
        try {
            $count = 0;
            $total = null;
            $state = 'success';
            $hasSuccessfulRun = false;
            $seenIds = [];
            for ($page = 1; $page <= 5; $page++) {
                $runs = $this->get("/repos/{$repository}/commits/{$headSha}/check-runs?per_page=100&page={$page}", $token);
                if (!is_int($runs['total_count'] ?? null) || $runs['total_count'] < 0
                    || !is_array($runs['check_runs'] ?? null) || !array_is_list($runs['check_runs'])) return 'unknown';
                if ($total !== null && $runs['total_count'] !== $total) return 'unknown';
                $total = $runs['total_count'];
                $list = $runs['check_runs'];
                if (count($list) > 100 || ($list === [] && $count < $total)) return 'unknown';
                foreach ($list as $run) {
                    if (!is_array($run) || !is_int($run['id'] ?? null) || $run['id'] < 1
                        || isset($seenIds[$run['id']]) || !is_string($run['status'] ?? null)) return 'unknown';
                    $seenIds[$run['id']] = true;
                    if ($run['status'] === 'completed') {
                        if (!is_string($run['conclusion'] ?? null)) return 'unknown';
                        if ($run['conclusion'] === 'success') $hasSuccessfulRun = true;
                        if (!in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true)) $state = 'failure';
                    } elseif (in_array($run['status'], ['queued', 'in_progress', 'waiting', 'requested', 'pending'], true)) {
                        if ($state !== 'failure') $state = 'pending';
                    } else {
                        return 'unknown';
                    }
                    $count++;
                }
                if ($count === $total) break;
                if ($count > $total || count($list) < 100) return 'unknown';
            }
            if ($count !== $total) return 'unknown';

            // GitHub's combined state covers legacy commit statuses, which are
            // separate from check runs. Empty status contexts are allowed only
            // when at least one check run exists.
            $statuses = $this->get("/repos/{$repository}/commits/{$headSha}/status?per_page=100", $token);
            if (!is_int($statuses['total_count'] ?? null) || $statuses['total_count'] < 0
                || !is_string($statuses['state'] ?? null)
                || !in_array($statuses['state'], ['success', 'pending', 'failure', 'error'], true)) return 'unknown';
            if ($statuses['total_count'] === 0) {
                if ($count === 0 || $statuses['state'] !== 'pending') return 'unknown';
                // Skipped/neutral checks may be acceptable alongside an actual
                // success, but alone do not establish that anything passed.
                return $state === 'success' && !$hasSuccessfulRun ? 'unknown' : $state;
            }
            if (in_array($statuses['state'], ['failure', 'error'], true)) return 'failure';
            if ($statuses['state'] === 'pending' && $state !== 'failure') return 'pending';
            return $state;
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** @param array<string, mixed> $json */
    private function post(string $path, string $token, array $json): array
    {
        $response = $this->http->request('POST', self::API_ROOT.$path, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$token,
                'User-Agent' => 'ProofPatcher',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'json' => $json,
            'timeout' => 15,
            'max_redirects' => 0,
        ]);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        if ($status !== 200 || strlen($content) > self::MAX_RESPONSE_BYTES || str_contains($content, $token)) {
            throw new \RuntimeException("GitHub API returned HTTP {$status} for path {$path}.");
        }
        $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub API returned non-JSON data.');
        }
        return $data;
    }

    /**
     * Determines whether a repository or repository descriptor is explicitly, unambiguously public.
     * Fails closed: if visibility is missing, null, not 'public', or if private flag is not strictly false,
     * it MUST be treated as private. Private content must never default to public.
     */
    public static function isExplicitlyPublic(array $repo): bool
    {
        if (!array_key_exists('private', $repo) || $repo['private'] !== false) {
            return false;
        }

        if (array_key_exists('visibility', $repo) && $repo['visibility'] !== 'public') {
            return false;
        }

        return true;
    }

    private function validateRepositoryName(string $repository): void
    {
        if (!preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', trim($repository), $m)
            || in_array($m[2], ['.', '..'], true)) {
            throw new \InvalidArgumentException('A valid repository name in owner/repo format is required.');
        }
    }

    private function get(string $path, string $token): array
    {
        $url = str_starts_with($path, 'http') ? $path : self::API_ROOT.$path;
        $response = $this->http->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$token,
                'User-Agent' => 'ProofPatcher',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 15,
            'max_redirects' => 0,
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("GitHub API returned HTTP {$status} for path {$path}.");
        }

        $content = $response->getContent(false);
        if (strlen($content) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('GitHub API response exceeded safe byte limit.');
        }

        if (str_contains($content, $token)) {
            throw new \RuntimeException('GitHub API response contained sensitive authorization token.');
        }

        $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub API returned non-JSON data.');
        }

        return $data;
    }
}
