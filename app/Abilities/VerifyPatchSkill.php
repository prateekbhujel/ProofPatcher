<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;
use ProofPatch\Patch\CssPatch;
use ProofPatch\Verifier\CheckoutVerifier;

/**
 * Skill verify.patch v1: run isolated 4-gate verification on a CSS patch.
 * Patch validation is NOT mirrored: CssPatch::fromArray runs inside.
 */
final class VerifyPatchSkill implements Skill
{
    public function __construct(private ?CheckoutVerifier $verifier = null)
    {
    }

    public function withVerifier(CheckoutVerifier $verifier): static
    {
        $clone = clone $this;
        $clone->verifier = $verifier;
        return $clone;
    }

    public function id(): string
    {
        return 'verify.patch';
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
        return 'Runs isolated 4-gate verification on a bounded CSS patch in a detached worktree. Spawns git and test processes.';
    }

    public function invoke(array $input): array
    {
        $patch = $input['patch'] ?? null;
        $provenance = $input['provenance'] ?? null;
        if (!is_array($patch)) {
            throw new HariError('invalid_input', 'Patch input is invalid.');
        }
        if ($provenance !== null && !is_array($provenance)) {
            throw new HariError('invalid_input', 'Provenance input is invalid.');
        }
        try {
            $candidate = CssPatch::fromArray($patch);
        } catch (\InvalidArgumentException $error) {
            throw new HariError('invalid_input', $error->getMessage());
        }
        try {
            $report = ($this->verifier ?? new CheckoutVerifier(base_path()))->verify($candidate, $provenance);
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Patch verification skill failed.');
        }
        if (!is_array($report)) {
            throw new HariError('skill_failed', 'Patch verification skill failed.');
        }
        return $report;
    }
}
