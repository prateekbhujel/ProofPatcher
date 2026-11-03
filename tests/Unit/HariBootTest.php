<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\HariError;
use Hari\Registry;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\HariBoot;

final class HariBootTest extends TestCase
{
    private function booted(): Registry
    {
        return HariBoot::boot();
    }

    public function testAllLegitimateBorrowsResolve(): void
    {
        $registry = $this->booted();
        $pairs = [
            ['engineering.workflow', 'proofpatch-review'],
            ['engineering.workflow', 'workbench'],
            ['budget.status', 'mcp'],
            ['review.result', 'mcp'],
            ['evidence.verify', 'mcp'],
            ['budget.status', 'kernel-cli'],
            ['budget.status', 'web'],
            ['budget.status', 'workbench'],
            ['evidence.verify', 'kernel-cli'],
            ['evidence.verify', 'web'],
            ['review.result', 'kernel-cli'],
            ['review.result', 'web'],
            ['review.result', 'telemetry'],
            ['verify.candidate', 'workbench'],
            ['verify.patch', 'kernel-cli'],
            ['verify.patch', 'web'],
        ];
        foreach ($pairs as [$skill, $borrower]) {
            $resolved = $registry->resolve($skill, 1, $borrower);
            self::assertSame($skill, $resolved->id(), "{$borrower} → {$skill}");
        }
    }

    public function testCrossAbilityBorrowRequiresDeclaration(): void
    {
        $registry = $this->booted();
        $denied = [
            ['engineering.workflow', 'mcp'],
            // mcp reads budgets, reviews, and evidence only — never verifies.
            ['verify.candidate', 'mcp'],
            ['verify.patch', 'mcp'],
            // workbench verifies and budgets; nothing else.
            ['evidence.verify', 'workbench'],
            ['review.result', 'workbench'],
            ['verify.patch', 'workbench'],
            // kernel-cli never runs candidate verification.
            ['verify.candidate', 'kernel-cli'],
            // telemetry only reads review outcomes.
            ['budget.status', 'telemetry'],
            ['verify.patch', 'telemetry'],
        ];
        foreach ($denied as [$skill, $borrower]) {
            try {
                $registry->resolve($skill, 1, $borrower);
                self::fail("Undeclared borrow resolved: {$borrower} → {$skill}.");
            } catch (HariError $error) {
                self::assertSame('borrow_not_declared', $error->code(), "{$borrower} → {$skill}");
            }
        }
    }

    public function testBootIsRepeatableOnFreshRegistries(): void
    {
        // Double boot on the SAME registry must fail (duplicates); fresh ones work.
        $registry = $this->booted();
        try {
            HariBoot::boot($registry);
            self::fail('Double boot was accepted.');
        } catch (HariError $error) {
            self::assertSame('duplicate_skill', $error->code());
        }
        self::assertSame('budget.status', $this->booted()->resolve('budget.status', 1, 'mcp')->id());
    }
}
