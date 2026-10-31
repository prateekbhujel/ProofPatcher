<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;

final class RepositoryAutoReviewSetting extends Model
{
    protected $table = 'repository_auto_review_settings';

    protected $fillable = [
        'repository',
        'enabled',
        'public_only',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'public_only' => 'boolean',
    ];

    public static function isRepoEnabled(string $repository): bool
    {
        $setting = self::where('repository', trim($repository))->first();
        return $setting !== null && $setting->enabled;
    }

    public static function setRepoEnabled(string $repository, bool $enabled): self
    {
        $repository = trim($repository);
        if (!preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository)) {
            throw new \InvalidArgumentException('A valid repository name in owner/repo format is required.');
        }

        return self::updateOrCreate(
            ['repository' => $repository],
            [
                'enabled' => $enabled,
                'public_only' => true, // Fails closed: automatic reviews strictly process public diffs only
            ]
        );
    }

    /**
     * @return array<string>
     */
    public static function enabledRepositories(): array
    {
        return self::where('enabled', true)->pluck('repository')->all();
    }

    public static function disableForRepository(string $repository): void
    {
        $setting = self::where('repository', trim($repository))->first();
        if ($setting !== null && $setting->enabled) {
            $setting->update(['enabled' => false]);
        }
    }
}
