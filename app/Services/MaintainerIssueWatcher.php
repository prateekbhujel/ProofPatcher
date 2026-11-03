<?php

declare(strict_types=1);

namespace ProofPatch\Services;

use Illuminate\Support\Facades\Schema;
use ProofPatch\Application\Workbench\IssueMaintainerTriage;
use ProofPatch\GitHub\BotInstallationTokenProvider;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Models\AutomaticInlineSetting;
use ProofPatch\Models\AutopilotIssueAction;
use ProofPatch\Models\MaintainerAutopilotSetting;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Watches open issues on autopilot-enabled public repositories and records
 * one maintainer action per issue revision: ask for info, point at a
 * duplicate or existing fix, or mark the dedicated demo fix as eligible.
 * Actions are recorded locally first; a GitHub comment is attempted only
 * when automatic commenting is opted in and the bot is configured. It never
 * posts as the user and never re-acts on the same issue revision.
 */
final class MaintainerIssueWatcher
{
    private const COMMENTABLE = ['needs_info', 'duplicate', 'has_open_pr', 'already_fixed', 'fix_eligible'];

    public function __construct(
        private GitHubAppClient $github,
        private ?BotInstallationTokenProvider $botTokens = null,
        private ?HttpClientInterface $http = null,
    ) {
        $this->http ??= HttpClient::create();
    }

    /**
     * @param array<string, array<string, mixed>> $authorizedRepos lower-case full name => repository descriptor
     * @param list<string> $enabledRepos
     * @return array{issuesInspected: int, actions: list<array<string, mixed>>, errors: list<string>}
     */
    public function poll(string $token, int $userId, array $authorizedRepos, array $enabledRepos, ?string $onlyRepo = null): array
    {
        $summary = ['issuesInspected' => 0, 'actions' => [], 'errors' => []];
        if (!Schema::hasTable('autopilot_issue_actions')) {
            $summary['errors'][] = 'Issue watching is unavailable until the database migrations are applied.';
            return $summary;
        }
        $demoRepo = DedicatedTestRepo::name();

        foreach ($enabledRepos as $repoName) {
            if ($onlyRepo !== null && strcasecmp($repoName, $onlyRepo) !== 0) {
                continue;
            }
            $lower = strtolower($repoName);
            if (!isset($authorizedRepos[$lower]) || ($authorizedRepos[$lower]['isPrivate'] ?? true) !== false) {
                continue;
            }
            try {
                $issueStatus = [];
                $issues = $this->github->listOpenIssues($token, $repoName, $issueStatus);
                if (($issueStatus['truncated'] ?? true) || !empty($issueStatus['errors'])) {
                    $summary['errors'][] = "Issue list for {$repoName} was incomplete; no issue actions were taken.";
                    continue;
                }
                $pullStatus = [];
                $openPulls = $this->github->listOpenPullRequests($token, $repoName, $pullStatus);
                $closedPulls = $this->github->listOpenPullRequests($token, $repoName, $pullStatus, 'closed');
                if (($pullStatus['truncated'] ?? true) || !empty($pullStatus['errors'])) {
                    $summary['errors'][] = "Pull list for {$repoName} was incomplete; no issue actions were taken.";
                    continue;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = "Issue watch failed for {$repoName}: ".$e->getMessage();
                continue;
            }
            $pulls = array_merge($openPulls, $closedPulls);
            $flags = MaintainerAutopilotSetting::flagsFor($repoName);

            foreach ($issues as $issue) {
                $summary['issuesInspected']++;
                $number = (int) ($issue['number'] ?? 0);
                if ($number < 1) {
                    continue;
                }
                $revision = hash('sha256', ((string) ($issue['title'] ?? ''))."\n".((string) ($issue['body'] ?? ''))."\n".((string) ($issue['updatedAt'] ?? '')));
                $already = AutopilotIssueAction::whereRaw('LOWER(repository) = ?', [$lower])
                    ->where('issue_number', $number)->where('issue_revision', $revision)->exists();
                if ($already) {
                    continue;
                }
                $others = array_values(array_filter($issues, static fn (array $o): bool => (int) ($o['number'] ?? 0) !== $number));
                $decision = IssueMaintainerTriage::decide(
                    $issue + ['repository' => $repoName], $others, $pulls, $flags['fixIssues'], $demoRepo
                );
                $published = false;
                $detail = $decision['reason'];
                if (in_array($decision['action'], self::COMMENTABLE, true)
                    && AutomaticInlineSetting::enabled($userId, $repoName)) {
                    $published = $this->postComment($token, $repoName, $number, self::commentBody($decision));
                    if (!$published) {
                        $detail .= ' GitHub comment was not posted (bot unavailable or not permitted); this action is recorded locally only.';
                    }
                }
                AutopilotIssueAction::create([
                    'repository' => $repoName,
                    'issue_number' => $number,
                    'issue_revision' => $revision,
                    'action' => $decision['action'],
                    'detail' => $detail,
                    'published' => $published,
                ]);
                $summary['actions'][] = [
                    'repository' => $repoName, 'number' => $number,
                    'action' => $decision['action'], 'published' => $published,
                ];
            }
        }
        return $summary;
    }

    /** @param array{action: string, reason: string, questions: list<string>, duplicateOf: ?int, pullNumber: ?int} $decision */
    private static function commentBody(array $decision): string
    {
        $lines = ['**ProofPatcher maintainer check**', '', $decision['reason']];
        if ($decision['action'] === 'needs_info' && $decision['questions'] !== []) {
            $lines[] = '';
            $lines[] = 'Could you add:';
            foreach ($decision['questions'] as $question) {
                $lines[] = '- '.$question;
            }
        }
        $lines[] = '';
        $lines[] = 'This is an automated suggestion from a local ProofPatcher autopilot. A maintainer makes the final call.';
        return implode("\n", $lines);
    }

    private function postComment(string $userToken, string $repository, int $issueNumber, string $body): bool
    {
        try {
            $botToken = ($this->botTokens ?? new BotInstallationTokenProvider($this->http))->forRepository($userToken, $repository);
            $response = $this->http->request('POST', "https://api.github.com/repos/{$repository}/issues/{$issueNumber}/comments", [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer '.$botToken,
                    'User-Agent' => 'ProofPatcher',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'json' => ['body' => $body],
                'timeout' => 15,
                'max_redirects' => 0,
            ]);
            return $response->getStatusCode() === 201;
        } catch (\Throwable) {
            return false;
        }
    }
}
