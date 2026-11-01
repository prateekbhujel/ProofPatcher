<?php

declare(strict_types=1);

namespace ProofPatch\Application\Evidence;

/**
 * Security decisions for serving check-run evidence images: run-id shape,
 * image-type allowlist, and runs-directory containment. Pure policy over
 * stdlib calls; the caller owns HTTP responses.
 */
final class CheckRunImage
{
    /** @var array<string, string> */
    public const FOLDERS = [
        'before' => 'baseline',
        'baseline' => 'baseline',
        'after' => 'candidate',
        'candidate' => 'candidate',
    ];

    public static function isValidRunId(string $runId): bool
    {
        return (bool) preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{12}\z/', $runId);
    }

    public static function folderFor(string $imageType): ?string
    {
        return self::FOLDERS[$imageType] ?? null;
    }

    /**
     * Resolves the evidence PNG only when it stays inside the runs directory.
     * Returns null for missing files, unreadable bases, and escapes.
     */
    public static function resolvePath(string $runsDir, string $runId, string $folder): ?string
    {
        $path = realpath($runsDir.'/'.$runId.'/'.$folder.'/checkout.png');
        $base = realpath($runsDir);
        if ($path === false || $base === false || !str_starts_with($path, $base) || !is_file($path)) {
            return null;
        }

        return $path;
    }
}
