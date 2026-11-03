<?php

declare(strict_types=1);

namespace ProofPatch\Support;

use Illuminate\Http\Request;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\Evidence\Receipt;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Models\WorkbenchTask;

/**
 * Shared helper for workbench task lifecycle, session hydration, optimistic concurrency, and evidence storage.
 */
final class TaskWorkflowHelper
{
    public static function resolveGitHubToken(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $auth = $request->session()->get('github_auth');
        if (is_array($auth) && isset($auth['token']) && is_string($auth['token']) && trim($auth['token']) !== '') {
            return trim($auth['token']);
        }

        return null;
    }

    public static function resolveContextHeadSha(array $context): string
    {
        $commit = $context['commit'] ?? null;
        if (is_string($commit) && preg_match('/\A[a-f0-9]{40}\z/', $commit)) {
            return $commit;
        }

        return substr(hash('sha256', 'synthetic-fixture:' . ($context['repository'] ?? 'order-total')), 0, 40);
    }

    public static function hydrateTaskSession(Request $request, WorkbenchTask $task): void
    {
        $session = $request->session();
        $session->put('issue_task_id', $task->id);

        $context = $task->context_payload;
        if (is_array($context)) {
            $session->put('issue_context', $context);
            $session->put('issue_is_owned_fixture', (bool) ($context['isSynthetic'] ?? false) || (bool) $task->is_dedicated);
            $session->put('issue_is_dedicated_test_repo', $task->is_dedicated);
        }

        if ($task->candidate_source !== null) {
            $meta = $task->metadata ?? [];
            $suggestion = [
                'status' => $task->is_offline_example ? 'offline_reference_example' : 'ready',
                'isOfflineExample' => $task->is_offline_example,
                'isSynthetic' => $task->is_synthetic,
                'isDedicated' => $task->is_dedicated,
                'provenance' => $task->provenance,
                'repository' => $task->repository,
                'issueNumber' => $task->issue_number,
                'commit' => $task->commit,
                'path' => $task->path,
                'sourceSha256' => $context['sourceSha256'] ?? '',
                'find' => $meta['find'] ?? "max(1, \$item['quantity'])",
                'replace' => $meta['replace'] ?? "\$item['quantity']",
                'explanation' => $meta['explanation'] ?? ($task->is_offline_example ? 'Offline reference example for OrderTotal boundary calculation. No external provider call was made.' : 'Restored task candidate proposal.'),
                'model' => $meta['model'] ?? null,
                'requestDigest' => $task->proposal_digest,
                'usage' => $meta['usage'] ?? null,
                'privateProvenanceId' => $meta['privateProvenanceId'] ?? null,
                'privateProvenanceRecorded' => $meta['privateProvenanceRecorded'] ?? null,
                'candidate' => [
                    'status' => $task->verification_decision === 'VERIFIED' ? 'verified_allowlisted_candidate' : 'unverified_in_memory_preview',
                    'source' => $task->candidate_source,
                    'sha256' => $task->candidate_sha256,
                    'applied' => false,
                    'verified' => $task->verification_decision === 'VERIFIED',
                ],
            ];
            $session->put('issue_suggestion', $suggestion);
        }

        $observation = ($task->metadata ?? [])['fixture_observation'] ?? null;
        if (is_array($observation) && ($observation['scope'] ?? null) === 'finite_local_fixture'
            && ($observation['runId'] ?? null) === $task->verification_report_id
            && is_string($task->candidate_sha256) && ($observation['candidateSha256'] ?? null) === $task->candidate_sha256) {
            // A session-only publication token is not recovered from task metadata.
            $observation['canPublish'] = false;
            $session->put('issue_fixture_observation', $observation);
        }

        if ($task->verification_report_id !== null) {
            $evidenceDir = RuntimePaths::runs(base_path()).'/'.$task->verification_report_id;
            if (is_dir($evidenceDir) && file_exists($evidenceDir . '/result.json')) {
                try {
                    $receiptHelper = new Receipt();
                    $verifiedReceipt = $receiptHelper->verify($evidenceDir);
                    if ($verifiedReceipt['integrity'] === 'VALID') {
                        $resultContent = (string) file_get_contents($evidenceDir . '/result.json');
                        $report = json_decode($resultContent, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($report) && ($report['id'] ?? '') === $task->verification_report_id) {
                            $receiptPath = $evidenceDir . '/receipt.json';
                            if (file_exists($receiptPath)) {
                                $receiptData = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
                                if (is_array($receiptData) && isset($receiptData['sha256'], $receiptData['manifest'])) {
                                    $report['receipt'] = [
                                        'id' => $receiptData['manifest']['runId'] ?? $task->verification_report_id,
                                        'sha256' => $receiptData['sha256'],
                                        'integrity' => $verifiedReceipt['integrity'],
                                        'externallyAnchored' => $verifiedReceipt['externallyAnchored'] ?? false,
                                        'manifest' => $receiptData['manifest'],
                                    ];
                                }
                            }

                            if (isset($report['receipt'])) {
                                $session->put('issue_verification_report', $report);

                                if ($task->verification_decision === 'VERIFIED' && !$task->is_simulated && $task->published_pr_number === null) {
                                    $approvalStore = new ServerHeldPublicationApproval(storage_path('app/publication_approvals'));
                                    $activeToken = $approvalStore->findActiveTokenForRun(
                                        $task->verification_report_id,
                                        (string) $task->candidate_sha256
                                    );
                                    if ($activeToken !== null) {
                                        $session->put('issue_publication_token', $activeToken);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        if ($task->published_pr_number !== null) {
            $session->put('issue_published_pr', [
                'prNumber' => $task->published_pr_number,
                'prUrl' => $task->published_pr_url,
                'branch' => $task->published_branch,
                'receiptSha256' => $task->published_receipt_sha,
                'verificationRunId' => $task->verification_report_id,
                'status' => 'success',
                'repository' => $task->repository,
                'publishedAt' => $task->updated_at?->toIso8601String() ?? gmdate(DATE_ATOM),
            ]);
        }
    }

    public static function resolveAndValidateTask(
        Request $request,
        array $context,
        ?string $expectedCandidateSha = null,
        ?string $expectedRunId = null
    ): WorkbenchTask {
        $taskId = $request->session()->get('issue_task_id');
        $task = null;
        if ($taskId !== null) {
            $task = WorkbenchTask::find($taskId);
            if ($task === null) {
                throw new \RuntimeException('Stale context write rejected: Active task no longer exists.');
            }
        }
        if ($task === null) {
            $task = WorkbenchTask::findByContext($context);
        }

        if ($task === null) {
            $task = WorkbenchTask::recordIntake($context, (bool) $request->session()->get('issue_is_dedicated_test_repo'));
            $request->session()->put('issue_task_id', $task->id);
        }

        // 1. Validate repository, issue number, and target file path
        if ($task->repository !== ($context['repository'] ?? '')
            || (int) $task->issue_number !== (int) ($context['issueNumber'] ?? 0)
            || $task->path !== ($context['path'] ?? '')) {
            throw new \RuntimeException('Stale session write rejected: Task target path or repository mismatch.');
        }

        // 2. Validate base revision / commit
        $expectedCommit = $context['commit'] ?? null;
        if ($expectedCommit !== null) {
            if ($task->commit === null || !hash_equals($task->commit, $expectedCommit)) {
                throw new \RuntimeException('Stale session write rejected: Base revision mismatch.');
            }
        } elseif ($task->commit !== null) {
            throw new \RuntimeException('Stale session write rejected: Base revision mismatch.');
        }

        // 3. Validate source file digest - EXACT NON-NULL REQUIRED
        $taskSourceSha = $task->context_payload['sourceSha256'] ?? null;
        $contextSourceSha = $context['sourceSha256'] ?? null;
        if ($taskSourceSha === null || $contextSourceSha === null || !hash_equals($taskSourceSha, $contextSourceSha)) {
            throw new \RuntimeException('Stale session write rejected: Source file digest mismatch or missing.');
        }

        // 4. Validate candidate SHA if applicable - EXACT NON-NULL REQUIRED
        if ($expectedCandidateSha !== null) {
            if ($task->candidate_sha256 === null || !hash_equals($task->candidate_sha256, $expectedCandidateSha)) {
                throw new \RuntimeException('Stale session write rejected: Candidate digest mismatch or candidate not established for active task.');
            }
        }

        // 5. Validate verification run ID if applicable - EXACT NON-NULL REQUIRED
        if ($expectedRunId !== null) {
            if ($task->verification_report_id === null || $task->verification_report_id !== $expectedRunId) {
                throw new \RuntimeException('Stale session write rejected: Verification run ID mismatch or verification not established for active task.');
            }
        }

        return $task;
    }

    public static function storeDurableRecoveryEvidence(
        WorkbenchTask $task,
        int $claimedVersion,
        string $runId,
        array $prResult,
        string $dbErrorMessage
    ): void {
        try {
            $dir = storage_path('app/publication_recovery');
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $sanitizedRunId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId);
            $file = $dir . "/task_{$task->id}_{$sanitizedRunId}.json";
            $data = json_encode([
                'taskId' => $task->id,
                'taskKey' => $task->key,
                'claimedVersion' => $claimedVersion,
                'prResult' => $prResult,
                'dbError' => $dbErrorMessage,
                'timestamp' => gmdate(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            @file_put_contents($file, $data, LOCK_EX);
            @chmod($file, 0600);
        } catch (\Throwable) {
        }
    }

    public static function consumeProposalApproval(
        Request $request,
        array $context,
        string $digest,
        SingleUseReviewApproval $approvalStore
    ): void {
        $approvalToken = (string) $request->input('approval_token', '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $approvalToken)) {
            throw new \InvalidArgumentException('A valid server-held approval token is required for live model proposals.');
        }

        $headSha = self::resolveContextHeadSha($context);
        $approvalStore->consume(
            $approvalToken,
            (string) $context['repository'],
            (int) $context['issueNumber'],
            $headSha,
            $digest
        );
        $request->session()->forget('issue_approval_token');
    }
}
