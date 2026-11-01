<?php

declare(strict_types=1);

namespace ProofPatch\Verifier;

/**
 * Marks an isolated runner whose verdicts are attested by a trusted oracle.
 *
 * The oracle boundary (supervisor-owned expected cases parsed from pinned
 * harness bytes, unprivileged workers, structured verdicts over a private
 * channel) protects the result from candidate code, so arbitrary statically
 * safe candidates may run. Runners without this marker keep the allowlist.
 */
interface TrustedOracleInterface extends IsolatedRunnerInterface
{
    public function oracleProtocolVersion(): string;
}
