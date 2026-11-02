<?php

declare(strict_types=1);

namespace ProofPatch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PrivatePullSession;
use ProofPatch\Http\Controllers\IssueWorkbenchController;
use ProofPatch\Models\WorkbenchTask;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Response;

/** Recheck the token identity and installation grant on each workbench request. */
final class AuthorizeGithubTasks
{
    public function __construct(private ?GitHubAppClient $client = null) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        // The terminal and the web app share one sign-in: a token saved by
        // `github:connect` is adopted into an empty web session here, then
        // verified with GitHub below exactly like a web sign-in. Nothing is
        // trusted until that live check passes.
        $auth = $session->get('github_auth');
        if (!is_array($auth) || !is_string($auth['token'] ?? null) || trim($auth['token']) === '') {
            try {
                $stored = \ProofPatch\GitHub\GitHubTokenStore::get();
                if (is_array($stored) && is_string($stored['token'] ?? null) && trim($stored['token']) !== '') {
                    $auth = [
                        'token' => trim($stored['token']),
                        'user' => $stored['user'] ?? null,
                        'repositories' => $stored['repositories'] ?? [],
                    ];
                    $session->put('github_auth', $auth);
                }
            } catch (\Throwable) {
            }
        }
        $previousUserId = is_int($auth['user']['id'] ?? null) ? $auth['user']['id'] : null;
        $token = is_array($auth) && is_string($auth['token'] ?? null) ? trim($auth['token']) : '';
        $userId = null;
        $repositories = [];
        if ($token !== '') {
            $tokenHash = hash('sha256', $token);
            $canUseRecentSessionCheck = $this->client === null
                && getenv('APP_ENV') !== 'testing'
                && $request->isMethod('GET')
                && is_array($auth)
                && ($auth['verified_token_sha256'] ?? null) === $tokenHash
                && is_int($auth['user']['id'] ?? null)
                && $auth['user']['id'] > 0
                && is_array($auth['repositories'] ?? null)
                && is_int($auth['verified_at'] ?? null)
                && $auth['verified_at'] >= time() - 60;

            if ($canUseRecentSessionCheck) {
                $userId = $auth['user']['id'];
                $repositories = $auth['repositories'];
            } else {
                try {
                    $client = $this->client ?? new GitHubAppClient(HttpClient::create());
                    $user = $client->getCurrentUser($token);
                    if (!is_int($user['id'] ?? null) || $user['id'] <= 0) {
                        throw new \RuntimeException('GitHub did not return a valid user identity.');
                    }
                    $status = [];
                    $repositories = $client->listSelectedRepositories($token, $status);
                    if (($status['truncated'] ?? true) || !empty($status['errors'])) {
                        throw new \RuntimeException('GitHub repository permissions could not be verified.');
                    }
                    $userId = $user['id'];
                    if (($auth['user']['id'] ?? null) !== $userId) {
                        if ($previousUserId !== null && \Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                            \ProofPatch\Models\AutomaticInlineSetting::revokeAllForUser($previousUserId);
                        }
                        $session->forget(array_merge(IssueWorkbenchController::CASE_SESSION_KEYS, [
                            'selected_github_repo', 'github_repo_issues', 'github_repo_pulls', 'notifications',
                        ]));
                    }
                    if (\Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                        \ProofPatch\Models\AutomaticInlineSetting::retainOnlyAuthorizedPublic($userId, $repositories);
                    }
                    $auth['user'] = $user;
                    $auth['repositories'] = $repositories;
                    $auth['verified_token_sha256'] = $tokenHash;
                    $auth['verified_at'] = time();
                    $session->put('github_auth', $auth);
                } catch (\Throwable) {
                    if ($previousUserId !== null && \Illuminate\Support\Facades\Schema::hasTable('automatic_inline_settings')) {
                        \ProofPatch\Models\AutomaticInlineSetting::revokeAllForUser($previousUserId);
                    }
                    $userId = null;
                    $repositories = [];
                    $auth['user'] = null;
                    $auth['repositories'] = [];
                    unset($auth['verified_token_sha256'], $auth['verified_at']);
                    $session->put('github_auth', $auth);
                    $session->forget(['selected_github_repo', 'github_repo_issues', 'github_repo_pulls', 'notifications']);
                }
            }
        }

