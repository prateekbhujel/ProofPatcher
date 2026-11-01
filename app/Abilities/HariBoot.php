<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\AbilityManifest;
use Hari\Registry;
use ProofPatch\Agent\RequestBudget;

/**
 * Explicit kernel boot: registers provider abilities, borrowing
 * abilities, and skills. No discovery. Verify engines are lazy:
 * skills build production defaults at invoke time (app context),
 * while callers pass test doubles via withVerifier().
 */
final class HariBoot
{
    private function __construct()
    {
    }

    public static function boot(?Registry $registry = null, ?RequestBudget $budget = null): Registry
    {
        $registry ??= new Registry();
        $registry->registerAbility(new AbilityManifest(
            'proofpatch-budget', 1, ['budget.status'], [], ['budget.read'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'proofpatch-evidence', 1, ['evidence.verify'], [], ['evidence.read'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'proofpatch-review', 1, ['review.result'], [], ['review.read'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'proofpatch-verify', 1, ['verify.candidate', 'verify.patch'], [], ['verify.execute'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'mcp', 1, [],
            ['budget.status' => 1, 'review.result' => 1, 'evidence.verify' => 1],
            ['budget.read', 'review.read', 'evidence.read'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'kernel-cli', 1, [],
            ['budget.status' => 1, 'evidence.verify' => 1, 'review.result' => 1, 'verify.patch' => 1],
            ['budget.read', 'evidence.read', 'review.read', 'verify.execute'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'workbench', 1, [], ['budget.status' => 1, 'verify.candidate' => 1], ['budget.read', 'verify.execute'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'telemetry', 1, [], ['review.result' => 1], ['review.read'],
        ));
        $registry->registerAbility(new AbilityManifest(
            'web', 1, [],
            ['budget.status' => 1, 'evidence.verify' => 1, 'review.result' => 1, 'verify.patch' => 1],
            ['budget.read', 'evidence.read', 'review.read', 'verify.execute'],
        ));
        $registry->provideSkill('proofpatch-budget', new BudgetStatusSkill($budget));
        $registry->provideSkill('proofpatch-evidence', new EvidenceVerifySkill());
        $registry->provideSkill('proofpatch-review', new ReviewResultSkill());
        $registry->provideSkill('proofpatch-verify', new VerifyCandidateSkill());
        $registry->provideSkill('proofpatch-verify', new VerifyPatchSkill());
        return $registry;
    }
}
