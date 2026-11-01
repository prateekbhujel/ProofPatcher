<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/**
 * Validated input for proposing a suggestion. Framework-free: the controller
 * copies plain values out of the request and session, and this object proves
 * they are shaped correctly before the use case runs.
 */
final readonly class ProposeSuggestionInput
{
    public function __construct(
        public array $context,
        public bool $isOwned,
        public bool $isDedicated,
        public string $digest,
        public bool $approved,
        public mixed $approvalToken,
        public bool $offlineReference,
        public bool $privateConsent,
        public bool $usesServerSession,
        public mixed $requestedCandidateSha,
        public ?array $sessionAuth,
        public mixed $verifiedAccess,
        public mixed $savedApprovalToken,
        public mixed $approvalUserId,
        public ?string $githubToken,
    ) {
    }

    /** @param array<string,mixed> $raw */
    public static function fromRaw(array $raw, array $context, bool $isOwned, bool $isDedicated): self
    {
        $digest = $raw['digest'] ?? null;
        if (($raw['approved'] ?? null) !== true || !is_string($digest)) {
            throw new \InvalidArgumentException('Review and approve the request first.');
        }

        return new self(
            context: $context,
            isOwned: $isOwned,
            isDedicated: $isDedicated,
            digest: $digest,
            approved: true,
            approvalToken: $raw['approval_token'] ?? null,
            offlineReference: (bool) filter_var($raw['offline_reference'] ?? false, FILTER_VALIDATE_BOOLEAN),
            privateConsent: (bool) filter_var($raw['private_consent'] ?? false, FILTER_VALIDATE_BOOLEAN),
            usesServerSession: (bool) ($raw['uses_server_session'] ?? true),
            requestedCandidateSha: $raw['candidate_sha'] ?? null,
            sessionAuth: isset($raw['session_auth']) && is_array($raw['session_auth']) ? $raw['session_auth'] : null,
            verifiedAccess: $raw['verified_access'] ?? null,
            savedApprovalToken: $raw['saved_approval_token'] ?? null,
            approvalUserId: $raw['approval_user_id'] ?? null,
            githubToken: isset($raw['github_token']) && is_string($raw['github_token']) ? $raw['github_token'] : null,
        );
    }
}
