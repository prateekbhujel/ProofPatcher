<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Hari\Hari;
use Hari\Registry;
use Illuminate\Console\Command;
use ProofPatch\Agent\ProviderConfigStore;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Support\KernelCli;
use ProofPatch\Support\WorkerStatusService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Terminal twin of the Kernel home: one read-only dashboard for the whole
 * pipeline. Never triggers model calls, GitHub writes, or worker runs, and
 * never prints the stored token. Statistics stay scoped to repositories in
 * the current token grant.
 */
final class KernelStatusCommand extends Command
{
    protected $signature = 'kernel:status
                            {--json : Output machine-readable JSON instead of the dashboard}';

    protected $description = 'Show the ProofPatcher Kernel pipeline status in the terminal';

    public function handle(): int
    {
        $state = KernelCli::connectState();
        $verification = $state['connected'] ? $this->verifyConnection() : 'none';
        $selected = $state['connected'] ? KernelCli::getSelectedRepo() : null;
        $authorized = [];
        $selectedPrivate = false;
        foreach ($state['repos'] as $repo) {
            $authorized[strtolower($repo['fullName'])] = $repo['fullName'];
            if ($selected !== null && strcasecmp($repo['fullName'], $selected) === 0 && ($repo['isPrivate'] ?? null) === true) {
                $selectedPrivate = true;
            }
        }

        $provider = $this->provider();
        $budget = $this->budget();
        $worker = $this->worker();
        $stats = $this->stats(array_keys($authorized));
        $enabled = $this->enabledRepositories();
        $next = $this->nextStep($state['connected'], $authorized, $selected, $enabled, (bool) ($worker['isLive'] ?? false), $budget === null || (bool) ($budget['canRequest'] ?? false), $selectedPrivate, $verification);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'kernel' => KernelCli::VERSION,
                'connected' => $state['connected'],
                'verified' => $verification === 'verified' ? true : ($verification === 'rejected' ? false : null),
                'user' => $state['user'],
                'reposCount' => count($authorized),
                'selectedRepo' => $selected,
                'provider' => $provider,
                'budget' => $budget,
                'worker' => $worker,
                'stats' => $stats,
                'reviewEnabled' => array_values($enabled),
                'nextStep' => $next,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('ProofPatcher Kernel ' . KernelCli::VERSION . ' — status');
        $this->line('');
        $this->line('  GitHub      ' . $this->githubLine($state, $selected, $verification));
        $this->line('  Hari        ' . $this->hariLine());
        $this->line('  Muse        ' . $provider['label'] . ' · ' . $provider['model'] . ' · ' . ($provider['keyReady'] ? 'key ready' : 'key missing'));
        $this->line('  Usage       ' . $this->budgetLine($budget));
        $this->line('  Autopilot   ' . $this->workerLine($worker));
        $this->line('  Auto-review ' . $stats['total'] . ' total · ' . $stats['completed'] . ' completed · ' . $stats['failed'] . ' failed · ' . $stats['skippedPrivate'] . ' skipped (private) · ' . $stats['skippedBudget'] . ' skipped (budget)');
        $this->line('  Reviews on  ' . ($enabled === [] ? 'none — enable one to let autopilot work' : implode(', ', array_values($enabled))));
        if (is_string($selected) && $selected !== '') {
            $flags = \ProofPatch\Models\MaintainerAutopilotSetting::flagsFor($selected);
            $on = static fn (bool $v): string => $v ? 'on' : 'off';
            $this->line('  Maintainer  fix '.$on($flags['fixIssues'])." · ready / merge / close unavailable (stored preferences may differ) for {$selected}");
        }
        $this->line('');
        $this->line('  Next: ' . $next['title']);
        $this->line('  ' . $next['command']);

        return self::SUCCESS;
    }

    private function hariLine(): string
    {
        try {
            $registry = $this->hari ?? Hari::registry();
            $skills = $registry->skills();
            $ids = array_map(static fn (array $s): string => $s['id'], $skills);
            return count($registry->abilities()) . ' abilities · ' . count($skills) . ' skills (' . implode(', ', $ids) . ') · ' . ($registry->isFrozen() ? 'frozen' : 'mutable');
        } catch (\Throwable) {
            return 'unavailable';
        }
    }

