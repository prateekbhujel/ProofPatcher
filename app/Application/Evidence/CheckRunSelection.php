<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

use ProofPatch\Patch\CssPatch;

/**
 * Pure run/fixture selection for the checks page: which run to spotlight,
 * which run to show by default, and which fixture the form targets. The
 * caller owns history building, sessions, and rendering.
 */
final class CheckRunSelection
{
    /** @param array<string, mixed> $history */
    public static function latestVisualRunId(array $history): mixed
    {
        foreach ($history as $id => $item) {
            if (!empty($item['hasVisual']) && ($item['decision'] ?? '') === 'VERIFIED') {
                return $id;
            }
        }
        foreach ($history as $id => $item) {
            if (!empty($item['hasVisual'])) {
                return $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $history */
    public static function defaultRunId(array $history, mixed $latestVisualRunId): mixed
    {
        $runId = $latestVisualRunId;
        if (!$runId) {
            foreach ($history as $id => $item) {
                if (($item['decision'] ?? '') === 'VERIFIED') {
                    $runId = $id;
                    break;
                }
            }
        }

        return $runId ?: array_key_first($history);
    }

    public static function fixture(mixed $input, mixed $proposal, mixed $report): string
    {
        $fixture = $input ?? $proposal['patch']['file'] ?? $report['proposal']['file'] ?? CssPatch::FILE;
        if (!is_string($fixture) || !in_array($fixture, CssPatch::FILES, true)) {
            $fixture = CssPatch::FILE;
        }

        return $fixture;
    }
}
