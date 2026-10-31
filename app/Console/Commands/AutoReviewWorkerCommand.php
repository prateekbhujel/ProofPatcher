<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Models\AutoReviewWorkerHeartbeat;
use ProofPatch\Services\AutoReviewService;

final class AutoReviewWorkerCommand extends Command
{
    protected $signature = 'review:poll
                            {--once : Run a single poll cycle and exit}
                            {--daemon : Run continuously as a background daemon}
                            {--interval=15 : Sleep interval in seconds between polling cycles}
                            {--repo= : Limit review cycle to a specific repository}';

    protected $description = 'Poll authorized repositories for new/updated pull requests and execute bounded advisory reviews';

    private bool $running = true;

    public function handle(AutoReviewService $service): int
    {
        $lockDir = function_exists('storage_path') ? storage_path('framework') : dirname(__DIR__, 3).'/storage/framework';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0700, true);
        }
        $lockPath = $lockDir.'/auto_review_worker.lock';
        $lockHandle = @fopen($lockPath, 'c+');

        if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->warn('Auto-review worker is already running in another process.');
            return self::SUCCESS;
        }

        $workerId = bin2hex(random_bytes(8));
        $pid = getmypid() ?: 0;
        $hostname = gethostname() ?: 'localhost';

        AutoReviewWorkerHeartbeat::recordBeat($workerId, $pid, $hostname, 0);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($workerId): void {
                $this->running = false;
                AutoReviewWorkerHeartbeat::stopBeat($workerId);
            });
            pcntl_signal(SIGTERM, function () use ($workerId): void {
                $this->running = false;
                AutoReviewWorkerHeartbeat::stopBeat($workerId);
            });
        }

        $isOnce = $this->option('once') || (!$this->option('daemon'));
        $interval = max(5, min(3600, (int) $this->option('interval')));
        $repo = $this->option('repo') ? (string) $this->option('repo') : null;

        $this->info("ProofPatcher Auto-Review Worker started (PID: {$pid}, Worker ID: {$workerId}).");
        $this->line('Constraint: Strictly public diffs only. Private or uncertain repositories are rejected.');

        $cycle = 0;
        try {
            do {
                $cycle++;
                AutoReviewWorkerHeartbeat::updateBeat($workerId, $cycle);

                $this->line("<comment>[Cycle #{$cycle}]</comment> Polling authorized repositories at ".date('Y-m-d H:i:s').'...');
                $cycleStarted = microtime(true);
                $result = $service->poll(onlyRepo: $repo);
                $elapsed = round(microtime(true) - $cycleStarted, 1);

                $statusTag = match ($result['status'] ?? 'unknown') {
                    'ok' => 'info',
                    'no_token', 'no_repositories_enabled' => 'comment',
                    default => 'error',
                };
                $this->line("  Status: <{$statusTag}>{$result['status']}</{$statusTag}> ({$elapsed}s)");
                $this->line("  Repositories checked: {$result['reposChecked']}");
                $this->line("  Pull requests inspected: {$result['pullsInspected']}");
                $this->line("  Reviews completed: {$result['reviewsCompleted']}");
                $this->line("  Reviews skipped: {$result['reviewsSkipped']}");
                $this->line("  Reviews failed: {$result['reviewsFailed']}");

                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $err) {
                        $this->error("  Error: {$err}");
                    }
                }

                if ($isOnce || !$this->running) {
                    break;
                }

                $this->line("  <comment>Next cycle in {$interval}s…</comment>");
                sleep($interval);
            } while ($this->running);
        } finally {
            AutoReviewWorkerHeartbeat::stopBeat($workerId);
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        $this->info('ProofPatcher Auto-Review Worker stopped gracefully.');
        return self::SUCCESS;
    }
}
