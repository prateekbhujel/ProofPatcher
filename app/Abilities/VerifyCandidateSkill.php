<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;
use ProofPatch\Application\Workbench\VerifyCandidateInput;
use ProofPatch\Application\Workbench\VerifyCandidateUseCase;
use ProofPatch\GitHub\ServerHeldPublicationApproval;
use ProofPatch\Verifier\ModalIsolatedRunner;
use ProofPatch\Verifier\OwnedCaseVerifier;
use ProofPatch\Verifier\TrustedOracleRunner;

/**
 * Skill verify.candidate v1: run the 5-gate candidate verification.
 * Guards are NOT mirrored here: invoke takes the same raw values as
 * VerifyCandidateInput::fromRaw and runs the identical code, so guard
 * behavior cannot drift. The engine is injectable for tests; the
 * controller always passes the engine it selected (custom-runner seam).
 */
final class VerifyCandidateSkill implements Skill
{
    public function __construct(private ?VerifyCandidateUseCase $useCase = null)
    {
    }

    public function withVerifier(OwnedCaseVerifier $verifier): static
    {
        $clone = clone $this;
        $clone->useCase = new VerifyCandidateUseCase($verifier);
        return $clone;
    }

    public function id(): string
    {
        return 'verify.candidate';
    }

    public function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['verify.execute'];
    }

    public function describe(): string
    {
        return 'Runs isolated 5-gate verification on an allowlisted candidate. Spawns runners and sandboxes; writes a verification report.';
    }

    public function invoke(array $input): array
    {
        $suggestion = $input['suggestion'] ?? null;
        $isOwned = $input['is_owned'] ?? null;
        $context = $input['context'] ?? null;
        $isolatedAvailable = $input['isolated_available'] ?? null;
        if (!is_array($suggestion) || !is_bool($isOwned) || !is_array($context) || !is_bool($isolatedAvailable)) {
            throw new HariError('invalid_input', 'Verification input is invalid.');
        }
        try {
            $validated = VerifyCandidateInput::fromRaw($suggestion, $isOwned, $context, $isolatedAvailable);
        } catch (\InvalidArgumentException $error) {
            return ['ok' => false, 'report' => [], 'error' => $error->getMessage()];
        }
        try {
            $result = ($this->useCase ?? self::defaultUseCase())->execute($validated);
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Verification skill failed.');
        }
        return ['ok' => $result->ok, 'report' => $result->report, 'error' => $result->error];
    }

    private static function defaultUseCase(): VerifyCandidateUseCase
    {
        // Production construction, mirroring the workbench controller.
        $rawGrant = getenv('MODAL_GRANT_VERIFIED') ?: ($_ENV['MODAL_GRANT_VERIFIED'] ?? $_SERVER['MODAL_GRANT_VERIFIED'] ?? 'false');
        $runner = TrustedOracleRunner::isEnabled()
            ? new TrustedOracleRunner()
            : new ModalIsolatedRunner(grantVerified: $rawGrant === 'true');
        return new VerifyCandidateUseCase(new OwnedCaseVerifier(
            base_path(),
            null,
            new ServerHeldPublicationApproval(storage_path('app/publication_approvals')),
            $runner->isAvailable()['available'] ? $runner : null
        ));
    }
}
