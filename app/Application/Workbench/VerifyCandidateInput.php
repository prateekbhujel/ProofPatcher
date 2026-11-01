<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/**
 * Validated input for verifying a candidate. Framework-free: the controller
 * copies plain values out of the session, and this object proves a candidate
 * and context are present before the use case runs.
 */
final readonly class VerifyCandidateInput
{
    public function __construct(
        public array $suggestion,
        public array $context,
        public string $candidateSource,
        public string $candidateSha,
        public bool $isolatedAvailable,
    ) {
    }

    /**
     * @param array<string,mixed>|null $suggestion
     * @param array<string,mixed>|null $context
     */
    public static function fromRaw(mixed $suggestion, bool $isOwned, mixed $context, bool $isolatedAvailable): self
    {
        if (!is_array($suggestion) || !isset($suggestion['candidate']['source'])) {
            throw new \InvalidArgumentException('No candidate proposal available to verify.');
        }
        if (!$isOwned || ($suggestion['path'] ?? '') !== 'src/OrderTotal.php') {
            throw new \InvalidArgumentException('Automated host verification is currently restricted to the allowlisted owned review case fixture. Worktrees and temporary directories are not security sandboxes for arbitrary untrusted code.');
        }
        if (!is_array($context)) {
            throw new \InvalidArgumentException('No active issue context available to verify.');
        }
        $candidateSource = (string) $suggestion['candidate']['source'];
        $candidateSha = hash('sha256', $candidateSource);
        // Fail closed for model-generated or untrusted candidate code if microVM runner is unavailable
        if (!$isolatedAvailable && (!empty($suggestion['model']) || !hash_equals(\ProofPatch\Verifier\OwnedCaseVerifier::KNOWN_TRUSTED_CANDIDATE_SHA256, $candidateSha))) {
            throw new \InvalidArgumentException('Host execution disabled: Model-generated or untrusted candidate code cannot be executed on the host. Worktrees, temporary directories, and token filters are not security sandboxes; an isolated microVM runner is required.');
        }

        return new self($suggestion, $context, $candidateSource, $candidateSha, $isolatedAvailable);
    }
}
