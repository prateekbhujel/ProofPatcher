<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Decides whether a snapshot candidate is a *different* pull than the loaded
 * one, so triage overlap compares against other pulls only. Pure predicate;
 * the caller owns snapshot loading and authorization.
 */
final class TriageCandidates
{
    /**
     * Select one observed revision per pull without deleting its saved history.
     * The caller owns loading, integrity checks and visibility authorization.
     *
     * @param list<array{id: string, capturedAt: string, pull: array<string, mixed>}> $captures
     * @return list<array<string, mixed>>
     */
    public static function latestPerPull(array $captures): array
    {
        $latest = [];
        foreach ($captures as $capture) {
            $pull = $capture['pull'] ?? null;
            $capturedAt = $capture['capturedAt'] ?? null;
            $id = $capture['id'] ?? null;
            if (!is_array($pull) || !is_string($pull['repository'] ?? null) || $pull['repository'] === ''
                || !is_int($pull['number'] ?? null) || $pull['number'] < 1
                || !is_string($capturedAt) || !is_string($id)) {
                continue;
            }
            $timestamp = strtotime($capturedAt);
            if ($timestamp === false) {
                continue;
            }
            $key = strtolower($pull['repository']).'#'.$pull['number'];
            $rank = [$timestamp, $id];
            if (!isset($latest[$key]) || $rank > $latest[$key]['rank']) {
                $latest[$key] = ['rank' => $rank, 'pull' => $pull];
            }
        }
        uasort($latest, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return array_values(array_column($latest, 'pull'));
    }

    public static function isDifferentPull(mixed $candidate, mixed $current): bool
    {
        return is_array($candidate)
            && (($candidate['number'] ?? null) !== ($current['number'] ?? null)
                || ($candidate['repository'] ?? '') !== ($current['repository'] ?? ''));
    }
}
