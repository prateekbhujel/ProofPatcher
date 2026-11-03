<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\AbilityManifest;
use Hari\HariError;
use Hari\Registry;
use PHPUnit\Framework\TestCase;
use ProofPatch\Abilities\EngineeringWorkflowSkill;

final class EngineeringWorkflowSkillTest extends TestCase
{
    public function testReviewAndRepairGuidanceIsDeterministicAndDistinct(): void
    {
        $skill = new EngineeringWorkflowSkill();
        $review = $skill->invoke(['task' => 'review']);
        $repair = $skill->invoke(['task' => 'repair']);

        self::assertSame($review, $skill->invoke(['task' => 'review']));
        self::assertSame($repair, (new EngineeringWorkflowSkill())->invoke(['task' => 'repair']));
        self::assertSame('review', $review['task']);
        self::assertSame('repair', $repair['task']);
        self::assertNotSame($review['guidance'], $repair['guidance']);
        foreach ([$review, $repair] as $output) {
            self::assertIsArray($output['checks']);
            self::assertNotEmpty($output['checks']);
            self::assertIsString($output['guidance']);
            self::assertNotEmpty($output['guidance']);
            self::assertLessThan(3000, strlen($output['guidance']));
        }
        self::assertSame([], $skill->capabilities());
    }

    public function testUnknownTasksAndUnexpectedContextAreRejected(): void
    {
        $skill = new EngineeringWorkflowSkill();
        foreach ([[], ['task' => 'execute'], ['task' => null], ['task' => ['review']],
            ['task' => 'repair', 'source' => 'private source'],
            ['task' => 'review', 'key' => 'private credential']] as $input) {
            try {
                $skill->invoke($input);
                self::fail('Unexpected workflow input was accepted.');
            } catch (HariError $error) {
                self::assertSame('invalid_input', $error->code());
                self::assertStringNotContainsString('private source', $error->getMessage());
                self::assertStringNotContainsString('private credential', $error->getMessage());
            }
        }
    }

    public function testAnotherApplicationCanBorrowTheSkillWithoutProofPatcherPrivileges(): void
    {
        $registry = new Registry();
        $skill = new EngineeringWorkflowSkill();
        $registry->registerAbility(new AbilityManifest('engineering', 1, ['engineering.workflow'], [], []));
        $registry->registerAbility(new AbilityManifest('other-application', 1, [], ['engineering.workflow' => 1], []));
        $registry->provideSkill('engineering', $skill);
        $registry->freeze();

        $borrowed = $registry->resolve('engineering.workflow', 1, 'other-application');
        self::assertSame($skill->invoke(['task' => 'review']), $borrowed->invoke(['task' => 'review']));
        self::assertSame([], $borrowed->capabilities());
        self::assertSame([], $registry->abilities()[1]['needs']);
        self::assertSame(['engineering', 'other-application'], array_column($registry->abilities(), 'name'));

        try {
            $registry->resolve('engineering.workflow', 2, 'other-application');
            self::fail('The declared workflow version was bypassed.');
        } catch (HariError $error) {
            self::assertSame('version_mismatch', $error->code());
        }
    }
}
