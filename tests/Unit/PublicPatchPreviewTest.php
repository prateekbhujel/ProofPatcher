<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\PublicPatchPreview;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PublicPatchPreviewTest extends TestCase
{
    private function captured(): array
    {
        return ['repository' => 'example/project', 'number' => 12,
            'headSha' => str_repeat('a', 40), 'baseSha' => str_repeat('b', 40)];
    }

    private function pull(int $count = 1): array
    {
        return ['number' => 12, 'head' => ['sha' => str_repeat('a', 40)],
            'base' => ['sha' => str_repeat('b', 40)], 'changed_files' => $count];
    }

    private function repo(): array
    {
        return ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
    }

    private function client(array $responses, array &$requests): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, &$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
            self::assertSame(0, $options['max_redirects']);
            foreach ($options['normalized_headers'] ?? [] as $name => $value) {
                self::assertNotSame('authorization', strtolower($name));
            }
            self::assertStringNotContainsString('Authorization:', implode(' ', $options['headers'] ?? []));
            if ($responses === []) self::fail('Unexpected extra request.');
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
    }

    public function testPreviewIsUnauthenticatedBoundedAndLabelsIncompleteData(): void
    {
        $files = [
            ['filename' => 'src/a.php', 'additions' => 1, 'deletions' => 1, 'patch' => "@@ -1 +1 @@\n-old\n+new"],
            ['filename' => 'image.bin', 'additions' => 0, 'deletions' => 0],
            ['filename' => 'large.txt', 'additions' => 1, 'deletions' => 0, 'patch' => '+'.str_repeat('x', 65536)],
            ['filename' => 'partial.txt', 'additions' => 2, 'deletions' => 0, 'patch' => "@@ -0,0 +1,2 @@\n+one"],
        ];
        $requests = [];
        $preview = (new PublicPatchPreview($this->client([$this->repo(), $this->pull(5), $files, $this->pull(5), $this->repo()], $requests)))->fetch($this->captured());
        self::assertCount(5, $requests);
        self::assertSame(['available', 'missing', 'oversize', 'partial'], array_column($preview['files'], 'status'));
        self::assertSame("@@ -1 +1 @@\n-old\n+new", $preview['files'][0]['patch']);
        self::assertNull($preview['files'][2]['patch']);
        self::assertTrue($preview['filesTruncated']);
        self::assertSame($this->captured()['headSha'], $preview['headSha']);
    }

    public function testPrivateOrUnverifiedRepositoryFailsBeforePatchFetch(): void
    {
        foreach ([['full_name' => 'example/project', 'private' => true, 'visibility' => 'private'],
            ['full_name' => 'example/project', 'private' => false], ['full_name' => 'other/project', 'private' => false, 'visibility' => 'public']] as $repo) {
            $requests = [];
            try {
                (new PublicPatchPreview($this->client([$repo], $requests)))->fetch($this->captured());
                self::fail('Unverified public visibility was accepted.');
            } catch (\RuntimeException) {
                self::assertCount(1, $requests);
            }
        }
    }

    public function testChangedHeadOrBaseNeverProducesPreview(): void
    {
        foreach (['head', 'base'] as $which) {
            $changed = $this->pull();
            $changed[$which]['sha'] = str_repeat('c', 40);
            foreach ([$changed, $this->pull()] as $first) {
                $requests = [];
                $responses = [$this->repo(), $first];
                if ($first === $this->pull()) {
                    $responses[] = [['filename' => 'a', 'additions' => 0, 'deletions' => 0]];
                    $responses[] = $changed;
                }
                try {
                    (new PublicPatchPreview($this->client($responses, $requests)))->fetch($this->captured());
                    self::fail('Stale pull request was accepted.');
                } catch (\RuntimeException) {
                    self::assertCount($first === $changed ? 2 : 4, $requests);
                }
            }
        }
    }

    public function testVisibilityChangeAfterFetchFailsClosed(): void
    {
        $requests = [];
        $private = ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private'];
        $this->expectException(\RuntimeException::class);
        (new PublicPatchPreview($this->client([$this->repo(), $this->pull(0), [], $this->pull(0), $private], $requests)))->fetch($this->captured());
    }

    public function testCapturedChangedFileCountMustStillMatch(): void
    {
        $requests = [];
        $captured = $this->captured();
        $captured['changedFiles'] = 2;
        try {
            (new PublicPatchPreview($this->client([$this->repo(), $this->pull(1)], $requests)))->fetch($captured);
            self::fail('A changed file count was accepted.');
        } catch (\RuntimeException) {
            self::assertCount(2, $requests);
        }
    }

    public function testInvalidSnapshotIsRejectedBeforeNetwork(): void
    {
        $requests = [];
        $this->expectException(\InvalidArgumentException::class);
        (new PublicPatchPreview($this->client([], $requests)))->fetch(['repository' => 'example/../evil']);
    }

    public function testPaginationFetchesAll101FilesAndTheApprovedDigestCoversEveryPage(): void
    {
        $files = [];
        for ($i = 0; $i < 101; ++$i) {
            $files[] = ['filename' => sprintf('src/file%03d.php', $i), 'additions' => 1, 'deletions' => 0,
                'patch' => "@@ -0,0 +1 @@\n+".$i];
        }
        $requests = [];
        $responses = [$this->repo(), $this->pull(101), array_slice($files, 0, 100), array_slice($files, 100), $this->pull(101), $this->repo()];
        $preview = (new PublicPatchPreview($this->client($responses, $requests)))->fetch($this->captured());
        self::assertCount(101, $preview['files']);
        self::assertFalse($preview['filesTruncated']);
        self::assertSame('https://api.github.com/repos/example/project/pulls/12/files?per_page=100&page=2', $requests[3][1]);
        $budget = new \ProofPatch\Agent\RequestBudget(sys_get_temp_dir().'/unused-proofpatch-preview', 1000000, 5);
        $agent = new \ProofPatch\Agent\PullRequestReview(new MockHttpClient(), $budget);
        $pull = $this->captured() + ['title' => 'Large review'];
        $first = $agent->preview($pull, $preview);
        self::assertSame(101, $first['filesCount']);
        $preview['files'][100]['patch'] = "@@ -0,0 +1 @@\n+changed";
        self::assertNotSame($first['digest'], $agent->preview($pull, $preview)['digest']);
    }

    public function testPagedPreviewRejectsDuplicatesOrRevisionChanges(): void
    {
        $page = [];
        for ($i = 0; $i < 100; ++$i) $page[] = ['filename' => 'src/'.$i, 'additions' => 1, 'deletions' => 0, 'patch' => '+a'];
        foreach (['duplicate', 'head', 'base', 'visibility'] as $case) {
            $next = [['filename' => $case === 'duplicate' ? 'src/0' : 'src/100', 'additions' => 1, 'deletions' => 0, 'patch' => '+b']];
            $last = $this->pull(101);
            if ($case === 'head' || $case === 'base') $last[$case]['sha'] = str_repeat('c', 40);
            $lastRepo = $case === 'visibility' ? ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private'] : $this->repo();
            $requests = [];
            try {
                (new PublicPatchPreview($this->client([$this->repo(), $this->pull(101), $page, $next, $last, $lastRepo], $requests)))->fetch($this->captured());
                self::fail('Invalid paged preview was accepted: '.$case);
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testMoreThan300OrAShortPageIsReportedIncomplete(): void
    {
        $page = static function (int $from): array {
            $files = [];
            for ($i = $from; $i < $from + 100; ++$i) $files[] = ['filename' => 'src/'.$i, 'additions' => 1, 'deletions' => 0, 'patch' => '+a'];
            return $files;
        };
        $requests = [];
        $preview = (new PublicPatchPreview($this->client([$this->repo(), $this->pull(301), $page(0), $page(100), $page(200), $this->pull(301), $this->repo()], $requests)))->fetch($this->captured());
        self::assertTrue($preview['filesTruncated']);
        self::assertCount(300, $preview['files']);
        self::assertCount(7, $requests);
        $agent = new \ProofPatch\Agent\PullRequestReview(new MockHttpClient(), new \ProofPatch\Agent\RequestBudget(sys_get_temp_dir().'/unused-proofpatch-preview', 1000000, 5));
        try { $agent->preview($this->captured(), $preview); self::fail('Incomplete PR must not reach the model.'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('diff is incomplete', $e->getMessage()); }

        $requests = [];
        $short = (new PublicPatchPreview($this->client([$this->repo(), $this->pull(101), array_slice($page(0), 0, 99), $this->pull(101), $this->repo()], $requests)))->fetch($this->captured());
        self::assertTrue($short['filesTruncated']);
        self::assertCount(5, $requests, 'A short page must not trigger further requests.');
    }

    public function testCompleteFortyKilobytePatchIsAvailableButOver64KiBIsNot(): void
    {
        $files = [
            ['filename' => 'src/large.php', 'additions' => 1, 'deletions' => 0, 'patch' => '+'.str_repeat('x', 40000)],
            ['filename' => 'src/too-large.php', 'additions' => 1, 'deletions' => 0, 'patch' => '+'.str_repeat('x', 65536)],
        ];
        $requests = [];
        $preview = (new PublicPatchPreview($this->client([$this->repo(), $this->pull(2), $files, $this->pull(2), $this->repo()], $requests)))->fetch($this->captured());
        self::assertSame(['available', 'oversize'], array_column($preview['files'], 'status'));
        self::assertNull($preview['files'][1]['patch']);
    }

    public function testAggregatePatchLimitStillRefusesAnIncompleteReview(): void
    {
        $files = [];
        for ($i = 0; $i < 3; ++$i) $files[] = ['filename' => 'src/'.$i.'.php', 'additions' => 1, 'deletions' => 0,
            'patch' => '+'.str_repeat('x', 60000)];
        $requests = [];
        $preview = (new PublicPatchPreview($this->client([$this->repo(), $this->pull(3), $files, $this->pull(3), $this->repo()], $requests)))->fetch($this->captured());
        self::assertSame(['available', 'available', 'oversize'], array_column($preview['files'], 'status'));
        $agent = new \ProofPatch\Agent\PullRequestReview(new MockHttpClient(), new \ProofPatch\Agent\RequestBudget(sys_get_temp_dir().'/unused-proofpatch-preview', 1000000, 5));
        $this->expectExceptionMessage('diff is incomplete');
        $agent->preview($this->captured(), $preview);
    }

    public function testAuthenticatedPreviewUsesOnlyGithubAndStillRejectsPrivateOrChangedBase(): void
    {
        $token = 'ghu_test_token_123456789';
        foreach (['private', 'changed'] as $scenario) {
            $calls = 0;
            $client = new MockHttpClient(function (string $method, string $url, array $options) use ($token, $scenario, &$calls) {
                ++$calls;
                self::assertSame('GET', $method);
                self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
                self::assertSame(0, $options['max_redirects']);
                self::assertContains('Authorization: Bearer '.$token, $options['headers']);
                $response = $this->repo();
                if ($scenario === 'private') $response = ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private'];
                if ($calls === 2) { $response = $this->pull(); $response['base']['sha'] = str_repeat('c', 40); }
                return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR));
            });
            try {
                (new PublicPatchPreview($client, $token))->fetch($this->captured());
                self::fail('Expected private or changed repository to be rejected.');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString($token, $e->getMessage());
                self::assertSame($scenario === 'private' ? 1 : 2, $calls);
            }
        }
    }

    public function testAuthenticatedResponseAndTransportFailuresDoNotExposeTokenOrRetryAnonymously(): void
    {
        // A JSON-escaped token must not evade detection when decoded.
        $token = 'abc"defghijklmnop123';
        $calls = 0;
        $client = new MockHttpClient(function () use ($token, &$calls) {
            ++$calls;
            return new MockResponse(json_encode($this->repo() + ['description' => $token], JSON_THROW_ON_ERROR));
        });
        try {
            (new PublicPatchPreview($client, $token))->fetch($this->captured());
            self::fail('Credential in decoded response was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertSame(1, $calls);
        }

        foreach (['denied', 'transport'] as $mode) {
            $calls = 0;
            $client = new MockHttpClient(static function () use ($token, $mode, &$calls) {
                ++$calls;
                if ($mode === 'transport') throw new \RuntimeException('failure with '.$token);
                return new MockResponse('denied '.$token, ['http_code' => 403]);
            });
            try {
                (new PublicPatchPreview($client, $token))->fetch($this->captured());
                self::fail('Denied request was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString($token, $e->getMessage());
                self::assertSame(1, $calls, 'Do not retry without authorization.');
            }
        }
    }
}
