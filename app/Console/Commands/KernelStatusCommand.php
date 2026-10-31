<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Agent\ProviderConfigStore;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Support\KernelCli;
use ProofPatch\Support\WorkerStatusService;

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
        $selected = $state['connected'] ? KernelCli::getSelectedRepo() : null;
        $authorized = [];
        foreach ($state['repos'] as $repo) {
            $authorized[strtolower($repo['fullName'])] = $repo['fullName'];
        }

        $provider = $this->provider();
        $budget = $this->budget();
        $worker = $this->worker();
        $stats = $this->stats(array_keys($authorized));
        $enabled = $this->enabledRepositories();
        $next = $this->nextStep($state['connected'], $authorized, $selected, $enabled, (bool) ($worker['isLive'] ?? false));

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'kernel' => KernelCli::VERSION,
                'connected' => $state['connected'],
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
        $this->line('  GitHub      ' . $this->githubLine($state, $selected));
        $this->line('  Muse        ' . $provider['label'] . ' · ' . $provider['model'] . ' · ' . ($provider['keyReady'] ? 'key ready' : 'key missing'));
        $this->line('  Budget      ' . $this->budgetLine($budget));
        $this->line('  Autopilot   ' . $this->workerLine($worker));
        $this->line('  Auto-review ' . $stats['total'] . ' total · ' . $stats['completed'] . ' completed · ' . $stats['failed'] . ' failed · ' . $stats['skippedPrivate'] . ' skipped (private) · ' . $stats['skippedBudget'] . ' skipped (budget)');
        $this->line('  Reviews on  ' . ($enabled === [] ? 'none — enable one to let autopilot work' : implode(', ', array_values($enabled))));
        $this->line('');
        $this->line('  Next: ' . $next['title']);
        $this->line('  ' . $next['command']);

        return self::SUCCESS;
    }

    /** @param array{connected: bool, user: ?array{login: ?string}, repos: list<array{fullName: string}>} $state */
    private function githubLine(array $state, ?string $selected): string
    {
        if (!$state['connected']) {
            return 'not connected';
        }
        $count = count($state['repos']);
        $line = 'connected as @' . ($state['user']['login'] ?? '?') . ' · ' . $count . ' ' . ($count === 1 ? 'repo' : 'repos');
        if ($selected !== null) {
            $line .= ' · selected ' . $selected;
        }

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

    /** @return ?array{attempts: int, maxAttempts: int, remainingMicros: int, canRequest: bool} */
    private function budget(): ?array
    {
        try {
            $status = RequestBudget::forRuntime()->status();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($status)) {
            return null;
        }

        return [
            'attempts' => (int) ($status['attempts'] ?? 0),
            'maxAttempts' => (int) ($status['maxAttempts'] ?? 0),
            'remainingMicros' => (int) ($status['remainingMicros'] ?? 0),
            'canRequest' => (bool) ($status['canRequest'] ?? false),
        ];
    }

    private function budgetLine(?array $budget): string
    {
        if ($budget === null) {
            return 'unavailable';
        }

        return $budget['attempts'] . ' of ' . $budget['maxAttempts'] . ' attempts · $' . number_format($budget['remainingMicros'] / 1000000, 2) . ' left';
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
        if ($authorizedLower === []) {
            return $stats;
        }
        $allowed = array_fill_keys($authorizedLower, true);
        try {
            $rows = AutoReviewResult::selectRaw('repository, status, COUNT(*) AS aggregate')->groupBy('repository', 'status')->get();
        } catch (\Throwable) {
            return $stats;
        }
        foreach ($rows as $row) {
            $repo = strtolower((string) ($row->repository ?? ''));
            if (!isset($allowed[$repo])) {
                continue;
            }
            $count = (int) ($row->aggregate ?? 0);
            $stats['total'] += $count;
            match ((string) ($row->status ?? '')) {
                'completed' => $stats['completed'] += $count,
                'failed' => $stats['failed'] += $count,
                'skipped_private' => $stats['skippedPrivate'] += $count,
                'skipped_budget' => $stats['skippedBudget'] += $count,
                default => null,
            };
        }

        return $stats;
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
    private function nextStep(bool $connected, array $authorized, ?string $selected, array $enabled, bool $workerLive): array
    {
        if (!$connected) {
            return ['title' => 'Connect GitHub to authorize repositories.', 'command' => 'php artisan github:connect'];
        }
        if ($authorized === []) {
            return ['title' => 'Install the GitHub App on a repository, then re-run connect.', 'command' => 'php artisan github:connect'];
        }
        if ($selected === null || !isset($authorized[strtolower($selected)])) {
            return ['title' => 'Select a repository to work on.', 'command' => 'php artisan kernel:select-repo'];
        }
        if (!isset($enabled[strtolower($selected)])) {
            return ['title' => "Enable auto-review for {$selected}.", 'command' => "php artisan kernel:select-repo --enable-review {$selected}"];
        }
        if (!$workerLive) {
            return ['title' => "Start a bounded autopilot run for {$selected}.", 'command' => "php artisan kernel:autopilot --repo={$selected} --minutes=10"];
        }

        return ['title' => 'Autopilot is live — watch its findings.', 'command' => "php artisan kernel:reviews --repository={$selected}"];
    }
}
