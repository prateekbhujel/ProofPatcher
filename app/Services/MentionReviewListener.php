<?php

declare(strict_types=1);

namespace ProofPatch\Services;

use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\AutomaticInlineCommentPublisher;
use ProofPatch\GitHub\MentionReviewAuthorization;
use ProofPatch\Models\AutoReviewResult;
use Symfony\Component\HttpClient\HttpClient;

/** Explicitly invoked local listener; publishes only with the separate opt-in flag. */
final readonly class MentionReviewListener
{
    public function __construct(private GitHubAppClient $github, private AutoReviewService $reviews, private ?AutomaticInlineCommentPublisher $publisher = null) {}

    /** Keep a short overlap for comments arriving while GitHub responds. */
    public static function advanceCursor(string $previous, int $pollStarted): string
    {
        $next = gmdate('Y-m-d\TH:i:s\Z', $pollStarted - 30);
        return $next > $previous ? $next : $previous;
    }

    /** @return list<int> Existing recent comments are excluded before the listener announces readiness. */
    public function baseline(string $token, string $repository, string $since): array
    {
        $this->authorizedUser($token, $repository);
        return array_map(static fn (array $comment): int => $comment['id'], $this->github->listRecentIssueComments($token, $repository, $since));
    }

    /** @param list<int> $baseline @return list<array{number:int,status:string,head?:string,publication?:string,url?:string}> */
    public function poll(string $token, string $repository, string $since, ?string $startedAt = null, array $baseline = [], bool $publish = false): array
    {
        $userId = $this->authorizedUser($token, $repository);
        $startedAt ??= $since;
        $results = [];
        foreach ($this->github->listRecentIssueComments($token, $repository, $since) as $comment) {
            if (in_array($comment['id'], $baseline, true)) continue;
            $url = $comment['issue_url'] ?? '';
            $prefix = 'https://api.github.com/repos/'.$repository.'/issues/';
            if (!is_string($url) || strncasecmp($url, $prefix, strlen($prefix)) !== 0) continue;
            $part = substr($url, strlen($prefix));
            if (!preg_match('/\A[1-9][0-9]{0,8}\z/', $part)) continue;
            $number = (int) $part;
            $created = $comment['created_at'] ?? null;
            if (!is_string($created) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $created)
                || $created < $startedAt || !MentionReviewAuthorization::isExactRequest($comment, $repository, $number, $created)) continue;
            $id = $comment['id'];
            $authorId = $comment['user']['id'];
            $login = $comment['user']['login'];

            // A claim is durable even on failure: never retry an uncertain paid request.
            $claimed = DB::table('mention_review_attempts')->insertOrIgnore([
                'repository' => strtolower($repository), 'comment_id' => $id, 'pull_number' => $number,
                'status' => 'claimed', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($claimed !== 1) continue;
            $head = null;
            $publication = null;
            $commentUrl = null;
            try {
                // A regular issue is not a pull request: the API call fails closed.
                if (!$this->github->canMaintainRepository($token, $repository, $login, $authorId)) {
                    throw new \RuntimeException('Maintainer permission could not be verified.');
                }
                $pull = $this->github->fetchPullRequestPatch($token, $repository, $number);
                if (($pull['isPrivate'] ?? true) !== false || ($pull['state'] ?? '') !== 'open') {
                    throw new \RuntimeException('Only open public pull requests can be reviewed.');
                }
                $head = $pull['headSha'];
                $authorization = new MentionReviewAuthorization($this->github, $token, $userId, $repository, $number, $id, $authorId, $login, $created);
                $outcome = $this->reviews->reviewPullRevision($repository, $number, $head, $pull['baseSha'], $pull['title'], $authorization);
                $result = (string) ($outcome['status'] ?? 'failed');
                if ($publish && in_array($result, ['completed', 'skipped_concurrent_worker'], true)) {
                    $record = AutoReviewResult::findForRevision($repository, $number, $head, $pull['baseSha']);
                    if ($record !== null && $record->status === 'completed' && $record->is_public === true && is_array($record->file_findings)) {
                        try {
                            $publisher = $this->publisher ?? new AutomaticInlineCommentPublisher(DB::connection()->getPdo(), HttpClient::create());
                            $posted = $publisher->publishRequested($token, $userId,
                                ['repository' => $repository, 'number' => $number, 'headSha' => $head, 'baseSha' => $pull['baseSha']],
                                $record->file_findings, is_string($record->recommendation) ? $record->recommendation : null, $authorization);
                            $publication = $posted === null ? 'not_posted' : 'posted';
                            if ($posted !== null) {
                                $commentUrl = $posted['url'];
                                // The attempt ledger remains authoritative if this update fails.
                                try { $record->update(['published_status' => $posted['publishedStatus']]); } catch (\Throwable) {}
                            }
                        } catch (\Throwable $e) {
                            $publication = str_contains($e->getMessage(), 'outcome is unknown') ? 'unknown' : 'failed';
                        }
                    } else {
                        $publication = 'not_posted';
                    }
                }
            } catch (\Throwable) {
                $result = 'rejected';
            }
            DB::table('mention_review_attempts')->where('repository', strtolower($repository))->where('comment_id', $id)
                ->update(['status' => $result, 'updated_at' => now()]);
            $row = ['number' => $number, 'status' => $result];
            if (is_string($head)) $row['head'] = $head;
            if (is_string($publication)) $row['publication'] = $publication;
            if (is_string($commentUrl)) $row['url'] = $commentUrl;
            $results[] = $row;
        }
        return $results;
    }

    private function authorizedUser(string $token, string $repository): int
    {
        $user = $this->github->getCurrentUser($token);
        $userId = $user['id'] ?? null;
        $status = [];
        $repos = $this->github->listSelectedRepositories($token, $status);
        $allowed = array_filter($repos, static fn (array $repo): bool =>
            strcasecmp((string) ($repo['fullName'] ?? ''), $repository) === 0 && ($repo['isPrivate'] ?? null) === false);
        if (!is_int($userId) || $userId < 1 || ($status['truncated'] ?? true) || !empty($status['errors']) || $allowed === []) {
            throw new \RuntimeException('Public repository access could not be verified.');
        }
        return $userId;
    }
}
