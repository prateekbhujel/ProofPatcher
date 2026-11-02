<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use Hari\AbilityManifest;
use Hari\HariError;
use Hari\Registry;
use Hari\Skill;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakeSkill implements Skill
{
    /** @param list<string> $capabilities */
    public function __construct(
        private string $id,
        private int $version,
        private array $capabilities = [],
        private array $output = ['ok' => true],
        private ?HariError $failure = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function describe(): string
    {
        return 'test skill';
    }

    public function invoke(array $input): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->output;
    }
}

final class HariRegistryTest extends TestCase
{
    private function booted(): Registry
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['demo.echo'], [], []));
        $registry->registerAbility(new AbilityManifest('borrower-b', 1, [], ['demo.echo' => 1], []));
        $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 1));
        return $registry;
    }

    public function testIntrospectionListsAbilitiesAndSkills(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('proofpatch-verify', 1, ['verify.gate'], [], ['model.exec']));
        $registry->provideSkill('proofpatch-verify', new FakeSkill('verify.gate', 2, ['model.exec'], ['ok' => true]));
        $registry->provideSkill('proofpatch-verify', new FakeSkill('verify.gate', 1, ['model.exec'], ['ok' => true]));

        $abilities = $registry->abilities();
        $this->assertSame('proofpatch-verify', $abilities[0]['name']);
        $this->assertSame(['verify.gate'], $abilities[0]['provides']);

        $skills = $registry->skills();
        $this->assertSame('verify.gate', $skills[0]['id']);
        $this->assertSame([1, 2], $skills[0]['versions']);
        $this->assertSame(['model.exec'], $skills[0]['capabilities']);
    }

    public function testResolveAndInvokeHappyPath(): void
    {
        $skill = $this->booted()->resolve('demo.echo', 1, 'borrower-b');

        self::assertSame(['ok' => true], $skill->invoke([]));
    }

    public function testTwoBorrowersShareOneSkill(): void
    {
        $registry = $this->booted();
        $registry->registerAbility(new AbilityManifest('borrower-c', 1, [], ['demo.echo' => 1], []));

        self::assertSame(['ok' => true], $registry->resolve('demo.echo', 1, 'borrower-c')->invoke([]));
    }

    public function testDuplicateSkillMajorFails(): void
    {
        $registry = $this->booted();

        try {
            $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 1));
            self::fail('Duplicate skill major was accepted.');
        } catch (HariError $error) {
            self::assertSame('duplicate_skill', $error->code());
        }
    }

    public function testSameIdDifferentMajorCoexists(): void
    {
        $registry = $this->booted();
        $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 2));
        $registry->registerAbility(new AbilityManifest('borrower-v2', 1, [], ['demo.echo' => 2], []));

        self::assertSame(1, $registry->resolve('demo.echo', 1, 'borrower-b')->version());
        self::assertSame(2, $registry->resolve('demo.echo', 2, 'borrower-v2')->version());
    }

    public function testUnknownBorrowerFails(): void
    {
        try {
            $this->booted()->resolve('demo.echo', 1, 'ghost');
            self::fail('Unknown borrower resolved.');
        } catch (HariError $error) {
            self::assertSame('unknown_borrower', $error->code());
        }
    }

    public function testUndeclaredBorrowFails(): void
    {
        $registry = $this->booted();
        $registry->registerAbility(new AbilityManifest('sneaky', 1, [], [], []));

        try {
            $registry->resolve('demo.echo', 1, 'sneaky');
            self::fail('Undeclared borrow resolved.');
        } catch (HariError $error) {
            self::assertSame('borrow_not_declared', $error->code());
        }
    }

    public function testMajorMismatchFails(): void
    {
        // borrower-b pins major 1; asking for 2 contradicts its own manifest.
        try {
            $this->booted()->resolve('demo.echo', 2, 'borrower-b');
            self::fail('Mismatched major resolved.');
        } catch (HariError $error) {
            self::assertSame('version_mismatch', $error->code());
        }
    }

    public function testUnprovidedMajorFails(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['demo.echo'], [], []));
        $registry->registerAbility(new AbilityManifest('borrower-b', 1, [], ['demo.echo' => 2], []));
        $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 1));

        try {
            $registry->resolve('demo.echo', 2, 'borrower-b');
            self::fail('Unprovided major resolved.');
        } catch (HariError $error) {
            // Borrower pinned 2 and asked 2, but only 1 is provided.
            self::assertSame('skill_unknown', $error->code());
        }
    }

    public function testMissingCapabilityFails(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['vault.open'], [], []));
        $registry->registerAbility(new AbilityManifest('borrower-b', 1, [], ['vault.open' => 1], []));
        $registry->provideSkill('provider-a', new FakeSkill('vault.open', 1, ['vault.read']));

        try {
            $registry->resolve('vault.open', 1, 'borrower-b');
            self::fail('Capability check was bypassed.');
        } catch (HariError $error) {
            self::assertSame('capability_denied', $error->code());
        }
    }

    public function testCapabilityHeldResolves(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['vault.open'], [], []));
        $registry->registerAbility(new AbilityManifest('borrower-b', 1, [], ['vault.open' => 1], ['vault.read']));
        $registry->provideSkill('provider-a', new FakeSkill('vault.open', 1, ['vault.read']));

        self::assertSame(['ok' => true], $registry->resolve('vault.open', 1, 'borrower-b')->invoke([]));
    }

    public function testProvideWithoutDeclarationFails(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, [], [], []));

        try {
            $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 1));
            self::fail('Undeclared provision was accepted.');
        } catch (HariError $error) {
            self::assertSame('provide_not_declared', $error->code());
        }
    }

    public function testProvideForUnknownAbilityFails(): void
    {
        try {
            (new Registry())->provideSkill('ghost', new FakeSkill('demo.echo', 1));
            self::fail('Provision for unknown ability was accepted.');
        } catch (HariError $error) {
            self::assertSame('unknown_ability', $error->code());
        }
    }

    public function testFrozenRegistryRejectsMutationButServesReads(): void
    {
        $registry = $this->booted();
        $registry->freeze();
        self::assertTrue($registry->isFrozen());

        try {
            $registry->registerAbility(new AbilityManifest('late', 1, [], [], []));
            self::fail('Frozen registry accepted mutation.');
        } catch (HariError $error) {
            self::assertSame('registry_frozen', $error->code());
        }
        self::assertSame(['ok' => true], $registry->resolve('demo.echo', 1, 'borrower-b')->invoke([]));
    }

    #[DataProvider('maliciousIds')]
    public function testMalformedIdsFail(string $id): void
    {
        try {
            $this->booted()->resolve($id, 1, 'borrower-b');
            self::fail("Malformed id resolved: {$id}");
        } catch (HariError $error) {
            self::assertSame('invalid_request', $error->code());
        }
    }

    /** @return array<string, array{string}> */
    public static function maliciousIds(): array
    {
        return [
            'empty' => [''],
            'single segment' => ['budget'],
            'uppercase' => ['Budget.Status'],
            'traversal' => ['../budget.status'],
            'null byte' => ["demo.echo\0x"],
            'spaces' => ['demo.echo '],
            'leading dot' => ['.demo.echo'],
        ];
    }

    public function testVersionZeroRejected(): void
    {
        try {
            new AbilityManifest('zero', 0, [], [], []);
            self::fail('Version 0 manifest was accepted.');
        } catch (HariError $error) {
            self::assertSame('invalid_ability', $error->code());
        }

        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['demo.echo'], [], []));
        try {
            $registry->provideSkill('provider-a', new FakeSkill('demo.echo', 0));
            self::fail('Version 0 skill was accepted.');
        } catch (HariError $error) {
            self::assertSame('invalid_skill', $error->code());
        }
    }

    public function testSkillFailurePropagatesTyped(): void
    {
        $registry = new Registry();
        $registry->registerAbility(new AbilityManifest('provider-a', 1, ['demo.fail'], [], []));
        $registry->registerAbility(new AbilityManifest('borrower-b', 1, [], ['demo.fail' => 1], []));
        $registry->provideSkill('provider-a', new FakeSkill('demo.fail', 1, [], [], new HariError('boom', 'Boom.')));

        try {
            $registry->resolve('demo.fail', 1, 'borrower-b')->invoke([]);
            self::fail('Skill failure was swallowed.');
        } catch (HariError $error) {
            self::assertSame('boom', $error->code());
        }
    }
}
