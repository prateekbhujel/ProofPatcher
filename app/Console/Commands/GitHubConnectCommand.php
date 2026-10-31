<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\GitHubDeviceFlow;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Support\KernelCli;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs the GitHub device authorization flow in the terminal and stores the
 * resulting token encrypted via GitHubTokenStore — the same store the web
 * UI and the auto-review worker read. Never prints the token.
 */
final class GitHubConnectCommand extends Command
{
    protected $signature = 'github:connect
                            {--client-id= : GitHub App Client ID (falls back to PROOFPATCH_GITHUB_CLIENT_ID)}
                            {--timeout=300 : Maximum seconds to wait for authorization (0 polls once)}
                            {--interval= : Override the poll interval in seconds (1-120)}
                            {--force : Replace an existing connection}';

    protected $description = 'Connect GitHub via device flow and store the token for CLI and web';

    private HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        parent::__construct();
        $this->http = $http ?? HttpClient::create();
    }

    public function handle(): int
    {
        $clientId = trim((string) ($this->option('client-id') ?: getenv('PROOFPATCH_GITHUB_CLIENT_ID') ?: getenv('GITHUB_APP_CLIENT_ID') ?: ''));
        if ($clientId === '') {
            $this->error('A GitHub App Client ID is required. Set PROOFPATCH_GITHUB_CLIENT_ID or pass --client-id.');
            return self::FAILURE;
        }

        if (!(bool) $this->option('force')) {
            $existing = KernelCli::connectState();
            if ($existing['connected']) {
                $login = $existing['user']['login'] ?? '?';
                $this->info("Already connected as @{$login}. Re-run with --force to replace the connection.");
                return self::SUCCESS;
            }
        }

        $timeout = max(0, min(3600, (int) $this->option('timeout')));
        $intervalOverride = $this->option('interval');
        $intervalOverride = $intervalOverride === null ? null : max(1, min(120, (int) $intervalOverride));

        $flow = new GitHubDeviceFlow($this->http);
        try {
            $init = $flow->initiate($clientId);
        } catch (\Throwable $e) {
            $this->error('Device flow failed to start: ' . $e->getMessage());
            return self::FAILURE;
        }

        $interval = $intervalOverride ?? max(1, min(120, (int) ($init['interval'] ?? 5)));
        $deadline = time() + $timeout;

        $this->line('Open ' . $init['verificationUri'] . ' and enter code: ' . $init['userCode']);
        $this->line('Waiting for authorization' . ($timeout > 0 ? " (up to {$timeout}s)" : '') . ' — press Ctrl+C to abort.');

        while (true) {
            try {
                $result = $flow->poll($clientId, $init['deviceCode']);
            } catch (\Throwable $e) {
                $this->error('Authorization poll failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            $status = $result['status'] ?? 'error';
            if ($status === 'authorized' && isset($result['accessToken'])) {
                return $this->finishConnection((string) $result['accessToken']);
            }
            if ($status === 'denied') {
                $this->error((string) ($result['error'] ?? 'Authorization was declined on GitHub.'));
                return self::FAILURE;
            }
            if ($status === 'expired') {
                $this->error((string) ($result['error'] ?? 'The verification code expired. Run github:connect again.'));
                return self::FAILURE;
            }
            if ($status === 'error') {
                $this->error('GitHub authorization error: ' . (string) ($result['error'] ?? 'unknown error'));
                return self::FAILURE;
            }
            if ($status === 'slow_down') {
                $suggested = (int) ($result['interval'] ?? 0);
                $interval = max($interval + 5, $suggested, 1);
            }

            if (time() >= $deadline) {
                $this->error('Authorization timed out. Run github:connect again for a fresh code.');
                return self::FAILURE;
            }
            sleep($interval);
        }
    }

    private function finishConnection(string $token): int
    {
        $token = trim($token);
        if ($token === '') {
            $this->error('GitHub returned an empty access token.');
            return self::FAILURE;
        }

        $client = new GitHubAppClient($this->http);
        try {
            $user = $client->getCurrentUser($token);
            if (!is_int($user['id'] ?? null) || $user['id'] <= 0) {
                throw new \RuntimeException('GitHub did not return a valid user identity.');
            }
            $reposStatus = [];
            $repos = $client->listSelectedRepositories($token, $reposStatus);
        } catch (\Throwable $e) {
            $this->error('Connected, but the GitHub identity check failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            GitHubTokenStore::save($token, $user, $repos, $reposStatus);
        } catch (\Throwable $e) {
            $this->error('Failed to store the GitHub token: ' . $e->getMessage());
            return self::FAILURE;
        }

        KernelCli::clearSelectedRepo();

        $count = count($repos);
        $this->info("Connected as @{$user['login']} — {$count} " . ($count === 1 ? 'repo' : 'repos') . ' authorized.');
        if (($reposStatus['truncated'] ?? false) || !empty($reposStatus['errors'] ?? [])) {
            $this->warn('Repository list is incomplete; some installations failed to load.');
        }
        $this->line('Next: php artisan kernel:select-repo');

        return self::SUCCESS;
    }
}
