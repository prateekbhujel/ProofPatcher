<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Support\Facades\DB;

/** The controller must verify the GitHub identity and repository grant first. */
final class AutomaticInlineSetting
{
    public static function enabled(int $userId, string $repository): bool
    {
        return (bool) DB::table('automatic_inline_settings')
            ->where('user_id', $userId)->where('repository', strtolower(trim($repository)))
            ->where('enabled', true)->exists();
    }

    public static function setForOwner(int $userId, string $repository, bool $enabled): void
    {
        $repository = strtolower(trim($repository));
        if ($userId < 1 || !preg_match('~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~', $repository)) {
            throw new \InvalidArgumentException('Invalid identity or repository.');
        }
        DB::table('automatic_inline_settings')->updateOrInsert(
            ['user_id' => $userId, 'repository' => $repository], ['enabled' => $enabled]
        );
    }

    public static function revokeAllForUser(int $userId): void
    {
        if ($userId > 0) DB::table('automatic_inline_settings')->where('user_id', $userId)->delete();
    }

    /** Remove posting consent when a previously granted repository disappears or becomes private. */
    public static function retainOnlyAuthorizedPublic(int $userId, array $repositories): void
    {
        if ($userId < 1) return;
        $allowed = [];
        foreach ($repositories as $repo) {
            if (is_array($repo) && is_string($repo['fullName'] ?? null) && ($repo['isPrivate'] ?? null) === false) {
                $allowed[] = strtolower($repo['fullName']);
            }
        }
        $query = DB::table('automatic_inline_settings')->where('user_id', $userId);
        if ($allowed !== []) $query->whereNotIn('repository', $allowed);
        $query->delete();
    }
}
