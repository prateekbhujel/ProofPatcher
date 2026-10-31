<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Support\IssueCompleteness;

final class IssueCompletenessTest extends TestCase
{
    public function testEmptyReportScoresZero(): void
    {
        $result = IssueCompleteness::analyze('', '');

        $this->assertSame(0, $result['score']);
        $this->assertSame('empty', $result['verdict']);
        $this->assertContains('Written description', $result['missing']);
    }

    public function testCompleteReportPassesEveryCheck(): void
    {
        $result = IssueCompleteness::analyze(
            'Zero-quantity order items are charged once instead of zero',
            <<<'BODY'
                Steps to reproduce:
                1. Add an item with quantity 0 to the cart.
                2. Submit the order.

                Expected: the line total is 0.
                Actual: the line total is charged once.

                Version 2.4.1 on PHP 8.4.

                ```
                OrderTotal::line(0, 10) // returns 10, should return 0
                ```
                BODY
        );

        $this->assertSame(100, $result['score']);
        $this->assertSame('complete', $result['verdict']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['questions']);
    }

    public function testThinReportListsExactlyWhatIsMissing(): void
    {
        $result = IssueCompleteness::analyze(
            'Checkout total looks wrong when items are free',
            'The total seems off for free items. I expected it to be fine but it is not.'
        );

        $this->assertSame('needs_info', $result['verdict']);
        $this->assertContains('Reproduction steps', $result['missing']);
        $this->assertContains('Environment details', $result['missing']);
        $this->assertContains('Logs or code evidence', $result['missing']);
        $this->assertNotContains('Expected vs actual', $result['missing']);
        $this->assertCount(3, $result['questions']);
        $this->assertStringContainsString('steps to reproduce', $result['questions'][0]);
    }

    public function testScreenshotWithoutTextReproIsFlagged(): void
    {
        $result = IssueCompleteness::analyze(
            'Total display glitch on checkout page',
            'See screenshot. It is broken! ![shot](https://example.com/a.png)'
        );

        $this->assertSame('needs_info', $result['verdict']);
        $this->assertContains('Text alongside screenshots', $result['missing']);
        $this->assertContains(
            'Could you add the written steps to reproduce alongside the screenshots?',
            $result['questions']
        );
    }

    public function testOversizedInputIsBounded(): void
    {
        $result = IssueCompleteness::analyze('A title with enough words to pass', str_repeat('x', 100000));

        $this->assertSame('needs_info', $result['verdict']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
