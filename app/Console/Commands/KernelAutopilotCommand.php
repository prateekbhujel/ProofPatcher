<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\GitHub\GitHubTokenStore;
use ProofPatch\Models\AutoReviewWorkerHeartbeat;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Support\KernelCli;

/**
 * Bounded autopilot: runs the automatic review poll loop for a limited time
 * and/or cycle count, then stops with a totals report. Honors the same
 * worker lock as review:poll so two autopilots never run at once.
 */
final class KernelAutopilotCommand extends Command
{
    protected $signature = 'kernel:autopilot
                            {--repo= : Limit to one owner/repo repository (default: CLI selection)}
                            {--minutes=10 : Maximum runtime in minutes (1-180)}
                            {--interval=15 : Seconds between poll cycles (0-3600)}
                            {--max-cycles= : Stop after this many poll cycles}';

    protected $description = 'Run bounded automatic reviews, then stop with a totals report';

    private AutoReviewService $service;

    private bool $running = true;

    public function __construct(?AutoReviewService $service = null)
    {
        parent::__construct();
        $this->service = $service ?? new AutoReviewService();
    }

    public function handle(): int
    {
        $token = GitHubTokenStore::getToken();
        if ($token === null || $token === '') {
            $this->error(KernelCli::report('kernel:autopilot · cannot start', 'no GitHub token stored', 'php artisan github:connect'));
            return self::FAILURE;
        }

        $repo = $this->option('repo') ? trim((string) $this->option('repo')) : KernelCli::getSelectedRepo();
        if ($repo === null || $repo === '') {
            $this->error(KernelCli::report('kernel:autopilot · cannot start', 'no repository given', 'php artisan kernel:select-repo or pass --repo=owner/name'));
            return self::FAILURE;
        }
        if (!KernelCli::isValidRepo($repo)) {
            $this->error(KernelCli::report('kernel:autopilot · cannot start', "expected owner/repo, got '{$repo}'", 'php artisan kernel:select-repo --list'));
            return self::FAILURE;
        }

        $state = KernelCli::connectState();
        $authorized = [];
        foreach ($state['repos'] as $row) {
            $authorized[strtolower($row['fullName'])] = $row['fullName'];
        }
        if (!isset($authorized[strtolower($repo)])) {
            $this->error(KernelCli::report('kernel:autopilot · cannot start', "{$repo} is not in the current GitHub grant", 'php artisan kernel:select-repo --list'));
            return self::FAILURE;
        }
        $repo = $authorized[strtolower($repo)];

        if (!RepositoryAutoReviewSetting::isRepoEnabled($repo)) {
            $this->error(KernelCli::report('kernel:autopilot · cannot start', "auto-review is off for {$repo}", "php artisan kernel:select-repo --enable-review {$repo}"));
            return self::FAILURE;
        }

        $minutes = max(1, min(180, (int) $this->option('minutes')));
        $interval = max(0, min(3600, (int) $this->option('interval')));
        $maxCyclesOption = $this->option('max-cycles');
        $maxCycles = $maxCyclesOption === null ? null : max(1, (int) $maxCyclesOption);

        $lockDir = $this->lockDir();
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0700, true);
        }
        $lockHandle = @fopen($lockDir . '/auto_review_worker.lock', 'c+');
        if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                @fclose($lockHandle);
            }
            $this->warn('Auto-review worker is already running in another process.');
            return self::SUCCESS;
        }

        $workerId = bin2hex(random_bytes(8));
        $pid = getmypid() ?: 0;
        $hostname = gethostname() ?: 'localhost';
        $this->trapSignals($workerId);

        try {
            AutoReviewWorkerHeartbeat::recordBeat($workerId, $pid, $hostname, 0);
        } catch (\Throwable) {
            // Heartbeat is best-effort telemetry; the run itself continues.
        }

        $deadline = time() + $minutes * 60;
        $cycles = 0;
        $totals = ['pulls' => 0, 'completed' => 0, 'skipped' => 0, 'failed' => 0];
        $stopReason = 'interrupted';

        $this->line("Autopilot for {$repo} — up to {$minutes} min" . ($maxCycles !== null ? ", {$maxCycles} cycles" : '') . '. Ctrl+C stops cleanly.');

        try {
            while ($this->running) {
                $cycles++;
                try {
                    $summary = $this->service->poll($token, $repo);
                } catch (\Throwable $e) {
                    $this->error(KernelCli::report("kernel:autopilot · cycle {$cycles} failed", $e->getMessage(), 'fix the cause above, then re-run kernel:autopilot'));
                    return self::FAILURE;
                }

                if (($summary['status'] ?? '') === 'no_token' || ($summary['status'] ?? '') === 'auth_revoked') {
                    $errors = is_array($summary['errors'] ?? null) ? $summary['errors'] : [];
                    $why = 'GitHub authorization was revoked or expired'
                        . ($errors !== [] ? ' (' . implode('; ', array_map('strval', $errors)) . ')' : '');
                    $this->error(KernelCli::report('kernel:autopilot · authorization lost', $why, 'php artisan github:connect --force'));
                    return self::FAILURE;
                }

                $totals['pulls'] += (int) ($summary['pullsInspected'] ?? 0);
                $totals['completed'] += (int) ($summary['reviewsCompleted'] ?? 0);
                $totals['skipped'] += (int) ($summary['reviewsSkipped'] ?? 0);
                $totals['failed'] += (int) ($summary['reviewsFailed'] ?? 0);

                $this->line(sprintf(
                    'cycle %d: %d pulls inspected · %d completed · %d skipped · %d failed',
                    $cycles,
                    (int) ($summary['pullsInspected'] ?? 0),
                    (int) ($summary['reviewsCompleted'] ?? 0),
                    (int) ($summary['reviewsSkipped'] ?? 0),
                    (int) ($summary['reviewsFailed'] ?? 0)
                ));
                foreach ((array) ($summary['followups'] ?? []) as $link) {
                    if (is_string($link)) $this->line('Review reply needs attention: '.$link);
                }
                foreach ((array) ($summary['errors'] ?? []) as $error) {
                    $this->warn((string) $error);
                }

                try {
                    AutoReviewWorkerHeartbeat::updateBeat($workerId, $cycles);
                } catch (\Throwable) {
                }

                if (($summary['budgetExhausted'] ?? false) === true) {
                    $stopReason = 'budget exhausted';
                    break;
                }
                if ($maxCycles !== null && $cycles >= $maxCycles) {
                    $stopReason = 'max cycles reached';
                    break;
                }
                if (time() >= $deadline) {
                    $stopReason = 'time budget reached';
                    break;
                }
                if (!$this->running) {
                    break;
                }
                if ($interval > 0) {
                    sleep($interval);
                }
            }
        } finally {
            try {
                AutoReviewWorkerHeartbeat::stopBeat($workerId);
            } catch (\Throwable) {
            }
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        $this->line(sprintf(
            'autopilot done (%d %s, %s): %d pulls · %d completed · %d skipped · %d failed',
            $cycles,
            $cycles === 1 ? 'cycle' : 'cycles',
            $stopReason,
            $totals['pulls'],
            $totals['completed'],
            $totals['skipped'],
            $totals['failed']
        ));

        return self::SUCCESS;
    }

    private function lockDir(): string
    {
        try {
            if (function_exists('storage_path')) {
                return storage_path('framework');
            }
        } catch (\Throwable) {
        }

        return dirname(__DIR__, 3) . '/storage/framework';
    }

    private function trapSignals(string $workerId): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        if (!function_exists('pcntl_signal')) {
            return;
        }
        $stop = function () use ($workerId): void {
            $this->running = false;
            try {
                AutoReviewWorkerHeartbeat::stopBeat($workerId);
            } catch (\Throwable) {
            }
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}
