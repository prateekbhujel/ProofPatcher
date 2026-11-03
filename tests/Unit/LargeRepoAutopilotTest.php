<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\Models\AutopilotIssueAction;
use ProofPatch\Models\MaintainerAutopilotSetting;
use ProofPatch\Services\MaintainerIssueWatcher;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LargeRepoAutopilotTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

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

    public function testWatcherHandlesABigIssueBacklogOncePerRevision(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'fix_issues', true);
        $issues = [];
        for ($i = 1; $i <= 120; $i++) {
            $issues[] = ['number' => $i, 'title' => "Broken thing number {$i}", 'body' => 'it fails', 'state' => 'open',
                'user' => ['login' => 'reporter'], 'html_url' => "https://example.test/{$i}",
                'created_at' => '2026-11-01T00:00:00Z', 'updated_at' => '2026-11-01T00:00:00Z', 'labels' => []];
        }
        $watcher = new MaintainerIssueWatcher($this->githubWithIssues($issues));
        $authorized = [strtolower(self::REPO) => ['isPrivate' => false]];

        $first = $watcher->poll('tok', 7, $authorized, [self::REPO]);
        self::assertSame(120, $first['issuesInspected']);
        self::assertCount(120, $first['actions']);
        self::assertSame(120, AutopilotIssueAction::count());

        $second = $watcher->poll('tok', 7, $authorized, [self::REPO]);
        self::assertSame([], $second['actions']);
        self::assertSame(120, AutopilotIssueAction::count());
    }

    public function testPullListingStaysCorrectAcrossManyPages(): void
    {
        $makePull = static fn (int $n): array => [
            'number' => $n, 'title' => "PR {$n}", 'state' => 'open',
            'head' => ['sha' => str_repeat('a', 40), 'ref' => 'feat'], 'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main'],
            'user' => ['login' => 'author'], 'body' => '',
        ];
        $pages = [
            1 => array_map($makePull, range(1, 100)),
            2 => array_map($makePull, range(101, 200)),
            3 => array_map($makePull, range(201, 250)),
        ];
        $client = new GitHubAppClient(new MockHttpClient(static function (string $method, string $url) use ($pages) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return new MockResponse(json_encode($pages[(int) ($q['page'] ?? 1)] ?? []));
        }));
        $status = [];
        $pulls = $client->listOpenPullRequests('tok', 'acme/big', $status);
        self::assertCount(250, $pulls);
        self::assertFalse($status['truncated']);
        self::assertSame([], $status['errors']);
    }

    /** @param list<array<string, mixed>> $issues */
    private function githubWithIssues(array $issues): GitHubAppClient
    {
        return new GitHubAppClient(new MockHttpClient(static function (string $method, string $url) use ($issues) {
            if (str_contains($url, '/issues?')) {
                return new MockResponse(json_encode($issues));
            }
            return new MockResponse(json_encode([]));
        }));
    }
}
