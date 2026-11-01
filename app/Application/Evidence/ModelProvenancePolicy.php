<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Binds a submitted patch to the session's generated proposal: provenance is
 * attached only when the patch bytes match the proposal exactly and the
 * source run matches. Anything else verifies as a manual patch (null).
 * Pure policy; the caller owns sessions and verification.
 */
final class ModelProvenancePolicy
{
    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|null
     */
    public static function forPatch(mixed $generated, array $patch, mixed $sourceRun): ?array
    {
        if (!is_array($generated) || ($generated['patch'] ?? null) !== $patch
            || ($generated['sourceRunId'] ?? null) !== $sourceRun) {
            return null;
        }

        return [
            'origin' => 'model', 'model' => $generated['model'], 'usage' => $generated['usage'],
            'sourceRunId' => $generated['sourceRunId'], 'sourceReceiptSha256' => $generated['sourceReceiptSha256'],
        ];
    }
}
