<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Read decisions for the checks history page: float a requested run to the
 * front, decide baseline readiness, and interpret receipt outcomes. Pure
 * over caller-supplied history, reports, and outcomes; the caller owns
 * evidence reads, skill calls, and rendering.
 */
final class CheckRunHistory
{
    /** @param array<int, string> $runFiles @return array<int, string> */
    public static function prioritizeRequested(array $runFiles, string $runId, string $runsDir): array
    {
        if ($runId !== '' && CheckRunImage::isValidRunId($runId)) {
            $requestedFile = $runsDir.'/'.$runId.'/result.json';
            if (is_file($requestedFile) && !in_array($requestedFile, array_slice($runFiles, 0, 25), true)) {
                array_unshift($runFiles, $requestedFile);
            }
        }

        return $runFiles;
    }

    public static function isBaselineReady(mixed $report, string $currentHead): bool
    {
        return $report !== null && ($report['integrityValid'] ?? false) && ($report['baseCommit'] ?? null) === $currentHead
            && ($report['runs']['baseline']['exitCode'] ?? null) === 1;
    }

    /** @return array{receipt: mixed, error: string|null} */
    public static function receiptOutcome(mixed $outcome): array
    {
        if (is_array($outcome) && ($outcome['valid'] ?? null) === true && is_array($outcome['receipt'] ?? null)) {
            return [$outcome['receipt'], null];
        }

        return [null, is_string($outcome['reason'] ?? null) ? $outcome['reason'] : 'Receipt is invalid.'];
    }
}
