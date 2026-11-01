<?php

declare(strict_types=1);

namespace Hari;

/**
 * A borrowable unit of behavior.
 *
 * Skills are deterministic at the boundary: plain-array input, plain-array
 * output, failures as HariError. A skill declares every capability it
 * needs; the registry refuses borrowers that do not hold them.
 */
interface Skill
{
    /** Dotted id, e.g. "budget.status". */
    public function id(): string;

    /** Major version, >= 1. */
    public function version(): int;

    /** @return list<string> required capability ids. */
    public function capabilities(): array;

    /** Human-readable side-effect declaration for audit. */
    public function describe(): string;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws HariError
     */
    public function invoke(array $input): array;
}
