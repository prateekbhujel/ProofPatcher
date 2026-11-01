<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ProofPatch\GitHub\AutomaticInlineCommentPublisher;

final class AutoReviewResult extends Model
{
    protected $table = 'auto_review_results';

    protected $fillable = [
        'repository',
        'pull_number',
        'head_sha',
        'base_sha',
        'status',
        'is_public',
        'summary',
        'risk_level',
        'recommendation',
        'file_findings',
        'raw_review',
        'usage',
        'error_message',
        'published_status',
        'model',
        'input_digest',
        'reviewed_at',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'file_findings' => 'array',
        'raw_review' => 'array',
        'usage' => 'array',
        'reviewed_at' => 'datetime',
    ];

    /** The attempt is persisted before POST, so it survives a worker crash. */
    public function getPublishedStatusAttribute(?string $stored): ?string
    {
        if (($this->attributes['status'] ?? null) !== 'completed') return $stored;
        $repository = $this->attributes['repository'] ?? null;
        $number = $this->attributes['pull_number'] ?? null;
        $head = $this->attributes['head_sha'] ?? null;
        $base = $this->attributes['base_sha'] ?? null;
        if (!is_string($repository) || !is_numeric($number) || !is_string($head) || !is_string($base)) {
            return 'publication_unknown';
        }
        try {
            $attempt = DB::table('automatic_inline_attempts')->where('revision_key',
                AutomaticInlineCommentPublisher::revisionKey($repository, (int) $number, $head, $base))->value('status');
        } catch (\Throwable) {
            // Without the attempt ledger, the app cannot know if GitHub got a POST.
            return 'publication_unknown';
        }
        return match ($attempt) {
            'posted' => 'one_inline_comment',
            'uncertain' => 'publication_unknown',
            null => $stored,
            default => 'publication_unknown',
        };
    }

    public static function findForRevision(string $repository, int $pullNumber, string $headSha, string $baseSha): ?self
    {
        return self::where('repository', $repository)
            ->where('pull_number', $pullNumber)
            ->where('head_sha', $headSha)
            ->where('base_sha', $baseSha)
            ->latest('id')
            ->first();
    }

    public static function hasCompletedReview(string $repository, int $pullNumber, string $headSha, string $baseSha): bool
    {
        return self::where('repository', $repository)
            ->where('pull_number', $pullNumber)
            ->where('head_sha', $headSha)
            ->where('base_sha', $baseSha)
            ->where('status', 'completed')
            ->exists();
    }
}
