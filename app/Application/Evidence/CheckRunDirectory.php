<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Resolves one check-run evidence directory inside the runs root. Refuses
 * malformed ids, symlinked roots or entries, and anything that does not
 * resolve to a direct child directory of the base. Pure policy over stdlib;
 * the caller owns HTTP behavior.
 */
final class CheckRunDirectory
{
    public static function resolve(string $runsDir, string $id): ?string
    {
        if (!CheckRunImage::isValidRunId($id) || is_link($runsDir) || is_link($runsDir.'/'.$id)) {
            return null;
        }
        $base = realpath($runsDir);
        $path = realpath($runsDir.'/'.$id);
        return $base !== false && $path !== false && dirname($path) === $base && is_dir($path) ? $path : null;
    }
}
