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
            $context = $in->context;
            unset($context['privateProvenanceId']);
            $id = $in->suggestion['privateProvenanceId'] ?? null;
            if (($in->suggestion['privateProvenanceRecorded'] ?? null) === false) {
                throw new \InvalidArgumentException('Private proposal provenance was not recorded.');
            }
            if (($in->suggestion['privateProvenanceRecorded'] ?? null) === true && $id === null) {
                throw new \InvalidArgumentException('Private proposal run is missing.');
            }
            if ($id !== null) {
                if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)
                    || ($in->suggestion['privateProvenanceRecorded'] ?? null) !== true) {
                    throw new \InvalidArgumentException('Private proposal provenance is invalid.');
                }
                $context['privateProvenanceId'] = $id;
            }
            return VerifyCandidateResult::success($this->verifier->verify($in->candidateSource, $context));
        } catch (\Throwable $error) {
            return VerifyCandidateResult::failure($error->getMessage());
        }
    }
}
