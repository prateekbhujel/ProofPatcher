<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

use ProofPatch\Agent\Credentials;
use ProofPatch\Agent\IssueSuggestion;
use ProofPatch\Agent\PrivateProposalStore;
use ProofPatch\Agent\RequestBudget;
use ProofPatch\Agent\ServerHeldModelProposal;
use ProofPatch\Agent\SingleFileCandidate;
use ProofPatch\Agent\SingleUseReviewApproval;
use ProofPatch\GitHub\DedicatedTestRepo;
use ProofPatch\GitHub\DedicatedTestRepoFetcher;
use ProofPatch\GitHub\PublicIssueContext;
use ProofPatch\Support\TaskWorkflowHelper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proposes a fix suggestion for the active workbench context.
 *
 * Owns the propose decision tree (dedicated test case, owned fixture, or
 * public task): approval checks, consent rechecks, live re-fetch before any
 * provider call, and the offline fallbacks. Has no HTTP or session access;
 * the controller supplies plain data and syncs session keys from the result.
 */
final class ProposeSuggestionUseCase
{
    public function __construct(
        private IssueSuggestion $suggestions,
        private RequestBudget $budget,
        private SingleUseReviewApproval $approvals,
        private ServerHeldModelProposal $proposals,
        private PrivateProposalStore $privateProposals,
        private HttpClientInterface $http,
        private string $ownedFixturePath,
    ) {
    }

    public function execute(ProposeSuggestionInput $in): ProposeSuggestionResult
    {
        $approvalConsumed = false;
        $privateApprovalConsumed = false;
        try {
            $context = $in->context;
            $digest = $in->digest;
            $budgetCanRequest = (bool) $this->budget->status()['canRequest'];
            if (!hash_equals($this->suggestions->preview($context)['digest'], $digest)) {
                throw new \InvalidArgumentException('Review and approve the exact request first.');
            }

            if ($in->isDedicated) {
                $suggestion = $this->proposeDedicated($in, $budgetCanRequest, $approvalConsumed, $privateApprovalConsumed);
            } elseif ($in->isOwned) {
                $suggestion = $this->proposeOwned($in, $budgetCanRequest, $approvalConsumed);
            } else {
                $suggestion = $this->proposeTask($in, $budgetCanRequest, $approvalConsumed);
            }

            return ProposeSuggestionResult::success($suggestion, $approvalConsumed, $privateApprovalConsumed);
        } catch (\Throwable $error) {
            return ProposeSuggestionResult::failure($error->getMessage(), $approvalConsumed, $privateApprovalConsumed);
        }
    }

