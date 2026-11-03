<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\GitHubAppClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubMergeReadinessTest extends TestCase
{
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function readiness(array $pages, array $statuses, array $threads): array
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($pages, $statuses, $threads): MockResponse {
            if (str_contains($url, '/check-runs?')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                return new MockResponse(json_encode($pages[(int) ($query['page'] ?? 1)] ?? [], JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/status?')) return new MockResponse(json_encode($statuses, JSON_THROW_ON_ERROR));
            if (str_ends_with($url, '/graphql')) {
                return new MockResponse(json_encode(['data' => ['repository' => ['pullRequest' => ['reviewThreads' => $threads]]]], JSON_THROW_ON_ERROR));
            }
            return new MockResponse(json_encode(['state' => 'open', 'merged' => false, 'draft' => false,
                'head' => ['sha' => self::HEAD], 'base' => ['sha' => self::BASE]], JSON_THROW_ON_ERROR));
        });
        return (new GitHubAppClient($http))->getMergeReadiness('private-test-token', 'example/repo', 1, self::HEAD);
    }

    private function noThreads(): array
    {
        return ['pageInfo' => ['hasNextPage' => false], 'nodes' => []];
    }

    public function testChecksBeyondFirstPageAreReadAndCanBlock(): void
    {
        $green = array_map(static fn (int $id): array => ['id' => $id, 'status' => 'completed', 'conclusion' => 'success'], range(1, 100));
        $pages = [1 => ['total_count' => 101, 'check_runs' => $green],
            2 => ['total_count' => 101, 'check_runs' => [['id' => 101, 'status' => 'completed', 'conclusion' => 'failure']]]];
        $result = $this->readiness($pages, ['total_count' => 0, 'state' => 'pending'], $this->noThreads());
        self::assertSame('failure', $result['checksState']);
        self::assertSame(self::BASE, $result['currentBase']);
    }

    public function testCompleteGreenChecksAndNoLegacyStatusesPass(): void
    {
        $result = $this->readiness([1 => ['total_count' => 1, 'check_runs' => [['id' => 1, 'status' => 'completed', 'conclusion' => 'success']]]],
            ['total_count' => 0, 'state' => 'pending'], $this->noThreads());
        self::assertSame('success', $result['checksState']);
        self::assertSame(0, $result['unresolvedThreads']);
    }

    public function testLegacyStatusFailureAndPendingBlockGreenCheckRuns(): void
    {
        $pages = [1 => ['total_count' => 1, 'check_runs' => [['id' => 1, 'status' => 'completed', 'conclusion' => 'success']]]];
        self::assertSame('failure', $this->readiness($pages, ['total_count' => 1, 'state' => 'failure'], $this->noThreads())['checksState']);
        self::assertSame('pending', $this->readiness($pages, ['total_count' => 1, 'state' => 'pending'], $this->noThreads())['checksState']);
    }

    public function testTruncationOrChangingTotalsNeverPass(): void
    {
        $green = ['id' => 1, 'status' => 'completed', 'conclusion' => 'success'];
        $shortPage = [1 => ['total_count' => 101, 'check_runs' => [$green]]];
        $firstPage = array_map(static fn (int $id): array => ['id' => $id, 'status' => 'completed', 'conclusion' => 'success'], range(1, 100));
        $changed = [1 => ['total_count' => 101, 'check_runs' => $firstPage],
            2 => ['total_count' => 102, 'check_runs' => [['id' => 101, 'status' => 'completed', 'conclusion' => 'success']]]];
        $duplicate = [1 => ['total_count' => 101, 'check_runs' => $firstPage],
            2 => ['total_count' => 101, 'check_runs' => [$green]]];
        self::assertSame('unknown', $this->readiness($shortPage, ['total_count' => 0, 'state' => 'pending'], $this->noThreads())['checksState']);
        self::assertSame('unknown', $this->readiness($changed, ['total_count' => 0, 'state' => 'pending'], $this->noThreads())['checksState']);
        self::assertSame('unknown', $this->readiness($duplicate, ['total_count' => 0, 'state' => 'pending'], $this->noThreads())['checksState']);
    }

    public function testMalformedThreadAndPaginatedThreadsAreUnknown(): void
    {
        $pages = [1 => ['total_count' => 1, 'check_runs' => [['id' => 1, 'status' => 'completed', 'conclusion' => 'success']]]];
        $statuses = ['total_count' => 0, 'state' => 'pending'];
        self::assertNull($this->readiness($pages, $statuses, ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[]]])['unresolvedThreads']);
        self::assertNull($this->readiness($pages, $statuses, ['pageInfo' => ['hasNextPage' => true], 'nodes' => []])['unresolvedThreads']);
    }

    public function testMissingChecksAndUnreadableStatusesStayUnknown(): void
    {
        self::assertSame('unknown', $this->readiness([1 => ['total_count' => 0, 'check_runs' => []]],
            ['total_count' => 0, 'state' => 'pending'], $this->noThreads())['checksState']);
        self::assertSame('unknown', $this->readiness([1 => ['total_count' => 1, 'check_runs' => [['id' => 1, 'status' => 'completed', 'conclusion' => 'success']]]],
            [], $this->noThreads())['checksState']);
    }
}
