<?php

declare(strict_types=1);

namespace ProofPatch\Services;

use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Models\RepositoryAutoReviewSetting;

/** Records only identifiers for human replies to our posted bot comments. */
final readonly class ReviewFollowupTracker
{
    public function __construct(private GitHubAppClient $github) {}

    /** @return list<string> Public GitHub links needing attention; no text is stored or sent to a model. */
    public function detect(string $token, int $userId, string $repository, int $number, string $head, string $base): array
    {
        $parents = DB::table('automatic_inline_attempts')->where('user_id', $userId)
            ->whereRaw('lower(repository) = ?', [strtolower($repository)])
            ->where('pull_number', $number)->where('status', 'posted')->whereNotNull('comment_id')
            ->pluck('comment_id')->map(static fn ($id): int => (int) $id)->all();
        if ($parents === [] || !RepositoryAutoReviewSetting::isRepoEnabled($repository)) return [];

        $replies = $this->github->listHumanReviewReplies($token, $repository, $number, $head, $base);
        $replies = array_values(array_filter($replies, static fn (array $reply): bool => in_array($reply['parentId'], $parents, true)));
        if ($replies === []) return [];

        // Recheck the active identity and full installation grant before persisting.
        $identity = $this->github->getCurrentUser($token);
        $status = [];
        $repos = $this->github->listSelectedRepositories($token, $status);
        $authorized = array_filter($repos, static fn (array $repo): bool =>
            strcasecmp((string) ($repo['fullName'] ?? ''), $repository) === 0 && ($repo['isPrivate'] ?? null) === false);
        if (($identity['id'] ?? null) !== $userId || ($status['truncated'] ?? true) || !empty($status['errors'])
            || $authorized === [] || !RepositoryAutoReviewSetting::isRepoEnabled($repository)) {
            throw new \RuntimeException('Follow-up access could not be verified.');
        }
        $this->github->assertPublicPullRevision($token, $repository, $number, $head, $base);
        return DB::transaction(static function () use ($replies, $userId, $repository, $number): array {
            if (!RepositoryAutoReviewSetting::isRepoEnabled($repository)) return [];
            $links = [];
            foreach ($replies as $reply) {
                DB::table('review_followups')->insertOrIgnore([
                    'user_id' => $userId, 'repository' => strtolower($repository), 'pull_number' => $number,
                    'comment_id' => $reply['id'], 'bot_comment_id' => $reply['parentId'],
                ]);
                $links[] = "https://github.com/{$repository}/pull/{$number}#discussion_r{$reply['id']}";
            }
            return $links;
        });
    }
}