    /** @param array<string,mixed> $context */
    private function proposeDedicated(ProposeSuggestionInput $in, bool $budgetCanRequest, bool &$consumed, bool &$privateConsumed): array
    {
        $context = $in->context;
        $digest = $in->digest;
        if (($context['sourceSha256'] ?? '') !== DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256) {
            throw new \RuntimeException('The dedicated test repository source file was modified.');
        }

        $hasKey = $this->hasProviderKey();
        $isPrivate = ModelContextPolicy::isPrivateDedicated($in->sessionAuth, $context);
        if ($isPrivate && !$in->offlineReference && (!$hasKey || !$budgetCanRequest)) {
            throw new \RuntimeException('Muse is unavailable or the local request allowance is exhausted.');
        }

        if ($hasKey && $budgetCanRequest && !$in->offlineReference) {
            if (!ModelContextPolicy::canSend($in->sessionAuth, $in->verifiedAccess, $context, $in->usesServerSession)) {
                throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
            }
            if ($isPrivate) {
                $this->checkPrivateConsent($in);
                // Recheck issue and fixed file/harness digests with the verified
                // GitHub identity immediately before the provider request.
                $current = (new DedicatedTestRepoFetcher($this->http))->fetchAndVerify((string) $in->githubToken);
            } else {
                $current = (new PublicIssueContext($this->http))->fetch($context['issueUrl'], $context['path']);
            }
            if (!hash_equals($this->suggestions->preview($current)['digest'], $digest)) {
                throw new \RuntimeException('The issue or repository changed. Preview the request again.');
            }
            $this->consumeApproval($in, $current);
            $consumed = true;
            $privateConsumed = $isPrivate;
            $suggestion = $this->suggestions->propose($current, $digest, Credentials::baseten(), !$isPrivate);
            $suggestion['commit'] = $context['commit'] ?? DedicatedTestRepo::requireBaseCommit();
            $suggestion['isSynthetic'] = false;
            $suggestion['isDedicated'] = true;
            $suggestion['provenance'] = 'Dedicated test repository';
            if ($isPrivate) {
                $suggestion['privateProvenanceRecorded'] = $this->recordPrivateProvenance($in, $current, $suggestion);
            }

            return $suggestion;
        }

        $serverProposal = null;
        if (is_string($in->requestedCandidateSha) && preg_match('/\A[a-f0-9]{64}\z/', $in->requestedCandidateSha)) {
            $serverProposal = $this->proposals->lookup($in->requestedCandidateSha);
        }

        if ($serverProposal !== null && hash_equals($serverProposal['sourceSha256'], $context['sourceSha256'])) {
            // Candidate was genuinely proposed by Meta Muse Spark on public fixture with exact matching source SHA-256
            return [
                'status' => 'suggested',
                'isOfflineExample' => false,
                'isSynthetic' => false,
                'isDedicated' => true,
                'provenance' => 'Dedicated test repository (candidate generated via Meta Muse Spark on public fixture)',
                'repository' => $context['repository'],
                'issueNumber' => $context['issueNumber'],
                'commit' => $context['commit'] ?? DedicatedTestRepo::requireBaseCommit(),
                'path' => $context['path'],
                'sourceSha256' => $context['sourceSha256'],
                'find' => $serverProposal['find'],
                'replace' => $serverProposal['replace'],
                'explanation' => $serverProposal['explanation'],
                'model' => $serverProposal['model'],
                'requestDigest' => $serverProposal['requestDigest'],
                'usage' => $serverProposal['usage'],
                'candidate' => [
                    'status' => 'unverified_in_memory_preview',
                    'source' => $serverProposal['candidateSource'],
                    'sha256' => $serverProposal['candidateSha256'],
                    'applied' => false,
                    'verified' => false,
                ],
            ];
        }

        // Offline reference proposal - strictly no model, no tokens, clearly labeled as offline reference
        $candidateSource = SingleFileCandidate::create(
            $context['source'],
            $context['sourceSha256'],
            "max(1, \$item['quantity'])",
            "\$item['quantity']",
        );

        return [
            'status' => 'offline_reference_example',
            'isOfflineExample' => true,
            'isSynthetic' => false,
            'isDedicated' => true,
            'provenance' => 'Dedicated test repository (offline reference example)',
            'repository' => $context['repository'],
            'issueNumber' => $context['issueNumber'],
            'commit' => $context['commit'] ?? DedicatedTestRepo::requireBaseCommit(),
            'path' => $context['path'],
            'sourceSha256' => $context['sourceSha256'],
            'find' => "max(1, \$item['quantity'])",
            'replace' => "\$item['quantity']",
            'explanation' => 'Offline reference example for OrderTotal zero-quantity bug. No external provider call was made.',
            'model' => null,
            'requestDigest' => $digest,
            'usage' => null,
            'candidate' => [
                'status' => 'unverified_in_memory_preview',
                'source' => $candidateSource,
                'sha256' => hash('sha256', $candidateSource),
                'applied' => false,
                'verified' => false,
            ],
        ];
    }

    private function proposeOwned(ProposeSuggestionInput $in, bool $budgetCanRequest, bool &$consumed): array
    {
        $context = $in->context;
        $digest = $in->digest;
        // Verify local fixture integrity
        if (!file_exists($this->ownedFixturePath) || hash_file('sha256', $this->ownedFixturePath) !== $context['sourceSha256']) {
            throw new \RuntimeException('The local fixture file was modified.');
        }

        $hasKey = $this->hasProviderKey();
        if ($hasKey && $budgetCanRequest && !$in->offlineReference) {
            if (!ModelContextPolicy::canSend($in->sessionAuth, $in->verifiedAccess, $context, $in->usesServerSession)) {
                throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
            }
            $this->consumeApproval($in, $context);
            $consumed = true;
            $suggestion = $this->suggestions->propose($context, $digest, Credentials::baseten());
            $suggestion['commit'] = null;
            $suggestion['isSynthetic'] = true;
            $suggestion['provenance'] = 'Local checked-in test fixture (tests/Fixtures/owned-review-case)';

            return $suggestion;
        }

        // Safe offline deterministic proposal clearly marked as an offline reference example
        $candidateSource = SingleFileCandidate::create(
            $context['source'],
            $context['sourceSha256'],
            "max(1, \$item['quantity'])",
            "\$item['quantity']",
        );

        return [
            'status' => 'offline_reference_example',
            'isOfflineExample' => true,
            'isSynthetic' => true,
            'provenance' => 'Local checked-in test fixture (tests/Fixtures/owned-review-case)',
            'repository' => $context['repository'],
            'issueNumber' => $context['issueNumber'],
            'commit' => null,
            'path' => $context['path'],
            'sourceSha256' => $context['sourceSha256'],
            'find' => "max(1, \$item['quantity'])",
            'replace' => "\$item['quantity']",
            'explanation' => 'Offline reference example for OrderTotal boundary calculation. No external provider call was made.',
            'model' => null,
            'requestDigest' => $digest,
            'usage' => null,
            'candidate' => [
                'status' => 'unverified_in_memory_preview',
                'source' => $candidateSource,
                'sha256' => hash('sha256', $candidateSource),
                'applied' => false,
                'verified' => false,
            ],
        ];
    }

