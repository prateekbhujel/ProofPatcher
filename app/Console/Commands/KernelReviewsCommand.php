<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Models\AutoReviewResult;
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

    public function handle(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $repository = is_string($this->option('repository')) ? trim($this->option('repository')) : '';
        $status = is_string($this->option('status')) ? trim($this->option('status')) : '';

        try {
            $query = AutoReviewResult::orderByDesc('id');
            if ($repository !== '') {
                $query->where('repository', $repository);
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            $rows = $query->limit($limit)->get();
        } catch (\Throwable $e) {
            $this->error('Review store unavailable: '.$e->getMessage());
            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->line('No automatic review findings yet.');
            return self::SUCCESS;
        }

        $detailWidth = max(24, TerminalWidth::columns() - 72);
        $table = [];
        foreach ($rows as $row) {
            $status = str_replace('_', ' ', (string) $row->status);
            $statusTag = match ((string) $row->status) {
                'completed' => 'info',
                'failed' => 'error',
                default => 'comment',
            };
            $table[] = [
                $row->repository.'#'.$row->pull_number,
                "<{$statusTag}>{$status}</{$statusTag}>",
                $row->recommendation ? str_replace('_', ' ', (string) $row->recommendation) : '—',
                $row->risk_level ? (string) $row->risk_level : '—',
                $row->reviewed_at?->format('Y-m-d H:i') ?? '—',
                mb_strimwidth((string) ($row->summary ?? ''), 0, $detailWidth, '…'),
            ];
        }
        $this->table(['Target', 'Status', 'Advice', 'Risk', 'Reviewed (UTC)', 'Summary'], $table);

        return self::SUCCESS;
    }
}
