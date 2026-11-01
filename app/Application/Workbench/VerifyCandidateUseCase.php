<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

use ProofPatch\Verifier\OwnedCaseVerifier;

/**
 * Verifies a proposed candidate against the allowlisted fixture.
 *
 * Fails closed for model-generated or untrusted code without an isolated
 * runner, then delegates the run to the owned-case verifier. Has no HTTP,
 * session, or task access; the controller persists the report and syncs
 * the session from the result.
 */
final class VerifyCandidateUseCase
{
    public function __construct(private OwnedCaseVerifier $verifier)
    {
    }

    public function execute(VerifyCandidateInput $in): VerifyCandidateResult
    {
        try {
            return VerifyCandidateResult::success($this->verifier->verify($in->candidateSource, $in->context));
        } catch (\Throwable $error) {
            return VerifyCandidateResult::failure($error->getMessage());
        }
    }
}
