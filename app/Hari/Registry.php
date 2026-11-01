<?php

declare(strict_types=1);

namespace Hari;

/**
 * Explicit skill registry. Registration is code, never discovery.
 * Resolution enforces declared borrows, pinned majors, and capabilities.
 * Every failure throws HariError; nothing resolves silently or partially.
 */
final class Registry
{
    /** @var array<string, Ability> */
    private array $abilities = [];

    /** @var array<string, array<int, Skill>> skill id => major => skill */
    private array $skills = [];

    private bool $frozen = false;

    public static function validSkillId(string $id): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+\z/', $id) === 1;
    }

    public static function validAbilityName(string $name): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $name) === 1;
    }

    public static function validCapability(string $capability): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+\z/', $capability) === 1;
    }

    public function registerAbility(Ability $ability): void
    {
        $this->guardMutable();
        $name = $ability->name();
        if (!self::validAbilityName($name) || $ability->version() < 1) {
            throw new HariError('invalid_ability', 'Ability manifest is invalid.');
        }
        foreach ($ability->borrows() as $id => $major) {
            if (!is_string($id) || !self::validSkillId($id) || !is_int($major) || $major < 1) {
                throw new HariError('invalid_ability', 'Borrow declaration is invalid.');
            }
        }
        foreach ($ability->needs() as $capability) {
            if (!is_string($capability) || !self::validCapability($capability)) {
                throw new HariError('invalid_ability', 'Capability id is invalid.');
            }
        }
        $this->abilities[$name] = $ability;
    }

    public function provideSkill(string $abilityName, Skill $skill): void
    {
        $this->guardMutable();
        $ability = $this->abilities[$abilityName] ?? null;
        if ($ability === null) {
            throw new HariError('unknown_ability', 'Providing ability is not registered.');
        }
        $id = $skill->id();
        $major = $skill->version();
        if (!self::validSkillId($id) || $major < 1) {
            throw new HariError('invalid_skill', 'Skill id or version is invalid.');
        }
        foreach ($skill->capabilities() as $capability) {
            if (!is_string($capability) || !self::validCapability($capability)) {
                throw new HariError('invalid_skill', 'Skill capability id is invalid.');
            }
        }
        if (!in_array($id, $ability->provides(), true)) {
            throw new HariError('provide_not_declared', 'Ability does not declare this skill.');
        }
        if (isset($this->skills[$id][$major])) {
            throw new HariError('duplicate_skill', 'Skill version is already provided.');
        }
        $this->skills[$id][$major] = $skill;
    }

    /**
     * @throws HariError
     */
    public function resolve(string $skillId, int $major, string $borrower): Skill
    {
        if (!self::validSkillId($skillId) || $major < 1 || !self::validAbilityName($borrower)) {
            throw new HariError('invalid_request', 'Resolution request is invalid.');
        }
        $ability = $this->abilities[$borrower] ?? null;
        if ($ability === null) {
            throw new HariError('unknown_borrower', 'Borrowing ability is not registered.');
        }
        $borrows = $ability->borrows();
        if (!isset($borrows[$skillId])) {
            throw new HariError('borrow_not_declared', 'Borrow is not declared in the manifest.');
        }
        if ($borrows[$skillId] !== $major) {
            throw new HariError('version_mismatch', 'Borrowed major does not match the request.');
        }
        $skill = $this->skills[$skillId][$major] ?? null;
        if ($skill === null) {
            throw new HariError('skill_unknown', 'Skill is not provided at this major.');
        }
        $held = array_fill_keys($ability->needs(), true);
        foreach ($skill->capabilities() as $required) {
            if (!isset($held[$required])) {
                throw new HariError('capability_denied', 'Borrower lacks a required capability.');
            }
        }
        return $skill;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function guardMutable(): void
    {
        if ($this->frozen) {
            throw new HariError('registry_frozen', 'Registry is frozen.');
        }
    }
}
