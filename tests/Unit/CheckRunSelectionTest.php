<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Evidence\CheckRunSelection;
use ProofPatch\Patch\CssPatch;

final class CheckRunSelectionTest extends TestCase
{
    public function testOnlyCheckoutReportsAppearInCheckoutHistory(): void
    {
        self::assertTrue(CheckRunSelection::isCheckoutReport(['decision' => 'VERIFIED']));
        self::assertTrue(CheckRunSelection::isCheckoutReport(['kind' => 'checkout']));
        self::assertFalse(CheckRunSelection::isCheckoutReport(['kind' => 'owned_backend', 'isSynthetic' => true]));
        self::assertFalse(CheckRunSelection::isCheckoutReport(['context' => [], 'isSynthetic' => true]));
        self::assertFalse(CheckRunSelection::isCheckoutReport(['kind' => 'unknown']));
    }

    public function testLatestVisualPrefersVerified(): void
    {
        $history = [
            'run-new' => ['decision' => 'FAILED', 'hasVisual' => true],
            'run-old' => ['decision' => 'VERIFIED', 'hasVisual' => true],
        ];
        self::assertSame('run-old', CheckRunSelection::latestVisualRunId($history));
        self::assertNull(CheckRunSelection::latestVisualRunId([]));
        self::assertNull(CheckRunSelection::latestVisualRunId(['r' => ['decision' => 'VERIFIED']]));
        self::assertSame('r', CheckRunSelection::latestVisualRunId(['r' => ['decision' => 'FAILED', 'hasVisual' => true]]));
    }

    public function testDefaultRunIdFallsBackInOrder(): void
    {
        $history = [
            'run-new' => ['decision' => 'FAILED', 'hasVisual' => true],
            'run-old' => ['decision' => 'VERIFIED', 'hasVisual' => true],
        ];
        self::assertSame('run-old', CheckRunSelection::defaultRunId($history, 'run-old'));
        self::assertSame('run-old', CheckRunSelection::defaultRunId($history, null));
        self::assertSame(
            'run-new',
            CheckRunSelection::defaultRunId(['run-new' => ['decision' => 'FAILED']], null)
        );
    }

    public function testFixtureResolutionOrder(): void
    {
        $proposal = ['patch' => ['file' => CssPatch::SECOND_FILE]];
        $report = ['proposal' => ['file' => CssPatch::THIRD_FILE]];

        self::assertSame(CssPatch::SECOND_FILE, CheckRunSelection::fixture(CssPatch::SECOND_FILE, $proposal, $report));
        self::assertSame(CssPatch::SECOND_FILE, CheckRunSelection::fixture(null, $proposal, $report));
        self::assertSame(CssPatch::THIRD_FILE, CheckRunSelection::fixture(null, null, $report));
        self::assertSame(CssPatch::FILE, CheckRunSelection::fixture(null, null, null));
        self::assertSame(CssPatch::FILE, CheckRunSelection::fixture('nope.css', $proposal, $report));
        self::assertSame(CssPatch::FILE, CheckRunSelection::fixture('', $proposal, $report));
        self::assertSame(CssPatch::FILE, CheckRunSelection::fixture(['x'], $proposal, $report));
    }
}
