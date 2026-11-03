<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\Application\Workbench\IssueMaintainerTriage;

final class IssueMaintainerTriageTest extends TestCase
{
    private function completeIssue(array $overrides = []): array
    {
        return array_merge([
            'repository' => 'example-owner/proofpatch-test',
            'number' => 1,
            'title' => 'Order total is wrong for multi item carts',
            'body' => "Steps to reproduce:\n1. Add two items\n2. View total\nExpected: sum of both items. Actual: only first item counted instead.\nEnvironment: PHP 8.4, commit abc.\n```\nOrderTotal.php line 12\n```",
        ], $overrides);
    }

    public function testAsksForInfoWhenIssueIsIncomplete(): void
    {
        $decision = IssueMaintainerTriage::decide(
            $this->completeIssue(['title' => 'Broken', 'body' => 'it fails']),
            [], [], false, null
        );
        self::assertSame('needs_info', $decision['action']);
        self::assertNotSame([], $decision['questions']);
    }

    public function testDetectsDuplicateOpenIssue(): void
    {
        $other = $this->completeIssue(['number' => 7, 'title' => 'Order total wrong for multi item carts']);
        $decision = IssueMaintainerTriage::decide($this->completeIssue(), [$other], [], false, null);
        self::assertSame('duplicate', $decision['action']);
        self::assertSame(7, $decision['duplicateOf']);
    }

    public function testDetectsOpenPullRequestAlreadyFixingIt(): void
    {
        $pulls = [['number' => 9, 'state' => 'open', 'isMerged' => false, 'title' => 'Fix order total', 'body' => 'Fixes #1']];
        $decision = IssueMaintainerTriage::decide($this->completeIssue(), [], $pulls, true, 'example-owner/proofpatch-test');
        self::assertSame('has_open_pr', $decision['action']);
        self::assertSame(9, $decision['pullNumber']);
    }

    public function testDetectsMergedFixSoIssueCanBeClosed(): void
    {
        $pulls = [['number' => 9, 'state' => 'closed', 'isMerged' => true, 'title' => 'Fix #1 total', 'body' => '']];
        $decision = IssueMaintainerTriage::decide($this->completeIssue(), [], $pulls, false, null);
        self::assertSame('already_fixed', $decision['action']);
        self::assertSame(9, $decision['pullNumber']);
    }

    public function testCompleteDemoIssueIsFixEligibleOnlyWhenFixEnabled(): void
    {
        $on = IssueMaintainerTriage::decide($this->completeIssue(), [], [], true, 'example-owner/proofpatch-test');
        self::assertSame('fix_eligible', $on['action']);

        $off = IssueMaintainerTriage::decide($this->completeIssue(), [], [], false, 'example-owner/proofpatch-test');
        self::assertSame('no_fix_scope', $off['action']);

        $otherRepo = IssueMaintainerTriage::decide(
            $this->completeIssue(['repository' => 'acme/other']), [], [], true, 'example-owner/proofpatch-test'
        );
        self::assertSame('no_fix_scope', $otherRepo['action']);
    }

    public function testNonDemoIssueNumberIsNotFixEligible(): void
    {
        $decision = IssueMaintainerTriage::decide(
            $this->completeIssue(['number' => 42]), [], [], true, 'example-owner/proofpatch-test'
        );
        self::assertSame('no_fix_scope', $decision['action']);
    }
}
