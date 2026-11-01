<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\HariError;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\VerifyCandidateSkill;
use ProofPatch\Abilities\VerifyPatchSkill;

final class VerifySkillsTest extends TestCase
{
    public function testCandidateSkillRejectsMalformedInput(): void
    {
        foreach ([[], ['suggestion' => 'x'], ['suggestion' => [], 'is_owned' => 1, 'context' => [], 'isolated_available' => true]] as $index => $input) {
            try {
                (new VerifyCandidateSkill())->invoke($input);
                self::fail("Malformed input accepted at case {$index}.");
            } catch (HariError $error) {
                self::assertSame('invalid_input', $error->code(), "Case {$index}");
            }
        }
    }

    public function testCandidateSkillGuardsRunWithoutEngine(): void
    {
        // No candidate present: fromRaw fails closed before any engine is used.
        $result = (new VerifyCandidateSkill())->invoke([
            'suggestion' => ['path' => 'src/OrderTotal.php'],
            'is_owned' => true,
            'context' => ['repository' => 'x/y'],
            'isolated_available' => true,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('No candidate proposal available to verify.', $result['error']);
    }

    public function testCandidateSkillConstructionIsLazy(): void
    {
        // No engine, no app context touched at construction: defaults are
        // built at invoke time inside the application. (Invoke-time engine
        // failure surfaces as skill_failed; covered live by consumer
        // fail-closed tests and attack probes, not by ambient container.)
        $skill = new VerifyCandidateSkill();
        self::assertSame('verify.candidate', $skill->id());
        self::assertSame(['verify.execute'], $skill->capabilities());
    }

    public function testPatchSkillRejectsMalformedInput(): void
    {
        foreach ([[], ['patch' => 'x'], ['patch' => [], 'provenance' => 'x']] as $index => $input) {
            try {
                (new VerifyPatchSkill())->invoke($input);
                self::fail("Malformed input accepted at case {$index}.");
            } catch (HariError $error) {
                self::assertSame('invalid_input', $error->code());
            }
        }
    }

    public function testPatchSkillRejectsInvalidPatch(): void
    {
        try {
            (new VerifyPatchSkill())->invoke(['patch' => ['file' => 'nope.css']]);
            self::fail('Invalid patch was accepted.');
        } catch (HariError $error) {
            self::assertSame('invalid_input', $error->code());
        }
    }
}