        $allowed = [];
        foreach ($repositories as $repository) {
            if (is_string($repository['fullName'] ?? null)) {
                $allowed[strtolower($repository['fullName'])] = true;
            }
        }
        $request->attributes->set('proofpatch_github_access', ['userId' => $userId, 'repositories' => $allowed]);

        $pull = $session->get('pullRequest');
        if (is_array($pull) && ($pull['isPrivate'] ?? false) === true && !PrivatePullSession::allows($request, $pull)) {
            PrivatePullSession::clear($request);
        }

        $notifications = $session->get('notifications', []);
        if (is_array($notifications)) {
            $notifications = array_values(array_filter($notifications, static function ($entry) use ($userId, $allowed): bool {
                if (!is_array($entry)) {
                    return false;
                }
                if (($entry['isLocal'] ?? false) === true) {
                    return true;
                }
                $owner = $entry['githubUserId'] ?? null;
                $repo = $entry['githubRepository'] ?? null;
                return $userId !== null && $owner === $userId
                    && ($repo === null || (is_string($repo) && isset($allowed[strtolower($repo)])));
            }));
            $session->put('notifications', $notifications);
        }

        $selected = $session->get('selected_github_repo');
        if (is_string($selected) && trim($selected) !== '' && !isset($allowed[strtolower($selected)])) {
            $session->forget(['selected_github_repo', 'github_repo_issues', 'github_repo_pulls', 'notifications']);
            $selected = null;
        }
        // Repository selection is shared too: when this session has none,
        // adopt the terminal's selection if it is inside the current grant.
        if (($selected === null || (is_string($selected) && trim($selected) === '')) && $userId !== null) {
            try {
                $shared = \ProofPatch\Support\KernelCli::getSelectedRepo();
                if ($shared !== null && isset($allowed[strtolower($shared)])) {
                    $session->put('selected_github_repo', $shared);
                }
            } catch (\Throwable) {
            }
        }

        $taskId = $session->get('issue_task_id');
        $task = $taskId !== null ? WorkbenchTask::find($taskId) : null;
        $context = $session->get('issue_context');
        if ($task === null && is_array($context) && empty($context['isSynthetic'])) {
            $session->forget(IssueWorkbenchController::CASE_SESSION_KEYS);
            if ($this->isIssueAction($request)) {
                return redirect('/issues')->with('error', 'Open an authorized task again.');
            }
        }
        if ($task !== null && !$this->allows($task, $userId, $allowed)) {
            $session->forget(IssueWorkbenchController::CASE_SESSION_KEYS);
            if ($this->isIssueAction($request)) {
                return redirect('/issues')->with('error', 'Connect the GitHub account that owns this task and grant access to its repository.');
            }
        }

        if ($this->isIssueAction($request)) {
            $path = $request->path();
            if (in_array($path, ['issues/select-repository', 'issues/load-issue', 'issues/load-dedicated'], true)) {
                $repo = $path === 'issues/load-dedicated'
                    ? \ProofPatch\GitHub\DedicatedTestRepo::name()
                    : (string) $request->input('repository', '');
                if ($userId === null || !is_string($repo) || !isset($allowed[strtolower($repo)])) {
                    return redirect('/issues')->with('error', 'Connect GitHub and grant access to this repository.');
                }
            }
            if ($path === 'issues/switch-task') {
                $target = WorkbenchTask::find($request->input('task_id'));
                if ($target !== null && !$this->allows($target, $userId, $allowed)) {
                    return redirect('/issues')->with('error', 'Connect the GitHub account that owns this task and grant access to its repository.');
                }
            }
        }

        $response = $next($request);
        if ($userId !== null) {
            // Authenticated pages may contain private task or pull-request data.
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        return $response;
    }

    /** @param array<string, true> $allowed */
    private function allows(WorkbenchTask $task, ?int $userId, array $allowed): bool
    {
        if ($task->is_synthetic) {
            return true;
        }

        if ($userId === null || $task->github_user_id !== $userId) {
            return false;
        }

        return isset($allowed[strtolower($task->repository)]);
    }

    private function isIssueAction(Request $request): bool
    {
        return !$request->isMethod('GET') && str_starts_with($request->path(), 'issues/');
    }
}
