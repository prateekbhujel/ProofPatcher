<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\HariError;
use Hari\Skill;
use ProofPatch\Evidence\Receipt;

/**
 * Skill evidence.verify v1: verify a receipt directory's integrity.
 * Invalid receipts are DATA ({valid:false}), not errors; only a broken
 * skill or bad input throws. Reasons carry no filesystem paths.
 */
final class EvidenceVerifySkill implements Skill
{
    public function id(): string
    {
        return 'evidence.verify';
    }

    public function version(): int
    {
        return 1;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return ['evidence.read'];
    }

    public function describe(): string
    {
        return 'Verifies a local evidence receipt directory. Reads files, writes nothing, no network.';
    }

    public function invoke(array $input): array
    {
        $directory = $input['directory'] ?? null;
        $expected = $input['expected'] ?? null;
        if (!is_string($directory) || $directory === '' || is_link($directory) || !is_dir($directory)) {
            throw new HariError('invalid_input', 'Evidence directory is invalid.');
        }
        if ($expected !== null && (!is_string($expected) || !preg_match('/\A[a-f0-9]{64}\z/', $expected))) {
            throw new HariError('invalid_input', 'Expected digest is invalid.');
        }
        try {
            $receipt = (new Receipt())->verify($directory, $expected);
        } catch (\InvalidArgumentException $error) {
            return ['valid' => false, 'reason' => $error->getMessage()];
        } catch (\Throwable) {
            throw new HariError('skill_failed', 'Evidence skill failed.');
        }
        return ['valid' => true, 'receipt' => $receipt];
    }
}
