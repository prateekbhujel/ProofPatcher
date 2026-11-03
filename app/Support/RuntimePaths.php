<?php

declare(strict_types=1);

namespace ProofPatch\Support;

/** Private runtime storage shared by web, CLI and standalone verifiers. */
final class RuntimePaths
{
    public static function runs(string $project): string
    {
        return $project.'/storage/app/private/proofpatch/runs';
    }

    public static function snapshots(string $project): string
    {
        return getenv('PROOFPATCH_SNAPSHOT_DIR') ?: $project.'/storage/app/private/proofpatch/pull-requests';
    }

    public static function approvals(string $project): string
    {
        $home = getenv('HOME');
        return getenv('PROOFPATCH_APPROVALS_DIR')
            ?: ($home ? $home.'/.local/share/proofpatch/runtime/approvals' : $project.'/storage/app/private/proofpatch/approvals');
    }
}
