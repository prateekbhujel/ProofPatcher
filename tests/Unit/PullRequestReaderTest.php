<?php

declare(strict_types=1);

namespace ProofPatch\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProofPatch\GitHub\PullRequestReader;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PullRequestReaderTest extends TestCase
{
    public function testReadsRealisticPullRequestFilesAndChecksAtTheExactHead(): void
    {
        $sha = str_repeat('a', 40);
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$urls, $sha): MockResponse {
            $urls[] = $url;
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects']);
            if (str_contains($url, '/files?')) {
                $data = [['filename' => 'src/Checkout.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1]];
            } elseif (str_contains($url, '/check-runs?')) {
                self::assertStringContainsString('/commits/'.$sha.'/', $url);
                $data = ['total_count' => 1, 'check_runs' => [['name' => 'Tests', 'status' => 'completed', 'conclusion' => 'success']]];
            } else {
                $data = ['number' => 12, 'title' => 'Fix checkout', 'state' => 'open', 'changed_files' => 2,
                    'head' => ['sha' => $sha, 'ref' => 'fix'], 'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main']];
            }
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
        });
        $result = (new PullRequestReader($client))->read('https://github.com/example/project/pull/12');
        self::assertSame('example/project', $result['repository']);
        self::assertSame($sha, $result['headSha']);
        self::assertSame(str_repeat('b', 40), $result['baseSha']);
        self::assertTrue($result['filesTruncated']);
        self::assertSame('src/Checkout.php', $result['files'][0]['name']);
        self::assertSame('success', $result['checks'][0]['conclusion']);
        self::assertCount(4, $urls);

