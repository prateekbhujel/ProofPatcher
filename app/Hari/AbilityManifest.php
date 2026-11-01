<?php

declare(strict_types=1);

namespace Hari;

/** Immutable manifest value object. Invalid manifests fail at construction. */
final readonly class AbilityManifest implements Ability
{
    /**
     * @param list<string> $provides
     * @param array<string, int> $borrows
     * @param list<string> $needs
     */
    public function __construct(
        private string $name,
        private int $version,
        private array $provides,
        private array $borrows,
        private array $needs,
    ) {
        if (!Registry::validAbilityName($name)) {
            throw new HariError('invalid_ability', 'Ability name is invalid.');
        }
        if ($version < 1) {
            throw new HariError('invalid_ability', 'Ability version must be >= 1.');
        }
        foreach ($provides as $id) {
            if (!is_string($id) || !Registry::validSkillId($id)) {
                throw new HariError('invalid_ability', 'Provided skill id is invalid.');
            }
        }
        foreach ($borrows as $id => $major) {
            if (!is_string($id) || !Registry::validSkillId($id) || !is_int($major) || $major < 1) {
                throw new HariError('invalid_ability', 'Borrow declaration is invalid.');
            }
        }
        foreach ($needs as $cap) {
            if (!is_string($cap) || !Registry::validCapability($cap)) {
                throw new HariError('invalid_ability', 'Capability id is invalid.');
            }
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function provides(): array
    {
        return $this->provides;
    }

    public function borrows(): array
    {
        return $this->borrows;
    }

    public function needs(): array
    {
        return $this->needs;
    }
}
