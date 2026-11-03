<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;

/** Reusable reasoning guidance; this skill neither runs checks nor grants tools. */
final class EngineeringWorkflowSkill implements Skill
{
    public function id(): string { return 'engineering.workflow'; }

    public function version(): int { return 1; }

    public function capabilities(): array { return []; }

    public function describe(): string
    {
        return 'Builds a review or repair checklist. No repository reads, model calls, execution, or external writes.';
    }

    public function invoke(array $input): array
    {
        $task = $input['task'] ?? null;
        if (array_keys($input) !== ['task'] || !in_array($task, ['review', 'repair'], true)) {
            throw new HariError('invalid_input', 'Choose review or repair for the engineering workflow.');
        }

        $checks = [
            'intent' => 'Identify the intended observable behavior from the supplied context. Missing context is unknown, not proof of a defect.',
            'cause' => 'Trace a concrete input through the changed code to its result. Prefer the smallest context-supported correction and the repository idioms visible in the supplied code.',
            'boundaries' => 'Consider relevant empty, invalid, boundary, and failure inputs. For state-changing code, also consider retries, interruption, duplicate delivery, and concurrent requests.',
            'evidence' => 'Check that each conclusion follows from the supplied evidence. Describe a useful reproduction or regression check when possible. Do not claim a check ran or passed without its actual result.',
        ];
        $checks['decision'] = $task === 'review'
            ? 'Keep only actionable findings supported by this review scope. Ask a focused question where the missing information could change the verdict; do not fill space with speculative warnings.'
            : 'Change only what the supplied task supports. Preserve tests and verification boundaries. Explain why the proposed change addresses the cause; the proposal remains unverified until the separate verifier runs.';

        return ['task' => $task, 'checks' => $checks, 'guidance' => implode(' ', $checks)];
    }
}