    /** @param array{connected: bool, user: ?array{login: ?string}, repos: list<array{fullName: string}>} $state */
    private function githubLine(array $state, ?string $selected, string $verification): string
    {
        if (!$state['connected']) {
            return 'not connected';
        }
        if ($verification === 'rejected') {
            return 'saved sign-in rejected by GitHub — sign in again';
        }
        $count = count($state['repos']);
        $line = 'connected as @' . ($state['user']['login'] ?? '?') . ' · ' . $count . ' ' . ($count === 1 ? 'repo' : 'repos');
        if ($selected !== null) {
            $line .= ' · selected ' . $selected;
        }
        $line .= $verification === 'verified' ? ' · verified just now' : ' · could not reach GitHub; showing saved sign-in';

        return $line;
    }

    /** @return array{label: string, model: string, keyReady: bool} */
    private function provider(): array
    {
        try {
            $status = ProviderConfigStore::status();
        } catch (\Throwable) {
            $status = [];
        }

        return [
            'label' => is_string($status['activeLabel'] ?? null) ? $status['activeLabel'] : 'unknown',
            'model' => is_string($status['activeModel'] ?? null) ? $status['activeModel'] : 'unknown',
            'keyReady' => (bool) ($status['activeKeyAvailable'] ?? false),
        ];
    }

    public function __construct(private ?Registry $hari = null, private ?HttpClientInterface $http = null)
    {
        parent::__construct();
    }

    /**
     * Live-checks the saved sign-in against GitHub. Read-only: one identity
     * request with a short timeout. Returns verified, rejected (GitHub said
     * the token is invalid), or unknown (unreachable or unexpected reply).
     *
     * @return 'verified'|'rejected'|'unknown'
     */
    private function verifyConnection(): string
    {
        try {
            $token = GitHubTokenStore::getToken();
            if ($token === null) {
                return 'unknown';
            }
            $client = $this->http ?? HttpClient::create(['timeout' => 8]);
            $response = $client->request('GET', 'https://api.github.com/user', [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'ProofPatcher',
                ],
                'timeout' => 8,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                return 'rejected';
            }
            if ($status !== 200) {
                return 'unknown';
            }
            $data = json_decode($response->getContent(false), true);
            if (!is_array($data) || !is_string($data['login'] ?? null)) {
                return 'unknown';
            }

            return 'verified';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** @return ?array{attempts: int, maxAttempts: int, reservedMicros: int, remainingMicros: int, canRequest: bool} */
    private function budget(): ?array
    {
        try {
            $status = ($this->hari ?? Hari::registry())
                ->resolve('budget.status', 1, 'kernel-cli')
                ->invoke([]);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($status)) {
            return null;
        }

        return [
            'attempts' => (int) ($status['attempts'] ?? 0),
            'maxAttempts' => (int) ($status['maxAttempts'] ?? 0),
            'reservedMicros' => (int) ($status['reservedMicros'] ?? 0),
            'remainingMicros' => (int) ($status['remainingMicros'] ?? 0),
            'canRequest' => (bool) ($status['canRequest'] ?? false),
        ];
    }

    private function budgetLine(?array $budget): string
    {
        if ($budget === null) {
            return 'unavailable';
        }

        return $budget['attempts'] . ' model requests · $' . number_format(($budget['reservedMicros'] ?? 0) / 1000000, 2) . ' tracked';
    }

    /** @return array{isLive: bool, status: string, workerPid: ?int, cycleCount: int} */
    private function worker(): array
    {
        try {
            $status = (new WorkerStatusService())->getStatus();
        } catch (\Throwable) {
            $status = [];
        }

        return [
            'isLive' => (bool) ($status['isLive'] ?? false),
            'status' => is_string($status['status'] ?? null) ? $status['status'] : 'offline',
            'workerPid' => is_int($status['workerPid'] ?? null) ? $status['workerPid'] : null,
            'cycleCount' => (int) ($status['cycleCount'] ?? 0),
        ];
    }

    /** @param array{isLive: bool, status: string, workerPid: ?int, cycleCount: int} $worker */
    private function workerLine(array $worker): string
    {
        if (!$worker['isLive']) {
            return 'offline';
        }
        $line = $worker['status'] . ' · cycle ' . $worker['cycleCount'];
        if ($worker['workerPid'] !== null) {
            $line .= ' · pid ' . $worker['workerPid'];
        }

        return $line;
    }

    /** @param list<string> $authorizedLower @return array{total: int, completed: int, failed: int, skippedPrivate: int, skippedBudget: int} */
    private function stats(array $authorizedLower): array
    {
        $stats = ['total' => 0, 'completed' => 0, 'failed' => 0, 'skippedPrivate' => 0, 'skippedBudget' => 0];
        $validRepos = array_values(array_filter($authorizedLower, static fn (mixed $repo): bool =>
            is_string($repo) && preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $repo) === 1));
        if ($validRepos === []) {
            return $stats;
        }
        try {
            $outcome = ($this->hari ?? Hari::registry())->resolve('review.result', 1, 'kernel-cli')->invoke([
                'stats_for' => $validRepos,
                'match' => 'lower',
            ]);
            $counts = is_array($outcome['stats'] ?? null) ? $outcome['stats'] : [];
        } catch (\Throwable) {
            return $stats;
        }

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'skippedPrivate' => (int) ($counts['skipped_private'] ?? 0),
            'skippedBudget' => (int) ($counts['skipped_budget'] ?? 0),
        ];
    }

