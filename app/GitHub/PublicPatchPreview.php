<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Fetches a bounded, public-only read-only preview, optionally as a signed-in user. */
final readonly class PublicPatchPreview
{
    private const MAX_PATCH_BYTES = 65536;
    private const MAX_TOTAL_BYTES = 131072;

    public function __construct(private HttpClientInterface $http, private ?string $token = null)
    {
        if ($token !== null && (strlen($token) < 16 || strlen($token) > 4096 || !preg_match('/\A[\x21-\x7e]+\z/', $token))) {
            throw new \InvalidArgumentException('GitHub credentials are invalid.');
        }
    }

    /** Recheck public visibility and both pinned PR revisions without fetching content. */
    public function assertCurrent(array $captured): void
    {
        $repository = $captured['repository'] ?? null;
        $number = $captured['number'] ?? null;
        $head = $captured['headSha'] ?? null;
        $base = $captured['baseSha'] ?? null;
        if (!is_string($repository)
            || !preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository, $parts)
            || in_array($parts[2], ['.', '..'], true) || !is_int($number) || $number < 1 || $number > 999999999
            || !is_string($head) || !preg_match('/\A[a-f0-9]{40}\z/', $head)
            || !is_string($base) || !preg_match('/\A[a-f0-9]{40}\z/', $base)) {
            throw new \InvalidArgumentException('The saved pull request is incomplete.');
        }
        $root = 'https://api.github.com/repos/'.rawurlencode($parts[1]).'/'.rawurlencode($parts[2]);
        $this->assertPublic($this->get($root), $repository);
        $count = $this->assertRevision($this->get($root.'/pulls/'.$number), $number, $head, $base);
        if (isset($captured['changedFiles']) && (!is_int($captured['changedFiles']) || $captured['changedFiles'] !== $count)) {
            throw new \RuntimeException('The captured pull request changed. Capture it again.');
        }
    }

    public function fetch(array $captured): array
    {
        $repository = $captured['repository'] ?? null;
        $number = $captured['number'] ?? null;
        $head = $captured['headSha'] ?? null;
        $base = $captured['baseSha'] ?? null;
        if (!is_string($repository)
            || !preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository, $parts)
            || in_array($parts[2], ['.', '..'], true) || !is_int($number) || $number < 1 || $number > 999999999
            || !is_string($head) || !preg_match('/\A[a-f0-9]{40}\z/', $head)
            || !is_string($base) || !preg_match('/\A[a-f0-9]{40}\z/', $base)) {
            throw new \InvalidArgumentException('The saved pull request is incomplete.');
        }
        $root = 'https://api.github.com/repos/'.rawurlencode($parts[1]).'/'.rawurlencode($parts[2]);
        $pullUrl = $root.'/pulls/'.$number;
        $this->assertPublic($this->get($root), $repository);
        $first = $this->get($pullUrl);
        $count = $this->assertRevision($first, $number, $head, $base);
        if (isset($captured['changedFiles']) && (!is_int($captured['changedFiles']) || $captured['changedFiles'] !== $count)) {
            throw new \RuntimeException('The captured pull request changed. Capture it again.');
        }
        $files = PullRequestChangedFiles::fetch($this->get(...), $pullUrl, $count);
        $last = $this->get($pullUrl);
        if ($this->assertRevision($last, $number, $head, $base) !== $count) {
            throw new \RuntimeException('The pull request changed. Capture it again.');
        }
        $this->assertPublic($this->get($root), $repository);

        $result = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['filename'] ?? null) || strlen($file['filename']) > 4096
                || !is_int($file['additions'] ?? null) || !is_int($file['deletions'] ?? null)
                || $file['additions'] < 0 || $file['deletions'] < 0) {
                throw new \RuntimeException('GitHub returned invalid changed files.');
            }
            $patch = $file['patch'] ?? null;
            $status = 'available';
            if (!is_string($patch)) {
                $patch = null;
                $status = 'missing';
            } elseif (strlen($patch) > self::MAX_PATCH_BYTES || $totalBytes + strlen($patch) > self::MAX_TOTAL_BYTES) {
                $patch = null;
                $status = 'oversize';
            } else {
                $totalBytes += strlen($patch);
                $additions = 0;
                $deletions = 0;
                foreach (explode("\n", $patch) as $line) {
                    if (str_starts_with($line, '+')) ++$additions;
                    if (str_starts_with($line, '-')) ++$deletions;
                }
                if ($additions !== $file['additions'] || $deletions !== $file['deletions']) {
                    $status = 'partial';
                }
            }
            $result[] = ['name' => $file['filename'], 'status' => $status, 'patch' => $patch];
        }

        return ['repository' => $repository, 'number' => $number, 'headSha' => $head, 'baseSha' => $base,
            'isPrivate' => false, 'files' => $result, 'filesTruncated' => $count > count($result), 'changedFiles' => $count];
    }

    private function assertPublic(array $repo, string $expected): void
    {
        if (($repo['private'] ?? null) !== false || ($repo['visibility'] ?? null) !== 'public'
            || !is_string($repo['full_name'] ?? null) || strcasecmp($repo['full_name'], $expected) !== 0) {
            throw new \RuntimeException('The repository could not be verified as public.');
        }
    }

    private function assertRevision(array $pull, int $number, string $head, string $base): int
    {
        if (($pull['number'] ?? null) !== $number || ($pull['head']['sha'] ?? null) !== $head
            || ($pull['base']['sha'] ?? null) !== $base || !is_int($pull['changed_files'] ?? null)
            || $pull['changed_files'] < 0) {
            throw new \RuntimeException('The pull request changed or is unavailable. Capture it again.');
        }
        return $pull['changed_files'];
    }

    private function get(string $url): array
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ProofPatcher', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token !== null) $headers['Authorization'] = 'Bearer '.$this->token;
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => $headers, 'max_redirects' => 0, 'timeout' => 15, 'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable) {
            throw new \RuntimeException('Public GitHub request failed.');
        }
        if ($status !== 200) throw new \RuntimeException('Public GitHub data is unavailable (HTTP '.$status.').');
        if (strlen($raw) > 2097152 || ($this->token !== null && str_contains($raw, $this->token))) {
            throw new \RuntimeException('GitHub response is too large to preview.');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('GitHub returned invalid data.');
        }
        if (!is_array($data) || ResponseTokenGuard::contains($data, $this->token)) {
            throw new \RuntimeException('GitHub returned invalid data.');
        }
        return $data;
    }
}
