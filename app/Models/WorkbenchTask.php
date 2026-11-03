<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkbenchTask extends Model
{
    protected $table = 'workbench_tasks';

    protected $guarded = [];

    protected $casts = [
        'is_dedicated' => 'boolean',
        'is_synthetic' => 'boolean',
        'is_offline_example' => 'boolean',
        'is_simulated' => 'boolean',
        'context_payload' => 'array',
        'metadata' => 'array',
        'issue_number' => 'integer',
        'published_pr_number' => 'integer',
        'version' => 'integer',
        'github_user_id' => 'integer',
    ];

    public static function computeKey(string $repository, int $issueNumber, string $path, ?string $commit = null): string
    {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        $key = "{$repository}#{$issueNumber}:{$normalizedPath}";
        if ($commit !== null && trim($commit) !== '') {
            $key .= '@' . substr(trim($commit), 0, 12);
        }
        return $key;
    }

    public static function findByContext(array $context, ?int $githubUserId = null): ?self
    {
        $repo = (string) ($context['repository'] ?? '');
        $issueNum = (int) ($context['issueNumber'] ?? 0);
        $path = (string) ($context['path'] ?? 'src/OrderTotal.php');
        $commit = isset($context['commit']) && is_string($context['commit']) ? $context['commit'] : null;

        // Match the complete stored identity, including a missing revision.
        // Legacy keys are supported through their stored identity fields.
        $query = self::where('repository', $repo)
            ->where('issue_number', $issueNum)
            ->where('path', $path);

        $githubUserId === null ? $query->whereNull('github_user_id') : $query->where('github_user_id', $githubUserId);

        $commit === null ? $query->whereNull('commit') : $query->where('commit', $commit);

        return $query->first();
    }

    public static function recordIntake(
        array $context,
        bool $isDedicated = false,
        ?int $expectedVersion = null,
        ?int $githubUserId = null
    ): self
    {
        $repo = (string) ($context['repository'] ?? 'unknown/repo');
        $issueNum = (int) ($context['issueNumber'] ?? 1);
        $path = (string) ($context['path'] ?? 'src/OrderTotal.php');
        $commit = isset($context['commit']) && is_string($context['commit']) ? $context['commit'] : null;
        $sourceSha = (string) ($context['sourceSha256'] ?? '');

        $key = self::computeKey($repo, $issueNum, $path, $commit);
        if ($githubUserId !== null) {
            $key .= ':user-' . $githubUserId;
        }

        $task = self::findByContext($context, $githubUserId);

        if ($task === null) {
            try {
                $task = new self();
                $task->key = $key;
                $task->github_user_id = $githubUserId;
                $task->repository = $repo;
                $task->issue_number = $issueNum;
                $task->issue_url = $context['issueUrl'] ?? null;
                $task->title = (string) ($context['title'] ?? "Issue #{$issueNum}");
                $task->body = (string) ($context['body'] ?? '');
                $task->path = $path;
                $task->commit = $commit;
                $task->provenance = $context['provenance'] ?? null;
                $task->status = 'queued';
                $task->is_dedicated = $isDedicated;
                $task->is_synthetic = (bool) ($context['isSynthetic'] ?? false);
                $task->context_payload = $context;
                $task->version = 1;
                $task->save();
                return $task;
            } catch (\Illuminate\Database\QueryException $e) {
                $task = self::findByContext($context, $githubUserId);
                if ($task === null) {
                    throw $e;
                }
            }
        }

        // Task already exists for this exact case.
        // Check if underlying context has changed (e.g. source file modified or commit changed)
        $existingSourceSha = $task->context_payload['sourceSha256'] ?? null;
        $existingCommit = $task->commit;

        $contextChanged = ($existingSourceSha !== null && $sourceSha !== '' && !hash_equals($existingSourceSha, $sourceSha))
            || ($existingCommit !== null && $commit !== null && !hash_equals($existingCommit, $commit));

        if ($contextChanged) {
            throw new \InvalidArgumentException('Source changed for this task revision; existing evidence was preserved.');
        }

        // Publishing-state lock: reject overlapping intake while publication is in progress
        if (in_array($task->status, ['publishing', 'outcome_unknown'], true)) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Intake blocked: Task #{$task->id} currently has an active publication claim in progress."
            );
        }

        if ($expectedVersion !== null && (int) ($task->version ?? 1) !== $expectedVersion) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Stale intake rejected: Task #{$task->id} was modified concurrently or is currently publishing."
            );
        }

        $newTitle = (string) ($context['title'] ?? $task->title ?? "Issue #{$issueNum}");
        $newBody = (string) ($context['body'] ?? $task->body ?? '');
        $newIssueUrl = $context['issueUrl'] ?? $task->issue_url;
        $newProvenance = $context['provenance'] ?? $task->provenance;

        $isDirty = ($task->title !== $newTitle)
            || ($task->body !== $newBody)
            || ($task->issue_url !== $newIssueUrl)
            || ($task->provenance !== $newProvenance)
            || ($task->is_dedicated !== $isDedicated);

        if ($isDirty) {
            $targetVersion = $expectedVersion ?? (int) ($task->version ?? 1);
            $query = self::where('id', $task->id)
                ->where('version', $targetVersion)
                ->whereNotIn('status', ['publishing', 'outcome_unknown']);

            if ($commit !== null) {
                $query->where('commit', $commit);
            } else {
                $query->whereNull('commit');
            }

            $updated = $query->update([
                'title' => $newTitle,
                'body' => $newBody,
                'issue_url' => $newIssueUrl,
                'provenance' => $newProvenance,
                'is_dedicated' => $isDedicated,
                'context_payload' => $context,
                'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

            if ($updated === 0) {
                throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                    "Stale intake rejected: Task #{$task->id} was modified concurrently or is currently publishing."
                );
            }

            $task->refresh();
        }

        return $task;
    }

    public function recordProposal(
        array $suggestion,
        ?int $expectedVersion = null,
        ?string $expectedSourceSha = null
    ): void {
        $targetVersion = $expectedVersion ?? (int) ($this->version ?? 1);
        $query = self::where('id', $this->id)
            ->where('version', $targetVersion)
            ->whereNotIn('status', ['publishing', 'outcome_unknown']);

        if ($expectedSourceSha !== null && $expectedSourceSha !== '') {
            $taskSource = $this->context_payload['sourceSha256'] ?? null;
            if ($taskSource !== null && !hash_equals($taskSource, $expectedSourceSha)) {
                throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                    "Stale proposal rejected: Source digest mismatch for task #{$this->id}."
                );
            }
        }

        $proposalDigest = $suggestion['requestDigest'] ?? null;
        $isOfflineExample = (bool) ($suggestion['isOfflineExample'] ?? false);
        $candidate = $suggestion['candidate'] ?? [];
        $candidateSource = $candidate['source'] ?? null;
        $candidateSha = $candidate['sha256'] ?? null;
        $metadata = array_merge($this->metadata ?? [], [
            'model' => $suggestion['model'] ?? null,
            'explanation' => $suggestion['explanation'] ?? null,
            'find' => $suggestion['find'] ?? null,
            'replace' => $suggestion['replace'] ?? null,
            'usage' => $suggestion['usage'] ?? null,
            'privateProvenanceId' => $suggestion['privateProvenanceId'] ?? null,
            'privateProvenanceRecorded' => $suggestion['privateProvenanceRecorded'] ?? null,
        ]);

        $now = now();
        $updated = $query->update([
            'status' => 'in_progress',
            'proposal_digest' => $proposalDigest,
            'is_offline_example' => $isOfflineExample,
            'candidate_source' => $candidateSource,
            'candidate_sha256' => $candidateSha,
            'verification_report_id' => null,
            'verification_decision' => null,
            'is_simulated' => false,
            'published_pr_number' => null,
            'published_pr_url' => null,
            'published_branch' => null,
            'published_receipt_sha' => null,
            'metadata' => $metadata,
            'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Stale proposal rejected: Task #{$this->id} was modified concurrently (expected version {$targetVersion})."
            );
        }

        $cardId = $this->metadata['local_task_card_id'] ?? null;
        if (is_int($cardId) && $cardId > 0) {
            LocalTaskCard::whereKey($cardId)->update(['status' => 'in_progress', 'updated_at' => $now]);
        }

        $this->refresh();
    }

    /** Save finite fixture observations without treating them as a verified run. */
    public function recordFixtureObservation(array $observation, string $runId, int $expectedVersion, string $candidateSha): void
    {
        if (($observation['status'] ?? null) !== 'passed' || ($observation['scope'] ?? null) !== 'finite_local_fixture'
            || ($observation['provider'] ?? null) !== 'modal' || ($observation['candidateSha256'] ?? null) !== $candidateSha
            || !preg_match('/\Afixture-[a-f0-9]{24}\z/', $runId)) {
            throw new \InvalidArgumentException('A matching completed fixture observation is required.');
        }
        $metadata = $this->metadata ?? [];
        $metadata['fixture_observation'] = $observation + ['runId' => $runId];
        $updated = self::where('id', $this->id)->where('version', $expectedVersion)
            ->where('candidate_sha256', $candidateSha)->whereNotIn('status', ['publishing', 'outcome_unknown'])
            ->update(['verification_report_id' => $runId, 'verification_decision' => 'OBSERVED_PASS',
                'is_simulated' => false, 'status' => 'needs_review', 'metadata' => $metadata,
                'version' => \Illuminate\Support\Facades\DB::raw('version + 1'), 'updated_at' => now()]);
        if ($updated !== 1) throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException('The candidate changed while checks were running.');
        $this->refresh();
    }

    public function recordVerification(
        array $report,
        ?int $expectedVersion = null,
        ?string $expectedCandidateSha = null
    ): void {
        $targetVersion = $expectedVersion ?? (int) ($this->version ?? 1);
        $query = self::where('id', $this->id)
            ->where('version', $targetVersion)
            ->whereNotIn('status', ['publishing', 'outcome_unknown']);

        if ($expectedCandidateSha !== null && $expectedCandidateSha !== '') {
            $query->where('candidate_sha256', $expectedCandidateSha);
        }

        $decision = $report['decision'] ?? 'FAILED';
        if (isset($report['isSimulated'])) {
            $isSimulated = (bool) $report['isSimulated'];
        } else {
            $isSimulated = (bool) (
                ($report['backendSandboxGap']['status'] ?? '') === 'allowlisted_bounded_demonstration'
                || empty($report['publicationAuthorizationToken'])
                || !($report['runs']['candidate']['isolated'] ?? false)
            );
        }
        $status = ($decision === 'VERIFIED') ? 'needs_review' : 'failed';

        $now = now();
        $updated = $query->update([
            'candidate_sha256' => $expectedCandidateSha ?: $this->candidate_sha256,
            'verification_report_id' => $report['id'] ?? null,
            'verification_decision' => $decision,
            'is_simulated' => $isSimulated,
            'status' => $status,
            'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Stale verification rejected: Candidate or task state was modified concurrently while verification was running."
            );
        }

        $cardId = $this->metadata['local_task_card_id'] ?? null;
        if (is_int($cardId) && $cardId > 0) {
            LocalTaskCard::whereKey($cardId)->update(['status' => $status, 'updated_at' => $now]);
        }

        $this->refresh();
    }

    public function claimPublication(
        int $expectedVersion,
        string $expectedCandidateSha,
        string $expectedRunId
    ): void {
        if ($expectedCandidateSha === '' || $expectedRunId === '') {
            throw new \InvalidArgumentException('Exact non-null candidate SHA and verification run ID are required to claim publication.');
        }

        $query = self::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->where('status', '=', 'needs_review')
            ->where('verification_report_id', $expectedRunId)
            ->where('candidate_sha256', $expectedCandidateSha);

        $now = now();
        $updated = $query->update([
            'status' => 'publishing',
            'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Publication claim rejected: Task #{$this->id} was modified concurrently, is already publishing, or verification run/candidate hash mismatch."
            );
        }

        $this->refresh();
    }

    public function releasePublicationClaim(int $expectedVersion): void
    {
        $updated = self::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->where('status', 'publishing')
            ->update([
                'status' => 'needs_review',
                'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            $this->refresh();
        }
    }

    public function recordPublication(
        array $prResult,
        ?int $expectedVersion = null,
        ?string $expectedRunId = null,
        ?string $expectedCandidateSha = null
    ): void {
        $targetVersion = $expectedVersion ?? (int) ($this->version ?? 1);
        $query = self::where('id', $this->id)->where('version', $targetVersion);

        if ($expectedRunId !== null && $expectedRunId !== '') {
            $query->where('verification_report_id', $expectedRunId);
        }

        if ($expectedCandidateSha !== null && $expectedCandidateSha !== '') {
            $query->where('candidate_sha256', $expectedCandidateSha);
        }

        $now = now();
        $updated = $query->update([
            'published_pr_number' => (int) ($prResult['prNumber'] ?? 0),
            'published_pr_url' => $prResult['prUrl'] ?? null,
            'published_branch' => $prResult['branch'] ?? null,
            'published_receipt_sha' => $prResult['receiptSha256'] ?? null,
            'status' => 'completed',
            'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException(
                "Stale publication rejected: Task #{$this->id} was modified concurrently while PR was being published."
            );
        }

        $cardId = $this->metadata['local_task_card_id'] ?? null;
        if (is_int($cardId) && $cardId > 0) {
            LocalTaskCard::whereKey($cardId)->update(['status' => 'completed', 'updated_at' => $now]);
        }

        $this->refresh();
    }

    public function recordOutcomeUnknown(string $branch, string $runId, string $errorMessage, int $expectedVersion): void
    {
        $meta = $this->metadata ?? [];
        $meta['outcome_unknown'] = [
            'branch' => $branch,
            'runId' => $runId,
            'error' => $errorMessage,
            'timestamp' => gmdate(DATE_ATOM),
        ];

        $updated = self::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->where('status', 'publishing')
            ->where('verification_report_id', $runId)
            ->update([
                'status' => 'outcome_unknown',
                'published_branch' => $branch,
                'metadata' => $meta,
                'version' => \Illuminate\Support\Facades\DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new \ProofPatch\Exceptions\StaleTaskConcurrencyException('Publication outcome could not be attached to a changed task.');
        }
        $this->refresh();
    }

    public function toBoardArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'version' => (int) ($this->version ?? 1),
            'repository' => $this->repository,
            'issueNumber' => $this->issue_number,
            'issueUrl' => $this->issue_url,
            'title' => $this->title,
            'path' => $this->path,
            'status' => $this->status,
            'provenance' => $this->provenance,
            'isDedicated' => $this->is_dedicated,
            'isSynthetic' => $this->is_synthetic,
            'isOfflineExample' => $this->is_offline_example,
            'isSimulated' => $this->is_simulated,
            'verificationDecision' => $this->verification_decision,
            'model' => $this->metadata['model'] ?? null,
            'publishedPrNumber' => $this->published_pr_number,
            'publishedPrUrl' => $this->published_pr_url,
            'publishedBranch' => $this->published_branch,
            'updatedAt' => $this->updated_at?->toIso8601String() ?? gmdate(DATE_ATOM),
        ];
    }
}
