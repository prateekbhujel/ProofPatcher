<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Hari\Hari;
use Hari\Registry;
use Illuminate\Console\Command;
use ProofPatch\Support\TerminalWidth;

/**
 * Terminal twin of the review findings lists: recent automatic review
 * outcomes as a console table. Read-only; the database is the only source.
 */
final class KernelReviewsCommand extends Command
{
    protected $signature = 'kernel:reviews
                            {--limit=10 : Maximum rows to show (1-50)}
                            {--repository= : Only show findings for one owner/repo repository}
                            {--status= : Only show one status (completed, failed, skipped_private, skipped_budget)}';

    protected $description = 'List recent automatic review findings in the terminal';

    public function __construct(private ?Registry $hari = null)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $repository = is_string($this->option('repository')) ? trim($this->option('repository')) : '';
        $status = is_string($this->option('status')) ? trim($this->option('status')) : '';

        if ($status !== '' && !in_array($status, \ProofPatch\Abilities\ReviewResultSkill::STATUSES, true)) {
            $this->error(\ProofPatch\Support\KernelCli::report('kernel:reviews · invalid status', "status '{$status}' is invalid", 'use --status='.implode('|', \ProofPatch\Abilities\ReviewResultSkill::STATUSES)));
            return self::FAILURE;
        }

        try {
            $input = ['limit' => $limit];
            if ($repository !== '') {
                $input['repository'] = $repository;
            }
            if ($status !== '') {
                $input['status'] = $status;
            }
            $result = ($this->hari ?? Hari::registry())
                ->resolve('review.result', 1, 'kernel-cli')
                ->invoke($input);
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : null;
            if ($rows === null) {
                throw new \RuntimeException('Review store returned an unexpected shape.');
            }
        } catch (\Throwable $e) {
            $this->error(\ProofPatch\Support\KernelCli::report('kernel:reviews · store unavailable', $e->getMessage(), 'check the database file is writable, then re-run'));
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->line('No automatic review findings yet.');
            $selected = \ProofPatch\Support\KernelCli::getSelectedRepo();
            $this->line($selected !== null
                ? "Next: php artisan kernel:autopilot --repo={$selected} --minutes=10"
                : 'Next: php artisan kernel:status shows the one step that unblocks reviews.');
            return self::SUCCESS;
        }

        $detailWidth = max(24, TerminalWidth::columns() - 89);
        $table = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowStatus = (string) ($row['status'] ?? '');
            $status = str_replace('_', ' ', $rowStatus);
            $statusTag = match ($rowStatus) {
                'completed' => 'info',
                'failed' => 'error',
                default => 'comment',
            };
            $table[] = [
                ($row['repository'] ?? '?').'#'.($row['pull_number'] ?? '?'),
                is_string($row['head_sha'] ?? null) && $row['head_sha'] !== '' ? substr($row['head_sha'], 0, 12) : '—',
                "<{$statusTag}>{$status}</{$statusTag}>",
                !empty($row['recommendation']) ? str_replace('_', ' ', (string) $row['recommendation']) : '—',
                !empty($row['risk_level']) ? (string) $row['risk_level'] : '—',
                is_string($row['reviewed_at_display'] ?? null) ? $row['reviewed_at_display'] : '—',
                mb_strimwidth((string) ($row['summary'] ?? ''), 0, $detailWidth, '…'),
            ];
        }
        $this->table(['Target', 'Review commit', 'Status', 'Advice', 'Risk', 'Reviewed (UTC)', 'Summary'], $table);

        return self::SUCCESS;
    }
}
