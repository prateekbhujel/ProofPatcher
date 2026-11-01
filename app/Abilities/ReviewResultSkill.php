<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;
use Illuminate\Support\Facades\DB;
use ProofPatch\Models\AutoReviewResult;

/**
 * Skill review.result v1: every read of automatic review outcomes.
 * Three modes (first match wins): stats | exact revision | filtered list.
 * Read-only; rows are an explicit projection (stable keys, no relations).
 */
final class ReviewResultSkill implements Skill
{
    public const STATUSES = ['completed', 'failed', 'skipped_private', 'skipped_budget', 'skipped_opted_out', 'in_progress', 'unknown'];

    private const REPO_PATTERN = '~\A[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}\z~';

    public function id(): string
    {
        return 'review.result';
    }

    public function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['review.read'];
    }

    public function describe(): string
    {
        return 'Reads automatic review outcomes from the local store: counts, one revision, or a filtered list. No model calls, no GitHub writes.';
    }

    public function invoke(array $input): array
    {
        $statsFor = $input['stats_for'] ?? null;
        if ($statsFor !== null) {
            return $this->stats($input);
        }
        $repository = $input['repository'] ?? null;
        $pullNumber = $input['pull_number'] ?? null;
        $headSha = $input['head_sha'] ?? null;
        $baseSha = $input['base_sha'] ?? null;
        if (is_string($repository) && is_int($pullNumber) && is_string($headSha) && is_string($baseSha)) {
            return $this->exact($repository, $pullNumber, $headSha, $baseSha);
        }
        return $this->list($input);
    }

    /** @return array<string, mixed> */
    private function stats(array $input): array
    {
        $statsFor = $input['stats_for'] ?? null;
        $match = $input['match'] ?? 'lower';
        if (!is_array($statsFor) || $statsFor === [] || count($statsFor) > 2000) {
            throw new HariError('invalid_input', 'Stats repository list is invalid.');
        }
        foreach ($statsFor as $repo) {
            if (!is_string($repo) || !preg_match(self::REPO_PATTERN, $repo)) {
                throw new HariError('invalid_input', 'Stats repository is invalid.');
            }
        }
        if ($match !== 'exact' && $match !== 'lower') {
            throw new HariError('invalid_input', 'Stats match mode is invalid.');
        }
        try {
            $column = $match === 'lower' ? DB::raw('LOWER(repository)') : 'repository';
            $keys = $match === 'lower' ? array_map(strtolower(...), $statsFor) : $statsFor;
            return ['mode' => 'stats', 'stats' => [
                'total' => AutoReviewResult::whereIn($column, $keys)->count(),
                'completed' => AutoReviewResult::whereIn($column, $keys)->where('status', 'completed')->count(),
                'failed' => AutoReviewResult::whereIn($column, $keys)->where('status', 'failed')->count(),
                'skipped_private' => AutoReviewResult::whereIn($column, $keys)->where('status', 'skipped_private')->count(),
                'skipped_budget' => AutoReviewResult::whereIn($column, $keys)->where('status', 'skipped_budget')->count(),
            ]];
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Review skill failed.');
        }
    }

    /** @return array<string, mixed> */
    private function exact(string $repository, int $pullNumber, string $headSha, string $baseSha): array
    {
        if (!preg_match(self::REPO_PATTERN, $repository) || $pullNumber < 1
            || !preg_match('/\A[a-f0-9]{40}\z/', $headSha) || !preg_match('/\A[a-f0-9]{40}\z/', $baseSha)) {
            throw new HariError('invalid_input', 'Revision lookup is invalid.');
        }
        try {
            $row = AutoReviewResult::findForRevision($repository, $pullNumber, $headSha, $baseSha);
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Review skill failed.');
        }
        return ['mode' => 'exact', 'found' => $row !== null,
            'rows' => $row === null ? [] : [$this->shape($row)]];
    }

    /** @return array<string, mixed> */
    private function list(array $input): array
    {
        $repository = $input['repository'] ?? null;
        $repositories = $input['repositories'] ?? null;
        $pullNumber = $input['pull_number'] ?? null;
        $status = $input['status'] ?? null;
        $limit = $input['limit'] ?? 10;
        if ($repository !== null && (!is_string($repository) || !preg_match(self::REPO_PATTERN, $repository))) {
            throw new HariError('invalid_input', 'Repository is invalid.');
        }
        if ($repositories !== null) {
            if (!is_array($repositories) || $repositories === [] || count($repositories) > 2000) {
                throw new HariError('invalid_input', 'Repository list is invalid.');
            }
            foreach ($repositories as $repo) {
                if (!is_string($repo) || !preg_match(self::REPO_PATTERN, $repo)) {
                    throw new HariError('invalid_input', 'Repository list is invalid.');
                }
            }
        }
        if ($pullNumber !== null && (!is_int($pullNumber) || $pullNumber < 1)) {
            throw new HariError('invalid_input', 'Pull number is invalid.');
        }
        if ($status !== null && (!is_string($status) || !in_array($status, self::STATUSES, true))) {
            throw new HariError('invalid_input', 'Status is invalid.');
        }
        if (!is_int($limit) || $limit < 1 || $limit > 5000) {
            throw new HariError('invalid_input', 'Limit is invalid.');
        }
        try {
            // Case-insensitive repository match: GitHub full names that differ
            // only by case are the same authorized repository.
            $query = AutoReviewResult::orderByDesc('id');
            if (is_string($repository)) {
                $query->where(DB::raw('LOWER(repository)'), strtolower($repository));
            } elseif (is_array($repositories)) {
                $query->whereIn(DB::raw('LOWER(repository)'), array_map(strtolower(...), $repositories));
            }
            if (is_int($pullNumber)) {
                $query->where('pull_number', $pullNumber);
            }
            if (is_string($status)) {
                $query->where('status', $status);
            }
            $rows = [];
            foreach ($query->limit($limit)->get() as $row) {
                $rows[] = $this->shape($row);
            }
            return ['mode' => 'list', 'found' => true, 'rows' => $rows];
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Review skill failed.');
        }
    }

    /** @return array<string, mixed> */
    private function shape(AutoReviewResult $row): array
    {
        $fileFindings = is_array($row->file_findings) ? array_values(array_filter(
            $row->file_findings, static fn (mixed $finding): bool => is_array($finding)
        )) : null;
        $findings = [];
        foreach ($fileFindings ?? [] as $finding) {
            $findings[] = [
                'path' => is_string($finding['path'] ?? null) ? $finding['path'] : '',
                'line' => $finding['lineHint'] ?? $finding['line'] ?? null,
                'status' => is_string($finding['status'] ?? null) ? $finding['status'] : '',
                'comment' => is_string($finding['comment'] ?? null) ? $finding['comment'] : '',
                'suggestion' => is_string($finding['suggestion'] ?? null) ? $finding['suggestion'] : null,
            ];
        }
        $rawUsage = $row->usage;
        $usage = is_array($rawUsage) ? $rawUsage : null;
        $promptTokens = is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null;
        $completionTokens = is_array($usage) ? ($usage['completion_tokens'] ?? null) : null;
        return [
            'id' => (int) $row->id,
            'repository' => (string) $row->repository,
            'pull_number' => (int) $row->pull_number,
            'head_sha' => (string) $row->head_sha,
            'base_sha' => (string) $row->base_sha,
            'status' => (string) $row->status,
            'is_public' => (bool) $row->is_public,
            'recommendation' => is_string($row->recommendation) ? $row->recommendation : null,
            'risk_level' => is_string($row->risk_level) ? $row->risk_level : null,
            'summary' => is_string($row->summary) ? $row->summary : null,
            'file_findings' => $fileFindings,
            'findings' => $findings,
            'findings_count' => count($findings),
            'raw_review' => is_array($row->raw_review) ? $row->raw_review : null,
            'usage' => $usage === null ? null : [
                'prompt_tokens' => is_int($promptTokens) ? $promptTokens : null,
                'completion_tokens' => is_int($completionTokens) ? $completionTokens : null,
            ],
            'error_message' => is_string($row->error_message) ? $row->error_message : null,
            'published_status' => is_string($row->published_status) ? $row->published_status : null,
            'model' => (string) $row->model,
            'input_digest' => is_string($row->input_digest) ? $row->input_digest : null,
            'reviewed_at' => $row->reviewed_at?->toISOString() ?? null,
            'reviewed_at_display' => $row->reviewed_at?->format('Y-m-d H:i') ?? null,
            'created_at' => $row->created_at?->toISOString() ?? null,
            'updated_at' => $row->updated_at?->toISOString() ?? null,
        ];
    }
}
