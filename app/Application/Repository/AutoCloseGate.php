<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Pure auto-close decision for pull requests that are not worth a
 * maintainer's time: the review rejected the revision, checks are failing,
 * and at least one blocking concern was found. Approving or merely
 * unfinished work is never closed. Fail closed on any unknown.
 */
final class AutoCloseGate
{
    /**
     * @param array{
     *     autoCloseEnabled?: bool, reviewStatus?: ?string, recommendation?: ?string,
     *     checksState?: ?string, concernCount?: int, state?: ?string, isMerged?: bool,
     *     reviewedHead?: ?string, currentHead?: ?string
     * } $in
     * @return array{allowed: bool, reasons: list<string>}
     */
    public static function evaluate(array $in): array
    {
        $reasons = [];

        if (($in['autoCloseEnabled'] ?? false) !== true) {
            $reasons[] = 'auto-close is off for this repository';
        }
        if (($in['isMerged'] ?? false) === true) {
            $reasons[] = 'pull request is already merged';
        }
        if (($in['state'] ?? null) !== 'open') {
            $reasons[] = 'pull request is not open';
        }
        if (($in['reviewStatus'] ?? null) !== 'completed') {
            $reasons[] = 'review is not completed for this revision';
        }
        $recommendation = $in['recommendation'] ?? null;
        if ($recommendation !== 'request_changes') {
            $reasons[] = 'recommendation is '.(is_string($recommendation) && $recommendation !== '' ? $recommendation : 'missing').', not request_changes';
        }
        $checks = $in['checksState'] ?? null;
        if ($checks !== 'failure') {
            $reasons[] = 'checks are not failing ('.(is_string($checks) && $checks !== '' ? $checks : 'unknown').')';
        }
        if ((int) ($in['concernCount'] ?? 0) < 1) {
            $reasons[] = 'no blocking concern was found in the review';
        }
        $reviewed = $in['reviewedHead'] ?? null;
        $current = $in['currentHead'] ?? null;
        if (!is_string($reviewed) || !is_string($current)
            || !preg_match('/\A[a-f0-9]{40}\z/', $reviewed) || !preg_match('/\A[a-f0-9]{40}\z/', $current)
            || !hash_equals($reviewed, $current)) {
            $reasons[] = 'branch changed after review; re-review the new revision';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }
}
