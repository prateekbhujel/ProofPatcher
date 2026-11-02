<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/** A scoped, live-checked maintainer request. Comment text never reaches the model. */
final readonly class MentionReviewAuthorization
{
    public function __construct(
        private GitHubAppClient $github,
        private string $token,
        private int $connectedUserId,
        private string $repository,
        private int $pullNumber,
        private int $commentId,
        private int $authorId,
        private string $authorLogin,
        private string $createdAt,
    ) {}

    public function allowsForUser(string $token, int $userId, string $repository, int $number, string $head, string $base): bool
    {
        return $userId === $this->connectedUserId && hash_equals($this->token, $token)
            && $this->allows($repository, $number, $head, $base);
    }

    /** Fail closed if access, authorship, comment or target changes before a model call. */
    public function allows(string $repository, int $number, string $head, string $base): bool
    {
        if (strcasecmp($repository, $this->repository) !== 0 || $number !== $this->pullNumber) return false;
        try {
            $stored = GitHubTokenStore::getToken();
            if (!is_string($stored) || $stored === '' || !hash_equals($this->token, $stored)) return false;
            $user = $this->github->getCurrentUser($this->token);
            $status = [];
            $repos = $this->github->listSelectedRepositories($this->token, $status);
            $allowed = array_filter($repos, fn (array $repo): bool =>
                strcasecmp((string) ($repo['fullName'] ?? ''), $this->repository) === 0
                && ($repo['isPrivate'] ?? null) === false);
            if (($user['id'] ?? null) !== $this->connectedUserId || ($status['truncated'] ?? true)
                || !empty($status['errors']) || $allowed === []) return false;

            $comment = $this->github->getIssueComment($this->token, $this->repository, $this->commentId);
            if (!self::isExactRequest($comment, $this->repository, $this->pullNumber, $this->createdAt)
                || ($comment['id'] ?? null) !== $this->commentId
                || ($comment['user']['id'] ?? null) !== $this->authorId
                || strcasecmp((string) ($comment['user']['login'] ?? ''), $this->authorLogin) !== 0
                || !$this->github->canMaintainRepository($this->token, $this->repository, $this->authorLogin, $this->authorId)) {
                return false;
            }
            $this->github->assertPublicPullRevision($this->token, $this->repository, $number, $head, $base);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isExactRequest(array $comment, string $repository, int $number, string $createdAt): bool
    {
        return in_array($comment['body'] ?? null, ['@proofpatcher review', '@pp review'], true)
            && ($comment['user']['type'] ?? null) === 'User'
            && is_int($comment['user']['id'] ?? null) && $comment['user']['id'] > 0
            && is_string($comment['user']['login'] ?? null)
            && is_string($comment['created_at'] ?? null) && ($comment['created_at'] ?? null) === $createdAt
            && ($comment['updated_at'] ?? null) === $createdAt
            && strcasecmp((string) ($comment['issue_url'] ?? ''), 'https://api.github.com/repos/'.$repository.'/issues/'.$number) === 0;
    }
}
