<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\AutoMergeGate;

final class AutoMergeGateTest extends TestCase
{
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function passing(array $overrides = []): array
    {
        return array_merge([
            'autoMergeEnabled' => true,
            'autoReadyEnabled' => true,
            'reviewStatus' => 'completed',
            'recommendation' => 'approve',
            'checksState' => 'success',
            'unresolvedThreads' => 0,
            'isDraft' => false,
            'state' => 'open',
            'isMerged' => false,
            'reviewedHead' => self::HEAD,
            'currentHead' => self::HEAD,
        ], $overrides);
    }

    public function testAllowsMergeWhenEveryGatePasses(): void
    {
        $result = AutoMergeGate::evaluate($this->passing());
        self::assertTrue($result['allowed'], implode('; ', $result['reasons']));
        self::assertSame([], $result['reasons']);
        self::assertFalse($result['needsReady']);
    }

    public function testDraftNeedsReadyStepWhenAutoReadyIsOn(): void
    {
        $result = AutoMergeGate::evaluate($this->passing(['isDraft' => true]));
        self::assertTrue($result['allowed'], implode('; ', $result['reasons']));
        self::assertTrue($result['needsReady']);
    }

    /** @return iterable<string, array{array, string}> */
    public static function blockedCases(): iterable
    {
        yield 'auto-merge off' => [['autoMergeEnabled' => false], 'auto-merge is off'];
        yield 'review not completed' => [['reviewStatus' => 'failed'], 'review is not completed'];
        yield 'recommendation not approve' => [['recommendation' => 'request_changes'], 'recommendation is request_changes'];
        yield 'checks failing' => [['checksState' => 'failure'], 'checks are not green'];
        yield 'checks pending' => [['checksState' => 'pending'], 'checks are not green'];
        yield 'checks unknown' => [['checksState' => 'unknown'], 'checks are not green'];
        yield 'threads unknown' => [['unresolvedThreads' => null], 'unresolved thread count is unknown'];
        yield 'threads unresolved' => [['unresolvedThreads' => 2], 'unresolved review threads remain'];
        yield 'already merged' => [['isMerged' => true, 'state' => 'closed'], 'already merged'];
        yield 'not open' => [['state' => 'closed'], 'not open'];
        yield 'branch moved' => [['currentHead' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], 'branch changed'];
        yield 'draft without auto-ready' => [['isDraft' => true, 'autoReadyEnabled' => false], 'still a draft'];
    }

    #[DataProvider('blockedCases')]
    public function testBlocksMergeAndSaysWhy(array $overrides, string $reasonPart): void
    {
        $result = AutoMergeGate::evaluate($this->passing($overrides));
        self::assertFalse($result['allowed']);
        self::assertStringContainsString($reasonPart, implode('; ', $result['reasons']));
    }

    public function testCollectsMultipleReasonsAtOnce(): void
    {
        $result = AutoMergeGate::evaluate($this->passing([
            'recommendation' => 'discuss', 'checksState' => 'pending', 'unresolvedThreads' => 1,
        ]));
        self::assertFalse($result['allowed']);
        self::assertGreaterThanOrEqual(3, count($result['reasons']));
    }
}
