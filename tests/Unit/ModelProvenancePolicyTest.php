<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\ModelProvenancePolicy;

final class ModelProvenancePolicyTest extends TestCase
{
    /** @return array{patch: array<string, mixed>, generated: array<string, mixed>} */
    private function matching(): array
    {
        $patch = [
            'file' => 'public/fixtures/checkout.html', 'baseSha256' => str_repeat('a', 64),
            'find' => 'pointer-events: auto;', 'replace' => 'pointer-events: none;',
        ];
        return [
            'patch' => $patch,
            'generated' => [
                'patch' => $patch, 'model' => 'm', 'usage' => ['in' => 1],
                'sourceRunId' => 'run-1', 'sourceReceiptSha256' => str_repeat('b', 64),
            ],
        ];
    }

    public function testMatchingProposalBindsProvenance(): void
    {
        $m = $this->matching();

        self::assertSame(
            [
                'origin' => 'model', 'model' => 'm', 'usage' => ['in' => 1],
                'sourceRunId' => 'run-1', 'sourceReceiptSha256' => str_repeat('b', 64),
            ],
            ModelProvenancePolicy::forPatch($m['generated'], $m['patch'], 'run-1')
        );
    }

    public function testAnyMismatchVerifiesAsManualPatch(): void
    {
        $m = $this->matching();
        $edited = $m['patch'];
        $edited['replace'] = 'color: red;';

        self::assertNull(ModelProvenancePolicy::forPatch($m['generated'], $edited, 'run-1'));
        self::assertNull(ModelProvenancePolicy::forPatch($m['generated'], $m['patch'], 'run-2'));
        self::assertNull(ModelProvenancePolicy::forPatch($m['generated'], $m['patch'], null));
        self::assertNull(ModelProvenancePolicy::forPatch(null, $m['patch'], 'run-1'));
        self::assertNull(ModelProvenancePolicy::forPatch('junk', $m['patch'], 'run-1'));
        self::assertNull(ModelProvenancePolicy::forPatch([], $m['patch'], 'run-1'));
    }
}