    private function proposeTask(ProposeSuggestionInput $in, bool $budgetCanRequest, bool &$consumed): array
    {
        $context = $in->context;
        $digest = $in->digest;
        if (!ModelContextPolicy::canSend($in->sessionAuth, $in->verifiedAccess, $context, $in->usesServerSession)) {
            throw new \RuntimeException('Private or unverified repository content stays local and cannot be sent to a model.');
        }
        if (!$budgetCanRequest) {
            throw new \RuntimeException('The local model request allowance is exhausted.');
        }
        // Refetch without credentials to verify the issue, public visibility and commit still match.
        $current = (new PublicIssueContext($this->http))->fetch($context['issueUrl'], $context['path']);
        if (!hash_equals($this->suggestions->preview($current)['digest'], $digest)) {
            throw new \RuntimeException('The issue or repository changed. Preview the request again.');
        }
        $this->consumeApproval($in, $current);
        $consumed = true;

        return $this->suggestions->propose($current, $digest, Credentials::baseten());
    }

    private function hasProviderKey(): bool
    {
        try {
            $key = Credentials::baseten();

            return is_string($key) && strlen($key) >= 16;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkPrivateConsent(ProposeSuggestionInput $in): void
    {
        $access = $in->verifiedAccess;
        $suppliedToken = $in->approvalToken;
        $savedToken = $in->savedApprovalToken;
        if (!$in->privateConsent || !is_array($access)
            || !is_int($access['userId'] ?? null) || $access['userId'] < 1
            || $in->approvalUserId !== $access['userId']
            || !is_string($savedToken) || !is_string($suppliedToken) || !hash_equals($savedToken, $suppliedToken)) {
            throw new \RuntimeException('Approve sending this private test issue and selected file to the displayed provider.');
        }
    }

    /** @param array<string,mixed> $context */
    private function consumeApproval(ProposeSuggestionInput $in, array $context): void
    {
        $approvalToken = $in->approvalToken;
        if (!is_string($approvalToken) || !preg_match('/\A[a-f0-9]{64}\z/', $approvalToken)) {
            throw new \InvalidArgumentException('A valid server-held approval token is required for live model proposals.');
        }
        $this->approvals->consume(
            $approvalToken,
            (string) $context['repository'],
            (int) $context['issueNumber'],
            TaskWorkflowHelper::resolveContextHeadSha($context),
            $in->digest
        );
    }

    /**
     * Binds a private suggestion to the verified GitHub identity, repo,
     * issue, base, source, and candidate. False keeps publication blocked.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $suggestion
     */
    private function recordPrivateProvenance(ProposeSuggestionInput $in, array $context, array $suggestion): bool
    {
        try {
            $access = $in->verifiedAccess;
            if (!is_array($access) || !is_int($access['userId'] ?? null) || ($access['userId'] ?? 0) < 1
                || !is_array($access['repositories'] ?? null)) {
                return false;
            }
            $candidateSource = $suggestion['candidate']['source'] ?? null;
            if (!is_string($candidateSource) || !is_string($suggestion['model'] ?? null)
                || !is_string($suggestion['requestDigest'] ?? null) || !is_array($suggestion['usage'] ?? null)) {
                return false;
            }
            $this->privateProposals->record(
                ['githubUserId' => $access['userId'], 'repository' => $context['repository'],
                    'issueNumber' => $context['issueNumber'], 'baseCommit' => $context['commit'],
                    'path' => $context['path'], 'sourceSha256' => $context['sourceSha256'],
                    'candidateSha256' => hash('sha256', $candidateSource)],
                ['userId' => $access['userId'], 'repositories' => $access['repositories']],
                ['candidateSource' => $candidateSource, 'model' => $suggestion['model'],
                    'requestDigest' => $suggestion['requestDigest'], 'usage' => $suggestion['usage']]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
