<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/** Do not pass a credential returned in a GitHub response to another provider. */
final class ResponseTokenGuard
{
    public static function contains(mixed $value, ?string $token): bool
    {
        if ($token === null) return false;
        if (is_string($value)) return str_contains($value, $token);
        if (!is_array($value)) return false;
        foreach ($value as $key => $item) {
            if ((is_string($key) && str_contains($key, $token)) || self::contains($item, $token)) return true;
        }
        return false;
    }
}
