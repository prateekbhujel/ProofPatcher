<?php

declare(strict_types=1);

namespace Hari;

/** Pure-data ability manifest: provides, borrows, needs. No behavior. */
interface Ability
{
    public function name(): string;

    public function version(): int;

    /** @return list<string> skill ids this ability provides. */
    public function provides(): array;

    /** @return array<string, int> borrowed skill id => pinned major. */
    public function borrows(): array;

    /** @return list<string> capability ids this ability holds. */
    public function needs(): array;
}
