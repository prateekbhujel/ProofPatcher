<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;
use ProofPatch\Agent\RequestBudget;

/** Skill budget.status v1: read-only budget counters. No network, no writes. */
final class BudgetStatusSkill implements Skill
{
    public function __construct(private ?RequestBudget $budget = null)
    {
    }

    public function id(): string
    {
        return 'budget.status';
    }

    public function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['budget.read'];
    }

    public function describe(): string
    {
        return 'Reads the local model-request budget ledger. No network, no writes, no credentials.';
    }

    public function invoke(array $input): array
    {
        try {
            $status = ($this->budget ?? RequestBudget::forRuntime())->status();
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Budget skill failed.');
        }

        // Explicit allowlist: counters only, never credentials or paths.
        return [
            'attempts' => $status['attempts'] ?? null,
            'maxAttempts' => $status['maxAttempts'] ?? null,
            'reservedMicros' => $status['reservedMicros'] ?? null,
            'limitMicros' => $status['limitMicros'] ?? null,
            'remainingMicros' => $status['remainingMicros'] ?? null,
            'canRequest' => $status['canRequest'] ?? false,
        ];
    }
}
