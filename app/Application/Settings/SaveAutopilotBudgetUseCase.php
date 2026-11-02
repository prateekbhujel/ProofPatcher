<?php

declare(strict_types=1);

namespace ProofPatch\Application\Settings;

/**
 * Decides the autopilot cap outcome from validated input. Pure domain logic:
 * no sessions, no HTTP, no database (the caller persists via the model).
 */
final class SaveAutopilotBudgetUseCase
{
    public function execute(SaveAutopilotBudgetInput $in): SaveAutopilotBudgetResult
    {
        if ($in->maxAttempts === null && $in->maxMicros === null) {
            return new SaveAutopilotBudgetResult(
                true, null, null, true,
                'Autopilot caps cleared. Automatic reviews are bounded only by your provider quota.',
                ''
            );
        }

        $parts = [];
        if ($in->maxAttempts !== null) {
            $parts[] = "{$in->maxAttempts} attempts";
        }
        if ($in->maxMicros !== null) {
            $parts[] = '$'.number_format($in->maxMicros / 1000000, 2);
        }

        return new SaveAutopilotBudgetResult(
            true, $in->maxAttempts, $in->maxMicros, false,
            'Autopilot cap set: '.implode(' and ', $parts).' of spend. Autopilot reviews stop there.',
            ''
        );
    }
}
