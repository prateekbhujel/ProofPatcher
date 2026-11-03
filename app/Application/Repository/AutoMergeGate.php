<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Pure auto-merge decision. Every condition must pass; all failing reasons
 * are returned together so the maintainer sees exactly what blocked a merge.
 * Fail closed: unknown checks or unknown thread state block the merge.
 */
final class AutoMergeGate
{
    /**
     * @param array{
     *     autoMergeEnabled?: bool, autoReadyEnabled?: bool, reviewStatus?: ?string,
     *     recommendation?: ?string, checksState?: ?string, unresolvedThreads?: ?int,
     *     isDraft?: bool, state?: ?string, isMerged?: bool,
     *     reviewedHead?: ?string, currentHead?: ?string
     * } $in
     * @return array{allowed: bool, needsReady: bool, reasons: list<string>}
     */
    public static function evaluate(array $in): array
    {
        $reasons = [];

        if (($in['autoMergeEnabled'] ?? false) !== true) {
            $reasons[] = 'auto-merge is off for this repository';
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
        if ($recommendation !== 'approve') {
            $reasons[] = 'recommendation is '.(is_string($recommendation) && $recommendation !== '' ? $recommendation : 'missing').', not approve';
        }
        $checks = $in['checksState'] ?? null;
        if ($checks !== 'success') {
            $reasons[] = 'checks are not green ('.(is_string($checks) && $checks !== '' ? $checks : 'unknown').')';
        }
        $threads = $in['unresolvedThreads'] ?? null;
        if ($threads === null) {
            $reasons[] = 'unresolved thread count is unknown';
        } elseif ($threads > 0) {
            $reasons[] = 'unresolved review threads remain ('.$threads.')';
        }
        $reviewed = $in['reviewedHead'] ?? null;
        $current = $in['currentHead'] ?? null;
        if (!is_string($reviewed) || !is_string($current)
            || !preg_match('/\A[a-f0-9]{40}\z/', $reviewed) || !preg_match('/\A[a-f0-9]{40}\z/', $current)
            || !hash_equals($reviewed, $current)) {
            $reasons[] = 'branch changed after review; re-review the new revision';
        }

        $isDraft = ($in['isDraft'] ?? false) === true;
        $autoReady = ($in['autoReadyEnabled'] ?? false) === true;
        if ($isDraft && !$autoReady) {
            $reasons[] = 'pull request is still a draft and auto-ready is off';
        }

        return [
            'allowed' => $reasons === [],
            'needsReady' => $isDraft && $autoReady,
            'reasons' => $reasons,
        ];
    }
}