        // Test shorthand formats resolve identically
        self::assertSame('https://github.com/owner/repo/pull/123', PullRequestReader::normalizeUrl('owner/repo#123'));
        self::assertSame('https://github.com/owner/repo/pull/123', PullRequestReader::normalizeUrl('owner/repo/pull/123'));
        self::assertSame('https://github.com/owner/repo/pull/123', PullRequestReader::normalizeUrl('github.com/owner/repo/pull/123'));
        self::assertSame('https://github.com/owner/repo/pull/123', PullRequestReader::normalizeUrl('https://github.com/owner/repo/pull/123'));
    }

    public function testRejectsPullRequestOrBaseChangesDuringCapture(): void
    {
        foreach (['head', 'base'] as $changed) {
            $reads = 0;
            $client = new MockHttpClient(function (string $method, string $url) use (&$reads, $changed): MockResponse {
                if (str_contains($url, '/files?')) {
                    return new MockResponse('[]');
                }
                if (str_contains($url, '/check-runs?')) {
                    return new MockResponse('{"total_count":0,"check_runs":[]}');
                }
                ++$reads;
                $data = ['number' => 12, 'title' => 'Fix checkout', 'state' => 'open', 'changed_files' => 0,
                    'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
                    'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main']];
                if ($reads === 2) {
                    $data[$changed]['sha'] = str_repeat('c', 40);
                }
                return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
            });
            try {
                (new PullRequestReader($client))->read('https://github.com/example/project/pull/12');
                self::fail('A moving pull request must not be captured.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('changed while', $error->getMessage());
            }
        }
    }

    public function testRejectsNonGithubAndManipulatedUrlsBeforeNetworkAccess(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse { ++$calls; return new MockResponse('{}'); });
        foreach (['https://github.com.evil.test/a/b/pull/1', 'https://github.com/a/../pull/1',
            'https://github.com/a/b/pull/1?token=secret', 'http://github.com/a/b/pull/1'] as $url) {
            try {
                (new PullRequestReader($client))->read($url);
                self::fail('Invalid URL accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $calls);
            }
        }
    }

    public function testHttpFailureDoesNotExposeResponseContents(): void
    {
        $client = new MockHttpClient(fn() => new MockResponse('private details', ['http_code' => 404]));
        try {
            (new PullRequestReader($client))->read('https://github.com/a/b/pull/1');
            self::fail('HTTP failure accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('HTTP 404', $error->getMessage());
            self::assertStringNotContainsString('private details', $error->getMessage());
        }
    }

    public function testRecognizesMergedPullRequestState(): void
    {
        $sha = str_repeat('a', 40);
        $client = new MockHttpClient(function (string $method, string $url) use ($sha): MockResponse {
            if (str_contains($url, '/files?')) {
                return new MockResponse('[]');
            }
            if (str_contains($url, '/check-runs?')) {
                return new MockResponse('{"total_count":0,"check_runs":[]}');
            }
            $data = [
                'number' => 83,
                'title' => 'Preserve split POSIX UTF-8 input across key reads',
                'state' => 'closed',
                'merged' => true,
                'merged_at' => '2026-09-25T12:16:11Z',
                'changed_files' => 0,
                'head' => ['sha' => $sha, 'ref' => 'fix-posix-readkey-utf8-state'],
                'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => ['private' => false, 'visibility' => 'public']],
            ];
            return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
        });

        $result = (new PullRequestReader($client))->read('https://github.com/prateekbhujel/php-terminal/pull/83');
        self::assertSame('merged', $result['state']);
        self::assertTrue($result['isMerged']);
        self::assertFalse($result['isPrivate']);
    }

    public function testAuthenticatedPublicOnlyCaptureRechecksVisibilityAndRevision(): void
    {
        $token = 'ghu_test_token_123456789';
        $repo = ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
        $pull = ['number' => 12, 'title' => 'Fix checkout', 'state' => 'open', 'changed_files' => 0,
            'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
            'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => $repo]];
        $responses = [$repo, $pull, [], ['total_count' => 0, 'check_runs' => []], $pull, $repo];
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$urls, $token) {
            $urls[] = $url;
            self::assertSame('GET', $method);
            self::assertStringStartsWith('https://api.github.com/repos/example/project', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertContains('Authorization: Bearer '.$token, $options['headers']);
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        $result = (new PullRequestReader($http, $token, publicOnly: true))->read('example/project#12');
        self::assertCount(6, $urls);
        self::assertFalse($result['isPrivate']);
        self::assertSame(str_repeat('b', 40), $result['baseSha']);
        self::assertStringNotContainsString($token, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAuthenticatedPublicOnlyCaptureStopsBeforePullForPrivateOrUncertainRepo(): void
    {
        foreach ([
            ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private'],
            ['full_name' => 'example/project', 'private' => false],
            ['full_name' => 'example/other', 'private' => false, 'visibility' => 'public'],
        ] as $repo) {
            $calls = 0;
            $http = new MockHttpClient(static function () use (&$calls, $repo) { ++$calls; return new MockResponse(json_encode($repo, JSON_THROW_ON_ERROR)); });
            try { (new PullRequestReader($http, 'ghu_test_token_123456789', publicOnly: true))->read('example/project#12'); self::fail('Nonpublic repository accepted.'); }
            catch (\RuntimeException $e) { self::assertSame(1, $calls); self::assertStringContainsString('public', $e->getMessage()); }
        }
    }

    public function testAuthenticatedPublicOnlyCaptureRejectsVisibilityFlipAndEncodedToken(): void
    {
        $token = 'abc"defghijklmnop123';
        $repo = ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
        $pull = ['number' => 12, 'title' => 'Fix checkout', 'state' => 'open', 'changed_files' => 0,
            'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
            'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => $repo]];
        foreach (['visibility', 'encoded-token', 'denied', 'transport'] as $case) {
            $responses = [$repo, $pull, [], ['total_count' => 0, 'check_runs' => []], $pull,
                ['full_name' => 'example/project', 'private' => true, 'visibility' => 'private']];
            $calls = 0;
            $http = new MockHttpClient(static function () use (&$responses, &$calls, $case, $token, $repo) {
                ++$calls;
                if ($case === 'transport') throw new \RuntimeException('bad '.$token);
                if ($case === 'denied') return new MockResponse('bad '.$token, ['http_code' => 403]);
                if ($case === 'encoded-token') return new MockResponse(json_encode($repo + ['description' => $token], JSON_THROW_ON_ERROR));
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            try { (new PullRequestReader($http, $token, publicOnly: true))->read('example/project#12'); self::fail('Expected refusal.'); }
            catch (\RuntimeException $e) {
                self::assertSame($case === 'visibility' ? 6 : 1, $calls);
                self::assertStringNotContainsString($token, $e->getMessage());
            }
        }
    }

    public function testCaptureReadsTwoPagesAndRechecksPullAndVisibility(): void
    {
        $repo = ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
        $pull = ['number' => 12, 'title' => 'Many files', 'state' => 'open', 'changed_files' => 101,
            'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
            'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => $repo]];
        $files = [];
        for ($i = 0; $i < 101; ++$i) $files[] = ['filename' => 'src/'.$i, 'status' => 'modified', 'additions' => 1, 'deletions' => 0];
        $urls = [];
        $responses = [$repo, $pull, array_slice($files, 0, 100), array_slice($files, 100), ['total_count' => 0, 'check_runs' => []], $pull, $repo];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$urls, &$responses) {
            $urls[] = $url;
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        $result = (new PullRequestReader($client, null, publicOnly: true))->read('example/project#12');
        self::assertCount(101, $result['files']);
        self::assertFalse($result['filesTruncated']);
        self::assertStringEndsWith('/files?per_page=100&page=2', $urls[3]);
        self::assertSame('src/100', $result['files'][100]['name']);
        self::assertCount(7, $urls);
    }

    public function testCaptureRejectsDuplicateFilesAndChangedCountAfterPagination(): void
    {
        $repo = ['full_name' => 'example/project', 'private' => false, 'visibility' => 'public'];
        $pull = ['number' => 12, 'title' => 'Many files', 'state' => 'open', 'changed_files' => 101,
            'head' => ['sha' => str_repeat('a', 40), 'ref' => 'fix'],
            'base' => ['sha' => str_repeat('b', 40), 'ref' => 'main', 'repo' => $repo]];
        $files = [];
        for ($i = 0; $i < 101; ++$i) $files[] = ['filename' => 'src/'.$i, 'status' => 'modified', 'additions' => 1, 'deletions' => 0];
        foreach (['duplicate', 'count'] as $case) {
            $current = $pull;
            if ($case === 'count') $current['changed_files'] = 102;
            $lastPage = $case === 'duplicate' ? [$files[0]] : [$files[100]];
            $responses = [$repo, $pull, array_slice($files, 0, 100), $lastPage, ['total_count' => 0, 'check_runs' => []], $current, $repo];
            $client = new MockHttpClient(static function () use (&$responses) {
                return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            });
            try { (new PullRequestReader($client, null, publicOnly: true))->read('example/project#12'); self::fail('Invalid capture accepted.'); }
            catch (\RuntimeException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
}
