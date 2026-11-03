<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Maintainer-initiated merge and close actions for one pull request.
 *
 * Every action requires: a verified GitHub identity, the repository in the
 * installation grant re-checked on this request, and the exact head SHA the
 * maintainer saw. Merges pass that SHA to GitHub, which rejects the merge if
 * the branch moved. A failed or interrupted request is reported as unknown and
 * is never retried automatically.
 */
final readonly class PullRequestActions
{
    private const ROOT = 'https://api.github.com';

    public function __construct(private HttpClientInterface $http) {}

    /** @return array{merged: true, sha: string} */
    public function merge(string $token, array $access, array $pull, string $expectedHead, string $method = 'squash', ?string $expectedBase = null): array
    {
        [$repository, $number] = $this->authorize($token, $access, $pull, $expectedHead);
        $current = $this->read($token, $repository, $number);
        $this->assertOpen($current, $expectedHead);
        if ($expectedBase !== null && (!preg_match('/\A[a-f0-9]{40}\z/', $expectedBase)
            || !is_string($current['base']['sha'] ?? null) || !hash_equals($expectedBase, $current['base']['sha']))) {
            throw new \RuntimeException('The base changed after review. Reload and review the new revision.');
        }
        if (($current['draft'] ?? false) === true) {
            throw new \RuntimeException('This pull request is still a draft. Mark it ready for review on GitHub before merging.');
        }
        if (($current['mergeable'] ?? null) === false) {
            throw new \RuntimeException('GitHub reports merge conflicts. Resolve them before merging.');
        }

        $body = ['sha' => $expectedHead, 'merge_method' => in_array($method, ['squash', 'merge', 'rebase'], true) ? $method : 'squash'];
        $response = $this->send('PUT', "/repos/{$repository}/pulls/{$number}/merge", $token, $body);
        $data = $this->decode($response['body']);
        if ($response['status'] === 409) {
            throw new \RuntimeException('The branch changed after you reviewed it. Reload the pull request and review the new commits.');
        }
        if ($response['status'] !== 200 || ($data['merged'] ?? false) !== true || !is_string($data['sha'] ?? null)) {
            $reason = is_string($data['message'] ?? null) ? $data['message'] : 'HTTP '.$response['status'];
            throw new \RuntimeException("GitHub did not merge pull request #{$number}: {$reason}");
        }

        return ['merged' => true, 'sha' => $data['sha']];
    }

    /** @return array{draft: false} */
    public function markReady(string $token, array $access, array $pull, string $expectedHead): array
    {
        [$repository, $number] = $this->authorize($token, $access, $pull, $expectedHead);
        $current = $this->read($token, $repository, $number);
        $this->assertOpen($current, $expectedHead);
        if (($current['draft'] ?? false) !== true) {
            return ['draft' => false];
        }
        $nodeId = $current['node_id'] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new \RuntimeException('GitHub did not return an identifier for this pull request.');
        }
        $response = $this->send('POST', '/graphql', $token, [
            'query' => 'mutation($id:ID!){markPullRequestReadyForReview(input:{pullRequestId:$id}){pullRequest{isDraft}}}',
            'variables' => ['id' => $nodeId],
        ]);
        $data = $this->decode($response['body']);
        $isDraft = $data['data']['markPullRequestReadyForReview']['pullRequest']['isDraft'] ?? null;
        if ($response['status'] !== 200 || $isDraft !== false) {
            throw new \RuntimeException("GitHub did not mark pull request #{$number} ready for review.");
        }
        return ['draft' => false];
    }

    /** @return array{state: 'closed'} */
    public function close(string $token, array $access, array $pull, string $expectedHead): array
    {
        [$repository, $number] = $this->authorize($token, $access, $pull, $expectedHead);
        $this->assertOpen($this->read($token, $repository, $number), $expectedHead);
        $response = $this->send('PATCH', "/repos/{$repository}/pulls/{$number}", $token, ['state' => 'closed']);
        $data = $this->decode($response['body']);
        if ($response['status'] !== 200 || ($data['state'] ?? null) !== 'closed') {
            throw new \RuntimeException("GitHub did not close pull request #{$number}.");
        }

        return ['state' => 'closed'];
    }

    /** @return array{0: string, 1: int} */
    private function authorize(string $token, array $access, array $pull, string $expectedHead): array
    {
        $repository = $pull['repository'] ?? null;
        $number = $pull['number'] ?? null;
        if (trim($token) === '' || !is_int($access['userId'] ?? null)) {
            throw new \RuntimeException('Connect GitHub before changing a pull request.');
        }
        if (!is_string($repository) || !preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $repository)
            || !is_int($number) || $number < 1) {
            throw new \RuntimeException('Select a pull request first.');
        }
        if (!isset($access['repositories'][strtolower($repository)])) {
            throw new \RuntimeException('This repository is not in your GitHub App installation.');
        }
        if (!preg_match('/\A[a-f0-9]{40}\z/', $expectedHead) || !hash_equals((string) ($pull['headSha'] ?? ''), $expectedHead)) {
            throw new \RuntimeException('The reviewed revision does not match the loaded pull request. Reload it first.');
        }

        return [$repository, $number];
    }

    private function assertOpen(array $current, string $expectedHead): void
    {
        if (($current['merged'] ?? false) === true) {
            throw new \RuntimeException('This pull request is already merged.');
        }
        if (($current['state'] ?? null) !== 'open') {
            throw new \RuntimeException('This pull request is not open.');
        }
        if (($current['head']['sha'] ?? null) !== $expectedHead) {
            throw new \RuntimeException('The branch changed after you reviewed it. Reload the pull request and review the new commits.');
        }
    }

    private function read(string $token, string $repository, int $number): array
    {
        $response = $this->send('GET', "/repos/{$repository}/pulls/{$number}", $token);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('GitHub could not load the pull request (HTTP '.$response['status'].').');
        }

        return $this->decode($response['body']);
    }

    /** @return array{status: int, body: string} */
    private function send(string $method, string $path, string $token, ?array $json = null): array
    {
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$token,
                'User-Agent' => 'ProofPatcher',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 15,
            'max_duration' => 20,
            'max_redirects' => 0,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }
        try {
            $response = $this->http->request($method, self::ROOT.$path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            $what = $method === 'GET' ? 'GitHub could not be reached.' : 'The outcome is unknown. Check the pull request on GitHub before trying again.';
            throw new \RuntimeException($what, 0, $e);
        }
        if (strlen($body) > 1048576 || str_contains($body, $token)) {
            throw new \RuntimeException('GitHub returned an unexpected response.');
        }

        return ['status' => $status, 'body' => $body];
    }

    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
