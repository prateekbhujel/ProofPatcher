<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use ProofPatch\GitHub\PullRequestActions;
use ProofPatch\Models\MaintainerAutopilotSetting;
use ProofPatch\Models\AutoReviewResult;
use ProofPatch\Models\RepositoryAutoReviewSetting;
use ProofPatch\Services\AutoReviewService;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutopilotMergeExecutionTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const REPO = 'example-owner/demo';

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
        RepositoryAutoReviewSetting::setRepoEnabled(self::REPO, true);
        AutoReviewResult::create(['repository' => self::REPO, 'pull_number' => 1,
            'head_sha' => self::HEAD, 'base_sha' => self::BASE, 'status' => 'completed',
            'is_public' => true, 'recommendation' => 'approve']);
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    public function testApprovedGreenRevisionMergesOnlyOnceAndPinsTheHead(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $writes = [];
        $service = $this->service($writes);
        $first = $this->attempt($service);
        $second = $this->attempt($service);
        self::assertSame('merged', $first['status']);
        self::assertSame('already_attempted', $second['status']);
        self::assertCount(1, $writes);
        self::assertSame(self::HEAD, $writes[0]['sha']);
    }

    public function testFailedChecksBlockAnyMergeWrite(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $writes = [];
        $service = $this->service($writes, 'failure');
        self::assertSame('blocked', $this->attempt($service)['status']);
        self::assertSame([], $writes);
    }

    public function testAnotherUserOrPrivateRepositoryCannotReuseAnOwnersSetting(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $writes = [];
        $service = $this->service($writes);
        self::assertSame('blocked', $this->attempt($service, 'another-user')['status']);
        self::assertSame('blocked', $this->attempt($service, 'example-owner', true)['status']);
        self::assertSame([], $writes);
    }

    public function testMissingOrNonPublicReviewCannotBeUsedForMerge(): void
    {
        MaintainerAutopilotSetting::setFlag(self::REPO, 'auto_merge', true);
        $writes = [];
        $service = $this->service($writes);
        AutoReviewResult::query()->update(['is_public' => false]);
        self::assertSame('blocked', $this->attempt($service)['status']);
        AutoReviewResult::query()->delete();
        self::assertSame('blocked', $this->attempt($service)['status']);
        self::assertSame([], $writes);
    }

    private function attempt(AutoReviewService $service, string $login = 'example-owner', bool $private = false): array
    {
        $method = new \ReflectionMethod($service, 'maybeAutoMerge');
        return $method->invoke($service, 'test-token-not-a-real-secret', 7, $login,
            [self::REPO => ['isPrivate' => $private]], self::REPO,
            ['number' => 1, 'headSha' => self::HEAD, 'baseSha' => self::BASE],
            ['recommendation' => 'approve']);
    }

    private function service(array &$writes, string $conclusion = 'success'): AutoReviewService
    {
        $github = new GitHubAppClient(new MockHttpClient(static function (string $method, string $url) use ($conclusion) {
            if (str_contains($url, '/check-runs?')) return new MockResponse(json_encode(['total_count' => 1,
                'check_runs' => [['id' => 1, 'status' => 'completed', 'conclusion' => $conclusion]]]));
            if (str_contains($url, '/status?')) return new MockResponse(json_encode(['total_count' => 0, 'state' => 'pending']));
            if (str_ends_with($url, '/graphql')) return new MockResponse(json_encode(['data' => ['repository' =>
                ['pullRequest' => ['reviewThreads' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => []]]]]]));
            return new MockResponse(json_encode(['state' => 'open', 'merged' => false, 'draft' => false,
                'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE]]));
        }));
        $actions = new PullRequestActions(new MockHttpClient(static function (string $method, string $url, array $options) use (&$writes) {
            if ($method === 'PUT') {
                $writes[] = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
                return new MockResponse(json_encode(['merged' => true, 'sha' => str_repeat('c', 40)]));
            }
            return new MockResponse(json_encode(['state' => 'open', 'merged' => false, 'draft' => false,
                'mergeable' => true, 'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE]]));
        }));
        return new AutoReviewService(github: $github, pullActions: $actions);
    }
}
