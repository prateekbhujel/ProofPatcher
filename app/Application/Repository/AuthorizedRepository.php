<?php

declare(strict_types=1);

namespace ProofPatch\Application\Repository;

/**
 * Finds one repository inside a GitHub grant list by full name. Pure lookup:
 * no sessions, no HTTP (the caller supplies the grant rows).
 */
final class AuthorizedRepository
{
    /**
     * @param array<int, mixed> $repos
     * @return array<string, mixed>|null
     */
    public static function find(array $repos, string $fullName): ?array
    {
        foreach ($repos as $row) {
            if (is_array($row) && ($row['fullName'] ?? '') === $fullName) {
                return $row;
            }
        }

        return null;
    }
}
