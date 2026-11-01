<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

use ProofPatch\Exceptions\AmbiguousPublicationException;
use ProofPatch\GitHub\DedicatedTestRepoPublisher;

/**
 * Publishes a verified candidate as a draft PR on the dedicated test repo.
 *
 * Assembles the publication context, performs the single GitHub write
 * sequence, and reconciles read-only when the write state is ambiguous. Has
 * no HTTP, session, or task access; the controller claims the task first and
 * finalizes it from the result. Never retries a write.
 */
final class PublishDraftPrUseCase
{
    public function __construct(private DedicatedTestRepoPublisher $publisher)
    {
    }

    public function execute(PublishDraftPrInput $in): PublishDraftPrResult
    {
        $publicationContext = $in->context;
        if (($in->suggestion['isOfflineExample'] ?? false) === true) {
            $publicationContext['proposalOrigin'] = 'offline_reference';
        } elseif (!empty($in->suggestion['model'])) {
            $publicationContext['proposalOrigin'] = 'muse';
            $publicationContext['model'] = $in->suggestion['model'];
            $publicationContext['usage'] = $in->suggestion['usage'] ?? null;
            $publicationContext['isPrivateDedicated'] = $in->isPrivateDedicated;
        } else {
            $publicationContext['proposalOrigin'] = 'unspecified';
        }

        try {
            return PublishDraftPrResult::published($this->publisher->publishDraftPr(
                $publicationContext,
                $in->evidenceDir,
                $in->candidateSource,
                $in->authToken,
                $in->githubToken
            ));
        } catch (AmbiguousPublicationException $ambiguousError) {
            // Ambiguous write state: attempt read-only reconciliation before failing closed
            $reconciled = null;
            try {
                $reconciled = $this->publisher->reconcileExistingPr($in->githubToken, $ambiguousError->branch);
            } catch (\Throwable) {
                $reconciled = null;
            }

            if ($reconciled !== null) {
                // Read-only check verified PR was actually created on GitHub
                $reconciled['receiptSha256'] = (string) ($in->report['receipt']['sha256'] ?? '');
                $reconciled['verificationRunId'] = (string) ($in->report['id'] ?? '');

                return PublishDraftPrResult::reconciled($reconciled);
            }

            // Could not confirm PR exists: fail closed with honest outcome-unknown state.
            // Preserve publication claim and deterministic branch identity; do NOT release claim or repeat writes.
            return PublishDraftPrResult::outcomeUnknown(
                $ambiguousError->repository,
                $ambiguousError->branch,
                $ambiguousError->runId,
                $ambiguousError->getMessage()
            );
        } catch (\Throwable $publishError) {
            // Pre-write failure: safe to release publication claim
            return PublishDraftPrResult::blocked('PR Publication blocked: ' . $publishError->getMessage());
        }
    }
}
