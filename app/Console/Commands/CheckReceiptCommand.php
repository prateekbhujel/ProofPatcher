<?php

declare(strict_types=1);

namespace ProofPatch\Console\Commands;

use Hari\Hari;
use Hari\Registry;
use Illuminate\Console\Command;

final class CheckReceiptCommand extends Command
{
    protected $signature = 'review:receipt {run : Run ID or path to evidence directory} {expected? : Optional expected SHA-256 digest}';

    protected $description = 'Verify the cryptographic integrity of a ProofPatcher evidence receipt';

    public function __construct(private ?Registry $hari = null)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = (string) $this->argument('run');
        $expected = $this->argument('expected') ? (string) $this->argument('expected') : null;

        $directory = is_dir($run) ? $run : (function_exists('base_path') ? base_path('artifacts/runs/'.$run) : dirname(__DIR__, 3).'/artifacts/runs/'.$run);
        if (!is_dir($directory)) {
            $this->error("Evidence directory not found: {$run}");
            return self::FAILURE;
        }

        try {
            $result = ($this->hari ?? Hari::registry())
                ->resolve('evidence.verify', 1, 'kernel-cli')
                ->invoke(['directory' => $directory, 'expected' => $expected]);
        } catch (\Throwable) {
            $this->error('Receipt check failed: The evidence skill is unavailable.');
            return self::FAILURE;
        }
        if (!is_array($result) || ($result['valid'] ?? null) !== true || !is_array($result['receipt'] ?? null)) {
            $this->error('Receipt check failed: '.(is_string($result['reason'] ?? null) ? $result['reason'] : 'Receipt is invalid.'));
            return self::FAILURE;
        }
        $this->line(json_encode($result['receipt'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
