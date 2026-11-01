<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use PDO;
use PDOException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Draft implementation: personal, public repositories only.
 * A verified account must explicitly opt in for each repository. This class
 * never enables an opt-in and does not merge or close pull requests.
 */
final readonly class AutomaticInlineCommentPublisher
{
    private const ROOT = 'https://api.github.com';

    /** @param null|\Closure(string): bool $credentialIsCurrent Test seam; production uses the secure token store. */
    public function __construct(private PDO $database, private HttpClientInterface $http, private ?\Closure $credentialIsCurrent = null) {}

    public static function revisionKey(string $repository, int $number, string $head, string $base): string
    {
        return hash('sha256', strtolower($repository)."\n".$number."\n".$head."\n".$base);
    }

    /** Returns a posted URL, or null if there is no eligible concern. */
    public function publish(string $token, int $userId, array $pull, array $findings, ?string $verdict = null): ?array
    {
        $repository = $pull['repository'] ?? null;
        $number = $pull['number'] ?? null;
        $head = $pull['headSha'] ?? null;
        $base = $pull['baseSha'] ?? null;
        if ($userId < 1 || $token === '' || !is_string($repository)
            || !preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository, $parts)
            || in_array($parts[2], ['.', '..'], true)
            || !is_int($number) || $number < 1 || $number > 999999999
            || !is_string($head) || !preg_match('/\A[a-f0-9]{40}\z/', $head)
            || !is_string($base) || !preg_match('/\A[a-f0-9]{40}\z/', $base)) {
            throw new \InvalidArgumentException('Invalid identity or pull request.');
        }
        if (!$this->isEnabled($userId, $repository)) {
            return null;
        }

        $eligible = null;
        foreach ($findings as $finding) {
            if (!is_array($finding) || ($finding['status'] ?? null) !== 'concern'
                || ($finding['commentable'] ?? null) !== true
                || !is_string($finding['path'] ?? null) || $finding['path'] === '' || strlen($finding['path']) > 4096
                || !is_int($finding['lineHint'] ?? null) || $finding['lineHint'] < 1
                || !is_string($finding['comment'] ?? null)) {
                continue;
            }
            $comment = trim($finding['comment']);
            // Do not allow model-supplied pings, links, HTML, or control bytes.
            if ($comment === '' || strlen($comment) > 1000 || preg_match('~@|https?://|www\.|[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~i', $comment)) {
                continue;
            }
            $eligible = ['path' => $finding['path'], 'line' => $finding['lineHint'], 'comment' => $comment,
                'status' => $finding['status'],
                'suggestion' => self::sanitizeSuggestion($finding['suggestion'] ?? null)];
            break;
        }
        if ($eligible === null) return null;

        $this->assertAuthorized($token, $userId, $repository, $parts[1]);

        $root = '/repos/'.rawurlencode($parts[1]).'/'.rawurlencode($parts[2]).'/pulls/'.$number;
        $first = $this->read($root, $token);
        $count = $this->pullFileCount($first, $number, $head, $base);
        $files = $this->read($root.'/files?per_page=100', $token);
        if ($count > 100 || !array_is_list($files) || count($files) !== $count) {
            throw new \RuntimeException('The pull request file list is incomplete.');
        }
        $targetPatch = null;
        $total = 0;
        $paths = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['filename'] ?? null) || isset($paths[$file['filename']])
                || !is_string($file['patch'] ?? null) || strlen($file['patch']) > 16384
                || !is_int($file['additions'] ?? null) || !is_int($file['deletions'] ?? null)) {
                throw new \RuntimeException('The pull request diff is incomplete.');
            }
            $paths[$file['filename']] = true;
            $total += strlen($file['patch']);
            $added = $removed = 0;
            foreach (explode("\n", $file['patch']) as $line) {
                if (str_starts_with($line, '+')) ++$added;
                if (str_starts_with($line, '-')) ++$removed;
            }
            if ($total > 131072 || $added !== $file['additions'] || $removed !== $file['deletions']) {
                throw new \RuntimeException('The pull request diff is incomplete.');
            }
            if ($file['filename'] === $eligible['path']) $targetPatch = $file['patch'];
        }
        if (!is_string($targetPatch)
            || !(DiffHunkParser::validateAnchor($targetPatch, $eligible['line'], $head, $head, 'RIGHT')['commentable'] ?? false)) {
            throw new \RuntimeException('The concern is not anchored to the current diff.');
        }
        if ($this->pullFileCount($this->read($root, $token), $number, $head, $base) !== $count) {
            throw new \RuntimeException('The pull request changed.');
        }
        if (!$this->isEnabled($userId, $repository)) return null;

        // Different comments, processes, and accounts cannot post twice for
        // the same PR revision. An uncertain result is never retried here.
        $revision = self::revisionKey($repository, $number, $head, $base);
        try {
            $insert = $this->database->prepare('INSERT INTO automatic_inline_attempts (revision_key, user_id, repository, pull_number, status) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$revision, $userId, strtolower($repository), $number, 'uncertain']);
        } catch (PDOException $e) {
            $existing = $this->database->prepare('SELECT status FROM automatic_inline_attempts WHERE revision_key = ?');
            $existing->execute([$revision]);
            if ($existing->fetchColumn() !== false) throw new \RuntimeException('This revision was already attempted. Check GitHub.', 0, $e);
            throw $e;
        }

        // Recheck the live account and repository grant as well as the opt-in
        // after reservation, immediately before the mutating request.
        $this->assertAuthorized($token, $userId, $repository, $parts[1]);
        $stillConnected = $this->credentialIsCurrent !== null
            ? ($this->credentialIsCurrent)($token)
            : (($currentToken = GitHubTokenStore::getToken()) !== null && hash_equals($currentToken, $token));
        if (!$stillConnected) throw new \RuntimeException('GitHub disconnected before posting.');
        if (!$this->isEnabled($userId, $repository)) return null;
        if ($this->pullFileCount($this->read($root, $token), $number, $head, $base) !== $count) {
            throw new \RuntimeException('The pull request changed.');
        }
        try {
            $response = $this->http->request('POST', self::ROOT.$root.'/comments', [
                'headers' => $this->headers($token), 'max_redirects' => 0, 'timeout' => 15, 'max_duration' => 20,
                'json' => ['body' => "Automated ProofPatcher suggestion (please review):\n\n".ReviewCommentFormatter::format(
                    ['status' => $eligible['status'], 'comment' => $eligible['comment']],
                    is_string($verdict) ? $verdict : null,
                    $eligible['suggestion']),
                    'commit_id' => $head, 'path' => $eligible['path'], 'line' => $eligible['line'], 'side' => 'RIGHT'],
            ]);
            if ($response->getStatusCode() !== 201) throw new \RuntimeException('GitHub did not confirm the comment.');
            $raw = $response->getContent(false);
            if (strlen($raw) > 131072) throw new \RuntimeException('Invalid confirmation.');
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_int($data['id'] ?? null) || $data['id'] < 1) throw new \RuntimeException('Invalid confirmation.');
            $update = $this->database->prepare('UPDATE automatic_inline_attempts SET status = ?, comment_id = ? WHERE revision_key = ?');
            $update->execute(['posted', $data['id'], $revision]);
            return ['id' => $data['id'], 'url' => 'https://github.com/'.$repository.'/pull/'.$number.'#discussion_r'.$data['id']];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Comment outcome is unknown. Check GitHub before retrying.', 0, $e);
        }
    }

    /**
     * Returns a postable suggestion or null. Code runs inside a fenced block,
     * so lone angle brackets are fine, but tag opens, pings, links, fence
     * breaks, and control bytes disqualify it; the comment still posts alone.
     */
    private static function sanitizeSuggestion(mixed $suggestion): ?string
    {
        if (!is_string($suggestion)) {
            return null;
        }
        $code = trim($suggestion);
        if ($code === '' || strlen($code) > 1000
            || preg_match('~@|https?://|www\.|```|<[a-zA-Z/!]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~i', $code)) {
            return null;
        }

        return $code;
    }

    private function isEnabled(int $userId, string $repository): bool
    {
        // Automatic comments require both independent permissions. Turning off
        // reviews also stops a model request already in flight from posting.
        $query = $this->database->prepare('SELECT 1 FROM automatic_inline_settings AS comments JOIN repository_auto_review_settings AS reviews ON reviews.repository = comments.repository COLLATE NOCASE WHERE comments.user_id = ? AND comments.repository = ? COLLATE NOCASE AND comments.enabled = 1 AND reviews.enabled = 1 LIMIT 1');
        $query->execute([$userId, $repository]);
        return $query->fetchColumn() !== false;
    }

    private function assertAuthorized(string $token, int $userId, string $repository, string $owner): void
    {
        $client = new GitHubAppClient($this->http);
        $current = $client->getCurrentUser($token);
        $status = [];
        $repos = $client->listSelectedRepositories($token, $status);
        if (($current['id'] ?? null) !== $userId || !is_string($current['login'] ?? null)
            || strcasecmp($current['login'], $owner) !== 0
            || ($status['truncated'] ?? true) || !empty($status['errors'])
            || !array_filter($repos, static fn (array $repo): bool =>
                strcasecmp((string) ($repo['fullName'] ?? ''), $repository) === 0 && ($repo['isPrivate'] ?? true) === false)) {
            throw new \RuntimeException('GitHub identity or public repository access could not be verified.');
        }
    }

    private function pullFileCount(array $pull, int $number, string $head, string $base): int
    {
        if (($pull['number'] ?? null) !== $number || ($pull['state'] ?? null) !== 'open'
            || ($pull['head']['sha'] ?? null) !== $head || ($pull['base']['sha'] ?? null) !== $base
            || ($pull['base']['repo']['private'] ?? null) !== false
            || ($pull['base']['repo']['visibility'] ?? null) !== 'public'
            || !is_int($pull['changed_files'] ?? null) || $pull['changed_files'] < 1) {
            throw new \RuntimeException('The pull request changed, is not public, or is not open.');
        }
        return $pull['changed_files'];
    }

    private function read(string $path, string $token): array
    {
        $response = $this->http->request('GET', self::ROOT.$path, [
            'headers' => $this->headers($token), 'max_redirects' => 0, 'timeout' => 15, 'max_duration' => 20,
        ]);
        if ($response->getStatusCode() !== 200) throw new \RuntimeException('GitHub could not verify the pull request.');
        $raw = $response->getContent(false);
        if (strlen($raw) > 1048576) throw new \RuntimeException('GitHub response was too large.');
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException('GitHub returned invalid data.');
        return $value;
    }

    private function headers(string $token): array
    {
        return ['Accept' => 'application/vnd.github+json', 'Authorization' => 'Bearer '.$token,
            'User-Agent' => 'ProofPatcher', 'X-GitHub-Api-Version' => '2022-11-28'];
    }
}
