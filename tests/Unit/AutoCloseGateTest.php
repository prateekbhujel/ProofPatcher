<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Repository\AutoCloseGate;

final class AutoCloseGateTest extends TestCase
{
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function passing(array $overrides = []): array
    {
        return array_merge([
            'autoCloseEnabled' => true,
            'reviewStatus' => 'completed',
            'recommendation' => 'request_changes',
            'checksState' => 'failure',
            'concernCount' => 2,
            'state' => 'open',
            'isMerged' => false,
            'reviewedHead' => self::HEAD,
            'currentHead' => self::HEAD,
        ], $overrides);
    }

    public function testAllowsCloseForFailingRejectedRevision(): void
    {
        $result = AutoCloseGate::evaluate($this->passing());
        self::assertTrue($result['allowed'], implode('; ', $result['reasons']));
    }

    /** @return iterable<string, array{array, string}> */
    public static function blockedCases(): iterable
    {
        yield 'auto-close off' => [['autoCloseEnabled' => false], 'auto-close is off'];
        yield 'review not completed' => [['reviewStatus' => 'failed'], 'review is not completed'];
        yield 'not rejected' => [['recommendation' => 'discuss'], 'recommendation is discuss'];
        yield 'approve never closes' => [['recommendation' => 'approve'], 'recommendation is approve'];
        yield 'checks not failing' => [['checksState' => 'pending'], 'checks are not failing'];
        yield 'checks green' => [['checksState' => 'success'], 'checks are not failing'];
        yield 'no concerns' => [['concernCount' => 0], 'no blocking concern'];
        yield 'already merged' => [['isMerged' => true], 'already merged'];
        yield 'not open' => [['state' => 'closed'], 'not open'];
        yield 'branch moved' => [['currentHead' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], 'branch changed'];
    }

    #[DataProvider('blockedCases')]
    public function testBlocksCloseAndSaysWhy(array $overrides, string $reasonPart): void
    {
        $result = AutoCloseGate::evaluate($this->passing($overrides));
        self::assertFalse($result['allowed']);
        self::assertStringContainsString($reasonPart, implode('; ', $result['reasons']));
    }
}
