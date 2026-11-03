<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Per-repository maintainer autopilot switches. Fail closed: a missing
 * table, missing row, or missing flag always means the action is off.
 */
final class MaintainerAutopilotSetting extends Model
{
    protected $table = 'maintainer_autopilot_settings';

    protected $fillable = ['repository', 'fix_issues', 'auto_ready', 'auto_merge'];

    protected $casts = [
        'fix_issues' => 'boolean',
        'auto_ready' => 'boolean',
        'auto_merge' => 'boolean',
    ];

    /** @return array{fixIssues: bool, autoReady: bool, autoMerge: bool} */
    public static function flagsFor(string $repository): array
    {
        $off = ['fixIssues' => false, 'autoReady' => false, 'autoMerge' => false];
        try {
            if (!Schema::hasTable('maintainer_autopilot_settings')) {
                return $off;
            }
            $row = self::whereRaw('LOWER(repository) = ?', [strtolower(trim($repository))])->first();
            if ($row === null) {
                return $off;
            }
            return [
                'fixIssues' => $row->fix_issues === true,
                'autoReady' => $row->auto_ready === true,
                'autoMerge' => $row->auto_merge === true,
            ];
        } catch (\Throwable) {
            return $off;
        }
    }

    public static function setFlag(string $repository, string $flag, bool $enabled): self
    {
        $repository = trim($repository);
        if (!preg_match('~\A([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})\z~', $repository)
            || !in_array($flag, ['fix_issues', 'auto_ready', 'auto_merge'], true)) {
            throw new \InvalidArgumentException('A valid repository and maintainer setting are required.');
        }
        return self::updateOrCreate(['repository' => $repository], [$flag => $enabled]);
    }
}