    /** @return array<string, string> lower => display */
    private function enabledRepositories(): array
    {
        try {
            $enabled = RepositoryAutoReviewSetting::enabledRepositories();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($enabled as $repo) {
            if (is_string($repo) && trim($repo) !== '') {
                $out[strtolower(trim($repo))] = trim($repo);
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $authorized lower => display
     * @param array<string, string> $enabled lower => display
     * @return array{title: string, command: string}
     */
    private function nextStep(bool $connected, array $authorized, ?string $selected, array $enabled, bool $workerLive, bool $canRequest, bool $selectedPrivate, string $verification): array
    {
        $step = \ProofPatch\Application\Settings\ConnectionNextStep::key($connected, count($authorized), $selected);
        if ($step === 'connect') {
            return ['title' => 'Connect GitHub to authorize repositories.', 'command' => 'php artisan github:connect'];
        }
        if ($verification === 'rejected') {
            return ['title' => 'The saved GitHub sign-in was rejected. Sign in again.', 'command' => 'php artisan github:connect --force'];
        }
        if ($step === 'grant') {
            return ['title' => 'Install the GitHub App on a repository, then re-run connect.', 'command' => 'php artisan github:connect'];
        }
        if ($step === 'select_repo' || ($selected !== null && !isset($authorized[strtolower($selected)]))) {
            return ['title' => 'Select a repository to work on.', 'command' => 'php artisan kernel:select-repo'];
        }
        if ($selectedPrivate) {
            return ['title' => 'Automatic reviews currently support public repositories.', 'command' => 'Select a public repository to run autopilot.'];
        }
        if (!isset($enabled[strtolower($selected)])) {
            return ['title' => "Enable auto-review for {$selected}.", 'command' => "php artisan kernel:select-repo --enable-review {$selected}"];
        }
        if (!$canRequest) {
            return ['title' => 'Local request budget is spent — raise caps to continue.', 'command' => 'Open Settings → Usage & Limits in the web app.'];
        }
        if (!$workerLive) {
            return ['title' => "Start a bounded autopilot run for {$selected}.", 'command' => "php artisan kernel:autopilot --repo={$selected} --minutes=10"];
        }

        return ['title' => 'Autopilot is live — watch its findings.', 'command' => "php artisan kernel:reviews --repository={$selected}"];
    }
}
