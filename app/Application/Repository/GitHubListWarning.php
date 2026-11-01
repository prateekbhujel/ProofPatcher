<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Formats GitHub list incompleteness warnings. A truncated page takes
 * precedence over item errors; a complete, error-free status has no warning.
 */
final class GitHubListWarning
{
    /** @param array<string, mixed> $status */
    public static function message(array $status, string $truncatedMessage, string $errorsPrefix): ?string
    {
        $errors = $status['errors'] ?? [];
        if ((bool) ($status['truncated'] ?? false)) {
            return $truncatedMessage;
        }
        if (!empty($errors)) {
            return $errorsPrefix.implode('; ', $errors);
        }

        return null;
    }
}
