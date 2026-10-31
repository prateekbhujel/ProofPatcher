<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Illuminate\Console\Command;
use ProofPatch\Support\Telemetry;

/**
 * Terminal twin of the Telemetry page: the same local usage numbers as
 * console tables. Width-aware when the php-terminal extension is present,
 * plain 100-column output otherwise. Read-only.
 */
final class KernelTelemetryCommand extends Command
{
    protected $signature = 'kernel:telemetry
                            {--ledger= : Read the request ledger from a custom path instead of the default}';

    protected $description = 'Show local model usage telemetry: requests, tokens, estimates, models, and recent activity';

    public function handle(): int
    {
        $ledger = $this->option('ledger');
        $data = Telemetry::collect(is_string($ledger) && trim($ledger) !== '' ? trim($ledger) : null);
        $width = $this->terminalWidth();

        $this->line('ProofPatcher Kernel telemetry (local estimates, generated '.$data['generatedAt'].')');
        $this->newLine();

        $r = $data['requests'];
        $t = $data['tokens'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Model requests', (string) $r['total']],
                ['Received / uncertain', $r['received'].' / '.$r['uncertain']],
                ['Estimated reserved', '$'.number_format($r['reservedMicros'] / 1000000, 4)],
                ['Tokens in / out', $t['prompt'].' / '.$t['completion']],
                ['Reviews completed', (string) $data['reviews']['completed'].' of '.$data['reviews']['total']],
                ['Ledger', $data['ledgerFound'] ? 'found' : 'absent (zeros shown)'],
            ]
        );

        if ($data['models'] !== []) {
            $this->newLine();
            $this->line('Models (from stored completed reviews)');
            $rows = [];
            foreach ($data['models'] as $model) {
                $rows[] = [$model['model'], (string) $model['reviews'], (string) $model['promptTokens'], (string) $model['completionTokens']];
            }
            $this->table(['Model', 'Reviews', 'Tokens in', 'Tokens out'], $rows);
        }

        if ($data['perDay'] !== []) {
            $this->newLine();
            $this->line('Daily volume (last 14 days)');
            $rows = [];
            foreach ($data['perDay'] as $day) {
                $rows[] = [$day['day'], (string) $day['requests'], '$'.number_format($day['micros'] / 1000000, 4)];
            }
            $this->table(['Day', 'Requests', 'Est. reserved'], $rows);
        }

        if ($data['feed'] !== []) {
            $this->newLine();
            $this->line('Recent activity (latest 25)');
            $rows = [];
            $detailWidth = max(24, $width - 56);
            foreach ($data['feed'] as $event) {
                $usage = $event['tokens'] !== null ? ((string) $event['tokens']).' tok' : '—';
                if ($event['micros'] !== null) {
                    $usage .= ' · $'.number_format($event['micros'] / 1000000, 4);
                }
                $statusTag = match ((string) $event['status']) {
                'received', 'completed' => 'info',
                'failed' => 'error',
                default => 'comment',
            };
            $rows[] = [
                    substr((string) $event['at'], 0, 19),
                    $event['kind'] === 'review' ? 'review' : 'request',
                    "<{$statusTag}>{$event['status']}</{$statusTag}>",
                    mb_strimwidth($event['detail'], 0, $detailWidth, '…'),
                    $usage,
                ];
            }
            $this->table(['Time (UTC)', 'Kind', 'Status', 'Detail', 'Usage'], $rows);
        }

        return self::SUCCESS;
    }

    private function terminalWidth(): int
    {
        return \ProofPatch\Support\TerminalWidth::columns();
    }
}
