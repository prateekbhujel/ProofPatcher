<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PullRequestActions;
use ProofPatch\Models\AutopilotIssueAction;
use ProofPatch\Models\MaintainerAutopilotSetting;
use ProofPatch\Services\MaintainerIssueWatcher;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MaintainerAutopilotFlowTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REPO = 'example-owner/proofpatch-test';

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testMaintainerFlagsDefaultOffAndCanBeSetPerRepository(): void
    {
        self::assertSame(
            ['fixIssues' => false, 'autoReady' => false, 'autoMerge' => false],
            MaintainerAutopilotSetting::flagsFor(self::REPO)
        );
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $flags = MaintainerAutopilotSetting::flagsFor(self::REPO);
        self::assertTrue($flags['autoMerge']);
        self::assertFalse($flags['fixIssues']);
    }

    public function testWatcherAsksForInfoOncePerIssueRevision(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'fix_issues', true);
        $watcher = new MaintainerIssueWatcher($this->github([
            ['number' => 3, 'title' => 'Broken', 'body' => 'it fails', 'state' => 'open',
                'user' => ['login' => 'reporter'], 'html_url' => 'https://example.test/3',
                'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z', 'labels' => []],
        ]));
        $authorized = [strtolower(self::REPO) => ['isPrivate' => false]];

        $first = $watcher->poll('tok', 7, $authorized, [self::REPO]);
        self::assertSame(1, $first['issuesInspected']);
        self::assertSame('needs_info', $first['actions'][0]['action']);
        self::assertFalse($first['actions'][0]['published']);

        $second = $watcher->poll('tok', 7, $authorized, [self::REPO]);
        self::assertSame([], $second['actions']);
        self::assertSame(1, AutopilotIssueAction::count());
    }

    public function testWatcherMarksCompleteDemoIssueFixEligibleWhenEnabled(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'fix_issues', true);
        $body = "Steps to reproduce:\n1. Add two items\n2. View total\nExpected: sum of both. Actual: first item only instead.\nEnvironment: PHP 8.4 commit abc.\n```\nOrderTotal.php line 12\n```";
        $watcher = new MaintainerIssueWatcher($this->github([
            ['number' => 1, 'title' => 'Order total is wrong for multi item carts', 'body' => $body, 'state' => 'open',
                'user' => ['login' => 'reporter'], 'html_url' => 'https://example.test/1',
                'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z', 'labels' => []],
        ]));
        $result = $watcher->poll('tok', 7, [strtolower(self::REPO) => ['isPrivate' => false]], [self::REPO]);
        self::assertSame('fix_eligible', $result['actions'][0]['action']);
    }

    public function testMarkReadyUsesGraphqlWithPinnedRevision(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options['body'] ?? null];
            if ($method === 'GET') {
                return new MockResponse(json_encode([
                    'number' => 5, 'state' => 'open', 'merged' => false, 'draft' => true,
                    'mergeable' => true, 'node_id' => 'PR_node', 'head' => ['sha' => self::HEAD],
                ]));
            }
            return new MockResponse(json_encode([
                'data' => ['markPullRequestReadyForReview' => ['pullRequest' => ['isDraft' => false]]],
            ]));
        });
        $access = ['userId' => 7, 'repositories' => ['acme/app' => true]];
        $pull = ['repository' => 'acme/app', 'number' => 5, 'headSha' => self::HEAD];
        self::assertSame(['draft' => false], (new PullRequestActions($http))->markReady('tok', $access, $pull, self::HEAD));
        self::assertStringEndsWith('/graphql', $requests[1][1]);
        self::assertStringContainsString('markPullRequestReadyForReview', (string) $requests[1][2]);
    }

    /** @param list<array<string, mixed>> $issues */
    private function github(array $issues): GitHubAppClient
    {
        return new GitHubAppClient(new MockHttpClient(static function (string $method, string $url) use ($issues) {
            if (str_contains($url, '/issues?')) {
                return new MockResponse(json_encode($issues));
            }
            return new MockResponse(json_encode([]));
        }));
    }
}
